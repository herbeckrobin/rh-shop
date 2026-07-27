<?php

declare(strict_types=1);

namespace RhShop\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Ersetzt {platzhalter} in Betreff und Zusatztext durch die konkreten Bestell-Werte.
 * Zwei Kontexte, weil der Betreff plain ist und der Zusatztext im HTML-Body landet:
 * dort werden Vorlagentext und eingesetzte Werte escaped, damit Kundenname und Co.
 * kein HTML einschleusen können.
 */
final class Placeholders
{
    /**
     * Betreff (plain). wp_mail kodiert den Betreff selbst, hier wird roh ersetzt.
     *
     * @param array<string, string> $values
     */
    public static function inSubject(string $template, array $values): string
    {
        foreach ($values as $key => $value) {
            $template = str_replace('{' . $key . '}', $value, $template);
        }

        return $template;
    }

    /**
     * Zusatztext für den HTML-Body: Vorlagentext escapen + Zeilenumbrüche, dann die
     * Werte escaped einsetzen. Leerer Text = leerer String (kein `<p>`).
     *
     * @param array<string, string> $values
     */
    public static function inHtml(string $template, array $values): string
    {
        if (trim($template) === '') {
            return '';
        }

        $html = nl2br(esc_html($template));
        foreach ($values as $key => $value) {
            $html = str_replace('{' . $key . '}', esc_html($value), $html);
        }

        return '<p>' . $html . '</p>';
    }
}
