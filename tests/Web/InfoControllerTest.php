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
 * wording tied to DerivedMetricsRepository::topByTrendQuality()'s real
 * behavior (Design Notes, spec-5-3, spec-topplista-steady-no-period) — a
 * literal substring so this test cannot silently drift from the code.
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

        // Tied to DerivedMetricsRepository::topByTrendQuality()'s real rule
        // (2026-10-06: not currently declining -- flat included -- AND not
        // spiking, ordered by streak length then owner count, no period) —
        // not a paraphrase.
        self::assertStringContainsString(
            'kvalificerar en aktie om den inte just nu minskar',
            $html,
        );
        self::assertStringContainsString(
            'En "flat" dag (ingen förändring) kvalificerar alltså precis som en uppgångsdag',
            $html,
        );
        self::assertStringContainsString(
            'sorteras de kvalificerade aktierna efter längst pågående uppgångssvit först, sedan flest ägare som tiebreak',
            $html,
        );
        self::assertStringContainsString('Spikande aktier utesluts alltid', $html);
        self::assertStringContainsString('perioden delas mellan alla lägen', $html);
        self::assertStringContainsString('periodraden nedtonad', $html);
        self::assertStringContainsString('Alla, LC, MC, SC eller First North', $html);
        self::assertStringContainsString('Topp tio räknas inom vald marknad', $html);
        // spec-plusdagar-landing-cookie — Plusdagar is the landing view and
        // the last view is remembered in a cookie.
        self::assertStringContainsString('("Flest ägare") eller efter hur länge', $html);
        self::assertStringContainsString('Plusdagar är standardläget', $html);
        self::assertStringContainsString('Topplista kommer ihåg din senaste vy', $html);
        self::assertStringContainsString('i en kaka i webbläsaren, tills du gör ett annat val', $html);
        self::assertStringContainsString('öppnas Plusdagar med Månad, Alla och Avanza', $html);
        self::assertStringNotContainsString('Valen sparas inte', $html, 'no leftover "not remembered" copy');
        self::assertStringNotContainsString('minst 1 dags obruten uppgångssvit', $html, 'no leftover up_streak >= 1 copy');
        self::assertStringNotContainsString('ägarantalets procentuella ökning under vald period', $html, 'no leftover period-pct sort copy');
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
