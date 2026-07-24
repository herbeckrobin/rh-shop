<?php

declare(strict_types=1);

namespace RhShop\Orders;

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

    public function sendConfirmation(Order $order): void
    {
        $symbol = $this->config->currencySymbol();
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        if ($order->email !== '') {
            wp_mail(
                $order->email,
                sprintf(/* translators: %s: Bestellnummer */ __('Deine Bestellung %s', 'rh-shop'), $order->orderNumber),
                $this->customerBody($order, $symbol),
                $headers
            );
        }

        $admin = (string) get_option('admin_email');
        if ($admin !== '') {
            wp_mail(
                $admin,
                sprintf(/* translators: %s: Bestellnummer */ __('Neue Bestellung %s', 'rh-shop'), $order->orderNumber),
                $this->adminBody($order, $symbol),
                $headers
            );
        }
    }

    private function customerBody(Order $order, string $symbol): string
    {
        $intro = sprintf(
            /* translators: %s: Bestellnummer */
            __('vielen Dank für deine Bestellung %s. Hier die Übersicht:', 'rh-shop'),
            $order->orderNumber
        );

        return '<p>' . esc_html__('Hallo,', 'rh-shop') . '</p>'
            . '<p>' . esc_html($intro) . '</p>'
            . $this->itemsTable($order, $symbol)
            . '<p>' . esc_html__('Wir melden uns, sobald deine Bestellung unterwegs ist.', 'rh-shop') . '</p>';
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
