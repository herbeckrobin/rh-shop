<?php

declare(strict_types=1);

namespace RhShop\Orders;

defined( 'ABSPATH' ) || exit;

use RhShop\Legal\Widerrufsformular;
use RhShop\Mail\MailDispatcher;
use RhShop\Mail\MailRegistry;
use RhShop\Shipping\Carrier;
use RhShop\Stripe\Config;
use RhShop\Support\Money;

/**
 * Verschickt die Transaktionsmails einer Bestellung über wp_mail (rh-smtp sorgt für
 * zuverlässige Zustellung). Kunde bekommt eine Bestätigung, der Betreiber eine
 * interne Benachrichtigung mit allen Positionen.
 *
 * Alle Werte werden escaped, auch in der internen Mail: die Positionsdaten sind
 * zwar aus dem eigenen Katalog, aber Kundenname/Adresse kommen von aussen.
 */
final class OrderMailer
{
    private readonly MailDispatcher $dispatcher;

    public function __construct(private readonly Config $config)
    {
        $this->dispatcher = new MailDispatcher($config);
    }

    public function sendConfirmation(Order $order, string $invoiceUrl = ''): void
    {
        $symbol = $this->config->currencySymbol();
        $values = $this->orderValues($order, $symbol);

        $this->dispatcher->send(
            MailRegistry::get(MailRegistry::ORDER_CONFIRMATION),
            $order->email,
            $values,
            $this->customerBody($order, $symbol, $invoiceUrl),
            $this->config->mailNote() // Legacy-Zusatztext, bis migriert
        );
        $this->dispatcher->send(
            MailRegistry::get(MailRegistry::ORDER_ADMIN_NOTIFY),
            $this->config->notifyAddress(),
            $values,
            $this->adminBody($order, $symbol)
        );
    }

    /**
     * Versandbestätigung an den Kunden ("ist unterwegs"), ausgelöst wenn der Betreiber
     * die Bestellung auf "versendet" setzt. Anbieter und Sendungsnummer kommen aus der
     * gespeicherten Bestellung, der Tracking-Link wird daraus gebaut.
     */
    public function sendShipped(Order $order): void
    {
        $this->dispatcher->send(
            MailRegistry::get(MailRegistry::ORDER_SHIPPED),
            $order->email,
            $this->orderValues($order, $this->config->currencySymbol()),
            $this->shippedBody($order)
        );
    }

    /**
     * Stornierungs-Mail an den Kunden, ausgelöst beim Wechsel auf "storniert".
     */
    public function sendCancelled(Order $order): void
    {
        $this->dispatcher->send(
            MailRegistry::get(MailRegistry::ORDER_CANCELLED),
            $order->email,
            $this->orderValues($order, $this->config->currencySymbol()),
            '<p>' . esc_html__('Hallo,', 'rh-shop') . '</p>'
            . '<p>' . esc_html(sprintf(/* translators: %s: Bestellnummer */ __('deine Bestellung %s wurde storniert.', 'rh-shop'), $order->orderNumber)) . '</p>'
            . '<p>' . esc_html__('Falls du bereits bezahlt hast, erstatten wir dir den Betrag zurück.', 'rh-shop') . '</p>'
        );
    }

    /**
     * Rückerstattungs-Mail an den Kunden, ausgelöst beim Wechsel auf "erstattet".
     */
    public function sendRefunded(Order $order): void
    {
        $symbol = $this->config->currencySymbol();
        $this->dispatcher->send(
            MailRegistry::get(MailRegistry::ORDER_REFUNDED),
            $order->email,
            $this->orderValues($order, $symbol),
            '<p>' . esc_html__('Hallo,', 'rh-shop') . '</p>'
            . '<p>' . esc_html(sprintf(
                /* translators: 1: Betrag, 2: Bestellnummer */
                __('wir haben dir %1$s für deine Bestellung %2$s erstattet.', 'rh-shop'),
                Money::format($order->totalCents, $symbol),
                $order->orderNumber
            )) . '</p>'
            . '<p>' . esc_html__('Je nach Zahlungsart kann es ein paar Tage dauern, bis der Betrag wieder bei dir ist.', 'rh-shop') . '</p>'
        );
    }

