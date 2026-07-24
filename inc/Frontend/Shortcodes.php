<?php

declare(strict_types=1);

namespace RhShop\Frontend;

use RhShop\Stripe\Config;
use RhShop\Support\Money;

/**
 * Kleine Frontend-Shortcodes, damit Rechtstexte (Versand-Seite) die Shop-Werte aus
 * EINER Quelle ziehen statt sie doppelt zu pflegen. `[rhshop_versandkosten]` gibt die
 * konfigurierten Pauschal-Versandkosten aus, so bleibt die Versand-Seite automatisch
 * synchron zur Einstellung im Shop-Tab.
 */
final class Shortcodes
{
    public function __construct(private readonly Config $config)
    {
    }

    public function boot(): void
    {
        add_shortcode('rhshop_versandkosten', [$this, 'shippingCost']);
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
