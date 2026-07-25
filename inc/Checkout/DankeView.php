<?php

declare(strict_types=1);

namespace RhShop\Checkout;

use RhShop\Orders\Order;
use RhShop\Orders\OrderStore;
use RhShop\Stripe\Config;
use RhShop\Stripe\StripeClient;
use RhShop\Support\Money;
use Stripe\Exception\ApiErrorException;

/**
 * Die Bestätigungsseite nach der Zahlung (`/danke?payment_intent=…`), status-bewusst.
 *
 * Der alte statische Text ("du bekommst gleich eine Mail") log: er versprach eine
 * Bestätigung, ohne die Zahlung zu prüfen. Hier wird der echte Stand gezeigt:
 *
 * - Bestellung lokal schon bezahlt (Webhook war da) -> Bestätigung.
 * - Noch pending -> einmal bei Stripe nachfragen (der Rücksprung ist schneller als
 *   der Webhook, das ist das Race): payment_status paid -> Bestätigung, sonst der
 *   ehrliche "wird noch verarbeitet"-Hinweis ohne Mail-Versprechen.
 * - Kein/kein passender session_id (Direktaufruf) -> neutraler Dank.
 */
final class DankeView
{
    public function __construct(
        private readonly Config $config,
        private readonly OrderStore $orders,
        private readonly StripeClient $stripe,
    ) {
    }

    public static function make(): self
    {
        $config = new Config();

        return new self($config, new OrderStore(), new StripeClient($config));
    }

    public function render(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Landing von Stripe, kein Formular; nur Anzeige.
        $paymentIntent = isset($_GET['payment_intent']) ? sanitize_text_field(wp_unslash($_GET['payment_intent'])) : '';

        $order = ($paymentIntent !== '' && str_starts_with($paymentIntent, 'pi_'))
            ? $this->orders->findByPaymentIntent($paymentIntent)
            : null;

        if ($order === null) {
            return $this->shell(
                'neutral',
                '<p class="rhshop-danke__lead">' . esc_html__('Vielen Dank für deinen Einkauf.', 'rh-shop') . '</p>'
            );
        }

        $paid = $order->status === Order::STATUS_PAID
            || $order->status === Order::STATUS_SHIPPED
            || $this->isPaidAtStripe($paymentIntent);

        return $paid ? $this->confirmed($order) : $this->processing($order);
    }

    private function confirmed(Order $order): string
    {
        $inner = $this->statusHead('success', esc_html__('Deine Zahlung ist bestätigt.', 'rh-shop'), $order)
            . '<p class="rhshop-danke__note">' . esc_html__('Die Bestellbestätigung schicken wir dir per E-Mail. Wir melden uns, sobald deine Bestellung unterwegs ist.', 'rh-shop') . '</p>'
            . $this->orderSummary($order, true);

        return $this->shell('success', $inner);
    }

    private function processing(Order $order): string
    {
        $inner = $this->statusHead('pending', esc_html__('Deine Zahlung wird gerade verarbeitet.', 'rh-shop'), $order)
            . '<p class="rhshop-danke__note">' . esc_html__('Sobald die Zahlung bestätigt ist, bekommst du die Bestellbestätigung per E-Mail.', 'rh-shop') . '</p>'
            . $this->orderSummary($order, false);

        return $this->shell('pending', $inner);
    }

    /**
     * Bestellübersicht aus dem Positions-Snapshot der Bestellung (Preise vom
     * Kaufzeitpunkt, keine Neuberechnung). Zeigt Positionen, Summen und, falls die
     * Rechnung schon erstellt ist, den Link dazu.
     */
    private function orderSummary(Order $order, bool $paid): string
    {
        $symbol = $this->config->currencySymbol();

        $items = '';
        foreach ($order->items as $item) {
            $name = (string) ($item['title'] ?? '');
            $options = (string) ($item['options'] ?? '');
            if ($options !== '') {
                $name .= ' (' . $options . ')';
            }
            $items .= sprintf(
                '<li class="rhshop-danke__item"><span class="rhshop-danke__item-name">%s</span>'
                . '<span class="rhshop-danke__item-qty">%s %d</span>'
                . '<span class="rhshop-danke__item-total">%s</span></li>',
                esc_html($name),
                esc_html__('Menge', 'rh-shop'),
                (int) ($item['qty'] ?? 0),
                esc_html(Money::format((int) ($item['line_total_cents'] ?? 0), $symbol))
            );
        }

        $shippingLabel = $order->shippingCents > 0
            ? Money::format($order->shippingCents, $symbol)
            : __('kostenlos', 'rh-shop');

        $rows = $this->row(__('Zwischensumme', 'rh-shop'), Money::format($order->subtotalCents, $symbol));
        $rows .= $this->row(__('Versand', 'rh-shop'), $shippingLabel);

        if ($order->taxMode === Order::TAX_KLEINUNTERNEHMER) {
            $rows .= $this->row(__('Gesamt', 'rh-shop'), Money::format($order->totalCents, $symbol), 'total');
            $rows .= '<p class="rhshop-danke__taxnote">' . esc_html__('Kleinunternehmer gemäß § 19 UStG. Im Preis ist keine Umsatzsteuer enthalten.', 'rh-shop') . '</p>';
        } else {
            $rows .= $this->row(
                sprintf(/* translators: %d: Steuersatz */ __('enthaltene MwSt. (%d %%)', 'rh-shop'), $this->config->taxRatePercent()),
                Money::format($order->taxCents, $symbol),
                'muted'
            );
            $rows .= $this->row(__('Gesamt (inkl. MwSt.)', 'rh-shop'), Money::format($order->totalCents, $symbol), 'total');
        }

        $invoice = $this->invoiceSection($order, $paid);

        return '<div class="rhshop-danke__order">'
            . '<h3 class="rhshop-danke__order-title">' . esc_html__('Deine Bestellung', 'rh-shop') . '</h3>'
            . '<ul class="rhshop-danke__items">' . $items . '</ul>'
            . '<div class="rhshop-danke__breakdown">' . $rows . '</div>'
            . $invoice
            . '</div>';
    }

