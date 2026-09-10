<?php

declare(strict_types=1);

/**
 * Story 2.1 operator spot-check — run over SSH to eyeball the live Avanza
 * universe listing:  php bin/list-universe.php
 *
 * Thin wrapper (like bin/resolve-ids.php): build the real HTTP client and the
 * adapter, run listUniverse(), print per-list counts + a 10-row sample.
 * Writes nothing. Exits 0 unless the run itself blows up.
 */

use GuzzleHttp\Client;
use Stockpicker\Adapter\AvanzaUniverseAdapter;

$services = require __DIR__ . '/../bootstrap.php';
$logger = $services['logger'];

try {
    $http = new Client(['timeout' => 30, 'connect_timeout' => 10]);
    $entries = (new AvanzaUniverseAdapter($http, $logger))->listUniverse();

    $counts = [];
    foreach ($entries as $entry) {
        $counts[$entry->list] = ($counts[$entry->list] ?? 0) + 1;
    }

    printf("%d universe entries\n", count($entries));
    foreach ($counts as $list => $count) {
        printf("  %-12s %d\n", $list, $count);
    }

    echo "\nsample (first 10 by orderbookId):\n";
    foreach (array_slice($entries, 0, 10) as $entry) {
        printf("  %-10s %-6s %s\n", $entry->avanzaOrderbookId, $entry->list, $entry->name);
    }
} catch (\Throwable $e) {
    $logger->error('list-universe failed', [
        'exception' => $e::class,
        'message' => $e->getMessage(),
    ]);
    fwrite(STDERR, 'list-universe failed: ' . $e->getMessage() . "\n");
    exit(1);
}
