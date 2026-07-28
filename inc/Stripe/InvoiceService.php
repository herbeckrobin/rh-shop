<?php

declare(strict_types=1);

namespace RhShop\Stripe;

defined( 'ABSPATH' ) || exit;

use RhShop\Orders\Order;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient as SdkClient;

/**
 * Erzeugt nach bestätigter Zahlung die Stripe-Rechnung zur Bestellung.
 *
 * Weil die Zahlung über den PaymentIntent (Payment Element, §312j) lief und nicht
 * über die Rechnung selbst, wird die Rechnung nachträglich erstellt und als extern
 * bezahlt markiert (paid_out_of_band), damit Stripe den Kunden NICHT erneut belastet.
 * Ablauf (empirisch gegen die Stripe-API verifiziert): Kunde anlegen, Draft-Rechnung
 * anlegen, Positionen direkt an die Rechnung hängen, finalisieren (erzeugt fortlaufende
 * Nummer + PDF), dann als bezahlt markieren.
 *
 * Die Verkäufer-Stammdaten (Name, Anschrift, Steuernummer/USt-IdNr) zieht Stripe aus
 * den Rechnungs-Einstellungen des Stripe-Kontos, der Betreiber pflegt sie dort einmal.
 * Kleinunternehmer-Hinweis (§19) setzen wir als Footer, bei Regelbesteuerung eine
 * inklusive 19%-USt, damit die enthaltene Steuer auf der Rechnung ausgewiesen wird.
 */
final class InvoiceService
{
    private const OPTION_VAT_RATE = 'rhshop_vat_rate_id';

    public function __construct(
        private readonly Config $config,
        private readonly StripeClient $stripe,
    ) {
    }

    /**
     * @return array{id:string, number:string, url:string}|null null bei Fehler
     *         (die Rechnung ist best-effort und blockiert Bestellung/Mail nicht).
     */
    public function createForOrder(Order $order): ?array
    {
        $client = $this->stripe->client();
        if ($client === null) {
            return null;
        }

        return $this->create($client, $order, true);
    }

    /**
     * @param bool $retryOnStaleTaxRate Self-Healing: die gecachte tax_rate-Id ist
     *             account-gebunden. Nach einem Wechsel der Stripe-Keys antwortet
     *             Stripe "No such tax rate"; dann den Cache verwerfen und genau
     *             einmal neu versuchen (legt den Steuersatz im aktuellen Account an).
     * @return array{id:string, number:string, url:string}|null
     */
    private function create(SdkClient $client, Order $order, bool $retryOnStaleTaxRate): ?array
    {
        try {
            $customer = $client->customers->create($this->customerParams($order));

            $invoice = $client->invoices->create([
                'customer' => $customer->id,
                'collection_method' => 'charge_automatically',
                'auto_advance' => false,
                'currency' => $order->currency,
                'footer' => $this->footer($order),
                'metadata' => ['order_id' => (string) $order->id, 'order_number' => $order->orderNumber],
            ]);

            $taxRates = $this->taxRates($client, $order);

            foreach ($order->items as $item) {
                $client->invoiceItems->create([
                    'customer' => $customer->id,
                    'invoice' => $invoice->id,
                    'currency' => $order->currency,
                    'amount' => (int) ($item['line_total_cents'] ?? 0),
                    'description' => $this->itemDescription($item),
                    'tax_rates' => $taxRates,
                ]);
            }

            if ($order->shippingCents > 0) {
                $client->invoiceItems->create([
                    'customer' => $customer->id,
                    'invoice' => $invoice->id,
                    'currency' => $order->currency,
                    'amount' => $order->shippingCents,
                    'description' => __('Versand', 'rh-shop'),
                    'tax_rates' => $taxRates,
                ]);
            }

            $invoice = $client->invoices->finalizeInvoice($invoice->id);
            if (($invoice->status ?? '') !== 'paid') {
                $invoice = $client->invoices->pay($invoice->id, ['paid_out_of_band' => true]);
            }

            return [
                'id' => (string) $invoice->id,
                'number' => (string) ($invoice->number ?? ''),
                'url' => (string) ($invoice->hosted_invoice_url ?? ''),
            ];
        } catch (ApiErrorException $e) {
            if ($retryOnStaleTaxRate && str_contains($e->getMessage(), 'No such tax rate')) {
                delete_option(self::OPTION_VAT_RATE . '_' . $this->config->taxRatePercent());

                return $this->create($client, $order, false);
            }

            // Kein stilles Schlucken: die Rechnung bleibt best-effort, aber der
            // Grund muss im Log stehen, sonst ist der Ausfall unsichtbar.
            error_log(sprintf('[rh-shop] Rechnung zu Bestellung %s fehlgeschlagen: %s', $order->orderNumber, $e->getMessage()));

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function customerParams(Order $order): array
    {
        $params = ['email' => $order->email, 'name' => $order->customerName];

        if ($order->address !== []) {
            $address = [];
            foreach (['line1', 'line2', 'city', 'postal_code', 'state', 'country'] as $key) {
                if (! empty($order->address[$key])) {
                    $address[$key] = (string) $order->address[$key];
                }
            }
            if ($address !== []) {
                $params['address'] = $address;
            }
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function itemDescription(array $item): string
    {
        $title = (string) ($item['title'] ?? '');
        $options = (string) ($item['options'] ?? '');
        $qty = (int) ($item['qty'] ?? 1);

        return $qty . '× ' . $title . ($options !== '' ? ' (' . $options . ')' : '');
    }

    private function footer(Order $order): string
    {
        if ($order->taxMode === Order::TAX_KLEINUNTERNEHMER) {
            return __('Kleinunternehmer gemäß § 19 UStG. Im Preis ist keine Umsatzsteuer enthalten.', 'rh-shop');
        }

        return '';
    }

    /**
     * Bei Regelbesteuerung eine inklusive USt (get-or-create, ID gecacht). Der Cache
     * ist pro Satz (Option-Key mit Prozentwert), damit ein geänderter Steuersatz nicht
     * die alte Stripe-Steuerrate weiterverwendet. Bei Kleinunternehmer keine Steuer.
     *
     * @return array<int, string>
     */
    private function taxRates(SdkClient $client, Order $order): array
    {
        if ($order->taxMode !== Order::TAX_VAT) {
            return [];
        }

        $percent = $this->config->taxRatePercent();
        if ($percent <= 0) {
            return [];
        }

        $optionKey = self::OPTION_VAT_RATE . '_' . $percent;
        $stored = (string) get_option($optionKey, '');
        if ($stored !== '') {
            return [$stored];
        }

        try {
            $rate = $client->taxRates->create([
                'display_name' => 'USt',
                'description' => sprintf('Umsatzsteuer %d %%', $percent),
                'percentage' => $percent,
                'inclusive' => true,
            ]);
        } catch (ApiErrorException $e) {
            // Ohne Steuersatz wird die Rechnung trotzdem erstellt (ohne USt-Ausweis),
            // aber der Grund gehört ins Log.
            error_log('[rh-shop] Stripe-Steuersatz anlegen fehlgeschlagen: ' . $e->getMessage());

            return [];
        }

        update_option($optionKey, (string) $rate->id);

        return [(string) $rate->id];
    }
}
