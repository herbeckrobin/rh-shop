<?php

/**
 * Prüft die einmalige Übernahme der Mail-Einstellungen in den Core.
 *
 * Der Schaden bei einem Fehler ist unsichtbar und teuer: ein gepflegter
 * Betreff wäre weg, oder eine abgeschaltete Mail ginge wieder raus. Beides
 * merkt niemand beim Klicken, sondern erst beim ersten Kunden.
 *
 * Läuft ohne WordPress und ohne Datenbank: Optionen liegen in einem Array.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

$GLOBALS['__options'] = [];
$GLOBALS['__settings'] = [];
$GLOBALS['__autoload'] = [];

function get_option(string $name, mixed $default = false): mixed
{
    return $GLOBALS['__options'][$name] ?? $default;
}

function update_option(string $name, mixed $wert, bool $autoload = true): bool
{
    // WordPress kehrt bei unverändertem Wert zurück, BEVOR es die
    // Autoload-Spalte anfasst. Der Stub bildet das nach, sonst wäre der
    // Selbstheilungs-Pfad unten grün, ohne dass er in echt etwas bewirkt.
    if (array_key_exists($name, $GLOBALS['__options']) && $GLOBALS['__options'][$name] === $wert) {
        return false;
    }

    $GLOBALS['__options'][$name] = $wert;
    $GLOBALS['__autoload'][$name] = $autoload;

    return true;
}

function rhbp_setting(string $gruppe, string $feld, mixed $default = null): mixed
{
    return $GLOBALS['__settings'][$gruppe][$feld] ?? $default;
}

function sanitize_email(string $wert): string
{
    return trim($wert);
}

function sanitize_text_field(string $wert): string
{
    return trim(strip_tags($wert));
}

function sanitize_textarea_field(string $wert): string
{
    return trim(strip_tags($wert));
}

function __(string $text, string $domain = ''): string
{
    return $text;
}

require_once __DIR__ . '/../vendor/rh/blueprint-core/autoload-src.php';

// Die Migration liest die Einstellungsgruppe des Shops. Die echte Klasse
// zieht halb Stripe nach, hier reicht die Konstante.
if (! class_exists(\RhShop\Stripe\Config::class)) {
    eval('namespace RhShop\\Stripe; class Config { public const GROUP = \'shop\'; }');
}

require_once __DIR__ . '/../inc/Mail/MailType.php';
require_once __DIR__ . '/../inc/Mail/MailRegistry.php';
require_once __DIR__ . '/../inc/Mail/MailSettings.php';
require_once __DIR__ . '/../inc/Mail/SettingsMigration.php';

use RhBlueprint\Core\Mail\MailSettings as CoreMailSettings;
use RhShop\Mail\MailRegistry;
use RhShop\Mail\SettingsMigration;

$fehler = 0;

function pruefe(bool $bedingung, string $name, string $detail = ''): void
{
    global $fehler;

    if ($bedingung) {
        echo "  ok   $name\n";

        return;
    }

    echo "  FEHL $name" . ($detail !== '' ? ": $detail" : '') . "\n";
    $fehler++;
}

function zuruecksetzen(): void
{
    $GLOBALS['__options'] = [];
    $GLOBALS['__settings'] = [];
    $GLOBALS['__autoload'] = [];
    CoreMailSettings::flushCache();
}

// --- Der übliche Fall: einiges gepflegt, vieles nicht ------------------------

zuruecksetzen();

$GLOBALS['__settings']['shop'] = [
    // Eine abgeschaltete Mail. Muss abgeschaltet bleiben.
    'mail_order_shipped_enabled' => false,
    // Ein gepflegter Betreff.
    'mail_order_shipped_subject' => 'Unterwegs: {bestellnummer}',
    // Ein Zusatztext bei einer anderen Mail.
    'mail_order_admin_notify_note' => 'Bitte im Lager melden.',
];

SettingsMigration::run();

pruefe(
    CoreMailSettings::for('shop.order_shipped')['enabled'] === false,
    'eine abgeschaltete Mail bleibt abgeschaltet'
);
pruefe(
    CoreMailSettings::subject('shop.order_shipped') === 'Unterwegs: {bestellnummer}',
    'der gepflegte Betreff kommt an',
    CoreMailSettings::subject('shop.order_shipped')
);
pruefe(
    CoreMailSettings::note('shop.order_admin_notify') === 'Bitte im Lager melden.',
    'der Zusatztext kommt an'
);

// Der Fall, an dem save() beinahe alles kaputtgemacht hätte: wer nur einen
// Zusatztext gepflegt hat, darf dadurch nicht seine Mail verlieren.
pruefe(
    CoreMailSettings::enabled('shop.order_admin_notify'),
    'wer nur einen Zusatztext gepflegt hat, verliert die Mail nicht'
);

// Nie angefasste Mails bekommen keinen Eintrag.
pruefe(
    CoreMailSettings::for('shop.order_cancelled') === [],
    'eine nie angefasste Mail bekommt keine Festlegung'
);
pruefe(
    CoreMailSettings::enabled('shop.order_cancelled'),
    'und geht weiter raus'
);

// --- Zweimal laufen darf nichts ändern ---------------------------------------

$standNachher = $GLOBALS['__options']['rhbp_mail_kinds'];
$GLOBALS['__settings']['shop']['mail_order_shipped_subject'] = 'Inzwischen anders';

SettingsMigration::run();

pruefe(
    $GLOBALS['__options']['rhbp_mail_kinds'] === $standNachher,
    'ein zweiter Lauf ändert nichts mehr'
);

// --- Was schon im Core steht, gewinnt ---------------------------------------

zuruecksetzen();

$GLOBALS['__settings']['shop'] = ['mail_order_shipped_subject' => 'Alt'];
CoreMailSettings::save('shop.order_shipped', ['enabled' => true, 'subject' => 'Neu, im Core gepflegt']);

SettingsMigration::run();

pruefe(
    CoreMailSettings::subject('shop.order_shipped') === 'Neu, im Core gepflegt',
    'ein im Core gepflegter Wert wird nicht überschrieben'
);

// --- Pflichtmails ------------------------------------------------------------

zuruecksetzen();

// Jemand hat in der alten Oberfläche eine Pflichtmail abgeschaltet. Das darf
// nicht übernommen werden: eine Bestellbestätigung ist gesetzlich fällig.
$GLOBALS['__settings']['shop'] = [
    'mail_order_confirmation_enabled' => false,
    'mail_order_confirmation_subject' => 'Danke für {bestellnummer}',
];

SettingsMigration::run();

pruefe(
    CoreMailSettings::subject('shop.order_confirmation') === 'Danke für {bestellnummer}',
    'der Betreff einer Pflichtmail wird übernommen'
);

// Ohne angemeldete Mail-Arten kennt der Core das Pflicht-Kennzeichen nicht,
// deshalb hier direkt der gespeicherte Wert.
pruefe(
    (CoreMailSettings::for('shop.order_confirmation')['enabled'] ?? null) !== false,
    'ein abgeschalteter Schalter einer Pflichtmail wird nicht übernommen'
);

// --- Nichts gepflegt ---------------------------------------------------------

zuruecksetzen();

SettingsMigration::run();

pruefe(
    ($GLOBALS['__options']['rhbp_mail_kinds'] ?? []) === [],
    'ohne gepflegte Werte entsteht kein Eintrag'
);
pruefe(
    ($GLOBALS['__options']['rhshop_mail_settings_migrated'] ?? '') !== '',
    'die Übernahme merkt sich trotzdem, dass sie gelaufen ist'
);
pruefe(
    ($GLOBALS['__autoload']['rhshop_mail_settings_migrated'] ?? false) === true,
    'und legt die Marke so ab, dass sie keine eigene Abfrage kostet'
);

// --- Altinstallation: übernommen, aber unter dem alten Markenwert ------------
//
// Hier stand die Marke auf '1' und autoload=off. Das kostete auf jedem Aufruf
// eine eigene Abfrage, Frontend eingeschlossen. Der Lauf darf die Übernahme
// NICHT wiederholen (sonst kämen abgeschaltete Mails zurück), sondern nur die
// Marke geradeziehen.

zuruecksetzen();

$GLOBALS['__options']['rhshop_mail_settings_migrated'] = '1';
$GLOBALS['__autoload']['rhshop_mail_settings_migrated'] = false;

// Etwas, das eine erneute Übernahme sofort verraten würde.
$GLOBALS['__settings']['shop'] = ['mail_order_shipped_subject' => 'Darf nicht übernommen werden'];

SettingsMigration::run();

pruefe(
    ($GLOBALS['__options']['rhbp_mail_kinds'] ?? []) === [],
    'eine Altinstallation übernimmt nicht ein zweites Mal'
);
pruefe(
    $GLOBALS['__autoload']['rhshop_mail_settings_migrated'] === true,
    'aber die Marke wird geradegezogen'
);

// Und beim nächsten Aufruf ist Ruhe.
$GLOBALS['__autoload']['rhshop_mail_settings_migrated'] = 'unberuehrt';

SettingsMigration::run();

pruefe(
    $GLOBALS['__autoload']['rhshop_mail_settings_migrated'] === 'unberuehrt',
    'danach fasst kein weiterer Lauf die Marke noch an'
);

// --- Leere Zeichenketten zählen nicht als Pflege -----------------------------

zuruecksetzen();

$GLOBALS['__settings']['shop'] = [
    'mail_order_shipped_subject' => '   ',
    'mail_order_shipped_note' => '',
];

SettingsMigration::run();

pruefe(
    CoreMailSettings::for('shop.order_shipped') === [],
    'ein leerer Betreff ist keine Pflege und erzeugt keinen Eintrag'
);

echo "\n";

if ($fehler > 0) {
    echo "$fehler Fehler.\n";
    exit(1);
}

echo "Alles gruen.\n";
