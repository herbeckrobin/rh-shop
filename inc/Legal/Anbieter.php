<?php

declare(strict_types=1);

namespace RhShop\Legal;

/**
 * Anbieter-Kontaktdaten für die Pflicht-Rechtstexte (Muster-Widerrufsformular).
 *
 * rh-shop hängt bewusst NICHT hart an rh-seo (Module sind unabhängig). Quelle:
 * Name und E-Mail kommen aus den WP-Stammdaten (blogname, admin_email), die
 * Anschrift aus einer eigenen Shop-Einstellung. Der Filter
 * `rh-blueprint/shop/anbieter` erlaubt es einer Suite-Integration (z.B. rh-seo mit
 * gepflegten Stammdaten) oder dem Theme, alles in EINER Quelle zu überschreiben,
 * damit der Betreiber die Adresse nicht doppelt tippt.
 */
final class Anbieter
{
    public const SETTING_ADDRESS = 'anbieter_adresse';

    /**
     * @return array{name: string, address: string, email: string}
     */
    public static function resolve(): array
    {
        $default = [
            'name' => (string) get_bloginfo('name'),
            'address' => (string) rhbp_setting('shop', self::SETTING_ADDRESS, ''),
            'email' => (string) get_option('admin_email'),
        ];

        $data = apply_filters('rh-blueprint/shop/anbieter', $default);

        return [
            'name' => isset($data['name']) ? (string) $data['name'] : $default['name'],
            'address' => isset($data['address']) ? (string) $data['address'] : $default['address'],
            'email' => isset($data['email']) ? (string) $data['email'] : $default['email'],
        ];
    }

    /**
     * Anbieter-Block als ein-/mehrzeiliger Text (Name, Anschrift, E-Mail). Fehlt die
     * Anschrift, bleibt der amtliche Ausfüllhinweis stehen, damit erkennbar ist, dass
     * noch etwas fehlt (statt still eine unvollständige Adresse auszugeben).
     */
    public static function block(): string
    {
        $a = self::resolve();

        $lines = array_filter([
            trim($a['name']),
            trim($a['address']),
            trim($a['email']),
        ], static fn (string $l): bool => $l !== '');

        if (trim($a['address']) === '') {
            $lines[] = __('[Anschrift des Unternehmers ergänzen]', 'rh-shop');
        }

        return implode("\n", $lines);
    }
}
