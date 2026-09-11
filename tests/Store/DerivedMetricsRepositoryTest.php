<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Store;

use DateTimeImmutable;
use DateTimeZone;
use Stockpicker\Adapter\NormalizedRow;
use Stockpicker\Store\DerivedMetricsRepository;
use Stockpicker\Store\OwnerCountRepository;

/**
 * One case per row of the spec's I/O & Edge-Case Matrix (spec-3-1), against
 * the real `owner_count_metrics` view (StoreTestCase's MariaDB mirror).
 */
final class DerivedMetricsRepositoryTest extends StoreTestCase
{
    private const ISIN = 'SE0015811963';

    private OwnerCountRepository $owners;
    private DerivedMetricsRepository $metrics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo->exec(
            "INSERT IGNORE INTO instrument (isin, name, list, first_seen)
             VALUES ('" . self::ISIN . "', 'Investor B', 'LC', '2026-01-01')"
        );

        $this->owners = new OwnerCountRepository($this->pdo);
        $this->metrics = new DerivedMetricsRepository($this->pdo);
    }

    /**
     * Seed one stored row for an exact calendar date via the run-date
     * override (Story 1.7) — bypasses the fetch-clock/timezone derivation
     * entirely, so a test can place rows (and gaps) precisely.
     */
    private function seed(string $asOfDate, int $owners, string $source = NormalizedRow::SOURCE_AVANZA): void
    {
        $row = new NormalizedRow(
            self::ISIN,
            $source,
            $owners,
            null,
            null,
            null,
            new DateTimeImmutable($asOfDate . 'T12:00:00', new DateTimeZone('UTC')),
        );

        self::assertTrue($this->owners->upsert($row, $asOfDate), "seed row for {$asOfDate}/{$source} should be new");
    }

    public function testFirstEverRowHasAllSevenMetricsNull(): void
    {
        $this->seed('2026-01-01', 1000);

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);
        self::assertCount(1, $rows);

        foreach (['delta_1d', 'pct_1d', 'sma_7', 'sma_30', 'sma_90', 'up_streak', 'spike_score'] as $field) {
            self::assertNull($rows[0][$field], "{$field} should be NULL on the first-ever row");
        }
    }

    public function test2ndTo6thRowsGetDeltaAndPctButNoSmaOrSpikeYet(): void
    {
        $values = [1000, 1010, 1005, 1020, 1030, 1025];
        foreach ($values as $i => $v) {
            $this->seed(sprintf('2026-01-%02d', $i + 1), $v);
        }

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);
        self::assertCount(6, $rows);

        // Gaps-and-islands over the raw day-to-day delta: row1 has no prior
        // row (NULL), then the running consecutive-increase count.
        $expectedUpStreak = [null, 1, 0, 1, 2, 0];
        $expectedDelta = [null, 10, -5, 15, 10, -5];

        foreach ($rows as $i => $row) {
            $delta = $row['delta_1d'] === null ? null : (int) $row['delta_1d'];
            self::assertSame($expectedDelta[$i], $delta, "delta_1d row {$i}");

            if ($i === 0) {
                self::assertNull($row['pct_1d'], 'pct_1d row 0');
            } else {
                self::assertNotNull($row['pct_1d'], "pct_1d row {$i}");
            }

            self::assertNull($row['sma_7'], "sma_7 row {$i}");
            self::assertNull($row['sma_30'], "sma_30 row {$i}");
            self::assertNull($row['sma_90'], "sma_90 row {$i}");
            self::assertNull($row['spike_score'], "spike_score row {$i}");

            $streak = $row['up_streak'] === null ? null : (int) $row['up_streak'];
            self::assertSame($expectedUpStreak[$i], $streak, "up_streak row {$i}");
        }
    }

    public function testSeventhConsecutiveRowSetsSma7ButNotSma30Sma90OrSpike(): void
    {
        $values = [1000, 1010, 1005, 1020, 1030, 1025, 1040];
        foreach ($values as $i => $v) {
            $this->seed(sprintf('2026-01-%02d', $i + 1), $v);
        }

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);
        self::assertCount(7, $rows);
        $last = $rows[6];

        self::assertEqualsWithDelta(array_sum($values) / 7, (float) $last['sma_7'], 0.0001, 'sma_7');
        self::assertNull($last['sma_30']);
        self::assertNull($last['sma_90']);
        self::assertNull($last['spike_score']);
    }

    public function testThirtiethConsecutiveRowSetsSma30AndSpikeScoreMatchingHandComputedFixture(): void
    {
        $values = [];
        for ($i = 0; $i < 30; ++$i) {
            $values[] = 1000 + $i * 10;
        }

        $start = new DateTimeImmutable('2026-03-01');
        foreach ($values as $i => $v) {
            $this->seed($start->modify("+{$i} days")->format('Y-m-d'), $v);
        }

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);
        self::assertCount(30, $rows);
        $last = $rows[29];

        // Hand-computed independently of the SQL: sample mean/stddev over the
        // trailing 30 rows.
        $mean = array_sum($values) / 30;
        $variance = array_sum(array_map(static fn (int $v): float => ($v - $mean) ** 2, $values)) / 29;
        $stddev = sqrt($variance);
        $expectedSpike = ($values[29] - $mean) / $stddev;

        self::assertEqualsWithDelta($mean, (float) $last['sma_30'], 0.0001, 'sma_30');
        self::assertEqualsWithDelta($expectedSpike, (float) $last['spike_score'], 0.001, 'spike_score');
        self::assertNotNull($last['delta_1d']);
        self::assertNotNull($last['pct_1d']);
        // Strictly increasing every day -> 29 consecutive up-days over 30 rows.
        self::assertSame(29, (int) $last['up_streak']);
    }

    public function testNinetiethConsecutiveRowSetsSma90MatchingHandComputedFixtureAndNotBefore(): void
    {
        $values = [];
        for ($i = 0; $i < 90; ++$i) {
            $values[] = 1000 + $i * 10;
        }

        $start = new DateTimeImmutable('2026-01-01');
        foreach ($values as $i => $v) {
            $this->seed($start->modify("+{$i} days")->format('Y-m-d'), $v);
        }

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);
        self::assertCount(90, $rows);

        // Row 89 (the 89th row, index 88) has only 89 stored rows -> sma_90 NULL.
        self::assertNull($rows[88]['sma_90'], 'sma_90 must stay NULL before the 90th row');

        // Row 90 (index 89): hand-computed mean of the trailing 90 rows.
        $mean = array_sum($values) / 90;
        self::assertEqualsWithDelta($mean, (float) $rows[89]['sma_90'], 0.0001, 'sma_90');
    }

    public function testDecreasingOwnerCountSeriesProducesNegativeDeltaAndPct(): void
    {
        // Guards the unsigned-subtraction wraparound bug found during manual
        // verification: number_of_owners/prev_owners are INT UNSIGNED, so a
        // plain unsigned-minus-unsigned would wrap a real decrease into a
        // huge positive number instead of a negative delta_1d/pct_1d.
        $this->seed('2026-08-01', 500000);
        $this->seed('2026-08-02', 499000);
        $this->seed('2026-08-03', 480000);

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);

        self::assertSame(-1000, (int) $rows[1]['delta_1d']);
        self::assertEqualsWithDelta(-0.002, (float) $rows[1]['pct_1d'], 0.0000001, 'pct_1d row 1');

        self::assertSame(-19000, (int) $rows[2]['delta_1d']);
        self::assertEqualsWithDelta(-19000 / 499000, (float) $rows[2]['pct_1d'], 0.0000001, 'pct_1d row 2');

        self::assertSame(0, (int) $rows[1]['up_streak']);
        self::assertSame(0, (int) $rows[2]['up_streak']);
    }

    public function testGapLargerThanOneDayNullsDeltaAndPctButNotRowBasedMetrics(): void
    {
        $values = [1000, 1010, 1020, 1030, 1040, 1050];
        foreach ($values as $i => $v) {
            $this->seed(sprintf('2026-04-%02d', $i + 1), $v);
        }
        $this->seed('2026-04-08', 1060); // gap: 2026-04-06 -> 2026-04-08 (2 days)

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);
        self::assertCount(7, $rows);
        $afterGap = $rows[6];

        self::assertSame('2026-04-08', $afterGap['as_of_date']);
        self::assertNull($afterGap['delta_1d'], 'delta_1d must be NULL across a >1-day gap');
        self::assertNull($afterGap['pct_1d'], 'pct_1d must be NULL across a >1-day gap');

        // Row-based metrics stay unaffected by the gap.
        self::assertEqualsWithDelta(1030.0, (float) $afterGap['sma_7'], 0.0001, 'sma_7 across the gap');
        self::assertSame(6, (int) $afterGap['up_streak'], 'raw_delta is still positive across the gap');
    }

    public function testPriorCountOfZeroNullsPctButStillComputesDelta(): void
    {
        $this->seed('2026-05-01', 0);
        $this->seed('2026-05-02', 50);

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);
        $second = $rows[1];

        self::assertSame(50, (int) $second['delta_1d']);
        self::assertNull($second['pct_1d']);
    }

    public function testDownOrFlatDayResetsUpStreakToZero(): void
    {
        $this->seed('2026-06-01', 1000);
        $this->seed('2026-06-02', 1010); // up -> streak 1
        $this->seed('2026-06-03', 1010); // flat -> resets to 0

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);

        self::assertSame(1, (int) $rows[1]['up_streak']);
        self::assertSame(0, (int) $rows[2]['delta_1d']);
        self::assertSame(0, (int) $rows[2]['up_streak']);
    }

    public function testTwoSourcesForTheSameIsinAreComputedIndependently(): void
    {
        $this->seed('2026-07-01', 1000, NormalizedRow::SOURCE_AVANZA);
        $this->seed('2026-07-02', 1010, NormalizedRow::SOURCE_AVANZA);

        $this->seed('2026-07-01', 500, NormalizedRow::SOURCE_NORDNET);
        $this->seed('2026-07-02', 400, NormalizedRow::SOURCE_NORDNET);

        $all = $this->metrics->forIsin(self::ISIN);

        self::assertSame([NormalizedRow::SOURCE_AVANZA, NormalizedRow::SOURCE_NORDNET], array_keys($all));
        self::assertCount(2, $all[NormalizedRow::SOURCE_AVANZA]);
        self::assertCount(2, $all[NormalizedRow::SOURCE_NORDNET]);

        self::assertSame(10, (int) $all[NormalizedRow::SOURCE_AVANZA][1]['delta_1d']);
        self::assertSame(-100, (int) $all[NormalizedRow::SOURCE_NORDNET][1]['delta_1d']);
    }
}
