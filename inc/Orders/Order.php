<?php

declare(strict_types=1);

namespace RhShop\Orders;

use RhShop\Support\Money;

/**
 * Eine Bestellung. Immutable Value-Object, gelesen aus der Tabelle.
 *
 * Die Positionen (`items`) sind ein Snapshot vom Kaufzeitpunkt, kein Live-Join auf
 * den Katalog: eine spätere Preis- oder Produktänderung darf eine bestehende
 * Bestellung nicht verändern.
 */
final class Order
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PAID,
        self::STATUS_SHIPPED,
        self::STATUS_CANCELLED,
        self::STATUS_REFUNDED,
    ];

    public const TAX_VAT = 'vat';
    public const TAX_KLEINUNTERNEHMER = 'kleinunternehmer';

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed>              $address
     */
    public function __construct(
        public readonly int $id,
        public readonly string $orderNumber,
        public readonly string $status,
        public readonly string $currency,
        public readonly string $email,
        public readonly string $customerName,
        public readonly array $address,
        public readonly array $items,
        public readonly int $subtotalCents,
        public readonly int $shippingCents,
        public readonly int $taxCents,
        public readonly int $totalCents,
        public readonly string $taxMode,
        public readonly string $stripeSessionId,
        public readonly string $stripePaymentIntentId,
        public readonly string $invoiceId,
        public readonly string $invoiceNumber,
        public readonly string $invoiceUrl,
        public readonly string $createdAt,
        public readonly ?string $paidAt,
    ) {
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID
            || $this->status === self::STATUS_SHIPPED;
    }

    public function formattedTotal(string $symbol = '€'): string
    {
        return Money::format($this->totalCents, $symbol);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $items = json_decode((string) ($row['items'] ?? '[]'), true);
        $address = json_decode((string) ($row['address'] ?? '') ?: '[]', true);

        return new self(
            id: (int) ($row['id'] ?? 0),
            orderNumber: (string) ($row['order_number'] ?? ''),
            status: (string) ($row['status'] ?? self::STATUS_PENDING),
            currency: (string) ($row['currency'] ?? 'eur'),
            email: (string) ($row['email'] ?? ''),
            customerName: (string) ($row['customer_name'] ?? ''),
            address: is_array($address) ? $address : [],
            items: is_array($items) ? $items : [],
            subtotalCents: (int) ($row['subtotal_cents'] ?? 0),
            shippingCents: (int) ($row['shipping_cents'] ?? 0),
            taxCents: (int) ($row['tax_cents'] ?? 0),
            totalCents: (int) ($row['total_cents'] ?? 0),
            taxMode: (string) ($row['tax_mode'] ?? self::TAX_VAT),
            stripeSessionId: (string) ($row['stripe_session_id'] ?? ''),
            stripePaymentIntentId: (string) ($row['stripe_payment_intent_id'] ?? ''),
            invoiceId: (string) ($row['invoice_id'] ?? ''),
            invoiceNumber: (string) ($row['invoice_number'] ?? ''),
            invoiceUrl: (string) ($row['invoice_url'] ?? ''),
            createdAt: (string) ($row['created_at'] ?? ''),
            paidAt: isset($row['paid_at']) && $row['paid_at'] !== null ? (string) $row['paid_at'] : null,
        );
    }
}
