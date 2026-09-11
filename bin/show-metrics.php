<?php

declare(strict_types=1);

/**
 * Story 3.3 — print one instrument's full owner-count series and derived
 * metrics over SSH:
 *
 *   php bin/show-metrics.php --isin=SE... [--limit=N]
 *
 * Thin wrapper (like bin/show-runs.php): bootstrap, resolve `--isin=`/`--limit=`
 * from argv, resolve the instrument via InstrumentRepository::get(), fetch
 * every source's series via Deriver::forInstrument(), render via
 * Stockpicker\Store\DerivedMetricsTable. `--isin=` is required and normalized
 * (trimmed, uppercased) before lookup, so a differently-cased but otherwise
 * correct ISIN still matches; an unknown isin is a distinct error from a
 * malformed/missing flag. `--limit=N` caps
 * each source to its N most recent rows (array_slice(..., -N)), preserving
 * chronological order. Read-only throughout; `catch \Throwable` -> log +
 * exit(1).
 */

use Stockpicker\Pipeline\Deriver;
use Stockpicker\Store\Database;
use Stockpicker\Store\DerivedMetricsRepository;
use Stockpicker\Store\DerivedMetricsTable;
use Stockpicker\Store\InstrumentRepository;

$usage = static function (): never {
    fwrite(STDERR, "usage: php bin/show-metrics.php --isin=SE... [--limit=N]\n");
    exit(1);
};

try {
    $services = require __DIR__ . '/../bootstrap.php';
    $isin = null;
    $limit = null;

    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--isin=(.+)$/', $arg, $m) === 1) {
            $isin = $m[1];
        } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m) === 1) {
            $limit = (int) $m[1];
            if ($limit <= 0) {
                $usage();
            }
        } else {
            $usage();
        }
    }

    if ($isin === null) {
        $usage();
    }

    $isin = strtoupper(trim($isin));

    $pdo = Database::connect($services['config']);
    $instrument = (new InstrumentRepository($pdo))->get($isin);

    if ($instrument === null) {
        fwrite(STDERR, "error: unknown isin: {$isin}\n");
        exit(1);
    }

    $bySource = (new Deriver(new DerivedMetricsRepository($pdo)))->forInstrument($isin);

    if ($limit !== null) {
        foreach ($bySource as $source => $rows) {
            $bySource[$source] = array_slice($rows, -$limit);
        }
    }

    echo DerivedMetricsTable::render($instrument, $bySource);
} catch (\Throwable $e) {
    if (isset($services['logger'])) {
        $services['logger']->error('show-metrics failed', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    } else {
        error_log('show-metrics bootstrap failed: ' . $e->getMessage());
    }
    fwrite(STDERR, 'show-metrics failed: ' . $e->getMessage() . "\n");
    exit(1);
}
