<?php

declare(strict_types=1);

namespace RhShop\Catalog;

use RhShop\Support\Money;

/**
 * Eine verkaufbare Variante eines Produkts (z.B. "T-Shirt, Größe L, Schwarz").
 *
 * Immutable Value-Object. Der Preis liegt pro Variante (Größe L darf mehr kosten
 * als S), darum ist die Variante die kleinste verkaufbare Einheit, nicht das
 * Produkt. Ein Produkt ohne echte Varianten wird als eine Variante ohne Optionen
 * geführt (siehe VariantRepository), damit Warenkorb und Checkout nur EINEN Typ
 * kennen.
 *
 * `id` ist ein stabiler Schlüssel, den der Warenkorb referenziert. Er überlebt
 * das Umsortieren/Umbenennen von Zeilen (anders als der Array-Index oder die SKU,
 * die optional ist).
 *
 * Bestand: null = nicht verfolgt (immer verfügbar), sonst dekrementierbar mit
 * Ausverkauft bei 0.
 */
final class Variant
{
    public function __construct(
        public readonly string $id,
        public readonly string $option1,
        public readonly string $option2,
        public readonly string $sku,
        public readonly int $priceCents,
        public readonly ?int $stock,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->stock === null || $this->stock > 0;
    }

    /**
     * Lesbare Bezeichnung der Optionen ("L / Schwarz"), leer bei einem Produkt
     * ohne Varianten.
     */
    public function optionsLabel(): string
    {
        $parts = array_filter([$this->option1, $this->option2], static fn (string $p): bool => $p !== '');

        return implode(' / ', $parts);
    }

    public function formattedPrice(string $currencySymbol = '€'): string
    {
        return Money::format($this->priceCents, $currencySymbol);
    }

    /**
     * Serialisierbare Form fürs Post-Meta.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'option1' => $this->option1,
            'option2' => $this->option2,
            'sku' => $this->sku,
            'price_cents' => $this->priceCents,
            'stock' => $this->stock,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $stock = $row['stock'] ?? null;

        return new self(
            id: (string) ($row['id'] ?? ''),
            option1: (string) ($row['option1'] ?? ''),
            option2: (string) ($row['option2'] ?? ''),
            sku: (string) ($row['sku'] ?? ''),
            priceCents: (int) ($row['price_cents'] ?? 0),
            stock: ($stock === null || $stock === '') ? null : (int) $stock,
        );
    }
}
