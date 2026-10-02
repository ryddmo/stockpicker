<?php

declare(strict_types=1);

namespace Stockpicker\Web;

use Stockpicker\Adapter\NormalizedRow;
use Stockpicker\Store\DerivedMetricsRepository;
use Stockpicker\Store\WatchlistRepository;

/**
 * Story 4.2 — renders the authenticated `/` Topplista page: a Source
 * switcher (Avanza/Nordnet), a Ranking-mode toggle ("Flest ägare" / "Stadig
 * tillväxt" / "Plusdagar"), and the top-10 Leaderboard rows for the resolved
 * (source, ranking) pair, each with its Watchlist star, badges, sparkline,
 * owner count and delta chip. In Plusdagar mode (spec-plusdagar) a period
 * line (Vecka · Månad · 3 mån · År) and a "Dölj spikar" toggle sit under
 * the ranking toggle, and each row carries a "{plus}/{data} · +{new}" chip.
 *
 * Source/ranking are per-request only (AD-14) — this controller never reads
 * or writes `settings`; the front controller resolves both from query
 * params (falling back to the defaults below) and passes them in.
 *
 * No templating engine (repo convention) — plain heredoc + htmlspecialchars,
 * same as AuthController::renderLoginPage(). The badge/no-history/spike
 * selection rules are exposed as small `public static` pure functions
 * (hasStreak(), isSpiking(), isSparklineMuted(), emptyStateCopy(), …) so
 * LeaderboardControllerTest can exercise the decision logic directly,
 * without a database or HTTP.
 */
final class LeaderboardController
{
    public const RANKING_COUNT = 'count';
    public const RANKING_STEADY = 'steady';
    public const RANKING_PLUS = 'plus';

    /**
     * spec-plusdagar — Plusdagar's period query values, mapped to their
     * calendar window (same N as the period chips, spec-calendar-period-
     * metrics), Swedish label, and the lowercase phrase used mid-sentence
     * in the empty state. PERIOD_DEFAULT is omitted from URLs.
     */
    public const PERIODS = [
        'vecka' => ['days' => 7, 'label' => 'Vecka', 'phrase' => 'vecka'],
        'manad' => ['days' => 30, 'label' => 'Månad', 'phrase' => 'månad'],
        '3man' => ['days' => 90, 'label' => '3 mån', 'phrase' => '3 mån'],
        'ar' => ['days' => 365, 'label' => 'År', 'phrase' => 'ett år'],
    ];
    public const PERIOD_DEFAULT = 'manad';

    /**
     * spec-plusdagar — periods whose window is long enough that a source
     * history shorter than the window renders the "För lite historik"
     * empty state instead of a partial ranking (I/O matrix: 3 mån / År).
     */
    private const PERIODS_REQUIRING_FULL_HISTORY = ['3man', 'ar'];

    /** spec-plusdagar — `?spikes=exclude` opts in to hiding spiking rows. */
    public const SPIKES_EXCLUDE = 'exclude';

    /**
     * spec-5-4 — Topplista's third Source-switcher mode: a Web-layer-only
     * display concept (deliberately not added to NormalizedRow, which
     * represents real adapter data sources, not a display mode). In "Alla"
     * mode ranking/badges/sparkline always use Avanza as the basis
     * (self::normalizeSource()/render()'s $rankingSource); Nordnet's latest
     * owner count is fetched for the same isins and shown display-only,
     * alongside, never as a ranking or summed figure (NFR6).
     */
    public const SOURCE_ALL = 'alla';

    private const TOP_N = 10;
    private const SPARKLINE_WINDOW_DAYS = 30;

    public function __construct(
        private readonly DerivedMetricsRepository $metrics,
        private readonly WatchlistRepository $watchlist,
    ) {
    }

