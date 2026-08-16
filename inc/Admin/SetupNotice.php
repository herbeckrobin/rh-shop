<?php

declare(strict_types=1);

namespace RhShop\Admin;

use RhBlueprint\Core\Admin\Guard;
defined( 'ABSPATH' ) || exit;

use RhShop\Catalog\ProductType;
use RhShop\Stripe\Config;

/**
 * Dezenter Einrichtungs-Hinweis für einen frisch aktivierten Shop. Zeigt den Weg zur
 * Einrichtung (kein erzwungener Redirect, der bricht die Plugin-Aktivierung, siehe
 * WooCommerce-UX-Guidelines). Verschwindet automatisch, sobald Stripe verbunden ist,
 * und lässt sich ausblenden.
 */
final class SetupNotice
{
    private const OPTION_DISMISSED = 'rhshop_setup_dismissed';
    private const DISMISS_ACTION = 'rhshop_dismiss_setup';

    public function __construct(private readonly Config $config)
    {
    }

    public function boot(): void
    {
        add_action('admin_notices', [$this, 'maybeShow']);
        add_action('admin_post_' . self::DISMISS_ACTION, [$this, 'handleDismiss']);
    }

    public function maybeShow(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        // Verbunden = eingerichtet, kein Hinweis mehr. Ebenso wenn ausgeblendet.
        if ($this->config->isConfigured() || (bool) get_option(self::OPTION_DISMISSED, false)) {
            return;
        }

        $settingsUrl = admin_url('admin.php?page=rh-blueprint&tab=shop');
        $overviewUrl = admin_url('edit.php?post_type=' . ProductType::POST_TYPE . '&page=rhshop-overview');
        $dismissUrl = wp_nonce_url(
            admin_url('admin-post.php?action=' . self::DISMISS_ACTION),
            self::DISMISS_ACTION
        );

        echo '<div class="notice notice-info"><p>';
        echo '<strong>' . esc_html__('RH Shop ist aktiv.', 'rh-shop') . '</strong> ';
        echo esc_html__('Richte deinen Shop ein: Stripe verbinden, Preise und Steuer festlegen, Rechtstexte prüfen.', 'rh-shop');
        echo '</p><p>';
        echo '<a class="button button-primary" href="' . esc_url($settingsUrl) . '">' . esc_html__('Shop einrichten', 'rh-shop') . '</a> ';
        echo '<a class="button" href="' . esc_url($overviewUrl) . '">' . esc_html__('Zur Übersicht', 'rh-shop') . '</a> ';
        echo '<a href="' . esc_url($dismissUrl) . '" style="margin-left:0.5rem">' . esc_html__('Ausblenden', 'rh-shop') . '</a>';
        echo '</p></div>';
    }

    public function handleDismiss(): void
    {
        Guard::form(self::DISMISS_ACTION);
        update_option(self::OPTION_DISMISSED, true);

        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }
}
