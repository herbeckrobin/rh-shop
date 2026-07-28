<?php

declare(strict_types=1);

namespace RhShop\Frontend;

defined( 'ABSPATH' ) || exit;

use RhShop\Cart\CartRestController;
use RhShop\Catalog\ProductType;
use RhShop\Catalog\VariantRepository;
use RhShop\Stripe\Config;
use RhShop\Support\RateLimiter;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Produkt-Suche für das Such-Overlay (Block rh-shop/search).
 *
 * Eigener Endpoint statt des Core-Endpoints /wp/v2/rh_product, weil das Overlay
 * den fertig formatierten Preis ("ab X €") und das Thumbnail braucht; beides
 * rechnet Render::priceLabel() serverseitig aus dem Katalog. Public read-only,
 * mit Rate-Limit: jeder Treffer kostet Katalog-Meta-Reads.
 */
final class SearchRestController
{
    private const MAX_RESULTS = 8;

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'register']);
    }

    public function register(): void
    {
        register_rest_route(CartRestController::NAMESPACE, '/search', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'search'],
            'permission_callback' => '__return_true',
            'args' => [
                'q' => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    public function search(WP_REST_Request $request): WP_REST_Response
    {
        if (RateLimiter::tooMany('search', 30, MINUTE_IN_SECONDS)) {
            return new WP_REST_Response(
                ['message' => __('Zu viele Suchanfragen. Bitte warte einen Moment.', 'rh-shop')],
                429
            );
        }

        $term = sanitize_text_field((string) $request->get_param('q'));
        if (mb_strlen($term) < 2) {
            return new WP_REST_Response(['results' => []]);
        }

        $query = new WP_Query([
            's' => $term,
            'post_type' => ProductType::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => self::MAX_RESULTS,
            'no_found_rows' => true,
        ]);

        $variants = new VariantRepository();
        $render = new Render($variants, new Config());

        $results = [];
        foreach ($query->posts as $product) {
            $productId = (int) $product->ID;
            $thumb = get_the_post_thumbnail_url($productId, 'thumbnail');
            $results[] = [
                'id' => $productId,
                'title' => get_the_title($productId),
                'url' => (string) get_permalink($productId),
                'thumb' => is_string($thumb) ? $thumb : '',
                'price' => $render->priceLabel($productId),
                'soldOut' => $variants->availableStockSummary($productId, 0)->soldOut,
            ];
        }

        wp_reset_postdata();

        return new WP_REST_Response(['results' => $results]);
    }
}
