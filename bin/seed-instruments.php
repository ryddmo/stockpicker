<?php

declare(strict_types=1);

/**
 * Story 1.2 seed step — loads the hardcoded development universe
 * (Stockpicker\Store\InstrumentSeeder) into the `instrument` table.
 *
 * Run over SSH after `vendor/bin/phinx migrate`:  php bin/seed-instruments.php
 * Idempotent: a second run inserts nothing and overwrites no resolved id.
 */

use Stockpicker\Store\Database;
use Stockpicker\Store\InstrumentRepository;
use Stockpicker\Store\InstrumentSeeder;

$services = require __DIR__ . '/../bootstrap.php';

try {
    $repo = new InstrumentRepository(Database::connect($services['config']));
    $result = InstrumentSeeder::seed($repo);

    printf("%d inserted, %d unchanged\n", $result['inserted'], $result['unchanged']);
} catch (\Throwable $e) {
    $services['logger']->error('seed-instruments failed', [
        'exception' => $e::class,
        'message' => $e->getMessage(),
    ]);
    fwrite(STDERR, 'seed-instruments failed: ' . $e->getMessage() . "\n");
    exit(1);
}
