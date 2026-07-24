<?php

/**
 * Frontend-Render des Produkt-Rasters. Dynamischer Block: die Preise/Bestände
 * kommen live aus dem Katalog, nicht aus gespeichertem Markup.
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

use RhShop\Catalog\ProductType;
use RhShop\Frontend\Render;
use RhShop\Stripe\Config;
use RhShop\Catalog\VariantRepository;

$columns = max(1, min(6, (int) ($attributes['columns'] ?? 3)));
$limit = max(1, min(48, (int) ($attributes['limit'] ?? 12)));
$category = sanitize_title((string) ($attributes['category'] ?? ''));

$queryArgs = [
    'post_type' => ProductType::POST_TYPE,
    'post_status' => 'publish',
    'posts_per_page' => $limit,
    'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'],
    'no_found_rows' => true,
];

if ($category !== '') {
    $queryArgs['tax_query'] = [[
        'taxonomy' => ProductType::TAXONOMY,
        'field' => 'slug',
        'terms' => $category,
    ]];
}

$query = new WP_Query($queryArgs);

if (! $query->have_posts()) {
    return;
}

$render = new Render(new VariantRepository(), new Config());

$wrapper = get_block_wrapper_attributes([
    'class' => 'rhshop-grid',
    'style' => '--rhshop-cols:' . $columns . ';',
]);

echo '<div ' . $wrapper . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes ist bereits escapt.

foreach ($query->posts as $product) {
    echo $render->card((int) $product->ID); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Render::card escapt intern.
}

echo '</div>';

wp_reset_postdata();
