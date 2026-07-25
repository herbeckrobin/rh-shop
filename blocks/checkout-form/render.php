<?php

/**
 * Frontend-Render des Kassen-Formulars: Kontakt, Pflicht-Checkboxen, Gesamtpreis-Zeile
 * (§312j, direkt über dem Button) und die Stripe-Zahlung. Der `data-rhshop-checkout`-
 * Wrapper sitzt hier, checkout.js hängt sich daran.
 */

declare(strict_types=1);

use RhShop\Checkout\CheckoutView;
use RhShop\Frontend\ExamplePreview;
use RhShop\Stripe\Config;

$wrapper = get_block_wrapper_attributes(['class' => 'rhshop-checkout-form-block']);

$exampleCart = ExamplePreview::isActive() ? ExamplePreview::cart() : null;
$view = $exampleCart !== null ? new CheckoutView($exampleCart, new Config()) : CheckoutView::make();

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CheckoutView escapt intern.
echo '<div ' . $wrapper . '>' . $view->formHtml() . '</div>';
