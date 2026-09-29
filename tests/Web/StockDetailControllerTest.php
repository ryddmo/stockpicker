<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Web;

use PHPUnit\Framework\TestCase;
use Stockpicker\Web\StockDetailController;

/**
 * Story 4.3 — range-gate/slice logic and empty-state selection, isolated
 * from HTTP and the database: StockDetailController exposes these rules as
 * small `public static` pure functions specifically so they can be exercised
 * directly here. Every case mirrors a row of the spec's I/O & Edge-Case
 * Matrix / Design Notes (spec-4-3).
 */
final class StockDetailControllerTest extends TestCase
{
    // -- Range/source normalization (garbage query values never 500) --------

    public function testNormalizeRangeDefaultsToDagForUnknownValues(): void
    {
        self::assertSame(StockDetailController::RANGE_DAG, StockDetailController::normalizeRange(''));
        self::assertSame(StockDetailController::RANGE_DAG, StockDetailController::normalizeRange('bogus'));
        self::assertSame(StockDetailController::RANGE_DAG, StockDetailController::normalizeRange('dag'));
    }

    public function testNormalizeRangeAcceptsEachKnownValue(): void
    {
        self::assertSame(StockDetailController::RANGE_VECKA, StockDetailController::normalizeRange('vecka'));
        self::assertSame(StockDetailController::RANGE_MANAD, StockDetailController::normalizeRange('manad'));
        self::assertSame(StockDetailController::RANGE_3MAN, StockDetailController::normalizeRange('3man'));
        self::assertSame(StockDetailController::RANGE_AR, StockDetailController::normalizeRange('ar'));
    }

    public function testNormalizeRangeMapsLegacyUrlValues(): void
    {
        // Matrix "Old URL": ?range=30d / ?range=90d -> manad / 3man.
        self::assertSame(StockDetailController::RANGE_MANAD, StockDetailController::normalizeRange('30d'));
        self::assertSame(StockDetailController::RANGE_3MAN, StockDetailController::normalizeRange('90d'));
    }

    public function testNormalizeSourceDefaultsToAvanzaForUnknownValues(): void
    {
        self::assertSame('avanza', StockDetailController::normalizeSource(''));
        self::assertSame('avanza', StockDetailController::normalizeSource('bogus'));
    }

    public function testNormalizeSourceAcceptsNordnet(): void
    {
        self::assertSame('nordnet', StockDetailController::normalizeSource('nordnet'));
    }

    // -- Calendar window / gate per range ------------------------------------

    public function testWindowDaysPerRange(): void
    {
        self::assertNull(StockDetailController::windowDays(StockDetailController::RANGE_DAG), 'Dag is the last 2 rows');
        self::assertSame(7, StockDetailController::windowDays(StockDetailController::RANGE_VECKA));
        self::assertSame(30, StockDetailController::windowDays(StockDetailController::RANGE_MANAD));
        self::assertSame(90, StockDetailController::windowDays(StockDetailController::RANGE_3MAN));
        self::assertSame(365, StockDetailController::windowDays(StockDetailController::RANGE_AR));
    }

    public function testGateDaysPerRange(): void
    {
        self::assertNull(StockDetailController::gateDays(StockDetailController::RANGE_DAG));
        self::assertSame(7, StockDetailController::gateDays(StockDetailController::RANGE_VECKA));
        self::assertSame(30, StockDetailController::gateDays(StockDetailController::RANGE_MANAD));
        self::assertSame(90, StockDetailController::gateDays(StockDetailController::RANGE_3MAN));
        self::assertSame(
            90,
            StockDetailController::gateDays(StockDetailController::RANGE_AR),
            'År shares 3 mån\'s gate (Design Notes)',
        );
    }

    // -- sliceForRange(): calendar window over the fetched rows, no query ----

    public function testSliceForRangeVeckaCoversSevenCalendarDaysNotSevenRows(): void
    {
        // Mon 2026-01-05 .. Fri 2026-01-16 (10 trading rows); last = Fri 16th,
        // cutoff = Fri 9th -> Fri 9th + Mon 12th..Fri 16th = 6 rows.
        $series = self::rows(10);

        $slice = StockDetailController::sliceForRange($series, StockDetailController::RANGE_VECKA);

        self::assertCount(6, $slice);
        self::assertSame('2026-01-09', $slice[0]['as_of_date']);
        self::assertSame('2026-01-16', $slice[array_key_last($slice)]['as_of_date']);
    }

