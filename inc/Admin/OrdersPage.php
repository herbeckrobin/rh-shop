<?php

declare(strict_types=1);

namespace RhShop\Admin;

use RhShop\Catalog\ProductType;
use RhShop\Orders\Order;
use RhShop\Orders\OrderStore;
use RhShop\Stripe\Config;
use RhShop\Support\Money;

/**
 * Bestell-Übersicht im Admin: eine Liste der letzten Bestellungen als Untermenü
 * unter "Shop". Read-only, damit der Betreiber sieht was reinkommt, ohne in die
 * DB oder die CLI zu müssen.
 */
final class OrdersPage
{
    private const CAPABILITY = 'manage_options';
    private const SLUG = 'rhshop-orders';

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'register']);
    }

    public function register(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . ProductType::POST_TYPE,
            __('Bestellungen', 'rh-shop'),
            __('Bestellungen', 'rh-shop'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }

        $orders = (new OrderStore())->recent(100);
        $symbol = (new Config())->currencySymbol();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Bestellungen', 'rh-shop') . '</h1>';

        if ($orders === []) {
            echo '<p>' . esc_html__('Noch keine Bestellungen.', 'rh-shop') . '</p></div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        foreach ([
            __('Nummer', 'rh-shop'),
            __('Datum', 'rh-shop'),
            __('Status', 'rh-shop'),
            __('Kunde', 'rh-shop'),
            __('Artikel', 'rh-shop'),
            __('Summe', 'rh-shop'),
            __('Rechnung', 'rh-shop'),
        ] as $head) {
            echo '<th>' . esc_html($head) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($orders as $order) {
            $this->renderRow($order, $symbol);
        }

        echo '</tbody></table></div>';
    }

    private function renderRow(Order $order, string $symbol): void
    {
        $date = mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $order->createdAt);
        $customer = trim($order->customerName . ' ' . ($order->email !== '' ? '<' . $order->email . '>' : ''));
        $itemCount = array_sum(array_map(static fn ($i): int => (int) ($i['qty'] ?? 0), $order->items));
        $itemTitles = implode(', ', array_map(
            static function (array $i): string {
                $name = (string) ($i['title'] ?? '');
                $options = (string) ($i['options'] ?? '');
                return $name . ($options !== '' ? ' (' . $options . ')' : '');
            },
            $order->items
        ));

        echo '<tr>';
        echo '<td><strong>' . esc_html($order->orderNumber) . '</strong></td>';
        echo '<td>' . esc_html($date) . '</td>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- statusPill escapt intern.
        echo '<td>' . $this->statusPill($order->status) . '</td>';
        echo '<td>' . esc_html($customer !== '' ? $customer : '-') . '</td>';
        printf(
            '<td><span title="%s">%d</span></td>',
            esc_attr($itemTitles),
            $itemCount
        );
        echo '<td>' . esc_html(Money::format($order->totalCents, $symbol)) . '</td>';
        if ($order->invoiceNumber !== '') {
            $label = esc_html($order->invoiceNumber);
            echo '<td>' . ($order->invoiceUrl !== ''
                ? '<a href="' . esc_url($order->invoiceUrl) . '" target="_blank" rel="noopener">' . $label . '</a>'
                : $label) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $label ist escapt, URL via esc_url.
        } else {
            echo '<td>-</td>';
        }
        echo '</tr>';
    }

    private function statusPill(string $status): string
    {
        $labels = [
            Order::STATUS_PENDING => [__('offen', 'rh-shop'), '#8a8a8a'],
            Order::STATUS_PAID => [__('bezahlt', 'rh-shop'), '#1a7f37'],
            Order::STATUS_SHIPPED => [__('versendet', 'rh-shop'), '#0969da'],
            Order::STATUS_CANCELLED => [__('storniert', 'rh-shop'), '#cf222e'],
            Order::STATUS_REFUNDED => [__('erstattet', 'rh-shop'), '#bc4c00'],
        ];

        [$label, $color] = $labels[$status] ?? [$status, '#8a8a8a'];

        return sprintf(
            '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:600;color:#fff;background:%s">%s</span>',
            esc_attr($color),
            esc_html($label)
        );
    }
}
