<?php

declare(strict_types=1);

namespace RhShop\Catalog;

/**
 * Liest und schreibt die Varianten eines Produkts als Post-Meta.
 *
 * Bewusst KEINE eigene DB-Tabelle: bei kleinen Sortimenten (Zielgröße 5-10
 * Produkte) sind Varianten als Post-Meta die schlankere Lösung, editierbar über
 * eine native Meta-Box, ohne dbDelta/Schema-Migrationen. Skaliert nicht auf
 * tausende Produkte, soll es aber auch nicht.
 *
 * Ein Produkt OHNE echte Varianten (z.B. ein Sticker) wird über einen einfachen
 * Produktpreis geführt und hier als EINE synthetische Variante ohne Optionen
 * zurückgegeben. So kennen Warenkorb und Checkout nur den Varianten-Typ und
 * müssen den Sonderfall "Produkt ohne Varianten" nicht überall verzweigen.
 */
final class VariantRepository
{
    public const META_VARIANTS = '_rhshop_variants';
    public const META_SIMPLE_PRICE = '_rhshop_price_cents';
    public const META_SIMPLE_STOCK = '_rhshop_stock';

    // PAngV-Grundpreis. Die Nennmenge liegt PRO VARIANTE (echte Varianten im
    // Varianten-Array, das Produkt ohne Varianten in META_GP_AMOUNT). Die Einheit
    // (g/kg/ml/l/cm/m/m²) ist die Mess-Dimension der Ware und gilt produktweit,
    // sie wechselt nicht pro Variante.
    public const META_GP_AMOUNT = '_rhshop_gp_amount';
    public const META_GP_UNIT = '_rhshop_gp_unit';

    // Bezeichnung der zwei Varianten-Achsen (option1/option2) pro Produkt. Nicht jedes
    // Sortiment unterscheidet nach Größe/Farbe: ein Produkt kann nach Material/Länge
    // oder Duft/Menge variieren. Leer = der übersetzte Default (Größe/Farbe).
    public const META_AXIS1_LABEL = '_rhshop_axis1_label';
    public const META_AXIS2_LABEL = '_rhshop_axis2_label';

    private const SIMPLE_VARIANT_ID = 'default';

    /**
     * Die verkaufbaren Einheiten eines Produkts. Entweder die gepflegten Varianten
     * oder, wenn keine da sind, eine synthetische Einheit aus dem einfachen Preis.
     *
     * @return array<int, Variant>
     */
    public function forProduct(int $productId): array
    {
        $rows = get_post_meta($productId, self::META_VARIANTS, true);

        if (is_array($rows) && $rows !== []) {
            return array_values(array_map(
                static fn (array $row): Variant => Variant::fromArray($row),
                array_filter($rows, 'is_array')
            ));
        }

        return [$this->simpleVariant($productId)];
    }

    public function hasRealVariants(int $productId): bool
    {
        $rows = get_post_meta($productId, self::META_VARIANTS, true);

        return is_array($rows) && $rows !== [];
    }

