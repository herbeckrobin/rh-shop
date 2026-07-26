<?php

declare(strict_types=1);

namespace RhShop\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Datenzugriff für Bestellungen. Hält CRUD und die Status-Übergänge an einer
 * Stelle, damit Checkout, Webhook und Admin dieselbe Wahrheit lesen.
 */
final class OrderStore
{
    /**
     * Neue Bestellung im Status "pending" anlegen. Die Bestellnummer wird aus der
     * Auto-Increment-ID abgeleitet (dadurch garantiert eindeutig und lesbar), darum
     * Insert dann Nummer nachtragen.
     *
     * @param array{
     *   currency?:string, email?:string, customer_name?:string,
     *   items:array<int, array<string, mixed>>,
     *   subtotal_cents?:int, shipping_cents?:int, tax_cents?:int, total_cents?:int,
     *   tax_mode?:string
     * } $draft
     * @return int Bestell-ID, 0 bei Fehler.
     */
    public function create(array $draft): int
    {
        global $wpdb;

        $table = Schema::ordersTable();
        $now = current_time('mysql');

        $data = [
            'order_number' => '',
            'status' => Order::STATUS_PENDING,
            'currency' => substr((string) ($draft['currency'] ?? 'eur'), 0, 3),
            'email' => sanitize_email((string) ($draft['email'] ?? '')),
            'customer_name' => sanitize_text_field((string) ($draft['customer_name'] ?? '')),
            'address' => null,
            'items' => (string) wp_json_encode(array_values($draft['items'] ?? [])),
            'subtotal_cents' => max(0, (int) ($draft['subtotal_cents'] ?? 0)),
            'shipping_cents' => max(0, (int) ($draft['shipping_cents'] ?? 0)),
            'tax_cents' => max(0, (int) ($draft['tax_cents'] ?? 0)),
            'total_cents' => max(0, (int) ($draft['total_cents'] ?? 0)),
            'tax_mode' => in_array($draft['tax_mode'] ?? '', [Order::TAX_VAT, Order::TAX_KLEINUNTERNEHMER], true) ? $draft['tax_mode'] : Order::TAX_VAT,
            'stripe_session_id' => '',
            'stripe_payment_intent_id' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $formats = ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s'];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $inserted = $wpdb->insert($table, $data, $formats);
        if ($inserted === false) {
            return 0;
        }

        $id = (int) $wpdb->insert_id;
        $number = sprintf('RH-%06d', $id);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update($table, ['order_number' => $number], ['id' => $id], ['%s'], ['%d']);

        return $id;
    }

    public function find(int $id): ?Order
    {
        return $this->fetch('id = %d', $id);
    }

    public function findByNumber(string $number): ?Order
    {
        return $this->fetch('order_number = %s', $number);
    }

    public function findByPaymentIntent(string $paymentIntentId): ?Order
    {
        if ($paymentIntentId === '') {
            return null;
        }

        return $this->fetch('stripe_payment_intent_id = %s', $paymentIntentId);
    }

    public function attachPaymentIntent(int $id, string $paymentIntentId): void
    {
        global $wpdb;

        $table = Schema::ordersTable();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $table,
            ['stripe_payment_intent_id' => $paymentIntentId, 'updated_at' => current_time('mysql')],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * Bestellung als bezahlt markieren. Idempotent: nur ein Übergang von "pending"
     * zählt, wird die Bestellung ein zweites Mal (doppeltes Webhook-Event) gemeldet,
     * gibt die Methode null zurück und es passiert nichts (kein zweiter Bestand-
     * Abzug, keine zweite Mail).
     *
     * @param array{email?:string, name?:string, address?:array<string, mixed>} $buyer
     * @return Order|null Die frisch bezahlte Bestellung, oder null wenn nicht
     *                    gefunden bzw. bereits bezahlt.
     */
    public function markPaidByPaymentIntent(string $paymentIntentId, array $buyer = []): ?Order
    {
        global $wpdb;

        $order = $this->findByPaymentIntent($paymentIntentId);
        if ($order === null || $order->isPaid()) {
            return null;
        }

        $now = current_time('mysql');
        $data = [
            'status' => Order::STATUS_PAID,
            'paid_at' => $now,
            'updated_at' => $now,
        ];
        $formats = ['%s', '%s', '%s'];

        if (! empty($buyer['email'])) {
            $data['email'] = sanitize_email((string) $buyer['email']);
            $formats[] = '%s';
        }
        if (! empty($buyer['name'])) {
            $data['customer_name'] = sanitize_text_field((string) $buyer['name']);
            $formats[] = '%s';
        }
        if (! empty($buyer['address']) && is_array($buyer['address'])) {
            $data['address'] = (string) wp_json_encode($buyer['address']);
            $formats[] = '%s';
        }

        $table = Schema::ordersTable();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update($table, $data, ['id' => $order->id], $formats, ['%d']);

        return $this->find($order->id);
    }

    public function saveInvoice(int $id, string $invoiceId, string $invoiceNumber, string $invoiceUrl): void
    {
        global $wpdb;

        $table = Schema::ordersTable();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $table,
            [
                'invoice_id' => $invoiceId,
                'invoice_number' => $invoiceNumber,
                'invoice_url' => $invoiceUrl,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );
    }

    public function updateStatus(int $id, string $status): void
    {
        if (! in_array($status, Order::STATUSES, true)) {
            return;
        }

        global $wpdb;

        $table = Schema::ordersTable();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $table,
            ['status' => $status, 'updated_at' => current_time('mysql')],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * Unbezahlte Bestellungen stornieren, die älter als $minutes sind (Reservierung
     * abgelaufen, keine Zahlung). Cutoff über current_datetime() = dieselbe Zeitbasis
     * wie created_at (WP-lokal), timezone-sicher. Gibt die Zahl der stornierten zurück.
     */
    public function cancelAbandonedPending(int $minutes): int
    {
        global $wpdb;
        $table = Schema::ordersTable();
        $cutoff = current_datetime()->modify('-' . max(1, $minutes) . ' minutes')->format('Y-m-d H:i:s');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, updated_at = %s WHERE status = %s AND created_at < %s",
            Order::STATUS_CANCELLED,
            current_time('mysql'),
            Order::STATUS_PENDING,
            $cutoff
        ));
    }

    /**
     * @return array<int, Order>
     */
    public function recent(int $limit = 50): array
    {
        global $wpdb;

        $table = Schema::ordersTable();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max(1, $limit)),
            ARRAY_A
        );

