<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Web;

use PHPUnit\Framework\TestCase;
use Stockpicker\Store\DerivedMetricsRepository;
use Stockpicker\Web\FullListController;

/**
 * Story 4.4 — query-param normalization and the zero-results/"rensa filter"
 * copy, isolated from HTTP and the database: FullListController exposes
 * these rules as small `public static` pure functions specifically so they
 * can be exercised directly here (same pattern as LeaderboardControllerTest/
 * StockDetailControllerTest). End-to-end rendering (search/sort/filter/
 * source combinations against real data) is covered by
 * FrontControllerIntegrationTest.
 */
final class FullListControllerTest extends TestCase
{
    // -- Source normalization (garbage query values never 500) --------------

    public function testNormalizeSourceDefaultsToAvanzaForUnknownValues(): void
    {
        self::assertSame('avanza', FullListController::normalizeSource(''));
        self::assertSame('avanza', FullListController::normalizeSource('bogus'));
    }

    public function testNormalizeSourceAcceptsNordnet(): void
    {
        self::assertSame('nordnet', FullListController::normalizeSource('nordnet'));
    }

    // -- Query normalization ---------------------------------------------------

    public function testNormalizeQueryTrimsWhitespaceAndDefaultsToEmptyString(): void
    {
        self::assertSame('', FullListController::normalizeQuery(null));
        self::assertSame('', FullListController::normalizeQuery(''));
        self::assertSame('volvo', FullListController::normalizeQuery('  volvo  '));
    }

    // -- Sort normalization ------------------------------------------------

    public function testNormalizeSortDefaultsToCountForUnknownOrMissingValues(): void
    {
        self::assertSame(DerivedMetricsRepository::SORT_COUNT, FullListController::normalizeSort(null));
        self::assertSame(DerivedMetricsRepository::SORT_COUNT, FullListController::normalizeSort(''));
        self::assertSame(DerivedMetricsRepository::SORT_COUNT, FullListController::normalizeSort('bogus'));
    }

    public function testNormalizeSortAcceptsPct(): void
    {
        self::assertSame(DerivedMetricsRepository::SORT_PCT, FullListController::normalizeSort('pct'));
    }

    // -- Flag normalization (growth/spike/watchlist) ------------------------

    public function testNormalizeFlagIsTrueOnlyForExactlyOne(): void
    {
        self::assertTrue(FullListController::normalizeFlag('1'));
        self::assertFalse(FullListController::normalizeFlag(null));
        self::assertFalse(FullListController::normalizeFlag(''));
        self::assertFalse(FullListController::normalizeFlag('true'));
        self::assertFalse(FullListController::normalizeFlag('0'));
    }

    // -- Market normalization ------------------------------------------------

    public function testNormalizeMarketAcceptsEachStoredListValue(): void
    {
        self::assertSame('LC', FullListController::normalizeMarket('LC'));
        self::assertSame('MC', FullListController::normalizeMarket('MC'));
        self::assertSame('SC', FullListController::normalizeMarket('SC'));
        self::assertSame('First North', FullListController::normalizeMarket('First North'));
    }

    public function testNormalizeMarketReturnsNullForUnknownOrMissingValues(): void
    {
        self::assertNull(FullListController::normalizeMarket(null));
        self::assertNull(FullListController::normalizeMarket(''));
        self::assertNull(FullListController::normalizeMarket('bogus'));
        self::assertNull(FullListController::normalizeMarket('lc'), 'market values are exact literal matches, not case-insensitive');
    }

    // -- Empty-state copy --------------------------------------------------

    public function testEmptyStateCopyMatchesTheProductCopy(): void
    {
        self::assertSame('Inga resultat för dessa filter.', FullListController::emptyStateCopy());
    }

    public function testEmptyStateHtmlContainsTheCopyAndARensaFilterLinkBackToABareList(): void
    {
        $html = FullListController::emptyStateHtml();

        self::assertStringContainsString('Inga resultat för dessa filter.', $html);
        self::assertStringContainsString('rensa filter', $html);
        self::assertStringContainsString('href="/list"', $html);
    }
}
