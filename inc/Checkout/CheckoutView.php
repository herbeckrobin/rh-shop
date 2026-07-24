<?php

declare(strict_types=1);

namespace RhShop\Checkout;

use RhShop\Cart\Cart;
use RhShop\Cart\CartLine;
use RhShop\Catalog\VariantRepository;
use RhShop\Stripe\Config;
use RhShop\Support\Money;

/**
 * Rendert die §312j-konforme Bestellseite.
 *
 * Reihenfolge und Platzierung sind rechtlich vorgegeben (LG Hildesheim, OLG
 * Nürnberg, BGH I ZR 159/24): unmittelbar ÜBER dem Bestell-Button stehen auf DIESER
 * Seite (nicht auf der Stripe-Seite) die wesentlichen Merkmale je Artikel, der
 * Gesamtpreis inkl. Steuern und die Versandkosten, dazu die Pflicht-Checkboxen. Der
 * Button heißt exakt "Zahlungspflichtig bestellen" und löst die verbindliche
 * Bestellung aus; danach mountet das JS die embedded Stripe-Zahl-UI.
 */
final class CheckoutView
{
    public function __construct(
        private readonly Cart $cart,
        private readonly Config $config,
    ) {
    }

    public function render(): string
    {
        if ($this->cart->isEmpty()) {
            return '<div class="rhshop-checkout rhshop-checkout--empty"><p>'
                . esc_html__('Dein Warenkorb ist leer.', 'rh-shop')
                . '</p><p><a class="rhshop-btn-checkout" href="' . esc_url($this->shopUrl()) . '">'
                . esc_html__('Zum Shop', 'rh-shop') . '</a></p></div>';
        }

        $totals = Totals::forCart($this->cart, $this->config);
        $symbol = $this->config->currencySymbol();

        $html = '<div class="rhshop-checkout" data-rhshop-checkout>';
        $html .= $this->summary($this->cart->lines(), $symbol);
        $html .= $this->breakdown($totals, $symbol);
        $html .= $this->form();
        $html .= '<div class="rhshop-checkout__payment" data-rhshop-stripe-mount hidden></div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Bestellübersicht: wesentliche Merkmale je Artikel (§312j Abs. 2).
     *
     * @param array<int, CartLine> $lines
     */
    private function summary(array $lines, string $symbol): string
    {
        $html = '<h2 class="rhshop-checkout__title">' . esc_html__('Deine Bestellung', 'rh-shop') . '</h2>';
        $html .= '<ul class="rhshop-checkout__items">';

        foreach ($lines as $line) {
            $name = $line->productTitle . ($line->optionsLabel !== '' ? ' (' . $line->optionsLabel . ')' : '');
            $html .= sprintf(
                '<li class="rhshop-checkout__item"><span class="rhshop-checkout__item-name">%s</span>'
                . '<span class="rhshop-checkout__item-qty">%s %d</span>'
                . '<span class="rhshop-checkout__item-total">%s</span></li>',
                esc_html($name),
                esc_html__('Menge', 'rh-shop'),
                $line->qty,
                esc_html(Money::format($line->lineTotalCents(), $symbol))
            );
        }

        return $html . '</ul>';
    }

    /**
     * Preisaufschlüsselung: Zwischensumme, Versand, Steuer/Kleinunternehmer-Hinweis,
     * Gesamtpreis inkl. Steuern (§312j Abs. 2, PAngV).
     */
    private function breakdown(Totals $totals, string $symbol): string
    {
        $rows = '<div class="rhshop-checkout__row"><span>' . esc_html__('Zwischensumme', 'rh-shop') . '</span><span>'
            . esc_html(Money::format($totals->subtotalCents, $symbol)) . '</span></div>';

        $shippingLabel = $totals->shippingCents > 0
            ? Money::format($totals->shippingCents, $symbol)
            : __('kostenlos', 'rh-shop');
        $rows .= '<div class="rhshop-checkout__row"><span>' . esc_html__('Versand', 'rh-shop') . '</span><span>'
            . esc_html($shippingLabel) . '</span></div>';

        if ($totals->isKleinunternehmer()) {
            $rows .= '<div class="rhshop-checkout__row rhshop-checkout__total"><span>' . esc_html__('Gesamt', 'rh-shop') . '</span><span>'
                . esc_html(Money::format($totals->totalCents, $symbol)) . '</span></div>';
            $rows .= '<p class="rhshop-checkout__taxnote">'
                . esc_html__('Kleinunternehmer gemäß § 19 UStG. Im Preis ist keine Umsatzsteuer enthalten.', 'rh-shop')
                . '</p>';
        } else {
            $rows .= sprintf(
                '<div class="rhshop-checkout__row rhshop-checkout__row--muted"><span>%s</span><span>%s</span></div>',
                esc_html(sprintf(/* translators: %d: Steuersatz */ __('enthaltene USt (%d %%)', 'rh-shop'), Config::VAT_RATE_PERCENT)),
                esc_html(Money::format($totals->taxCents, $symbol))
            );
            $rows .= '<div class="rhshop-checkout__row rhshop-checkout__total"><span>' . esc_html__('Gesamt (inkl. MwSt.)', 'rh-shop') . '</span><span>'
                . esc_html(Money::format($totals->totalCents, $symbol)) . '</span></div>';
        }

        return '<div class="rhshop-checkout__breakdown">' . $rows . '</div>';
    }

    /**
     * Kontaktfeld + Pflicht-Checkboxen + der §312j-Button.
     */
    private function form(): string
    {
        $checkboxes = $this->checkbox('terms', __('AGB', 'rh-shop'), 'agb', __('Ich habe die %s gelesen und akzeptiere sie.', 'rh-shop'))
            . $this->checkbox('revocation', __('Widerrufsbelehrung', 'rh-shop'), 'widerrufsbelehrung', __('Ich habe die %s zur Kenntnis genommen.', 'rh-shop'))
            . $this->checkbox('privacy', __('Datenschutzerklärung', 'rh-shop'), 'datenschutz', __('Ich habe die %s gelesen.', 'rh-shop'));

        return '<div class="rhshop-checkout__form" data-rhshop-checkout-form>'
            . '<div class="rhshop-field"><label for="rhshop-email">' . esc_html__('E-Mail', 'rh-shop') . '</label>'
            . '<input type="email" id="rhshop-email" data-rhshop-email required autocomplete="email" /></div>'
            . '<div class="rhshop-field"><label for="rhshop-name">' . esc_html__('Name (optional)', 'rh-shop') . '</label>'
            . '<input type="text" id="rhshop-name" data-rhshop-name autocomplete="name" /></div>'
            . '<div class="rhshop-checkout__consents">' . $checkboxes . '</div>'
            . '<button type="button" class="rhshop-btn-order" data-rhshop-order>' . esc_html__('Zahlungspflichtig bestellen', 'rh-shop') . '</button>'
            . '<p class="rhshop-checkout__msg" data-rhshop-checkout-msg role="alert" aria-live="assertive"></p>'
            . '</div>';
    }

    private function checkbox(string $key, string $linkText, string $legalKey, string $template): string
    {
        $link = sprintf('<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url($this->legalUrl($legalKey)), esc_html($linkText));
        // %s im Template ist der (bereits escapte) Link, der Rest ist statischer, escapter Text.
        $label = str_replace('%s', $link, esc_html($template));

        return sprintf(
            '<label class="rhshop-consent"><input type="checkbox" data-rhshop-accept="%s" /> <span>%s</span></label>',
            esc_attr($key),
            $label
        );
    }

    private function legalUrl(string $key): string
    {
        $default = $key === 'datenschutz' && get_privacy_policy_url() !== ''
            ? get_privacy_policy_url()
            : home_url('/' . $key);

        return (string) apply_filters('rh-blueprint/shop/legal_url', $default, $key);
    }

    private function shopUrl(): string
    {
        return (string) apply_filters('rh-blueprint/shop/shop_url', home_url('/shop'));
    }

    public static function make(): self
    {
        return new self(new Cart(new VariantRepository()), new Config());
    }
}
