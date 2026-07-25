<?php

declare(strict_types=1);

namespace RhShop\Catalog;

/**
 * Bestands-Zusammenfassung eines Produkts über alle seine Varianten. Die eine
 * Quelle für die Vor-Auswahl-Sicht: das "Fast ausverkauft"-Badge im Raster und die
 * Zusammenfassungszeile auf der Produktseite lesen NUR dieses Objekt, sie rechnen
 * den Bestand nicht selbst zusammen.
 *
 * Immutable Value-Object, framework-frei. Gebaut von VariantRepository::stockSummary().
 */
final class StockSummary
{
    public function __construct(
        /** Alle Varianten ausverkauft (keine kaufbar). */
        public readonly bool $soldOut,
        /** Mindestens eine noch verfügbare Variante ist knapp (Bestand <= Schwelle). */
        public readonly bool $anyLow,
        /** Kleinster Bestand unter den knappen Varianten (für "nur noch X"); null wenn keine knapp. */
        public readonly ?int $lowest,
    ) {
    }

    public static function empty(): self
    {
        return new self(false, false, null);
    }

    /**
     * Zusammenfassung aus einer Varianten-Liste rechnen (pure, WP-frei). Der Repository
     * holt nur die Varianten und ruft das hier auf, damit die Regel testbar bleibt.
     *
     * @param array<int, Variant> $variants
     */
    public static function fromVariants(array $variants, int $threshold): self
    {
        if ($variants === []) {
            return self::empty();
        }

        $anyAvailable = false;
        $lowest = null;

        foreach ($variants as $variant) {
            if (! $variant->isAvailable()) {
                continue;
            }
            $anyAvailable = true;

            if ($variant->isLowStock($threshold)) {
                $lowest = $lowest === null ? $variant->stock : min($lowest, $variant->stock);
            }
        }

        return new self(
            soldOut: ! $anyAvailable,
            anyLow: $lowest !== null,
            lowest: $lowest,
        );
    }
}
