<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Store;

use PHPUnit\Framework\TestCase;
use Stockpicker\Config;
use Stockpicker\Store\Database;
use Stockpicker\Store\QueueRepository;
use Stockpicker\Store\RunRepository;

/**
 * Drives `bin/prune.php` as a real subprocess (mirrors ShowRunsScriptTest).
 * Skips unless the dev DB the script targets is reachable and migrated.
 */
final class PruneScriptTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../..';
    private const ISIN = 'SE0000000099';

    public function testUnrecognizedFlagExitsNonZeroWithUsage(): void
    {
        [$code, $out] = $this->prune('--bogus');

        self::assertNotSame(0, $code);
        self::assertStringContainsString('usage:', $out);
    }

    public function testNonPositiveWorkQueueDaysExitsNonZeroWithUsage(): void
    {
        [$code, $out] = $this->prune('--work-queue-days=0');

        self::assertNotSame(0, $code);
        self::assertStringContainsString('usage:', $out);
    }

    public function testNonPositiveIngestRunDaysExitsNonZeroWithUsage(): void
    {
        [$code, $out] = $this->prune('--ingest-run-days=0');

        self::assertNotSame(0, $code);
        self::assertStringContainsString('usage:', $out);
    }

    public function testDryRunReportsCountsAndDeletesNothing(): void
    {
        $this->requireDevelopmentDatabase();
        $pdo = Database::connect(Config::load((string) realpath(self::REPO_ROOT)));
        $runs = new RunRepository($pdo);
        $started = new \DateTimeImmutable('2000-01-01T00:00:00Z');
        $runId = $runs->record('fetch', '2000-01-01', $started, $started, 1, 1, 0);

        try {
            [$code, $out] = $this->prune('--work-queue-days=1', '--ingest-run-days=1');

            self::assertSame(0, $code, $out);
            self::assertStringContainsString('[dry run]', $out);
            self::assertStringContainsString('Re-run with --apply', $out);
            self::assertCount(1, $runs->forRunDate('2000-01-01'), 'dry run must not delete anything');
        } finally {
            $pdo->exec(sprintf('DELETE FROM ingest_run WHERE id = %d', $runId));
        }
    }

    public function testApplyDeletesOldRowsFromBothTablesAndReportsCounts(): void
    {
        $this->requireDevelopmentDatabase();
        $pdo = Database::connect(Config::load((string) realpath(self::REPO_ROOT)));
        $pdo->exec(
            "INSERT IGNORE INTO instrument (isin, name, list, first_seen)
             VALUES ('" . self::ISIN . "', 'PruneScriptTest', 'LC', '2000-01-01')"
        );

        $runs = new RunRepository($pdo);
        $started = new \DateTimeImmutable('2000-01-01T00:00:00Z');
        $runId = $runs->record('fetch', '2000-01-01', $started, $started, 1, 1, 0);

        $queue = new QueueRepository($pdo);
        $queue->enqueue(self::ISIN, '2000-01-01');
        $jobs = $queue->claimBatch('2000-01-01', 1, $started);
        $queue->markDone($jobs[0]->id);

        try {
            [$code, $out] = $this->prune('--work-queue-days=1', '--ingest-run-days=1', '--apply');

            self::assertSame(0, $code, $out);
            self::assertStringContainsString('work_queue: deleted', $out);
            self::assertStringContainsString('ingest_run: deleted', $out);
            self::assertSame([], $queue->countByStatus('2000-01-01'));
            self::assertSame([], $runs->forRunDate('2000-01-01'));
        } finally {
            $pdo->exec("DELETE FROM work_queue WHERE isin = '" . self::ISIN . "'");
            $pdo->exec(sprintf('DELETE FROM ingest_run WHERE id = %d', $runId));
            $pdo->exec("DELETE FROM instrument WHERE isin = '" . self::ISIN . "'");
        }
    }

    private function requireDevelopmentDatabase(): void
    {
        try {
            $pdo = Database::connect(Config::load((string) realpath(self::REPO_ROOT)));
            $pdo->query('SELECT status FROM ingest_run LIMIT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped(
                'dev DB for bin/prune.php not ready (config.php + phinx migrate -e development): ' . $e->getMessage()
            );
        }
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function prune(string ...$args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg((string) realpath(self::REPO_ROOT) . '/bin/prune.php') . ' '
            . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';

        $output = [];
        $code = 0;
        exec($cmd, $output, $code);

        return [$code, implode("\n", $output)];
    }
}
