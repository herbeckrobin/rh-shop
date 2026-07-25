<?php

declare(strict_types=1);

/**
 * Standalone-Unit-Tests für die reinen Rechen-Pfade des Shops (Geld, Grundpreis,
 * Steuer). Bewusst ohne WordPress und ohne PHPUnit: die getesteten Methoden sind
 * pure Funktionen (Cent rein, Cent raus), die kein WP brauchen. Lauf: php tests/run.php
 */

$inc = dirname(__DIR__) . '/inc';

require $inc . '/Support/Money.php';
require $inc . '/Catalog/GrundpreisUnit.php';
require $inc . '/Orders/Order.php';
require $inc . '/Checkout/Totals.php';

$GLOBALS['__rhshop_tests'] = ['pass' => 0, 'fail' => 0, 'fails' => []];

function eq(mixed $actual, mixed $expected, string $msg): void
{
    if ($actual === $expected) {
        $GLOBALS['__rhshop_tests']['pass']++;
        return;
    }

    $GLOBALS['__rhshop_tests']['fail']++;
    $GLOBALS['__rhshop_tests']['fails'][] = sprintf(
        '%s (erwartet %s, war %s)',
        $msg,
        var_export($expected, true),
        var_export($actual, true)
    );
}
