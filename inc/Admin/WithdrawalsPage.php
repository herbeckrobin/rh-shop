<?php

declare(strict_types=1);

namespace RhShop\Admin;

use RhShop\Catalog\ProductType;
use RhShop\Withdrawal\Withdrawal;
use RhShop\Withdrawal\WithdrawalStore;

/**
 * Dokumentation der eingegangenen Widerrufe (§356a) im Admin. Read-only, mit dem
 * nachweispflichtigen Eingangszeitpunkt. Untermenü unter "Shop".
 */
final class WithdrawalsPage
{
    private const CAPABILITY = 'manage_options';
    private const SLUG = 'rhshop-widerrufe';

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'register']);
    }

    public function register(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . ProductType::POST_TYPE,
            __('Widerrufe', 'rh-shop'),
            __('Widerrufe', 'rh-shop'),
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

        $withdrawals = (new WithdrawalStore())->recent(200);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Widerrufe', 'rh-shop') . '</h1>';
        echo '<p class="description">' . esc_html__('Eingegangene Widerrufe nach §356a BGB, mit Eingangszeitpunkt als Nachweis.', 'rh-shop') . '</p>';

        if ($withdrawals === []) {
            echo '<p>' . esc_html__('Noch keine Widerrufe.', 'rh-shop') . '</p></div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        foreach ([
            __('Eingegangen', 'rh-shop'),
            __('Name', 'rh-shop'),
            __('Bestellnummer', 'rh-shop'),
            __('E-Mail', 'rh-shop'),
            __('Zugeordnet', 'rh-shop'),
            __('Grund', 'rh-shop'),
        ] as $head) {
            echo '<th>' . esc_html($head) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($withdrawals as $withdrawal) {
            $this->renderRow($withdrawal);
        }

        echo '</tbody></table></div>';
    }

    private function renderRow(Withdrawal $withdrawal): void
    {
        $date = mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $withdrawal->receivedAt);
        $matched = $withdrawal->orderId > 0
            ? '<span style="color:#1a7f37">' . esc_html__('ja', 'rh-shop') . '</span>'
            : '<span style="color:#bc4c00">' . esc_html__('nicht gefunden', 'rh-shop') . '</span>';

        echo '<tr>';
        echo '<td>' . esc_html($date) . '</td>';
        echo '<td>' . esc_html($withdrawal->customerName) . '</td>';
        echo '<td>' . esc_html($withdrawal->orderNumber) . '</td>';
        echo '<td>' . esc_html($withdrawal->email) . '</td>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- feste, escapte Markup-Konstanten.
        echo '<td>' . $matched . '</td>';
        echo '<td>' . esc_html($withdrawal->reason !== '' ? $withdrawal->reason : '-') . '</td>';
        echo '</tr>';
    }
}
