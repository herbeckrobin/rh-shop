<?php

declare(strict_types=1);

use RhShop\Shipping\Carrier;
use RhShop\Shipping\ShippingMethod;

/**
 * Versand-Logik: Carrier-Registry (Tracking-Links) und Versandmethoden-Bepreisung.
 * Reine Pfade ohne WordPress (die __()-gestützten Anzeigemethoden bleiben aussen vor).
 */

// Carrier::sanitize: gültiger Wert bleibt, alles andere fällt auf NONE.
eq(Carrier::sanitize('dhl'), 'dhl', 'gültiger Carrier bleibt');
eq(Carrier::sanitize('quatsch'), Carrier::NONE, 'ungültiger Carrier -> none');
eq(Carrier::sanitize(''), Carrier::NONE, 'leer -> none');

// Carrier::exists
eq(Carrier::exists('hermes'), true, 'hermes existiert');
eq(Carrier::exists(Carrier::NONE), true, 'none existiert');
eq(Carrier::exists('xyz'), false, 'xyz existiert nicht');

// Carrier::trackingUrl: Nummer in die Vorlage, none/leer -> leer, URL wird durchgereicht.
eq(
    Carrier::trackingUrl('dhl', '00340434161094015902'),
    'https://nolp.dhl.de/nextt-online-public/de/search?piececode=00340434161094015902',
    'DHL-Tracking-Link gebaut'
);
eq(Carrier::trackingUrl(Carrier::NONE, '123'), '', 'Abholung -> kein Link');
eq(Carrier::trackingUrl('dhl', ''), '', 'leere Nummer -> kein Link');
eq(Carrier::trackingUrl('dhl', '   '), '', 'nur Leerzeichen -> kein Link');
eq(Carrier::trackingUrl('hermes', 'https://example.com/t/5'), 'https://example.com/t/5', 'ganze URL wird durchgereicht');
eq(
    Carrier::trackingUrl('gls', 'AB CD'),
    'https://gls-group.com/DE/de/paketverfolgung?match=AB%20CD',
    'Nummer wird URL-kodiert'
);

// ShippingMethod::shippingFor (delegiert an die eine Rechenquelle Totals::shippingFor).
$m = new ShippingMethod('id', 'DHL', 'dhl', 490, null, '', true);
eq($m->shippingFor(0), 0, 'leerer Warenkorb kostet keinen Versand');
eq($m->shippingFor(2000), 490, 'Methodenpreis bei Warenwert');

$mFree = new ShippingMethod('id', 'DHL', 'dhl', 490, 5000, '', true);
eq($mFree->shippingFor(2000), 490, 'unter Gratis-Schwelle voller Preis');
eq($mFree->shippingFor(5000), 0, 'auf Gratis-Schwelle gratis');
eq($mFree->shippingFor(6000), 0, 'über Gratis-Schwelle gratis');

// fromArray / toArray: sauberer Roundtrip, Typen normalisiert.
$row = ['id' => 'm1', 'label' => 'Hermes', 'carrier' => 'hermes', 'price_cents' => 390, 'free_from_cents' => 4900, 'delivery_time' => '2-3 Tage', 'enabled' => false];
$parsed = ShippingMethod::fromArray($row);
eq($parsed->id, 'm1', 'fromArray Id');
eq($parsed->carrier, 'hermes', 'fromArray Carrier');
eq($parsed->priceCents, 390, 'fromArray Preis');
eq($parsed->freeFromCents, 4900, 'fromArray Gratis-ab');
eq($parsed->enabled, false, 'fromArray enabled false');
eq($parsed->toArray()['carrier'], 'hermes', 'toArray Roundtrip Carrier');

// fromArray härtet Fremdwerte: unbekannter Carrier -> none, leere Gratis-ab -> null.
$p2 = ShippingMethod::fromArray(['id' => 'x', 'label' => 'X', 'carrier' => 'bogus', 'price_cents' => 100, 'free_from_cents' => '', 'enabled' => true]);
eq($p2->carrier, Carrier::NONE, 'fromArray unbekannter Carrier -> none');
eq($p2->freeFromCents, null, 'fromArray leere Gratis-ab -> null');
