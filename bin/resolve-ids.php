<?php

declare(strict_types=1);

/**
 * Story 1.7 interim source-id resolver — run over SSH after seed-instruments
 * and before a fetch run:  php bin/resolve-ids.php
 *
 * Thin wrapper around Stockpicker\Store\SourceIdResolver (like
 * bin/seed-instruments.php around InstrumentSeeder): build the real HTTP client
 * and the two adapters, run the resolver, print `resolved / skipped / failed`.
 * `resolved` / `failed` are per source attempt; `skipped` is per instrument
 * (every id already cached). Exits 0 unless the run itself blows up.
 */

use GuzzleHttp\Client;
use Stockpicker\Adapter\AvanzaAdapter;
use Stockpicker\Adapter\NordnetAdapter;
use Stockpicker\Store\Database;
use Stockpicker\Store\InstrumentRepository;
use Stockpicker\Store\SourceIdResolver;

$services = require __DIR__ . '/../bootstrap.php';
$logger = $services['logger'];

try {
    $repo = new InstrumentRepository(Database::connect($services['config']));

    $http = new Client(['timeout' => 20, 'connect_timeout' => 10]);
    $adapters = [
        'avanza' => new AvanzaAdapter($http, $logger),
        'nordnet' => new NordnetAdapter($http, $logger),
    ];

    $result = SourceIdResolver::resolve($repo, $adapters, $logger);

    printf("%d resolved, %d skipped, %d failed\n", $result['resolved'], $result['skipped'], $result['failed']);
} catch (\Throwable $e) {
    $logger->error('resolve-ids failed', [
        'exception' => $e::class,
        'message' => $e->getMessage(),
    ]);
    fwrite(STDERR, 'resolve-ids failed: ' . $e->getMessage() . "\n");
    exit(1);
}
