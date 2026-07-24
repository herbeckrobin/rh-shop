<?php

declare(strict_types=1);

namespace RhShop\Orders;

use RhShop\Catalog\VariantRepository;

/**
 * Was nach bestätigter Zahlung passiert: Bestand reduzieren und die Bestätigungs-
 * mails verschicken. Wird NUR aufgerufen, wenn die Bestellung frisch auf "bezahlt"
 * übergegangen ist (OrderStore::markPaid ist idempotent), darum kein doppelter
 * Bestand-Abzug und keine doppelte Mail bei einem wiederholten Webhook-Event.
 */
final class Fulfillment
{
    public function __construct(
        private readonly VariantRepository $variants,
        private readonly OrderMailer $mailer,
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

        $this->mailer->sendConfirmation($order);

        /**
         * Nach abgeschlossener Bestellung (bezahlt, Bestand gebucht, Mail raus).
         * Anker für Erweiterungen (z.B. Stripe-Rechnung anstoßen, Versanddienst).
         *
         * @param Order $order
         */
        do_action('rh-blueprint/shop/order_paid', $order);
    }
}
