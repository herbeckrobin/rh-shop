<?php

/**
 * Frontend-Render der Kasse. Dynamisch: liest den aktuellen Warenkorb aus dem
 * Cookie und rendert die §312j-Bestellseite. Das Mounten der Stripe-Zahl-UI und die
 * Absende-Logik übernimmt checkout.js.
 */

declare(strict_types=1);

use RhShop\Checkout\CheckoutView;
use RhShop\Frontend\ExamplePreview;
use RhShop\Stripe\Config;

$wrapper = get_block_wrapper_attributes(['class' => 'rhshop-checkout-block']);

// Im Editor (ServerSideRender mit ?rhshop_preview) eine Beispiel-Kasse mit echten
// Produkten zeigen, sonst die echte Kasse aus dem Warenkorb des Besuchers.
$exampleCart = ExamplePreview::isActive() ? ExamplePreview::cart() : null;
$view = $exampleCart !== null ? new CheckoutView($exampleCart, new Config()) : CheckoutView::make();

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CheckoutView escapt intern.
echo '<div ' . $wrapper . '>' . $view->render() . '</div>';
