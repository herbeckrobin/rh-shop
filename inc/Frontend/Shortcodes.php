<?php

declare(strict_types=1);

namespace RhShop\Frontend;

defined( 'ABSPATH' ) || exit;

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
     * Legacy: neue Seiten nutzen den Block rh-shop/danke, der Shortcode bleibt für
     * bestehende Installationen erhalten.
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
     * Formatierte Versandkosten, satz-tauglich (der Shortcode steht mitten im
     * Fließtext). Sind Versandmethoden konfiguriert, werden die aktiven gelistet
     * ("Abholung kostenlos, DHL 4,90 €"), sonst greift die Legacy-Pauschale.
     */
    public function shippingCost(): string
    {
        $methods = \RhShop\Shipping\ShippingMethods::make()->active();

        if ($methods !== []) {
            $symbol = $this->config->currencySymbol();
            $parts = [];
            foreach ($methods as $method) {
                $price = $method->priceCents <= 0
                    ? __('kostenlos', 'rh-shop')
                    : Money::format($method->priceCents, $symbol);
                if ($method->freeFromCents !== null && $method->priceCents > 0) {
                    $price .= sprintf(
                        /* translators: %s: Bestellwert, ab dem der Versand kostenlos ist */
                        __(' (kostenlos ab %s)', 'rh-shop'),
                        Money::format($method->freeFromCents, $symbol)
                    );
                }
                $parts[] = $method->label . ' ' . $price;
            }

            return esc_html(implode(', ', $parts));
        }

        $cents = $this->config->shippingCents();

        if ($cents <= 0) {
            return esc_html__('kostenlos', 'rh-shop');
        }

        return esc_html(Money::format($cents, $this->config->currencySymbol()));
    }
}
