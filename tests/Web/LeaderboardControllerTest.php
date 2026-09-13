<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Web;

use PHPUnit\Framework\TestCase;
use Stockpicker\Web\LeaderboardController;

/**
 * Story 4.2 — badge/no-history/empty-state selection logic, isolated from
 * HTTP and the database: LeaderboardController exposes these rules as small
 * `public static` pure functions specifically so they can be exercised
 * directly here. Every case mirrors a row of the spec's I/O & Edge-Case
 * Matrix (spec-4-2).
 */
final class LeaderboardControllerTest extends TestCase
{
    // -- Streak badge: up_streak >= 1 -----------------------------------------

    public function testHasStreakIsTrueWhenUpStreakIsAtLeastOne(): void
    {
        self::assertTrue(LeaderboardController::hasStreak(1));
        self::assertTrue(LeaderboardController::hasStreak(7));
    }

    public function testHasStreakIsFalseWhenUpStreakIsZeroOrNull(): void
    {
        self::assertFalse(LeaderboardController::hasStreak(0));
        self::assertFalse(LeaderboardController::hasStreak(null));
    }

    public function testStreakBadgeHtmlRendersFireEmojiAndDayCountWhenStreaking(): void
    {
        $html = LeaderboardController::streakBadgeHtml(5);

        self::assertStringContainsString('🔥', $html);
        self::assertStringContainsString('5d', $html);
        self::assertStringContainsString('badge--streak', $html);
    }

    public function testStreakBadgeHtmlFallsBackToFlatVariantWhenZero(): void
    {
        $html = LeaderboardController::streakBadgeHtml(0);

        self::assertStringContainsString('flat', $html);
        self::assertStringContainsString('badge--nohist', $html);
        self::assertStringNotContainsString('🔥', $html);
    }

    public function testStreakBadgeHtmlFallsBackToFlatVariantWhenNull(): void
    {
        $html = LeaderboardController::streakBadgeHtml(null);

        self::assertStringContainsString('flat', $html);
        self::assertStringContainsString('badge--nohist', $html);
    }

    // -- Spike badge: spike_score >= 2, upward only ---------------------------

    public function testIsSpikingIsTrueAtOrAboveThreshold(): void
    {
        self::assertTrue(LeaderboardController::isSpiking(2.0));
        self::assertTrue(LeaderboardController::isSpiking(5.3));
    }

    public function testIsSpikingIsFalseJustBelowThreshold(): void
    {
        self::assertFalse(LeaderboardController::isSpiking(1.999));
    }

    public function testIsSpikingIsFalseWhenNull(): void
    {
        self::assertFalse(LeaderboardController::isSpiking(null));
    }

    public function testIsSpikingIsFalseForANegativeSpikeScoreEvenWhenLarge(): void
    {
        // A large negative deviation is a drop, not a spike (decided
        // 2026-09-13) — the Spike badge must never flag it.
        self::assertFalse(LeaderboardController::isSpiking(-6.0));
    }

    public function testSpikeBadgeHtmlIsEmptyWhenNotSpiking(): void
    {
        self::assertSame('', LeaderboardController::spikeBadgeHtml(null));
        self::assertSame('', LeaderboardController::spikeBadgeHtml(1.5));
        self::assertSame('', LeaderboardController::spikeBadgeHtml(-8.0));
    }

    public function testSpikeBadgeHtmlRendersWhenSpiking(): void
    {
        $html = LeaderboardController::spikeBadgeHtml(2.4);

        self::assertStringContainsString('⚡', $html);
        self::assertStringContainsString('spike', $html);
        self::assertStringContainsString('badge--spike', $html);
    }

    // -- Sparkline muted/no-history treatment: sma_7 IS NULL ------------------

    public function testSparklineIsMutedWhenSma7IsNull(): void
    {
        self::assertTrue(LeaderboardController::isSparklineMuted(null));
    }

