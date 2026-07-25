<?php

declare(strict_types=1);

namespace RhShop\Checkout;

use RhShop\Cart\Cart;
use RhShop\Catalog\VariantRepository;
use RhShop\Orders\OrderStore;

/**
 * Leert den Warenkorb, nachdem Stripe den Käufer auf die Rück-URL geführt hat.
 *
 * Der Webhook läuft serverseitig OHNE die Session/den Cookie des Käufers, kann den
 * Warenkorb-Cookie also nicht anfassen. Das Leeren muss darum in einem Request im
 * Browser des Käufers passieren: genau das ist der Rücksprung auf `/danke?payment_intent=…`
 * nach abgeschlossener Zahlung.
 *
 * Läuft auf `template_redirect` (vor jeder Ausgabe, damit setcookie greift). Geleert
 * wird nur, wenn der payment_intent zu einer echten Bestellung gehört (Stripe Payment Element
 * leitet erst nach abgeschlossener Zahlung auf die Rück-URL, ein Rücksprung ist also
 * ein abgeschlossener Kauf).
 */
final class ReturnHandler
{
    public function boot(): void
    {
        add_action('template_redirect', [$this, 'maybeClearCart']);
    }

    public function maybeClearCart(): void
    {
        if (is_admin() || headers_sent()) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Landing von Stripe, kein Formular; leert nur den eigenen Cookie.
        $paymentIntent = isset($_GET['payment_intent']) ? sanitize_text_field(wp_unslash($_GET['payment_intent'])) : '';
        if ($paymentIntent === '' || ! str_starts_with($paymentIntent, 'pi_')) {
            return;
        }

        // Nur leeren, wenn der PaymentIntent zu einer bei uns angelegten Bestellung gehört.
        if ((new OrderStore())->findByPaymentIntent($paymentIntent) === null) {
            return;
        }

        $cart = new Cart(new VariantRepository());
        if ($cart->isEmpty()) {
            return;
        }

        $cart->clear();
        $cart->persist();
    }
}
