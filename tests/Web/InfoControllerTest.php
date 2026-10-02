<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Web;

use PHPUnit\Framework\TestCase;
use Stockpicker\Web\InfoController;

/**
 * spec-5-3 — InfoController::render() is pure static content (no
 * repository, no PDO), so it belongs in a plain TestCase, not
 * StoreTestCase (Code Map, spec-5-3). Asserts the rendered HTML covers
 * every Acceptance Criterion: all four pages described, all four symbols
 * explained, and the Stadig tillväxt qualifying rule stated in the exact
 * wording tied to DerivedMetricsRepository::topByTrendQualityForPeriod()'s real
 * behavior (Design Notes, spec-5-3) — a literal substring so this test
 * cannot silently drift from the code.
 */
final class InfoControllerTest extends TestCase
{
    public function testRenderDescribesAllFourPages(): void
    {
        $html = InfoController::render();

        self::assertStringContainsString('Topplista', $html);
        self::assertStringContainsString('Fullständig lista', $html);
        self::assertStringContainsString('Bevakningslista', $html);
        self::assertStringContainsString('Aktiedetalj', $html);
    }

    public function testRenderExplainsAllFourSymbols(): void
    {
        $html = InfoController::render();

        self::assertStringContainsString('🔥', $html);
        self::assertStringContainsString('Streak', $html);
        self::assertStringContainsString('⚡', $html);
        self::assertStringContainsString('Spike', $html);
        self::assertStringContainsString('☆', $html);
        self::assertStringContainsString('★', $html);
        self::assertStringContainsString('Delta-chip', $html);
    }

    public function testRenderStatesTheExactStadigTillvaxtQualifyingRuleAndOrdering(): void
    {
        $html = InfoController::render();

        // Tied to DerivedMetricsRepository::topByTrendQualityForPeriod()'s
        // real rule (up_streak >= 1 AND not spiking, ordered by the chosen
        // period's % growth, NULL % last by streak) — not a paraphrase.
        self::assertStringContainsString(
            'kvalificerar om aktien har minst 1 dags obruten uppgångssvit och inte just nu spikar.',
            $html,
        );
        self::assertStringContainsString(
            'sorteras de kvalificerade aktierna efter ägarantalets procentuella ökning under vald period',
            $html,
        );
        self::assertStringContainsString('standard är Månad), med störst ökning först', $html);
        self::assertStringContainsString('Spikande aktier utesluts alltid', $html);
        self::assertStringContainsString('perioden delas mellan alla lägen', $html);
        self::assertStringContainsString('periodraden nedtonad', $html);
        self::assertStringContainsString('Alla, LC, MC, SC eller First North', $html);
        self::assertStringContainsString('Topp tio räknas inom vald marknad', $html);
        // spec-plusdagar-landing-cookie — Plusdagar is the landing view and
        // the last view is remembered in a cookie.
        self::assertStringContainsString('("Flest ägare") eller efter hur mycket', $html);
        self::assertStringContainsString('Plusdagar är standardläget', $html);
        self::assertStringContainsString('Topplista kommer ihåg din senaste vy', $html);
        self::assertStringContainsString('i en kaka i webbläsaren, tills du gör ett annat val', $html);
        self::assertStringContainsString('öppnas Plusdagar med Månad, Alla och Avanza', $html);
        self::assertStringNotContainsString('Valen sparas inte', $html, 'no leftover "not remembered" copy');
        self::assertStringNotContainsString('sorteras med längst', $html, 'no leftover streak-length sort copy');
    }

    public function testRenderIncludesTheTabBarWithTopplistaMarkedActiveAndAPlainWatchlistLink(): void
    {
        $html = InfoController::render();

        self::assertStringContainsString('class="tab tab--active" href="/">Topplista</a>', $html);
        self::assertStringContainsString('class="tab" href="/watchlist">Bevakningslista</a>', $html);
    }

    public function testRenderProducesAFullHtmlDocument(): void
    {
        $html = InfoController::render();

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('<html lang="sv">', $html);
        self::assertStringNotContainsString('<form', $html);
    }

    public function testRenderPreservesNordnetSourceInBothTabBarLinks(): void
    {
        $html = InfoController::render('nordnet');

        self::assertStringContainsString('class="tab tab--active" href="/?source=nordnet">Topplista</a>', $html);
        self::assertStringContainsString('class="tab" href="/watchlist?source=nordnet">Bevakningslista</a>', $html);
    }

    public function testRenderTreatsAnUnknownSourceAsAvanza(): void
    {
        $html = InfoController::render('garbage');

        self::assertStringContainsString('class="tab tab--active" href="/">Topplista</a>', $html);
    }
}
