<?php

declare(strict_types=1);

namespace RhShop\Stripe;

use RhShop\Cart\CartRestController;
use RhShop\Orders\Fulfillment;
use RhShop\Orders\OrderStore;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Stripe-Webhook. Öffentlicher Endpoint (Stripe ruft ihn serverseitig auf), gesichert
 * NICHT über WP-Auth, sondern über die Stripe-Signatur gegen den ROHEN Body.
 *
 * Die Bestellung wird ausschließlich hier auf "bezahlt" gesetzt, nie clientseitig:
 * ein erfolgreicher Redirect im Browser beweist keine Zahlung (Tab geschlossen,
 * manipuliert, blockiert). Nur das signierte `checkout.session.completed`-Event mit
 * `payment_status = paid` ist der Beweis. Idempotenz liegt in OrderStore::markPaid,
 * ein doppeltes Event bucht also nicht doppelt.
 */
final class WebhookController
{
    /** Events, die eine bezahlte Bestellung bestätigen (Karte sofort, SEPA verzögert). */
    private const PAID_EVENTS = ['checkout.session.completed', 'checkout.session.async_payment_succeeded'];

    public function __construct(
        private readonly Config $config,
        private readonly OrderStore $orders,
        private readonly Fulfillment $fulfillment,
    ) {
    }

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(CartRestController::NAMESPACE, '/webhook', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $secret = $this->config->webhookSecret();
        if ($secret === '') {
            return new WP_REST_Response(['error' => 'webhook_not_configured'], 503);
        }

        $payload = $request->get_body();
        $signature = (string) $request->get_header('stripe-signature');

        try {
            $event = Webhook::constructEvent($payload, $signature, $secret);
        } catch (SignatureVerificationException | UnexpectedValueException $e) {
            return new WP_REST_Response(['error' => 'invalid_signature'], 400);
        }

        if (in_array($event->type, self::PAID_EVENTS, true)) {
            $this->fulfillSession($event->data->object);
        }

        return new WP_REST_Response(['received' => true], 200);
    }

    /**
     * @param object $session Stripe Checkout Session aus dem Event.
     */
    private function fulfillSession(object $session): void
    {
        if (($session->payment_status ?? '') !== 'paid') {
            return; // verzögerte Zahlung: kommt später über async_payment_succeeded.
        }

        $sessionId = (string) ($session->id ?? '');
        if ($sessionId === '') {
            return;
        }

        $paymentIntent = $session->payment_intent ?? '';
        $paymentIntentId = is_string($paymentIntent) ? $paymentIntent : (string) ($paymentIntent->id ?? '');

        $order = $this->orders->markPaid($sessionId, $paymentIntentId, $this->buyer($session));

        // Nur wenn frisch auf bezahlt umgestellt (Idempotenz), Bestand + Mail.
        if ($order !== null) {
            $this->fulfillment->fulfill($order);
        }
    }

    /**
     * @param object $session
     * @return array{email?:string, name?:string, address?:array<string, string>}
     */
    private function buyer(object $session): array
    {
        $details = $session->customer_details ?? null;

        $address = [];
        if ($details !== null && isset($details->address) && is_object($details->address)) {
            foreach (['line1', 'line2', 'city', 'postal_code', 'state', 'country'] as $key) {
                if (isset($details->address->$key) && $details->address->$key !== null) {
                    $address[$key] = (string) $details->address->$key;
                }
            }
        }

        return [
            'email' => (string) ($details->email ?? $session->customer_email ?? ''),
            'name' => (string) ($details->name ?? ''),
            'address' => $address,
        ];
    }
}