    /**
     * Mail bei fehlgeschlagener Zahlung. Greift vor allem bei späteren/asynchronen
     * Zahlungsarten, die erst nach der Bestellung fehlschlagen können.
     */
    public function sendPaymentFailed(Order $order): void
    {
        $this->dispatcher->send(
            MailRegistry::get(MailRegistry::PAYMENT_FAILED),
            $order->email,
            $this->orderValues($order, $this->config->currencySymbol()),
            '<p>' . esc_html__('Hallo,', 'rh-shop') . '</p>'
            . '<p>' . esc_html(sprintf(/* translators: %s: Bestellnummer */ __('die Zahlung für deine Bestellung %s ist leider fehlgeschlagen. Es wurde nichts belastet.', 'rh-shop'), $order->orderNumber)) . '</p>'
            . '<p>' . esc_html__('Du kannst die Bestellung gern erneut auslösen.', 'rh-shop') . '</p>'
        );
    }

    /**
     * Platzhalter-Werte einer Bestellung für Betreff und Zusatztext.
     *
     * @return array<string, string>
     */
    private function orderValues(Order $order, string $symbol): array
    {
        return [
            'bestellnummer' => $order->orderNumber,
            'name' => $order->customerName,
            'summe' => Money::format($order->totalCents, $symbol),
            'sendungsnummer' => $order->trackingNumber,
            'shop_name' => (string) get_bloginfo('name'),
        ];
    }

    private function shippedBody(Order $order): string
    {
        $trackingHtml = '';
        if ($order->trackingNumber !== '') {
            $lines = [];
            if ($order->carrier !== '' && $order->carrier !== Carrier::NONE) {
                /* translators: %s: Versandanbieter (z.B. DHL) */
                $lines[] = esc_html(sprintf(__('Versendet mit %s.', 'rh-shop'), Carrier::label($order->carrier)));
            }
            /* translators: %s: Sendungsnummer */
            $lines[] = esc_html(sprintf(__('Sendungsnummer: %s', 'rh-shop'), $order->trackingNumber));
            $trackingHtml = '<p>' . implode('<br>', $lines) . '</p>';

            $url = Carrier::trackingUrl($order->carrier, $order->trackingNumber);
            if ($url !== '') {
                $trackingHtml .= '<p><a href="' . esc_url($url) . '">' . esc_html__('Sendung verfolgen', 'rh-shop') . '</a></p>';
            }
        }

        return '<p>' . esc_html__('Hallo,', 'rh-shop') . '</p>'
            . '<p>' . esc_html(sprintf(
                /* translators: %s: Bestellnummer */
                __('gute Nachrichten: deine Bestellung %s ist unterwegs zu dir.', 'rh-shop'),
                $order->orderNumber
            )) . '</p>'
            . $trackingHtml;
    }

    private function customerBody(Order $order, string $symbol, string $invoiceUrl = ''): string
    {
        $intro = sprintf(
            /* translators: %s: Bestellnummer */
            __('vielen Dank für deine Bestellung %s. Hier die Übersicht:', 'rh-shop'),
            $order->orderNumber
        );

        $invoice = $invoiceUrl !== ''
            ? '<p>' . sprintf(
                /* translators: %s: Link zur Rechnung */
                esc_html__('Deine Rechnung findest du hier: %s', 'rh-shop'),
                '<a href="' . esc_url($invoiceUrl) . '">' . esc_html__('Rechnung ansehen', 'rh-shop') . '</a>'
            ) . '</p>'
            : '';

        return '<p>' . esc_html__('Hallo,', 'rh-shop') . '</p>'
            . '<p>' . esc_html($intro) . '</p>'
            . $this->itemsTable($order, $symbol)
            . $invoice
            . '<p>' . esc_html__('Wir melden uns, sobald deine Bestellung unterwegs ist.', 'rh-shop') . '</p>'
            . $this->widerrufBlock();
    }