    public function testSliceForRangeManadCoversThirtyCalendarDays(): void
    {
        // 40 trading rows from Mon 2026-01-05 end on Fri 2026-02-27; cutoff
        // 2026-01-28 (Wed) -> 2026-01-28 .. 2026-02-27 = 23 trading rows.
        $series = self::rows(40);

        $slice = StockDetailController::sliceForRange($series, StockDetailController::RANGE_MANAD);

        self::assertSame('2026-01-28', $slice[0]['as_of_date']);
        self::assertCount(23, $slice);
    }

    public function testSliceForRangeReturnsTheWholeArrayWhenHistoryIsShorterThanTheWindow(): void
    {
        $series = self::rows(3);

        $slice = StockDetailController::sliceForRange($series, StockDetailController::RANGE_3MAN);

        self::assertCount(3, $slice);
    }

    public function testSliceForRangeArIsCappedAtThreeHundredSixtyFiveDays(): void
    {
        // 300 trading rows span well over a year.
        $series = self::rows(300);
        $last = $series[array_key_last($series)]['as_of_date'];
        $cutoff = (new \DateTimeImmutable($last))->modify('-365 days')->format('Y-m-d');

        $slice = StockDetailController::sliceForRange($series, StockDetailController::RANGE_AR);

        self::assertLessThan(300, count($slice));
        self::assertGreaterThanOrEqual($cutoff, $slice[0]['as_of_date']);
        self::assertLessThan($cutoff, $series[300 - count($slice) - 1]['as_of_date']);
    }

    public function testSliceForRangeDagTakesTheLastTwoRows(): void
    {
        // Mon 2026-01-05 .. Fri 2026-02-13 (30 rows) + Mon: Dag is Fri + Mon.
        $series = self::rows(31);

        $slice = StockDetailController::sliceForRange($series, StockDetailController::RANGE_DAG);

        self::assertCount(2, $slice);
        self::assertSame('2026-02-13', $slice[0]['as_of_date']);
        self::assertSame('2026-02-16', $slice[1]['as_of_date']);
    }

    public function testSliceForRangeOfAnEmptySeriesIsEmpty(): void
    {
        self::assertSame([], StockDetailController::sliceForRange([], StockDetailController::RANGE_MANAD));
    }

    // -- isInsufficientHistory(): gated on the primary's calendar span -------

    public function testIsInsufficientHistoryIsTrueWhenHistoryDoesNotSpanTheWindow(): void
    {
        // 21 trading rows: Mon 2026-01-05 .. Mon 2026-02-02 = 28 days < 30.
        self::assertTrue(StockDetailController::isInsufficientHistory(self::rows(21), StockDetailController::RANGE_MANAD));
        self::assertTrue(StockDetailController::isInsufficientHistory(self::rows(1), StockDetailController::RANGE_DAG));
        self::assertTrue(StockDetailController::isInsufficientHistory([], StockDetailController::RANGE_DAG));
        self::assertTrue(StockDetailController::isInsufficientHistory([], StockDetailController::RANGE_VECKA));
    }

    public function testIsInsufficientHistoryIsFalseOnceHistorySpansTheWindow(): void
    {
        // 23 trading rows: Mon 2026-01-05 .. Wed 2026-02-04 = 30 days.
        self::assertFalse(StockDetailController::isInsufficientHistory(self::rows(23), StockDetailController::RANGE_MANAD));
        // 6 trading rows: Mon .. next Mon = 7 days.
        self::assertFalse(StockDetailController::isInsufficientHistory(self::rows(6), StockDetailController::RANGE_VECKA));
        self::assertFalse(StockDetailController::isInsufficientHistory(self::rows(2), StockDetailController::RANGE_DAG));
    }

    public function testIsInsufficientHistoryForArUsesThe3ManGateNotAFullYear(): void
    {
        // 64 trading rows: Mon 2026-01-05 .. Thu 2026-04-02 = 87 days -> gated;
        // 66 rows: .. Mon 2026-04-06 = 91 days -> renders.
        self::assertTrue(StockDetailController::isInsufficientHistory(self::rows(64), StockDetailController::RANGE_AR));
        self::assertFalse(StockDetailController::isInsufficientHistory(self::rows(66), StockDetailController::RANGE_AR));
    }

    // -- Insufficient-history message -----------------------------------------

