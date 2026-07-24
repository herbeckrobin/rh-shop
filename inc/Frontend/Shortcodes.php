<?php

declare(strict_types=1);

namespace RhShop\Frontend;

use RhShop\Checkout\DankeView;
use RhShop\Legal\Widerrufsformular;
use RhShop\Stripe\Config;
use RhShop\Support\Money;

/**
 * Kleine Frontend-Shortcodes, damit Rechtstexte die Shop-Werte aus EINER Quelle
 * ziehen statt sie doppelt zu pflegen. `[rhshop_versandkosten]` gibt die
 * konfigurierten Pauschal-Versandkosten aus. `[rhshop_widerrufsformular]` rendert das
 * amtliche Muster-Widerrufsformular mit den Anbieterdaten, zum Einbinden auf der
 * Widerrufsbelehrungs-Seite.
 */
final class Shortcodes
{
    public function __construct(private readonly Config $config)
    {
    }

    public function boot(): void
    {
        add_shortcode('rhshop_versandkosten', [$this, 'shippingCost']);
        add_shortcode('rhshop_widerrufsformular', [$this, 'widerrufsformular']);
        add_shortcode('rhshop_danke', [$this, 'danke']);
    }

    /**
     * Status-bewusste Bestätigungsseite nach der Zahlung. Rückgabe ist escaptes Markup.
     */
    public function danke(): string
    {
        return DankeView::make()->render();
    }

    /**
     * Amtliches Muster-Widerrufsformular. Rückgabe ist bereits escaptes Markup.
     */
    public function widerrufsformular(): string
    {
        return Widerrufsformular::html();
    }

    /**
     * Formatierte Versandkosten. Bei 0 der Hinweis "kostenlos".
     */
    public function shippingCost(): string
    {
        $cents = $this->config->shippingCents();

        if ($cents <= 0) {
            return esc_html__('kostenlos', 'rh-shop');
        }

        return esc_html(Money::format($cents, $this->config->currencySymbol()));
    }
}
