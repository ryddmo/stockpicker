<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Store;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Stockpicker\Adapter\NormalizedRow;
use Stockpicker\Config;
use Stockpicker\Store\Database;
use Stockpicker\Store\OwnerCountRepository;

/**
 * Drives `bin/show-metrics.php` as a real subprocess, mirroring
 * `ShowRunsScriptTest`. Argv-validation cases run unconditionally; the
 * happy-path case needs the dev DB (config.php + `phinx migrate -e
 * development`, which creates the `owner_count_metrics` view from Story 3.1)
 * and self-skips without it.
 */
final class ShowMetricsScriptTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../..';
    private const ISIN = 'SE0099999999';

    public function testMissingIsinExitsNonZeroWithUsage(): void
    {
        [$code, $out] = $this->show('--limit=5');

        self::assertNotSame(0, $code);
        self::assertStringContainsString('usage:', $out);
    }

    public function testUnrecognizedFlagExitsNonZeroWithUsage(): void
    {
        [$code, $out] = $this->show('--isin=' . self::ISIN, '--bogus=1');

        self::assertNotSame(0, $code);
        self::assertStringContainsString('usage:', $out);
    }

    public function testNonPositiveLimitExitsNonZeroWithUsage(): void
    {
        [$code, $out] = $this->show('--isin=' . self::ISIN, '--limit=0');

        self::assertNotSame(0, $code);
        self::assertStringContainsString('usage:', $out);
    }

    public function testMalformedLimitExitsNonZeroWithUsage(): void
    {
        [$code, $out] = $this->show('--isin=' . self::ISIN, '--limit=abc');

        self::assertNotSame(0, $code);
        self::assertStringContainsString('usage:', $out);
    }

    public function testUnknownIsinExitsNonZeroWithDistinctErrorMessage(): void
    {
        $this->requireDevelopmentDatabase();

        [$code, $out] = $this->show('--isin=SE0000000000');

        self::assertNotSame(0, $code);
        self::assertStringContainsString('error: unknown isin: SE0000000000', $out);
        self::assertStringNotContainsString('usage:', $out);
    }

    public function testValidIsinPrintsIdentityAndOneBlockPerSource(): void
    {
        $this->requireDevelopmentDatabase();

        $pdo = Database::connect(Config::load((string) realpath(self::REPO_ROOT)));
        $pdo->exec(
            "INSERT INTO instrument (isin, name, list, first_seen)
             VALUES ('" . self::ISIN . "', 'Show Metrics Test AB', 'LC', '2026-01-01')"
        );

        $repo = new OwnerCountRepository($pdo);
        $repo->upsert(new NormalizedRow(
            self::ISIN,
            NormalizedRow::SOURCE_AVANZA,
            1000,
            100.0,
            1000000.0,
            null,
            new DateTimeImmutable('2026-09-10T09:00:00Z', new DateTimeZone('UTC')),
        ), '2026-09-10');

        try {
            [$code, $out] = $this->show('--isin=' . self::ISIN);

            self::assertSame(0, $code, $out);
            self::assertStringContainsString(self::ISIN, $out);
            self::assertStringContainsString('Show Metrics Test AB', $out);
            self::assertStringContainsString('== avanza ==', $out);
            self::assertStringContainsString('2026-09-10', $out);
            self::assertStringContainsString('== nordnet ==', $out);
            self::assertStringContainsString('no data', $out);
        } finally {
            $pdo->exec("DELETE FROM owner_count_daily WHERE isin = '" . self::ISIN . "'");
            $pdo->exec("DELETE FROM instrument WHERE isin = '" . self::ISIN . "'");
        }
    }

    public function testLimitCapsOutputToTheMostRecentRowsInChronologicalOrder(): void
    {
        $this->requireDevelopmentDatabase();

        $pdo = Database::connect(Config::load((string) realpath(self::REPO_ROOT)));
        $pdo->exec(
            "INSERT INTO instrument (isin, name, list, first_seen)
             VALUES ('" . self::ISIN . "', 'Show Metrics Test AB', 'LC', '2026-01-01')"
        );

        $repo = new OwnerCountRepository($pdo);
        // avanza: 5 rows, more than --limit=3 -> must be truncated to the 3
        // most recent. nordnet: 2 rows, fewer than --limit=3 and using dates
        // disjoint from avanza's -> must come through untruncated, proving
        // the array_slice loop caps each source independently rather than
        // globally across the combined result.
        $avanzaDates = ['2026-09-06', '2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10'];
        $nordnetDates = ['2026-08-20', '2026-08-21'];
        foreach ($avanzaDates as $date) {
            $repo->upsert(new NormalizedRow(
                self::ISIN,
                NormalizedRow::SOURCE_AVANZA,
                1000,
                100.0,
                1000000.0,
                null,
                new DateTimeImmutable($date . 'T09:00:00Z', new DateTimeZone('UTC')),
            ), $date);
        }
        foreach ($nordnetDates as $date) {
            $repo->upsert(new NormalizedRow(
                self::ISIN,
                NormalizedRow::SOURCE_NORDNET,
                500,
                100.0,
                1000000.0,
                null,
                new DateTimeImmutable($date . 'T09:00:00Z', new DateTimeZone('UTC')),
            ), $date);
        }

        try {
            [$code, $out] = $this->show('--isin=' . self::ISIN, '--limit=3');

            self::assertSame(0, $code, $out);

            $avanzaStart = strpos($out, '== avanza ==');
            $nordnetStart = strpos($out, '== nordnet ==');
            self::assertNotFalse($avanzaStart);
            self::assertNotFalse($nordnetStart);
            $avanzaBlock = substr($out, $avanzaStart, $nordnetStart - $avanzaStart);
            $nordnetBlock = substr($out, $nordnetStart);

            // avanza: the 2 oldest of the 5 seeded rows are dropped...
            self::assertStringNotContainsString('2026-09-06', $avanzaBlock);
            self::assertStringNotContainsString('2026-09-07', $avanzaBlock);
            // ...and the 3 most recent remain, in chronological order.
            self::assertStringContainsString('2026-09-08', $avanzaBlock);
            self::assertStringContainsString('2026-09-09', $avanzaBlock);
            self::assertStringContainsString('2026-09-10', $avanzaBlock);
            self::assertTrue(
                strpos($avanzaBlock, '2026-09-08') < strpos($avanzaBlock, '2026-09-09')
                && strpos($avanzaBlock, '2026-09-09') < strpos($avanzaBlock, '2026-09-10'),
                $avanzaBlock,
            );

            // nordnet: only 2 rows were seeded (fewer than --limit=3), so
            // both must still be present, unaffected by avanza's truncation.
            self::assertStringContainsString('2026-08-20', $nordnetBlock);
            self::assertStringContainsString('2026-08-21', $nordnetBlock);
        } finally {
            $pdo->exec("DELETE FROM owner_count_daily WHERE isin = '" . self::ISIN . "'");
            $pdo->exec("DELETE FROM instrument WHERE isin = '" . self::ISIN . "'");
        }
    }

    private function requireDevelopmentDatabase(): void
    {
        try {
            $pdo = Database::connect(Config::load((string) realpath(self::REPO_ROOT)));
            $pdo->query('SELECT isin FROM owner_count_metrics LIMIT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped(
                'dev DB for bin/show-metrics.php not ready (config.php + phinx migrate -e development): ' . $e->getMessage()
            );
        }
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function show(string ...$args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg((string) realpath(self::REPO_ROOT) . '/bin/show-metrics.php') . ' '
            . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';

        $output = [];
        $code = 0;
        exec($cmd, $output, $code);

        return [$code, implode("\n", $output)];
    }
}
