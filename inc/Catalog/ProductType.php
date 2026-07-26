<?php

declare(strict_types=1);

namespace RhShop\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Registriert den Produkt-CPT `rh_product` und die Kategorie-Taxonomie.
 *
 * Bewusst ein CPT (kein Options-/Tabellen-Konstrukt): der Kunde pflegt Produkte
 * im gewohnten WordPress-Editor, Beschreibung über Gutenberg, Bilder über die
 * native Mediathek (Beitragsbild + Galerie im Block). Das ist die editor-souveräne
 * Pflege, ohne die der White-Label-Handover nicht funktioniert. Der CPT registriert
 * sich auf `init` (über den Core-booted-Hook), früh genug für den Block-Editor.
 */
final class ProductType
{
    public const POST_TYPE = 'rh_product';
    public const TAXONOMY = 'rh_product_cat';

    public function boot(): void
    {
        $this->registerPostType();
        $this->registerTaxonomy();
    }

    private function registerPostType(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Produkte', 'rh-shop'),
                'singular_name' => __('Produkt', 'rh-shop'),
                'add_new' => __('Neues Produkt', 'rh-shop'),
                'add_new_item' => __('Neues Produkt anlegen', 'rh-shop'),
                'edit_item' => __('Produkt bearbeiten', 'rh-shop'),
                'new_item' => __('Neues Produkt', 'rh-shop'),
                'view_item' => __('Produkt ansehen', 'rh-shop'),
                'search_items' => __('Produkte suchen', 'rh-shop'),
                'not_found' => __('Keine Produkte gefunden', 'rh-shop'),
                'not_found_in_trash' => __('Keine Produkte im Papierkorb', 'rh-shop'),
                'all_items' => __('Alle Produkte', 'rh-shop'),
                'menu_name' => __('Shop', 'rh-shop'),
            ],
            'public' => true,
            'has_archive' => true,
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-cart',
            'menu_position' => 26,
            'supports' => ['title', 'editor', 'thumbnail', 'excerpt', 'page-attributes'],
            'rewrite' => ['slug' => 'produkt', 'with_front' => false],
            'taxonomies' => [self::TAXONOMY],
        ]);
    }

    private function registerTaxonomy(): void
    {
        register_taxonomy(self::TAXONOMY, [self::POST_TYPE], [
            'labels' => [
                'name' => __('Produktkategorien', 'rh-shop'),
                'singular_name' => __('Produktkategorie', 'rh-shop'),
                'add_new_item' => __('Neue Kategorie', 'rh-shop'),
                'edit_item' => __('Kategorie bearbeiten', 'rh-shop'),
                'search_items' => __('Kategorien suchen', 'rh-shop'),
                'all_items' => __('Alle Kategorien', 'rh-shop'),
                'menu_name' => __('Kategorien', 'rh-shop'),
            ],
            'public' => true,
            'hierarchical' => true,
            'show_in_rest' => true,
            'show_admin_column' => true,
            'rewrite' => ['slug' => 'produkt-kategorie', 'with_front' => false],
        ]);
    }
}
