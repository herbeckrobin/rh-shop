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
    private const PAID_EVENTS = ['payment_intent.succeeded'];

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
            $this->fulfillPaymentIntent($event->data->object);
        }

        return new WP_REST_Response(['received' => true], 200);
    }

    /**
     * @param object $intent Stripe PaymentIntent aus dem Event.
     */
    private function fulfillPaymentIntent(object $intent): void
    {
        $intentId = (string) ($intent->id ?? '');
        if ($intentId === '') {
            return;
        }

        $order = $this->orders->markPaidByPaymentIntent($intentId, $this->buyer($intent));

        // Nur wenn frisch auf bezahlt umgestellt (Idempotenz), Bestand + Mail. Ein
        // unbekannter/fremder PaymentIntent ergibt null -> sauberes 200, kein Retry.
        //
        // Fulfillment-Fehler werden bewusst NICHT gefangen: eine unerwartete Exception
        // propagiert (WP gibt 500, Stripe wiederholt den Webhook, rh-monitor erfasst sie).
        // Beim Retry blockt der paid-Guard oben ein zweites Fulfillment. rh-shop loggt
        // selbst nichts, die Sichtbarkeit erledigen WP und das Monitoring-Plugin.
        if ($order !== null) {
            $this->fulfillment->fulfill($order);
        }
    }

    /**
     * Käuferdaten aus dem PaymentIntent: E-Mail aus receipt_email, Name und Anschrift
     * aus shipping (liefert das Address Element beim confirmPayment).
     *
     * @param object $intent
     * @return array{email?:string, name?:string, address?:array<string, string>}
     */
    private function buyer(object $intent): array
    {
        $shipping = $intent->shipping ?? null;

        $address = [];
        if ($shipping !== null && isset($shipping->address) && is_object($shipping->address)) {
            foreach (['line1', 'line2', 'city', 'postal_code', 'state', 'country'] as $key) {
                if (isset($shipping->address->$key) && $shipping->address->$key !== null) {
                    $address[$key] = (string) $shipping->address->$key;
                }
            }
        }

        return [
            'email' => (string) ($intent->receipt_email ?? ''),
            'name' => (string) ($shipping->name ?? ''),
            'address' => $address,
        ];
    }
}