        return array_map([Order::class, 'fromRow'], is_array($rows) ? $rows : []);
    }

    /**
     * Anzahl Bestellungen mit einem Status (z.B. 'paid' = bezahlt, wartet auf Versand).
     */
    public function countByStatus(string $status): int
    {
        global $wpdb;

        $table = Schema::ordersTable();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", $status));
    }

    /**
     * Umsatz (Summe der Gesamtbeträge in Cent) tatsächlich bezahlter Bestellungen der
     * letzten N Tage. Bezahlt = Status paid oder shipped, gemessen an paid_at.
     * Datumsgrenze rechnet MySQL (umgeht die WordPress-Zeitzonen-Falle).
     */
    public function revenueLastDays(int $days): int
    {
        global $wpdb;

        $table = Schema::ordersTable();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(total_cents), 0) FROM {$table} WHERE paid_at >= (NOW() - INTERVAL %d DAY) AND status IN ('paid', 'shipped')",
            max(1, $days)
        ));
    }

    /**
     * Anzahl bezahlter Bestellungen der letzten N Tage (paid_at).
     */
    public function paidCountLastDays(int $days): int
    {
        global $wpdb;

        $table = Schema::ordersTable();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE paid_at >= (NOW() - INTERVAL %d DAY) AND status IN ('paid', 'shipped')",
            max(1, $days)
        ));
    }

    /**
     * Anzahl bezahlter Bestellungen im gleich langen Fenster DAVOR (Tag -2N bis -N),
     * für den Trend-Vergleich der aktuellen N Tage gegen die N Tage davor.
     */
    public function paidCountPreviousDays(int $days): int
    {
        global $wpdb;

        $days = max(1, $days);
        $table = Schema::ordersTable();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE paid_at >= (NOW() - INTERVAL %d DAY) AND paid_at < (NOW() - INTERVAL %d DAY)
             AND status IN ('paid', 'shipped')",
            $days * 2,
            $days
        ));
    }

    /**
     * Die am längsten auf Versand wartenden Bestellungen (Status bezahlt), älteste
     * zuerst. Fürs Dashboard: was ist als Nächstes zu verschicken.
     *
     * @return array<int, array{id:int, order_number:string, paid_at:string}>
     */
    public function oldestAwaitingShipment(int $limit): array
    {
        global $wpdb;

        $table = Schema::ordersTable();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, order_number, paid_at FROM {$table} WHERE status = %s AND paid_at IS NOT NULL ORDER BY paid_at ASC LIMIT %d",
            Order::STATUS_PAID,
            max(1, $limit)
        ), ARRAY_A);

        return is_array($rows) ? array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'order_number' => (string) $r['order_number'],
            'paid_at' => (string) $r['paid_at'],
        ], $rows) : [];
    }

    /**
     * Umsatz je Produkt aus den Positions-Snapshots bezahlter Bestellungen der letzten
     * N Tage, absteigend. Fürs "was macht den Umsatz aus"-Diagramm. Aggregiert in PHP,
     * weil die Positionen als JSON in der Zeile liegen (kleiner Katalog, kleine Menge).
     *
     * @return array<string, int> Produkttitel => Umsatz in Cent (absteigend)
     */
    public function revenueByProduct(int $days, int $limit): array
    {
        global $wpdb;

        $table = Schema::ordersTable();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT items FROM {$table} WHERE paid_at >= (NOW() - INTERVAL %d DAY) AND status IN ('paid', 'shipped')",
            max(1, $days)
        ));

        $revenue = [];
        foreach ($rows as $json) {
            $items = json_decode((string) $json, true);
            if (! is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                $title = (string) ($item['title'] ?? '');
                if ($title === '') {
                    continue;
                }
                $revenue[$title] = ($revenue[$title] ?? 0) + (int) ($item['line_total_cents'] ?? 0);
            }
        }

        arsort($revenue);

        return array_slice($revenue, 0, max(1, $limit), true);
    }

    /**
     * Anzahl bezahlter Bestellungen je Zeit-Eimer (Tag oder Monat) im Fenster
     * [$from, $to). Nur nicht-leere Eimer; die leeren füllt die Anzeige. $from/$to sind
     * WP-lokale Datumsstrings (gleiche Basis wie created_at), damit die Gruppierung
     * zeitzonen-konsistent ist.
     *
     * @return array<string, int> Eimer (Y-m-d bzw. Y-m) => Anzahl
     */
    public function paidCountSeries(string $from, string $to, string $granularity): array
    {
        global $wpdb;

        $table = Schema::ordersTable();
        $format = $granularity === 'month' ? '%Y-%m' : '%Y-%m-%d';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(created_at, %s) AS bucket, COUNT(*) AS c FROM {$table}
             WHERE created_at >= %s AND created_at < %s AND status IN ('paid', 'shipped')
             GROUP BY bucket",
            $format,
            $from,
            $to
        ), ARRAY_A);

        $map = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $map[(string) $r['bucket']] = (int) $r['c'];
        }

        return $map;
    }

    /**
     * Gemeinsamer Einzel-Fetch mit prepared WHERE-Klausel.
     */
    private function fetch(string $where, int|string $value): ?Order
    {
        global $wpdb;

        $table = Schema::ordersTable();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE {$where} LIMIT 1", $value),
            ARRAY_A
        );

        return is_array($row) ? Order::fromRow($row) : null;
    }
}
