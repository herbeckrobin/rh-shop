<?php

declare(strict_types=1);

namespace RhShop\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Beschreibt eine Mail-Art des Shops (Bestellbestätigung, Versandbestätigung, ...).
 * Immutable Value-Object aus der Registry. Trägt den Standard-Betreff und die Meta-
 * Daten, an denen die Admin-UI und die Mailer ansetzen: wer sie bekommt, ob sie
 * abschaltbar ist (rechtliche Pflicht-Mails sind es nicht) und welche Platzhalter im
 * Betreff und Zusatztext verfügbar sind.
 */
final class MailType
{
    public const RECIPIENT_CUSTOMER = 'customer';
    public const RECIPIENT_OPERATOR = 'operator';

    /**
     * @param array<int, string> $placeholders verfügbare {platzhalter} ohne Klammern
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $description,
        public readonly string $recipient,
        public readonly string $defaultSubject,
        public readonly bool $lockable,
        public readonly array $placeholders,
    ) {
    }

    public function isForCustomer(): bool
    {
        return $this->recipient === self::RECIPIENT_CUSTOMER;
    }
}
