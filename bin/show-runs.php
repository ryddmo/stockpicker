<?php

declare(strict_types=1);

/**
 * Story 1.8 — print the recent `ingest_run` rows over SSH:
 *
 *   php bin/show-runs.php [--limit=N] [--date=YYYY-MM-DD] [--alarms]
 *
 * Thin wrapper (like bin/seed-instruments.php): bootstrap, build RunRepository
 * from Database::connect(), render via Stockpicker\Store\RunTable. Default: the
 * 20 most recent rows, newest first. `--date` narrows to one run date, oldest
 * first — the order a night happened in; `--limit` caps both paths. Read-only;
 * a malformed `--date` / non-positive `--limit` prints the usage line and
 * exits 1; `catch \Throwable` -> log + exit(1).
 */

use Stockpicker\Store\Database;
use Stockpicker\Store\RunRepository;
use Stockpicker\Store\RunTable;

$usage = static function (): never {
    fwrite(STDERR, "usage: php bin/show-runs.php [--limit=N] [--date=YYYY-MM-DD]\n");
    exit(1);
};

try {
    $services = require __DIR__ . '/../bootstrap.php';
    $limit = 20;
    $date = null;
    $alarms = false;

    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $arg, $m) === 1) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $m[1]);
            if ($parsed === false || $parsed->format('Y-m-d') !== $m[1]) {
                $usage();
            }
            $date = $m[1];
        } elseif ($arg === '--alarms') {
            $alarms = true;
        } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m) === 1) {
            $limit = (int) $m[1];
            if ($limit <= 0) {
                $usage();
            }
        } else {
            $usage();
        }
    }

    $repo = new RunRepository(Database::connect($services['config']));
    $rows = $alarms ? $repo->alarmed($limit) : ($date !== null ? $repo->forRunDate($date, $limit) : $repo->recent($limit));

    echo RunTable::render($rows);
} catch (\Throwable $e) {
    if (isset($services['logger'])) {
        $services['logger']->error('show-runs failed', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    } else {
        error_log('show-runs bootstrap failed: ' . $e->getMessage());
    }
    fwrite(STDERR, 'show-runs failed: ' . $e->getMessage() . "\n");
    exit(1);
}
