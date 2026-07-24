<?php

declare(strict_types=1);

namespace RhShop\Orders;

/**
 * DB-Schema für die Bestellungen.
 *
 * Eine eigene Tabelle (keine CPT/Options): Bestellungen sind transaktionale Daten
 * mit Status-Übergängen und Lookups über die Stripe-Session, das gehört nicht in
 * autoloaded Options. Die Positionen liegen als JSON-Snapshot in der Zeile, mit den
 * Werten zum KAUFZEITPUNKT (Titel, Variante, Preis). So bleibt die Bestellung
 * korrekt, auch wenn Produkt/Preis später geändert oder gelöscht werden.
 *
 * dbDelta läuft beim Aktivieren und bei jedem Versions-Sprung (maybeUpgrade), weil
 * das Plugin sich per ZIP selbst aktualisiert und dann kein Activation-Hook feuert.
 */
final class Schema
{
    public const DB_VERSION = '2';
    public const OPTION_DB_VERSION = 'rhshop_orders_db_version';

    public static function ordersTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'rhshop_orders';
    }

    public static function withdrawalsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'rhshop_withdrawals';
    }

    public static function activate(): void
    {
        self::install();
    }

    public static function maybeUpgrade(): void
    {
        if (get_option(self::OPTION_DB_VERSION) === self::DB_VERSION) {
            return;
        }

        self::install();
    }

    private static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $orders = self::ordersTable();

        $sql = "CREATE TABLE {$orders} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_number VARCHAR(32) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            currency VARCHAR(3) NOT NULL DEFAULT 'eur',
            email VARCHAR(190) NOT NULL DEFAULT '',
            customer_name VARCHAR(190) NOT NULL DEFAULT '',
            address LONGTEXT NULL,
            items LONGTEXT NOT NULL,
            subtotal_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
            shipping_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
            tax_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
            total_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
            tax_mode VARCHAR(20) NOT NULL DEFAULT 'vat',
            stripe_session_id VARCHAR(255) NOT NULL DEFAULT '',
            stripe_payment_intent_id VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            paid_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY order_number (order_number),
            KEY status (status),
            KEY stripe_session_id (stripe_session_id)
        ) {$charset};";

        dbDelta($sql);

        // Widerrufe nach §356a BGB. Eigene Tabelle, weil ein Widerruf auch ohne
        // gefundene Bestellung erfasst werden muss (der Kunde kann eine falsche
        // Nummer eingeben, die Erklärung ist trotzdem entgegenzunehmen). received_at
        // ist der nachweispflichtige Eingangszeitpunkt (§356a Abs. 4/5).
        $withdrawals = self::withdrawalsTable();
        $sqlWithdrawals = "CREATE TABLE {$withdrawals} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            order_number VARCHAR(64) NOT NULL DEFAULT '',
            customer_name VARCHAR(190) NOT NULL DEFAULT '',
            email VARCHAR(190) NOT NULL DEFAULT '',
            reason TEXT NULL,
            ip VARCHAR(64) NOT NULL DEFAULT '',
            received_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY received_at (received_at)
        ) {$charset};";

        dbDelta($sqlWithdrawals);

        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
    }
}
