<?php

declare(strict_types=1);

use RhShop\Mail\Placeholders;

/**
 * Platzhalter-Ersetzung im Mail-Betreff (WP-frei). Der HTML-Kontext nutzt esc_html und
 * wird über die DDEV-Integrationstests geprüft.
 */

eq(Placeholders::inSubject('Bestellung {bestellnummer}', ['bestellnummer' => 'RH-000001']), 'Bestellung RH-000001', 'ein Platzhalter ersetzt');
eq(Placeholders::inSubject('{a} und {b}', ['a' => 'X', 'b' => 'Y']), 'X und Y', 'mehrere Platzhalter ersetzt');
eq(Placeholders::inSubject('ohne', []), 'ohne', 'Text ohne Platzhalter bleibt');
eq(Placeholders::inSubject('{unbekannt}', ['a' => 'X']), '{unbekannt}', 'unbekannter Platzhalter bleibt stehen');
eq(Placeholders::inSubject('{n} von {n}', ['n' => '3']), '3 von 3', 'gleicher Platzhalter mehrfach');
