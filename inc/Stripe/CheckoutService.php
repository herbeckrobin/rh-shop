<?php

declare(strict_types=1);

namespace RhShop\Stripe;

defined( 'ABSPATH' ) || exit;

use RhShop\Cart\Cart;
use RhShop\Cart\CartLine;
use RhShop\Catalog\ReservationRepository;
use RhShop\Checkout\Totals;
use RhShop\Orders\Order;
use RhShop\Orders\OrderStore;
use Stripe\Exception\ApiErrorException;
use WP_Error;

/**
 * Legt die Bestellung an und erzeugt den Stripe PaymentIntent für das Payment Element.
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
        private readonly ReservationRepository $reservations = new ReservationRepository(),
    ) {
    }

    /**
     * @param array{email?:string, name?:string} $buyer
     * @return array{client_secret:string, order_id:int, order_number:string, payment_intent_id:string}|WP_Error
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

        // Bestand reservieren (gegen Überverkauf), BEVOR die Zahlung startet. Klappt
        // eine Position nicht, wird die ganze Bestellung freigegeben und sauber
        // abgebrochen, kein PaymentIntent, der Kunde zahlt nie für Vergriffenes.
        $holdMinutes = $this->config->reservationHoldMinutes();
        foreach ($lines as $line) {
            if ($this->reservations->reserve($orderId, $line->productId, $line->variantId, $line->qty, $holdMinutes)) {
                continue;
            }

            $this->reservations->releaseForOrder($orderId);
            $this->orders->updateStatus($orderId, Order::STATUS_CANCELLED);

            $name = $line->productTitle . ($line->optionsLabel !== '' ? ' (' . $line->optionsLabel . ')' : '');

            return new WP_Error(
                'rhshop_out_of_stock',
                /* translators: %s: Artikelname */
                sprintf(__('„%s" ist leider gerade vergriffen. Bitte passe die Menge an.', 'rh-shop'), $name),
                ['status' => 409]
            );
        }

        // Payment Element: ein PaymentIntent über den Warenkorb-Total (Bruttopreis,
        // Versand + enthaltene Steuer schon eingerechnet). Die Zahlarten wählt Stripe
        // automatisch, die Felder rendert das Frontend über die Elements. Die
        // Lieferadresse liefert das Address Element beim confirmPayment.
        $params = [
            'amount' => $totals->totalCents,
            'currency' => $currency,
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => ['order_id' => (string) $orderId, 'order_number' => $orderNumber],
        ];

        if (! empty($buyer['email'])) {
            $params['receipt_email'] = sanitize_email((string) $buyer['email']);
        }

        try {
            $intent = $client->paymentIntents->create($params);
        } catch (ApiErrorException $e) {
            // Erwartbarer externer Fehlschlag: dem Kunden eine generische, saubere
            // Meldung geben (kein roher Stripe-Text nach aussen). Der Prozess bricht
            // sauber ab, es wurde nichts belastet. Reservierung freigeben, damit der
            // Bestand nicht bis zum Ablauf blockiert bleibt.
            $this->reservations->releaseForOrder($orderId);

            return new WP_Error(
                'rhshop_stripe_error',
                __('Die Zahlung konnte gerade nicht gestartet werden. Bitte versuche es in einem Moment erneut.', 'rh-shop'),
                ['status' => 502]
            );
        }

        $this->orders->attachPaymentIntent($orderId, (string) $intent->id);

        return [
            'client_secret' => (string) $intent->client_secret,
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'payment_intent_id' => (string) $intent->id,
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
}
