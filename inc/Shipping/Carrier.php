<?php

declare(strict_types=1);

namespace RhShop\Shipping;

defined( 'ABSPATH' ) || exit;

/**
 * Registry der Versand-Anbieter (Carrier). Feste, vertrauenswürdige Konstanten,
 * kein User-Input. Jeder Carrier bringt eine Tracking-URL-Vorlage mit (für den
 * Kunden-Link in der Versandmail) und optional einen Portal-Link (wo der Betreiber
 * ohne API-Anbindung sein Label erstellt).
 *
 * Die Tracking-Vorlagen sind die offiziellen Sendungsverfolgungs-Endpoints der
 * Carrier, Platzhalter {code} wird durch die Sendungsnummer ersetzt. Der Kunde
 * bekommt so einen direkten, anbieter-korrekten Link statt einer nackten Nummer.
 *
 * Phase 2 (API-Label über einen Broker wie shipcloud) dockt hier an, ohne dass
 * diese Struktur umgebaut werden muss.
 */
final class Carrier
{
    /** Abholung / kein Versand: kein Carrier, kein Tracking. */
    public const NONE = 'none';

    /**
     * @var array<string, array{label:string, tracking:string, portal:string}>
     */
    private const CARRIERS = [
        'dhl' => [
            'label' => 'DHL',
            'tracking' => 'https://nolp.dhl.de/nextt-online-public/de/search?piececode={code}',
            'portal' => 'https://www.dhl.de/de/geschaeftskunden.html',
        ],
        'hermes' => [
            'label' => 'Hermes',
            'tracking' => 'https://www.myhermes.de/empfangen/sendungsverfolgung/sendungsinformation/#{code}',
            'portal' => 'https://www.myhermes.de/',
        ],
        'dpd' => [
            'label' => 'DPD',
            'tracking' => 'https://tracking.dpd.de/status/de_DE/parcel/{code}',
            'portal' => 'https://www.dpd.com/de/de/',
        ],
        'gls' => [
            'label' => 'GLS',
            'tracking' => 'https://gls-group.com/DE/de/paketverfolgung?match={code}',
            'portal' => 'https://gls-one.de/',
        ],
        'ups' => [
            'label' => 'UPS',
            'tracking' => 'https://www.ups.com/track?tracknum={code}',
            'portal' => 'https://www.ups.com/de/de/shipping/',
        ],
    ];

    /**
     * Alle wählbaren Carrier fürs Admin-Dropdown, inklusive "Abholung/kein Versand".
     * id => Anzeigename.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $out = [self::NONE => __('Abholung / kein Versand', 'rh-shop')];
        foreach (self::CARRIERS as $id => $data) {
            $out[$id] = $data['label'];
        }

        return $out;
    }

    public static function exists(string $id): bool
    {
        return $id === self::NONE || isset(self::CARRIERS[$id]);
    }

    /** Ein gültiger Carrier-Id, sonst NONE. */
    public static function sanitize(string $id): string
    {
        return self::exists($id) ? $id : self::NONE;
    }

    public static function label(string $id): string
    {
        if ($id === self::NONE) {
            return __('Abholung / kein Versand', 'rh-shop');
        }

        return self::CARRIERS[$id]['label'] ?? $id;
    }

    /**
     * Direkter Sendungsverfolgungs-Link für eine Nummer. Leer, wenn kein Carrier,
     * keine Nummer oder keine Vorlage vorhanden. Gibt der Betreiber statt einer
     * Nummer eine komplette URL ein, wird die unverändert durchgereicht.
     */
    public static function trackingUrl(string $id, string $number): string
    {
        $number = trim($number);
        if ($number === '' || $id === self::NONE) {
            return '';
        }

        if (filter_var($number, FILTER_VALIDATE_URL)) {
            return $number;
        }

        $template = self::CARRIERS[$id]['tracking'] ?? '';
        if ($template === '') {
            return '';
        }

        return str_replace('{code}', rawurlencode($number), $template);
    }

    /**
     * Portal-Link, wo der Betreiber ohne API-Anbindung sein Versandlabel erstellt.
     * Leer bei Abholung/kein Versand.
     */
    public static function portalUrl(string $id): string
    {
        return self::CARRIERS[$id]['portal'] ?? '';
    }
}
