<?php

declare(strict_types=1);

namespace RhShop\Mail;

defined( 'ABSPATH' ) || exit;

use RhShop\Stripe\Config;

/**
 * Liest die Per-Mail-Konfiguration aus der Shop-Option. Pro Mail-Art drei präfixte
 * Flat-Keys (`mail_<id>_enabled` / `_subject` / `_note`) im selben Gruppen-Array,
 * dasselbe Muster wie die Anbieter in rh-tracking. So greift rhbp_setting weiter und
 * die Werte speichern sich mit dem übrigen Shop-Formular.
 *
 * Betreff leer = Standard-Betreff aus der Registry. Rechtliche Pflicht-Mails
 * (lockable=false) gehen immer raus, unabhängig vom An/Aus-Schalter.
 */
final class MailSettings
{
    public static function enabledKey(string $mailId): string
    {
        return 'mail_' . $mailId . '_enabled';
    }

    public static function subjectKey(string $mailId): string
    {
        return 'mail_' . $mailId . '_subject';
    }

    public static function noteKey(string $mailId): string
    {
        return 'mail_' . $mailId . '_note';
    }

    /**
     * Geht diese Mail raus? Pflicht-Mails (nicht abschaltbar) immer, sonst laut Schalter
     * (Default an).
     */
    public function enabled(MailType $type): bool
    {
        if (! $type->lockable) {
            return true;
        }

        return (bool) rhbp_setting(Config::GROUP, self::enabledKey($type->id), true);
    }

    /**
     * Der wirksame Betreff: eigener, wenn gepflegt, sonst der Registry-Default. Noch mit
     * Platzhaltern, die der Mailer einsetzt.
     */
    public function subjectTemplate(MailType $type): string
    {
        $custom = trim((string) rhbp_setting(Config::GROUP, self::subjectKey($type->id), ''));

        return $custom !== '' ? $custom : $type->defaultSubject;
    }

    /**
     * Optionaler Zusatztext des Betreibers für diese Mail (noch mit Platzhaltern).
     */
    public function noteTemplate(string $mailId): string
    {
        return trim((string) rhbp_setting(Config::GROUP, self::noteKey($mailId), ''));
    }
}
