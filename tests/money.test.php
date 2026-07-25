<?php

declare(strict_types=1);

use RhShop\Support\Money;

// toCents: Komma und Punkt, Leerzeichen, ungültige Eingaben.
eq(Money::toCents('24,90'), 2490, 'toCents Komma');
eq(Money::toCents('24.90'), 2490, 'toCents Punkt');
eq(Money::toCents('1000'), 100000, 'toCents ganze Zahl');
eq(Money::toCents(' 24,90 '), 2490, 'toCents mit Leerzeichen');
eq(Money::toCents('0'), 0, 'toCents null');
eq(Money::toCents(''), 0, 'toCents leer');
eq(Money::toCents('abc'), 0, 'toCents ungültig');
eq(Money::toCents('24,905'), 2491, 'toCents rundet kaufmännisch');

// format: deutsches Format mit Tausenderpunkt.
eq(Money::format(2490), '24,90 €', 'format klein');
eq(Money::format(100000), '1.000,00 €', 'format Tausender');
eq(Money::format(0), '0,00 €', 'format null');
eq(Money::format(2490, 'CHF'), '24,90 CHF', 'format andere Währung');

// toInput: Punkt-Notation fürs number-Feld.
eq(Money::toInput(2490), '24.90', 'toInput');
eq(Money::toInput(100000), '1000.00', 'toInput ohne Tausenderpunkt');
