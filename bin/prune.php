<?php

declare(strict_types=1);

/**
 * Delete old, no-longer-operationally-relevant rows from `work_queue`
 * (done/failed jobs) and `ingest_run` (run-log rows) so both tables stay
 * bounded on Loopia shared hosting — neither is pruned anywhere else.
 * `owner_count_daily`, the actual time series, is never touched.
 *
 *   php bin/prune.php [--work-queue-days=30] [--ingest-run-days=180] [--apply]
 *
 * Without --apply this is a dry run: it reports how many rows each table
 * would lose and changes nothing. `--work-queue-days` only ever counts/deletes
 * `done`/`failed` rows (never `pending`/`claimed`, regardless of age).
 * Deleting an `ingest_run` row nulls out any `owner_count_daily.ingest_run_id`
 * that pointed at it (ON DELETE SET NULL) — only that old run's provenance
 * link is lost, not the owner-count row itself. A non-positive day count or
 * unrecognized flag prints the usage line and exits 1; `catch \Throwable` ->
 * log + exit(1).
 */

use Stockpicker\Store\Database;
use Stockpicker\Store\QueueRepository;
use Stockpicker\Store\RunRepository;

$usage = static function (): never {
    fwrite(STDERR, "usage: php bin/prune.php [--work-queue-days=30] [--ingest-run-days=180] [--apply]\n");
    exit(1);
};

try {
    $services = require __DIR__ . '/../bootstrap.php';
    $workQueueDays = 30;
    $ingestRunDays = 180;
    $apply = false;

    $argv ??= [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--work-queue-days=(\d+)$/', $arg, $m) === 1) {
            $workQueueDays = (int) $m[1];
            if ($workQueueDays <= 0) {
                $usage();
            }
        } elseif (preg_match('/^--ingest-run-days=(\d+)$/', $arg, $m) === 1) {
            $ingestRunDays = (int) $m[1];
            if ($ingestRunDays <= 0) {
                $usage();
            }
        } elseif ($arg === '--apply') {
            $apply = true;
        } else {
            $usage();
        }
    }

    $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Stockholm'));
    $workQueueCutoff = $now->modify("-{$workQueueDays} days")->format('Y-m-d');
    $ingestRunCutoff = $now->modify("-{$ingestRunDays} days")->format('Y-m-d');

    $pdo = Database::connect($services['config']);
    $queue = new QueueRepository($pdo);
    $runs = new RunRepository($pdo);

    if ($apply) {
        $queuePruned = $queue->prune($workQueueCutoff);
        $runsPruned = $runs->prune($ingestRunCutoff);
        printf("work_queue: deleted %d done/failed row(s) with run_date < %s\n", $queuePruned, $workQueueCutoff);
        printf("ingest_run: deleted %d row(s) with run_date < %s\n", $runsPruned, $ingestRunCutoff);
    } else {
        $queueCount = $queue->countPrunable($workQueueCutoff);
        $runsCount = $runs->countPrunable($ingestRunCutoff);
        printf("[dry run] work_queue: %d done/failed row(s) with run_date < %s would be deleted\n", $queueCount, $workQueueCutoff);
        printf("[dry run] ingest_run: %d row(s) with run_date < %s would be deleted\n", $runsCount, $ingestRunCutoff);
        echo "Re-run with --apply to actually delete.\n";
    }
} catch (\Throwable $e) {
    if (isset($services['logger'])) {
        $services['logger']->error('prune failed', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    } else {
        error_log('prune bootstrap failed: ' . $e->getMessage());
    }
    fwrite(STDERR, 'prune failed: ' . $e->getMessage() . "\n");
    exit(1);
}
