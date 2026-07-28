<?php

/**
 * Frontend-Render der Bestellbestätigung (Danke-Seite). Dünner Wrapper um die
 * DankeView, die den Status aus dem payment_intent der Rück-URL liest (bezahlt,
 * in Verarbeitung, nicht gefunden) und die Bestellübersicht samt Rechnungs-Link
 * rendert. Ersetzt den Legacy-Shortcode [rhshop_danke], der für bestehende
 * Installationen erhalten bleibt.
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

use RhShop\Checkout\DankeView;

$wrapper = get_block_wrapper_attributes(['class' => 'rhshop-danke-block']);

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- DankeView escapt intern.
echo '<div ' . $wrapper . '>' . DankeView::make()->render() . '</div>';
