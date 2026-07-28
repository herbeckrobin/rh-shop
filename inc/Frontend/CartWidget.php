<?php

declare(strict_types=1);

namespace RhShop\Frontend;

defined( 'ABSPATH' ) || exit;

use RhShop\Cart\Cart;
use RhShop\Cart\CartView;
use RhShop\Catalog\VariantRepository;
use RhShop\Stripe\Config;

/**
 * Warenkorb-Widget für die Navigation: ein Trigger (Icon und/oder Wort) mit Anzahl-Badge
 * und ein Warenkorb-Overlay (Drawer von rechts).
 *
 * Der Drawer wiederverwendet die bestehende CartView (Positionen + Summe), damit es
 * genau EINE Warenkorb-Darstellung und -Logik gibt. shop.js aktualisiert alle
 * data-rhshop-cart-*-Container seiten-weit, der Drawer bleibt so mit der Warenkorb-Seite
 * synchron. Das Overlay wird im Frontend per JS an den <body> gehängt (Isolation von
 * Theme- und Nav-Styles), ohne JS ist der Trigger ein normaler Link zur Warenkorb-Seite.
 *
 * @param array{display:string,label:string,icon:string,showBadge:bool,hideZero:bool,openOnAdd:bool} $attrs
 */
final class CartWidget
{
    /** @var array<string, string> Erlaubte Anzeige-Modi. */
    private const DISPLAY = ['icon', 'word', 'both'];

    /** @var array<string, string> Erlaubte Icon-Namen. */
    public const ICONS = ['bag', 'cart', 'basket'];

    public function __construct(
        private readonly Cart $cart,
        private readonly Config $config,
    ) {
    }

    public static function make(): self
    {
        return new self(new Cart(new VariantRepository()), new Config());
    }

    /**
     * @param array<string, mixed> $attrs
     */
    public function render(array $attrs, string $wrapper): string
    {
        $display = in_array((string) ($attrs['display'] ?? 'icon'), self::DISPLAY, true) ? (string) $attrs['display'] : 'icon';
        $icon = in_array((string) ($attrs['icon'] ?? 'bag'), self::ICONS, true) ? (string) $attrs['icon'] : 'bag';
        $label = trim((string) ($attrs['label'] ?? '')) !== '' ? (string) $attrs['label'] : __('Warenkorb', 'rh-shop');
        $showBadge = (bool) ($attrs['showBadge'] ?? true);
        $hideZero = (bool) ($attrs['hideZero'] ?? true);
        $openOnAdd = (bool) ($attrs['openOnAdd'] ?? true);

        $count = $this->cart->count();
        $cartUrl = (string) apply_filters('rh-blueprint/shop/cart_url', home_url('/warenkorb'));

        $trigger = $this->trigger($display, $icon, $label, $showBadge, $hideZero, $count, $cartUrl);
        $drawer = $this->drawer();

        return sprintf(
            '<div %1$s data-rhshop-cart-widget data-rhshop-cw-open-on-add="%2$s">%3$s%4$s</div>',
            $wrapper,
            $openOnAdd ? '1' : '0',
            $trigger, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- intern escapt.
            $drawer   // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- intern escapt.
        );
    }

    private function trigger(string $display, string $icon, string $label, bool $showBadge, bool $hideZero, int $count, string $cartUrl): string
    {
        $iconHtml = $display !== 'word'
            ? '<span class="rhshop-cw__icon" aria-hidden="true">' . self::iconSvg($icon) . '</span>'
            : '';

        $labelHtml = $display !== 'icon'
            ? '<span class="rhshop-cw__label">' . esc_html($label) . '</span>'
            : '';

        $badgeHtml = '';
        if ($showBadge) {
            $hidden = ($hideZero && $count === 0) ? ' hidden' : '';
            $badgeHtml = '<span class="rhshop-cw__badge" data-rhshop-cart-count' . $hidden . '>' . esc_html((string) $count) . '</span>';
        }

        // Ein echter Link zur Warenkorb-Seite: ohne JS funktioniert er als Navigation,
        // mit JS fängt cart-widget.js den Klick ab und öffnet den Drawer.
        return sprintf(
            '<a class="rhshop-cw__trigger" href="%1$s" data-rhshop-cw-open aria-haspopup="dialog" aria-expanded="false" aria-label="%2$s">%3$s%4$s%5$s</a>',
            esc_url($cartUrl),
            esc_attr__('Warenkorb öffnen', 'rh-shop'),
            $iconHtml,  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            $labelHtml, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            $badgeHtml  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        );
    }

    private function drawer(): string
    {
        $view = new CartView($this->cart, $this->config);

        return '<div class="rhshop-cw__drawer" data-rhshop-cw-drawer hidden>'
            . '<div class="rhshop-cw__backdrop" data-rhshop-cw-close aria-hidden="true"></div>'
            . '<aside class="rhshop-cw__panel" data-rhshop-cw-panel role="dialog" aria-modal="true" aria-label="' . esc_attr__('Warenkorb', 'rh-shop') . '" tabindex="-1">'
            . '<header class="rhshop-cw__head">'
            . '<span class="rhshop-cw__panel-title">' . esc_html__('Warenkorb', 'rh-shop') . '</span>'
            . '<button type="button" class="rhshop-cw__close" data-rhshop-cw-close data-rhshop-cw-close-btn aria-label="' . esc_attr__('Schließen', 'rh-shop') . '">&times;</button>'
            . '</header>'
            . '<div class="rhshop-cw__body">' . $view->itemsHtml() . '</div>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CartView escapt intern.
            . '<div class="rhshop-cw__summary">' . $view->summaryHtml() . '</div>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            . '</aside>'
            . '</div>';
    }

    /**
     * Inline-SVG eines Icons (currentColor, erbt Nav-Textfarbe). Feste, vertrauenswürdige
     * Konstanten, kein User-Input.
     */
    public static function iconSvg(string $name): string
    {
        $icons = [
            'bag' => '<path d="M6 8h12l-1 12H7L6 8Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
            'cart' => '<circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M3 4h2l2.4 12.2A1.5 1.5 0 0 0 8.9 17.4H18a1.5 1.5 0 0 0 1.5-1.2L21 8H6"/>',
            'basket' => '<path d="M5 9h14l-1.3 10.2A1.5 1.5 0 0 1 16.2 20.5H7.8a1.5 1.5 0 0 1-1.5-1.3L5 9Z"/><path d="M9 9 12 4l3 5"/>',
        ];
        $path = $icons[$name] ?? $icons['bag'];

        return '<svg viewBox="0 0 24 24" width="1.35em" height="1.35em" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
    }
}