    /**
     * Renders the full page for the given (already-fallback-resolved-by-the-
     * caller-or-not) source/ranking query values. Unrecognized values fall
     * back to the defaults here too, so a garbage query string never 500s.
     */
    public function render(string $source, string $rankingMode, string $period = '', string $spikes = ''): string
    {
        $source = self::normalizeSource($source);
        $rankingMode = self::normalizeRanking($rankingMode);
        $period = self::normalizePeriod($period);
        $excludeSpikes = $spikes === self::SPIKES_EXCLUDE;

        // Alla mode ranks by Avanza's data always (Intent) — badges/
        // sparkline/rank basis stay Avanza-derived, Nordnet is display-only.
        $rankingSource = $source === self::SOURCE_ALL ? NormalizedRow::SOURCE_AVANZA : $source;

        $insufficientHistory = false;
        if ($rankingMode === self::RANKING_PLUS) {
            $days = self::PERIODS[$period]['days'];
            $insufficientHistory = in_array($period, self::PERIODS_REQUIRING_FULL_HISTORY, true)
                && !$this->metrics->historySpansDays($rankingSource, $days);
            $rows = $insufficientHistory
                ? []
                : $this->metrics->topByPlusDays($rankingSource, $days, $excludeSpikes, self::TOP_N);
        } elseif ($rankingMode === self::RANKING_STEADY) {
            $rows = $this->metrics->topByTrendQuality($rankingSource, self::TOP_N);
        } else {
            $rows = $this->metrics->topByOwnerCount($rankingSource, self::TOP_N);
        }

        $starred = array_flip($this->watchlist->starredIsins());

        $nordnetOwners = [];
        if ($source === self::SOURCE_ALL && $rows !== []) {
            $isins = array_map(static fn (array $row): string => (string) $row['isin'], $rows);
            $nordnetOwners = $this->metrics->latestOwnerCountForIsins($isins, NormalizedRow::SOURCE_NORDNET);
        }

        if ($rows === []) {
            $bodyHtml = '<p class="empty-state">'
                . self::e(self::emptyStateCopy($rankingMode, $period, $insufficientHistory)) . '</p>';
        } else {
            $bodyHtml = '';
            foreach ($rows as $i => $row) {
                $isin = (string) $row['isin'];
                $rowNordnetOwners = $source === self::SOURCE_ALL ? ($nordnetOwners[$isin] ?? null) : null;
                $bodyHtml .= $this->renderRow($row, $source, $rankingMode, $i + 1, isset($starred[$isin]), $rowNordnetOwners);
            }
        }

        return self::pageHtml($source, $rankingMode, $period, $excludeSpikes, $bodyHtml, $rows !== []);
    }

    /**
     * 'steady' | 'plus' | anything else (including garbage) falls back to
     * the 'count' default — a garbage query value never 500s.
     */
    public static function normalizeRanking(string $rankingMode): string
    {
        return match ($rankingMode) {
            self::RANKING_STEADY => self::RANKING_STEADY,
            self::RANKING_PLUS => self::RANKING_PLUS,
            default => self::RANKING_COUNT,
        };
    }

    /**
     * spec-plusdagar — one of self::PERIODS' keys; anything else (including
     * garbage) falls back to self::PERIOD_DEFAULT (Månad).
     */
    public static function normalizePeriod(string $period): string
    {
        return array_key_exists($period, self::PERIODS) ? $period : self::PERIOD_DEFAULT;
    }

    /**
     * Three-way Source normalization (mirrors StockDetailController's/
     * FullListController's/WatchlistController's two-way
     * normalizeSource()): 'alla' | 'nordnet' | anything else (including
     * garbage) falls back to 'avanza' — a garbage query value never 500s.
     */
    public static function normalizeSource(string $source): string
    {
        return match ($source) {
            self::SOURCE_ALL => self::SOURCE_ALL,
            NormalizedRow::SOURCE_NORDNET => NormalizedRow::SOURCE_NORDNET,
            default => NormalizedRow::SOURCE_AVANZA,
        };
    }

    /**
     * Alla mode's owner cell (Intent/AC): Avanza and Nordnet stacked as two
     * `.stat-line`s — the two sources are always shown side by side, never
     * summed (NFR6: different populations, neither is the legal shareholder
     * count). One line per source, each `white-space: nowrap`, so the 100px
     * column never breaks a number at its thousands-separator space. An isin
     * with no stored Nordnet data ($nordnetOwners === null,
     * latestOwnerCountForIsins()'s "absent from the map" signal) shows
     * "Nordnet ingen data" instead of a misleading zero. Returns escaped HTML,
     * ready for rowBodyHtml()'s $eOwners.
     */
    public static function combinedOwnerCountHtml(int $avanzaOwners, ?int $nordnetOwners): string
    {
        $avanzaText = number_format($avanzaOwners, 0, ',', ' ');
        $nordnetText = $nordnetOwners !== null
            ? number_format($nordnetOwners, 0, ',', ' ')
            : 'ingen data';

        return self::ownerLineHtml('Avanza', $avanzaText) . self::ownerLineHtml('Nordnet', $nordnetText);
    }

    private static function ownerLineHtml(string $sourceLabel, string $value): string
    {
        return '<span class="stat-line"><span class="stat-src">' . self::e($sourceLabel) . '</span> '
            . self::e($value) . '</span>';
    }

