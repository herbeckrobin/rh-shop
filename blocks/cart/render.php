<?php

/**
 * Frontend-Render des Warenkorbs. Serverseitig aus dem Cookie gerendert (funktioniert
 * ohne JS für die Anzeige); shop.js aktualisiert Mengen/Entfernen über die REST-API.
 * Die Zeilen-Struktur ist identisch zum JS-Renderer in shop.js.
 */

declare(strict_types=1);

use RhShop\Cart\Cart;
use RhShop\Catalog\VariantRepository;
use RhShop\Stripe\Config;

$cart = new Cart(new VariantRepository());
$state = $cart->toState(new Config());
$checkoutUrl = (string) apply_filters('rh-blueprint/shop/checkout_url', home_url('/kasse'));

$wrapper = get_block_wrapper_attributes(['class' => 'rhshop-cart', 'data-rhshop-cart' => '1']);

$renderLine = static function (array $line): string {
    $media = $line['thumbnail'] !== ''
        ? sprintf('<img src="%s" alt="" loading="lazy" />', esc_url($line['thumbnail']))
        : '<span class="rhshop-card__ph" aria-hidden="true"></span>';

    return sprintf(
        '<li class="rhshop-cart__line" data-p="%1$d" data-v="%2$s">'
        . '<div class="rhshop-cart__media">%3$s</div>'
        . '<div class="rhshop-cart__info"><span class="rhshop-cart__title">%4$s</span>'
        . '<span class="rhshop-cart__opts">%5$s</span>'
        . '<span class="rhshop-cart__unit">%6$s</span></div>'
        . '<div class="rhshop-qty"><button type="button" data-rhshop-cart-qty="-">−</button>'
        . '<input type="number" value="%7$d" min="1" max="99" data-rhshop-cart-qty-input inputmode="numeric" />'
        . '<button type="button" data-rhshop-cart-qty="+">+</button></div>'
        . '<span class="rhshop-cart__lt" data-rhshop-line-total>%8$s</span>'
        . '<button type="button" class="rhshop-cart__remove" data-rhshop-cart-remove aria-label="%9$s">×</button>'
        . '</li>',
        (int) $line['product_id'],
        esc_attr((string) $line['variant_id']),
        $media,
        esc_html((string) $line['title']),
        esc_html((string) $line['options']),
        esc_html((string) $line['unit_price']),
        (int) $line['qty'],
        esc_html((string) $line['line_total']),
        esc_attr__('Entfernen', 'rh-shop')
    );
};

echo '<div ' . $wrapper . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

echo '<p class="rhshop-cart__empty" data-rhshop-cart-empty' . ($state['empty'] ? '' : ' hidden') . '>'
    . esc_html__('Dein Warenkorb ist leer.', 'rh-shop') . '</p>';

echo '<ul class="rhshop-cart__lines" data-rhshop-cart-lines' . ($state['empty'] ? ' hidden' : '') . '>';
foreach ($state['lines'] as $line) {
    echo $renderLine($line); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Closure escapt intern.
}
echo '</ul>';

echo '<div class="rhshop-cart__foot" data-rhshop-cart-foot' . ($state['empty'] ? ' hidden' : '') . '>';
echo '<div class="rhshop-cart__sum"><span>' . esc_html__('Summe', 'rh-shop') . '</span>'
    . '<strong data-rhshop-cart-total>' . esc_html((string) $state['total']) . '</strong></div>';
echo '<a class="rhshop-btn-checkout" href="' . esc_url($checkoutUrl) . '">' . esc_html__('Zur Kasse', 'rh-shop') . '</a>';
echo '</div>';

echo '</div>';
