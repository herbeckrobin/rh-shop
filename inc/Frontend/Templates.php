<?php

declare(strict_types=1);

namespace RhShop\Frontend;

use RhShop\Catalog\ProductType;

/**
 * Registriert das Single-Template der Produkt-CPT über register_block_template
 * (WP 6.7+). Der Slug MUSS `single-{posttype}` heißen, dann greift es automatisch als
 * Default-Single-Template, ohne dass der Nutzer es zuweisen muss.
 *
 * Warum überhaupt ein eigenes Template: sonst rendert das Blog-Single-Template des
 * Themes die Produktseite, samt Autor-Byline ("Verfasst von") und Datum, was für ein
 * Produkt falsch ist. Das eigene Template zeigt Bild, Titel, Kauf-Box und
 * Beschreibung und ist im Site-Editor frei anpassbar (Editor-Souveränität).
 *
 * Auf WP < 6.7 (kein register_block_template) übernimmt der the_content-Filter in
 * {@see SingleProduct} als Fallback, dann hängt die Kauf-Box unter dem Inhalt.
 */
final class Templates
{
    public static function available(): bool
    {
        return function_exists('register_block_template');
    }

    public function boot(): void
    {
        if (! self::available()) {
            return;
        }

        $this->register();
    }

    public function register(): void
    {
        register_block_template('rh-shop//single-' . ProductType::POST_TYPE, [
            'title' => __('Einzelprodukt (Shop)', 'rh-shop'),
            'description' => __('Produktseite: Bild, Titel, Kauf-Box und Beschreibung. Im Site-Editor frei anpassbar.', 'rh-shop'),
            'post_types' => [ProductType::POST_TYPE],
            'content' => $this->content(),
        ]);
    }

    /**
     * Standard-Layout als Block-Markup: zwei Spalten (Bild | Titel + Kauf-Box), die
     * Beschreibung darunter über die volle Breite. Referenziert die Header-/Footer-
     * Template-Parts des Themes, erbt also dessen Rahmen. Der Kunde ordnet das im
     * Editor um wie er will.
     */
    private function content(): string
    {
        return '<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /-->'
            . '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->'
            . '<main class="wp-block-group">'
            . '<!-- wp:columns {"align":"wide"} -->'
            . '<div class="wp-block-columns alignwide">'
            . '<!-- wp:column -->'
            . '<div class="wp-block-column"><!-- wp:post-featured-image /--></div>'
            . '<!-- /wp:column -->'
            . '<!-- wp:column -->'
            . '<div class="wp-block-column">'
            . '<!-- wp:post-title {"level":1} /-->'
            . '<!-- wp:rh-shop/buy-box /-->'
            . '</div>'
            . '<!-- /wp:column -->'
            . '</div>'
            . '<!-- /wp:columns -->'
            . '<!-- wp:post-content /-->'
            . '</main>'
            . '<!-- /wp:group -->'
            . '<!-- wp:template-part {"slug":"footer","area":"footer","tagName":"footer"} /-->';
    }
}
