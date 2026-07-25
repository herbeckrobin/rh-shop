<?php

declare(strict_types=1);

use RhShop\Catalog\StockSummary;
use RhShop\Catalog\Variant;

/**
 * Bestands-Logik: die eine Wahrheit, an der Kauf-Box, Warenkorb, Karte und Checkout
 * die Verfügbarkeit ablesen. Pure Value-Objekte, kein WordPress.
 */

$make = static fn (?int $stock, string $o1 = '', string $o2 = ''): Variant
    => new Variant('id-' . $o1 . $o2, $o1, $o2, '', 1000, $stock);

// maxQty: null = unbegrenzt, sonst der Bestand (nie negativ).
eq($make(null)->maxQty(), null, 'maxQty unbegrenzt');
eq($make(7)->maxQty(), 7, 'maxQty = Bestand');
eq($make(0)->maxQty(), 0, 'maxQty 0 bei ausverkauft');

// isAvailable
eq($make(null)->isAvailable(), true, 'unbegrenzt verfügbar');
eq($make(3)->isAvailable(), true, 'Rest verfügbar');
eq($make(0)->isAvailable(), false, 'ausverkauft nicht verfügbar');

// isLowStock (Schwelle 5)
eq($make(null)->isLowStock(5), false, 'unbegrenzt nie knapp');
eq($make(0)->isLowStock(5), false, 'ausverkauft nicht knapp');
eq($make(3)->isLowStock(5), true, 'unter Schwelle knapp');
eq($make(5)->isLowStock(5), true, 'auf Schwelle knapp');
eq($make(6)->isLowStock(5), false, 'über Schwelle nicht knapp');
eq($make(3)->isLowStock(0), false, 'Schwelle 0 schaltet ab');

// StockSummary::fromVariants
eq(StockSummary::fromVariants([], 5)->soldOut, false, 'leer -> nicht ausverkauft');
eq(StockSummary::fromVariants([], 5)->anyLow, false, 'leer -> nichts knapp');

$allOut = StockSummary::fromVariants([$make(0, 'S'), $make(0, 'M')], 5);
eq($allOut->soldOut, true, 'alle 0 -> ausverkauft');
eq($allOut->anyLow, false, 'ausverkauft -> nicht knapp');

$someLow = StockSummary::fromVariants([$make(0, 'S'), $make(3, 'M'), $make(20, 'L')], 5);
eq($someLow->soldOut, false, 'eine verfügbar -> nicht ausverkauft');
eq($someLow->anyLow, true, 'eine knapp -> anyLow');
eq($someLow->lowest, 3, 'kleinster knapper Bestand');

$mixedLow = StockSummary::fromVariants([$make(2, 'S'), $make(4, 'M')], 5);
eq($mixedLow->lowest, 2, 'kleinster von mehreren knappen');

$noneLow = StockSummary::fromVariants([$make(null, 'S'), $make(50, 'M')], 5);
eq($noneLow->anyLow, false, 'nichts knapp');
eq($noneLow->lowest, null, 'kein knapper Bestand -> null');

// Ausverkaufte Variante darf den kleinsten knappen NICHT auf 0 ziehen.
$outIgnored = StockSummary::fromVariants([$make(0, 'S'), $make(4, 'M')], 5);
eq($outIgnored->lowest, 4, 'ausverkauft zählt nicht als knapp');
