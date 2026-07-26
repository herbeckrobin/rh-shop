<?php

declare(strict_types=1);

namespace RhShop\Legal;

defined( 'ABSPATH' ) || exit;

/**
 * Muster-Widerrufsformular im amtlich vorgeschriebenen Wortlaut (Anlage 2 zu
 * Art. 246a § 1 Abs. 2 EGBGB). Anders als AGB/Widerrufsbelehrung darf und soll
 * dieser Text ausgeliefert werden, er ist gesetzlich vorgegeben, es sind nur die
 * Anbieterdaten einzufügen (kommen aus {@see Anbieter}).
 *
 * Wird auf der Widerrufsbelehrungs-Seite per Shortcode eingebunden und der
 * Bestätigungsmail beigelegt (Art. 246a: Widerrufsformular auf dauerhaftem
 * Datenträger).
 */
final class Widerrufsformular
{
    /**
     * Das Formular als HTML-Block. Der Wortlaut ist fix (Gesetz), nur der
     * Anbieter-Block wird eingesetzt.
     */
    public static function html(): string
    {
        $anbieter = nl2br(esc_html(Anbieter::block()));

        $intro = esc_html__('Wenn Sie den Vertrag widerrufen wollen, dann füllen Sie bitte dieses Formular aus und senden Sie es zurück.', 'rh-shop');

        $items = [
            sprintf(/* translators: %s: Anbieter-Kontaktdaten */ esc_html__('An %s:', 'rh-shop'), '<br>' . $anbieter),
            esc_html__('Hiermit widerrufe(n) ich/wir (*) den von mir/uns (*) abgeschlossenen Vertrag über den Kauf der folgenden Waren (*)/die Erbringung der folgenden Dienstleistung (*)', 'rh-shop'),
            esc_html__('Bestellt am (*)/erhalten am (*)', 'rh-shop'),
            esc_html__('Name des/der Verbraucher(s)', 'rh-shop'),
            esc_html__('Anschrift des/der Verbraucher(s)', 'rh-shop'),
            esc_html__('Unterschrift des/der Verbraucher(s) (nur bei Mitteilung auf Papier)', 'rh-shop'),
            esc_html__('Datum', 'rh-shop'),
        ];

        $list = '';
        foreach ($items as $item) {
            // Der Anbieter-Block ist bereits escaped (nl2br + esc_html), der Rest ebenso.
            $list .= '<li>' . $item . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        return '<div class="rhshop-widerrufsformular">'
            . '<h3>' . esc_html__('Muster-Widerrufsformular', 'rh-shop') . '</h3>'
            . '<p><em>' . $intro . '</em></p>'
            . '<ul class="rhshop-widerrufsformular__list">' . $list . '</ul>'
            . '<p class="rhshop-widerrufsformular__note">' . esc_html__('(*) Unzutreffendes streichen.', 'rh-shop') . '</p>'
            . '</div>';
    }
}
