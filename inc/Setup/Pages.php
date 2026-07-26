<?php

declare(strict_types=1);

namespace RhShop\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * Legt die vom Shop benötigten Rechtstext-Seiten beim Aktivieren an, damit ein
 * frischer Install sofort einen funktionierenden "Versandkosten"-Link hat (PAngV).
 *
 * Idempotent: eine schon vorhandene Seite mit dem Slug wird NICHT überschrieben
 * (der Betreiber pflegt sie selbst). Die Seite ist ein Startpunkt mit Beispieltext,
 * die konkreten Versandkosten kommen per Shortcode aus der Shop-Einstellung, bleiben
 * also automatisch synchron. Wer Versandinfos schon in den AGB hat, kann den Link
 * per Filter `rh-blueprint/shop/legal_url` woanders hin zeigen und diese Seite löschen.
 */
final class Pages
{
    private const VERSAND_SLUG = 'versand';
    private const OPTION_VERSAND_ID = 'rhshop_versand_page_id';
    private const DANKE_SLUG = 'danke';
    private const OPTION_DANKE_ID = 'rhshop_danke_page_id';

    public static function install(): void
    {
        self::ensureVersandPage();
        self::ensureDankePage();
    }

    private static function ensureVersandPage(): void
    {
        $existing = get_page_by_path(self::VERSAND_SLUG);
        if ($existing instanceof \WP_Post) {
            update_option(self::OPTION_VERSAND_ID, $existing->ID);
            return;
        }

        $pageId = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => __('Versand und Lieferung', 'rh-shop'),
            'post_name' => self::VERSAND_SLUG,
            'post_content' => self::versandContent(),
        ]);

        if (is_int($pageId) && $pageId > 0) {
            update_option(self::OPTION_VERSAND_ID, $pageId);
        }
    }

    /**
     * Bestätigungsseite (Ziel der Stripe-Rück-URL). Der Shortcode zeigt den echten
     * Zahlungsstatus statt eines blinden Mail-Versprechens.
     */
    private static function ensureDankePage(): void
    {
        $existing = get_page_by_path(self::DANKE_SLUG);
        if ($existing instanceof \WP_Post) {
            update_option(self::OPTION_DANKE_ID, $existing->ID);
            return;
        }

        $pageId = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => __('Vielen Dank', 'rh-shop'),
            'post_name' => self::DANKE_SLUG,
            'post_content' => "<!-- wp:shortcode -->[rhshop_danke]<!-- /wp:shortcode -->",
        ]);

        if (is_int($pageId) && $pageId > 0) {
            update_option(self::OPTION_DANKE_ID, $pageId);
        }
    }

    /**
     * Beispiel-Inhalt als Block-Markup. Der Betreiber passt Lieferzeit und Gebiet an,
     * die Versandkosten zieht der Shortcode aus der Shop-Einstellung.
     */
    private static function versandContent(): string
    {
        $intro = esc_html__('Hier findest du unsere Versandkosten und Lieferzeiten. Pass die Angaben an dein Angebot an.', 'rh-shop');
        $costLine = esc_html__('Versandkosten pro Bestellung: [rhshop_versandkosten].', 'rh-shop');
        $timeLine = esc_html__('Lieferzeit: in der Regel 2 bis 4 Werktage nach Zahlungseingang.', 'rh-shop');
        $areaLine = esc_html__('Wir versenden innerhalb Deutschlands.', 'rh-shop');

        return "<!-- wp:paragraph -->\n<p>{$intro}</p>\n<!-- /wp:paragraph -->\n\n"
            . "<!-- wp:paragraph -->\n<p>{$costLine}</p>\n<!-- /wp:paragraph -->\n\n"
            . "<!-- wp:paragraph -->\n<p>{$timeLine}</p>\n<!-- /wp:paragraph -->\n\n"
            . "<!-- wp:paragraph -->\n<p>{$areaLine}</p>\n<!-- /wp:paragraph -->";
    }
}
