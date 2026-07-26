<?php

declare(strict_types=1);

namespace RhShop\Catalog;

defined( 'ABSPATH' ) || exit;

use RhShop\Orders\Schema;

/**
 * Bestand-Reservierungen gegen Überverkauf bei gleichzeitigem Zugriff.
 *
 * Reserviert wird beim Auslösen der Bestellung (nicht im Warenkorb). Der Kern ist
 * atomar: in einer Transaktion wird die Bestand-Zeile per `SELECT ... FOR UPDATE`
 * gesperrt, dann verfügbar = Bestand − aktive Reservierungen geprüft und erst dann
 * reserviert. Zwei gleichzeitige Anfragen für den letzten Artikel serialisieren am
 * Zeilen-Lock, die zweite sieht die Reservierung der ersten und scheitert sauber.
 * (Setzt InnoDB voraus, siehe Schema.)
 *
 * Verfügbar = Bestand − aktive Reservierungen. Abgelaufene Reservierungen zählen nicht
 * mehr mit (lazy, `expires_at > UTC_TIMESTAMP()`), der Bestand ist so ohne Aufräumen
 * wieder frei; ein Cron räumt die Zeilen zusätzlich weg.
 */
final class ReservationRepository
{
    /**
     * Menge einer Variante für eine Bestellung reservieren. true = reserviert (oder
     * Bestand nicht verfolgt = unbegrenzt), false = nicht genug verfügbar.
     */
    public function reserve(int $orderId, int $productId, string $variantId, int $qty, int $holdMinutes): bool
    {
        if ($qty <= 0) {
            return true;
        }

        global $wpdb;
        $stockTable = Schema::variantStockTable();
        $resTable = Schema::reservationsTable();
        $holdMinutes = max(1, $holdMinutes);

        $wpdb->query('START TRANSACTION');

        // Bricht der Request zwischen START und COMMIT ab (Fatal, Memory-Limit, Filter-
        // Hook), darf kein Zeilen-Lock offen bleiben: Throwable rollt zurück und wirft
        // weiter (Sichtbarkeit im Monitoring, siehe ADR 0001).
        try {
            // Bestand-Zeile exklusiv sperren. Serialisiert konkurrierende Reservierungen
            // derselben Variante.
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT stock, tracked FROM {$stockTable} WHERE product_id = %d AND variant_id = %s FOR UPDATE",
                $productId,
                $variantId
            ));

            // Keine Zeile oder nicht verfolgt = unbegrenzt, keine Reservierung nötig.
            if ($row === null || (int) $row->tracked === 0) {
                $wpdb->query('COMMIT');

                return true;
            }

            // Aktive Reservierungen ANDERER Bestellungen (die eigene zählt nicht doppelt).
            $reserved = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(qty), 0) FROM {$resTable}
                 WHERE product_id = %d AND variant_id = %s AND expires_at > UTC_TIMESTAMP() AND order_id <> %d",
                $productId,
                $variantId,
                $orderId
            ));

            if ((int) $row->stock - $reserved < $qty) {
                $wpdb->query('ROLLBACK');

                return false;
            }

            // Reservieren (idempotent pro Bestellung+Variante über den Unique-Key).
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$resTable} (order_id, product_id, variant_id, qty, expires_at, created_at)
                 VALUES (%d, %d, %s, %d, DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d MINUTE), UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE qty = VALUES(qty), expires_at = VALUES(expires_at)",
                $orderId,
                $productId,
                $variantId,
                $qty,
                $holdMinutes
            ));

            $wpdb->query('COMMIT');

            return true;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');

            throw $e;
        }
    }

    /**
     * Alle Reservierungen einer Bestellung freigeben (bei Abbruch oder nachdem die
     * Zahlung den Bestand echt reduziert hat).
     */
    public function releaseForOrder(int $orderId): void
    {
        global $wpdb;

        $wpdb->delete(Schema::reservationsTable(), ['order_id' => $orderId], ['%d']);
    }

    /**
     * Summe der aktiven (nicht abgelaufenen) Reservierungen einer Variante.
     */
    public function activeReserved(int $productId, string $variantId): int
    {
        global $wpdb;
        $table = Schema::reservationsTable();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(qty), 0) FROM {$table}
             WHERE product_id = %d AND variant_id = %s AND expires_at > UTC_TIMESTAMP()",
            $productId,
            $variantId
        ));
    }

    /**
     * Aktive Reservierungen aller Varianten eines Produkts (ein Query, für die
     * Verfügbarkeits-Anzeige im Frontend).
     *
     * @return array<string, int> variant_id => reservierte Menge
     */
    public function activeReservedForProduct(int $productId): array
    {
        global $wpdb;
        $table = Schema::reservationsTable();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT variant_id, COALESCE(SUM(qty), 0) AS reserved FROM {$table}
             WHERE product_id = %d AND expires_at > UTC_TIMESTAMP() GROUP BY variant_id",
            $productId
        ));

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->variant_id] = (int) $row->reserved;
        }

        return $map;
    }

    /**
     * Abgelaufene Reservierungen löschen (Cron). Gibt die Zahl der entfernten Zeilen
     * zurück.
     */
    public function pruneExpired(): int
    {
        global $wpdb;
        $table = Schema::reservationsTable();

        return (int) $wpdb->query("DELETE FROM {$table} WHERE expires_at <= UTC_TIMESTAMP()");
    }
}
