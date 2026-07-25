<?php

/**
 * Frontend-Render der Warenkorb-Summe und des Zur-Kasse-Buttons. shop.js hält die Summe
 * seiten-weit aktuell, wenn im Positionen-Block Mengen geändert werden.
 */

declare(strict_types=1);

use RhShop\Cart\CartView;
use RhShop\Frontend\ExamplePreview;
use RhShop\Stripe\Config;

$wrapper = get_block_wrapper_attributes(['class' => 'rhshop-cart']);

$exampleCart = ExamplePreview::isActive() ? ExamplePreview::cart() : null;
$view = $exampleCart !== null ? new CartView($exampleCart, new Config()) : CartView::make();

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CartView escapt intern.
echo '<div ' . $wrapper . '>' . $view->summaryHtml() . '</div>';
