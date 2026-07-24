<?php

declare(strict_types=1);

namespace RhShop;

use RhBlueprint\Core\Core;
use RhBlueprint\Core\Settings\SettingsPage;
use RhShop\Admin\ShopSettingsPage;
use RhShop\Checkout\CheckoutRestController;
use RhShop\Admin\VariantMetaBox;
use RhShop\Catalog\ProductType;
use RhShop\Catalog\VariantRepository;
use RhShop\Frontend\Blocks;
use RhShop\Orders\Fulfillment;
use RhShop\Orders\OrderMailer;
use RhShop\Orders\OrderStore;
use RhShop\Orders\Schema;
use RhShop\Stripe\Config;
use RhShop\Stripe\WebhookController;

/**
 * Bootstrap von rh-shop.
 *
 * Hängt am Core-Hook `rh-blueprint/core/booted` (feuert auf `init`), weil die
 * CPT-/Taxonomie-Labels Übersetzungsfunktionen nutzen und die ab WP 6.7 nicht vor
 * `init` laufen dürfen. Braucht nur den Core (keine db-engine): der Katalog lebt
 * als CPT + Varianten-Post-Meta, Stripe macht die Zahlung.
 *
 * Stand: Fundament (Produktmodell). Stripe-Anbindung, Warenkorb, Checkout und
 * Bestellungen kommen als eigene Bausteine dazu.
 */
final class Plugin
{
    public static function boot(): void
    {
        if (class_exists(UpdateChecker::class)) {
            (new UpdateChecker())->boot();
        }

        // Bestell-Tabelle bei Versions-Sprung synchron halten (Updates feuern keinen
        // Activation-Hook). Registrierung in boot() (läuft vor init), damit der
        // init-Hook rechtzeitig hängt; dbDelta läuft nur bei Versions-Differenz.
        add_action('init', [Schema::class, 'maybeUpgrade'], 0);

        add_action('rh-blueprint/core/booted', [self::class, 'onCoreBooted']);
    }

    public static function onCoreBooted(Core $core): void
    {
        // Der CPT registriert sich frontend UND backend (Produktseiten brauchen ihn),
        // die Varianten-Meta-Box nur im Admin.
        (new ProductType())->boot();

        // Frontend: Blocks (Raster, Einzelprodukt, Warenkorb), Detailseiten-Integration
        // und Cart-REST. Läuft auch im Admin (Editor-Assets + REST-Registrierung).
        (new Blocks())->boot();

        // Checkout-REST (§312j-Button → Bestellung + Stripe-Session).
        (new CheckoutRestController())->boot();

        // Stripe-Webhook: bestätigt die Zahlung serverseitig, bucht Bestand, mailt.
        $config = new Config();
        (new WebhookController(
            $config,
            new OrderStore(),
            new Fulfillment(new VariantRepository(), new OrderMailer($config))
        ))->boot();

        $core->settings()->registerTab('shop', __('Shop', 'rh-shop'), 70);

        if (is_admin()) {
            (new VariantMetaBox())->boot();
            (new ShopSettingsPage(new Config()))->boot();
        }

        add_filter('rh-blueprint/dashboard/quick_links', static function (array $links): array {
            $links[] = [
                'label' => __('Shop', 'rh-shop'),
                'url' => admin_url('admin.php?page=' . SettingsPage::MENU_SLUG . '&tab=shop'),
                'icon' => 'cart',
            ];
            return $links;
        });
    }
}
