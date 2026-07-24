<?php

/**
 * Frontend-Render des Einzelprodukt-Blocks. Nutzt entweder das gewählte Produkt
 * (productId) oder, auf einer Produkt-Detailseite ohne Auswahl, das aktuelle.
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

use RhShop\Catalog\ProductType;
use RhShop\Frontend\Render;
use RhShop\Stripe\Config;
use RhShop\Catalog\VariantRepository;

$productId = (int) ($attributes['productId'] ?? 0);

if ($productId <= 0 && is_singular(ProductType::POST_TYPE)) {
    $productId = get_the_ID();
}

$product = $productId > 0 ? get_post($productId) : null;
if (! $product || $product->post_type !== ProductType::POST_TYPE || $product->post_status !== 'publish') {
    return;
}

$render = new Render(new VariantRepository(), new Config());
$wrapper = get_block_wrapper_attributes(['class' => 'rhshop-single-block']);

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Wrapper + buyBox escapen intern.
echo '<div ' . $wrapper . '>' . $render->buyBox($productId) . '</div>';
