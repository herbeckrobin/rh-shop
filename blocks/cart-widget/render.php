<?php

/**
 * Frontend-Render des Warenkorb-Widgets (Trigger + Drawer-Overlay). Die Markup-Logik
 * liegt in CartWidget, damit render.php dünn bleibt. cart-widget.js hängt das Overlay
 * an den <body> (Isolation) und steuert Öffnen/Schließen; shop.js hält den Warenkorb
 * im Drawer aktuell.
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

use RhShop\Frontend\CartWidget;

$wrapper = get_block_wrapper_attributes(['class' => 'rhshop-cw']);

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CartWidget escapt intern.
echo CartWidget::make()->render($attributes, $wrapper);