    public function testSparklineIsNotMutedWhenSma7IsPresent(): void
    {
        self::assertFalse(LeaderboardController::isSparklineMuted('1234.5'));
        self::assertFalse(LeaderboardController::isSparklineMuted(1234.5));
    }

    public function testSparklineNoHistoryLabelIncludesTheTrackedDayCount(): void
    {
        self::assertSame('3d spårade · ingen trend än', LeaderboardController::sparklineNoHistoryLabel(3));
        self::assertSame('0d spårade · ingen trend än', LeaderboardController::sparklineNoHistoryLabel(0));
    }

    // -- Empty-state copy ------------------------------------------------------

    public function testEmptyStateCopyForSteadyGrowthModeMatchesTheProductCopy(): void
    {
        self::assertSame(
            'Inga aktier med stadig tillväxt just nu.',
            LeaderboardController::emptyStateCopy(LeaderboardController::RANKING_STEADY),
        );
    }

    public function testEmptyStateCopyForOwnerCountModeIsAPlainFallback(): void
    {
        self::assertSame('Inga aktier hittades.', LeaderboardController::emptyStateCopy(LeaderboardController::RANKING_COUNT));
    }

    // -- Delta chip: count and percent always together ------------------------

    public function testDeltaChipHtmlIsEmptyWhenEitherValueIsUnavailable(): void
    {
        self::assertSame('', LeaderboardController::deltaChipHtml(null, 0.01));
        self::assertSame('', LeaderboardController::deltaChipHtml(5, null));
        self::assertSame('', LeaderboardController::deltaChipHtml(null, null));
    }

    public function testDeltaChipHtmlFormatsAPositiveDeltaWithSignAndPercentTogether(): void
    {
        $html = LeaderboardController::deltaChipHtml(412, 0.009);

        self::assertStringContainsString('+412', $html);
        self::assertStringContainsString('0,9 %', $html);
        self::assertStringContainsString('delta-chip--positive', $html);
    }

    public function testDeltaChipHtmlFormatsANegativeDeltaWithMinusSign(): void
    {
        $html = LeaderboardController::deltaChipHtml(-100, -0.02);

        self::assertStringContainsString('-100', $html);
        self::assertStringContainsString('2,0 %', $html);
        self::assertStringContainsString('delta-chip--negative', $html);
    }

    public function testDeltaChipHtmlEscapesNothingDangerousButStaysWellFormed(): void
    {
        $html = LeaderboardController::deltaChipHtml(0, 0.0);

        self::assertStringContainsString('delta-chip--neutral', $html);
        self::assertStringContainsString('0 ·', $html);
    }

    // -- Row markup (spec-5-1): namecol/trend/statcol, not flat flex siblings -

    /**
     * Root-cause regression guard for spec-5-1's mobile layout bug: the row
     * body used to be five flat, mostly-unshrinkable flex siblings
     * (name/badges/trend/stat/delta-chip), which let `.trend`'s
     * "insufficient history" label push the owner count and delta chip
     * off-screen at 390px. rowBodyHtml() now nests name+badges inside one
     * shrinkable `.namecol` and owners+delta-chip inside one protected
     * `.statcol`, matching the approved mockup — this test asserts that
     * nesting directly in the rendered markup so a future refactor can't
     * silently flatten it back out.
     */
    public function testRowBodyHtmlNestsNameAndBadgesInNamecolAndOwnersAndDeltaChipInStatcol(): void
    {
        $html = LeaderboardController::rowBodyHtml(
            'Volvo B',
            '<span class="badge badge--streak">streak</span>',
            '<span class="sparkline"></span>',
            '48 210',
            '<span class="delta-chip delta-chip--positive">+412 · 0,9 %</span>',
        );

        self::assertStringContainsString('class="namecol"', $html);
        self::assertStringContainsString('class="statcol"', $html);

        // Badges must be nested inside namecol, after the name — not a flat
        // sibling of .trend/.stat.
        $namecolStart = strpos($html, 'class="namecol"');
        $namecolEnd = strpos($html, '</span>', strpos($html, 'class="badges"'));
        self::assertNotFalse($namecolStart);
        self::assertNotFalse($namecolEnd);
        self::assertGreaterThan($namecolStart, strpos($html, 'Volvo B'));
        self::assertGreaterThan(strpos($html, 'Volvo B'), strpos($html, 'badge--streak'));
        self::assertLessThan($namecolEnd, strpos($html, 'badge--streak'));

        // Owners + delta-chip must be nested inside statcol, not flat
        // siblings that CSS could shrink or clip independently.
        $statcolStart = strpos($html, 'class="statcol"');
        self::assertNotFalse($statcolStart);
        self::assertGreaterThan($statcolStart, strpos($html, '48 210'));
        self::assertGreaterThan(strpos($html, '48 210'), strpos($html, 'delta-chip--positive'));
    }

