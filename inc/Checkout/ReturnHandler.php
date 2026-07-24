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
 * Browser des Käufers passieren: genau das ist der Rücksprung auf `/danke?session_id=…`
 * nach abgeschlossener Zahlung.
 *
 * Läuft auf `template_redirect` (vor jeder Ausgabe, damit setcookie greift). Geleert
 * wird nur, wenn die session_id zu einer echten Bestellung gehört (embedded Checkout
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
        $sessionId = isset($_GET['session_id']) ? sanitize_text_field(wp_unslash($_GET['session_id'])) : '';
        if ($sessionId === '' || ! str_starts_with($sessionId, 'cs_')) {
            return;
        }

        // Nur leeren, wenn die Session zu einer bei uns angelegten Bestellung gehört.
        if ((new OrderStore())->findBySessionId($sessionId) === null) {
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
