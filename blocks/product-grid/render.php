<?php

/**
 * Frontend-Render des Produkt-Rasters. Dynamischer Block: die Preise/Bestände
 * kommen live aus dem Katalog, nicht aus gespeichertem Markup.
 *
 * Modi/Optionen:
 * - related: zeigt Produkte derselben Kategorie wie das aktuelle Produkt (ohne es
 *   selbst), für die "Ähnliche Produkte"-Sektion der Detailseite. Ohne Treffer
 *   rendert der Block nichts (auch keine Überschrift).
 * - orderby: menu_order (Standard) | date (Neueste zuerst) | price (aufsteigend).
 *   Preis bewusst NICHT als meta_key-Sort: Varianten-Produkte haben kein simples
 *   Preis-Meta und fielen aus der Query. Stattdessen PHP-Sort über den günstigsten
 *   Varianten-Preis (Sortimente sind klein, max. 100 Posts).
 * - showFilter: Kategorie-Pills über dem Raster. Rein client-seitig (shop.js), die
 *   Controls sind bis zur JS-Initialisierung hidden, ohne JS zeigt das Raster
 *   einfach alles (Progressive Enhancement). Eine Text-Suche gibt es hier bewusst
 *   nicht, die sitzt als eigener Block (rh-shop/search) in der Navigation.
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

use RhShop\Catalog\ProductType;
use RhShop\Frontend\ExamplePreview;
use RhShop\Frontend\Render;
use RhShop\Stripe\Config;
use RhShop\Catalog\VariantRepository;

$columns = max(1, min(6, (int) ($attributes['columns'] ?? 3)));
$limit = max(1, min(48, (int) ($attributes['limit'] ?? 12)));
$category = sanitize_title((string) ($attributes['category'] ?? ''));
$orderby = (string) ($attributes['orderby'] ?? 'menu_order');
$orderby = in_array($orderby, ['menu_order', 'date', 'price'], true) ? $orderby : 'menu_order';
$heading = sanitize_text_field((string) ($attributes['heading'] ?? ''));
$related = (bool) ($attributes['related'] ?? false);
$showFilter = (bool) ($attributes['showFilter'] ?? false);

$variants = new VariantRepository();

$queryArgs = [
    'post_type' => ProductType::POST_TYPE,
    'post_status' => 'publish',
    'posts_per_page' => $limit,
    'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'],
    'no_found_rows' => true,
];

if ($orderby === 'date') {
    $queryArgs['orderby'] = 'date';
    $queryArgs['order'] = 'DESC';
}

// Preis-Sortierung: breiter queren, unten per PHP sortieren und auf limit kappen.
if ($orderby === 'price') {
    $queryArgs['posts_per_page'] = 100;
}

if ($related) {
    // Kontext-Produkt: die aktuelle Detailseite, im Editor das Beispiel-Produkt.
    $contextId = is_singular(ProductType::POST_TYPE) ? (int) get_the_ID() : 0;
    if ($contextId <= 0 && ExamplePreview::isActive()) {
        $contextId = ExamplePreview::productId();
    }
    if ($contextId <= 0) {
        return;
    }

    $queryArgs['post__not_in'] = [$contextId];

    // Erste Kategorie des Produkts; ohne Kategorie einfach "alle anderen Produkte".
    $terms = get_the_terms($contextId, ProductType::TAXONOMY);
    if (is_array($terms) && $terms !== []) {
        $queryArgs['tax_query'] = [[
            'taxonomy' => ProductType::TAXONOMY,
            'field' => 'term_id',
            'terms' => (int) $terms[0]->term_id,
        ]];
    }
} elseif ($category !== '') {
    $queryArgs['tax_query'] = [[
        'taxonomy' => ProductType::TAXONOMY,
        'field' => 'slug',
        'terms' => $category,
    ]];
}

$query = new WP_Query($queryArgs);
$products = $query->posts;

// related: gibt die Kategorie weniger her als das Limit (oder nichts), mit weiteren
// Produkten auffüllen. Eine halb leere "Ähnliche Produkte"-Reihe wirkt kaputter als
// eine gemischte; die Kategorie-Treffer stehen weiterhin vorn.
if ($related && count($products) < $limit) {
    $exclude = array_merge($queryArgs['post__not_in'], array_map(static fn ($p): int => (int) $p->ID, $products));
    $fill = get_posts([
        'post_type' => ProductType::POST_TYPE,
        'post_status' => 'publish',
        'numberposts' => $limit - count($products),
        'post__not_in' => $exclude,
        'orderby' => 'menu_order title',
        'order' => 'ASC',
    ]);
    $products = array_merge($products, $fill);
}

if ($products === []) {
    return;
}

if ($orderby === 'price') {
    usort($products, static fn ($a, $b): int => $variants->fromPriceCents((int) $a->ID) <=> $variants->fromPriceCents((int) $b->ID));
    $products = array_slice($products, 0, $limit);
}

$render = new Render($variants, new Config());

$wrapper = get_block_wrapper_attributes([
    'class' => 'rhshop-grid-block',
]);

// Kategorie-Pills aus den tatsächlich gezeigten Produkten (nur belegte Terms).
$controls = '';
if (! $related && $showFilter) {
    $usedTerms = [];
    foreach ($products as $product) {
        $terms = get_the_terms((int) $product->ID, ProductType::TAXONOMY);
        if (! is_array($terms)) {
            continue;
        }
        foreach ($terms as $term) {
            $usedTerms[$term->slug] = $term->name;
        }
    }
    if (count($usedTerms) > 1) {
        asort($usedTerms);
        $pills = '<div class="rhshop-grid-pills" role="group" aria-label="' . esc_attr__('Nach Kategorie filtern', 'rh-shop') . '">'
            . '<button type="button" class="rhshop-grid-pill" data-rhshop-pill="" aria-pressed="true">' . esc_html__('Alle', 'rh-shop') . '</button>';
        foreach ($usedTerms as $slug => $name) {
            $pills .= sprintf(
                '<button type="button" class="rhshop-grid-pill" data-rhshop-pill="%s" aria-pressed="false">%s</button>',
                esc_attr($slug),
                esc_html($name)
            );
        }
        $pills .= '</div>';

        $controls = '<div class="rhshop-grid-controls" data-rhshop-grid-controls hidden>' . $pills . '</div>'
            . '<p class="rhshop-grid-empty" data-rhshop-grid-empty aria-live="polite" hidden>' . esc_html__('Keine Produkte gefunden.', 'rh-shop') . '</p>';
    }
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Wrapper escapt, Teil-Markup escapt intern.
echo '<div ' . $wrapper . '>';

if ($heading !== '') {
    echo '<h2 class="rhshop-grid-heading">' . esc_html($heading) . '</h2>';
}

echo $controls; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- oben escapt.

echo '<div class="rhshop-grid" style="--rhshop-cols:' . (int) $columns . ';" data-rhshop-grid>';

foreach ($products as $product) {
    echo $render->card((int) $product->ID); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Render::card escapt intern.
}

echo '</div></div>';

wp_reset_postdata();