    /**
     * Empty-state copy (I/O & Edge-Case Matrix, spec-4-2/spec-plusdagar).
     * Steady growth and Plusdagar have product-specified copy; the
     * owner-count mode's empty case is not reachable in normal operation
     * (it would mean zero active instruments) but still gets a plain
     * fallback rather than a blank page. Plusdagar distinguishes "the
     * source's history is shorter than the 3 mån/År window"
     * ($insufficientHistory) from "nothing gained owners in the period".
     */
    public static function emptyStateCopy(
        string $rankingMode,
        string $period = self::PERIOD_DEFAULT,
        bool $insufficientHistory = false,
    ): string {
        if ($rankingMode === self::RANKING_PLUS) {
            if ($insufficientHistory) {
                return sprintf('För lite historik för %s ännu.', self::PERIODS[self::normalizePeriod($period)]['phrase']);
            }

            return 'Inga aktier med fler ägare under perioden.';
        }

        return $rankingMode === self::RANKING_STEADY
            ? 'Inga aktier med stadig tillväxt just nu.'
            : 'Inga aktier hittades.';
    }

    /**
     * spec-plusdagar — the row's "16/18 · +1 035" chip: plus days / data
     * days in the period (the denominator is always shown), then the
     * period's new owners. Same quiet grey as the "flat" badge
     * (`badge--nohist`): the numbers carry the signal, not the colour.
     */
    public static function plusDaysChipHtml(int $plusDays, int $dataDays, int $newOwners): string
    {
        $sign = $newOwners > 0 ? '+' : ($newOwners < 0 ? '-' : '');
        $daysText = sprintf('%d/%d ·', $plusDays, $dataDays);
        $newText = $sign . number_format(abs($newOwners), 0, ',', ' ');

        // Two nowrap parts so a narrow 390px name column can only break the
        // chip after the "·", never inside a number (text reads
        // "15/16 · +1 035" either way).
        return '<span class="badge badge--nohist badge--plusdays" title="Plusdagar / datadagar · nya ägare under perioden">'
            . '<span class="plusdays-part">' . self::e($daysText) . '</span> '
            . '<span class="plusdays-part">' . self::e($newText) . '</span></span>';
    }

    /**
     * Streak badge condition (Story 3.1's fixed view column, spec-4-2):
     * `up_streak >= 1`. NULL (first-ever row) does not qualify.
     */
    public static function hasStreak(?int $upStreak): bool
    {
        return $upStreak !== null && $upStreak >= 1;
    }

    /**
     * Spike badge condition: `spike_score >= 2`, upward only — a large
     * *negative* spike_score (a genuine drop) must never trigger this badge
     * (decided 2026-09-13).
     */
    public static function isSpiking(?float $spikeScore): bool
    {
        return $spikeScore !== null && $spikeScore >= DerivedMetricsRepository::SPIKE_THRESHOLD;
    }

    /**
     * Sparkline muted/dashed condition: `sma_7 IS NULL` (fewer than 7 stored
     * rows). Accepts the raw view value (string|null from PDO, or already
     * cast) so callers can pass the row field straight through.
     */
    public static function isSparklineMuted(mixed $sma7): bool
    {
        return $sma7 === null;
    }

    public static function sparklineNoHistoryLabel(int $trackedDays): string
    {
        return sprintf('%dd spårade · ingen trend än', $trackedDays);
    }

    public static function streakBadgeHtml(?int $upStreak): string
    {
        if (self::hasStreak($upStreak)) {
            return '<span class="badge badge--streak">🔥 ' . $upStreak . 'd</span>';
        }

        return '<span class="badge badge--nohist">flat</span>';
    }

    public static function spikeBadgeHtml(?float $spikeScore): string
    {
        if (!self::isSpiking($spikeScore)) {
            return '';
        }

        return '<span class="badge badge--spike">⚡ spike</span>';
    }

    /**
     * "+412 · 0,9 %" — count and percent always together (DESIGN.md), sign
     * shown on the count, magnitude-only on the percent. Empty when either
     * value is unavailable (no stored predecessor within 5 days to compare to).
     */
    public static function deltaChipHtml(?int $delta, ?float $pct): string
    {
        if ($delta === null || $pct === null) {
            return '';
        }

        $sign = $delta > 0 ? '+' : ($delta < 0 ? '-' : '');
        $deltaText = $sign . number_format(abs($delta), 0, ',', ' ');
        $pctText = number_format(abs($pct) * 100, 1, ',', '') . ' %';
        $cls = $delta > 0 ? 'positive' : ($delta < 0 ? 'negative' : 'neutral');

        return sprintf(
            '<span class="delta-chip delta-chip--%s">%s · %s</span>',
            $cls,
            self::e($deltaText),
            self::e($pctText),
        );
    }

