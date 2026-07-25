<?php

declare(strict_types=1);

namespace RhShop\Catalog;

/**
 * Die eine Quelle für die PAngV-Grundpreis-Einheiten: welche Einheiten es gibt, in
 * welche Basiseinheit sie umgerechnet werden (1 kg / 1 l / 1 m / 1 m²) und die
 * Umrechnung selbst. Vorher lag diese Liste plus die Faktoren verstreut in Render,
 * VariantRepository und VariantMetaBox, jede Änderung musste an drei Stellen passieren.
 *
 * Registry-Format je Einheit: [Basiseinheit-Label, Faktor in die Basiseinheit].
 * Beispiel 'g' => ['kg', 0.001]: 1 g sind 0,001 kg, der Grundpreis wird also je kg
 * ausgewiesen. Die Faktoren sind physikalische Konstanten, keine willkürlichen Werte:
 * g→kg = /1000, ml→l = /1000, cm→m = /100.
 */
final class GrundpreisUnit
{
    /** @var array<string, array{0: string, 1: float}> */
    private const UNITS = [
        'g'  => ['kg', 0.001],
        'kg' => ['kg', 1.0],
        'ml' => ['l', 0.001],
        'l'  => ['l', 1.0],
        'cm' => ['m', 0.01],
        'm'  => ['m', 1.0],
        'm2' => ['m²', 1.0],
    ];

    public static function isValid(string $unit): bool
    {
        return isset(self::UNITS[$unit]);
    }

    /**
     * Auswahl für die Meta-Box (führend die Stückware-Option ohne Grundpreis).
     *
     * @return array<string, string> Einheit-Schlüssel => Anzeige-Label
     */
    public static function options(): array
    {
        $options = ['' => __('keine (Stückware)', 'rh-shop')];
        foreach (array_keys(self::UNITS) as $unit) {
            $options[$unit] = self::displayLabel($unit);
        }

        return $options;
    }

    /**
     * Preis je Basiseinheit in Cent (z.B. Cent pro kg), oder null wenn nicht
     * berechenbar (unbekannte Einheit, keine Nennmenge, kein Preis).
     */
    public static function basePriceCents(?float $amount, int $priceCents, string $unit): ?int
    {
        if ($amount === null || $amount <= 0 || $priceCents <= 0 || ! self::isValid($unit)) {
            return null;
        }

        [, $factor] = self::UNITS[$unit];
        $contentInBase = $amount * $factor;
        if ($contentInBase <= 0) {
            return null;
        }

        return (int) round($priceCents / $contentInBase);
    }

    /** Label der Basiseinheit, z.B. "kg" für "g". Leer bei unbekannter Einheit. */
    public static function baseLabel(string $unit): string
    {
        return self::isValid($unit) ? self::UNITS[$unit][0] : '';
    }

    /** Anzeige-Label der Einheit selbst ("m²" statt des Slugs "m2"). */
    public static function displayLabel(string $unit): string
    {
        return $unit === 'm2' ? 'm²' : $unit;
    }
}
