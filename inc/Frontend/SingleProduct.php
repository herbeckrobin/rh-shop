<?php

declare(strict_types=1);

namespace RhShop\Frontend;

defined( 'ABSPATH' ) || exit;

use RhShop\Catalog\ProductType;

/**
 * Fallback für WP < 6.7: hängt die Kauf-Steuerung unter den Inhalt einer
 * Produkt-Detailseite. Ab 6.7 liefert das registrierte Single-Template die Kauf-Box
 * als platzierbaren Block ({@see Templates}), dann macht dieser Filter nichts (sonst
 * doppelt). Titel/Beschreibung/Bild rendert das Theme bzw. Template.
 */
final class SingleProduct
{
    public function __construct(private readonly Render $render)
    {
    }

    public function boot(): void
    {
        add_filter('the_content', [$this, 'appendControls'], 20);
    }

    public function appendControls(string $content): string
    {
        // Ab WP 6.7 liefert das registrierte Template die Kauf-Box als Block.
        if (Templates::available()) {
            return $content;
        }

        if (is_admin() || ! is_singular(ProductType::POST_TYPE) || ! in_the_loop() || ! is_main_query()) {
            return $content;
        }

        return $content . $this->render->controls((int) get_the_ID());
    }
}
