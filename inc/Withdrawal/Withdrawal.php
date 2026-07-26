<?php

declare(strict_types=1);

namespace RhShop\Withdrawal;

defined( 'ABSPATH' ) || exit;

/**
 * Ein Widerruf nach §356a BGB. Erfasst die drei Pflichtangaben (Name, Vertrags-/
 * Bestellidentifikation, Kontakt) plus den nachweispflichtigen Eingangszeitpunkt.
 * Immutable.
 */
final class Withdrawal
{
    public function __construct(
        public readonly int $id,
        public readonly int $orderId,
        public readonly string $orderNumber,
        public readonly string $customerName,
        public readonly string $email,
        public readonly string $reason,
        public readonly string $ip,
        public readonly string $receivedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) ($row['id'] ?? 0),
            orderId: (int) ($row['order_id'] ?? 0),
            orderNumber: (string) ($row['order_number'] ?? ''),
            customerName: (string) ($row['customer_name'] ?? ''),
            email: (string) ($row['email'] ?? ''),
            reason: (string) ($row['reason'] ?? ''),
            ip: (string) ($row['ip'] ?? ''),
            receivedAt: (string) ($row['received_at'] ?? ''),
        );
    }
}
