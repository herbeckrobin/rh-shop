<?php

declare(strict_types=1);

namespace RhShop\Cart;

defined( 'ABSPATH' ) || exit;

use RhShop\Catalog\VariantRepository;
use RhShop\Stripe\Config;
use RhShop\Support\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST-Endpoints für den Warenkorb (add/update/remove/get).
 *
 * Öffentlich (der Shop ist für Gäste), aber gegen CSRF per REST-Nonce (X-WP-Nonce)
 * abgesichert. Jeder Handler baut den Cart aus dem Cookie, mutiert, schreibt das
 * Cookie (persist läuft vor der Antwort, Header noch nicht raus) und gibt den
 * frischen Zustand zurück, aus dem das Frontend Mini-Cart und Warenkorb rendert.
 */
final class CartRestController
{
    public const NAMESPACE = 'rhshop/v1';

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $writable = [
            'methods' => WP_REST_Server::CREATABLE,
            'permission_callback' => [$this, 'checkNonce'],
        ];

        register_rest_route(self::NAMESPACE, '/cart', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getCart'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/cart/add', $writable + ['callback' => [$this, 'add']]);
        register_rest_route(self::NAMESPACE, '/cart/update', $writable + ['callback' => [$this, 'update']]);
        register_rest_route(self::NAMESPACE, '/cart/remove', $writable + ['callback' => [$this, 'remove']]);
    }

    public function checkNonce(WP_REST_Request $request): bool
    {
        return (bool) wp_verify_nonce((string) $request->get_header('X-WP-Nonce'), 'wp_rest');
    }

    public function getCart(): WP_REST_Response
    {
        return $this->respond($this->cart());
    }

    public function add(WP_REST_Request $request): WP_REST_Response|\WP_Error
    {
        // Rate-Limit wie bei checkout/session: seit dem Quick-Add-Button liegt dieser
        // Endpoint auf jeder Raster-Karte offen, jeder Aufruf kostet Katalog-Lookups.
        // Großzügig genug für echtes Stöbern, eng genug gegen Bot-Dauerfeuer.
        if (RateLimiter::tooMany('cart', 60, MINUTE_IN_SECONDS)) {
            return new \WP_Error('rhshop_rate_limited', __('Zu viele Anfragen. Bitte warte einen Moment und versuch es erneut.', 'rh-shop'), ['status' => 429]);
        }

        $cart = $this->cart();
        $capped = $cart->add(
            (int) $request->get_param('product_id'),
            sanitize_text_field((string) $request->get_param('variant_id')),
            (int) ($request->get_param('qty') ?? 1)
        );

        return $this->respond($cart, $capped);
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $cart = $this->cart();
        $capped = $cart->setQty(
            (int) $request->get_param('product_id'),
            sanitize_text_field((string) $request->get_param('variant_id')),
            (int) $request->get_param('qty')
        );

        return $this->respond($cart, $capped);
    }

    public function remove(WP_REST_Request $request): WP_REST_Response
    {
        $cart = $this->cart();
        $cart->remove(
            (int) $request->get_param('product_id'),
            sanitize_text_field((string) $request->get_param('variant_id'))
        );

        return $this->respond($cart);
    }

    private function cart(): Cart
    {
        return new Cart(new VariantRepository());
    }

    private function respond(Cart $cart, ?int $capped = null): WP_REST_Response
    {
        $cart->persist();

        $state = $cart->toState(new Config());

        // Wurde die Menge wegen Bestand gedeckelt, eine Warnung mitgeben (die UI
        // zeigt sie an). Die Zahl kommt aus dem Domain-Ergebnis, der Text ist Anzeige.
        if ($capped !== null) {
            $state['notice'] = sprintf(
                /* translators: %d: verbleibender Bestand */
                _n('Nur noch %d verfügbar, die Menge wurde angepasst.', 'Nur noch %d verfügbar, die Menge wurde angepasst.', $capped, 'rh-shop'),
                $capped
            );
        }

        return new WP_REST_Response($state);
    }
}
