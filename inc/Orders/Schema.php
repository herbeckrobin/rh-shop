<?php

declare(strict_types=1);

namespace RhShop\Orders;

defined( 'ABSPATH' ) || exit;

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
    public const DB_VERSION = '6';
    public const OPTION_DB_VERSION = 'rhshop_orders_db_version';
    public const OPTION_STOCK_MIGRATED = 'rhshop_stock_migrated';

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

    /**
     * Physischer Variantenbestand, eine Zeile pro Variante. Bewusst raus aus dem
     * serialisierten Post-Meta: Bestand ist transaktional und muss atomar (mit Zeilen-
     * Lock, FOR UPDATE) reserviert und reduziert werden, was in einem serialisierten
     * Blob nicht geht. Die Varianten-DEFINITION (Optionen, Preis, SKU) bleibt Config
     * im Post-Meta, nur der Bestand wohnt hier.
     */
    public static function variantStockTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'rhshop_variant_stock';
    }

    /**
     * Bestand-Reservierungen mit Ablaufzeit (gegen Überverkauf bei gleichzeitigem
     * Zugriff). Verfügbar = Bestand − aktive Reservierungen. Reserviert wird beim
     * Auslösen der Bestellung, abgelaufene Reservierungen zählen nicht mehr mit.
     */
    public static function reservationsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'rhshop_stock_reservations';
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
            shipping_method VARCHAR(190) NOT NULL DEFAULT '',
            carrier VARCHAR(32) NOT NULL DEFAULT '',
            tracking_number VARCHAR(190) NOT NULL DEFAULT '',
            tax_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
            total_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
            tax_mode VARCHAR(20) NOT NULL DEFAULT 'vat',
            stripe_session_id VARCHAR(255) NOT NULL DEFAULT '',
            stripe_payment_intent_id VARCHAR(255) NOT NULL DEFAULT '',
            invoice_id VARCHAR(64) NOT NULL DEFAULT '',
            invoice_number VARCHAR(64) NOT NULL DEFAULT '',
            invoice_url VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            paid_at DATETIME NULL DEFAULT NULL,
            shipped_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY order_number (order_number),
            KEY status (status),
            KEY stripe_session_id (stripe_session_id),
            KEY stripe_payment_intent_id (stripe_payment_intent_id(191))
        ) ENGINE=InnoDB {$charset};";

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

        // Physischer Bestand pro Variante. tracked=0 bedeutet unbegrenzt (nicht
        // verfolgt), dann greifen Reservierung und Abzug nicht. So ist die atomare
        // Bedingung `stock >= qty` sauber, ohne NULL-Sonderfall.
        $stock = self::variantStockTable();
        $sqlStock = "CREATE TABLE {$stock} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            variant_id VARCHAR(64) NOT NULL DEFAULT '',
            stock INT NOT NULL DEFAULT 0,
            tracked TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY product_variant (product_id, variant_id)
        ) ENGINE=InnoDB {$charset};";

        dbDelta($sqlStock);

        $reservations = self::reservationsTable();
        $sqlReservations = "CREATE TABLE {$reservations} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            product_id BIGINT UNSIGNED NOT NULL,
            variant_id VARCHAR(64) NOT NULL DEFAULT '',
            qty INT UNSIGNED NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_product_variant (order_id, product_id, variant_id),
            KEY variant_expiry (product_id, variant_id, expires_at)
        ) ENGINE=InnoDB {$charset};";

        dbDelta($sqlReservations);

        self::migrateStockFromPostMeta();
        self::upgradeReservationSchema();

        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
    }

    /**
     * Bestandsinstallationen härten (dbDelta ändert Engine und Unique-Keys nicht
     * zuverlässig): Engine der bestandskritischen Tabellen auf InnoDB ziehen (sonst
     * sind FOR UPDATE und Transaktionen wirkungslos) und den Reservierungs-Unique-Key
     * von (order_id, variant_id) auf (order_id, product_id, variant_id) tauschen. Ohne
     * product_id kollidieren zwei Produkte ohne Varianten (beide variant_id 'default')
     * in EINER Bestellung, die zweite Reservierung geht verloren -> Überverkauf.
     */
    private static function upgradeReservationSchema(): void
    {
        global $wpdb;
        $res = self::reservationsTable();

        // Engine sicherstellen (idempotent). Auf Hosts mit MyISAM-Default wäre der
        // ganze Überverkaufs-Schutz sonst still deaktiviert.
        foreach ([$res, self::variantStockTable(), self::ordersTable()] as $tbl) {
            $engine = $wpdb->get_var($wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $tbl
            ));
            if ($engine !== null && strcasecmp((string) $engine, 'InnoDB') !== 0) {
                $wpdb->query("ALTER TABLE {$tbl} ENGINE=InnoDB");
            }
        }

        // Alten Unique-Key ersetzen. Reservierungen sind ephemer (kurze Haltedauer),
        // ein Leeren garantiert, dass der neue, engere Unique-Key kollisionsfrei greift.
        $hasOld = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'order_variant'",
            $res
        ));
        if ($hasOld > 0) {
            $wpdb->query("DELETE FROM {$res}");
            $wpdb->query("ALTER TABLE {$res} DROP INDEX order_variant");
        }

        $hasNew = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'order_product_variant'",
            $res
        ));
        if ($hasNew === 0) {
            $wpdb->query("ALTER TABLE {$res} ADD UNIQUE KEY order_product_variant (order_id, product_id, variant_id)");
        }
    }

    /**
     * Einmaliger Umzug des Bestands aus dem Post-Meta in die Bestand-Tabelle. Läuft
     * genau einmal (Flag), INSERT IGNORE schützt zusätzlich vorhandene Zeilen, damit
     * ein erneuter Aufruf nie einen bereits gepflegten Tabellen-Bestand mit altem
     * Post-Meta überschreibt.
     */
    private static function migrateStockFromPostMeta(): void
    {
        if (get_option(self::OPTION_STOCK_MIGRATED) === '1') {
            return;
        }

        global $wpdb;
        $table = self::variantStockTable();
        $now = current_time('mysql', true);

        $productIds = get_posts([
            'post_type' => 'rh_product',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);

        foreach ($productIds as $productId) {
            $productId = (int) $productId;

            // Simple-Produkt: _rhshop_stock (leer/false = nicht verfolgt).
            $simple = get_post_meta($productId, '_rhshop_stock', true);
            $tracked = ! ($simple === '' || $simple === false) ? 1 : 0;
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$table} (product_id, variant_id, stock, tracked, updated_at) VALUES (%d, %s, %d, %d, %s)",
                $productId,
                \RhShop\Catalog\VariantRepository::SIMPLE_VARIANT_ID,
                $tracked === 1 ? max(0, (int) $simple) : 0,
                $tracked,
                $now
            ));

            // Echte Varianten: _rhshop_variants[].stock (null/'' = nicht verfolgt).
            $rows = get_post_meta($productId, '_rhshop_variants', true);
            if (! is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (! is_array($row) || ($row['id'] ?? '') === '') {
                    continue;
                }
                $raw = $row['stock'] ?? null;
                $isTracked = ! ($raw === null || $raw === '') ? 1 : 0;
                $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO {$table} (product_id, variant_id, stock, tracked, updated_at) VALUES (%d, %s, %d, %d, %s)",
                    $productId,
                    (string) $row['id'],
                    $isTracked === 1 ? max(0, (int) $raw) : 0,
                    $isTracked,
                    $now
                ));
            }
        }

        update_option(self::OPTION_STOCK_MIGRATED, '1');
    }
}
