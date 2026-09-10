<?php

declare(strict_types=1);

/**
 * Story 2.1 live verification — call the real Börsdata API once and print the
 * universe the BorsdataAdapter builds:  php bin/show-universe.php
 *
 * Thin wrapper (like bin/resolve-ids.php): bootstrap, build the Guzzle client
 * and the adapter with the key from config.php, run listUniverse(), then print
 * everything the spec's Implementation Notes tell the operator to record — the
 * real /v1/markets id->name->label map, the instrument type ids seen on the
 * target markets (kept vs excluded), the per-list counts, and the dropped-row
 * tally. Those all come from the adapter's `borsdata: universe built` info
 * record, captured here via a TestHandler. Read-only — no database, no writes.
 * `catch \Throwable` -> log + exit(1).
 */

use GuzzleHttp\Client;
use Monolog\Handler\TestHandler;
use Stockpicker\Adapter\BorsdataAdapter;
use Stockpicker\Adapter\UniverseEntry;

try {
    $services = require __DIR__ . '/../bootstrap.php';
    $logger = $services['logger'];

    $capture = new TestHandler();
    $logger->pushHandler($capture);

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

    $built = null;
    foreach ($capture->getRecords() as $record) {
        if ($record->message === 'borsdata: universe built') {
            $built = $record->context;
        }
    }

    if ($built !== null) {
        echo "\n/v1/markets correlation (marketId => label):\n";
        foreach (($built['markets'] ?? []) as $marketId => $label) {
            printf("  %-8s => %s\n", $marketId, $label);
        }
        printf("\nkept instrument types:      %s\n", json_encode($built['kept_instrument_types'] ?? []));
        printf("types seen on target mkts:  %s\n", json_encode($built['types_on_target_markets'] ?? []));
        printf("dropped rows:               %s\n", json_encode($built['dropped'] ?? []));
        echo "\nPin BorsdataAdapter::labelForMarketName() / EQUITY_TYPE_IDS against the\n";
        echo "marketId->label map and the type ids above, then record them in the\n";
        echo "spec's Implementation Notes and re-capture the test fixtures.\n";
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
