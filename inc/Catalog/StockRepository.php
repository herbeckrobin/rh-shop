<?php

declare(strict_types=1);

namespace RhShop\Catalog;

defined( 'ABSPATH' ) || exit;

use RhShop\Orders\Schema;

/**
 * Physischer Variantenbestand in der eigenen Tabelle (eine Zeile pro Variante). Die
 * eine Quelle für den Bestand, getrennt von der Varianten-Definition (die als Config
 * im Post-Meta bleibt). Erlaubt atomare Reservierung und atomaren Abzug mit Zeilen-
 * Lock, was im serialisierten Post-Meta nicht ging.
 *
 * Bestand null = nicht verfolgt (unbegrenzt). In der Tabelle steht dafür tracked=0,
 * damit die atomare Bedingung `stock >= qty` ohne NULL-Sonderfall auskommt.
 */
final class StockRepository
{
    /**
     * Physischer Bestand einer Variante. null = nicht verfolgt (unbegrenzt) oder noch
     * keine Zeile vorhanden (neue Variante, bis der Bestand gepflegt wird).
     */
    public function physical(int $productId, string $variantId): ?int
    {
        global $wpdb;
        $table = Schema::variantStockTable();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT stock, tracked FROM {$table} WHERE product_id = %d AND variant_id = %s",
            $productId,
            $variantId
        ));

        if ($row === null || (int) $row->tracked === 0) {
            return null;
        }

        return (int) $row->stock;
    }

    /**
     * Bestand aller Varianten eines Produkts (ein Query). null = nicht verfolgt.
     *
     * @return array<string, int|null> variant_id => Bestand
     */
    public function forProduct(int $productId): array
    {
        global $wpdb;
        $table = Schema::variantStockTable();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT variant_id, stock, tracked FROM {$table} WHERE product_id = %d",
            $productId
        ));

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->variant_id] = ((int) $row->tracked === 0) ? null : (int) $row->stock;
        }

        return $map;
    }

    /**
     * Bestand setzen (Upsert). null = nicht verfolgt (unbegrenzt).
     */
    public function set(int $productId, string $variantId, ?int $stock): void
    {
        global $wpdb;
        $table = Schema::variantStockTable();

        $tracked = $stock === null ? 0 : 1;
        $value = $stock === null ? 0 : max(0, $stock);

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (product_id, variant_id, stock, tracked, updated_at) VALUES (%d, %s, %d, %d, %s)
             ON DUPLICATE KEY UPDATE stock = VALUES(stock), tracked = VALUES(tracked), updated_at = VALUES(updated_at)",
            $productId,
            $variantId,
            $value,
            $tracked,
            current_time('mysql', true)
        ));
    }

    /**
     * Bestand atomar reduzieren (nach bestätigter Zahlung). Nur bei verfolgtem Bestand,
     * nie unter 0. Ein einzelnes UPDATE, also race-frei ohne Lese-Schreib-Fenster.
     */
    public function decrement(int $productId, string $variantId, int $qty): void
    {
        if ($qty <= 0) {
            return;
        }

        global $wpdb;
        $table = Schema::variantStockTable();

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET stock = GREATEST(0, stock - %d), updated_at = %s
             WHERE product_id = %d AND variant_id = %s AND tracked = 1",
            $qty,
            current_time('mysql', true),
            $productId,
            $variantId
        ));
    }

    /**
     * Bestand-Zeilen von Varianten löschen, die es nicht mehr gibt (nach dem Speichern
     * im Meta-Box, wenn Varianten entfernt wurden). Leere Liste = alle löschen.
     *
     * @param array<int, string> $keepVariantIds
     */
    public function pruneOrphans(int $productId, array $keepVariantIds): void
    {
        global $wpdb;
        $table = Schema::variantStockTable();

        if ($keepVariantIds === []) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE product_id = %d", $productId));

            return;
        }

        $placeholders = implode(', ', array_fill(0, count($keepVariantIds), '%s'));
        $sql = "DELETE FROM {$table} WHERE product_id = %d AND variant_id NOT IN ({$placeholders})";

        $wpdb->query($wpdb->prepare($sql, array_merge([$productId], array_values($keepVariantIds))));
    }
}
