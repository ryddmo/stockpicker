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

    private function insertInstrument(string $isin, string $name, ?string $lastSeen = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO instrument (isin, name, list, first_seen, last_seen)
             VALUES (:isin, :name, \'LC\', \'2026-01-01\', :last_seen)'
        );
        $stmt->execute(['isin' => $isin, 'name' => $name, 'last_seen' => $lastSeen]);
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
}