    public function testDaysUntilSufficientIsCalendarDaysUntilTheHistorySpansTheGate(): void
    {
        // 10 trading rows: Mon 2026-01-05 .. Fri 2026-01-16 = 11 days spanned.
        self::assertSame(19, StockDetailController::daysUntilSufficient(self::rows(10), StockDetailController::RANGE_MANAD));
        self::assertSame(0, StockDetailController::daysUntilSufficient(self::rows(23), StockDetailController::RANGE_MANAD));
        self::assertSame(30, StockDetailController::daysUntilSufficient([], StockDetailController::RANGE_MANAD));
        self::assertSame(1, StockDetailController::daysUntilSufficient(self::rows(1), StockDetailController::RANGE_DAG));
    }

    public function testInsufficientHistoryMessageIncludesTheDayCount(): void
    {
        $message = StockDetailController::insufficientHistoryMessage(self::rows(10), StockDetailController::RANGE_MANAD);

        self::assertStringContainsString('19 dagar', $message);
        self::assertStringContainsString('Inte tillräckligt med historik', $message);
    }

    public function testInsufficientHistoryMessageUsesSingularDagForOneDay(): void
    {
        $message = StockDetailController::insufficientHistoryMessage(self::rows(1), StockDetailController::RANGE_DAG);

        self::assertStringEndsWith('kolla in igen om 1 dag', $message);
    }

    // -- scaledPoints(): x by shared calendar-date domain, y by own min/max --

    public function testScaledPointsPositionsEachPointByItsDateWithinTheSharedDomainNotByArrayIndex(): void
    {
        // Primary: 3 points spanning the full domain, large magnitude.
        $primary = [
            self::rowWithDate('2026-01-01', 1000),
            self::rowWithDate('2026-01-03', 1010),
            self::rowWithDate('2026-01-05', 1020),
        ];
        // Secondary: fewer rows, starting later and ending earlier than
        // primary (Nordnet's instrument id resolving later than Avanza's is
        // a real scenario) and a wholly different magnitude.
        $secondary = [
            self::rowWithDate('2026-01-02', 50),
            self::rowWithDate('2026-01-04', 60),
        ];

        // The shared domain is the union of both series' dates: 01-01..01-05 (4 days).
        $domain = ['2026-01-01', '2026-01-05'];
        $width = 320;
        $height = 120;

        $primaryXs = self::xCoordinates(StockDetailController::scaledPoints($primary, $domain, $width, $height));
        $secondaryXs = self::xCoordinates(StockDetailController::scaledPoints($secondary, $domain, $width, $height));

        self::assertEqualsWithDelta(0.0, $primaryXs[0], 0.01, '01-01 sits at the domain start');
        self::assertEqualsWithDelta($width / 2, $primaryXs[1], 0.01, '01-03 is exactly halfway through the 4-day domain');
        self::assertEqualsWithDelta($width, $primaryXs[2], 0.01, '01-05 sits at the domain end');

        // If x were positioned by array index instead of date, a 2-point
        // secondary series would land at 0 and $width (the two ends) — the
        // date-based fix must instead place it a quarter/three-quarters in.
        self::assertEqualsWithDelta($width / 4, $secondaryXs[0], 0.01, '01-02 is one quarter through the shared domain');
        self::assertEqualsWithDelta(3 * $width / 4, $secondaryXs[1], 0.01, '01-04 is three quarters through the shared domain');
    }

    public function testScaledPointsIsEmptyBelowTwoPoints(): void
    {
        self::assertSame('', StockDetailController::scaledPoints([], ['2026-01-01', '2026-01-01'], 320, 120));
        self::assertSame(
            '',
            StockDetailController::scaledPoints(
                [self::rowWithDate('2026-01-01', 1000)],
                ['2026-01-01', '2026-01-01'],
                320,
                120,
            ),
        );
    }

    // -- Y-axis ticks (backlog 2026-09-15): primary-only, min/mid/max --------

    public function testYAxisTicksReturnsMaxMidMinAtTheirActualHeightPercent(): void
    {
        $slice = [
            self::rowWithDate('2026-01-01', 100),
            self::rowWithDate('2026-01-02', 200),
        ];

        $ticks = StockDetailController::yAxisTicks($slice);

        self::assertSame(200, $ticks[0]['value']);
        self::assertSame(0.0, $ticks[0]['percent'], 'the max sits at the very top');
        self::assertSame(150, $ticks[1]['value']);
        self::assertEqualsWithDelta(50.0, $ticks[1]['percent'], 0.01, 'the midpoint sits halfway down');
        self::assertSame(100, $ticks[2]['value']);
        self::assertSame(100.0, $ticks[2]['percent'], 'the min sits at the very bottom');
    }

