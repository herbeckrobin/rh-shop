<?php

declare(strict_types=1);

namespace RhShop\Frontend;

defined( 'ABSPATH' ) || exit;

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
            . '<div class="wp-block-column is-vertically-aligned-top"><!-- wp:rh-shop/product-gallery /--></div>'
            . '<!-- /wp:column -->'
            // Sticky-Spalte: die Kauf-Seite bleibt beim Scrollen neben einer langen
            // Galerie/Beschreibung im Blick (CSS in shop.css, nur Desktop).
            . '<!-- wp:column {"verticalAlignment":"top","className":"rhshop-sticky-col"} -->'
            . '<div class="wp-block-column is-vertically-aligned-top rhshop-sticky-col">'
            . '<!-- wp:post-title {"level":1,"style":{"typography":{"fontSize":"clamp(1.75rem, 1.1rem + 2.4vw, 2.6rem)","lineHeight":"1.12"},"spacing":{"margin":{"top":"0"}}}} /-->'
            . '<!-- wp:rh-shop/buy-box /-->'
            . '<!-- wp:separator {"className":"is-style-wide","style":{"spacing":{"margin":{"top":"1.6rem","bottom":"1.4rem"}}}} -->'
            . '<hr class="wp-block-separator has-alpha-channel-opacity is-style-wide" style="margin-top:1.6rem;margin-bottom:1.4rem"/>'
            . '<!-- /wp:separator -->'
            . '<!-- wp:list {"className":"rhshop-features"} -->'
            . '<ul class="wp-block-list rhshop-features">'
            . '<!-- wp:list-item --><li>' . esc_html__('Versand in 2 bis 4 Werktagen', 'rh-shop') . '</li><!-- /wp:list-item -->'
            . '<!-- wp:list-item --><li>' . esc_html__('14 Tage Widerrufsrecht', 'rh-shop') . '</li><!-- /wp:list-item -->'
            . '<!-- wp:list-item --><li>' . esc_html__('Sichere Zahlung über Stripe', 'rh-shop') . '</li><!-- /wp:list-item -->'
            . '</ul>'
            . '<!-- /wp:list -->'
            . $this->detailsBlock(
                __('Versand und Lieferung', 'rh-shop'),
                __('Wir versenden innerhalb von 2 bis 4 Werktagen. Die Versandkosten siehst du im Warenkorb und in der Kasse, ab Erreichen der Frei-Grenze liefern wir kostenlos.', 'rh-shop')
            )
            . $this->detailsBlock(
                __('Widerrufsrecht', 'rh-shop'),
                __('Du kannst deine Bestellung innerhalb von 14 Tagen ohne Angabe von Gründen widerrufen. Alle Details stehen in der Widerrufsbelehrung.', 'rh-shop')
            )
            . '</div>'
            . '<!-- /wp:column -->'
            . '</div>'
            . '<!-- /wp:columns -->'
            // align:wide + eigenes constrained-Layout: normale Absätze bleiben auf
            // Lesebreite (contentSize), wide-Sektionen im Content gehen auf wideSize.
            // Ohne das klemmt das constrained <main> den ganzen Content auf 720px.
            . '<!-- wp:post-content {"align":"wide","layout":{"type":"constrained"},"style":{"spacing":{"margin":{"top":"clamp(2rem, 4vw, 3rem)"}}}} /-->'
            . '<!-- wp:rh-shop/product-grid {"related":true,"columns":4,"limit":4,"heading":"' . esc_attr__('Ähnliche Produkte', 'rh-shop') . '","align":"wide","style":{"spacing":{"margin":{"top":"clamp(2.5rem, 5vw, 4rem)"}}}} /-->'
            . '</main>'
            . '<!-- /wp:group -->'
            . '<!-- wp:template-part {"slug":"footer","area":"footer","tagName":"footer"} /-->';
    }

    /**
     * Ein Core-Details-Block (Akkordeon) als Template-Markup. Inhalt ist im
     * Site-Editor frei anpassbar, das hier ist nur der Startzustand.
     */
    private function detailsBlock(string $summary, string $text): string
    {
        $summaryJson = wp_json_encode($summary);

        return '<!-- wp:details {"summary":' . $summaryJson . ',"className":"rhshop-details"} -->'
            . '<details class="wp-block-details rhshop-details"><summary>' . esc_html($summary) . '</summary>'
            . '<!-- wp:paragraph --><p>' . esc_html($text) . '</p><!-- /wp:paragraph -->'
            . '</details>'
            . '<!-- /wp:details -->';
    }
}
