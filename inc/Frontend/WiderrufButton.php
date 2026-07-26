<?php

declare(strict_types=1);

namespace RhShop\Frontend;

defined( 'ABSPATH' ) || exit;

use RhShop\Stripe\Config;

/**
 * Der "Vertrag widerrufen"-Button nach §356a Abs. 1: ständig verfügbar, gut lesbar,
 * hervorgehoben, auf JEDER Seite, ohne Login. Wird per wp_footer eingehängt, damit
 * er theme-unabhängig überall erscheint (kein manuelles Platzieren im Template
 * nötig). Bewusst distinkt gestylt, um sich von AGB-/Impressum-Links abzuheben
 * (Noerr: Footer-Platzierung braucht Kontrast).
 *
 * Wer den Button lieber selbst im Header/Footer-Template platziert, kann ihn in den
 * Shop-Einstellungen abschalten und stattdessen den Widerruf-Block bzw. einen Link
 * auf /widerruf setzen.
 */
final class WiderrufButton
{
    public function __construct(private readonly Config $config)
    {
    }

    public function boot(): void
    {
        if (! $this->config->widerrufButtonEnabled()) {
            return;
        }

        add_action('wp_footer', [$this, 'render']);
    }

    public function render(): void
    {
        // Nicht im Editor/Adminbereich, nicht auf der Widerrufsseite selbst.
        if (is_admin()) {
            return;
        }

        $url = (string) apply_filters('rh-blueprint/shop/widerruf_url', home_url('/widerruf'));

        printf(
            '<div class="rhshop-widerruf-link"><a href="%s">%s</a></div>',
            esc_url($url),
            esc_html__('Vertrag widerrufen', 'rh-shop')
        );

        // Minimales, sitewide Styling (der Button erscheint auf jeder Seite, darum
        // inline und nicht über die nur auf Shop-Seiten geladene shop.css).
        echo '<style>
.rhshop-widerruf-link{text-align:center;padding:1rem;}
.rhshop-widerruf-link a{display:inline-block;padding:.45rem 1rem;border:1px solid currentColor;border-radius:8px;font-size:.85rem;font-weight:600;text-decoration:none;color:inherit;opacity:.85;}
.rhshop-widerruf-link a:hover{opacity:1;}
</style>';
    }
}