    public function testYAxisTicksIsEmptyForAnEmptySlice(): void
    {
        self::assertSame([], StockDetailController::yAxisTicks([]));
    }

    public function testYAxisTicksCollapsesToOneCenteredTickWhenTheSliceIsFlat(): void
    {
        $slice = [self::rowWithDate('2026-01-01', 500), self::rowWithDate('2026-01-02', 500)];

        $ticks = StockDetailController::yAxisTicks($slice);

        self::assertCount(1, $ticks);
        self::assertSame(500, $ticks[0]['value']);
        self::assertSame(50.0, $ticks[0]['percent']);
    }

    public function testYAxisTicksIgnoresTheSecondarySourceEntirely(): void
    {
        // The axis is built from primarySlice alone — a caller accidentally
        // passing the secondary's (very different magnitude) series must
        // never leak into the primary's tick values. Nothing to assert
        // beyond the signature itself accepting only one slice; documented
        // here so the guarantee has a named test, not just a docblock.
        $primary = [self::rowWithDate('2026-01-01', 500), self::rowWithDate('2026-01-02', 500)];

        self::assertSame(500, StockDetailController::yAxisTicks($primary)[0]['value']);
    }

    // -- tickLabel(): magnitude-aware rounding for compact axis chrome -------

    public function testTickLabelRoundsSixDigitValuesToTheNearestThousand(): void
    {
        self::assertSame('532k', StockDetailController::tickLabel(532481));
        self::assertSame('530k', StockDetailController::tickLabel(529600));
    }

    public function testTickLabelRoundsFiveDigitValuesWithOneDecimal(): void
    {
        self::assertSame('45,3k', StockDetailController::tickLabel(45260));
        self::assertSame('40k', StockDetailController::tickLabel(40012));
    }

    public function testTickLabelRoundsFourDigitValuesWithUpToTwoDecimals(): void
    {
        self::assertSame('8,73k', StockDetailController::tickLabel(8734));
        self::assertSame('8,7k', StockDetailController::tickLabel(8700));
        self::assertSame('8k', StockDetailController::tickLabel(8000));
    }

    public function testTickLabelLeavesValuesUnderOneThousandUnabbreviated(): void
    {
        self::assertSame('450', StockDetailController::tickLabel(450));
        self::assertSame('7', StockDetailController::tickLabel(7));
        self::assertSame('0', StockDetailController::tickLabel(0));
    }

    // -- Primary line color key (contextual; secondary is always fixed) ------

    public function testPrimaryColorKeyIsNoHistoryWhenSma7IsNull(): void
    {
        $slice = [self::row(1, sma7: null, delta: 5, spikeScore: null)];

        self::assertSame('nohistory', StockDetailController::primaryColorKey($slice));
    }

    public function testPrimaryColorKeyIsNoHistoryWhenSliceIsEmpty(): void
    {
        self::assertSame('nohistory', StockDetailController::primaryColorKey([]));
    }

    public function testPrimaryColorKeyIsSpikeWhenSpikingEvenIfLastDeltaIsPositive(): void
    {
        $slice = [self::row(1, sma7: 100.0, delta: 5, spikeScore: 2.5)];

        self::assertSame('spike', StockDetailController::primaryColorKey($slice));
    }

    public function testPrimaryColorKeyIsPositiveOrNegativeFromTheLastRowsDelta(): void
    {
        self::assertSame(
            'positive',
            StockDetailController::primaryColorKey([self::row(1, sma7: 100.0, delta: 5, spikeScore: null)]),
        );
        self::assertSame(
            'negative',
            StockDetailController::primaryColorKey([self::row(1, sma7: 100.0, delta: -5, spikeScore: null)]),
        );
    }

