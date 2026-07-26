<?php

declare(strict_types=1);

namespace RhShop\Orders;

defined( 'ABSPATH' ) || exit;

use RhShop\Catalog\ReservationRepository;
use RhShop\Catalog\VariantRepository;
use RhShop\Stripe\InvoiceService;

/**
 * Was nach bestätigter Zahlung passiert: Bestand reduzieren, Rechnung erstellen und
 * die Bestätigungsmails verschicken. Wird NUR aufgerufen, wenn die Bestellung frisch
 * auf "bezahlt" übergegangen ist (OrderStore::markPaid ist idempotent), darum kein
 * doppelter Bestand-Abzug, keine doppelte Rechnung und keine doppelte Mail bei einem
 * wiederholten Webhook-Event.
 *
 * Die Rechnung ist best-effort: schlägt die Stripe-Rechnung fehl, bricht die
 * Bestellabwicklung (Bestand, Mail) trotzdem nicht ab.
 */
final class Fulfillment
{
    public function __construct(
        private readonly VariantRepository $variants,
        private readonly OrderMailer $mailer,
        private readonly OrderStore $orders,
        private readonly ?InvoiceService $invoices,
        private readonly bool $invoiceEnabled,
        private readonly ReservationRepository $reservations = new ReservationRepository(),
    ) {
    }

    public function fulfill(Order $order): void
    {
        foreach ($order->items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $variantId = (string) ($item['variant_id'] ?? '');
            $qty = (int) ($item['qty'] ?? 0);

            if ($productId > 0 && $variantId !== '' && $qty > 0) {
                $this->variants->decrementStock($productId, $variantId, $qty);
            }
        }

        // Reservierung dieser Bestellung auflösen: der Bestand ist jetzt echt
        // reduziert, die Reservierung hat ihren Zweck erfüllt.
        $this->reservations->releaseForOrder($order->id);

        $invoiceUrl = '';
        if ($this->invoiceEnabled && $this->invoices !== null) {
            $ref = $this->invoices->createForOrder($order);
            if ($ref !== null) {
                $this->orders->saveInvoice($order->id, $ref['id'], $ref['number'], $ref['url']);
                $invoiceUrl = $ref['url'];
            }
        }

        $this->mailer->sendConfirmation($order, $invoiceUrl);

        /**
         * Nach abgeschlossener Bestellung (bezahlt, Bestand gebucht, Rechnung + Mail raus).
         *
         * @param Order $order
         */
        do_action('rh-blueprint/shop/order_paid', $order);
    }
}
