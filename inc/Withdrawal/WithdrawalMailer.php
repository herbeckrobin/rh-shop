<?php

declare(strict_types=1);

namespace RhShop\Withdrawal;

defined( 'ABSPATH' ) || exit;

use RhShop\Mail\MailDispatcher;
use RhShop\Mail\MailRegistry;
use RhShop\Stripe\Config;

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
    private readonly MailDispatcher $dispatcher;

    public function __construct(private readonly Config $config)
    {
        $this->dispatcher = new MailDispatcher($config);
    }

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
        $received = $this->formatDateTime($withdrawal->receivedAt);
        $values = [
            'bestellnummer' => $withdrawal->orderNumber,
            'name' => $withdrawal->customerName,
            'shop_name' => (string) get_bloginfo('name'),
        ];

        // Kundenmail nur bei verifiziertem Widerruf (leerer Empfänger = kein Versand).
        // Die Eingangsbestätigung ist Pflicht (nicht abschaltbar), darum ohne An/Aus-Risiko.
        $this->dispatcher->send(
            MailRegistry::get(MailRegistry::WITHDRAWAL_CUSTOMER),
            $verified ? $withdrawal->email : '',
            $values,
            $this->customerBody($withdrawal, $received)
        );
        $this->dispatcher->send(
            MailRegistry::get(MailRegistry::WITHDRAWAL_OPERATOR),
            $this->config->notifyAddress(),
            $values,
            $this->adminBody($withdrawal, $received)
        );
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
