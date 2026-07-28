<?php

/**
 * Frontend-Render der Produkt-Galerie: Hauptbild + Thumbnail-Leiste. Klick auf ein
 * Thumbnail tauscht das Hauptbild, Klick aufs Hauptbild öffnet den Zoom (shop.js).
 * Ohne JS sind die Thumbnails Links auf die Originaldatei (Progressive Enhancement).
 * Ohne Galerie-Bilder fällt der Block aufs Beitragsbild zurück, ohne beides auf den
 * Platzhalter, damit das Layout der Detailseite stabil bleibt.
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

use RhShop\Admin\GalleryMetaBox;
use RhShop\Catalog\ProductType;
use RhShop\Frontend\ExamplePreview;

$productId = (int) ($attributes['productId'] ?? 0);

if ($productId <= 0 && is_singular(ProductType::POST_TYPE)) {
    $productId = (int) get_the_ID();
}

if ($productId <= 0 && ExamplePreview::isActive()) {
    $productId = ExamplePreview::productId();
}

$product = $productId > 0 ? get_post($productId) : null;
if (! $product || $product->post_type !== ProductType::POST_TYPE || $product->post_status !== 'publish') {
    return;
}

// Bild-Liste: Beitragsbild zuerst, dann die Galerie (ohne Dopplung).
$ids = [];
$featured = (int) get_post_thumbnail_id($productId);
if ($featured > 0) {
    $ids[] = $featured;
}
foreach (GalleryMetaBox::imageIds($productId) as $id) {
    if ($id !== $featured) {
        $ids[] = $id;
    }
}

$wrapper = get_block_wrapper_attributes(['class' => 'rhshop-gallery']);

if ($ids === []) {
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Wrapper ist escapt.
    echo '<div ' . $wrapper . '><figure class="rhshop-gallery__main"><span class="rhshop-card__ph" aria-hidden="true"></span></figure></div>';

    return;
}

$title = get_the_title($productId);

$main = wp_get_attachment_image($ids[0], 'large', false, [
    'class' => 'rhshop-gallery__img',
    'data-rhshop-gallery-main' => '',
]);

$thumbs = '';
if (count($ids) > 1) {
    $thumbs .= '<div class="rhshop-gallery__thumbs" role="group" aria-label="' . esc_attr__('Weitere Produktbilder', 'rh-shop') . '">';
    foreach ($ids as $index => $id) {
        $large = wp_get_attachment_image_url($id, 'large');
        $full = wp_get_attachment_image_url($id, 'full');
        if (! is_string($large)) {
            continue;
        }
        $thumbs .= sprintf(
            '<a class="rhshop-gallery__thumb%1$s" href="%2$s" data-rhshop-gallery-thumb="%3$s"%4$s>%5$s</a>',
            $index === 0 ? ' is-active' : '',
            esc_url(is_string($full) ? $full : $large),
            esc_attr($large),
            $index === 0 ? ' aria-current="true"' : '',
            wp_get_attachment_image($id, 'thumbnail', false, [
                /* translators: %d: Position des Bildes in der Galerie */
                'alt' => sprintf(__('Produktbild %d', 'rh-shop'), $index + 1),
            ])
        );
    }
    $thumbs .= '</div>';
}

$zoomLabel = esc_attr__('Bild vergrößern', 'rh-shop');

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Teil-Markup ist escapt.
echo '<div ' . $wrapper . ' data-rhshop-gallery data-rhshop-gallery-title="' . esc_attr($title) . '">'
    . '<figure class="rhshop-gallery__main"><button type="button" class="rhshop-gallery__zoom" data-rhshop-gallery-zoom aria-label="' . $zoomLabel . '">'
    . $main
    . '</button></figure>'
    . $thumbs
    . '</div>';
