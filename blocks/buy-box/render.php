<?php

/**
 * Frontend-Render der Kauf-Box: nur die Kauf-Steuerung (Preis, Varianten, Menge,
 * In-den-Warenkorb) des Produkts. Titel, Bild und Beschreibung kommen im
 * Produkt-Template als eigene Core-Blocks, darum rendert dieser Block sie NICHT
 * (kein Doppel). Ohne gesetztes Produkt nimmt er das der aktuellen Detailseite.
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

use RhShop\Catalog\ProductType;
use RhShop\Catalog\VariantRepository;
use RhShop\Frontend\ExamplePreview;
use RhShop\Frontend\Render;
use RhShop\Stripe\Config;

$productId = (int) ($attributes['productId'] ?? 0);

if ($productId <= 0 && is_singular(ProductType::POST_TYPE)) {
    $productId = (int) get_the_ID();
}

// Im Editor ohne konkretes Produkt (die Kauf-Box sitzt im Produkt-Template) ein
// echtes Beispiel-Produkt zeigen, damit man die Kauf-Steuerung sieht.
if ($productId <= 0 && ExamplePreview::isActive()) {
    $productId = ExamplePreview::productId();
}

$product = $productId > 0 ? get_post($productId) : null;
if (! $product || $product->post_type !== ProductType::POST_TYPE || $product->post_status !== 'publish') {
    return;
}

$render = new Render(new VariantRepository(), new Config());
$wrapper = get_block_wrapper_attributes(['class' => 'rhshop-buybox-block']);

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Wrapper + controls escapen intern.
echo '<div ' . $wrapper . '>' . $render->controls($productId) . '</div>';
