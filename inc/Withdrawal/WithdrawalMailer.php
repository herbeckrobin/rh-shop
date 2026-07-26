<?php

declare(strict_types=1);

namespace RhShop\Withdrawal;

/**
 * Verschickt die Eingangsbestätigung nach §356a Abs. 4 BGB auf dauerhaftem
 * Datenträger (E-Mail), plus eine interne Benachrichtigung an den Betreiber.
 *
 * KRITISCH (IT-Recht-Kanzlei): die Kundenmail bestätigt ausschliesslich den EINGANG
 * des Widerrufs, NICHT dessen Wirksamkeit oder Annahme. Formulierungen wie
 * "akzeptiert"/"wirksam bestätigt" sind bewusst vermieden. Pflichtinhalt: der Inhalt
 * der Widerrufserklärung (die drei Angaben) plus Datum und Uhrzeit des Eingangs.
 */
final class WithdrawalMailer
{
    /**
     * @param bool $verified Nur bei einer gegen die Bestellung (Nummer + E-Mail)
     *                       verifizierten Erklärung geht die Eingangsbestätigung an den
     *                       Kunden. Sonst würde der Endpoint zum Mail-Relay: ein Angreifer
     *                       könnte die Bestätigung an beliebige fremde Adressen schicken.
     *                       Die Admin-Benachrichtigung geht immer raus, der Betreiber kann
     *                       einen unverifizierten Widerruf manuell prüfen und bestätigen.
     */
    public function send(Withdrawal $withdrawal, bool $verified): void
    {
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $received = $this->formatDateTime($withdrawal->receivedAt);

        if ($verified && $withdrawal->email !== '') {
            wp_mail(
                $withdrawal->email,
                __('Eingangsbestätigung deines Widerrufs', 'rh-shop'),
                $this->customerBody($withdrawal, $received),
                $headers
            );
        }

        $admin = (string) get_option('admin_email');
        if ($admin !== '') {
            wp_mail(
                $admin,
                sprintf(/* translators: %s: Bestellnummer */ __('Neuer Widerruf eingegangen (%s)', 'rh-shop'), $withdrawal->orderNumber),
                $this->adminBody($withdrawal, $received),
                $headers
            );
        }
    }

    private function customerBody(Withdrawal $w, string $received): string
    {
        return '<p>' . esc_html__('Hallo,', 'rh-shop') . '</p>'
            . '<p>' . esc_html__('wir bestätigen den Eingang deines Widerrufs mit folgendem Inhalt:', 'rh-shop') . '</p>'
            . $this->declarationTable($w, $received)
            . '<p><strong>' . esc_html__('Hinweis:', 'rh-shop') . '</strong> '
            . esc_html__('Diese E-Mail bestätigt ausschliesslich den Eingang deines Widerrufs, nicht seine Wirksamkeit. Wir prüfen den Widerruf und seinen Umfang und melden uns bei dir.', 'rh-shop')
            . '</p>';
    }

    private function adminBody(Withdrawal $w, string $received): string
    {
        return '<p>' . esc_html__('Es ist ein Widerruf eingegangen:', 'rh-shop') . '</p>'
            . $this->declarationTable($w, $received)
            . ($w->ip !== '' ? '<p style="font-size:12px;color:#666">IP: ' . esc_html($w->ip) . '</p>' : '');
    }

    private function declarationTable(Withdrawal $w, string $received): string
    {
        $rows = [
            __('Name', 'rh-shop') => $w->customerName,
            __('Bestellnummer', 'rh-shop') => $w->orderNumber,
            __('E-Mail', 'rh-shop') => $w->email,
            __('Eingegangen am', 'rh-shop') => $received,
        ];
        if ($w->reason !== '') {
            $rows[__('Angegebener Grund', 'rh-shop')] = $w->reason;
        }

        $html = '<table cellpadding="6" style="border-collapse:collapse">';
        foreach ($rows as $label => $value) {
            $html .= sprintf(
                '<tr><td style="text-align:left;color:#555">%s</td><td><strong>%s</strong></td></tr>',
                esc_html((string) $label),
                esc_html((string) $value)
            );
        }

        return $html . '</table>';
    }

    /**
     * Eingangszeitpunkt als deutsches Datum + Uhrzeit (Pflicht nach Abs. 4).
     */
    private function formatDateTime(string $mysql): string
    {
        return mysql2date(
            get_option('date_format') . ' ' . get_option('time_format') . ' \U\h\r',
            $mysql
        );
    }
}
