<?php

declare(strict_types=1);

namespace RhShop\Frontend;

use RhShop\Catalog\ProductType;

/**
 * Hängt die Kauf-Steuerung (Preis, Varianten, In-den-Warenkorb) unter den Inhalt
 * einer Produkt-Detailseite. So funktioniert die Produktseite mit jedem Theme,
 * ohne ein eigenes Single-Template zu erzwingen. Titel/Beschreibung/Bild rendert
 * das Theme wie gewohnt, hier kommt nur die Commerce-UI dazu.
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
        if (is_admin() || ! is_singular(ProductType::POST_TYPE) || ! in_the_loop() || ! is_main_query()) {
            return $content;
        }

        return $content . $this->render->controls((int) get_the_ID());
    }
}
