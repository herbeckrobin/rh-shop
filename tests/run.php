<?php

declare(strict_types=1);

/**
 * Test-Runner: lädt den Bootstrap und alle *.test.php, zählt Ergebnisse, Exit-Code
 * ungleich 0 bei Fehlern (CI-tauglich). Lauf: php tests/run.php
 */

require __DIR__ . '/bootstrap.php';

foreach (glob(__DIR__ . '/*.test.php') as $file) {
    require $file;
}

$result = $GLOBALS['__rhshop_tests'];

foreach ($result['fails'] as $message) {
    fwrite(STDERR, "FAIL: {$message}\n");
}

printf("\n%d bestanden, %d fehlgeschlagen\n", $result['pass'], $result['fail']);

exit($result['fail'] === 0 ? 0 : 1);
