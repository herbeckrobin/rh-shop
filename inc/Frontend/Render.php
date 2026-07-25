<?php

declare(strict_types=1);

namespace RhShop\Frontend;

use RhShop\Catalog\GrundpreisUnit;
use RhShop\Catalog\VariantRepository;
use RhShop\Orders\Order;
use RhShop\Stripe\Config;
use RhShop\Support\Money;

/**
 * Geteilte Render-Helfer für die Frontend-Blocks und die Produkt-Detailseite.
 *
 * `controls()` (Preis + Varianten-Auswahl + In-den-Warenkorb) ist die EINE Quelle,
 * die sowohl der Einzelprodukt-Block als auch der the_content-Filter der Detailseite
 * konsumieren, damit die Kauf-UI nicht zweimal existiert und auseinanderläuft.
 */
final class Render
{
    public function __construct(
        private readonly VariantRepository $variants,
        private readonly Config $config,
    ) {
    }

    /**
     * Preis-Label eines Produkts. "ab X €" bei mehreren Preisen, sonst "X €".
     */
    public function priceLabel(int $productId): string
    {
        $units = $this->variants->forProduct($productId);
        $prices = array_values(array_unique(array_map(static fn ($u): int => $u->priceCents, $units)));
        $label = Money::format($this->variants->fromPriceCents($productId), $this->config->currencySymbol());

        return count($prices) > 1 ? sprintf(/* translators: %s: Preis */ __('ab %s', 'rh-shop'), $label) : $label;
    }

    /**
     * PAngV-Preishinweis (§3/§6): bei Regelbesteuerung "inkl. MwSt.", bei
     * Kleinunternehmer der §19-Hinweis (NICHT "inkl. MwSt.", das wäre falsch und
     * abmahnbar), plus der Versandkosten-Hinweis. Auf der Detailseite ist
     * "Versandkosten" auf die Versandkosten-Seite verlinkt; auf der Karte (die selbst
     * ein Link ist) als reiner Text, um kein Link-im-Link zu erzeugen.
     */
    public function priceNote(bool $withLink = false): string
    {
        $versand = __('Versandkosten', 'rh-shop');
        $url = $withLink ? $this->shippingInfoUrl() : '';
        if ($url !== '') {
            $versand = '<a href="' . esc_url($url) . '">' . esc_html($versand) . '</a>';
        } else {
            $versand = esc_html($versand);
        }

        if ($this->config->taxMode() === Order::TAX_KLEINUNTERNEHMER) {
            // translators: %s: verlinktes oder unverlinktes Wort "Versandkosten".
            $text = sprintf(esc_html__('zzgl. %s, gemäß § 19 UStG keine USt.', 'rh-shop'), '%s');
        } else {
            // translators: %s: verlinktes oder unverlinktes Wort "Versandkosten".
            $text = sprintf(esc_html__('inkl. MwSt., zzgl. %s', 'rh-shop'), '%s');
        }

        return '<span class="rhshop-price-note">' . str_replace('%s', $versand, $text) . '</span>';
    }

    /**
     * Ziel für den "Versandkosten"-Link. Leer, wenn es keine sinnvolle Seite gibt:
     * dann bleibt der Hinweis reiner Text statt eines toten Links. Vorrang hat eine
     * Seite mit dem Slug "versand"; der Filter `rh-blueprint/shop/legal_url` kann das
     * Ziel überschreiben (z.B. auf einen Anker in den AGB).
     */
    private function shippingInfoUrl(): string
    {
        $default = '';
        $page = get_page_by_path('versand');
        if ($page instanceof \WP_Post) {
            $default = (string) get_permalink($page);
        }

        return (string) apply_filters('rh-blueprint/shop/legal_url', $default, 'versand');
    }

    /**
     * PAngV-Grundpreis (§4/§5) als reiner Text, z.B. "(25,80 €/kg)". Die Nennmenge
     * liegt pro Variante, die Einheit produktweit. Basiseinheit seit 2022 einheitlich
     * 1 kg / 1 l / 1 m / 1 m² (die alte 250g-Ausnahme entfällt). Leer, wenn keine
     * Nennmenge, kein Preis oder keine Grundpreis-Einheit da ist (Stückware).
     */
    public function grundpreisText(?float $amount, string $unit, int $priceCents): string
    {
        $perBaseCents = GrundpreisUnit::basePriceCents($amount, $priceCents, $unit);
        if ($perBaseCents === null) {
            return '';
        }

        return '(' . Money::format($perBaseCents, $this->config->currencySymbol()) . '/' . GrundpreisUnit::baseLabel($unit) . ')';
    }