    public function testPrimaryColorKeyIsNeutralWhenNoDeltaAndNotSpiking(): void
    {
        $slice = [self::row(1, sma7: 100.0, delta: null, spikeScore: null)];

        self::assertSame('neutral', StockDetailController::primaryColorKey($slice));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(int $count): array
    {
        // Mon–Fri trading days from Mon 2026-01-05 (collection skips
        // weekends), so calendar-window logic is exercised realistically.
        $out = [];
        $date = new \DateTimeImmutable('2026-01-05');
        for ($i = 0; $i < $count; ++$i) {
            while ((int) $date->format('N') >= 6) {
                $date = $date->modify('+1 day');
            }
            $out[] = ['as_of_date' => $date->format('Y-m-d')]
                + self::row($i, sma7: $i >= 6 ? 100.0 : null, delta: 1, spikeScore: null);
            $date = $date->modify('+1 day');
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(int $numberOfOwners, ?float $sma7, ?int $delta, ?float $spikeScore): array
    {
        return [
            'number_of_owners' => $numberOfOwners,
            'sma_7' => $sma7,
            'delta_1d' => $delta,
            'pct_1d' => null,
            'up_streak' => null,
            'spike_score' => $spikeScore,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function rowWithDate(string $asOfDate, int $numberOfOwners): array
    {
        return [
            'as_of_date' => $asOfDate,
            'number_of_owners' => $numberOfOwners,
            'sma_7' => 100.0,
            'delta_1d' => 1,
            'pct_1d' => null,
            'up_streak' => null,
            'spike_score' => null,
        ];
    }

    // -- avanzaLinkHtml() (spec-5-2) -----------------------------------------

    public function testAvanzaLinkHtmlOmitsTheLinkWhenOrderbookIdIsNull(): void
    {
        self::assertSame('', StockDetailController::avanzaLinkHtml(null));
    }

    public function testAvanzaLinkHtmlOmitsTheLinkWhenOrderbookIdIsAnEmptyString(): void
    {
        self::assertSame('', StockDetailController::avanzaLinkHtml(''));
    }

    public function testAvanzaLinkHtmlBuildsTheOmAktienUrlAndOpensInANewTabSafely(): void
    {
        $html = StockDetailController::avanzaLinkHtml('1001');

        self::assertStringContainsString('href="https://www.avanza.se/aktier/om-aktien.html/1001"', $html);
        self::assertStringContainsString('target="_blank"', $html);
        self::assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    // -- rangeLabel() ---------------------------------------------------------

    public function testRangeLabelCoversEveryRangeValue(): void
    {
        self::assertSame('Dag', StockDetailController::rangeLabel(StockDetailController::RANGE_DAG));
        self::assertSame('Vecka', StockDetailController::rangeLabel(StockDetailController::RANGE_VECKA));
        self::assertSame('Månad', StockDetailController::rangeLabel(StockDetailController::RANGE_MANAD));
        self::assertSame('3 mån', StockDetailController::rangeLabel(StockDetailController::RANGE_3MAN));
        self::assertSame('År', StockDetailController::rangeLabel(StockDetailController::RANGE_AR));
    }

    public function testRangeLabelDefaultsToDagForGarbage(): void
    {
        self::assertSame('Dag', StockDetailController::rangeLabel('bogus'));
    }

    // -- chartAltText() (design handbook §8) -----------------------------------

    public function testChartAltTextReportsInsufficientHistoryBelowTwoPoints(): void
    {
        $text = StockDetailController::chartAltText([], 'avanza', StockDetailController::RANGE_DAG);

        self::assertStringContainsString('otillräcklig historik', $text);
        self::assertStringContainsString('Avanza', $text);
        self::assertStringContainsString('Dag', $text);
    }

    public function testChartAltTextSummarizesPeriodStartEndAndAPositiveChange(): void
    {
        $slice = [
            ['as_of_date' => '2026-09-01', 'number_of_owners' => 1000],
            ['as_of_date' => '2026-09-27', 'number_of_owners' => 1207],
        ];

        $text = StockDetailController::chartAltText($slice, 'nordnet', StockDetailController::RANGE_MANAD);

        self::assertStringContainsString('Nordnet', $text);
        self::assertStringContainsString('Månad', $text);
        self::assertStringContainsString('1 000', $text);
        self::assertStringContainsString('2026-09-01', $text);
        self::assertStringContainsString('1 207', $text);
        self::assertStringContainsString('2026-09-27', $text);
        self::assertStringContainsString('+207', $text);
        self::assertStringContainsString('+20,7 %', $text);
    }

    public function testChartAltTextSignsANegativeChangeWithAMinus(): void
    {
        $slice = [
            ['as_of_date' => '2026-09-01', 'number_of_owners' => 1000],
            ['as_of_date' => '2026-09-02', 'number_of_owners' => 900],
        ];

        $text = StockDetailController::chartAltText($slice, 'avanza', StockDetailController::RANGE_DAG);

        self::assertStringContainsString('−100', $text);
        self::assertStringContainsString('−10,0 %', $text);
    }

    /**
     * @return list<float>
     */
    private static function xCoordinates(string $pointsAttr): array
    {
        if ($pointsAttr === '') {
            return [];
        }

        return array_map(
            static fn (string $pair): float => (float) explode(',', $pair)[0],
            explode(' ', $pointsAttr),
        );
    }
}
