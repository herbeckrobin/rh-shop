<?php

declare(strict_types=1);

namespace RhShop\Stripe;

use RhShop\Cart\Cart;
use RhShop\Cart\CartLine;
use RhShop\Checkout\Totals;
use RhShop\Orders\OrderStore;
use Stripe\Exception\ApiErrorException;
use WP_Error;

/**
 * Legt die Bestellung an und erzeugt die embedded Stripe Checkout Session.
 *
 * Reihenfolge ist bewusst: ZUERST die verbindliche Bestellung (pending) in unserer
 * DB, DANN die Stripe-Session. Das erfüllt die §312j-Auflage "Bestellung wird am
 * eigenen Button final angelegt, bevor es zu Stripe geht". Stripe kennt den Katalog
 * nicht: die line_items werden dynamisch als price_data aus dem Warenkorb gebaut
 * (Bruttopreise, kein Stripe Tax). Der Warenkorb-Total wird serverseitig gerechnet,
 * ein manipuliertes Cookie kann den Betrag nicht fälschen.
 */
final class CheckoutService
{
    public function __construct(
        private readonly Config $config,
        private readonly StripeClient $stripe,
        private readonly OrderStore $orders,
    ) {
    }

    /**
     * @param array{email?:string, name?:string} $buyer
     * @return array{client_secret:string, order_id:int, order_number:string, session_id:string}|WP_Error
     */
    public function createSession(Cart $cart, array $buyer): array|WP_Error
    {
        $lines = $cart->lines();
        if ($lines === []) {
            return new WP_Error('rhshop_empty_cart', __('Der Warenkorb ist leer.', 'rh-shop'), ['status' => 400]);
        }

        $client = $this->stripe->client();
        if ($client === null) {
            return new WP_Error('rhshop_not_configured', __('Zahlung ist nicht konfiguriert.', 'rh-shop'), ['status' => 503]);
        }

        $currency = $this->config->currency();
        $totals = Totals::forCart($cart, $this->config);

        // Bestellung ZUERST anlegen (Positions-Snapshot).
        $orderId = $this->orders->create([
            'currency' => $currency,
            'email' => $buyer['email'] ?? '',
            'customer_name' => $buyer['name'] ?? '',
            'items' => array_map([$this, 'snapshot'], $lines),
            'subtotal_cents' => $totals->subtotalCents,
            'shipping_cents' => $totals->shippingCents,
            'tax_cents' => $totals->taxCents,
            'total_cents' => $totals->totalCents,
            'tax_mode' => $totals->taxMode,
        ]);

        if ($orderId === 0) {
            return new WP_Error('rhshop_order_failed', __('Bestellung konnte nicht angelegt werden.', 'rh-shop'), ['status' => 500]);
        }

        $order = $this->orders->find($orderId);
        $orderNumber = $order?->orderNumber ?? '';

        $params = [
            'mode' => 'payment',
            'ui_mode' => 'embedded',
            'line_items' => array_map(
                fn (CartLine $l): array => [
                    'quantity' => $l->qty,
                    'price_data' => [
                        'currency' => $currency,
                        'unit_amount' => $l->unitPriceCents,
                        'product_data' => [
                            'name' => $l->optionsLabel !== '' ? $l->productTitle . ' (' . $l->optionsLabel . ')' : $l->productTitle,
                        ],
                    ],
                ],
                $lines
            ),
            'return_url' => $this->returnUrl(),
            'metadata' => ['order_id' => (string) $orderId, 'order_number' => $orderNumber],
            'payment_intent_data' => ['metadata' => ['order_id' => (string) $orderId, 'order_number' => $orderNumber]],
        ];

        if (! empty($buyer['email'])) {
            $params['customer_email'] = $buyer['email'];
        }

        if ($totals->shippingCents > 0) {
            $params['shipping_options'] = [[
                'shipping_rate_data' => [
                    'type' => 'fixed_amount',
                    'fixed_amount' => ['amount' => $totals->shippingCents, 'currency' => $currency],
                    'display_name' => __('Versand', 'rh-shop'),
                ],
            ]];
        }

        $params['shipping_address_collection'] = [
            'allowed_countries' => (array) apply_filters('rh-blueprint/shop/shipping_countries', ['DE', 'AT', 'CH']),
        ];

        try {
            $session = $client->checkout->sessions->create($params);
        } catch (ApiErrorException $e) {
            return new WP_Error('rhshop_stripe_error', $e->getMessage(), ['status' => 502]);
        }

        $this->orders->attachSession($orderId, (string) $session->id);

        return [
            'client_secret' => (string) $session->client_secret,
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'session_id' => (string) $session->id,
        ];
    }

    /**
     * Positions-Snapshot für die Bestellung.
     *
     * @return array<string, mixed>
     */
    private function snapshot(CartLine $line): array
    {
        return [
            'product_id' => $line->productId,
            'variant_id' => $line->variantId,
            'title' => $line->productTitle,
            'options' => $line->optionsLabel,
            'sku' => $line->sku,
            'unit_price_cents' => $line->unitPriceCents,
            'qty' => $line->qty,
            'line_total_cents' => $line->lineTotalCents(),
        ];
    }

    /**
     * Rück-URL nach abgeschlossener Zahlung. Stripe ersetzt {CHECKOUT_SESSION_ID}.
     * Manuell zusammengesetzt, weil add_query_arg die geschweiften Klammern encoden
     * würde.
     */
    private function returnUrl(): string
    {
        $base = (string) apply_filters('rh-blueprint/shop/return_url', home_url('/danke'));
        $sep = str_contains($base, '?') ? '&' : '?';

        return $base . $sep . 'session_id={CHECKOUT_SESSION_ID}';
    }
}
