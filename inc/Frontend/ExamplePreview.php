<?php

declare(strict_types=1);

namespace RhShop\Frontend;

defined( 'ABSPATH' ) || exit;

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

        // Hat der Redakteur selbst etwas im Warenkorb, zeigt die Vorschau genau das,
        // sonst wäre der Editor-Blick ein anderer als der eigene Test im Frontend.
        $own = new Cart($repo);
        if (! $own->isEmpty()) {
            return $own;
        }

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
     * Produkt für die Vorschau. Bearbeitet der Redakteur gerade ein konkretes
     * Produkt, ist das DIESES (der Editor schickt seine post_id mit): die Vorschau
     * zeigt dann echte Bilder, Preise und Varianten des Produkts vor einem. Erst im
     * Template-Editor, wo es kein konkretes Produkt gibt, greift das erste aus dem
     * Katalog als Beispiel. 0, wenn der Katalog leer ist.
     */
    public static function productId(): int
    {
        $context = self::contextProductId();
        if ($context > 0) {
            return $context;
        }

        $products = self::products(1);

        return $products === [] ? 0 : (int) $products[0]->ID;
    }

    /**
     * Die vom Editor mitgeschickte post_id, wenn sie zu einem Produkt gehört.
     * 0 im Template-Editor oder auf einer normalen Seite.
     */
    public static function contextProductId(): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reines Anzeige-Flag der Vorschau, zusätzlich Capability-gated über isActive().
        $postId = isset($_GET['rhshop_post']) ? absint($_GET['rhshop_post']) : 0;
        if ($postId <= 0) {
            return 0;
        }

        $post = get_post($postId);

        return $post instanceof WP_Post && $post->post_type === ProductType::POST_TYPE ? $postId : 0;
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