    /**
     * Rechnungs-Abschnitt. Ist die Rechnung schon da, direkt der Link. Ist bezahlt,
     * aber die Rechnung noch nicht erstellt (der Webhook erstellt sie ein paar
     * Sekunden nach dem Rücksprung), ein Slot der per Poll live nachgeladen wird,
     * ohne dass der Käufer neu laden muss.
     */
    private function invoiceSection(Order $order, bool $paid): string
    {
        if ($order->invoiceUrl !== '') {
            return '<p class="rhshop-danke__invoice"><a href="' . esc_url($order->invoiceUrl) . '" target="_blank" rel="noopener">'
                . esc_html__('Rechnung ansehen', 'rh-shop') . '</a></p>';
        }

        // Auf den Seiten-Zustand hören (bezahlt via Stripe), nicht auf den DB-Status:
        // beim ersten Rücksprung hat der Webhook die Bestellung oft noch nicht auf
        // bezahlt gesetzt. So erscheint der Poll-Slot sofort und lädt die Rechnung nach.
        if (! $paid) {
            return '';
        }

        $endpoint = rest_url(\RhShop\Cart\CartRestController::NAMESPACE . '/checkout/invoice');

        $config = wp_json_encode([
            'url' => $endpoint,
            'paymentIntent' => $order->stripePaymentIntentId,
            'label' => __('Rechnung ansehen', 'rh-shop'),
        ]);

        return '<p class="rhshop-danke__invoice" data-rhshop-invoice-slot>'
            . '<span class="rhshop-danke__invoice-wait"><span class="rhshop-spinner" aria-hidden="true"></span>'
            . esc_html__('Rechnung wird erstellt …', 'rh-shop') . '</span></p>'
            . '<script>(' . $this->pollScript() . ')(' . $config . ');</script>';
    }

    /**
     * Poll-Funktion als String (per JSON-Config aufgerufen), damit keine Werte
     * unescaped in den JS-Code interpoliert werden. Link wird per DOM gesetzt (href +
     * textContent), keine HTML-Injektion.
     */
    private function pollScript(): string
    {
        return <<<'JS'
function(c){
  var slot=document.querySelector('[data-rhshop-invoice-slot]');
  if(!slot||!c.paymentIntent){return;}
  var tries=0;
  function fill(u){
    var a=document.createElement('a');
    a.href=u;a.target='_blank';a.rel='noopener';a.textContent=c.label;
    slot.textContent='';slot.appendChild(a);
  }
  function poll(){
    tries++;
    fetch(c.url+'?payment_intent='+encodeURIComponent(c.paymentIntent),{credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(d){
        if(d&&d.invoice_url){fill(d.invoice_url);return;}
        if(tries<12){setTimeout(poll,2500);}else{slot.textContent='';}
      })
      .catch(function(){if(tries<12){setTimeout(poll,2500);}else{slot.textContent='';}});
  }
  setTimeout(poll,2000);
}
JS;
    }

    private function row(string $label, string $value, string $modifier = ''): string
    {
        $class = 'rhshop-danke__row' . ($modifier !== '' ? ' rhshop-danke__row--' . $modifier : '');

        return sprintf(
            '<div class="%s"><span>%s</span><span>%s</span></div>',
            esc_attr($class),
            esc_html($label),
            esc_html($value)
        );
    }

    /**
     * Bei noch nicht lokal bezahlter Bestellung einmal die Wahrheit bei Stripe holen
     * (Webhook-Verzögerung überbrücken). Fehlschlag/keine Konfiguration -> false, dann
     * greift der ehrliche "wird verarbeitet"-Text.
     */
    private function isPaidAtStripe(string $paymentIntentId): bool
    {
        $client = $this->stripe->client();
        if ($client === null) {
            return false;
        }

        try {
            $intent = $client->paymentIntents->retrieve($paymentIntentId);
        } catch (ApiErrorException) {
            return false;
        }

        return ($intent->status ?? '') === 'succeeded';
    }

    private function shell(string $variant, string $inner): string
    {
        // $inner ist bereits escaptes Markup.
        return '<div class="rhshop-danke rhshop-danke--' . esc_attr($variant) . '">'
            . $inner // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            . '</div>';
    }

    /**
     * Status-Kopf: Badge (Haken bzw. Sanduhr) neben der Kern-Aussage und der
     * Bestellnummer. Kein großer Titel, der Seitentitel des Themes steht schon drüber.
     */
    private function statusHead(string $variant, string $lead, Order $order): string
    {
        $badge = $variant === 'success'
            ? '<span class="rhshop-danke__badge rhshop-danke__badge--ok" aria-hidden="true">✓</span>'
            : '<span class="rhshop-danke__badge rhshop-danke__badge--wait" aria-hidden="true">⏳</span>';

        $orderNo = sprintf(
            /* translators: %s: Bestellnummer */
            esc_html__('Bestellung %s', 'rh-shop'),
            '<strong>' . esc_html($order->orderNumber) . '</strong>'
        );

        return '<div class="rhshop-danke__status">'
            . $badge
            . '<div class="rhshop-danke__status-text">'
            . '<p class="rhshop-danke__lead">' . $lead . '</p>'
            . '<p class="rhshop-danke__order-no">' . $orderNo . '</p>'
            . '</div></div>';
    }
}
