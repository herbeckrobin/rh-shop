<?php

declare(strict_types=1);

use RhShop\Catalog\GrundpreisUnit;

// isValid
eq(GrundpreisUnit::isValid('kg'), true, 'isValid kg');
eq(GrundpreisUnit::isValid('m2'), true, 'isValid m2');
eq(GrundpreisUnit::isValid('xx'), false, 'isValid unbekannt');
eq(GrundpreisUnit::isValid(''), false, 'isValid leer');

// basePriceCents: Preis je Basiseinheit
eq(GrundpreisUnit::basePriceCents(500.0, 890, 'g'), 1780, '890ct/500g -> 17,80/kg');
eq(GrundpreisUnit::basePriceCents(330.0, 1490, 'ml'), 4515, '1490ct/330ml -> 45,15/l');
eq(GrundpreisUnit::basePriceCents(1.0, 2500, 'kg'), 2500, '1kg identisch');
eq(GrundpreisUnit::basePriceCents(null, 1490, 'ml'), null, 'ohne Nennmenge null');
eq(GrundpreisUnit::basePriceCents(0.0, 1490, 'ml'), null, 'Nennmenge 0 null');
eq(GrundpreisUnit::basePriceCents(500.0, 0, 'g'), null, 'Preis 0 null');
eq(GrundpreisUnit::basePriceCents(500.0, 890, 'xx'), null, 'unbekannte Einheit null');

// baseLabel / displayLabel
eq(GrundpreisUnit::baseLabel('g'), 'kg', 'baseLabel g -> kg');
eq(GrundpreisUnit::baseLabel('ml'), 'l', 'baseLabel ml -> l');
eq(GrundpreisUnit::baseLabel('cm'), 'm', 'baseLabel cm -> m');
eq(GrundpreisUnit::baseLabel('m2'), 'm²', 'baseLabel m2 -> m²');
eq(GrundpreisUnit::baseLabel('xx'), '', 'baseLabel unbekannt leer');
eq(GrundpreisUnit::displayLabel('m2'), 'm²', 'displayLabel m2');
eq(GrundpreisUnit::displayLabel('kg'), 'kg', 'displayLabel kg');
