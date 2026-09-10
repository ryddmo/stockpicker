<?php

declare(strict_types=1);

/**
 * Story 2.1 live verification — call the real Börsdata API once and print the
 * universe the BorsdataAdapter builds:  php bin/show-universe.php
 *
 * Thin wrapper (like bin/resolve-ids.php): bootstrap, build the shared Guzzle
 * client and the adapter with the key from config.php, run listUniverse(),
 * print a per-list count table and a small sample. Read-only — no database, no
 * writes. `catch \Throwable` -> log + exit(1).
 *
 * Feed the output back into the spec's Implementation Notes: the real
 * /v1/markets id+name for the four target markets, the instrument type ids kept
 * vs excluded, and the per-list counts.
 */

use GuzzleHttp\Client;
use Stockpicker\Adapter\BorsdataAdapter;
use Stockpicker\Adapter\UniverseEntry;

try {
    $services = require __DIR__ . '/../bootstrap.php';
    $logger = $services['logger'];

    $http = new Client(['timeout' => 30, 'connect_timeout' => 10]);
    $adapter = new BorsdataAdapter($http, $logger, $services['config']->borsdataApiKey());

    $universe = $adapter->listUniverse();

    $counts = [
        UniverseEntry::LIST_LC => 0,
        UniverseEntry::LIST_MC => 0,
        UniverseEntry::LIST_SC => 0,
        UniverseEntry::LIST_FIRST_NORTH => 0,
    ];
    foreach ($universe as $entry) {
        $counts[$entry->list] = ($counts[$entry->list] ?? 0) + 1;
    }

    printf("Börsdata universe: %d instruments\n\n", count($universe));
    foreach ($counts as $list => $count) {
        printf("  %-12s %5d\n", $list, $count);
    }

    echo "\nSample (first 10, ISIN-sorted):\n";
    foreach (array_slice($universe, 0, 10) as $entry) {
        printf("  %s  %-6s  %s\n", $entry->isin, $entry->list, $entry->name);
    }
} catch (\Throwable $e) {
    if (isset($services['logger'])) {
        $services['logger']->error('show-universe failed', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    } else {
        error_log('show-universe bootstrap failed: ' . $e->getMessage());
    }
    fwrite(STDERR, 'show-universe failed: ' . $e->getMessage() . "\n");
    exit(1);
}
