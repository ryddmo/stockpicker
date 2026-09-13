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

        // Tied to DerivedMetricsRepository::topByTrendQuality()'s real rule
        // (up_streak >= 1 AND not spiking, ordered by up_streak DESC) —
        // not a paraphrase.
        self::assertStringContainsString(
            'kvalificerar om aktien har minst 1 dags obruten uppgångssvit och inte just nu spikar; sorteras med längst svit först',
            $html,
        );
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
