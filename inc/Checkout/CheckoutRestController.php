<?php

declare(strict_types=1);

namespace RhShop\Checkout;

use RhShop\Cart\Cart;
use RhShop\Cart\CartRestController;
use RhShop\Catalog\VariantRepository;
use RhShop\Orders\OrderStore;
use RhShop\Stripe\CheckoutService;
use RhShop\Stripe\Config;
use RhShop\Stripe\StripeClient;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST-Endpoint, den der "Zahlungspflichtig bestellen"-Button auslöst.
 *
 * Hier werden die §312j-Pflicht-Checkboxen (AGB, Widerruf, Datenschutz) UND die
 * E-Mail serverseitig geprüft, BEVOR die Bestellung angelegt und die Stripe-Session
 * erzeugt wird. Erst danach liefert der Endpoint das client_secret, mit dem das
 * Frontend die embedded Stripe-Zahl-UI mountet.
 */
final class CheckoutRestController
{
    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(CartRestController::NAMESPACE, '/checkout/session', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'createSession'],
            'permission_callback' => [$this, 'checkNonce'],
        ]);

        // Öffentlicher Read: der Käufer fragt auf der Danke-Seite den Rechnungsstatus
        // zu SEINER Session ab (die Session-ID ist die Zugangsberechtigung). Liefert nur
        // paid-Status und die Stripe-Rechnungs-URL, keine Kundendaten.
        register_rest_route(CartRestController::NAMESPACE, '/checkout/invoice', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'invoiceStatus'],
            'permission_callback' => '__return_true',
            'args' => ['session_id' => ['type' => 'string', 'required' => true]],
        ]);
    }

    public function invoiceStatus(WP_REST_Request $request): WP_REST_Response
    {
        $sessionId = sanitize_text_field((string) $request->get_param('session_id'));
        $order = str_starts_with($sessionId, 'cs_') ? (new OrderStore())->findBySessionId($sessionId) : null;

        if ($order === null) {
            return new WP_REST_Response(['paid' => false, 'invoice_url' => '']);
        }

        return new WP_REST_Response([
            'paid' => $order->isPaid(),
            'invoice_url' => $order->invoiceUrl,
        ]);
    }

    public function checkNonce(WP_REST_Request $request): bool
    {
        return (bool) wp_verify_nonce((string) $request->get_header('X-WP-Nonce'), 'wp_rest');
    }

    public function createSession(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $config = new Config();

        $revocation = rest_sanitize_boolean($request->get_param('accept_revocation'));
        $privacy = rest_sanitize_boolean($request->get_param('accept_privacy'));
        // AGB nur verlangen, wenn der Betreiber sie eingeschaltet hat (sie sind nicht
        // gesetzlich Pflicht). Widerruf + Datenschutz sind immer Pflicht.
        $terms = ! $config->agbEnabled() || rest_sanitize_boolean($request->get_param('accept_terms'));

        if (! $terms || ! $revocation || ! $privacy) {
            $missing = $config->agbEnabled()
                ? __('Bitte bestätige AGB, Widerrufsbelehrung und Datenschutz.', 'rh-shop')
                : __('Bitte bestätige Widerrufsbelehrung und Datenschutz.', 'rh-shop');

            return new WP_Error('rhshop_terms_required', $missing, ['status' => 400]);
        }

        $email = sanitize_email((string) $request->get_param('email'));
        if (! is_email($email)) {
            return new WP_Error('rhshop_email_invalid', __('Bitte gib eine gültige E-Mail-Adresse an.', 'rh-shop'), ['status' => 400]);
        }

        $name = sanitize_text_field((string) $request->get_param('name'));
        $service = new CheckoutService($config, new StripeClient($config), new OrderStore());

        $result = $service->createSession(new Cart(new VariantRepository()), ['email' => $email, 'name' => $name]);

        if ($result instanceof WP_Error) {
            return $result;
        }

        return new WP_REST_Response($result);
    }
}
