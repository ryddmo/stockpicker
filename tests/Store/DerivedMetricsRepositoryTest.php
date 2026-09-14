<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Store;

use DateTimeImmutable;
use DateTimeZone;
use Stockpicker\Adapter\NormalizedRow;
use Stockpicker\Store\DerivedMetricsRepository;
use Stockpicker\Store\OwnerCountRepository;
use Stockpicker\Store\WatchlistRepository;

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
        $this->seedFor(self::ISIN, $asOfDate, $owners, $source);
    }

    /**
     * Same as seed(), for an arbitrary isin — used by the topByOwnerCount()/
     * topByTrendQuality() tests (Story 4.2), which need more than one
     * instrument. The caller must insert the `instrument` row itself first.
     */
    private function seedFor(string $isin, string $asOfDate, int $owners, string $source = NormalizedRow::SOURCE_AVANZA): void
    {
        $row = new NormalizedRow(
            $isin,
            $source,
            $owners,
            null,
            null,
            null,
            new DateTimeImmutable($asOfDate . 'T12:00:00', new DateTimeZone('UTC')),
        );

        self::assertTrue($this->owners->upsert($row, $asOfDate), "seed row for {$isin}/{$asOfDate}/{$source} should be new");
    }

    private function insertInstrument(string $isin, string $name, ?string $lastSeen = null, string $list = 'LC'): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO instrument (isin, name, list, first_seen, last_seen)
             VALUES (:isin, :name, :list, \'2026-01-01\', :last_seen)'
        );
        $stmt->execute(['isin' => $isin, 'name' => $name, 'list' => $list, 'last_seen' => $lastSeen]);
    }

    public function testFirstEverRowHasAllSevenMetricsNull(): void
    {
        $this->seed('2026-01-01', 1000);

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);
        self::assertCount(1, $rows);

        // spec-5-5: pct_7d/pct_90d/pct_365d added to the same NULL-on-
        // first-row assertion loop as the original seven.
        foreach (['delta_1d', 'pct_1d', 'pct_7d', 'pct_90d', 'pct_365d', 'sma_7', 'sma_30', 'sma_90', 'up_streak', 'spike_score'] as $field) {
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

    // -- spec-5-5: pct_7d/pct_90d/pct_365d (same gap-aware rule as pct_1d) ---

    public function testPct7dPopulatesWhenExactlySevenCalendarDaysOfHistoryExist(): void
    {
        $values = [1000, 1010, 1020, 1030, 1040, 1050, 1060, 1070];
        foreach ($values as $i => $v) {
            $this->seed(sprintf('2026-02-%02d', $i + 1), $v);
        }

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);
        self::assertCount(8, $rows);
        $last = $rows[7];

        self::assertSame('2026-02-08', $last['as_of_date']);
        self::assertNotNull($last['pct_7d']);
        self::assertEqualsWithDelta((1070 - 1000) / 1000, (float) $last['pct_7d'], 0.0000001, 'pct_7d');
        // Not enough calendar-days-back history yet for the two longer windows.
        self::assertNull($last['pct_90d']);
        self::assertNull($last['pct_365d']);
    }

    public function testPct7dIsNullWhenFewerThanSevenRowsOfHistoryExist(): void
    {
        $values = [1000, 1010, 1020];
        foreach ($values as $i => $v) {
            $this->seed(sprintf('2026-02-%02d', $i + 1), $v);
        }

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);
        $last = $rows[count($rows) - 1];

        self::assertNull($last['pct_7d'], 'not enough rows for a 7-calendar-day-back comparison yet');
    }

    public function testGapCrossingTheSevenDayBoundaryNullsPct7dDespiteEnoughRowsExisting(): void
    {
        // 6 consecutive days, then a 1-day gap (2026-03-07 skipped), then 2
        // more days -> 8 total rows, enough for a row 7 positions back to
        // exist, but that row is 8 calendar days earlier, not 7 -- must
        // still NULL, mirroring testGapLargerThanOneDayNullsDeltaAndPctButNotRowBasedMetrics's
        // pct_1d guard, extended to pct_7d (spec's I/O & Edge-Case Matrix:
        // "a data gap crosses exactly the 7/90/365-day boundary").
        $values = [1000, 1010, 1020, 1030, 1040, 1050];
        foreach ($values as $i => $v) {
            $this->seed(sprintf('2026-03-%02d', $i + 1), $v);
        }
        $this->seed('2026-03-08', 1060); // gap: 03-06 -> 03-08
        $this->seed('2026-03-09', 1070);

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);
        self::assertCount(8, $rows);
        $last = $rows[7];

        self::assertSame('2026-03-09', $last['as_of_date']);
        self::assertNull(
            $last['pct_7d'],
            'a data gap crossing the 7-day window must null pct_7d, even though a row 7 positions back exists',
        );
    }

    public function testPct7dZeroChangeShowsAsExactlyZeroNotNull(): void
    {
        $values = array_fill(0, 8, 1000);
        foreach ($values as $i => $v) {
            $this->seed(sprintf('2026-04-%02d', $i + 1), $v);
        }

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);
        $last = $rows[7];

        self::assertNotNull($last['pct_7d'], 'an unchanged owner count is still a valid comparison, not insufficient history');
        self::assertEqualsWithDelta(0.0, (float) $last['pct_7d'], 0.0000001);
    }

    public function testPct90dPopulatesWithTheCorrectValueWhenExactlyNinetyCalendarDaysOfHistoryExist(): void
    {
        // Review round (iteration 1): pct_7d had a value-correctness test but
        // pct_90d/pct_365d did not -- only their NULL path was ever checked,
        // so a copy-paste slip in the hand-duplicated CASE WHEN block (e.g.
        // reusing prev_owners_7 while leaving the DATEDIFF(...) = 90 guard
        // intact) would ship a wrong number with the full suite green.
        $start = new DateTimeImmutable('2026-01-01');
        for ($i = 0; $i <= 90; ++$i) {
            $this->seed($start->modify("+{$i} days")->format('Y-m-d'), 1000 + $i * 2);
        }

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);
        self::assertCount(91, $rows);
        $last = $rows[90];

        self::assertNotNull($last['pct_90d']);
        self::assertEqualsWithDelta((1180 - 1000) / 1000, (float) $last['pct_90d'], 0.0000001, 'pct_90d');
        self::assertNull($last['pct_365d'], 'not enough calendar-days-back history yet for the 365-day window');
    }

    public function testPct365dPopulatesWithTheCorrectValueWhenExactlyThreeHundredSixtyFiveCalendarDaysOfHistoryExist(): void
    {
        $start = new DateTimeImmutable('2025-01-01');
        for ($i = 0; $i <= 365; ++$i) {
            $this->seed($start->modify("+{$i} days")->format('Y-m-d'), 1000 + $i);
        }

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);
        self::assertCount(366, $rows);
        $last = $rows[365];

        self::assertNotNull($last['pct_365d']);
        self::assertEqualsWithDelta((1365 - 1000) / 1000, (float) $last['pct_365d'], 0.0000001, 'pct_365d');
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

    // -- topByOwnerCount() ---------------------------------------------------

    public function testTopByOwnerCountOrdersByLatestNumberOfOwnersDescAndExcludesDelistedInstruments(): void
    {
        $this->insertInstrument('SE0000108656', 'Atlas Copco A');
        $this->insertInstrument('SE0000199999', 'Delisted AB', '2026-02-01');

        $this->seedFor(self::ISIN, '2026-01-01', 1000);
        $this->seedFor('SE0000108656', '2026-01-01', 5000);
        $this->seedFor('SE0000199999', '2026-01-01', 9000);

        $top = $this->metrics->topByOwnerCount(NormalizedRow::SOURCE_AVANZA, 10);

        self::assertCount(2, $top, 'the delisted instrument must never appear');
        self::assertSame('SE0000108656', $top[0]['isin']);
        self::assertSame('Atlas Copco A', $top[0]['name']);
        self::assertSame(self::ISIN, $top[1]['isin']);
    }

    public function testTopByOwnerCountRanksByTheLatestRowNotAHistoricalPeak(): void
    {
        $this->insertInstrument('SE0000108656', 'Atlas Copco A');

        $this->seedFor(self::ISIN, '2026-01-01', 9000);
        $this->seedFor(self::ISIN, '2026-01-02', 1000); // latest day: a big drop
        $this->seedFor('SE0000108656', '2026-01-01', 5000);

        $top = $this->metrics->topByOwnerCount(NormalizedRow::SOURCE_AVANZA, 10);

        self::assertSame('SE0000108656', $top[0]['isin'], 'ranked on the latest (1000), not the historical peak (9000)');
        self::assertSame(1000, (int) $top[1]['number_of_owners']);
    }

    public function testTopByOwnerCountRespectsTheLimit(): void
    {
        $this->insertInstrument('SE0000108656', 'Atlas Copco A');
        $this->insertInstrument('SE0000222222', 'SSAB B');

        $this->seedFor(self::ISIN, '2026-01-01', 1000);
        $this->seedFor('SE0000108656', '2026-01-01', 2000);
        $this->seedFor('SE0000222222', '2026-01-01', 3000);

        $top = $this->metrics->topByOwnerCount(NormalizedRow::SOURCE_AVANZA, 2);

        self::assertCount(2, $top);
        self::assertSame('SE0000222222', $top[0]['isin']);
        self::assertSame('SE0000108656', $top[1]['isin']);
    }

    public function testTopByOwnerCountNeverMergesTwoSourcesForTheSameIsin(): void
    {
        $this->seedFor(self::ISIN, '2026-01-01', 1000, NormalizedRow::SOURCE_AVANZA);
        $this->seedFor(self::ISIN, '2026-01-01', 500000, NormalizedRow::SOURCE_NORDNET);

        $top = $this->metrics->topByOwnerCount(NormalizedRow::SOURCE_AVANZA, 10);

        self::assertCount(1, $top);
        self::assertSame(1000, (int) $top[0]['number_of_owners'], 'Nordnet\'s count must never leak into an Avanza query');
    }

    // -- topByTrendQuality() --------------------------------------------------

    public function testTopByTrendQualityRanksByUpStreakDescAndExcludesAnyRowMeetingTheSpikeThreshold(): void
    {
        $this->insertInstrument('SE0000108656', 'Atlas Copco A');
        $this->insertInstrument('SE0000222222', 'SSAB B');

        // self::ISIN: 29 days of steady +10 growth, then a huge last-day jump.
        // Strictly increasing every day -> up_streak 29 (the highest of the
        // three), but the jump drives spike_score well past the >=2
        // threshold -> must be excluded from the ranking entirely, despite
        // having the longest streak.
        $start = new DateTimeImmutable('2026-03-01');
        for ($i = 0; $i < 29; ++$i) {
            $this->seedFor(self::ISIN, $start->modify("+{$i} days")->format('Y-m-d'), 1000 + $i * 10);
        }
        $this->seedFor(self::ISIN, $start->modify('+29 days')->format('Y-m-d'), 1280 + 5000);

        // Atlas Copco A: a clean 5-day up-streak, no 30-row history yet
        // (spike_score stays NULL — not excluded).
        foreach ([2000, 2010, 2020, 2030, 2040, 2050] as $i => $v) {
            $this->seedFor('SE0000108656', sprintf('2026-04-%02d', $i + 1), $v);
        }

        // SSAB B: a shorter 2-day up-streak.
        foreach ([3000, 3010, 3020] as $i => $v) {
            $this->seedFor('SE0000222222', sprintf('2026-04-%02d', $i + 1), $v);
        }

        $top = $this->metrics->topByTrendQuality(NormalizedRow::SOURCE_AVANZA, 10);

        $isins = array_column($top, 'isin');
        self::assertNotContains(self::ISIN, $isins, 'the spiking isin must never appear in the steady-growth ranking');
        self::assertSame(['SE0000108656', 'SE0000222222'], $isins);
        self::assertSame(5, (int) $top[0]['up_streak']);
        self::assertSame(2, (int) $top[1]['up_streak']);
    }

    public function testTopByTrendQualityReturnsEmptyArrayWhenNothingQualifies(): void
    {
        // No owner_count_daily rows at all for this source -> nothing to rank,
        // and the empty-state copy ("Inga aktier med stadig tillväxt just
        // nu.") is a LeaderboardController concern, not this repository's.
        $top = $this->metrics->topByTrendQuality(NormalizedRow::SOURCE_AVANZA, 10);

        self::assertSame([], $top);
    }

    public function testTopByTrendQualityExcludesFlatOrNoStreakInstrumentsEvenWhenNotSpiking(): void
    {
        // A single, non-spiking, non-growing instrument: rn=1 has up_streak
        // NULL (no predecessor), and a down/flat day resets it to 0. Neither
        // ever qualifies as "stadig tillväxt".
        $this->seed('2026-06-01', 1000);
        $this->seed('2026-06-02', 990); // down day -> up_streak 0

        $top = $this->metrics->topByTrendQuality(NormalizedRow::SOURCE_AVANZA, 10);

        self::assertSame([], $top, 'a flat/no-streak instrument must never qualify for Stadig tillväxt');
    }

    public function testTopByTrendQualityExcludesDelistedInstruments(): void
    {
        $this->insertInstrument('SE0000199999', 'Delisted AB', '2026-02-01');

        foreach ([1000, 1010, 1020] as $i => $v) {
            $this->seedFor('SE0000199999', sprintf('2026-05-%02d', $i + 1), $v);
        }

        $top = $this->metrics->topByTrendQuality(NormalizedRow::SOURCE_AVANZA, 10);

        self::assertSame([], $top);
    }

    // -- recentSeries() ---------------------------------------------------------

    public function testRecentSeriesReturnsAllValuesOldestFirstWhenLessHistoryExistsThanTheWindow(): void
    {
        $values = [1000, 1010, 1020, 1030, 1040];
        foreach ($values as $i => $v) {
            $this->seed(sprintf('2026-09-%02d', $i + 1), $v);
        }

        $series = $this->metrics->recentSeries(self::ISIN, NormalizedRow::SOURCE_AVANZA, 30);

        self::assertCount(5, $series, 'fewer rows than the window when less history exists — no padding');
        self::assertSame('2026-09-01', $series[0]['as_of_date']);
        self::assertSame('2026-09-05', $series[4]['as_of_date']);
        self::assertSame(1040, $series[4]['number_of_owners']);
    }

    public function testRecentSeriesCapsAtTheRequestedWindowKeepingTheMostRecentDaysOldestFirst(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->seed(sprintf('2026-10-%02d', $i + 1), 1000 + $i);
        }

        $series = $this->metrics->recentSeries(self::ISIN, NormalizedRow::SOURCE_AVANZA, 3);

        self::assertCount(3, $series);
        self::assertSame(['2026-10-08', '2026-10-09', '2026-10-10'], array_column($series, 'as_of_date'));
    }

    public function testRecentSeriesNeverMergesTwoSourcesForTheSameIsin(): void
    {
        $this->seed('2026-01-01', 1000, NormalizedRow::SOURCE_AVANZA);
        $this->seed('2026-01-01', 500000, NormalizedRow::SOURCE_NORDNET);

        $series = $this->metrics->recentSeries(self::ISIN, NormalizedRow::SOURCE_AVANZA, 30);

        self::assertSame([['as_of_date' => '2026-01-01', 'number_of_owners' => 1000]], $series);
    }

    // -- searchAndFilter() -----------------------------------------------------

    public function testSearchAndFilterDefaultReturnsAllActiveInstrumentsOrderedByOwnerCountDescWithIsinTiebreak(): void
    {
        $this->insertInstrument('SE0000108656', 'Atlas Copco A');
        $this->insertInstrument('SE0000199999', 'Delisted AB', '2026-02-01');

        $this->seedFor(self::ISIN, '2026-01-01', 1000);
        $this->seedFor('SE0000108656', '2026-01-01', 1000); // tie on owner count
        $this->seedFor('SE0000199999', '2026-01-01', 9000);

        $rows = $this->metrics->searchAndFilter(NormalizedRow::SOURCE_AVANZA, [], DerivedMetricsRepository::SORT_COUNT);

        self::assertCount(2, $rows, 'the delisted instrument must never appear');
        // Tied owner counts -> isin ASC tie-break: SE0000108656 < SE0015811963.
        self::assertSame('SE0000108656', $rows[0]['isin']);
        self::assertSame(self::ISIN, $rows[1]['isin']);
    }

    public function testSearchAndFilterQMatchesNameCaseInsensitiveSubstring(): void
    {
        $this->insertInstrument('SE0000108656', 'Atlas Copco A');
        $this->seedFor(self::ISIN, '2026-01-01', 1000); // Investor B
        $this->seedFor('SE0000108656', '2026-01-01', 2000);

        $rows = $this->metrics->searchAndFilter(NormalizedRow::SOURCE_AVANZA, ['q' => 'INVES'], DerivedMetricsRepository::SORT_COUNT);

        self::assertCount(1, $rows);
        self::assertSame(self::ISIN, $rows[0]['isin']);
    }

    public function testSearchAndFilterEscapesLikeWildcardCharactersInTheSearchTerm(): void
    {
        $this->insertInstrument('SE0000333333', 'A_B AB');
        // Without escaping, '_' is a LIKE single-char wildcard, so a naive
        // '%A_B%' pattern would also match "AXB AB" — the escaping in
        // escapeLike() must make the search term match only literally.
        $this->insertInstrument('SE0000444444', 'AXB AB');
        $this->seedFor('SE0000333333', '2026-01-01', 1000);
        $this->seedFor('SE0000444444', '2026-01-01', 2000);

        $rows = $this->metrics->searchAndFilter(NormalizedRow::SOURCE_AVANZA, ['q' => 'A_B'], DerivedMetricsRepository::SORT_COUNT);

        self::assertSame(['SE0000333333'], array_column($rows, 'isin'));
    }

    public function testSearchAndFilterQWithNoMatchesReturnsEmptyArray(): void
    {
        $this->seedFor(self::ISIN, '2026-01-01', 1000);

        $rows = $this->metrics->searchAndFilter(NormalizedRow::SOURCE_AVANZA, ['q' => 'nosuchcompany'], DerivedMetricsRepository::SORT_COUNT);

        self::assertSame([], $rows);
    }

    public function testSearchAndFilterSortPctOrdersByPct1dDescWithIsinTiebreak(): void
    {
        $this->insertInstrument('SE0000108656', 'Atlas Copco A');

        // self::ISIN: 1000 -> 1100 (+10%). SE0000108656: 1000 -> 2000 (+100%).
        $this->seedFor(self::ISIN, '2026-01-01', 1000);
        $this->seedFor(self::ISIN, '2026-01-02', 1100);
        $this->seedFor('SE0000108656', '2026-01-01', 1000);
        $this->seedFor('SE0000108656', '2026-01-02', 2000);

        $rows = $this->metrics->searchAndFilter(NormalizedRow::SOURCE_AVANZA, [], DerivedMetricsRepository::SORT_PCT);

        self::assertSame(['SE0000108656', self::ISIN], array_column($rows, 'isin'));
    }

    public function testSearchAndFilterGrowthFilterAppliesTheExactSteadyGrowthQualifyingRule(): void
    {
        $this->insertInstrument('SE0000108656', 'Atlas Copco A');

        // self::ISIN: 29 days steady growth then a huge jump -> spiking, must be excluded.
        $start = new DateTimeImmutable('2026-03-01');
        for ($i = 0; $i < 29; ++$i) {
            $this->seedFor(self::ISIN, $start->modify("+{$i} days")->format('Y-m-d'), 1000 + $i * 10);
        }
        $this->seedFor(self::ISIN, $start->modify('+29 days')->format('Y-m-d'), 1280 + 5000);

        // Atlas Copco A: a clean 5-day up-streak, no spike.
        foreach ([2000, 2010, 2020, 2030, 2040, 2050] as $i => $v) {
            $this->seedFor('SE0000108656', sprintf('2026-04-%02d', $i + 1), $v);
        }

        $rows = $this->metrics->searchAndFilter(NormalizedRow::SOURCE_AVANZA, ['growth' => true], DerivedMetricsRepository::SORT_COUNT);

        self::assertSame(['SE0000108656'], array_column($rows, 'isin'), 'the spiking isin must never qualify for growth');
    }

    public function testSearchAndFilterSpikeFilterMatchesSpikeThresholdExactly(): void
    {
        $this->insertInstrument('SE0000108656', 'Atlas Copco A');

        // self::ISIN spikes; Atlas Copco A grows cleanly, no spike.
        $start = new DateTimeImmutable('2026-03-01');
        for ($i = 0; $i < 29; ++$i) {
            $this->seedFor(self::ISIN, $start->modify("+{$i} days")->format('Y-m-d'), 1000 + $i * 10);
        }
        $this->seedFor(self::ISIN, $start->modify('+29 days')->format('Y-m-d'), 1280 + 5000);

        foreach ([2000, 2010, 2020, 2030, 2040, 2050] as $i => $v) {
            $this->seedFor('SE0000108656', sprintf('2026-04-%02d', $i + 1), $v);
        }

        $rows = $this->metrics->searchAndFilter(NormalizedRow::SOURCE_AVANZA, ['spike' => true], DerivedMetricsRepository::SORT_COUNT);

        self::assertSame([self::ISIN], array_column($rows, 'isin'));
    }

    public function testSearchAndFilterGrowthAndSpikeTogetherIsAlwaysEmpty(): void
    {
        // Contradictory in practice: growth requires "not spiking", spike
        // requires "spiking" -> the AND-combined intersection can never
        // match anything (Acceptance Criteria, spec-4-4).
        $start = new DateTimeImmutable('2026-03-01');
        for ($i = 0; $i < 29; ++$i) {
            $this->seedFor(self::ISIN, $start->modify("+{$i} days")->format('Y-m-d'), 1000 + $i * 10);
        }
        $this->seedFor(self::ISIN, $start->modify('+29 days')->format('Y-m-d'), 1280 + 5000);

        $rows = $this->metrics->searchAndFilter(
            NormalizedRow::SOURCE_AVANZA,
            ['growth' => true, 'spike' => true],
            DerivedMetricsRepository::SORT_COUNT,
        );

        self::assertSame([], $rows);
    }

    public function testSearchAndFilterWatchlistFilterOnlyReturnsStarredIsins(): void
    {
        $this->insertInstrument('SE0000108656', 'Atlas Copco A');
        $this->seedFor(self::ISIN, '2026-01-01', 1000);
        $this->seedFor('SE0000108656', '2026-01-01', 2000);

        (new WatchlistRepository($this->pdo))->toggle('SE0000108656');

        $rows = $this->metrics->searchAndFilter(NormalizedRow::SOURCE_AVANZA, ['watchlist' => true], DerivedMetricsRepository::SORT_COUNT);

        self::assertSame(['SE0000108656'], array_column($rows, 'isin'));
    }

    public function testSearchAndFilterMarketFilterMatchesExactStoredListValue(): void
    {
        $this->insertInstrument('SE0000108656', 'Atlas Copco A', null, 'MC');
        $this->insertInstrument('SE0000222222', 'SSAB B', null, 'First North');
        $this->seedFor(self::ISIN, '2026-01-01', 1000); // LC (default from insertInstrument in setUp)
        $this->seedFor('SE0000108656', '2026-01-01', 2000);
        $this->seedFor('SE0000222222', '2026-01-01', 3000);

        $rows = $this->metrics->searchAndFilter(NormalizedRow::SOURCE_AVANZA, ['market' => 'First North'], DerivedMetricsRepository::SORT_COUNT);

        self::assertSame(['SE0000222222'], array_column($rows, 'isin'));
    }

    /**
     * The repository itself does not enforce any casing rule on `market` —
     * that's `FullListController::normalizeMarket()`'s whitelist, evaluated
     * before this method is ever called. This test calls the repository
     * directly, bypassing that whitelist, to document/lock in whatever the
     * `instrument.list` column's collation actually does with a
     * differently-cased value, whichever way it falls.
     */
    public function testSearchAndFilterMarketFilterCaseSensitivityAtTheSqlLayerIsDocumented(): void
    {
        $this->insertInstrument('SE0000108656', 'Atlas Copco A', null, 'First North');
        $this->seedFor('SE0000108656', '2026-01-01', 1000);

        $rows = $this->metrics->searchAndFilter(NormalizedRow::SOURCE_AVANZA, ['market' => 'first north'], DerivedMetricsRepository::SORT_COUNT);

        // MariaDB's default utf8mb4 collation is case-insensitive, so a
        // plain `=` comparison matches 'First North' regardless of the
        // query value's casing — this is the actual SQL-layer behavior,
        // not a deliberate design choice; the case-sensitive-looking
        // Boundaries & Constraints wording ("the literal stored strings")
        // is enforced above this layer, by the controller's whitelist.
        self::assertSame(['SE0000108656'], array_column($rows, 'isin'));
    }

    public function testSearchAndFilterCombinesQSortAndFilterWithAndSemantics(): void
    {
        $this->insertInstrument('SE0000108656', 'Investor A', null, 'MC');
        $this->insertInstrument('SE0000222222', 'SSAB B', null, 'LC');
        $this->seedFor(self::ISIN, '2026-01-01', 1000); // Investor B, LC
        $this->seedFor('SE0000108656', '2026-01-01', 2000); // Investor A, MC
        $this->seedFor('SE0000222222', '2026-01-01', 3000); // SSAB B, LC

        // q="investor" matches both Investor A and Investor B; market=LC
        // narrows to just Investor B.
        $rows = $this->metrics->searchAndFilter(
            NormalizedRow::SOURCE_AVANZA,
            ['q' => 'investor', 'market' => 'LC'],
            DerivedMetricsRepository::SORT_COUNT,
        );

        self::assertSame([self::ISIN], array_column($rows, 'isin'));
    }

    public function testSearchAndFilterExcludesDelistedInstruments(): void
    {
        $this->insertInstrument('SE0000199999', 'Delisted AB', '2026-02-01');
        $this->seedFor('SE0000199999', '2026-01-01', 9000);

        $rows = $this->metrics->searchAndFilter(NormalizedRow::SOURCE_AVANZA, [], DerivedMetricsRepository::SORT_COUNT);

        self::assertSame([], $rows);
    }

    public function testSearchAndFilterNeverMergesTwoSourcesForTheSameIsin(): void
    {
        $this->seedFor(self::ISIN, '2026-01-01', 1000, NormalizedRow::SOURCE_AVANZA);
        $this->seedFor(self::ISIN, '2026-01-01', 500000, NormalizedRow::SOURCE_NORDNET);

        $rows = $this->metrics->searchAndFilter(NormalizedRow::SOURCE_AVANZA, [], DerivedMetricsRepository::SORT_COUNT);

        self::assertCount(1, $rows);
        self::assertSame(1000, (int) $rows[0]['number_of_owners']);
    }

    public function testSearchAndFilterReturnsEmptyArrayWhenNothingMatches(): void
    {
        $rows = $this->metrics->searchAndFilter(NormalizedRow::SOURCE_AVANZA, [], DerivedMetricsRepository::SORT_COUNT);

        self::assertSame([], $rows);
    }

    // -- recentSeriesForIsins() -------------------------------------------------

    public function testRecentSeriesForIsinsReturnsEachIsinsSeriesKeyedByIsinOldestFirst(): void
    {
        $this->insertInstrument('SE0000108656', 'Atlas Copco A');

        foreach ([1000, 1010, 1020] as $i => $v) {
            $this->seedFor(self::ISIN, sprintf('2026-09-%02d', $i + 1), $v);
        }
        foreach ([2000, 2010] as $i => $v) {
            $this->seedFor('SE0000108656', sprintf('2026-09-%02d', $i + 1), $v);
        }

        $series = $this->metrics->recentSeriesForIsins([self::ISIN, 'SE0000108656'], NormalizedRow::SOURCE_AVANZA, 30);

        self::assertSame([self::ISIN, 'SE0000108656'], array_keys($series));
        self::assertCount(3, $series[self::ISIN]);
        self::assertSame('2026-09-01', $series[self::ISIN][0]['as_of_date']);
        self::assertSame('2026-09-03', $series[self::ISIN][2]['as_of_date']);
        self::assertCount(2, $series['SE0000108656']);
        self::assertSame(2010, $series['SE0000108656'][1]['number_of_owners']);
    }

    public function testRecentSeriesForIsinsCapsAtTheRequestedWindowPerIsinKeepingTheMostRecentDaysOldestFirst(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->seed(sprintf('2026-10-%02d', $i + 1), 1000 + $i);
        }

        $series = $this->metrics->recentSeriesForIsins([self::ISIN], NormalizedRow::SOURCE_AVANZA, 3);

        self::assertCount(3, $series[self::ISIN]);
        self::assertSame(['2026-10-08', '2026-10-09', '2026-10-10'], array_column($series[self::ISIN], 'as_of_date'));
    }

    public function testRecentSeriesForIsinsIncludesAnEmptyListForAnIsinWithNoStoredRows(): void
    {
        $this->insertInstrument('SE0000108656', 'Atlas Copco A');
        $this->seedFor(self::ISIN, '2026-01-01', 1000);
        // SE0000108656 has no owner_count_daily rows at all.

        $series = $this->metrics->recentSeriesForIsins([self::ISIN, 'SE0000108656'], NormalizedRow::SOURCE_AVANZA, 30);

        self::assertSame([self::ISIN, 'SE0000108656'], array_keys($series));
        self::assertCount(1, $series[self::ISIN]);
        self::assertSame([], $series['SE0000108656']);
    }

    public function testRecentSeriesForIsinsNeverMergesTwoSourcesForTheSameIsin(): void
    {
        $this->seed('2026-01-01', 1000, NormalizedRow::SOURCE_AVANZA);
        $this->seed('2026-01-01', 500000, NormalizedRow::SOURCE_NORDNET);

        $series = $this->metrics->recentSeriesForIsins([self::ISIN], NormalizedRow::SOURCE_AVANZA, 30);

        self::assertSame([['as_of_date' => '2026-01-01', 'number_of_owners' => 1000]], $series[self::ISIN]);
    }

    public function testRecentSeriesForIsinsReturnsEmptyArrayForAnEmptyIsinsList(): void
    {
        $series = $this->metrics->recentSeriesForIsins([], NormalizedRow::SOURCE_AVANZA, 30);

        self::assertSame([], $series);
    }

    // -- latestOwnerCountForIsins() (spec-5-4) -----------------------------------

    public function testLatestOwnerCountForIsinsReturnsEachIsinsLatestCountKeyedByIsin(): void
    {
        $this->insertInstrument('SE0000108656', 'Atlas Copco A');

        foreach ([1000, 1010, 1020] as $i => $v) {
            $this->seedFor(self::ISIN, sprintf('2026-09-%02d', $i + 1), $v, NormalizedRow::SOURCE_NORDNET);
        }
        $this->seedFor('SE0000108656', '2026-09-01', 500, NormalizedRow::SOURCE_NORDNET);

        $result = $this->metrics->latestOwnerCountForIsins(
            [self::ISIN, 'SE0000108656'],
            NormalizedRow::SOURCE_NORDNET,
        );

        // Latest by as_of_date, not the row insertion order or a historical peak.
        self::assertSame(1020, $result[self::ISIN]);
        self::assertSame(500, $result['SE0000108656']);
    }

    public function testLatestOwnerCountForIsinsOmitsAnIsinWithNoStoredRowsForTheSource(): void
    {
        $this->insertInstrument('SE0000108656', 'Atlas Copco A');
        $this->seedFor(self::ISIN, '2026-01-01', 1000, NormalizedRow::SOURCE_NORDNET);
        // SE0000108656 has no owner_count_daily rows for Nordnet at all.

        $result = $this->metrics->latestOwnerCountForIsins(
            [self::ISIN, 'SE0000108656'],
            NormalizedRow::SOURCE_NORDNET,
        );

        self::assertSame([self::ISIN => 1000], $result);
        self::assertArrayNotHasKey('SE0000108656', $result, 'a missing isin must be absent, not padded with 0/null');
    }

    public function testLatestOwnerCountForIsinsReturnsEmptyArrayForAnEmptyIsinsList(): void
    {
        $result = $this->metrics->latestOwnerCountForIsins([], NormalizedRow::SOURCE_NORDNET);

        self::assertSame([], $result);
    }

    public function testLatestOwnerCountForIsinsNeverMergesTwoSourcesForTheSameIsin(): void
    {
        $this->seed('2026-01-01', 1000, NormalizedRow::SOURCE_AVANZA);
        $this->seed('2026-01-01', 500000, NormalizedRow::SOURCE_NORDNET);

        $result = $this->metrics->latestOwnerCountForIsins([self::ISIN], NormalizedRow::SOURCE_AVANZA);

        self::assertSame([self::ISIN => 1000], $result);
    }
}
