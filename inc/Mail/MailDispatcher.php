<?php

declare(strict_types=1);

namespace RhShop\Mail;

defined( 'ABSPATH' ) || exit;

use RhBlueprint\Core\Mail\Mail;
use RhBlueprint\Core\Mail\MailMessage;
use RhShop\Stripe\Config;

/**
 * Verschickt eine Shop-Mail über den gemeinsamen Weg der Suite.
 *
 * Vorher lief das hier an allem vorbei: eigene Registry, eigene Einstellungen,
 * eigener Rahmen, eigener Aufruf von wp_mail. Das Ergebnis waren zwei
 * Mail-Optiken auf einer Website und Einstellungen an zwei Orten, von denen der
 * Betreiber nur einen findet.
 *
 * Was der Shop mitbringt, bleibt: Logo, Akzentfarbe und Anschrift hängen an
 * seiner Konfiguration und reisen über Haken in den gemeinsamen Rahmen. Was
 * überall gleich ist (An/Aus, Empfänger, Betreff mit Platzhaltern, Zusatztext,
 * Testmodus, Wellenbremse, Protokoll), macht jetzt der Core.
 */
final class MailDispatcher
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Verschickt die Mail, wenn ihre Art aktiv ist und ein Empfänger vorliegt.
     *
     * @param array<string, string> $values     Werte für die Platzhalter.
     * @param string                $legacyNote Zusatztext aus der alten Konfiguration,
     *                                          falls für diese Mail noch keiner gepflegt ist.
     */
    public function send(?MailType $type, string $to, array $values, string $bodyHtml, string $legacyNote = ''): void
    {
        if ($type === null || $to === '') {
            return;
        }

        $this->applyBranding();

        $nachricht = new MailMessage($type->label);
        $nachricht->kind(MailRegistry::kindId($type->id));
        $nachricht->placeholders($values);

        // Der Rumpf ist fertiges, escaptes HTML aus den Mailern. Er geht als
        // ein Block durch, statt ihn in Bausteine zu zerlegen: eine Rechnung
        // mit Positionstabelle ist kein Textabsatz.
        $nachricht->raw(['type' => 'html', 'html' => $bodyHtml]);

        // Der alte Zusatztext greift nur, solange für diese Mail keiner in den
        // E-Mail-Einstellungen steht. Der Core setzt seinen eigenen davor.
        $zusatz = $legacyNote !== ''
            ? Placeholders::inPlain($legacyNote, $values)
            : '';

        Mail::send($to, $type->label, $nachricht, $zusatz);
    }

    /**
     * Reicht Logo, Farbe und Anschrift des Shops in den gemeinsamen Rahmen.
     *
     * Einmal pro Anfrage, und nur solange der Shop verschickt: die Haken sind
     * für Mails an Endkunden gedacht, eine Sicherheitsmeldung soll weiter wie
     * eine Systemmeldung aussehen.
     */
    private function applyBranding(): void
    {
        static $gesetzt = false;

        if ($gesetzt) {
            return;
        }

        $gesetzt = true;

        $farbe = $this->config->mailLayoutAccent();
        $logo = $this->config->mailLayoutLogoUrl();
        $fuss = $this->config->mailLayoutFooter();

        if ($farbe !== '') {
            add_filter('rh-blueprint/mail/brand_accent', static fn (): string => $farbe);
        }

        if ($logo !== '') {
            add_filter('rh-blueprint/mail/brand_logo', static fn (): string => $logo);
        }

        if ($fuss !== '') {
            add_filter('rh-blueprint/mail/footer_note', static fn (): string => $fuss);
        }
    }
}
