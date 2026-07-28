<?php

/**
 * Frontend-Render des Warenkorb-Zustands-Containers. Rendert seine InnerBlocks in
 * einem Wrapper, der nur im passenden Warenkorb-Zustand sichtbar ist (leer bzw.
 * gefüllt). Server-seitig sitzt das hidden-Attribut sofort richtig (kein Flackern),
 * client-seitig toggelt shop.js beide Container bei jeder Warenkorb-Änderung über
 * das data-Attribut. Der Block fasst dabei ausschließlich seine EIGENEN Wrapper an,
 * nie die Struktur des Betreibers.
 *
 * @var array<string, mixed> $attributes
 * @var string               $content    Gerenderte InnerBlocks.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

use RhShop\Cart\Cart;
use RhShop\Catalog\VariantRepository;
use RhShop\Frontend\ExamplePreview;

$state = ($attributes['state'] ?? 'filled') === 'empty' ? 'empty' : 'filled';

// Im Editor (Beispiel-Vorschau) beide Zustände zeigen, sonst den echten Warenkorb fragen.
$cartEmpty = ExamplePreview::isActive() ? ($state === 'empty') : (new Cart(new VariantRepository()))->isEmpty();

$visible = ($state === 'empty') === $cartEmpty;

$wrapper = get_block_wrapper_attributes(['class' => 'rhshop-cart-state']);

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Wrapper escapt, $content sind gerenderte Blocks.
echo '<div ' . $wrapper . ' data-rhshop-cart-state="' . esc_attr($state) . '"' . ($visible ? '' : ' hidden') . '>' . $content . '</div>';
