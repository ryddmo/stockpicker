<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Store;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
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

        // spec-5-5: pct_7d/pct_90d/pct_365d (and spec-calendar-period-
        // metrics' pct_30d) added to the same NULL-on-first-row assertion
        // loop as the original seven.
        foreach (['delta_1d', 'pct_1d', 'pct_7d', 'pct_30d', 'pct_90d', 'pct_365d', 'sma_7', 'sma_30', 'sma_90', 'up_streak', 'spike_score'] as $field) {
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

    // -- spec-calendar-period-metrics: 1-day change vs previous trading day --

    public function testMondayDeltaComparesAgainstFriday(): void
    {
        $this->seed('2026-09-25', 1000); // Fri
        $this->seed('2026-09-28', 1012); // Mon

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);
        $monday = $rows[1];

        self::assertSame(12, (int) $monday['delta_1d']);
        self::assertEqualsWithDelta(0.012, (float) $monday['pct_1d'], 0.0000001);
    }

    public function testPostEasterTuesdayDeltaComparesAgainstMaundyThursday(): void
    {
        $this->seed('2026-04-02', 1000); // Thu before Good Friday
        $this->seed('2026-04-07', 990);  // Tue after Easter Monday (5 days)

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);
        $tuesday = $rows[1];

        self::assertSame(-10, (int) $tuesday['delta_1d']);
        self::assertEqualsWithDelta(-0.01, (float) $tuesday['pct_1d'], 0.0000001);
    }

    public function testLongGapNullsDeltaAndPctButNotRowBasedMetrics(): void
    {
        $values = [1000, 1010, 1020, 1030, 1040, 1050];
        foreach ($values as $i => $v) {
            $this->seed(sprintf('2026-04-%02d', $i + 1), $v);
        }
        $this->seed('2026-04-14', 1060); // 8 days after 2026-04-06
        $this->seed('2026-04-20', 1070); // 6 days after 2026-04-14 -- one past the tolerance

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);
        self::assertCount(8, $rows);
        $afterGap = $rows[6];

        self::assertSame('2026-04-14', $afterGap['as_of_date']);
        self::assertNull($afterGap['delta_1d'], 'delta_1d must be NULL when the previous row is 8 days back');
        self::assertNull($afterGap['pct_1d'], 'pct_1d must be NULL when the previous row is 8 days back');
        self::assertNull($rows[7]['delta_1d'], 'a 6-day gap is past the 5-day tolerance');

        // Row-based metrics stay unaffected by the gap.
        self::assertEqualsWithDelta(1030.0, (float) $afterGap['sma_7'], 0.0001, 'sma_7 across the gap');
        self::assertSame(6, (int) $afterGap['up_streak'], 'raw_delta is still positive across the gap');
    }

    // -- spec-calendar-period-metrics: pct_Nd vs latest row <= d - N days ---

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
        // Not enough calendar-days-back history yet for the longer windows.
        self::assertNull($last['pct_30d']);
        self::assertNull($last['pct_90d']);
        self::assertNull($last['pct_365d']);
    }

    public function testPct7dIsNullWhenHistoryIsShorterThanSevenDays(): void
    {
        $values = [1000, 1010, 1020];
        foreach ($values as $i => $v) {
            $this->seed(sprintf('2026-02-%02d', $i + 1), $v);
        }

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);
        $last = $rows[count($rows) - 1];

        self::assertNull($last['pct_7d'], 'no row on or before d - 7 yet');
    }

    public function testPct7dOnAWednesdayAfterAWednesdayClosureUsesTheTuesdayBefore(): void
    {
        // Matrix "Vecka on a Wednesday after Wed holiday": d = Wed
        // 2026-01-07, d - 7 = Wed 2025-12-31 (New Year's Eve, exchange
        // closed) not stored, d - 8 = Tue 2025-12-30 stored -> pct_7d
        // compares against Tue.
        $this->seed('2025-12-29', 900);  // Mon
        $this->seed('2025-12-30', 1000); // Tue
        // Wed 2025-12-31 and Thu 2026-01-01 closed
        $this->seed('2026-01-02', 1020); // Fri
        $this->seed('2026-01-05', 1030); // Mon
        // Tue 2026-01-06 (Epiphany) closed
        $this->seed('2026-01-07', 1050); // Wed

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);
        $last = $rows[count($rows) - 1];

        self::assertSame('2026-01-07', $last['as_of_date']);
        self::assertEqualsWithDelta((1050 - 1000) / 1000, (float) $last['pct_7d'], 0.0000001, 'pct_7d vs d - 8');
    }

    /**
     * @return array<string, array{string, int, int, bool}>
     */
    public static function periodAnchorCases(): array
    {
        $cases = [];
        foreach (['pct_30d' => 30, 'pct_90d' => 90, 'pct_365d' => 365] as $field => $n) {
            $cases["{$field} anchor 3 days before d-N"] = [$field, $n, $n + 3, false];
            $cases["{$field} anchor at N+5 kept"] = [$field, $n, $n + 5, false];
            $cases["{$field} anchor at N+6 NULL"] = [$field, $n, $n + 6, true];
        }

        return $cases;
    }

    /**
     * The anchor is the latest row on or before d - N (a row just after
     * d - N is ignored), and it counts only within N + 5 days.
     */
    #[DataProvider('periodAnchorCases')]
    public function testPeriodAnchorIsTheLatestRowOnOrBeforeDMinusNWithinNPlusFiveDays(
        string $field,
        int $n,
        int $anchorDaysBack,
        bool $expectNull,
    ): void {
        $d = new DateTimeImmutable('2026-06-10');
        $this->seed($d->modify("-{$anchorDaysBack} days")->format('Y-m-d'), 1000);
        // Decoy one day *after* d - N: must never be used as the anchor.
        $this->seed($d->modify('-' . ($n - 1) . ' days')->format('Y-m-d'), 5000);
        $this->seed($d->format('Y-m-d'), 1100);

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);
        $last = $rows[count($rows) - 1];

        self::assertSame('2026-06-10', $last['as_of_date']);
        if ($expectNull) {
            self::assertNull($last[$field], "{$field}: anchor {$anchorDaysBack} days back is past N + 5");
        } else {
            self::assertNotNull($last[$field], "{$field}: anchor {$anchorDaysBack} days back is within N + 5");
            self::assertEqualsWithDelta(0.1, (float) $last[$field], 0.0000001, $field);
        }
    }

    public function testPeriodToleranceIsNPlusFiveDaysInclusive(): void
    {
        $this->seed('2026-03-01', 1000);
        // d - 7 = 2026-03-06; nearest row on or before it is 03-01, 12 days
        // back = 7 + 5 -> kept.
        $this->seed('2026-03-13', 1100);
        // d - 7 = 2026-03-20; nearest row on or before it is 03-13, 14 days
        // back > 7 + 5 -> NULL.
        $this->seed('2026-03-27', 1200);

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);

        self::assertEqualsWithDelta(0.1, (float) $rows[1]['pct_7d'], 0.0000001, '12 days back is within 7 + 5');
        self::assertNull($rows[2]['pct_7d'], '14 days back is past 7 + 5');
    }

    public function testShortHistorySetsPct7dButNullsTheLongerPeriods(): void
    {
        // Matrix "Short history": first row 21 days before the last.
        $this->seed('2026-06-01', 1000); // Mon
        $this->seed('2026-06-12', 1100); // Fri
        $this->seed('2026-06-19', 1210); // Fri
        $this->seed('2026-06-22', 1250); // Mon

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);
        $last = $rows[count($rows) - 1];

        self::assertEqualsWithDelta((1250 - 1100) / 1100, (float) $last['pct_7d'], 0.0000001, 'pct_7d vs Fri 06-12 (10 days back)');
        self::assertNull($last['pct_30d']);
        self::assertNull($last['pct_90d']);
        self::assertNull($last['pct_365d']);
    }

    public function testStaleAnchorNullsPct30d(): void
    {
        // Matrix "Stale anchor": the nearest row on or before d - 30 is 40
        // days back -> past 30 + 5 -> NULL, not a misleading 40-day change.
        $this->seed('2026-05-01', 1000);
        $this->seed('2026-06-10', 1400); // 40 days later; d - 30 = 2026-05-11

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);
        $last = $rows[1];

        self::assertNull($last['pct_30d']);
        self::assertNull($last['delta_1d'], '40 days is also far past the 1-day tolerance');
    }

    public function testMonToFriHistorySpanningAYearPopulatesAllFourPeriodsWithTheCorrectValues(): void
    {
        // Acceptance: Mon–Fri data spanning >= 1 year -> all four set.
        $date = new DateTimeImmutable('2025-06-02'); // Mon
        $ownersByDate = [];
        for ($i = 0; $i < 300; ++$i) {
            while ((int) $date->format('N') >= 6) {
                $date = $date->modify('+1 day');
            }
            $ownersByDate[$date->format('Y-m-d')] = 1000 + $i;
            $this->seed($date->format('Y-m-d'), 1000 + $i);
            $date = $date->modify('+1 day');
        }

        $rows = $this->metrics->forIsinAndSource(self::ISIN, NormalizedRow::SOURCE_AVANZA);
        $last = $rows[count($rows) - 1];
        $lastDate = new DateTimeImmutable((string) $last['as_of_date']);
        $lastOwners = (int) $last['number_of_owners'];

        foreach (['pct_7d' => 7, 'pct_30d' => 30, 'pct_90d' => 90, 'pct_365d' => 365] as $field => $n) {
            // Expected anchor: the latest stored date on or before d - N.
            $anchor = $lastDate->modify("-{$n} days");
            while (!isset($ownersByDate[$anchor->format('Y-m-d')]) && $anchor->format('Y-m-d') >= '2025-06-02') {
                $anchor = $anchor->modify('-1 day');
            }
            self::assertArrayHasKey($anchor->format('Y-m-d'), $ownersByDate, "{$field}: fixture must contain an anchor on or before d - {$n}");
            $anchorOwners = $ownersByDate[$anchor->format('Y-m-d')];

            self::assertNotNull($last[$field], "{$field} must be populated");
            self::assertEqualsWithDelta(
                ($lastOwners - $anchorOwners) / $anchorOwners,
                (float) $last[$field],
                0.0000001,
                $field,
            );
        }

        // Every Monday in the series has a Fri-based delta_1d.
        foreach ($rows as $row) {
            if ($row['as_of_date'] !== '2025-06-02' && (new DateTimeImmutable((string) $row['as_of_date']))->format('N') === '1') {
                self::assertSame(1, (int) $row['delta_1d'], "Monday {$row['as_of_date']} delta_1d");
            }
        }
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
        // reusing anchor_owners_7 while leaving the N + 5 tolerance guard
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
        self::assertEqualsWithDelta((1180 - 1120) / 1120, (float) $last['pct_30d'], 0.0000001, 'pct_30d');
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

    public function testEveryTopRankingSelectsAllFourPeriodPercentages(): void
    {
        // spec-calendar-period-metrics: pct_30d joined the Topplista (and
        // digest) column lists alongside pct_7d/pct_90d/pct_365d.
        $this->seed('2026-01-01', 1000);
        $this->seed('2026-01-02', 1010);

        $results = [
            'topByOwnerCount' => $this->metrics->topByOwnerCount(NormalizedRow::SOURCE_AVANZA, 10),
            'topByTrendQuality' => $this->metrics->topByTrendQuality(NormalizedRow::SOURCE_AVANZA, 10),
            'topByOwnerCountAsOf' => $this->metrics->topByOwnerCountAsOf(NormalizedRow::SOURCE_AVANZA, '2026-01-02', 10),
            'topByTrendQualityAsOf' => $this->metrics->topByTrendQualityAsOf(NormalizedRow::SOURCE_AVANZA, '2026-01-02', 10),
        ];

        foreach ($results as $method => $rows) {
            self::assertNotEmpty($rows, $method);
            foreach (['pct_7d', 'pct_30d', 'pct_90d', 'pct_365d'] as $field) {
                self::assertArrayHasKey($field, $rows[0], "{$method} must select {$field}");
            }
        }
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

    // -- topByOwnerCountAsOf() (spec-5-6) ----------------------------------------

    public function testTopByOwnerCountAsOfReturnsTheRowForThePinnedDateOnly(): void
    {
        $this->insertInstrument('SE0000108656', 'Atlas Copco A');

        $this->seedFor(self::ISIN, '2026-01-01', 1000);
        $this->seedFor(self::ISIN, '2026-01-02', 1500); // a later day: must not leak in
        $this->seedFor('SE0000108656', '2026-01-01', 5000);
        $this->seedFor('SE0000108656', '2026-01-02', 6000);

        $top = $this->metrics->topByOwnerCountAsOf(NormalizedRow::SOURCE_AVANZA, '2026-01-01', 10);

        self::assertCount(2, $top);
        self::assertSame('SE0000108656', $top[0]['isin']);
        self::assertSame(5000, (int) $top[0]['number_of_owners']);
        self::assertSame(self::ISIN, $top[1]['isin']);
        self::assertSame(1000, (int) $top[1]['number_of_owners']);
    }

    public function testTopByOwnerCountAsOfIsEmptyWhenNoDataExistsForThatDate(): void
    {
        $this->seedFor(self::ISIN, '2026-01-01', 1000);

        $top = $this->metrics->topByOwnerCountAsOf(NormalizedRow::SOURCE_AVANZA, '2026-01-02', 10);

        self::assertSame([], $top);
    }

    public function testTopByOwnerCountAsOfExcludesDelistedInstruments(): void
    {
        $this->insertInstrument('SE0000199999', 'Delisted AB', '2026-02-01');
        $this->seedFor('SE0000199999', '2026-01-01', 9000);
        $this->seedFor(self::ISIN, '2026-01-01', 1000);

        $top = $this->metrics->topByOwnerCountAsOf(NormalizedRow::SOURCE_AVANZA, '2026-01-01', 10);

        self::assertCount(1, $top, 'the delisted instrument must never appear');
        self::assertSame(self::ISIN, $top[0]['isin']);
    }

    public function testTopByOwnerCountAsOfNeverMergesTwoSourcesForTheSameIsin(): void
    {
        $this->seedFor(self::ISIN, '2026-01-01', 1000, NormalizedRow::SOURCE_AVANZA);
        $this->seedFor(self::ISIN, '2026-01-01', 500000, NormalizedRow::SOURCE_NORDNET);

        $top = $this->metrics->topByOwnerCountAsOf(NormalizedRow::SOURCE_AVANZA, '2026-01-01', 10);

        self::assertCount(1, $top);
        self::assertSame(1000, (int) $top[0]['number_of_owners']);
    }

    public function testTopByOwnerCountAsOfRespectsTheLimit(): void
    {
        $this->insertInstrument('SE0000108656', 'Atlas Copco A');
        $this->insertInstrument('SE0000222222', 'SSAB B');

        $this->seedFor(self::ISIN, '2026-01-01', 1000);
        $this->seedFor('SE0000108656', '2026-01-01', 2000);
        $this->seedFor('SE0000222222', '2026-01-01', 3000);

        $top = $this->metrics->topByOwnerCountAsOf(NormalizedRow::SOURCE_AVANZA, '2026-01-01', 2);

        self::assertCount(2, $top);
        self::assertSame('SE0000222222', $top[0]['isin']);
        self::assertSame('SE0000108656', $top[1]['isin']);
    }

    public function testTopByOwnerCountAsOfBreaksATieByIsinAscending(): void
    {
        // self::ISIN is 'SE0015811963'; this one sorts before it alphabetically.
        $this->insertInstrument('SE0000108656', 'Atlas Copco A');

        $this->seedFor(self::ISIN, '2026-01-01', 1000);
        $this->seedFor('SE0000108656', '2026-01-01', 1000);

        $top = $this->metrics->topByOwnerCountAsOf(NormalizedRow::SOURCE_AVANZA, '2026-01-01', 10);

        self::assertCount(2, $top);
        self::assertSame('SE0000108656', $top[0]['isin'], 'tied number_of_owners must break by isin ASC');
        self::assertSame(self::ISIN, $top[1]['isin']);
    }

    // -- topByTrendQualityAsOf() (spec-5-6) --------------------------------------

    public function testTopByTrendQualityAsOfReturnsTheRowForThePinnedDateOnly(): void
    {
        $this->insertInstrument('SE0000108656', 'Atlas Copco A');

        // self::ISIN: qualifies (up_streak >= 1) on 2026-04-02, but the run
        // continues into 2026-04-03 with another up day. Pinning the query
        // to 2026-04-02 must return that day's up_streak (1), not the later
        // day's (2).
        $this->seedFor(self::ISIN, '2026-04-01', 1000);
        $this->seedFor(self::ISIN, '2026-04-02', 1010);
        $this->seedFor(self::ISIN, '2026-04-03', 1020);

        $this->seedFor('SE0000108656', '2026-04-01', 2000);
        $this->seedFor('SE0000108656', '2026-04-02', 1900); // down day: no streak on this date

        $top = $this->metrics->topByTrendQualityAsOf(NormalizedRow::SOURCE_AVANZA, '2026-04-02', 10);

        self::assertCount(1, $top, 'only the qualifying isin on this exact date, not the later day\'s state');
        self::assertSame(self::ISIN, $top[0]['isin']);
        self::assertSame(1, (int) $top[0]['up_streak']);
    }

    public function testTopByTrendQualityAsOfIsEmptyWhenNoDataExistsForThatDate(): void
    {
        $this->seed('2026-01-01', 1000);
        $this->seed('2026-01-02', 1010);

        $top = $this->metrics->topByTrendQualityAsOf(NormalizedRow::SOURCE_AVANZA, '2026-01-05', 10);

        self::assertSame([], $top);
    }

    public function testTopByTrendQualityAsOfExcludesASpikingRowOnThatExactDate(): void
    {
        $start = new DateTimeImmutable('2026-03-01');
        for ($i = 0; $i < 29; ++$i) {
            $this->seedFor(self::ISIN, $start->modify("+{$i} days")->format('Y-m-d'), 1000 + $i * 10);
        }
        $spikeDate = $start->modify('+29 days')->format('Y-m-d');
        $this->seedFor(self::ISIN, $spikeDate, 1280 + 5000);

        $top = $this->metrics->topByTrendQualityAsOf(NormalizedRow::SOURCE_AVANZA, $spikeDate, 10);

        self::assertSame([], $top, 'a spiking row must never qualify, even pinned to its own date');
    }

    public function testTopByTrendQualityAsOfExcludesFlatOrNoStreakInstrumentsOnThatDate(): void
    {
        $this->seed('2026-06-01', 1000);
        $this->seed('2026-06-02', 990); // down day -> up_streak 0 on this date

        $top = $this->metrics->topByTrendQualityAsOf(NormalizedRow::SOURCE_AVANZA, '2026-06-02', 10);

        self::assertSame([], $top);
    }

    public function testTopByTrendQualityAsOfBreaksATieByIsinAscending(): void
    {
        // self::ISIN is 'SE0015811963'; this one sorts before it alphabetically.
        $this->insertInstrument('SE0000108656', 'Atlas Copco A');

        $this->seedFor(self::ISIN, '2026-07-01', 1000);
        $this->seedFor(self::ISIN, '2026-07-02', 1010); // up_streak 1

        $this->seedFor('SE0000108656', '2026-07-01', 2000);
        $this->seedFor('SE0000108656', '2026-07-02', 2010); // up_streak 1, tied

        $top = $this->metrics->topByTrendQualityAsOf(NormalizedRow::SOURCE_AVANZA, '2026-07-02', 10);

        self::assertCount(2, $top);
        self::assertSame('SE0000108656', $top[0]['isin'], 'tied up_streak must break by isin ASC');
        self::assertSame(self::ISIN, $top[1]['isin']);
    }

    // -- spec-plusdagar: topByPlusDays() / historySpansDays() ------------------

    private const PD_D = '2026-10-01'; // Thu — the source's latest as_of_date

    /**
     * Every Mon–Fri calendar date in [$start, $end], ascending.
     *
     * @return list<string>
     */
    private static function weekdaysBetween(string $start, string $end): array
    {
        $out = [];
        $d = new DateTimeImmutable($start);
        $last = new DateTimeImmutable($end);
        while ($d <= $last) {
            if ((int) $d->format('N') <= 5) {
                $out[] = $d->format('Y-m-d');
            }
            $d = $d->modify('+1 day');
        }

        return $out;
    }

    /**
     * @param array<string, int> $series as_of_date => owners
     */
    private function seedSeries(string $isin, array $series, string $source = NormalizedRow::SOURCE_AVANZA): void
    {
        foreach ($series as $date => $owners) {
            $this->seedFor($isin, $date, $owners, $source);
        }
    }

    /**
     * Mon–Fri series 2026-08-25 .. PD_D, each day's owners = previous +
     * $step, except dates in $overrides which take an explicit delta.
     *
     * @param array<string, int> $overrides date => delta for that day
     *
     * @return array<string, int>
     */
    private static function monthSeries(int $base, int $step, array $overrides = []): array
    {
        $out = [];
        $v = $base;
        foreach (self::weekdaysBetween('2026-08-25', self::PD_D) as $i => $date) {
            if ($i > 0) {
                $v += $overrides[$date] ?? $step;
            }
            $out[$date] = $v;
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $rows */
    private static function rowFor(array $rows, string $isin): ?array
    {
        foreach ($rows as $row) {
            if ($row['isin'] === $isin) {
                return $row;
            }
        }

        return null;
    }

    public function testTopByPlusDaysRanksAMonthOfGainsWithOneBadDayAndCountsTheDenominator(): void
    {
        $this->insertInstrument('SE0000000101', 'Alpha AB');
        $series = self::monthSeries(10000, 100, ['2026-09-15' => -50]);
        $this->seedSeries('SE0000000101', $series);

        $rows = $this->metrics->topByPlusDays(NormalizedRow::SOURCE_AVANZA, 30, false, 10);

        $windowDays = count(array_filter(array_keys($series), static fn (string $d): bool => $d > '2026-09-01'));
        self::assertCount(1, $rows);
        self::assertSame('SE0000000101', $rows[0]['isin']);
        self::assertSame($windowDays, $rows[0]['data_days']);
        self::assertSame($windowDays - 1, $rows[0]['plus_days'], 'one down day costs exactly one plus day');
        self::assertSame($series[self::PD_D] - $series['2026-09-01'], $rows[0]['new_owners'], 'baseline is the latest row on or before D − 30');
        // renderRow()'s latest-metrics columns are still present.
        self::assertSame('Alpha AB', $rows[0]['name']);
        self::assertSame(self::PD_D, (string) $rows[0]['as_of_date']);
        self::assertArrayHasKey('pct_30d', $rows[0]);
        self::assertArrayHasKey('up_streak', $rows[0]);
    }

    public function testTopByPlusDaysCountsFlatDaysAsPlusDays(): void
    {
        $this->insertInstrument('SE0000000101', 'Alpha AB');
        // All flat except one +5 at the end -> net +5, every data day a plus day.
        $series = self::monthSeries(500, 0, [self::PD_D => 5]);
        $this->seedSeries('SE0000000101', $series);

        $rows = $this->metrics->topByPlusDays(NormalizedRow::SOURCE_AVANZA, 30, false, 10);

        self::assertCount(1, $rows);
        self::assertSame($rows[0]['data_days'], $rows[0]['plus_days']);
        self::assertSame(5, $rows[0]['new_owners']);
    }

    public function testTopByPlusDaysNeverRanksAFlatAllMonthOrNetNegativeInstrument(): void
    {
        $this->insertInstrument('SE0000000101', 'Flat AB');
        $this->insertInstrument('SE0000000102', 'Minus AB');
        $this->insertInstrument('SE0000000103', 'Plus AB');
        $this->seedSeries('SE0000000101', self::monthSeries(500, 0));
        $this->seedSeries('SE0000000102', self::monthSeries(500, 0, ['2026-09-20' => 0, '2026-09-22' => -1]));
        $this->seedSeries('SE0000000103', self::monthSeries(500, 1));

        $rows = $this->metrics->topByPlusDays(NormalizedRow::SOURCE_AVANZA, 30, false, 10);

        self::assertSame(['SE0000000103'], array_column($rows, 'isin'));
    }

    public function testTopByPlusDaysReturnsEmptyWhenNothingHasNetNewOwners(): void
    {
        $this->insertInstrument('SE0000000101', 'Minus AB');
        $this->seedSeries('SE0000000101', self::monthSeries(5000, -3));

        self::assertSame([], $this->metrics->topByPlusDays(NormalizedRow::SOURCE_AVANZA, 30, false, 10));
        self::assertSame([], $this->metrics->topByPlusDays(NormalizedRow::SOURCE_AVANZA, 7, false, 10));
    }

    public function testTopByPlusDaysBreaksAPlusDaysTieByNewOwnersThenIsin(): void
    {
        $this->insertInstrument('SE0000000103', 'Small AB');
        $this->insertInstrument('SE0000000102', 'Large B AB');
        $this->insertInstrument('SE0000000101', 'Large A AB');
        // Vecka: window (09-24, 10-01] -> Fri 25, Mon 28, Tue 29, Wed 30, Thu 1 = 5/5 for all.
        $this->seedSeries('SE0000000103', self::monthSeries(100, 1));
        $this->seedSeries('SE0000000102', self::monthSeries(100000, 50));
        $this->seedSeries('SE0000000101', self::monthSeries(200000, 50));

        $rows = $this->metrics->topByPlusDays(NormalizedRow::SOURCE_AVANZA, 7, false, 10);

        self::assertSame(['SE0000000101', 'SE0000000102', 'SE0000000103'], array_column($rows, 'isin'));
        foreach ($rows as $row) {
            self::assertSame(5, $row['plus_days']);
            self::assertSame(5, $row['data_days']);
        }
        self::assertSame(250, $rows[0]['new_owners']);
        self::assertSame(5, $rows[2]['new_owners']);
    }

    public function testTopByPlusDaysRanksByRawCountNotShareAndTiesAcrossDifferentDenominators(): void
    {
        $this->insertInstrument('SE0000000101', 'Daily AB');
        $this->insertInstrument('SE0000000102', 'Weekday AB');
        $this->insertInstrument('SE0000000103', 'Weekday Big AB');

        // Daily AB stores every calendar day: Vecka window has 7 data days,
        // 5 up and 2 down, net +10.
        $this->seedSeries('SE0000000101', [
            '2026-09-24' => 1000,
            '2026-09-25' => 1010, '2026-09-26' => 1005, '2026-09-27' => 1006,
            '2026-09-28' => 1001, '2026-09-29' => 1002, '2026-09-30' => 1003, '2026-10-01' => 1010,
        ]);
        // Weekday AB: 5/5, net +100 -> ties Daily AB on 5 plus days, wins on new owners.
        $this->seedSeries('SE0000000102', [
            '2026-09-24' => 1000, '2026-09-25' => 1020, '2026-09-28' => 1040,
            '2026-09-29' => 1060, '2026-09-30' => 1080, '2026-10-01' => 1100,
        ]);
        // Weekday Big AB: 4/5 (one down day), net +5000 -> below both 5s.
        $this->seedSeries('SE0000000103', [
            '2026-09-24' => 1000, '2026-09-25' => 3000, '2026-09-28' => 2999,
            '2026-09-29' => 4000, '2026-09-30' => 5000, '2026-10-01' => 6000,
        ]);

        $rows = $this->metrics->topByPlusDays(NormalizedRow::SOURCE_AVANZA, 7, false, 10);

        self::assertSame(['SE0000000102', 'SE0000000101', 'SE0000000103'], array_column($rows, 'isin'));
        self::assertSame([5, 5, 4], array_column($rows, 'plus_days'));
        self::assertSame([5, 7, 5], array_column($rows, 'data_days'));
        self::assertSame([100, 10, 5000], array_column($rows, 'new_owners'));
    }

    public function testTopByPlusDaysRanksANewListingFromItsFirstRowInTheWindow(): void
    {
        $this->insertInstrument('SE0000000101', 'Veteran AB');
        $this->insertInstrument('SE0000000102', 'Dormy AB');
        $this->seedSeries('SE0000000101', self::monthSeries(1000, 10));
        // Listed 2026-09-24: 6 rows, the first has no predecessor -> 5/5.
        $this->seedSeries('SE0000000102', [
            '2026-09-24' => 0, '2026-09-25' => 1500, '2026-09-28' => 2600,
            '2026-09-29' => 3300, '2026-09-30' => 4100, '2026-10-01' => 4602,
        ]);

        $rows = $this->metrics->topByPlusDays(NormalizedRow::SOURCE_AVANZA, 30, false, 10);

        $dormy = self::rowFor($rows, 'SE0000000102');
        self::assertNotNull($dormy, 'a new listing must be ranked');
        self::assertSame(5, $dormy['plus_days']);
        self::assertSame(5, $dormy['data_days']);
        self::assertSame(4602, $dormy['new_owners'], 'no row before the window -> baseline is the first row in it');
        self::assertSame('SE0000000101', $rows[0]['isin'], 'the veteran with more plus days ranks first');
        self::assertSame('SE0000000102', $rows[1]['isin']);
    }

    public function testTopByPlusDaysDoesNotCountARowWhosePredecessorIsMoreThanFiveDaysBack(): void
    {
        $this->insertInstrument('SE0000000101', 'Gappy AB');
        $series = self::monthSeries(1000, 10);
        // Drop Fri 09-11 .. Thu 09-17: Fri 09-18's predecessor is Thu 09-10, 8 days back.
        foreach (array_keys($series) as $date) {
            if ($date >= '2026-09-11' && $date <= '2026-09-17') {
                unset($series[$date]);
            }
        }
        $series['2026-09-18'] -= 100; // a down move across the gap — must not count either way
        $this->seedSeries('SE0000000101', $series);

        $rows = $this->metrics->topByPlusDays(NormalizedRow::SOURCE_AVANZA, 30, false, 10);

        $windowRows = count(array_filter(array_keys($series), static fn (string $d): bool => $d > '2026-09-01'));
        self::assertCount(1, $rows);
        self::assertSame($windowRows - 1, $rows[0]['data_days'], 'the row after the 8-day gap is not a data day');
        self::assertSame($windowRows - 1, $rows[0]['plus_days'], 'and is not in the numerator either');
    }

    public function testTopByPlusDaysSpikeToggleExcludesOnlyASpikingLatestRow(): void
    {
        $this->insertInstrument('SE0000000101', 'Spiky AB');
        $this->insertInstrument('SE0000000102', 'Young AB');

        // Spiky AB: 29 calendar days of steady growth then a huge jump on D -> spike_score >= 2.
        $start = new DateTimeImmutable('2026-09-02');
        for ($i = 0; $i < 29; ++$i) {
            $this->seedFor('SE0000000101', $start->modify("+{$i} days")->format('Y-m-d'), 1000 + $i * 10);
        }
        $this->seedFor('SE0000000101', self::PD_D, 1280 + 5000);
        // Young AB: too few rows for a spike_score (NULL) -> never excluded.
        $this->seedSeries('SE0000000102', ['2026-09-30' => 100, '2026-10-01' => 110]);

        $spiky = $this->metrics->forIsinAndSource('SE0000000101', NormalizedRow::SOURCE_AVANZA);
        self::assertGreaterThanOrEqual(DerivedMetricsRepository::SPIKE_THRESHOLD, (float) end($spiky)['spike_score']);

        $included = $this->metrics->topByPlusDays(NormalizedRow::SOURCE_AVANZA, 7, false, 10);
        $excluded = $this->metrics->topByPlusDays(NormalizedRow::SOURCE_AVANZA, 7, true, 10);

        self::assertSame(['SE0000000101', 'SE0000000102'], array_column($included, 'isin'), 'spikes are included by default');
        self::assertSame(['SE0000000102'], array_column($excluded, 'isin'), 'a NULL spike_score is never excluded');
    }

    public function testTopByPlusDaysExcludesInactiveInstrumentsAndNeverMixesSources(): void
    {
        $this->insertInstrument('SE0000000101', 'Delisted AB', '2026-09-30');
        $this->insertInstrument('SE0000000102', 'Nordnet Only AB');
        $this->insertInstrument('SE0000000103', 'Avanza AB');
        $this->seedSeries('SE0000000101', self::monthSeries(1000, 10));
        $this->seedSeries('SE0000000102', self::monthSeries(1000, 10), NormalizedRow::SOURCE_NORDNET);
        $this->seedSeries('SE0000000103', self::monthSeries(1000, 1));
        // Nordnet rows for the Avanza instrument going down must not affect its Avanza ranking.
        $this->seedSeries('SE0000000103', self::monthSeries(900, -1), NormalizedRow::SOURCE_NORDNET);

        $avanza = $this->metrics->topByPlusDays(NormalizedRow::SOURCE_AVANZA, 30, false, 10);
        $nordnet = $this->metrics->topByPlusDays(NormalizedRow::SOURCE_NORDNET, 30, false, 10);

        self::assertSame(['SE0000000103'], array_column($avanza, 'isin'));
        self::assertSame($avanza[0]['data_days'], $avanza[0]['plus_days']);
        self::assertSame(['SE0000000102'], array_column($nordnet, 'isin'));
    }

    public function testTopByPlusDaysSkipsAnInstrumentWithNoRowInTheWindow(): void
    {
        $this->insertInstrument('SE0000000101', 'Stale AB');
        $this->insertInstrument('SE0000000102', 'Fresh AB');
        // Stale AB's last row is two weeks before D: nothing in the Vecka window.
        $this->seedSeries('SE0000000101', ['2026-09-10' => 100, '2026-09-17' => 500]);
        $this->seedSeries('SE0000000102', ['2026-09-30' => 100, '2026-10-01' => 110]);

        $rows = $this->metrics->topByPlusDays(NormalizedRow::SOURCE_AVANZA, 7, false, 10);

        self::assertSame(['SE0000000102'], array_column($rows, 'isin'));
    }

    public function testTopByPlusDaysHonoursTheLimit(): void
    {
        foreach (['SE0000000101', 'SE0000000102', 'SE0000000103'] as $i => $isin) {
            $this->insertInstrument($isin, "Bolag {$i}");
            $this->seedSeries($isin, self::monthSeries(1000, $i + 1));
        }

        self::assertCount(2, $this->metrics->topByPlusDays(NormalizedRow::SOURCE_AVANZA, 30, false, 2));
    }

    public function testHistorySpansDaysComparesTheSourcesEarliestRowAgainstItsLatestMinusN(): void
    {
        self::assertFalse($this->metrics->historySpansDays(NormalizedRow::SOURCE_AVANZA, 7), 'no rows at all');

        $this->insertInstrument('SE0000000101', 'Alpha AB');
        $this->seedSeries('SE0000000101', self::monthSeries(1000, 1)); // 2026-08-25 .. 2026-10-01 = 37 days

        self::assertTrue($this->metrics->historySpansDays(NormalizedRow::SOURCE_AVANZA, 7));
        self::assertTrue($this->metrics->historySpansDays(NormalizedRow::SOURCE_AVANZA, 30));
        self::assertTrue($this->metrics->historySpansDays(NormalizedRow::SOURCE_AVANZA, 37));
        self::assertFalse($this->metrics->historySpansDays(NormalizedRow::SOURCE_AVANZA, 38));
        self::assertFalse($this->metrics->historySpansDays(NormalizedRow::SOURCE_AVANZA, 90));
        self::assertFalse($this->metrics->historySpansDays(NormalizedRow::SOURCE_NORDNET, 7), 'per source');
    }
}
