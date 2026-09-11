<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Store;

use PHPUnit\Framework\TestCase;
use Stockpicker\Config;
use Stockpicker\Store\Database;
use Stockpicker\Store\RunRepository;

/**
 * Drives `bin/show-runs.php` as a real subprocess (mirrors
 * `MigrationTest::phinx()`). Skips unless the dev DB the script targets
 * (config.php -> `Database::connect`) is reachable and migrated — same spirit
 * as the other store tests self-skipping without a database.
 */
final class ShowRunsScriptTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../..';

    public function testMalformedDateExitsNonZeroWithUsage(): void
    {
        [$code, $out] = $this->show('--date=2026-13-45');

        self::assertNotSame(0, $code);
        self::assertStringContainsString('usage:', $out);
    }

    public function testNonPositiveLimitExitsNonZeroWithUsage(): void
    {
        [$code, $out] = $this->show('--limit=0');

        self::assertNotSame(0, $code);
        self::assertStringContainsString('usage:', $out);
    }

    public function testValidInvocationWithNoMatchingRunsExitsZeroAndPrintsNoRuns(): void
    {
        $this->requireDevelopmentDatabase();

        // A run date far in the past has no rows whatever the dev DB holds.
        [$code, $out] = $this->show('--date=1999-01-01');

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('no runs', $out);
    }

    public function testAlarmsFlagShowsAlarmedRunsOnly(): void
    {
        $this->requireDevelopmentDatabase();
        $pdo = Database::connect(Config::load((string) realpath(self::REPO_ROOT)));
        $repo = new RunRepository($pdo);
        $started = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $cleanId = $repo->record('cleantest', '2098-01-01', $started, $started, 1, 1, 0);
        $alarmId = $repo->start('alarmtest', '2098-01-02', $started);
        $repo->finish($alarmId, $started, 1, 0, 1, ['avanza' => ['schema_mismatch' => 1]]);

        try {
            [$code, $out] = $this->show('--alarms', '--limit=20');

            self::assertSame(0, $code, $out);
            self::assertStringContainsString('alarmtest', $out);
            self::assertStringNotContainsString('cleantest', $out);
        } finally {
            $pdo->exec(sprintf('DELETE FROM ingest_run WHERE id IN (%d, %d)', $cleanId, $alarmId));
        }
    }

    private function requireDevelopmentDatabase(): void
    {
        try {
            $pdo = Database::connect(Config::load((string) realpath(self::REPO_ROOT)));
            $pdo->query('SELECT status FROM ingest_run LIMIT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped(
                'dev DB for bin/show-runs.php not ready (config.php + phinx migrate -e development): ' . $e->getMessage()
            );
        }
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function show(string ...$args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg((string) realpath(self::REPO_ROOT) . '/bin/show-runs.php') . ' '
            . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';

        $output = [];
        $code = 0;
        exec($cmd, $output, $code);

        return [$code, implode("\n", $output)];
    }
}