    public function find(int $productId, string $variantId): ?Variant
    {
        foreach ($this->forProduct($productId) as $variant) {
            if ($variant->id === $variantId) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * Günstigster verfügbarer Preis, für die "ab X €"-Anzeige im Raster.
     */
    public function fromPriceCents(int $productId): int
    {
        $cheapest = $this->cheapestVariant($productId);

        return $cheapest?->priceCents ?? 0;
    }

    /**
     * Die günstigste Einheit eines Produkts (für "ab X €" + den dazu passenden
     * Grundpreis im Raster). null, wenn kein Preis gepflegt ist.
     */
    public function cheapestVariant(int $productId): ?Variant
    {
        $priced = array_filter(
            $this->forProduct($productId),
            static fn (Variant $v): bool => $v->priceCents > 0
        );

        if ($priced === []) {
            return null;
        }

        usort($priced, static fn (Variant $a, Variant $b): int => $a->priceCents <=> $b->priceCents);

        return $priced[0];
    }

    /**
     * Grundpreis-Einheit des Produkts (produktweit). Leer = keine Grundpreis-Pflicht.
     */
    public function unit(int $productId): string
    {
        $unit = (string) get_post_meta($productId, self::META_GP_UNIT, true);

        return GrundpreisUnit::isValid($unit) ? $unit : '';
    }

    /**
     * Die Bezeichnungen der zwei Varianten-Achsen eines Produkts, mit übersetztem
     * Default (Größe/Farbe), wenn nicht gepflegt. Eine Quelle für Meta-Box und
     * Frontend, damit die Achsen-Namen nicht auseinanderlaufen.
     *
     * @return array{0: string, 1: string}
     */
    public function axisLabels(int $productId): array
    {
        $one = trim((string) get_post_meta($productId, self::META_AXIS1_LABEL, true));
        $two = trim((string) get_post_meta($productId, self::META_AXIS2_LABEL, true));

        return [
            $one !== '' ? $one : __('Größe', 'rh-shop'),
            $two !== '' ? $two : __('Farbe', 'rh-shop'),
        ];
    }

    public function isSoldOut(int $productId): bool
    {
        foreach ($this->forProduct($productId) as $variant) {
            if ($variant->isAvailable()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Bestands-Zusammenfassung über alle Varianten (die eine Quelle fürs Badge im
     * Raster und die Zusammenfassungszeile auf der Produktseite). Berechnet, ob alles
     * ausverkauft ist und ob/wie knapp die verfügbaren Varianten sind.
     */
    public function stockSummary(int $productId, int $threshold): StockSummary
    {
        return StockSummary::fromVariants($this->forProduct($productId), $threshold);
    }

    /**
     * Varianten speichern. Zeilen ohne stabile id bekommen eine (überlebt das
     * Umsortieren/Umbenennen, worauf der Warenkorb sich verlässt).
     *
     * @param array<int, Variant> $variants
     */
    public function save(int $productId, array $variants): void
    {
        $rows = array_map(
            function (Variant $variant): array {
                $data = $variant->toArray();
                if ($data['id'] === '') {
                    $data['id'] = self::generateId();
                }
                return $data;
            },
            $variants
        );

        update_post_meta($productId, self::META_VARIANTS, array_values($rows));
    }

    public function saveSimple(int $productId, int $priceCents, ?int $stock): void
    {
        update_post_meta($productId, self::META_SIMPLE_PRICE, $priceCents);
        update_post_meta($productId, self::META_SIMPLE_STOCK, $stock === null ? '' : $stock);
    }

    /**
     * Bestand einer Einheit reduzieren (nach bestätigter Zahlung). Bei nicht
     * verfolgtem Bestand (null) ein No-Op. Gibt false zurück, wenn die Einheit
     * nicht gefunden wurde.
     */
    public function decrementStock(int $productId, string $variantId, int $qty): bool
    {
        if ($variantId === self::SIMPLE_VARIANT_ID && ! $this->hasRealVariants($productId)) {
            $stock = get_post_meta($productId, self::META_SIMPLE_STOCK, true);
            if ($stock === '' || $stock === false) {
                return true; // nicht verfolgt
            }
            update_post_meta($productId, self::META_SIMPLE_STOCK, max(0, (int) $stock - $qty));

            return true;
        }

        $rows = get_post_meta($productId, self::META_VARIANTS, true);
        if (! is_array($rows)) {
            return false;
        }

        $found = false;
        foreach ($rows as $i => $row) {
            if (! is_array($row) || (string) ($row['id'] ?? '') !== $variantId) {
                continue;
            }
            $found = true;
            if (($row['stock'] ?? null) !== null && $row['stock'] !== '') {
                $rows[$i]['stock'] = max(0, (int) $row['stock'] - $qty);
            }
        }

        if ($found) {
            update_post_meta($productId, self::META_VARIANTS, $rows);
        }

        return $found;
    }

    private function simpleVariant(int $productId): Variant
    {
        $priceCents = (int) get_post_meta($productId, self::META_SIMPLE_PRICE, true);
        $stockRaw = get_post_meta($productId, self::META_SIMPLE_STOCK, true);
        $gpRaw = get_post_meta($productId, self::META_GP_AMOUNT, true);

        return new Variant(
            id: self::SIMPLE_VARIANT_ID,
            option1: '',
            option2: '',
            sku: '',
            priceCents: $priceCents,
            stock: ($stockRaw === '' || $stockRaw === false) ? null : (int) $stockRaw,
            gpAmount: ($gpRaw === '' || $gpRaw === false || (float) $gpRaw <= 0) ? null : (float) $gpRaw,
        );
    }

    public static function generateId(): string
    {
        return bin2hex(random_bytes(4));
    }
}
