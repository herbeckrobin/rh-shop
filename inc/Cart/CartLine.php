<?php

declare(strict_types=1);

namespace RhShop\Cart;

defined( 'ABSPATH' ) || exit;

/**
 * Eine aufgelöste Warenkorb-Zeile: die rohe Cookie-Referenz (Produkt + Variante +
 * Menge) mit den aktuellen Katalogdaten verknüpft. Preis kommt IMMER frisch aus
 * dem Katalog, nie aus dem Cookie, damit ein manipuliertes Cookie keinen falschen
 * Preis durchsetzen kann.
 */
final class CartLine
{
    public function __construct(
        public readonly int $productId,
        public readonly string $variantId,
        public readonly string $productTitle,
        public readonly string $optionsLabel,
        public readonly string $sku,
        public readonly int $unitPriceCents,
        public readonly int $qty,
        public readonly string $permalink,
        public readonly string $thumbnailUrl,
        /** Höchste kaufbare Menge (Bestand); null = unbegrenzt. Deckelt den Warenkorb-Stepper. */
        public readonly ?int $maxQty = null,
    ) {
    }

    public function lineTotalCents(): int
    {
        return $this->unitPriceCents * $this->qty;
    }

    public function key(): string
    {
        return $this->productId . ':' . $this->variantId;
    }
}
