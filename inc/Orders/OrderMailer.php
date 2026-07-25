<?php

declare(strict_types=1);

namespace RhShop\Orders;

use RhShop\Legal\Widerrufsformular;
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
    public function __construct(private readonly Config $config)
    {
    }

    public function sendConfirmation(Order $order, string $invoiceUrl = ''): void
    {
        $symbol = $this->config->currencySymbol();
        $headers = $this->headers();

        if ($order->email !== '') {
            wp_mail(
                $order->email,
                sprintf(/* translators: %s: Bestellnummer */ __('Deine Bestellung %s', 'rh-shop'), $order->orderNumber),
                $this->customerBody($order, $symbol, $invoiceUrl),
                $headers
            );
        }

        $notify = $this->config->notifyAddress();
        if ($notify !== '') {
            wp_mail(
                $notify,
                sprintf(/* translators: %s: Bestellnummer */ __('Neue Bestellung %s', 'rh-shop'), $order->orderNumber),
                $this->adminBody($order, $symbol),
                $headers
            );
        }
    }

    /**
     * Versandbestätigung an den Kunden ("ist unterwegs"), ausgelöst wenn der Betreiber
     * die Bestellung auf "versendet" setzt. Sendungsnummer/Link ist optional.
     */
    public function sendShipped(Order $order, string $tracking = ''): void
    {
        if ($order->email === '') {
            return;
        }

        wp_mail(
            $order->email,
            sprintf(/* translators: %s: Bestellnummer */ __('Deine Bestellung %s ist unterwegs', 'rh-shop'), $order->orderNumber),
            $this->shippedBody($order, $tracking),
            $this->headers()
        );
    }

    /**
     * Gemeinsame Mail-Header. Absender (From) nur setzen, wenn der Betreiber eine eigene
     * Adresse gepflegt hat, sonst bleibt der WordPress-/rh-smtp-Default.
     *
     * @return array<int, string>
     */
    private function headers(): array
    {
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $fromAddress = $this->config->mailFromAddress();
        if ($fromAddress !== '') {
            $fromName = $this->config->mailFromName();
            $headers[] = 'From: ' . ($fromName !== '' ? sprintf('%s <%s>', $fromName, $fromAddress) : $fromAddress);
        }

        return $headers;
    }

    private function shippedBody(Order $order, string $tracking): string
    {
        $trackingHtml = '';
        if ($tracking !== '') {
            $trackingHtml = filter_var($tracking, FILTER_VALIDATE_URL) !== false
                ? '<p>' . sprintf(
                    /* translators: %s: Link zur Sendungsverfolgung */
                    esc_html__('Sendung verfolgen: %s', 'rh-shop'),
                    '<a href="' . esc_url($tracking) . '">' . esc_html__('zur Sendungsverfolgung', 'rh-shop') . '</a>'
                ) . '</p>'
                : '<p>' . esc_html(sprintf(/* translators: %s: Sendungsnummer */ __('Sendungsnummer: %s', 'rh-shop'), $tracking)) . '</p>';
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

        $note = $this->config->mailNote();
        $noteHtml = $note !== '' ? '<p>' . nl2br(esc_html($note)) . '</p>' : '';

        return '<p>' . esc_html__('Hallo,', 'rh-shop') . '</p>'
            . '<p>' . esc_html($intro) . '</p>'
            . $this->itemsTable($order, $symbol)
            . $invoice
            . '<p>' . esc_html__('Wir melden uns, sobald deine Bestellung unterwegs ist.', 'rh-shop') . '</p>'
            . $noteHtml
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