    /**
     * spec-5-5 / spec-calendar-period-metrics — one labeled period percentage
     * ("Vecka"/"Månad"/"3 mån"/"År" + `pct_7d`/`pct_30d`/`pct_90d`/
     * `pct_365d`): `+2,1 %`/`-2,1 %`/`0,0 %`, signed and colored by
     * sign (same positive/negative/neutral convention as deltaChipHtml()),
     * or a `period-pct--nohist` "–" mark with a title when `$pct` is `null`
     * (the view's NULL — history too short, or the nearest row on or before
     * as_of_date − N lies more than N+5 days back; never rendered as a
     * misleading number).
     */
    public static function periodPctItemHtml(string $label, ?float $pct): string
    {
        $eLabel = self::e($label);

        if ($pct === null) {
            return sprintf(
                '<span class="period-pct period-pct--nohist" title="Ingen jämförbar dag">'
                    . '<span class="period-pct-label">%s</span><span class="period-pct-value">–</span></span>',
                $eLabel,
            );
        }

        // Sign/color must come from the *rounded* value (review round,
        // iteration 1): a tiny raw pct like -0.00001 rounds to a displayed
        // "0,0 %" but a sign/class derived from the unrounded float would
        // still show it as negative -- a self-contradictory "-0,0 %".
        $roundedPct = round($pct * 100, 1);
        $sign = $roundedPct > 0 ? '+' : ($roundedPct < 0 ? '-' : '');
        $valueText = $sign . number_format(abs($roundedPct), 1, ',', '') . ' %';
        $cls = $roundedPct > 0 ? 'positive' : ($roundedPct < 0 ? 'negative' : 'neutral');

        return sprintf(
            '<span class="period-pct period-pct--%s"><span class="period-pct-label">%s</span><span class="period-pct-value">%s</span></span>',
            $cls,
            $eLabel,
            self::e($valueText),
        );
    }

    /**
     * spec-5-5 / spec-calendar-period-metrics — the Vecka/Månad/3 mån/År line
     * below a Topplista row (calendar periods: 7/30/90/365 days). Today's
     * percentage is intentionally NOT repeated here — it's already shown via
     * deltaChipHtml()'s output in `.statcol`.
     */
    public static function periodPctsHtml(?float $pct7d, ?float $pct30d, ?float $pct90d, ?float $pct365d): string
    {
        return '<span class="period-pcts">'
            . self::periodPctItemHtml('Vecka', $pct7d)
            . self::periodPctItemHtml('Månad', $pct30d)
            . self::periodPctItemHtml('3 mån', $pct90d)
            . self::periodPctItemHtml('År', $pct365d)
            . '</span>';
    }

