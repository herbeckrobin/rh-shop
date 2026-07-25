<?php

declare(strict_types=1);

namespace RhShop\Orders;

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

    public function findBySessionId(string $sessionId): ?Order
    {
        if ($sessionId === '') {
            return null;
        }

        return $this->fetch('stripe_session_id = %s', $sessionId);
    }

    public function attachSession(int $id, string $sessionId): void
    {
        global $wpdb;

        $table = Schema::ordersTable();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $table,
            ['stripe_session_id' => $sessionId, 'updated_at' => current_time('mysql')],
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
    public function markPaid(string $sessionId, string $paymentIntentId, array $buyer = []): ?Order
    {
        global $wpdb;

        $order = $this->findBySessionId($sessionId);
        if ($order === null || $order->isPaid()) {
            return null;
        }

        $now = current_time('mysql');
        $data = [
            'status' => Order::STATUS_PAID,
            'stripe_payment_intent_id' => $paymentIntentId,
            'paid_at' => $now,
            'updated_at' => $now,
        ];
        $formats = ['%s', '%s', '%s', '%s'];

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
