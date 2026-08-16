<?php

declare(strict_types=1);

namespace RhShop\Mail;

defined('ABSPATH') || exit;

use RhBlueprint\Core\Mail\MailSettings as CoreMailSettings;
use RhShop\Stripe\Config;

/**
 * Übernimmt die gepflegten Mail-Einstellungen in die des Core.
 *
 * Der Shop hat seine An/Aus-Schalter, Betreffe und Zusatztexte bisher in der
 * eigenen Option abgelegt (`mail_<id>_enabled` und so weiter). Der Core legt
 * sie in `rhbp_mail_kinds` ab. Ohne diesen Schritt wären nach der Umstellung
 * alle gepflegten Betreffe weg, und eine abgeschaltete Mail ginge plötzlich
 * wieder raus.
 *
 * Läuft genau einmal und merkt sich das. Vorhandene Werte auf der Core-Seite
 * werden nicht überschrieben: wer dort schon etwas eingestellt hat, hat die
 * neuere Entscheidung getroffen.
 */
final class SettingsMigration
{
    private const DONE_OPTION = 'rhshop_mail_settings_migrated';

    public static function run(): void
    {
        if (get_option(self::DONE_OPTION) === '1') {
            return;
        }

        if (! class_exists(CoreMailSettings::class)) {
            return;
        }

        $uebernommen = 0;

        foreach (MailRegistry::all() as $type) {
            $kindId = MailRegistry::kindId($type->id);
            $vorhanden = CoreMailSettings::for($kindId);

            $alt = [];

            // Nur was wirklich gepflegt wurde. Ein nie angefasster Schalter
            // soll auf der Core-Seite keine Festlegung erzeugen.
            $schalter = rhbp_setting(Config::GROUP, MailSettings::enabledKey($type->id), null);

            if ($schalter !== null && $type->lockable) {
                $alt['enabled'] = (bool) $schalter;
            }

            $betreff = trim((string) rhbp_setting(Config::GROUP, MailSettings::subjectKey($type->id), ''));

            if ($betreff !== '') {
                $alt['subject'] = $betreff;
            }

            $zusatz = trim((string) rhbp_setting(Config::GROUP, MailSettings::noteKey($type->id), ''));

            if ($zusatz !== '') {
                $alt['note'] = $zusatz;
            }

            if ($alt === []) {
                continue;
            }

            // Was auf der Core-Seite schon steht, gewinnt.
            $neu = array_merge($alt, $vorhanden);

            // save() schreibt jedes Feld, auch die nicht übergebenen: ein
            // fehlendes `enabled` würde als "aus" gespeichert und eine aktive
            // Mail stillegen. Deshalb hier immer einen Wert setzen.
            if (! array_key_exists('enabled', $neu)) {
                $neu['enabled'] = true;
            }

            CoreMailSettings::save($kindId, $neu);
            $uebernommen++;
        }

        update_option(self::DONE_OPTION, '1', false);

        if ($uebernommen > 0 && function_exists('error_log')) {
            error_log(sprintf('[rh-shop] %d Mail-Einstellungen in den Core übernommen.', $uebernommen));
        }
    }
}