    /**
     * Grundpreis der günstigsten Variante als fertiges Span (fürs Raster). Leer,
     * wenn das Produkt keinen Grundpreis führt.
     */
    private function grundpreisSpan(int $productId): string
    {
        $cheapest = $this->variants->cheapestVariant($productId);
        if ($cheapest === null) {
            return '';
        }

        $text = $this->grundpreisText($cheapest->gpAmount, $this->variants->unit($productId), $cheapest->priceCents);

        return $text === '' ? '' : '<span class="rhshop-grundpreis">' . esc_html($text) . '</span>';
    }

    /**
     * Eine Produktkarte fürs Raster.
     */
    public function card(int $productId): string
    {
        $soldOut = $this->variants->isSoldOut($productId);
        $title = get_the_title($productId);
        $thumb = get_the_post_thumbnail_url($productId, 'medium_large');

        $media = is_string($thumb) && $thumb !== ''
            ? sprintf('<img src="%s" alt="%s" loading="lazy" />', esc_url($thumb), esc_attr($title))
            : '<span class="rhshop-card__ph" aria-hidden="true"></span>';

        $badge = $soldOut
            ? '<span class="rhshop-badge rhshop-badge--out">' . esc_html__('Ausverkauft', 'rh-shop') . '</span>'
            : '';

        // Grundpreis der günstigsten Variante (passt zur "ab X €"-Anzeige),
        // Preishinweis ohne Link (die Karte ist selbst ein Link, kein Link-im-Link).
        $grundpreis = $this->grundpreisSpan($productId);

        return sprintf(
            '<a class="rhshop-card%1$s" href="%2$s">'
            . '<span class="rhshop-card__media">%3$s%4$s</span>'
            . '<span class="rhshop-card__title">%5$s</span>'
            . '<span class="rhshop-card__price">%6$s%7$s</span>'
            . '%8$s'
            . '</a>',
            $soldOut ? ' is-sold-out' : '',
            esc_url((string) get_permalink($productId)),
            $media,
            $badge,
            esc_html($title),
            esc_html($this->priceLabel($productId)),
            $grundpreis !== '' ? ' ' . $grundpreis : '',
            $this->priceNote(false)
        );
    }

