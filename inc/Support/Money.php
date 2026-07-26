<?php

declare(strict_types=1);

namespace RhShop\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Geldbeträge werden intern IMMER als Ganzzahl in Cent geführt, nie als Float.
 * Float-Arithmetik auf Preisen kippt bei Summen und Steuern (0.1 + 0.2 != 0.3);
 * Stripe erwartet Beträge ohnehin in der kleinsten Währungseinheit (Cent).
 */
final class Money
{
    /**
     * Editor-Eingabe ("24,90" oder "24.90") in Cent umwandeln.
     * Tausenderpunkte sind bei den kleinen Sortimenten hier nicht zu erwarten,
     * darum bewusst simpel gehalten: Komma zu Punkt, dann in Cent runden.
     */
    public static function toCents(string $input): int
    {
        $normalized = trim($input);
        $normalized = str_replace([' ', "\u{00A0}"], '', $normalized);
        $normalized = str_replace(',', '.', $normalized);

        if ($normalized === '' || ! is_numeric($normalized)) {
            return 0;
        }

        return (int) round((float) $normalized * 100);
    }

    /**
     * Cent als deutsches Preisformat ("24,90 €") fürs Admin/Frontend.
     */
    public static function format(int $cents, string $currencySymbol = '€'): string
    {
        return number_format($cents / 100, 2, ',', '.') . ' ' . $currencySymbol;
    }

    /**
     * Cent als reiner Dezimalstring ("24.90") für Formularfelder (Punkt-Notation
     * ist im number-Input verlässlicher als das Komma).
     */
    public static function toInput(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
