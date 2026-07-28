<?php

/**
 * Frontend-Render der Produkt-Suche: Lupe-Trigger (erbt Farbe/Schrift der Nav) plus
 * Overlay-Markup. search.js hängt das Overlay an den <body> und steuert Öffnen,
 * Live-Suche (Endpoint rhshop/v1/search) und Schließen. Ohne JS bleibt nur die Lupe
 * ohne Funktion sichtbar; der Block ist ein reines Komfort-Feature, keine Pflicht-UI.
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$placeholder = sanitize_text_field((string) ($attributes['placeholder'] ?? __('Produkte suchen …', 'rh-shop')));

$wrapper = get_block_wrapper_attributes(['class' => 'rhshop-search']);

$icon = '<svg viewBox="0 0 24 24" width="1.35em" height="1.35em" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true">'
    . '<circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.8-3.8"></path></svg>';

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Teil-Markup ist escapt bzw. statisches SVG.
echo '<div ' . $wrapper . ' data-rhshop-search>'
    . '<button type="button" class="rhshop-search__trigger" data-rhshop-search-open aria-expanded="false" aria-label="' . esc_attr__('Suche öffnen', 'rh-shop') . '">'
    . $icon
    . '</button>'
    . '<div class="rhshop-search__overlay" data-rhshop-search-overlay role="dialog" aria-modal="true" aria-label="' . esc_attr__('Produkt-Suche', 'rh-shop') . '" hidden>'
    . '<div class="rhshop-search__backdrop" data-rhshop-search-close></div>'
    . '<div class="rhshop-search__panel">'
    . '<div class="rhshop-search__bar">'
    . '<label class="rhshop-search__field">'
    . '<span class="screen-reader-text">' . esc_html__('Suchbegriff', 'rh-shop') . '</span>'
    . '<input type="search" data-rhshop-search-input placeholder="' . esc_attr($placeholder) . '" autocomplete="off">'
    . '</label>'
    . '<button type="button" class="rhshop-search__close" data-rhshop-search-close aria-label="' . esc_attr__('Suche schließen', 'rh-shop') . '">×</button>'
    . '</div>'
    . '<p class="rhshop-search__status" data-rhshop-search-status aria-live="polite"></p>'
    . '<ul class="rhshop-search__results" data-rhshop-search-results></ul>'
    . '</div>'
    . '</div>'
    . '</div>';
