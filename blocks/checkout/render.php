<?php

/**
 * Frontend-Render der Kasse. Dynamisch: liest den aktuellen Warenkorb aus dem
 * Cookie und rendert die §312j-Bestellseite. Das Mounten der Stripe-Zahl-UI und die
 * Absende-Logik übernimmt checkout.js.
 */

declare(strict_types=1);

use RhShop\Checkout\CheckoutView;

$wrapper = get_block_wrapper_attributes(['class' => 'rhshop-checkout-block']);

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CheckoutView escapt intern.
echo '<div ' . $wrapper . '>' . CheckoutView::make()->render() . '</div>';
