<?php

declare(strict_types=1);

namespace RhShop\Frontend;

use RhShop\Catalog\VariantRepository;
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

        return sprintf(
            '<a class="rhshop-card%1$s" href="%2$s">'
            . '<span class="rhshop-card__media">%3$s%4$s</span>'
            . '<span class="rhshop-card__title">%5$s</span>'
            . '<span class="rhshop-card__price">%6$s</span>'
            . '</a>',
            $soldOut ? ' is-sold-out' : '',
            esc_url((string) get_permalink($productId)),
            $media,
            $badge,
            esc_html($title),
            esc_html($this->priceLabel($productId))
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

        $data = array_map(static fn ($u): array => [
            'id' => $u->id,
            'o1' => $u->option1,
            'o2' => $u->option2,
            'price' => Money::format($u->priceCents, $symbol),
            'available' => $u->isAvailable(),
        ], $units);

        $sizes = $this->distinctOptions($units, 1);
        $colors = $this->distinctOptions($units, 2);

        $selects = '';
        if ($hasRealVariants) {
            if ($sizes !== []) {
                $selects .= $this->select('1', __('Größe wählen', 'rh-shop'), $sizes);
            }
            if ($colors !== []) {
                $selects .= $this->select('2', __('Farbe wählen', 'rh-shop'), $colors);
            }
        }

        // Startzustand: bei Produkten ohne echte Varianten sofort kaufbar (die eine
        // Einheit ist vorausgewählt), bei Varianten erst nach vollständiger Auswahl.
        $addDisabled = ($soldOut || $hasRealVariants) ? ' disabled' : '';

        return sprintf(
            '<div class="rhshop-buy" data-rhshop-buy data-rhshop-product="%1$d" data-rhshop-variants="%2$s" data-rhshop-has-variants="%3$s">'
            . '<div class="rhshop-buy__price" data-rhshop-price>%4$s</div>'
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
            $soldOut ? esc_html__('Ausverkauft', 'rh-shop') : esc_html__('In den Warenkorb', 'rh-shop')
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