    /**
     * @param array<string, mixed> $row one topByOwnerCount()/topByTrendQuality() row
     * @param ?int $nordnetOwners only meaningful when $source is
     *   self::SOURCE_ALL (null otherwise) — Nordnet's latest owner count for
     *   this row's isin, or null when Nordnet has no stored data for it
     *   ("ingen data", never a misleading zero).
     */
    private function renderRow(array $row, string $source, string $rankingMode, int $rank, bool $starred, ?int $nordnetOwners): string
    {
        $isin = (string) $row['isin'];
        $name = (string) $row['name'];
        $owners = (int) $row['number_of_owners'];
        $delta = $row['delta_1d'] !== null ? (int) $row['delta_1d'] : null;
        $pct = $row['pct_1d'] !== null ? (float) $row['pct_1d'] : null;
        $pct7d = $row['pct_7d'] !== null ? (float) $row['pct_7d'] : null;
        $pct30d = $row['pct_30d'] !== null ? (float) $row['pct_30d'] : null;
        $pct90d = $row['pct_90d'] !== null ? (float) $row['pct_90d'] : null;
        $pct365d = $row['pct_365d'] !== null ? (float) $row['pct_365d'] : null;
        $upStreak = $row['up_streak'] !== null ? (int) $row['up_streak'] : null;
        $spikeScore = $row['spike_score'] !== null ? (float) $row['spike_score'] : null;
        $muted = self::isSparklineMuted($row['sma_7']);

        // Alla mode's badges/sparkline/rank basis are Avanza-derived
        // throughout (Intent) — $row itself already came from an
        // Avanza-ranked query (render()'s $rankingSource), so the
        // sparkline's own series fetch must match it rather than use the
        // page-level Alla source, which recentSeries() would not recognize.
        $dataSource = $source === self::SOURCE_ALL ? NormalizedRow::SOURCE_AVANZA : $source;
        $series = $this->metrics->recentSeries($isin, $dataSource, self::SPARKLINE_WINDOW_DAYS);

        $badgesHtml = self::streakBadgeHtml($upStreak);
        if ($rankingMode === self::RANKING_PLUS) {
            $badgesHtml .= self::plusDaysChipHtml((int) $row['plus_days'], (int) $row['data_days'], (int) $row['new_owners']);
        }
        $badgesHtml .= self::spikeBadgeHtml($spikeScore);
        $sparklineHtml = self::sparklineHtml($series, $muted, $spikeScore, $delta);
        $deltaChipHtml = self::deltaChipHtml($delta, $pct);
        $periodPctsHtml = self::periodPctsHtml($pct7d, $pct30d, $pct90d, $pct365d);

        $eIsin = self::e($isin);
        $eName = self::e($name);
        $eOwners = $source === self::SOURCE_ALL
            ? self::combinedOwnerCountHtml($owners, $nordnetOwners)
            : self::e(number_format($owners, 0, ',', ' '));
        $starGlyph = $starred ? '★' : '☆';
        $starClass = $starred ? 'star star--filled' : 'star star--empty';
        $starLabel = self::e($starred ? 'Ta bort från bevakningslistan' : 'Lägg till i bevakningslistan');
        $ariaPressed = $starred ? 'true' : 'false';
        $rowBodyHtml = self::rowBodyHtml($eName, $badgesHtml, $sparklineHtml, $eOwners, $deltaChipHtml);

        return <<<HTML
        <div class="row">
          <span class="rank">{$rank}</span>
          <button type="button" class="{$starClass}" data-isin="{$eIsin}" aria-pressed="{$ariaPressed}" aria-label="{$starLabel}">{$starGlyph}</button>
          <a class="row-body" href="/stock/{$eIsin}">
            {$rowBodyHtml}
          </a>
          {$periodPctsHtml}
        </div>

        HTML;
    }

    /**
     * spec-5-1 — the `.row-body` inner markup shared by all three list
     * controllers (Leaderboard/FullList/Watchlist). `.namecol` and `.statcol`
     * match the approved mockup's structure (`leaderboard-hero.html` lines
     * 103-136): `.namecol` nests name+badges in the one column that's
     * allowed to shrink (badges wrap below the name, EXPERIENCE.md's
     * "Streak-/Spike-badgar radbryts under radnamnet"); `.statcol` nests
     * owners+delta-chip in a protected, effectively-unshrinkable column
     * (UX-DR12: count+percent always shown together). `.trend`'s fixed-width
     * treatment is this story's own addition (not present in the mockup,
     * which has no "insufficient history" label at all) — it's a
     * fixed-width column so that label wraps within its own bound instead of
     * forcing the row wider. Together these replace the previous five flat,
     * mostly-unshrinkable flex siblings that let `.trend`'s label push the
     * owner count and delta chip off-screen at 390px. Extracted as its own
     * pure static function (not inlined in renderRow()) so it's unit-testable
     * without a database, same precedent as streakBadgeHtml()/deltaChipHtml()
     * above, and so FullListController/WatchlistController can reuse it
     * byte-for-byte instead of re-duplicating the markup (they already reuse
     * this class's badge/delta helpers the same way).
     *
     * $eName/$eOwners must already be htmlspecialchars-escaped by the caller
     * (same convention as the rest of this class); $badgesHtml/
     * $sparklineHtml/$deltaChipHtml are pre-rendered HTML fragments from the
     * other static helpers in this class.
     */
    public static function rowBodyHtml(
        string $eName,
        string $badgesHtml,
        string $sparklineHtml,
        string $eOwners,
        string $deltaChipHtml,
    ): string {
        return <<<HTML
        <span class="namecol">
          <span class="name">{$eName}</span>
          <span class="badges">{$badgesHtml}</span>
        </span>
        <span class="trend">{$sparklineHtml}</span>
        <span class="statcol">
          <span class="stat">{$eOwners}</span>
          {$deltaChipHtml}
        </span>
        HTML;
    }

