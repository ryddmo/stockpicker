<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Web;

use PHPUnit\Framework\TestCase;
use Stockpicker\Store\ShortPositionRepository;
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

    // -- periodPctItemHtml()/periodPctsHtml() — Vecka/Månad/3 mån/År line --

    public function testPeriodPctItemHtmlFormatsAPositivePercentWithSignAndLabel(): void
    {
        $html = LeaderboardController::periodPctItemHtml('Vecka', 0.021);

        self::assertStringContainsString('+2,1 %', $html);
        self::assertStringContainsString('>Vecka<', $html);
        self::assertStringContainsString('period-pct--positive', $html);
    }

    public function testPeriodPctItemHtmlFormatsANegativePercentWithMinusSign(): void
    {
        $html = LeaderboardController::periodPctItemHtml('3 mån', -0.021);

        self::assertStringContainsString('-2,1 %', $html);
        self::assertStringContainsString('period-pct--negative', $html);
    }

    public function testPeriodPctItemHtmlFormatsZeroAsNeutralNotNoHistory(): void
    {
        $html = LeaderboardController::periodPctItemHtml('År', 0.0);

        self::assertStringContainsString('0,0 %', $html);
        self::assertStringContainsString('period-pct--neutral', $html);
        self::assertStringNotContainsString('period-pct--nohist', $html);
    }

    public function testPeriodPctItemHtmlNeverShowsASignedZeroWhenTheRawValueRoundsToZero(): void
    {
        // A genuine but tiny change (e.g. -0.00001) must round to a plain,
        // neutral "0,0 %" -- not a self-contradictory "-0,0 %" styled
        // negative from the unrounded float's sign.
        $html = LeaderboardController::periodPctItemHtml('Vecka', -0.00001);

        self::assertStringContainsString('0,0 %', $html);
        self::assertStringNotContainsString('-0,0 %', $html);
        self::assertStringNotContainsString('+0,0 %', $html);
        self::assertStringContainsString('period-pct--neutral', $html);
        self::assertStringNotContainsString('period-pct--negative', $html);
    }

    public function testPeriodPctItemHtmlRendersANoHistoryMarkWhenNull(): void
    {
        $html = LeaderboardController::periodPctItemHtml('3 mån', null);

        self::assertStringContainsString('period-pct--nohist', $html);
        self::assertStringContainsString('–', $html);
        self::assertStringContainsString('title="Ingen jämförbar dag"', $html);
        self::assertStringNotContainsString('period-pct--positive', $html);
        self::assertStringNotContainsString('period-pct--negative', $html);
    }

    public function testPeriodPctsHtmlWrapsFourItemsLabeledVeckaManadTreManAndAr(): void
    {
        $html = LeaderboardController::periodPctsHtml(0.01, 0.123, null, -0.5);

        self::assertStringContainsString('class="period-pcts"', $html);
        self::assertStringContainsString('>Vecka<', $html);
        self::assertStringContainsString('>Månad<', $html);
        self::assertStringContainsString('>3 mån<', $html);
        self::assertStringContainsString('>År<', $html);
        self::assertStringContainsString('+1,0 %', $html);
        self::assertStringContainsString('+12,3 %', $html);
        self::assertStringContainsString('period-pct--nohist', $html);
        self::assertStringContainsString('-50,0 %', $html);
        // Ordered Vecka, Månad, 3 mån, År.
        self::assertLessThan(strpos($html, '>Månad<'), strpos($html, '>Vecka<'));
        self::assertLessThan(strpos($html, '>3 mån<'), strpos($html, '>Månad<'));
        self::assertLessThan(strpos($html, '>År<'), strpos($html, '>3 mån<'));
        // Each value sits next to its own label.
        self::assertLessThan(strpos($html, '>3 mån<'), strpos($html, '+12,3 %'));
        self::assertGreaterThan(strpos($html, '>Månad<'), strpos($html, '+12,3 %'));
    }

    public function testPeriodPctsHtmlAllFourNullRendersFourNoHistoryMarks(): void
    {
        $html = LeaderboardController::periodPctsHtml(null, null, null, null);

        self::assertSame(4, substr_count($html, 'period-pct--nohist'));
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

    // -- spec-5-4: combinedOwnerCountHtml() — Avanza / Nordnet stacked ---------

    private const AVANZA_1234 = '<span class="stat-line"><span class="stat-src">Avanza</span> 1 234</span>';

    public function testCombinedOwnerCountHtmlStacksBothSourcesAsSeparateLinesWhenBothPresent(): void
    {
        self::assertSame(
            self::AVANZA_1234 . '<span class="stat-line"><span class="stat-src">Nordnet</span> 567</span>',
            LeaderboardController::combinedOwnerCountHtml(1234, 567),
        );
    }

    public function testCombinedOwnerCountHtmlShowsIngenDataWhenNordnetIsMissingNeverAZeroOrBlank(): void
    {
        $html = LeaderboardController::combinedOwnerCountHtml(1234, null);

        self::assertSame(
            self::AVANZA_1234 . '<span class="stat-line"><span class="stat-src">Nordnet</span> ingen data</span>',
            $html,
        );
        self::assertStringNotContainsString('</span> 0<', $html);
    }

    public function testCombinedOwnerCountHtmlShowsALiteralZeroDistinctFromMissingData(): void
    {
        // A stored Nordnet row of exactly 0 owners is real data, not the
        // "no stored row" case -- the null-check must stay a strict
        // `!== null`, never a falsy/empty() check that would conflate the two.
        self::assertSame(
            self::AVANZA_1234 . '<span class="stat-line"><span class="stat-src">Nordnet</span> 0</span>',
            LeaderboardController::combinedOwnerCountHtml(1234, 0),
        );
    }

    // -- spec-plusdagar ---------------------------------------------------------

    public function testNormalizeRankingRecognizesAllThreeModesAndFallsBackToPlus(): void
    {
        self::assertSame(LeaderboardController::RANKING_PLUS, LeaderboardController::normalizeRanking('plus'));
        self::assertSame(LeaderboardController::RANKING_STEADY, LeaderboardController::normalizeRanking('steady'));
        self::assertSame(LeaderboardController::RANKING_COUNT, LeaderboardController::normalizeRanking('count'));
        self::assertSame(LeaderboardController::RANKING_PLUS, LeaderboardController::normalizeRanking(''));
        self::assertSame(LeaderboardController::RANKING_PLUS, LeaderboardController::normalizeRanking('xyz'));
    }

    // -- spec-plusdagar-landing-cookie: view normalize/serialize/parse --------

    public function testNormalizeViewWithNoParamsIsPlusdagarManadAllaAvanza(): void
    {
        self::assertSame(
            ['source' => 'avanza', 'ranking' => 'plus', 'period' => 'manad', 'spikes' => false, 'market' => null, 'shorts' => false],
            LeaderboardController::normalizeView([]),
        );
    }

    public function testNormalizeViewKeepsValidValuesAndDropsSpikesOutsidePlusdagar(): void
    {
        self::assertSame(
            ['source' => 'nordnet', 'ranking' => 'steady', 'period' => 'vecka', 'spikes' => false, 'market' => 'SC', 'shorts' => false],
            LeaderboardController::normalizeView(['source' => 'nordnet', 'ranking' => 'steady', 'period' => 'vecka', 'spikes' => 'exclude', 'market' => 'SC']),
        );
        self::assertTrue(LeaderboardController::normalizeView(['ranking' => 'plus', 'spikes' => 'exclude'])['spikes']);
        self::assertTrue(LeaderboardController::normalizeView(['spikes' => 'exclude'])['spikes'], 'Plusdagar is the default ranking');
        self::assertFalse(LeaderboardController::normalizeView(['ranking' => 'count', 'spikes' => 'exclude'])['spikes']);
    }

    public function testNormalizeViewFallsBackPerParamForGarbageAndNonStringValues(): void
    {
        self::assertSame(
            ['source' => 'avanza', 'ranking' => 'plus', 'period' => 'manad', 'spikes' => false, 'market' => 'LC', 'shorts' => false],
            LeaderboardController::normalizeView(['source' => ['nordnet'], 'ranking' => 'xx', 'period' => 7, 'spikes' => 'yes', 'market' => 'LC']),
        );
    }

    public function testSerializeViewOmitsDefaultsButAlwaysCarriesRanking(): void
    {
        self::assertSame('ranking=plus', LeaderboardController::serializeView(LeaderboardController::normalizeView([])));
        self::assertSame(
            'ranking=count&market=LC',
            LeaderboardController::serializeView(LeaderboardController::normalizeView(['ranking' => 'count', 'market' => 'LC'])),
        );
        self::assertSame(
            'source=nordnet&ranking=plus&period=vecka&spikes=exclude&market=First+North',
            LeaderboardController::serializeView(LeaderboardController::normalizeView(['source' => 'nordnet', 'period' => 'vecka', 'spikes' => 'exclude', 'market' => 'First North'])),
        );
    }

    public function testViewCookieRoundTripsThroughSerializeAndParse(): void
    {
        foreach ([
            [],
            ['ranking' => 'count', 'market' => 'LC'],
            ['source' => 'alla', 'ranking' => 'steady', 'period' => '3man', 'market' => 'MC'],
            ['source' => 'nordnet', 'ranking' => 'plus', 'period' => 'ar', 'spikes' => 'exclude', 'market' => 'First North'],
        ] as $raw) {
            $view = LeaderboardController::normalizeView($raw);
            self::assertSame($view, LeaderboardController::parseViewCookie(LeaderboardController::serializeView($view)));
        }
    }

    public function testParseViewCookieFallsBackToDefaultsForMissingOrGarbageCookies(): void
    {
        $defaults = LeaderboardController::normalizeView([]);
        self::assertSame($defaults, LeaderboardController::parseViewCookie(null));
        self::assertSame($defaults, LeaderboardController::parseViewCookie(''));
        self::assertSame($defaults, LeaderboardController::parseViewCookie(['ranking' => 'count']));
        self::assertSame($defaults, LeaderboardController::parseViewCookie('%%%&ranking=xx&market=ZZ'));
        self::assertSame($defaults, LeaderboardController::parseViewCookie('ranking[]=count&market[x]=LC'));
        self::assertSame(
            ['source' => 'avanza', 'ranking' => 'count', 'period' => 'manad', 'spikes' => false, 'market' => null, 'shorts' => false],
            LeaderboardController::parseViewCookie('ranking=count&spikes=exclude'),
        );
    }

    public function testResolveViewWithNoViewParamRendersTheCookieWithoutWriting(): void
    {
        $cookie = 'ranking=steady&period=vecka&market=SC&source=nordnet';
        $remembered = LeaderboardController::parseViewCookie($cookie);

        foreach ([[], ['fbclid' => 'abc'], ['utm_source' => 'x', 'utm_medium' => 'y']] as $get) {
            self::assertSame(['view' => $remembered, 'write' => false], LeaderboardController::resolveView($get, $cookie));
        }
        self::assertSame(
            ['view' => LeaderboardController::normalizeView([]), 'write' => false],
            LeaderboardController::resolveView(['fbclid' => 'abc'], null),
        );
    }

    public function testResolveViewWithOnlySourceMergesItIntoTheCookieAndWrites(): void
    {
        $resolved = LeaderboardController::resolveView(
            ['source' => 'nordnet', 'fbclid' => 'abc'],
            'ranking=steady&period=vecka&market=LC',
        );
        self::assertTrue($resolved['write']);
        self::assertSame(
            ['source' => 'nordnet', 'ranking' => 'steady', 'period' => 'vecka', 'spikes' => false, 'market' => 'LC', 'shorts' => false],
            $resolved['view'],
        );
        self::assertSame('source=nordnet&ranking=steady&period=vecka&market=LC', LeaderboardController::serializeView($resolved['view']));

        // No cookie: the defaults with that source.
        self::assertSame(
            ['view' => LeaderboardController::normalizeView(['source' => 'alla']), 'write' => true],
            LeaderboardController::resolveView(['source' => 'alla'], null),
        );

        // A garbage / non-string source falls back to Avanza, keeping the rest.
        $resolved = LeaderboardController::resolveView(['source' => ['x']], 'source=alla&ranking=count');
        self::assertSame('avanza', $resolved['view']['source']);
        self::assertSame('count', $resolved['view']['ranking']);
    }

    public function testResolveViewWithAnyOtherViewParamIsAuthoritative(): void
    {
        $cookie = 'source=nordnet&ranking=plus&period=vecka&spikes=exclude&market=SC';
        foreach (['ranking' => 'steady', 'period' => 'ar', 'market' => 'LC', 'spikes' => 'exclude'] as $key => $value) {
            $get = [$key => $value];
            self::assertSame(
                ['view' => LeaderboardController::normalizeView($get), 'write' => true],
                LeaderboardController::resolveView($get, $cookie),
                $key,
            );
        }
        self::assertSame(
            ['view' => LeaderboardController::normalizeView(['source' => 'alla', 'ranking' => 'count']), 'write' => true],
            LeaderboardController::resolveView(['source' => 'alla', 'ranking' => 'count'], $cookie),
        );
    }

    public function testNormalizePeriodRecognizesTheFourPeriodsAndFallsBackToManad(): void
    {
        foreach (['vecka', 'manad', '3man', 'ar'] as $period) {
            self::assertSame($period, LeaderboardController::normalizePeriod($period));
        }
        self::assertSame('manad', LeaderboardController::normalizePeriod(''));
        self::assertSame('manad', LeaderboardController::normalizePeriod('xyz'));
        self::assertSame(
            [7, 30, 90, 365],
            array_column(array_values(LeaderboardController::PERIODS), 'days'),
            'same calendar windows as the period chips',
        );
    }

    public function testEmptyStateCopyForPlusModeDistinguishesShortHistoryFromNoQualifiers(): void
    {
        self::assertSame(
            'Inga aktier med fler ägare under perioden.',
            LeaderboardController::emptyStateCopy(LeaderboardController::RANKING_PLUS, 'manad'),
        );
        self::assertSame(
            'För lite historik för 3 mån ännu.',
            LeaderboardController::emptyStateCopy(LeaderboardController::RANKING_PLUS, '3man', true),
        );
        self::assertSame(
            'För lite historik för ett år ännu.',
            LeaderboardController::emptyStateCopy(LeaderboardController::RANKING_PLUS, 'ar', true),
        );
    }

    public function testEmptyStateCopyForSteadyModeHasNoShortHistoryStateSinceTheNoPeriodChange(): void
    {
        // spec-topplista-steady-no-period (2026-10-06): Stadig tillväxt
        // dropped the period entirely, so $insufficientHistory is now
        // ignored for it too -- same as Flest ägare always was.
        self::assertSame(
            'Inga aktier med stadig tillväxt just nu.',
            LeaderboardController::emptyStateCopy(LeaderboardController::RANKING_STEADY, 'manad'),
        );
        self::assertSame(
            'Inga aktier med stadig tillväxt just nu.',
            LeaderboardController::emptyStateCopy(LeaderboardController::RANKING_STEADY, 'manad', true),
            'the insufficientHistory flag no longer has any effect on Stadig tillväxt',
        );
        self::assertSame(
            'Inga aktier med stadig tillväxt just nu.',
            LeaderboardController::emptyStateCopy(LeaderboardController::RANKING_STEADY, 'vecka', true),
        );
        self::assertSame(
            'Inga aktier hittades.',
            LeaderboardController::emptyStateCopy(LeaderboardController::RANKING_COUNT, 'manad', true),
            'Flest ägare has no period and no short-history state',
        );
    }

    public function testPlusDaysChipHtmlShowsPlusOverDataDaysAndNewOwnersInTheQuietGreyBadge(): void
    {
        $html = LeaderboardController::plusDaysChipHtml(15, 16, 1035);

        self::assertSame('15/16 · +1 035', strip_tags($html));
        self::assertStringContainsString('badge badge--nohist badge--plusdays', $html);
        self::assertStringNotContainsString('positive', $html, 'green means today\'s direction, not the period');
    }

    public function testPlusDaysChipHtmlAlwaysShowsTheDenominator(): void
    {
        $html = LeaderboardController::plusDaysChipHtml(5, 5, 4602);

        self::assertSame('5/5 · +4 602', strip_tags($html));
        self::assertStringContainsString('<span class="plusdays-part">5/5 ·</span> <span class="plusdays-part">+4 602</span>', $html, 'breaks only after the separator');
    }

    // -- spec-short-interest-badge-ui ------------------------------------------

    public function testShortThresholdIsFivePercentShared(): void
    {
        self::assertSame(5.0, ShortPositionRepository::BADGE_THRESHOLD_PCT);
        self::assertTrue(LeaderboardController::isShorted(5.0), 'exactly 5 qualifies');
        self::assertTrue(LeaderboardController::isShorted(15.82));
        self::assertFalse(LeaderboardController::isShorted(4.99));
        self::assertFalse(LeaderboardController::isShorted(null));
    }

    public function testShortBadgeHtmlFormatsOneDecimalWithDecimalCommaInItsOwnRedBadge(): void
    {
        $html = LeaderboardController::shortBadgeHtml(15.82);

        self::assertSame('Blankad 15,8 %', strip_tags($html));
        self::assertStringContainsString('class="badge badge--short"', $html);
        self::assertSame('Blankad 5,0 %', strip_tags(LeaderboardController::shortBadgeHtml(5.0)));
    }

    public function testShortBadgeHtmlIsEmptyBelowThresholdOrWithoutAPosition(): void
    {
        self::assertSame('', LeaderboardController::shortBadgeHtml(4.99));
        self::assertSame('', LeaderboardController::shortBadgeHtml(null));
        self::assertSame('', LeaderboardController::shortBadgeWithDateHtml(4.99, '2026-10-02'));
        self::assertSame('', LeaderboardController::shortBadgeWithDateHtml(null, null));
    }

    public function testShortBadgeWithDateHtmlAppendsFisOwnDateInSwedish(): void
    {
        self::assertSame('Blankad 15,8 % (FI 2 okt)', strip_tags(LeaderboardController::shortBadgeWithDateHtml(15.82, '2026-10-02')));
        self::assertStringContainsString('badge--short', LeaderboardController::shortBadgeWithDateHtml(15.82, '2026-10-02'));
        self::assertSame('Blankad 6,0 %', strip_tags(LeaderboardController::shortBadgeWithDateHtml(6.0, 'garbage')), 'no garbage date');
    }

    public function testSwedishShortDateUsesUnpaddedDayAndLowercaseMonthAbbreviation(): void
    {
        $expected = ['jan', 'feb', 'mar', 'apr', 'maj', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
        foreach ($expected as $i => $month) {
            self::assertSame('9 ' . $month, LeaderboardController::swedishShortDate(sprintf('2026-%02d-09', $i + 1)));
        }
        self::assertSame('31 dec', LeaderboardController::swedishShortDate('2026-12-31'));
        self::assertNull(LeaderboardController::swedishShortDate('2026-02-30'));
        self::assertNull(LeaderboardController::swedishShortDate(''));
    }

    public function testNormalizeViewTreatsShortsAsAViewParamInEveryModeAndGarbageAsOff(): void
    {
        foreach (['plus', 'steady', 'count'] as $ranking) {
            self::assertTrue(LeaderboardController::normalizeView(['ranking' => $ranking, 'shorts' => 'exclude'])['shorts'], $ranking);
        }
        self::assertFalse(LeaderboardController::normalizeView(['shorts' => 'yes'])['shorts']);
        self::assertFalse(LeaderboardController::normalizeView(['shorts' => ['x']])['shorts']);
        self::assertFalse(LeaderboardController::normalizeView([])['shorts']);
    }

    public function testSerializeViewEmitsShortsAfterSpikesOnlyWhenOn(): void
    {
        self::assertSame(
            'ranking=plus&spikes=exclude&shorts=exclude&market=LC',
            LeaderboardController::serializeView(LeaderboardController::normalizeView(['spikes' => 'exclude', 'shorts' => 'exclude', 'market' => 'LC'])),
        );
        self::assertSame(
            'ranking=count&shorts=exclude',
            LeaderboardController::serializeView(LeaderboardController::normalizeView(['ranking' => 'count', 'shorts' => 'exclude'])),
        );
        self::assertSame('ranking=count', LeaderboardController::serializeView(LeaderboardController::normalizeView(['ranking' => 'count'])));
    }

    public function testShortsCookieRoundTripsAndShortsInQueryIsAuthoritative(): void
    {
        $view = LeaderboardController::parseViewCookie('ranking=count&shorts=exclude');
        self::assertSame('count', $view['ranking']);
        self::assertTrue($view['shorts']);
        self::assertSame($view, LeaderboardController::parseViewCookie(LeaderboardController::serializeView($view)));

        // Bare `/` with that cookie: the remembered view, toggle on.
        self::assertSame(['view' => $view, 'write' => false], LeaderboardController::resolveView([], 'ranking=count&shorts=exclude'));

        // `shorts` alone in the query is authoritative (cookie ignored).
        $resolved = LeaderboardController::resolveView(['shorts' => 'exclude'], 'ranking=count&market=LC');
        self::assertSame(['view' => LeaderboardController::normalizeView(['shorts' => 'exclude']), 'write' => true], $resolved);
    }
}
