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
            . '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"},"style":{"spacing":{"padding":{"top":"clamp(2rem, 5vw, 4rem)","bottom":"clamp(3rem, 6vw, 5rem)"}}}} -->'
            . '<main class="wp-block-group" style="padding-top:clamp(2rem, 5vw, 4rem);padding-bottom:clamp(3rem, 6vw, 5rem)">'
            . '<!-- wp:columns {"align":"wide","verticalAlignment":"top"} -->'
            . '<div class="wp-block-columns alignwide are-vertically-aligned-top">'
            . '<!-- wp:column {"verticalAlignment":"top"} -->'
            . '<div class="wp-block-column is-vertically-aligned-top"><!-- wp:post-featured-image {"aspectRatio":"1","style":{"border":{"radius":"12px"}}} /--></div>'
            . '<!-- /wp:column -->'
            . '<!-- wp:column {"verticalAlignment":"top"} -->'
            . '<div class="wp-block-column is-vertically-aligned-top">'
            . '<!-- wp:post-title {"level":1,"style":{"typography":{"fontSize":"clamp(1.75rem, 1.1rem + 2.4vw, 2.6rem)","lineHeight":"1.12"},"spacing":{"margin":{"top":"0"}}}} /-->'
            . '<!-- wp:rh-shop/buy-box /-->'
            . '<!-- wp:separator {"className":"is-style-wide","style":{"spacing":{"margin":{"top":"1.6rem","bottom":"1.4rem"}}}} -->'
            . '<hr class="wp-block-separator has-alpha-channel-opacity is-style-wide" style="margin-top:1.6rem;margin-bottom:1.4rem"/>'
            . '<!-- /wp:separator -->'
            . '<!-- wp:list {"className":"rhshop-features"} -->'
            . '<ul class="wp-block-list rhshop-features">'
            . '<!-- wp:list-item --><li>Versand in 2 bis 4 Werktagen</li><!-- /wp:list-item -->'
            . '<!-- wp:list-item --><li>14 Tage Widerrufsrecht</li><!-- /wp:list-item -->'
            . '<!-- wp:list-item --><li>Sichere Zahlung über Stripe</li><!-- /wp:list-item -->'
            . '</ul>'
            . '<!-- /wp:list -->'
            . '</div>'
            . '<!-- /wp:column -->'
            . '</div>'
            . '<!-- /wp:columns -->'
            . '<!-- wp:post-content {"style":{"spacing":{"margin":{"top":"clamp(2rem, 4vw, 3rem)"}}}} /-->'
            . '</main>'
            . '<!-- /wp:group -->'
            . '<!-- wp:template-part {"slug":"footer","area":"footer","tagName":"footer"} /-->';
    }
}
