<?php

declare(strict_types=1);

namespace RhShop\Frontend;

use RhShop\Cart\CartRestController;
use RhShop\Catalog\ProductType;

/**
 * Registriert die Frontend-Blocks (Raster, Einzelprodukt, Warenkorb) und ihre
 * Assets, plus die Detailseiten-Integration und die Cart-REST-Endpoints.
 *
 * Buildless: das Editor-Script läuft über die window.wp.*-Globals, die Frontend-
 * Assets werden gezielt geladen (Produktseite oder Seite mit einem Shop-Block),
 * nicht sitewide. Die Block-Style- und Editor-Handles werden VOR register_block_type
 * registriert, weil die block.json sie referenziert.
 */
final class Blocks
{
    private const BLOCKS = ['product-grid', 'product-single', 'cart', 'checkout', 'widerruf'];

    public function boot(): void
    {
        add_filter('block_categories_all', [$this, 'category']);
        add_action('init', [$this, 'register'], 20);
        add_action('wp_enqueue_scripts', [$this, 'enqueueFrontend']);

        (new SingleProduct(new Render(new \RhShop\Catalog\VariantRepository(), new \RhShop\Stripe\Config())))->boot();
        (new CartRestController())->boot();
    }

    /**
     * @param array<int, array<string, mixed>> $categories
     * @return array<int, array<string, mixed>>
     */
    public function category(array $categories): array
    {
        array_unshift($categories, [
            'slug' => 'rh-shop',
            'title' => __('Shop', 'rh-shop'),
            'icon' => 'cart',
        ]);

        return $categories;
    }

    public function register(): void
    {
        $this->registerAssets();

        foreach (self::BLOCKS as $slug) {
            register_block_type(RHSHOP_PLUGIN_DIR . 'blocks/' . $slug);
        }
    }

    private function registerAssets(): void
    {
        $editorRel = 'assets/js/blocks-editor.js';
        wp_register_script(
            'rh-shop-blocks-editor',
            RHSHOP_PLUGIN_URL . $editorRel,
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n'],
            $this->assetVersion($editorRel),
            true
        );
        wp_localize_script('rh-shop-blocks-editor', 'rhShopBlocks', [
            'meta' => $this->blockMetas(),
            'products' => $this->productChoices(),
            'categories' => $this->categoryChoices(),
        ]);

        wp_register_style('rh-shop', RHSHOP_PLUGIN_URL . 'assets/css/shop.css', [], $this->assetVersion('assets/css/shop.css'));

        wp_register_script('rh-shop-view', RHSHOP_PLUGIN_URL . 'assets/js/shop.js', [], $this->assetVersion('assets/js/shop.js'), true);
        wp_localize_script('rh-shop-view', 'rhShopConfig', [
            'restUrl' => esc_url_raw(rest_url(CartRestController::NAMESPACE . '/')),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);

        // Widerruf (§356a): eigenes Script, nutzt rhShopConfig aus rh-shop-view.
        wp_register_script('rh-shop-widerruf', RHSHOP_PLUGIN_URL . 'assets/js/widerruf.js', ['rh-shop-view'], $this->assetVersion('assets/js/widerruf.js'), true);

        // Kasse: eigenes Script (lädt Stripe.js erst dort nach) + Publishable Key.
        wp_register_script('rh-shop-checkout', RHSHOP_PLUGIN_URL . 'assets/js/checkout.js', [], $this->assetVersion('assets/js/checkout.js'), true);
        wp_localize_script('rh-shop-checkout', 'rhShopCheckout', [
            'pk' => (new \RhShop\Stripe\Config())->publishableKey(),
            'restUrl' => esc_url_raw(rest_url(CartRestController::NAMESPACE . '/')),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    public function enqueueFrontend(): void
    {
        if (! $this->needsAssets()) {
            return;
        }

        wp_enqueue_style('rh-shop');
        wp_enqueue_script('rh-shop-view');

        if (has_block('rh-shop/checkout')) {
            wp_enqueue_script('rh-shop-checkout');
        }

        if (has_block('rh-shop/widerruf')) {
            wp_enqueue_script('rh-shop-widerruf');
        }
    }

    private function needsAssets(): bool
    {
        if (is_singular(ProductType::POST_TYPE)) {
            return true;
        }

        foreach (self::BLOCKS as $slug) {
            if (has_block('rh-shop/' . $slug)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Die block.json-Metadaten der Blocks fürs Editor-JS (registerBlockType nimmt
     * das Metadaten-Objekt), damit Attribute nicht doppelt gepflegt werden.
     *
     * @return array<int, array<string, mixed>>
     */
    private function blockMetas(): array
    {
        if (! is_admin()) {
            return [];
        }

        $metas = [];
        foreach (self::BLOCKS as $slug) {
            $file = RHSHOP_PLUGIN_DIR . 'blocks/' . $slug . '/block.json';
            if (! is_readable($file)) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                $metas[] = $decoded;
            }
        }

        return $metas;
    }

    /**
     * @return array<int, array{value:int,label:string}>
     */
    private function productChoices(): array
    {
        if (! is_admin()) {
            return [];
        }

        $posts = get_posts([
            'post_type' => ProductType::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => 100,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        return array_map(static fn ($p): array => [
            'value' => (int) $p->ID,
            'label' => get_the_title($p),
        ], $posts);
    }

    /**
     * @return array<int, array{value:string,label:string}>
     */
    private function categoryChoices(): array
    {
        if (! is_admin()) {
            return [];
        }

        $terms = get_terms(['taxonomy' => ProductType::TAXONOMY, 'hide_empty' => false]);
        if (! is_array($terms)) {
            return [];
        }

        return array_map(static fn ($t): array => [
            'value' => $t->slug,
            'label' => $t->name,
        ], $terms);
    }

    private function assetVersion(string $relative): string
    {
        $abs = RHSHOP_PLUGIN_DIR . $relative;

        return file_exists($abs) ? (string) filemtime($abs) : RHSHOP_VERSION;
    }
}