    /**
     * Die Kauf-Steuerung: Preis, Varianten-Auswahl (Größe/Farbe soweit vorhanden),
     * Menge, In-den-Warenkorb. Die Varianten liegen als JSON im data-Attribut, das
     * Frontend-JS (shop.js) matcht die Auswahl, aktualisiert Preis + Verfügbarkeit
     * und legt die passende Variante in den Warenkorb.
     */
    public function controls(int $productId): string
    {
        $units = $this->variants->forProduct($productId);
        $hasRealVariants = $this->variants->hasRealVariants($productId);
        $soldOut = $this->variants->isSoldOut($productId);
        $symbol = $this->config->currencySymbol();
        $unit = $this->variants->unit($productId);

        $data = array_map(fn ($u): array => [
            'id' => $u->id,
            'o1' => $u->option1,
            'o2' => $u->option2,
            'price' => Money::format($u->priceCents, $symbol),
            'available' => $u->isAvailable(),
            'gp' => $this->grundpreisText($u->gpAmount, $unit, $u->priceCents),
        ], $units);

        $sizes = $this->distinctOptions($units, 1);
        $colors = $this->distinctOptions($units, 2);

        $selects = '';
        if ($hasRealVariants) {
            [$axis1, $axis2] = $this->variants->axisLabels($productId);
            if ($sizes !== []) {
                /* translators: %s: Name der Varianten-Achse, z.B. Größe */
                $selects .= $this->select('1', sprintf(__('%s wählen', 'rh-shop'), $axis1), $sizes);
            }
            if ($colors !== []) {
                /* translators: %s: Name der Varianten-Achse, z.B. Farbe */
                $selects .= $this->select('2', sprintf(__('%s wählen', 'rh-shop'), $axis2), $colors);
            }
        }

        // Startzustand: bei Produkten ohne echte Varianten sofort kaufbar (die eine
        // Einheit ist vorausgewählt), bei Varianten erst nach vollständiger Auswahl.
        $addDisabled = ($soldOut || $hasRealVariants) ? ' disabled' : '';

        // Startwert des Grundpreis-Spans: die günstigste Variante (passt zur
        // "ab X €"-Anzeige). shop.js aktualisiert es bei Varianten-Auswahl aus dem
        // gp-Feld der Varianten-Daten. Das Span ist immer da (auch leer), damit JS
        // es befüllen kann; per CSS zeigt es den Abstand nur, wenn es Inhalt hat.
        $cheapest = $this->variants->cheapestVariant($productId);
        $gpInitial = $cheapest !== null
            ? $this->grundpreisText($cheapest->gpAmount, $unit, $cheapest->priceCents)
            : '';

        return sprintf(
            '<div class="rhshop-buy" data-rhshop-buy data-rhshop-product="%1$d" data-rhshop-variants="%2$s" data-rhshop-has-variants="%3$s">'
            . '<div class="rhshop-buy__price"><span data-rhshop-price>%4$s</span><span class="rhshop-grundpreis" data-rhshop-grundpreis>%10$s</span></div>'
            . '%11$s'
            . '%5$s'
            . '<div class="rhshop-buy__row">'
            . '<div class="rhshop-qty"><button type="button" data-rhshop-qty="-" aria-label="%6$s">−</button>'
            . '<input type="number" value="1" min="1" max="99" data-rhshop-qty-input inputmode="numeric" />'
            . '<button type="button" data-rhshop-qty="+" aria-label="%7$s">+</button></div>'
            . '<button type="button" class="rhshop-btn-add" data-rhshop-add%8$s>%9$s</button>'
            . '</div>'
            . '<p class="rhshop-buy__msg" data-rhshop-msg role="status" aria-live="polite"></p>'
            . '</div>',
            $productId,
            esc_attr((string) wp_json_encode($data)),
            $hasRealVariants ? '1' : '0',
            esc_html($this->priceLabel($productId)),
            $selects,
            esc_attr__('Menge verringern', 'rh-shop'),
            esc_attr__('Menge erhöhen', 'rh-shop'),
            $addDisabled,
            $soldOut ? esc_html__('Ausverkauft', 'rh-shop') : esc_html__('In den Warenkorb', 'rh-shop'),
            esc_html($gpInitial),
            $this->priceNote(true)
        );
    }

    /**
     * Vollständige Buy-Box (Bild + Titel + Kurztext + Kauf-Steuerung) für den
     * Einzelprodukt-Block, wenn er frei platziert wird.
     */
    public function buyBox(int $productId): string
    {
        $title = get_the_title($productId);
        $thumb = get_the_post_thumbnail_url($productId, 'large');
        $excerpt = get_the_excerpt($productId);

        $media = is_string($thumb) && $thumb !== ''
            ? sprintf('<img src="%s" alt="%s" />', esc_url($thumb), esc_attr($title))
            : '<span class="rhshop-card__ph" aria-hidden="true"></span>';

        return '<div class="rhshop-single">'
            . '<div class="rhshop-single__media">' . $media . '</div>'
            . '<div class="rhshop-single__info">'
            . '<h2 class="rhshop-single__title">' . esc_html($title) . '</h2>'
            . ($excerpt !== '' ? '<p class="rhshop-single__excerpt">' . esc_html($excerpt) . '</p>' : '')
            . $this->controls($productId)
            . '</div></div>';
    }

    /**
     * @param array<int, \RhShop\Catalog\Variant> $units
     * @return array<int, string>
     */
    private function distinctOptions(array $units, int $axis): array
    {
        $values = array_map(
            static fn ($u): string => $axis === 1 ? $u->option1 : $u->option2,
            $units
        );

        return array_values(array_unique(array_filter($values, static fn (string $v): bool => $v !== '')));
    }

    /**
     * @param array<int, string> $options
     */
    private function select(string $axis, string $placeholder, array $options): string
    {
        $html = sprintf('<select class="rhshop-buy__select" data-rhshop-opt="%s"><option value="">%s</option>', esc_attr($axis), esc_html($placeholder));
        foreach ($options as $option) {
            $html .= sprintf('<option value="%1$s">%1$s</option>', esc_html($option));
        }

        return $html . '</select>';
    }
}
