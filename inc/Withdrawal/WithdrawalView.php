<?php

declare(strict_types=1);

namespace RhShop\Withdrawal;

defined( 'ABSPATH' ) || exit;

/**
 * Rendert die Widerrufs-Bestätigungsseite (§356a). Zweite Stufe des zweistufigen
 * Ablaufs: der Kunde kam über den "Vertrag widerrufen"-Button hierher, füllt die
 * drei Pflichtangaben aus und löst mit "Widerruf bestätigen" den Widerruf aus.
 *
 * Datenminimierung: nur Name, Bestellnummer, E-Mail sind Pflicht. Der Grund ist
 * ausdrücklich optional. Keine weiteren Pflichtfelder.
 */
final class WithdrawalView
{
    public function render(): string
    {
        $revocationUrl = (string) apply_filters('rh-blueprint/shop/legal_url', home_url('/widerrufsbelehrung'), 'widerrufsbelehrung');

        $intro = '<p>' . esc_html__('Hier kannst du deinen Vertrag widerrufen. Fülle die folgenden Angaben aus und bestätige den Widerruf. Du kannst deinen Widerruf alternativ auch formlos per E-Mail oder Post erklären.', 'rh-shop') . '</p>';
        $intro .= '<p class="rhshop-widerruf__belehrung">' . sprintf(
            /* translators: %s: Link zur Widerrufsbelehrung */
            esc_html__('Details zu deinem Widerrufsrecht findest du in der %s.', 'rh-shop'),
            '<a href="' . esc_url($revocationUrl) . '" target="_blank" rel="noopener">' . esc_html__('Widerrufsbelehrung', 'rh-shop') . '</a>'
        ) . '</p>';

        $form = '<div class="rhshop-widerruf__form" data-rhshop-widerruf-form>'
            . $this->field('name', __('Name', 'rh-shop'), 'text', true, 'name')
            . $this->field('order', __('Bestellnummer', 'rh-shop'), 'text', true)
            . $this->field('email', __('E-Mail-Adresse', 'rh-shop'), 'email', true, 'email')
            . '<div class="rhshop-field"><label for="rhshop-w-reason">' . esc_html__('Grund (optional)', 'rh-shop') . '</label>'
            . '<textarea id="rhshop-w-reason" rows="3" data-rhshop-w-reason></textarea></div>'
            . '<button type="button" class="rhshop-btn-order" data-rhshop-widerruf-submit>' . esc_html__('Widerruf bestätigen', 'rh-shop') . '</button>'
            . '<p class="rhshop-checkout__msg" data-rhshop-w-msg role="alert" aria-live="assertive"></p>'
            . '</div>';

        $success = '<div class="rhshop-widerruf__success" data-rhshop-w-success hidden>'
            . '<h3>' . esc_html__('Widerruf eingegangen', 'rh-shop') . '</h3>'
            . '<p>' . esc_html__('Wir haben deinen Widerruf erhalten und dir eine Eingangsbestätigung per E-Mail geschickt. Diese bestätigt nur den Eingang, wir prüfen deinen Widerruf und melden uns.', 'rh-shop') . '</p>'
            . '</div>';

        return '<div class="rhshop-widerruf" data-rhshop-widerruf>' . $intro . $form . $success . '</div>';
    }

    private function field(string $key, string $label, string $type, bool $required, string $autocomplete = ''): string
    {
        $id = 'rhshop-w-' . $key;

        return '<div class="rhshop-field">'
            . '<label for="' . esc_attr($id) . '">' . esc_html($label) . ($required ? ' *' : '') . '</label>'
            . sprintf(
                '<input type="%s" id="%s" data-rhshop-w-%s%s%s />',
                esc_attr($type),
                esc_attr($id),
                esc_attr($key),
                $required ? ' required' : '',
                $autocomplete !== '' ? ' autocomplete="' . esc_attr($autocomplete) . '"' : ''
            )
            . '</div>';
    }
}
