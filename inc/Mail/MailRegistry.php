<?php

declare(strict_types=1);

namespace RhShop\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Zentrales Verzeichnis aller Shop-Mails. Eine Quelle, aus der die Admin-UI die Reihen
 * baut und die Mailer ihren Standard-Betreff und ihre Meta-Daten ziehen. Neue Mail-Arten
 * kommen als weiterer Eintrag hier dazu, ohne dass die UI angefasst werden muss.
 *
 * IDs sind stabile Schlüssel (auch für die Settings-Keys mail_<id>_*), darum als
 * Konstanten. Labels/Betreffe nutzen __() und werden erst zur Laufzeit (nach init)
 * über all() erzeugt.
 */
final class MailRegistry
{
    public const ORDER_CONFIRMATION = 'order_confirmation';
    public const ORDER_ADMIN_NOTIFY = 'order_admin_notify';
    public const ORDER_SHIPPED = 'order_shipped';
    public const ORDER_CANCELLED = 'order_cancelled';
    public const ORDER_REFUNDED = 'order_refunded';
    public const PAYMENT_FAILED = 'payment_failed';
    public const WITHDRAWAL_CUSTOMER = 'withdrawal_customer';
    public const WITHDRAWAL_OPERATOR = 'withdrawal_operator';

    /**
     * Alle Mail-Arten, in Anzeige-Reihenfolge.
     *
     * @return array<string, MailType>
     */
    public static function all(): array
    {
        $types = [
            new MailType(
                self::ORDER_CONFIRMATION,
                __('Bestellbestätigung', 'rh-shop'),
                __('An den Kunden, sobald die Zahlung bestätigt ist. Enthält Positionen, Rechnung und die Widerrufsbelehrung.', 'rh-shop'),
                MailType::RECIPIENT_CUSTOMER,
                /* translators: Mail-Betreff, {bestellnummer} bleibt als Platzhalter */
                __('Deine Bestellung {bestellnummer}', 'rh-shop'),
                false,
                ['bestellnummer', 'name', 'summe', 'shop_name'],
            ),
            new MailType(
                self::ORDER_ADMIN_NOTIFY,
                __('Neue Bestellung (Betreiber)', 'rh-shop'),
                __('An dich, sobald eine Bestellung bezahlt ist. Mit Kundendaten und Positionen.', 'rh-shop'),
                MailType::RECIPIENT_OPERATOR,
                __('Neue Bestellung {bestellnummer}', 'rh-shop'),
                true,
                ['bestellnummer', 'name', 'summe', 'shop_name'],
            ),
            new MailType(
                self::ORDER_SHIPPED,
                __('Versandbestätigung', 'rh-shop'),
                __('An den Kunden, wenn du die Bestellung auf „versendet" setzt. Mit Anbieter und Sendungsnummer.', 'rh-shop'),
                MailType::RECIPIENT_CUSTOMER,
                __('Deine Bestellung {bestellnummer} ist unterwegs', 'rh-shop'),
                true,
                ['bestellnummer', 'name', 'sendungsnummer', 'shop_name'],
            ),
            new MailType(
                self::ORDER_CANCELLED,
                __('Stornierung', 'rh-shop'),
                __('An den Kunden, wenn du eine Bestellung auf „storniert" setzt.', 'rh-shop'),
                MailType::RECIPIENT_CUSTOMER,
                __('Deine Bestellung {bestellnummer} wurde storniert', 'rh-shop'),
                true,
                ['bestellnummer', 'name', 'shop_name'],
            ),
            new MailType(
                self::ORDER_REFUNDED,
                __('Rückerstattung', 'rh-shop'),
                __('An den Kunden, wenn du eine Bestellung auf „erstattet" setzt.', 'rh-shop'),
                MailType::RECIPIENT_CUSTOMER,
                __('Rückerstattung für deine Bestellung {bestellnummer}', 'rh-shop'),
                true,
                ['bestellnummer', 'name', 'summe', 'shop_name'],
            ),
            new MailType(
                self::PAYMENT_FAILED,
                __('Zahlung fehlgeschlagen', 'rh-shop'),
                __('An den Kunden, wenn eine Zahlung fehlschlägt (v.a. bei späteren Zahlungsarten wie Lastschrift).', 'rh-shop'),
                MailType::RECIPIENT_CUSTOMER,
                __('Zahlung für deine Bestellung {bestellnummer} fehlgeschlagen', 'rh-shop'),
                true,
                ['bestellnummer', 'name', 'shop_name'],
            ),
            new MailType(
                self::WITHDRAWAL_CUSTOMER,
                __('Widerruf-Eingangsbestätigung', 'rh-shop'),
                __('An den Kunden, wenn ein Widerruf eingeht (§356a). Bestätigt nur den Eingang.', 'rh-shop'),
                MailType::RECIPIENT_CUSTOMER,
                __('Eingangsbestätigung deines Widerrufs', 'rh-shop'),
                false,
                ['bestellnummer', 'name', 'shop_name'],
            ),
            new MailType(
                self::WITHDRAWAL_OPERATOR,
                __('Widerruf-Meldung (Betreiber)', 'rh-shop'),
                __('An dich, wenn ein Widerruf eingeht.', 'rh-shop'),
                MailType::RECIPIENT_OPERATOR,
                __('Neuer Widerruf eingegangen ({bestellnummer})', 'rh-shop'),
                true,
                ['bestellnummer', 'name', 'shop_name'],
            ),
        ];

        $map = [];
        foreach ($types as $type) {
            $map[$type->id] = $type;
        }

        return $map;
    }

    public static function get(string $id): ?MailType
    {
        return self::all()[$id] ?? null;
    }
}
