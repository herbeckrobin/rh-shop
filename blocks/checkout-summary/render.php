<?php

/**
 * Frontend-Render der Bestellübersicht (Positionen + Preisaufschlüsselung). Reine
 * Anzeige aus dem aktuellen Warenkorb, kein JS. Getrennt vom Formular-Block, damit
 * das Layout (z.B. zweispaltig) frei über Core-Blocks bestimmbar ist.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

use RhShop\Checkout\CheckoutView;
use RhShop\Frontend\ExamplePreview;
use RhShop\Stripe\Config;

$wrapper = get_block_wrapper_attributes(['class' => 'rhshop-checkout-summary-block']);

$exampleCart = ExamplePreview::isActive() ? ExamplePreview::cart() : null;
$view = $exampleCart !== null ? new CheckoutView($exampleCart, new Config()) : CheckoutView::make();

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CheckoutView escapt intern.
echo '<div ' . $wrapper . '>' . $view->summaryHtml() . '</div>';
