<?php

declare(strict_types=1);

/**
 * Story 2.2 — run `UniverseSync` once over SSH:  php bin/universe-sync.php
 *
 * The recommended first-run bootstrap: it passes **no** wall-clock budget, so it
 * resolves every not-yet-known orderbookId's ISIN and every missing Nordnet id
 * in one pass (~45–50 min serial for the full ~740-name universe). The hourly
 * `/cron/refill` path is timeboxed and converges over subsequent runs; this
 * script is the one that finishes it in a single sitting.
 *
 * Thin wrapper (pattern: bin/resolve-ids.php): bootstrap, build the real HTTP
 * client + adapters + repositories, run the sync for today (Europe/Stockholm),
 * print the churn counts. `catch (\Throwable)` → log `error`, STDERR, exit(1).
 */

use GuzzleHttp\Client;
use Stockpicker\Adapter\AvanzaUniverseAdapter;
use Stockpicker\Adapter\NordnetAdapter;
use Stockpicker\Pipeline\UniverseSync;
use Stockpicker\Store\Database;
use Stockpicker\Store\InstrumentRepository;
use Stockpicker\Store\RunRepository;
use Stockpicker\Store\SettingsRepository;

$services = require __DIR__ . '/../bootstrap.php';
$logger = $services['logger'];

try {
    $pdo = Database::connect($services['config']);
    $http = new Client(['timeout' => 30, 'connect_timeout' => 10]);

    $sync = new UniverseSync(
        new AvanzaUniverseAdapter($http, $logger),
        new NordnetAdapter($http, $logger),
        new InstrumentRepository($pdo),
        new SettingsRepository($pdo),
        new RunRepository($pdo),
        $logger,
    );

    $runDate = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
    $counts = $sync->run($runDate);

    printf(
        "universe sync %s: %d added, %d removed, %d changed, %d reactivated, "
        . "%d ids resolved, %d ids failed, %d deferred, %d active\n",
        $runDate,
        $counts['added'],
        $counts['removed'],
        $counts['changed'],
        $counts['reactivated'],
        $counts['ids_resolved'],
        $counts['ids_failed'],
        $counts['deferred'],
        $counts['active_after'],
    );
} catch (\Throwable $e) {
    $logger->error('universe-sync failed', [
        'exception' => $e::class,
        'message' => $e->getMessage(),
    ]);
    fwrite(STDERR, 'universe-sync failed: ' . $e->getMessage() . "\n");
    exit(1);
}