    /**
     * @param list<array{as_of_date: string, number_of_owners: int}> $series
     */
    private static function sparklineHtml(array $series, bool $muted, ?float $spikeScore, ?int $delta): string
    {
        $count = count($series);

        if ($count < 2) {
            $label = self::e(self::sparklineNoHistoryLabel($count));

            return '<span class="sparkline sparkline--empty" aria-hidden="true"></span>'
                . '<span class="sparkline-label">' . $label . '</span>';
        }

        $values = array_column($series, 'number_of_owners');
        $min = min($values);
        $max = max($values);
        $range = $max - $min;
        $width = 130;
        $height = 30;

        $points = [];
        foreach ($values as $i => $v) {
            $x = ($i / ($count - 1)) * $width;
            $y = $range === 0 ? $height / 2 : $height - (($v - $min) / $range) * $height;
            $points[] = sprintf('%.2f,%.2f', $x, $y);
        }

        if ($muted) {
            $strokeClass = 'sparkline-line--nohistory';
        } elseif (self::isSpiking($spikeScore)) {
            $strokeClass = 'sparkline-line--spike';
        } elseif ($delta !== null && $delta > 0) {
            $strokeClass = 'sparkline-line--positive';
        } elseif ($delta !== null && $delta < 0) {
            $strokeClass = 'sparkline-line--negative';
        } else {
            $strokeClass = 'sparkline-line--neutral';
        }

        $svg = sprintf(
            '<svg class="sparkline" viewBox="0 0 %d %d" preserveAspectRatio="none" role="img" aria-hidden="true"><polyline class="%s" points="%s" /></svg>',
            $width,
            $height,
            $strokeClass,
            self::e(implode(' ', $points)),
        );

        if ($muted) {
            $svg .= '<span class="sparkline-label">' . self::e(self::sparklineNoHistoryLabel($count)) . '</span>';
        }

        return $svg;
    }

    private static function pageHtml(
        string $source,
        string $rankingMode,
        string $period,
        bool $excludeSpikes,
        string $rowsHtml,
        bool $hasRows,
    ): string {
        $tabBar = self::tabBarHtml('topplista', $source);
        $sourceSwitcher = self::sourceSwitcherHtml($source, $rankingMode, $period, $excludeSpikes);
        $rankingToggle = self::rankingToggleHtml($source, $rankingMode, $period, $excludeSpikes);
        $plusOptions = $rankingMode === self::RANKING_PLUS
            ? self::plusOptionsHtml($source, $period, $excludeSpikes)
            : '';
        $fullListHref = self::e(self::fullListUrl($source));
        $infoHref = self::e(self::infoUrl($source));
        $rowHead = $hasRows ? self::rowHeadHtml() : '';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="sv">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Topplista — stockpicker</title>
        <link rel="stylesheet" href="/assets/app.css">
        </head>
        <body>
        <div class="page">
          <div class="nav-strip">
            <div class="wordmark">Stockpicker</div>
            {$tabBar}
          </div>
          <header class="page-header">
            <h1>Topplista</h1>
            <p class="subtitle">Ägarantal, rankade. Spikar uppmärksammas, döljs inte.</p>
            <div class="controls">
              {$sourceSwitcher}
              <div class="ranking-group">
                {$rankingToggle}
                {$plusOptions}
              </div>
            </div>
          </header>
          <main class="rows rows--ranked">
            {$rowHead}
            {$rowsHtml}
          </main>
          <p class="full-list-link"><a href="{$fullListHref}">Visa fullständig lista</a></p>
          <p class="info-link"><a href="{$infoHref}">Vad betyder allt detta?</a></p>
        </div>
        <div class="wl-toast" id="wl-toast" role="status" aria-live="polite"></div>
        <script src="/assets/watchlist.js" defer></script>
        </body>
        </html>

        HTML;
    }

    /**
     * Desktop-only (≥900px, see app.css) column headers over the CSS-grid
     * table layout — spec's "Kolumnrubriker visas" (design handbook §7).
     * Suppressed entirely on an empty result set (render()'s $hasRows) so no
     * header floats above the empty-state paragraph.
     *
     * `aria-hidden`, not `role="row"`/`role="columnheader"`: a `.row`'s
     * `.row-body` is a single `<a>` spanning Bolag+Trend+Ägare+Förändring
     * (the design handbook's own "hela raden är klickbar" §7 requirement),
     * so `.row` can never validly carry `role="row"` with per-column
     * `role="cell"` children — a link's implicit `role="link"` sitting
     * between them breaks the required row→cell ARIA table structure, and
     * browsers/AT then may not expose *any* table semantics, header
     * included. Real ARIA table roles here would need dropping the
     * whole-row-link pattern, a bigger change than this header decoration
     * justifies. Each row already reads correctly in plain sequential order
     * without a table announcement (star has its own aria-label, the
     * sparkline is aria-hidden, every value is plain adjacent text) — so the
     * header is purely a sighted-desktop affordance, hidden from
     * assistive tech rather than exposed with structurally-invalid roles
     * (found during the design handbook's P2 accessibility QA pass).
     */
    private static function rowHeadHtml(): string
    {
        return <<<HTML
        <div class="row-head" aria-hidden="true">
          <span class="col">#</span>
          <span class="col"></span>
          <span class="col">Bolag</span>
          <span class="col">Trend</span>
          <span class="col">Ägare</span>
          <span class="col">Förändring</span>
          <span class="col">Period</span>
        </div>
        HTML;
    }