    public function testRowBodyHtmlHandlesFlatBadgeOnlyAndEmptyDeltaChip(): void
    {
        // streakBadgeHtml() never actually returns '' (always at least the
        // "flat" badge) — this is the realistic no-streak/no-spike row.
        // An empty delta chip (no >1-day-gap-free predecessor) is the
        // realistic empty case and must still render valid namecol/statcol
        // markup, not break the structure.
        $badgesHtml = LeaderboardController::streakBadgeHtml(null);
        $html = LeaderboardController::rowBodyHtml('Boliden', $badgesHtml, '<span class="sparkline"></span>', '21 870', '');

        self::assertStringContainsString('class="namecol"', $html);
        self::assertStringContainsString('class="statcol"', $html);
        self::assertStringContainsString('Boliden', $html);
        self::assertStringContainsString('21 870', $html);
        self::assertStringContainsString('badge--nohist', $html);
    }

    // -- spec-5-4: normalizeSource() — three-way, garbage falls back to avanza -

    public function testNormalizeSourceRecognizesAlla(): void
    {
        self::assertSame('alla', LeaderboardController::normalizeSource('alla'));
    }

    public function testNormalizeSourceRecognizesNordnet(): void
    {
        self::assertSame('nordnet', LeaderboardController::normalizeSource('nordnet'));
    }

    public function testNormalizeSourceDefaultsToAvanzaForEmptyString(): void
    {
        self::assertSame('avanza', LeaderboardController::normalizeSource(''));
    }

    public function testNormalizeSourceDefaultsToAvanzaForGarbage(): void
    {
        self::assertSame('avanza', LeaderboardController::normalizeSource('bogus'));
    }

    public function testNormalizeSourceRecognizesAvanzaItself(): void
    {
        self::assertSame('avanza', LeaderboardController::normalizeSource('avanza'));
    }

    // -- spec-5-4: combinedOwnerCountText() — "Avanza {n} · Nordnet {m}" -------

    public function testCombinedOwnerCountTextShowsBothSourcesSideBySideWhenBothPresent(): void
    {
        self::assertSame(
            'Avanza 1 234 · Nordnet 567',
            LeaderboardController::combinedOwnerCountText(1234, 567),
        );
    }

    public function testCombinedOwnerCountTextShowsIngenDataWhenNordnetIsMissingNeverAZeroOrBlank(): void
    {
        $text = LeaderboardController::combinedOwnerCountText(1234, null);

        self::assertSame('Avanza 1 234 · Nordnet ingen data', $text);
        self::assertStringNotContainsString('Nordnet 0', $text);
    }

    public function testCombinedOwnerCountTextShowsALiteralZeroDistinctFromMissingData(): void
    {
        // A stored Nordnet row of exactly 0 owners is real data, not the
        // "no stored row" case -- the null-check must stay a strict
        // `!== null`, never a falsy/empty() check that would conflate the two.
        self::assertSame(
            'Avanza 1 234 · Nordnet 0',
            LeaderboardController::combinedOwnerCountText(1234, 0),
        );
    }
}
