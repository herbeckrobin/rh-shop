<?php

/**
 * Plugin Name:       RH Shop
 * Plugin URI:        https://github.com/herbeckrobin/rh-shop
 * Update URI:        https://github.com/herbeckrobin/rh-shop
 * Description:       Schlanker Shop für kleine Sortimente: Katalog in WordPress, Zahlung über Stripe. Weniger Ballast als WooCommerce, Pflege in Minuten. Teil der rh-blueprint Kollektion.
 * Version:           0.8.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Robin Herbeck
 * Author URI:        https://robinherbeck.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rh-shop
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('RHSHOP_VERSION', '0.8.0');
define('RHSHOP_PLUGIN_FILE', __FILE__);
define('RHSHOP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RHSHOP_PLUGIN_URL', plugin_dir_url(__FILE__));

$rhshop_autoload = RHSHOP_PLUGIN_DIR . 'vendor/autoload.php';

if (! is_readable($rhshop_autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>RH Shop:</strong> Composer-Dependencies fehlen. Bitte <code>composer install</code> im Plugin-Verzeichnis ausführen.</p></div>';
    });
    return;
}

require_once $rhshop_autoload;

// Bestell-Tabelle beim Aktivieren anlegen (Versions-Sync bei Updates läuft
// zusätzlich über Schema::maybeUpgrade auf init).
register_activation_hook(__FILE__, ['RhShop\\Orders\\Schema', 'activate']);

// Versand-Seite (PAngV) anlegen, idempotent (überschreibt eine vorhandene nicht).
register_activation_hook(__FILE__, ['RhShop\\Setup\\Pages', 'install']);

// Aufräum-Cron beim Deaktivieren abbestellen.
register_deactivation_hook(__FILE__, ['RhShop\\Orders\\ReservationCron', 'unschedule']);

RhShop\Plugin::boot();
