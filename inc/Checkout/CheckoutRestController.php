<?php

declare(strict_types=1);

namespace RhShop\Checkout;

defined( 'ABSPATH' ) || exit;

use RhShop\Cart\Cart;
use RhShop\Cart\CartRestController;
use RhShop\Catalog\VariantRepository;
use RhShop\Orders\OrderStore;
use RhShop\Shipping\ShippingMethods;
use RhShop\Stripe\CheckoutService;
use RhShop\Stripe\Config;
use RhShop\Stripe\StripeClient;
use RhShop\Support\RateLimiter;
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

        // Preis-Vorschau für eine gewählte Versandmethode. Rechnet die Totals server-
        // seitig (eine Quelle), damit die Anzeige und der Stripe-Betrag konsistent
        // bleiben, wenn der Kunde die Methode im Checkout wechselt. Kein Stripe-Call,
        // keine Reservierung, nur eine Rechnung, darum ohne Rate-Limit (Nonce reicht).
        register_rest_route(CartRestController::NAMESPACE, '/checkout/quote', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'quote'],
            'permission_callback' => [$this, 'checkNonce'],
        ]);

        // Öffentlicher Read: der Käufer fragt auf der Danke-Seite den Rechnungsstatus
        // zu SEINEM PaymentIntent ab (die PI-ID ist die Zugangsberechtigung). Liefert nur
        // paid-Status und die Stripe-Rechnungs-URL, keine Kundendaten.
        register_rest_route(CartRestController::NAMESPACE, '/checkout/invoice', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'invoiceStatus'],
            'permission_callback' => '__return_true',
            'args' => ['payment_intent' => ['type' => 'string', 'required' => true]],
        ]);
    }

    public function invoiceStatus(WP_REST_Request $request): WP_REST_Response
    {
        $paymentIntent = sanitize_text_field((string) $request->get_param('payment_intent'));
        $order = str_starts_with($paymentIntent, 'pi_') ? (new OrderStore())->findByPaymentIntent($paymentIntent) : null;

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

    /**
     * Preis-Vorschau: die Totals für den aktuellen Warenkorb mit der gewählten
     * Versandmethode. Liefert die formatierten Werte + total_cents (für die Stripe-
     * Betrags-Aktualisierung) und die aufgelöste Methoden-Id.
     */
    public function quote(WP_REST_Request $request): WP_REST_Response
    {
        $config = new Config();
        $methodId = sanitize_text_field((string) $request->get_param('shipping_method'));
        $method = ShippingMethods::make()->resolveForCheckout($methodId);
        $totals = Totals::forCart(new Cart(new VariantRepository()), $config, $method);

        $state = $totals->toState($config->currencySymbol());
        $state['shipping_method'] = $method->id;

        return new WP_REST_Response($state);
    }

    public function createSession(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        // Rate-Limit gegen Denial-of-Inventory: jeder Aufruf reserviert Bestand + kostet
        // einen Stripe-Call. Ein echter Kunde bestellt selten mehr als ein paar Mal.
        if (RateLimiter::tooMany('checkout', 8, MINUTE_IN_SECONDS)) {
            return new WP_Error('rhshop_rate_limited', __('Zu viele Anfragen. Bitte warte einen Moment und versuch es erneut.', 'rh-shop'), ['status' => 429]);
        }

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
        $shippingMethod = sanitize_text_field((string) $request->get_param('shipping_method'));
        $service = new CheckoutService($config, new StripeClient($config), new OrderStore());

        $result = $service->createSession(new Cart(new VariantRepository()), ['email' => $email, 'name' => $name], $shippingMethod);

        if ($result instanceof WP_Error) {
            return $result;
        }

        return new WP_REST_Response($result);
    }
}