    /**
     * Story 4.5 — the persistent two-tab bar (Topplista, Bevakningslista)
     * added to all four authenticated pages (Design Notes/Code Map,
     * spec-4-5). No shared layout file exists (Stories 4.3/4.4's
     * already-accepted CSS duplication debt), so this exact snippet is
     * duplicated byte-for-byte across FullListController,
     * StockDetailController, WatchlistController and here — not a new gap.
     * $active is 'topplista' or 'watchlist'; Fullständig lista/Aktiedetalj
     * both mark 'topplista' active (Boundaries & Constraints: Fullständig
     * lista is reachable only via Topplista's own footer action, never a
     * tab of its own).
     */
    private static function tabBarHtml(string $active, string $source): string
    {
        $topplistaClass = $active === 'topplista' ? 'tab tab--active' : 'tab';
        $watchlistClass = $active === 'watchlist' ? 'tab tab--active' : 'tab';
        // spec-5-4 (review round, iteration 1): unlike fullListUrl()/infoUrl()
        // (whose targets don't support Alla, so dropping the param is
        // harmless), this tab bar's own "Topplista" link points at Topplista
        // itself, which *does* support Alla — so it must carry SOURCE_ALL
        // forward too, or clicking it while already in Alla mode silently
        // resets the view back to Avanza.
        $suffix = match ($source) {
            self::SOURCE_ALL => '?source=alla',
            NormalizedRow::SOURCE_NORDNET => '?source=nordnet',
            default => '',
        };
        $topplistaHref = self::e('/' . $suffix);
        $watchlistHref = self::e('/watchlist' . $suffix);

        return <<<HTML
        <div class="tab-bar" role="tablist" aria-label="Sidor">
          <a class="{$topplistaClass}" href="{$topplistaHref}">Topplista</a>
          <a class="{$watchlistClass}" href="{$watchlistHref}">Bevakningslista</a>
        </div>
        HTML;
    }

    private static function sourceSwitcherHtml(string $source, string $rankingMode, string $period, bool $excludeSpikes): string
    {
        // spec-5-4 — Alla shown first, before Avanza and Nordnet (Intent).
        // spec-plusdagar — period and spike toggle survive a source switch.
        $allaClass = $source === self::SOURCE_ALL ? 'tab tab--active' : 'tab';
        $avanzaClass = $source === NormalizedRow::SOURCE_AVANZA ? 'tab tab--active' : 'tab';
        $nordnetClass = $source === NormalizedRow::SOURCE_NORDNET ? 'tab tab--active' : 'tab';
        $allaHref = self::e(self::url(self::SOURCE_ALL, $rankingMode, $period, $excludeSpikes));
        $avanzaHref = self::e(self::url(NormalizedRow::SOURCE_AVANZA, $rankingMode, $period, $excludeSpikes));
        $nordnetHref = self::e(self::url(NormalizedRow::SOURCE_NORDNET, $rankingMode, $period, $excludeSpikes));

        return <<<HTML
        <div class="source-switcher" role="tablist" aria-label="Källa">
          <a class="{$allaClass}" href="{$allaHref}">Alla</a>
          <a class="{$avanzaClass}" href="{$avanzaHref}">Avanza</a>
          <a class="{$nordnetClass}" href="{$nordnetHref}">Nordnet</a>
        </div>
        HTML;
    }

