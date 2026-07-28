<?php

declare(strict_types=1);

namespace RhShop\Cart;

defined( 'ABSPATH' ) || exit;

use RhShop\Catalog\VariantRepository;
use RhShop\Stripe\Config;

/**
 * Rendert die zwei Teile des Warenkorbs getrennt: die Positionsliste (itemsHtml) und
 * die Summe mit Zur-Kasse-Button (summaryHtml). So bestimmt das Layout über Core-Blocks
 * frei, wo was steht. shop.js aktualisiert beide Teile seiten-weit über die
 * data-Attribute (Menge/Entfernen/Summe), unabhängig davon, in welchem Block sie liegen.
 */
final class CartView
{
    public function __construct(
        private readonly Cart $cart,
        private readonly Config $config,
    ) {
    }

    public static function make(): self
    {
        return new self(new Cart(new VariantRepository()), new Config());
    }

    public function itemsHtml(): string
    {
        $state = $this->cart->toState($this->config);

        $lines = '';
        foreach ($state['lines'] as $line) {
            $lines .= $this->line($line);
        }

        return '<div class="rhshop-cart-items" data-rhshop-cart-items>'
            . '<div class="rhshop-cart-empty" data-rhshop-cart-empty' . ($state['empty'] ? '' : ' hidden') . '>'
            . $this->emptyStateHtml()
            . '</div>'
            . '<p class="rhshop-cart__notice" data-rhshop-cart-notice role="status" aria-live="polite"></p>'
            . '<ul class="rhshop-cart__lines" data-rhshop-cart-lines' . ($state['empty'] ? ' hidden' : '') . '>'
            . $lines // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- line() escapt intern.
            . '</ul></div>';
    }

    /**
     * Kompakter eingebauter Leer-Zustand (Symbol, Einladung, Zum-Shop-Button): der
     * Default für Drawer und einfache Seiten. Wer den Leer-Zustand frei gestalten
     * will (Empfehlungen, eigene Texte), nutzt auf der Seite den Block
     * rh-shop/cart-state, der den ganzen Bereich zustandsabhängig zeigt.
     */
    private function emptyStateHtml(): string
    {
        $shopUrl = (string) apply_filters('rh-blueprint/shop/shop_url', $this->shopUrl());

        $html = '<div class="rhshop-cart-empty__head">'
            . '<span class="rhshop-cart-empty__icon" aria-hidden="true">' . \RhShop\Frontend\CartWidget::iconSvg('bag') . '</span>'
            . '<p class="rhshop-cart-empty__title">' . esc_html__('Dein Warenkorb ist leer.', 'rh-shop') . '</p>'
            . '<p class="rhshop-cart-empty__text">' . esc_html__('Stöber durch den Shop, deine Auswahl landet hier.', 'rh-shop') . '</p>';

        if ($shopUrl !== '') {
            $html .= '<a class="rhshop-btn-checkout rhshop-cart-empty__cta" href="' . esc_url($shopUrl) . '">' . esc_html__('Zum Shop', 'rh-shop') . '</a>';
        }

        return $html . '</div>';
    }

    /**
     * Ziel des Zum-Shop-Buttons: die Seite mit dem Slug "shop", überschreibbar über
     * den Filter oben. Leer (kein Button), wenn es keine solche Seite gibt.
     */
    private function shopUrl(): string
    {
        return \RhShop\Support\Pages::url('shop');
    }

    public function summaryHtml(): string
    {
        $state = $this->cart->toState($this->config);
        $checkoutUrl = (string) apply_filters('rh-blueprint/shop/checkout_url', home_url('/kasse'));

        return '<div class="rhshop-cart-summary" data-rhshop-cart-summary>'
            . '<div class="rhshop-cart__foot" data-rhshop-cart-foot' . ($state['empty'] ? ' hidden' : '') . '>'
            . '<div class="rhshop-cart__sum"><span>' . esc_html__('Summe', 'rh-shop') . '</span>'
            . '<strong data-rhshop-cart-total>' . esc_html((string) $state['total']) . '</strong></div>'
            . '<a class="rhshop-btn-checkout" href="' . esc_url($checkoutUrl) . '">' . esc_html__('Zur Kasse', 'rh-shop') . '</a>'
            . '</div></div>';
    }

    /**
     * @param array<string, mixed> $line
     */
    private function line(array $line): string
    {
        $media = ((string) $line['thumbnail']) !== ''
            ? sprintf('<img src="%s" alt="" loading="lazy" />', esc_url((string) $line['thumbnail']))
            : '<span class="rhshop-card__ph" aria-hidden="true"></span>';

        return sprintf(
            '<li class="rhshop-cart__line" data-p="%1$d" data-v="%2$s">'
            . '<div class="rhshop-cart__media">%3$s</div>'
            . '<div class="rhshop-cart__info"><span class="rhshop-cart__title">%4$s</span>'
            . '<span class="rhshop-cart__opts">%5$s</span>'
            . '<span class="rhshop-cart__unit">%6$s</span></div>'
            . '<div class="rhshop-qty"><button type="button" data-rhshop-cart-qty="-">−</button>'
            . '<input type="number" value="%7$d" min="1" max="%10$d" data-rhshop-cart-qty-input inputmode="numeric" />'
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
            esc_attr__('Entfernen', 'rh-shop'),
            // max: Bestand der Variante (null = unbegrenzt -> 99, die MAX_QTY-Grenze).
            $line['max'] !== null ? min(99, (int) $line['max']) : 99
        );
    }
}
