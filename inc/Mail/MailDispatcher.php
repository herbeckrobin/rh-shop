<?php

declare(strict_types=1);

namespace RhShop\Mail;

defined( 'ABSPATH' ) || exit;

use RhShop\Stripe\Config;

/**
 * Baut eine Shop-Mail zusammen und verschickt sie über wp_mail (rh-smtp macht den
 * Transport). Eine Stelle für den ganzen Ablauf, den beide Mailer (Bestellung, Widerruf)
 * teilen: An/Aus prüfen, Betreff und Zusatztext aus der Konfiguration mit Platzhaltern
 * füllen, in den gemeinsamen Rahmen wrappen, Absender-Header setzen.
 */
final class MailDispatcher
{
    private readonly MailSettings $settings;

    public function __construct(private readonly Config $config)
    {
        $this->settings = new MailSettings();
    }

    /**
     * Verschickt die Mail, wenn ihre Art aktiv ist und ein Empfänger vorliegt.
     *
     * @param array<string, string> $values     Platzhalter-Werte
     * @param string                $legacyNote  Fallback-Zusatztext, falls für diese Mail
     *                                           noch kein eigener gepflegt ist (Migration).
     */
    public function send(?MailType $type, string $to, array $values, string $bodyHtml, string $legacyNote = ''): void
    {
        if ($type === null || $to === '' || ! $this->settings->enabled($type)) {
            return;
        }

        $subject = Placeholders::inSubject($this->settings->subjectTemplate($type), $values);

        $noteTemplate = $this->settings->noteTemplate($type->id);
        if ($noteTemplate === '' && $legacyNote !== '') {
            $noteTemplate = $legacyNote;
        }
        $note = Placeholders::inHtml($noteTemplate, $values);

        wp_mail($to, $subject, MailLayout::wrap($bodyHtml . $note, $this->config), $this->headers());
    }

    /**
     * Gemeinsame Mail-Header. Absender (From) nur, wenn der Betreiber eine eigene Adresse
     * gepflegt hat, sonst bleibt der WordPress-/rh-smtp-Default.
     *
     * @return array<int, string>
     */
    private function headers(): array
    {
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $fromAddress = $this->config->mailFromAddress();
        if ($fromAddress !== '') {
            $fromName = $this->config->mailFromName();
            $headers[] = 'From: ' . ($fromName !== '' ? sprintf('%s <%s>', $fromName, $fromAddress) : $fromAddress);
        }

        return $headers;
    }
}