    private static function rankingToggleHtml(string $source, string $rankingMode, string $period, bool $excludeSpikes): string
    {
        $countClass = $rankingMode === self::RANKING_COUNT ? 'tab tab--active' : 'tab';
        $steadyClass = $rankingMode === self::RANKING_STEADY ? 'tab tab--active' : 'tab';
        $plusClass = $rankingMode === self::RANKING_PLUS ? 'tab tab--active' : 'tab';
        $countHref = self::e(self::url($source, self::RANKING_COUNT));
        $steadyHref = self::e(self::url($source, self::RANKING_STEADY));
        $plusHref = self::e(self::url($source, self::RANKING_PLUS, $period, $excludeSpikes));
        // The active Plusdagar tab carries its period ("Plusdagar · Månad").
        $plusLabel = self::e($rankingMode === self::RANKING_PLUS
            ? 'Plusdagar · ' . self::PERIODS[$period]['label']
            : 'Plusdagar');

        return <<<HTML
        <div class="ranking-toggle" role="tablist" aria-label="Rankningsläge">
          <a class="{$countClass}" href="{$countHref}">Flest ägare</a>
          <a class="{$steadyClass}" href="{$steadyHref}">Stadig tillväxt</a>
          <a class="{$plusClass}" href="{$plusHref}">{$plusLabel}</a>
        </div>
        HTML;
    }

    /**
     * spec-plusdagar — the line directly under the ranking toggle, Plusdagar
     * mode only: plain period links "Vecka · Månad · 3 mån · År" (active
     * one marked) and the checkbox-styled "☐/☑ Dölj spikar" link. Plain
     * links, no JS (AD-12); source and the other control survive each click.
     */
    private static function plusOptionsHtml(string $source, string $period, bool $excludeSpikes): string
    {
        $links = [];
        foreach (self::PERIODS as $key => $def) {
            $href = self::e(self::url($source, self::RANKING_PLUS, $key, $excludeSpikes));
            $label = self::e($def['label']);
            $links[] = $key === $period
                ? "<a class=\"period-link period-link--active\" href=\"{$href}\" aria-current=\"true\">{$label}</a>"
                : "<a class=\"period-link\" href=\"{$href}\">{$label}</a>";
        }
        $periodLinks = implode('<span class="period-sep" aria-hidden="true">·</span>', $links);

        $spikeHref = self::e(self::url($source, self::RANKING_PLUS, $period, !$excludeSpikes));
        $spikeClass = $excludeSpikes ? 'spike-toggle spike-toggle--active' : 'spike-toggle';
        $spikeGlyph = $excludeSpikes ? '☑' : '☐';

        return <<<HTML
        <div class="plus-options">
          <nav class="period-links" aria-label="Period">{$periodLinks}</nav>
          <a class="{$spikeClass}" href="{$spikeHref}"><span aria-hidden="true">{$spikeGlyph}</span> Dölj spikar</a>
        </div>
        HTML;
    }

    /**
     * Builds the "/" URL for a given (source, ranking[, period, spikes])
     * combination, omitting a query param entirely when it is the default —
     * so the default view's own links stay a plain "/" (never stored
     * server-side either way, AD-14). `period`/`spikes` only exist in
     * Plusdagar mode and are dropped for the other rankings.
     */
    private static function url(
        string $source,
        string $rankingMode,
        string $period = self::PERIOD_DEFAULT,
        bool $excludeSpikes = false,
    ): string {
        $params = [];
        if ($source === self::SOURCE_ALL) {
            $params['source'] = 'alla';
        } elseif ($source === NormalizedRow::SOURCE_NORDNET) {
            $params['source'] = 'nordnet';
        }
        if ($rankingMode === self::RANKING_STEADY) {
            $params['ranking'] = 'steady';
        } elseif ($rankingMode === self::RANKING_PLUS) {
            $params['ranking'] = 'plus';
            if ($period !== self::PERIOD_DEFAULT) {
                $params['period'] = $period;
            }
            if ($excludeSpikes) {
                $params['spikes'] = self::SPIKES_EXCLUDE;
            }
        }

        return $params === [] ? '/' : '/?' . http_build_query($params);
    }

    /**
     * Story 4.4 — the footer's "Visa fullständig lista" link target:
     * `/list`, carrying the current Source forward (`?source={current}`,
     * spec's Code Map) so switching to the full list doesn't silently reset
     * back to Avanza. Omitted for the Avanza default, same
     * omit-when-default convention as url(). One deliberate exception
     * (spec-5-4): `self::SOURCE_ALL` also omits the param — Fullständig
     * lista has no Alla concept of its own (Boundaries: unchanged by that
     * story), so landing there on Avanza is the only sensible target, not a
     * regression of this docblock's guarantee.
     */
    private static function fullListUrl(string $source): string
    {
        return $source === NormalizedRow::SOURCE_NORDNET
            ? '/list?source=nordnet'
            : '/list';
    }

    private static function infoUrl(string $source): string
    {
        return $source === NormalizedRow::SOURCE_NORDNET
            ? '/info?source=nordnet'
            : '/info';
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
