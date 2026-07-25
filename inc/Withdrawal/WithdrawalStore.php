<?php

declare(strict_types=1);

namespace RhShop\Withdrawal;

use RhShop\Orders\Schema;

/**
 * Datenzugriff für Widerrufe. Dokumentiert jeden eingegangenen Widerruf mit
 * Eingangszeitpunkt (Nachweis nach §356a Abs. 4/5).
 */
final class WithdrawalStore
{
    /**
     * @param array{order_id?:int, order_number?:string, customer_name?:string, email?:string, reason?:string, ip?:string} $data
     * @return int Datensatz-ID (mit dem Eingangszeitpunkt), 0 bei Fehler.
     */
    public function create(array $data): int
    {
        global $wpdb;

        $table = Schema::withdrawalsTable();
        $row = [
            'order_id' => max(0, (int) ($data['order_id'] ?? 0)),
            'order_number' => sanitize_text_field((string) ($data['order_number'] ?? '')),
            'customer_name' => sanitize_text_field((string) ($data['customer_name'] ?? '')),
            'email' => sanitize_email((string) ($data['email'] ?? '')),
            'reason' => sanitize_textarea_field((string) ($data['reason'] ?? '')),
            'ip' => sanitize_text_field((string) ($data['ip'] ?? '')),
            'received_at' => current_time('mysql'),
        ];
        $formats = ['%d', '%s', '%s', '%s', '%s', '%s', '%s'];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $inserted = $wpdb->insert($table, $row, $formats);

        return $inserted === false ? 0 : (int) $wpdb->insert_id;
    }

    public function find(int $id): ?Withdrawal
    {
        global $wpdb;

        $table = Schema::withdrawalsTable();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);

        return is_array($row) ? Withdrawal::fromRow($row) : null;
    }

    /**
     * @return array<int, Withdrawal>
     */
    public function recent(int $limit = 100): array
    {
        global $wpdb;

        $table = Schema::withdrawalsTable();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max(1, $limit)),
            ARRAY_A
        );

        return array_map([Withdrawal::class, 'fromRow'], is_array($rows) ? $rows : []);
    }

    /**
     * Anzahl eingegangener Widerrufe (für die Übersicht). Es gibt kein "erledigt"-Flag,
     * der Betreiber bearbeitet sie manuell, darum die Gesamtzahl.
     */
    public function count(): int
    {
        global $wpdb;

        $table = Schema::withdrawalsTable();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }
}