    /**
     * Widerruf auf dauerhaftem Datenträger (Art. 246a EGBGB): Link zur
     * Widerrufsbelehrung (falls als Seite vorhanden) plus das amtliche
     * Muster-Widerrufsformular. Per Filter abschaltbar für Sortimente ohne
     * Widerrufsrecht (z.B. reine Dienstleistungen/Downloads).
     */
    private function widerrufBlock(): string
    {
        if (! (bool) apply_filters('rh-blueprint/shop/confirmation_widerruf', true)) {
            return '';
        }

        $url = $this->widerrufsbelehrungUrl();
        $link = $url !== ''
            ? '<p>' . sprintf(
                /* translators: %s: Link zur Widerrufsbelehrung */
                esc_html__('Deine Widerrufsbelehrung findest du hier: %s', 'rh-shop'),
                '<a href="' . esc_url($url) . '">' . esc_html__('zur Widerrufsbelehrung', 'rh-shop') . '</a>'
            ) . '</p>'
            : '';

        return '<hr>' . $link . Widerrufsformular::html();
    }

    /**
     * URL der Widerrufsbelehrungs-Seite. Leer, wenn keine existiert (kein toter Link),
     * überschreibbar per Filter `rh-blueprint/shop/legal_url`.
     */
    private function widerrufsbelehrungUrl(): string
    {
        $page = get_page_by_path('widerrufsbelehrung');
        $default = $page instanceof \WP_Post ? (string) get_permalink($page) : '';

        return (string) apply_filters('rh-blueprint/shop/legal_url', $default, 'widerrufsbelehrung');
    }

    private function adminBody(Order $order, string $symbol): string
    {
        $lines = '<p><strong>' . esc_html__('Bestellung', 'rh-shop') . ':</strong> ' . esc_html($order->orderNumber) . '</p>'
            . '<p><strong>' . esc_html__('Kunde', 'rh-shop') . ':</strong> ' . esc_html($order->customerName) . ' (' . esc_html($order->email) . ')</p>';

        if ($order->address !== []) {
            $lines .= '<p><strong>' . esc_html__('Adresse', 'rh-shop') . ':</strong> ' . esc_html($this->formatAddress($order->address)) . '</p>';
        }

        return $lines . $this->itemsTable($order, $symbol);
    }

    private function itemsTable(Order $order, string $symbol): string
    {
        $rows = '';
        foreach ($order->items as $item) {
            $name = (string) ($item['title'] ?? '');
            $options = (string) ($item['options'] ?? '');
            if ($options !== '') {
                $name .= ' (' . $options . ')';
            }
            $rows .= sprintf(
                '<tr><td>%s</td><td style="text-align:center">%d</td><td style="text-align:right">%s</td></tr>',
                esc_html($name),
                (int) ($item['qty'] ?? 0),
                esc_html(Money::format((int) ($item['line_total_cents'] ?? 0), $symbol))
            );
        }

        $taxNote = $order->taxMode === Order::TAX_KLEINUNTERNEHMER
            ? '<p style="font-size:12px;color:#666">' . esc_html__('Kleinunternehmer gemäß § 19 UStG. Keine Umsatzsteuer ausgewiesen.', 'rh-shop') . '</p>'
            : '';

        return '<table cellpadding="6" style="border-collapse:collapse;width:100%;max-width:520px">'
            . '<thead><tr><th style="text-align:left">' . esc_html__('Artikel', 'rh-shop') . '</th>'
            . '<th>' . esc_html__('Menge', 'rh-shop') . '</th>'
            . '<th style="text-align:right">' . esc_html__('Summe', 'rh-shop') . '</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>'
            . '<p style="text-align:right"><strong>' . esc_html__('Versand', 'rh-shop') . ':</strong> ' . esc_html(Money::format($order->shippingCents, $symbol)) . '<br>'
            . '<strong>' . esc_html__('Gesamt', 'rh-shop') . ':</strong> ' . esc_html(Money::format($order->totalCents, $symbol)) . '</p>'
            . $taxNote;
    }

    /**
     * @param array<string, mixed> $address
     */
    private function formatAddress(array $address): string
    {
        $parts = array_filter([
            (string) ($address['line1'] ?? ''),
            (string) ($address['line2'] ?? ''),
            trim(((string) ($address['postal_code'] ?? '')) . ' ' . ((string) ($address['city'] ?? ''))),
            (string) ($address['country'] ?? ''),
        ], static fn (string $p): bool => trim($p) !== '');

        return implode(', ', $parts);
    }
}
