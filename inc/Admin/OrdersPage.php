<?php

declare(strict_types=1);

namespace RhShop\Admin;

use RhShop\Catalog\ProductType;
use RhShop\Orders\Order;
use RhShop\Orders\OrderMailer;
use RhShop\Orders\OrderStore;
use RhShop\Stripe\Config;
use RhShop\Support\Money;

/**
 * Bestell-Übersicht im Admin: eine Liste der letzten Bestellungen als Untermenü
 * unter "Shop". Der Betreiber sieht, was reinkommt, und setzt pro Bestellung den
 * Status (z.B. auf "versendet"), ohne in die DB oder die CLI zu müssen.
 */
final class OrdersPage
{
    private const CAPABILITY = 'manage_options';
    private const SLUG = 'rhshop-orders';
    private const ACTION_SET_STATUS = 'rhshop_set_status';
    private const NONCE = 'rhshop_set_status';

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'register']);
        add_action('admin_post_' . self::ACTION_SET_STATUS, [$this, 'handleSetStatus']);
        add_action('admin_notices', [$this, 'maybeNotice']);
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
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- statusCell escapt intern.
        echo '<td>' . $this->statusCell($order) . '</td>';
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

    /**
     * Status -> [Label, Farbe]. Eine Quelle für Pille und Auswahl-Dropdown, die
     * Reihenfolge ist die Anzeige-Reihenfolge im Dropdown. Die gültigen Schlüssel
     * kommen aus Order::STATUSES (Domäne), Label und Farbe sind Anzeige-Sache.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function statusMeta(): array
    {
        return [
            Order::STATUS_PENDING => [__('offen', 'rh-shop'), '#8a8a8a'],
            Order::STATUS_PAID => [__('bezahlt', 'rh-shop'), '#1a7f37'],
            Order::STATUS_SHIPPED => [__('versendet', 'rh-shop'), '#0969da'],
            Order::STATUS_CANCELLED => [__('storniert', 'rh-shop'), '#cf222e'],
            Order::STATUS_REFUNDED => [__('erstattet', 'rh-shop'), '#bc4c00'],
        ];
    }

    private function statusPill(string $status): string
    {
        [$label, $color] = $this->statusMeta()[$status] ?? [$status, '#8a8a8a'];

        return sprintf(
            '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:600;color:#fff;background:%s">%s</span>',
            esc_attr($color),
            esc_html($label)
        );
    }

    /**
     * Status-Zelle: die farbige Pille als Blick-Anker plus ein Mini-Formular
     * (Auswahl + "Setzen") zum Umstellen. Bewusst kein Auto-Submit, damit keine
     * versehentliche Statusänderung passiert. POST an admin-post.php mit Nonce.
     */
    private function statusCell(Order $order): string
    {
        $options = '';
        foreach ($this->statusMeta() as $key => [$label]) {
            $options .= sprintf(
                '<option value="%s"%s>%s</option>',
                esc_attr($key),
                selected($order->status, $key, false),
                esc_html($label)
            );
        }

        // Das Sendungs-Feld wird nur genutzt, wenn auf "versendet" gesetzt wird, dann
        // geht es in die "ist unterwegs"-Mail an den Kunden. Sonst wird es ignoriert.
        return $this->statusPill($order->status)
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:6px;display:flex;gap:4px;align-items:center;flex-wrap:wrap;max-width:250px">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_SET_STATUS) . '" />'
            . '<input type="hidden" name="order_id" value="' . esc_attr((string) $order->id) . '" />'
            . wp_nonce_field(self::NONCE, '_wpnonce', true, false)
            . '<select name="status" aria-label="' . esc_attr__('Status ändern', 'rh-shop') . '">' . $options . '</select>'
            . '<button type="submit" class="button button-small">' . esc_html__('Setzen', 'rh-shop') . '</button>'
            . '<input type="text" name="tracking" value="" placeholder="' . esc_attr__('Sendungsnr./Link (optional)', 'rh-shop') . '" style="flex:1 1 100%;font-size:12px" />'
            . '</form>';
    }

    /**
     * Setzt den Bestellstatus. Cap + Nonce prüfen, Werte hart validieren (Status muss
     * aus Order::STATUSES sein), dann PRG-Redirect zurück auf die Liste (kein
     * Doppel-Submit beim Neuladen).
     */
    public function handleSetStatus(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-shop'));
        }

        check_admin_referer(self::NONCE);

        $orderId = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : '';
        $tracking = isset($_POST['tracking']) ? sanitize_text_field(wp_unslash($_POST['tracking'])) : '';

        $ok = false;
        $store = new OrderStore();
        $order = $orderId > 0 ? $store->find($orderId) : null;

        if ($order !== null && in_array($status, Order::STATUSES, true)) {
            $wasShipped = $order->status === Order::STATUS_SHIPPED;
            $store->updateStatus($orderId, $status);
            $ok = true;

            // Versandbestätigung nur beim Übergang NACH "versendet", nicht bei jedem
            // Speichern (kein doppelter Versand, wenn schon versendet war).
            if ($status === Order::STATUS_SHIPPED && ! $wasShipped) {
                $fresh = $store->find($orderId);
                if ($fresh !== null) {
                    (new OrderMailer(new Config()))->sendShipped($fresh, $tracking);
                }
            }
        }

        wp_safe_redirect(add_query_arg(
            'rhshop_status',
            $ok ? 'ok' : 'err',
            admin_url('edit.php?post_type=' . ProductType::POST_TYPE . '&page=' . self::SLUG)
        ));
        exit;
    }

    /**
     * Erfolgs-/Fehler-Hinweis nach dem Redirect, nur auf der Bestell-Seite.
     */
    public function maybeNotice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reines Anzeige-Flag nach dem eigenen PRG-Redirect.
        $flag = isset($_GET['rhshop_status']) ? sanitize_key(wp_unslash($_GET['rhshop_status'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Seiten-Filter, keine Statusänderung.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        if ($flag === '' || $page !== self::SLUG) {
            return;
        }

        if ($flag === 'ok') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Bestellstatus aktualisiert.', 'rh-shop') . '</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Status konnte nicht gesetzt werden.', 'rh-shop') . '</p></div>';
        }
    }
}
