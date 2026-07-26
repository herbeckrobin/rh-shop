<?php

/**
 * Frontend-Render der Warenkorb-Positionen. shop.js aktualisiert Mengen/Entfernen und
 * die Summe (im Summe-Block) seiten-weit über die data-Attribute.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

use RhShop\Cart\CartView;
use RhShop\Frontend\ExamplePreview;
use RhShop\Stripe\Config;
use RhShop\Cart\Cart;
use RhShop\Catalog\VariantRepository;

$wrapper = get_block_wrapper_attributes(['class' => 'rhshop-cart']);

$exampleCart = ExamplePreview::isActive() ? ExamplePreview::cart() : null;
$view = $exampleCart !== null ? new CartView($exampleCart, new Config()) : CartView::make();

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CartView escapt intern.
echo '<div ' . $wrapper . '>' . $view->itemsHtml() . '</div>';
