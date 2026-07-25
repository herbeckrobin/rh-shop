<?php

declare(strict_types=1);

namespace RhShop\Frontend;

use RhShop\Cart\Cart;
use RhShop\Catalog\ProductType;
use RhShop\Catalog\VariantRepository;
use WP_Post;

/**
 * Beispiel-Daten für die Editor-Vorschau. Die dynamischen Blocks (Warenkorb, Kasse,
 * Kauf-Box) sind im Frontend leer bzw. kontextabhängig. Im Editor sollen sie eine
 * Beispielansicht mit echten Katalog-Produkten zeigen, damit der Betreiber sich das
 * Ergebnis vorstellen kann. Der Editor ruft die Server-Vorschau mit `?rhshop_preview=1`,
 * das Flag greift nur für eingeloggte Redakteure (kein öffentlicher Effekt).
 */
final class ExamplePreview
{
    public static function isActive(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reines Anzeige-Flag der ServerSideRender-Vorschau, keine Aktion, zusätzlich Capability-gated.
        return isset($_GET['rhshop_preview']) && current_user_can('edit_posts');
    }

    /**
     * Ein Warenkorb, in-memory mit bis zu zwei echten Produkten befüllt (NICHT
     * persistiert, der echte Warenkorb des Nutzers bleibt unberührt). Null, wenn es
     * keine kaufbaren Produkte gibt.
     */
    public static function cart(): ?Cart
    {
        $repo = new VariantRepository();
        $cart = new Cart($repo);

        foreach (self::products(2) as $product) {
            $variant = $repo->cheapestVariant((int) $product->ID);
            if ($variant !== null) {
                $cart->add((int) $product->ID, $variant->id, 1);
            }
        }

        return $cart->isEmpty() ? null : $cart;
    }

    /**
     * ID des ersten kaufbaren Produkts (für die Kauf-Box-Vorschau), 0 wenn keins.
     */
    public static function productId(): int
    {
        $products = self::products(1);

        return $products === [] ? 0 : (int) $products[0]->ID;
    }

    /**
     * @return array<int, WP_Post>
     */
    private static function products(int $count): array
    {
        // Deterministisch nach ID sortieren: die getrennten Kassen-/Warenkorb-Blöcke
        // ziehen jeweils eigenständig ihre Beispiel-Produkte, sie müssen dieselben in
        // derselben Reihenfolge treffen, sonst passen Positionen und Summe nicht zusammen.
        $posts = get_posts([
            'post_type' => ProductType::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => $count,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        return is_array($posts) ? $posts : [];
    }
}
