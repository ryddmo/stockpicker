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
        self::assertSame(StockDetailController::RANGE_30D, StockDetailController::normalizeRange('30d'));
        self::assertSame(StockDetailController::RANGE_90D, StockDetailController::normalizeRange('90d'));
        self::assertSame(StockDetailController::RANGE_AR, StockDetailController::normalizeRange('ar'));
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

    // -- Window size / gate threshold per range (Design Notes table) --------

    public function testWindowSizePerRange(): void
    {
        self::assertSame(2, StockDetailController::windowSize(StockDetailController::RANGE_DAG));
        self::assertSame(7, StockDetailController::windowSize(StockDetailController::RANGE_VECKA));
        self::assertSame(30, StockDetailController::windowSize(StockDetailController::RANGE_30D));
        self::assertSame(90, StockDetailController::windowSize(StockDetailController::RANGE_90D));
        self::assertNull(StockDetailController::windowSize(StockDetailController::RANGE_AR), 'Ar is uncapped');
    }

    public function testGateThresholdPerRange(): void
    {
        self::assertSame(2, StockDetailController::gateThreshold(StockDetailController::RANGE_DAG));
        self::assertSame(7, StockDetailController::gateThreshold(StockDetailController::RANGE_VECKA));
        self::assertSame(30, StockDetailController::gateThreshold(StockDetailController::RANGE_30D));
        self::assertSame(90, StockDetailController::gateThreshold(StockDetailController::RANGE_90D));
        self::assertSame(
            90,
            StockDetailController::gateThreshold(StockDetailController::RANGE_AR),
            'Ar shares the 90d floor (Design Notes)',
        );
    }

    // -- sliceForRange(): array_slice(-n), never a second query --------------

    public function testSliceForRangeTakesTheLastNRows(): void
    {
        $series = self::rows(10);

        $slice = StockDetailController::sliceForRange($series, StockDetailController::RANGE_VECKA);

        self::assertCount(7, $slice);
        self::assertSame(3, $slice[array_key_first($slice)]['number_of_owners']);
        self::assertSame(9, $slice[array_key_last($slice)]['number_of_owners']);
    }

    public function testSliceForRangeReturnsTheWholeArrayWhenFewerRowsThanTheWindow(): void
    {
        $series = self::rows(3);

        $slice = StockDetailController::sliceForRange($series, StockDetailController::RANGE_90D);

        self::assertCount(3, $slice);
    }

    public function testSliceForRangeArReturnsEveryRowUncapped(): void
    {
        $series = self::rows(150);

        $slice = StockDetailController::sliceForRange($series, StockDetailController::RANGE_AR);

        self::assertCount(150, $slice);
    }

    public function testSliceForRangeDagTakesTheLastTwoRows(): void
    {
        $series = self::rows(30);

        $slice = StockDetailController::sliceForRange($series, StockDetailController::RANGE_DAG);

        self::assertCount(2, $slice);
        self::assertSame(28, $slice[array_key_first($slice)]['number_of_owners']);
        self::assertSame(29, $slice[array_key_last($slice)]['number_of_owners']);
    }

    // -- isInsufficientHistory(): gated on the primary source only -----------

    public function testIsInsufficientHistoryIsTrueBelowTheGate(): void
    {
        self::assertTrue(StockDetailController::isInsufficientHistory(self::rows(29), StockDetailController::RANGE_30D));
        self::assertTrue(StockDetailController::isInsufficientHistory(self::rows(1), StockDetailController::RANGE_DAG));
        self::assertTrue(StockDetailController::isInsufficientHistory([], StockDetailController::RANGE_DAG));
    }

    public function testIsInsufficientHistoryIsFalseAtOrAboveTheGate(): void
    {
        self::assertFalse(StockDetailController::isInsufficientHistory(self::rows(30), StockDetailController::RANGE_30D));
        self::assertFalse(StockDetailController::isInsufficientHistory(self::rows(2), StockDetailController::RANGE_DAG));
        self::assertFalse(StockDetailController::isInsufficientHistory(self::rows(90), StockDetailController::RANGE_AR));
    }

    public function testIsInsufficientHistoryForArUsesThe90RowFloorNotAllAvailableRows(): void
    {
        // 89 rows is "a lot" of history but still short of Ar's shared 90d
        // floor (Design Notes) -> must still gate.
        self::assertTrue(StockDetailController::isInsufficientHistory(self::rows(89), StockDetailController::RANGE_AR));
    }

    // -- Insufficient-history message -----------------------------------------

    public function testDaysUntilSufficientIsTheGapToTheGate(): void
    {
        self::assertSame(20, StockDetailController::daysUntilSufficient(self::rows(10), StockDetailController::RANGE_30D));
        self::assertSame(0, StockDetailController::daysUntilSufficient(self::rows(30), StockDetailController::RANGE_30D));
    }

    public function testInsufficientHistoryMessageIncludesTheDayCount(): void
    {
        $message = StockDetailController::insufficientHistoryMessage(self::rows(10), StockDetailController::RANGE_30D);

        self::assertStringContainsString('20 dagar', $message);
        self::assertStringContainsString('Inte tillräckligt med historik', $message);
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
        $out = [];
        for ($i = 0; $i < $count; ++$i) {
            $out[] = self::row($i, sma7: $i >= 6 ? 100.0 : null, delta: 1, spikeScore: null);
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
