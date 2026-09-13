<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Web;

use PHPUnit\Framework\TestCase;
use Stockpicker\Web\WatchlistController;

/**
 * Story 4.5 — query-param normalization and the zero-starred empty-state
 * copy/HTML, isolated from HTTP and the database: WatchlistController
 * exposes these rules as small `public static` pure functions specifically
 * so they can be exercised directly here (same pattern as
 * FullListControllerTest/LeaderboardControllerTest). End-to-end rendering
 * (starred rows, source switch, tab bar) is covered by
 * FrontControllerIntegrationTest.
 */
final class WatchlistControllerTest extends TestCase
{
    // -- Source normalization (garbage query values never 500) --------------

    public function testNormalizeSourceDefaultsToAvanzaForUnknownValues(): void
    {
        self::assertSame('avanza', WatchlistController::normalizeSource(''));
        self::assertSame('avanza', WatchlistController::normalizeSource('bogus'));
    }

    public function testNormalizeSourceAcceptsNordnet(): void
    {
        self::assertSame('nordnet', WatchlistController::normalizeSource('nordnet'));
    }

    // -- Empty-state copy --------------------------------------------------

    public function testEmptyStateCopyMatchesTheProductCopy(): void
    {
        self::assertSame('Inga aktier bevakade än.', WatchlistController::emptyStateCopy());
    }

    public function testEmptyStateHtmlContainsTheCopyAndALinkBackToRoot(): void
    {
        $html = WatchlistController::emptyStateHtml();

        self::assertStringContainsString('Inga aktier bevakade än.', $html);
        self::assertStringContainsString('href="/"', $html);
    }
}
