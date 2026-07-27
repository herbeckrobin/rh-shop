<?php

declare(strict_types=1);

namespace RhShop\Admin;

defined( 'ABSPATH' ) || exit;

use RhShop\Catalog\ProductType;
use RhShop\Orders\Order;
use RhShop\Orders\OrderMailer;
use RhShop\Orders\OrderStore;
use RhShop\Shipping\Carrier;
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

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reiner Anzeige-Parameter, keine Aktion.
        $orderId = isset($_GET['order']) ? absint(wp_unslash($_GET['order'])) : 0;
        if ($orderId > 0) {
            $this->renderDetail($orderId);
            return;
        }

        $this->renderList();
    }

    private function renderList(): void
    {
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
        echo '<td><strong><a href="' . esc_url($this->detailUrl($order->id)) . '">' . esc_html($order->orderNumber) . '</a></strong></td>';
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
     * Detailansicht einer Bestellung: voller Kontext für den Betreiber (Kunde, Adresse,
     * Positionen, Summen, Rechnung, Zahlungsreferenz) plus das Status-Ändern.
     */
    private function renderDetail(int $orderId): void
    {
        $order = (new OrderStore())->find($orderId);
        $backLink = '<p><a href="' . esc_url($this->listUrl()) . '">&larr; ' . esc_html__('Alle Bestellungen', 'rh-shop') . '</a></p>';

        if ($order === null) {
            echo '<div class="wrap"><h1>' . esc_html__('Bestellung', 'rh-shop') . '</h1><p>'
                . esc_html__('Bestellung nicht gefunden.', 'rh-shop') . '</p>' . $backLink . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }

        $symbol = (new Config())->currencySymbol();
        $date = mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $order->createdAt);

        echo '<div class="wrap">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $backLink ist escapt.
        echo $backLink;
        echo '<h1>' . sprintf(/* translators: %s: Bestellnummer */ esc_html__('Bestellung %s', 'rh-shop'), esc_html($order->orderNumber)) . '</h1>';
        echo '<p class="description">' . esc_html($date) . '</p>';

        // Status + Kunde nebeneinander.
        echo '<div style="display:flex;gap:2.5rem;flex-wrap:wrap;margin:1.5rem 0">';
        echo '<div><h2 style="margin-top:0">' . esc_html__('Status', 'rh-shop') . '</h2>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- statusCell escapt intern.
        echo $this->statusCell($order);
        echo '</div>';

        echo '<div><h2 style="margin-top:0">' . esc_html__('Kunde', 'rh-shop') . '</h2>';
        $name = $order->customerName !== '' ? $order->customerName : __('kein Name angegeben', 'rh-shop');
        echo '<p>' . esc_html($name) . '<br>';
        if ($order->email !== '') {
            echo '<a href="mailto:' . esc_attr($order->email) . '">' . esc_html($order->email) . '</a>';
        }
        echo '</p>';
        if ($order->address !== []) {
            echo '<p>' . nl2br(esc_html($this->formatAddress($order->address))) . '</p>';
        }
        echo '</div></div>';

        // Positionen + Summen.
        echo '<h2>' . esc_html__('Positionen', 'rh-shop') . '</h2>';
        echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th>'
            . esc_html__('Artikel', 'rh-shop') . '</th><th>' . esc_html__('Menge', 'rh-shop')
            . '</th><th style="text-align:right">' . esc_html__('Summe', 'rh-shop') . '</th></tr></thead><tbody>';
        foreach ($order->items as $item) {
            $itemName = (string) ($item['title'] ?? '');
            $opts = (string) ($item['options'] ?? '');
            if ($opts !== '') {
                $itemName .= ' (' . $opts . ')';
            }
            printf(
                '<tr><td>%s</td><td>%d</td><td style="text-align:right">%s</td></tr>',
                esc_html($itemName),
                (int) ($item['qty'] ?? 0),
                esc_html(Money::format((int) ($item['line_total_cents'] ?? 0), $symbol))
            );
        }
        echo '</tbody><tfoot>';
        $this->totalRow(esc_html__('Zwischensumme', 'rh-shop'), esc_html(Money::format($order->subtotalCents, $symbol)));
        $shipLabel = __('Versand', 'rh-shop') . ($order->shippingMethod !== '' ? ' (' . $order->shippingMethod . ')' : '');
        $this->totalRow(
            esc_html($shipLabel),
            esc_html($order->shippingCents > 0 ? Money::format($order->shippingCents, $symbol) : __('kostenlos', 'rh-shop'))
        );
        if ($order->taxMode !== Order::TAX_KLEINUNTERNEHMER) {
            $this->totalRow(esc_html__('enthaltene MwSt.', 'rh-shop'), esc_html(Money::format($order->taxCents, $symbol)));
        }
        $this->totalRow(
            '<strong>' . esc_html__('Gesamt', 'rh-shop') . '</strong>',
            '<strong>' . esc_html(Money::format($order->totalCents, $symbol)) . '</strong>'
        );
        echo '</tfoot></table>';

        // Zahlung + Rechnung.
        echo '<h2>' . esc_html__('Zahlung & Rechnung', 'rh-shop') . '</h2><p>';
        if ($order->invoiceUrl !== '') {
            echo '<a class="button" href="' . esc_url($order->invoiceUrl) . '" target="_blank" rel="noopener">'
                . esc_html__('Rechnung ansehen', 'rh-shop') . '</a> ';
        } elseif ($order->invoiceNumber !== '') {
            echo esc_html($order->invoiceNumber) . ' ';
        }
        if ($order->stripePaymentIntentId !== '') {
            echo '<code>' . esc_html($order->stripePaymentIntentId) . '</code>';
        }
        echo '</p></div>';
    }

    private function totalRow(string $label, string $value): void
    {
        // $label/$value sind bereits escapt (Aufrufer).
        printf('<tr><td colspan="2" style="text-align:right">%s</td><td style="text-align:right">%s</td></tr>', $label, $value); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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

        return implode("\n", $parts);
    }

    private function detailUrl(int $orderId): string
    {
        return add_query_arg('order', $orderId, $this->listUrl());
    }

    private function listUrl(): string
    {
        return admin_url('edit.php?post_type=' . ProductType::POST_TYPE . '&page=' . self::SLUG);
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
     * Status-Zelle: die farbige Pille als Blick-Anker plus ein Mini-Formular zum
     * Umstellen. Beim Versenden wählt der Betreiber den Anbieter und trägt die
     * Sendungsnummer ein (beides wird gespeichert), ein "Label erstellen"-Link führt ins
     * Anbieter-Portal. Bereits versendete Bestellungen zeigen die gespeicherte Nummer mit
     * direktem Sendungsverfolgungs-Link. Bewusst kein Auto-Submit. POST mit Nonce.
     */
    private function statusCell(Order $order): string
    {
        $statusOptions = '';
        foreach ($this->statusMeta() as $key => [$label]) {
            $statusOptions .= sprintf('<option value="%s"%s>%s</option>', esc_attr($key), selected($order->status, $key, false), esc_html($label));
        }

        $selectedCarrier = $order->carrier !== '' ? $order->carrier : Carrier::NONE;
        $carrierOptions = '';
        foreach (Carrier::options() as $cid => $clabel) {
            $carrierOptions .= sprintf('<option value="%s"%s>%s</option>', esc_attr($cid), selected($selectedCarrier, $cid, false), esc_html($clabel));
        }

        $portalUrl = Carrier::portalUrl($selectedCarrier);
        $trackUrl = Carrier::trackingUrl($order->carrier, $order->trackingNumber);

        // Gespeicherte Sendungsinfo mit Kunden-Trackinglink, wenn vorhanden.
        $shippedInfo = '';
        if ($order->trackingNumber !== '') {
            $num = $trackUrl !== ''
                ? '<a href="' . esc_url($trackUrl) . '" target="_blank" rel="noopener">' . esc_html($order->trackingNumber) . '</a>'
                : esc_html($order->trackingNumber);
            $shippedInfo = '<p style="margin:6px 0 0;font-size:12px;color:#50575e">'
                . esc_html(Carrier::label($order->carrier)) . ': ' . $num . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $num escapt.
        }

        return $this->statusPill($order->status)
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:6px;display:flex;gap:4px;align-items:center;flex-wrap:wrap;max-width:280px" data-rhshop-ship>'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_SET_STATUS) . '" />'
            . '<input type="hidden" name="order_id" value="' . esc_attr((string) $order->id) . '" />'
            . wp_nonce_field(self::NONCE, '_wpnonce', true, false)
            . '<select name="status" aria-label="' . esc_attr__('Status ändern', 'rh-shop') . '">' . $statusOptions . '</select>'
            . '<button type="submit" class="button button-small">' . esc_html__('Setzen', 'rh-shop') . '</button>'
            . '<select name="carrier" data-rhshop-carrier aria-label="' . esc_attr__('Versandanbieter', 'rh-shop') . '" style="flex:1 1 45%;font-size:12px">' . $carrierOptions . '</select>'
            . '<input type="text" name="tracking" value="' . esc_attr($order->trackingNumber) . '" placeholder="' . esc_attr__('Sendungsnummer', 'rh-shop') . '" style="flex:1 1 45%;font-size:12px" />'
            . '<a data-rhshop-portal href="' . esc_url($portalUrl) . '" target="_blank" rel="noopener" style="font-size:12px"' . ($portalUrl === '' ? ' hidden' : '') . '>' . esc_html__('Label erstellen', 'rh-shop') . ' &#8599;</a>'
            . '</form>'
            . $shippedInfo
            . $this->shippingScript();
    }

    /**
     * Einmaliges Inline-JS: aktualisiert den "Label erstellen"-Link, wenn im Formular ein
     * anderer Anbieter gewählt wird (Portal-URL je Carrier). Guard, damit es bei vielen
     * Zeilen nur einmal ausgegeben wird.
     */
    private function shippingScript(): string
    {
        static $printed = false;
        if ($printed) {
            return '';
        }
        $printed = true;

        $portals = [];
        foreach (array_keys(Carrier::options()) as $cid) {
            $portals[$cid] = Carrier::portalUrl($cid);
        }

        return '<script>( function () {'
            . 'var P = ' . wp_json_encode($portals) . ';'
            . 'document.addEventListener( "change", function ( e ) {'
            . 'var sel = e.target.closest( "[data-rhshop-carrier]" ); if ( ! sel ) { return; }'
            . 'var wrap = sel.closest( "[data-rhshop-ship]" ); var link = wrap && wrap.querySelector( "[data-rhshop-portal]" ); if ( ! link ) { return; }'
            . 'var url = P[ sel.value ] || ""; if ( url ) { link.href = url; link.hidden = false; } else { link.hidden = true; }'
            . '} );'
            . '} )();</script>';
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
        $carrier = isset($_POST['carrier']) ? Carrier::sanitize(sanitize_key(wp_unslash($_POST['carrier']))) : Carrier::NONE;

        $ok = false;
        $store = new OrderStore();
        $order = $orderId > 0 ? $store->find($orderId) : null;

        if ($order !== null && in_array($status, Order::STATUSES, true)) {
            $previous = $order->status;

            if ($status === Order::STATUS_SHIPPED) {
                // Carrier + Sendungsnummer persistieren (bleiben erhalten, auch für eine
                // spätere Korrektur). Die Versandbestätigung geht nur beim ERSTEN Übergang
                // raus, damit ein Nachtragen der Nummer keine zweite Mail auslöst.
                $store->markShipped($orderId, $carrier, $tracking);
                if ($previous !== Order::STATUS_SHIPPED) {
                    $fresh = $store->find($orderId);
                    if ($fresh !== null) {
                        (new OrderMailer(new Config()))->sendShipped($fresh);
                    }
                }
            } else {
                $store->updateStatus($orderId, $status);

                // Storno-/Rückerstattungs-Mail nur beim echten Statuswechsel, nicht bei
                // wiederholtem Setzen desselben Status (keine Doppel-Mail).
                if ($previous !== $status && ($status === Order::STATUS_CANCELLED || $status === Order::STATUS_REFUNDED)) {
                    $fresh = $store->find($orderId);
                    if ($fresh !== null) {
                        $mailer = new OrderMailer(new Config());
                        if ($status === Order::STATUS_CANCELLED) {
                            $mailer->sendCancelled($fresh);
                        } else {
                            $mailer->sendRefunded($fresh);
                        }
                    }
                }
            }

            $ok = true;
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
