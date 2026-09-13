<?php

declare(strict_types=1);

namespace Stockpicker\Web;

use Stockpicker\Adapter\NormalizedRow;
use Stockpicker\Store\DerivedMetricsRepository;
use Stockpicker\Store\WatchlistRepository;

/**
 * Story 4.2 — renders the authenticated `/` Topplista page: a Source
 * switcher (Avanza/Nordnet), a Ranking-mode toggle ("Flest ägare" / "Stadig
 * tillväxt"), and the top-10 Leaderboard rows for the resolved (source,
 * ranking) pair, each with its Watchlist star, badges, sparkline, owner
 * count and delta chip.
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
    public function render(string $source, string $rankingMode): string
    {
        $source = $source === NormalizedRow::SOURCE_NORDNET
            ? NormalizedRow::SOURCE_NORDNET
            : NormalizedRow::SOURCE_AVANZA;
        $rankingMode = $rankingMode === self::RANKING_STEADY
            ? self::RANKING_STEADY
            : self::RANKING_COUNT;

        $rows = $rankingMode === self::RANKING_STEADY
            ? $this->metrics->topByTrendQuality($source, self::TOP_N)
            : $this->metrics->topByOwnerCount($source, self::TOP_N);

        $starred = array_flip($this->watchlist->starredIsins());

        if ($rows === []) {
            $bodyHtml = '<p class="empty-state">' . self::e(self::emptyStateCopy($rankingMode)) . '</p>';
        } else {
            $bodyHtml = '';
            foreach ($rows as $i => $row) {
                $bodyHtml .= $this->renderRow($row, $source, $i + 1, isset($starred[(string) $row['isin']]));
            }
        }

        return self::pageHtml($source, $rankingMode, $bodyHtml);
    }

    /**
     * Empty-state copy (I/O & Edge-Case Matrix, spec-4-2). Only the
     * steady-growth zero-qualifiers case has product-specified copy; the
     * owner-count mode's empty case is not reachable in normal operation
     * (it would mean zero active instruments) but still gets a plain
     * fallback rather than a blank page.
     */
    public static function emptyStateCopy(string $rankingMode): string
    {
        return $rankingMode === self::RANKING_STEADY
            ? 'Inga aktier med stadig tillväxt just nu.'
            : 'Inga aktier hittades.';
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
     * value is unavailable (no >1-day-gap-free predecessor to compare to).
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
     * @param array<string, mixed> $row one topByOwnerCount()/topByTrendQuality() row
     */
    private function renderRow(array $row, string $source, int $rank, bool $starred): string
    {
        $isin = (string) $row['isin'];
        $name = (string) $row['name'];
        $owners = (int) $row['number_of_owners'];
        $delta = $row['delta_1d'] !== null ? (int) $row['delta_1d'] : null;
        $pct = $row['pct_1d'] !== null ? (float) $row['pct_1d'] : null;
        $upStreak = $row['up_streak'] !== null ? (int) $row['up_streak'] : null;
        $spikeScore = $row['spike_score'] !== null ? (float) $row['spike_score'] : null;
        $muted = self::isSparklineMuted($row['sma_7']);

        $series = $this->metrics->recentSeries($isin, $source, self::SPARKLINE_WINDOW_DAYS);

        $badgesHtml = self::streakBadgeHtml($upStreak) . self::spikeBadgeHtml($spikeScore);
        $sparklineHtml = self::sparklineHtml($series, $muted, $spikeScore, $delta);
        $deltaChipHtml = self::deltaChipHtml($delta, $pct);

        $eIsin = self::e($isin);
        $eName = self::e($name);
        $eOwners = self::e(number_format($owners, 0, ',', ' '));
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

    private static function pageHtml(string $source, string $rankingMode, string $rowsHtml): string
    {
        $tabBar = self::tabBarHtml('topplista', $source);
        $sourceSwitcher = self::sourceSwitcherHtml($source, $rankingMode);
        $rankingToggle = self::rankingToggleHtml($source, $rankingMode);
        $fullListHref = self::e(self::fullListUrl($source));
        $infoHref = self::e(self::infoUrl($source));
        $css = self::css();

        return <<<HTML
        <!DOCTYPE html>
        <html lang="sv">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Topplista — stockpicker</title>
        <style>{$css}</style>
        </head>
        <body>
        <div class="page">
          {$tabBar}
          <header class="page-header">
            <div class="wordmark">STOCKPICKER</div>
            <h1>Topplista</h1>
            <p class="subtitle">Ägarantal, rankade. Spikar uppmärksammas, döljs inte.</p>
            <div class="controls">
              {$sourceSwitcher}
              {$rankingToggle}
            </div>
          </header>
          <main class="rows">
            {$rowsHtml}
          </main>
          <p class="full-list-link"><a href="{$fullListHref}">Visa fullständig lista</a></p>
          <p class="info-link"><a href="{$infoHref}">Vad betyder allt detta?</a></p>
        </div>
        <script src="/assets/watchlist.js" defer></script>
        </body>
        </html>

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
        $suffix = $source === NormalizedRow::SOURCE_NORDNET ? '?source=nordnet' : '';
        $topplistaHref = self::e('/' . $suffix);
        $watchlistHref = self::e('/watchlist' . $suffix);

        return <<<HTML
        <div class="tab-bar" role="tablist" aria-label="Sidor">
          <a class="{$topplistaClass}" href="{$topplistaHref}">Topplista</a>
          <a class="{$watchlistClass}" href="{$watchlistHref}">Bevakningslista</a>
        </div>
        HTML;
    }

    private static function sourceSwitcherHtml(string $source, string $rankingMode): string
    {
        $avanzaClass = $source === NormalizedRow::SOURCE_AVANZA ? 'tab tab--active' : 'tab';
        $nordnetClass = $source === NormalizedRow::SOURCE_NORDNET ? 'tab tab--active' : 'tab';
        $avanzaHref = self::e(self::url(NormalizedRow::SOURCE_AVANZA, $rankingMode));
        $nordnetHref = self::e(self::url(NormalizedRow::SOURCE_NORDNET, $rankingMode));

        return <<<HTML
        <div class="source-switcher" role="tablist" aria-label="Källa">
          <a class="{$avanzaClass}" href="{$avanzaHref}">Avanza</a>
          <a class="{$nordnetClass}" href="{$nordnetHref}">Nordnet</a>
        </div>
        HTML;
    }

    private static function rankingToggleHtml(string $source, string $rankingMode): string
    {
        $countClass = $rankingMode === self::RANKING_COUNT ? 'tab tab--active' : 'tab';
        $steadyClass = $rankingMode === self::RANKING_STEADY ? 'tab tab--active' : 'tab';
        $countHref = self::e(self::url($source, self::RANKING_COUNT));
        $steadyHref = self::e(self::url($source, self::RANKING_STEADY));

        return <<<HTML
        <div class="ranking-toggle" role="tablist" aria-label="Rankningsläge">
          <a class="{$countClass}" href="{$countHref}">Flest ägare</a>
          <a class="{$steadyClass}" href="{$steadyHref}">Stadig tillväxt</a>
        </div>
        HTML;
    }

    /**
     * Builds the "/" URL for a given (source, ranking) pair, omitting a
     * query param entirely when it is the default — so the default view's
     * own links stay a plain "/" (never stored server-side either way, AD-14).
     */
    private static function url(string $source, string $rankingMode): string
    {
        $params = [];
        if ($source === NormalizedRow::SOURCE_NORDNET) {
            $params['source'] = 'nordnet';
        }
        if ($rankingMode === self::RANKING_STEADY) {
            $params['ranking'] = 'steady';
        }

        return $params === [] ? '/' : '/?' . http_build_query($params);
    }

    /**
     * Story 4.4 — the footer's "Visa fullständig lista" link target:
     * `/list`, carrying the current Source forward (`?source={current}`,
     * spec's Code Map) so switching to the full list doesn't silently reset
     * back to Avanza. Omitted for the Avanza default, same
     * omit-when-default convention as url().
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

    private static function css(): string
    {
        return <<<'CSS'
        :root {
          --bg-app: #F5F6FA;
          --bg-surface: #FFFFFF;
          --border: #E4E7EC;
          --row-border: #EEF0F5;
          --control-bg: #ECEEF3;
          --text-primary: #101323;
          --text-secondary: #667085;
          --text-muted: #98A2B3;
          --brand: #5B4FE9;
          --brand-tint: #EEEDFD;
          --positive: #12B76A;
          --positive-tint: #E7F9F0;
          --negative: #F04438;
          --negative-tint: #FEECEB;
          --spike-bg: #FEF0C7;
          --spike-text: #B54708;
          --star-filled: #F5A623;
          --star-empty: #D0D5DD;
          --nohist-bg: #F2F4F7;
        }
        * { box-sizing: border-box; }
        body {
          margin: 0;
          background: var(--bg-app);
          color: var(--text-primary);
          font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
        }
        .page { max-width: 720px; margin: 0 auto; padding: 18px; }
        .tab-bar {
          display: inline-flex; background: var(--control-bg); border-radius: 9999px; padding: 3px; gap: 2px;
          margin: 0 0 14px;
        }
        .wordmark {
          font-size: 12px; font-weight: 800; letter-spacing: 0.06em;
          color: var(--brand); text-transform: uppercase;
        }
        h1 { font-size: 22px; font-weight: 800; margin: 4px 0 2px; }
        .subtitle { font-size: 12.5px; color: var(--text-secondary); margin: 0 0 16px; }
        .controls { display: flex; flex-direction: column; gap: 8px; margin-bottom: 18px; }
        .source-switcher, .ranking-toggle {
          display: inline-flex; background: var(--control-bg); border-radius: 9999px; padding: 3px; gap: 2px;
        }
        .tab {
          padding: 8px 14px; border-radius: 9999px; text-decoration: none;
          font-size: 13px; font-weight: 700; color: var(--text-secondary);
        }
        .source-switcher .tab--active, .tab-bar .tab--active { background: var(--text-primary); color: var(--bg-surface); }
        .ranking-toggle .tab--active {
          background: var(--bg-surface); color: var(--brand);
          box-shadow: 0 1px 3px rgba(16,19,31,0.12);
        }
        .rows { display: flex; flex-direction: column; gap: 8px; }
        .row {
          display: flex; align-items: center; gap: 10px;
          background: var(--bg-surface); border: 1px solid var(--row-border);
          border-radius: 16px; padding: 10px 12px;
        }
        .rank { font-size: 12px; font-weight: 800; color: var(--text-muted); width: 1.5em; text-align: center; }
        .star {
          background: none; border: none; cursor: pointer; font-size: 18px; line-height: 1;
          padding: 8px; min-width: 44px; min-height: 44px;
        }
        .star--filled { color: var(--star-filled); }
        .star--empty { color: var(--star-empty); }
        .row-body {
          display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0;
          text-decoration: none; color: inherit;
        }
        .namecol { display: flex; flex-direction: column; min-width: 0; flex: 1 1 auto; }
        .name {
          display: block; font-size: 13.5px; font-weight: 700; overflow: hidden;
          text-overflow: ellipsis; white-space: nowrap;
        }
        .badges { display: flex; gap: 4px; flex-wrap: wrap; margin-top: 3px; }
        .badge {
          font-size: 9px; font-weight: 800; text-transform: uppercase;
          border-radius: 9999px; padding: 3px 7px; white-space: nowrap;
        }
        .badge--streak { background: var(--brand-tint); color: var(--brand); }
        .badge--spike { background: var(--spike-bg); color: var(--spike-text); }
        .badge--nohist { background: var(--nohist-bg); color: var(--text-muted); }
        .trend {
          flex: 0 0 auto; width: 52px; display: flex; flex-direction: column;
          align-items: flex-start; gap: 2px;
        }
        .sparkline { width: 52px; height: 22px; }
        .sparkline--empty { width: 52px; height: 22px; display: inline-block; }
        .sparkline-label {
          font-size: 9px; color: var(--text-muted); white-space: normal;
          overflow-wrap: break-word; max-width: 52px; line-height: 1.25;
        }
        .sparkline-line--positive { fill: none; stroke: var(--positive); stroke-width: 2px; }
        .sparkline-line--negative { fill: none; stroke: var(--negative); stroke-width: 2px; }
        .sparkline-line--neutral { fill: none; stroke: var(--brand); stroke-width: 2px; }
        .sparkline-line--spike { fill: none; stroke: var(--spike-text); stroke-width: 2px; }
        .sparkline-line--nohistory { fill: none; stroke: var(--text-muted); stroke-width: 2px; stroke-dasharray: 2 3; }
        .statcol { text-align: right; flex-shrink: 0; width: 70px; }
        .stat { display: block; font-size: 13.5px; font-weight: 800; white-space: nowrap; }
        .delta-chip {
          display: inline-block; margin-top: 3px;
          font-size: 9.5px; font-weight: 800; border-radius: 6px; padding: 3px 6px;
          white-space: nowrap;
        }
        .delta-chip--positive { background: var(--positive-tint); color: var(--positive); }
        .delta-chip--negative { background: var(--negative-tint); color: var(--negative); }
        .delta-chip--neutral { background: var(--nohist-bg); color: var(--text-secondary); }
        .empty-state { color: var(--text-secondary); font-size: 13.5px; }
        .full-list-link { text-align: center; margin: 16px 0 4px; }
        .full-list-link a { color: var(--brand); font-size: 13px; font-weight: 700; text-decoration: none; }
        .info-link { text-align: center; margin: 4px 0 0; }
        .info-link a { color: var(--text-secondary); font-size: 12px; font-weight: 700; text-decoration: none; }
        @media (min-width: 900px) {
          .page { max-width: 960px; box-shadow: 0 12px 40px rgba(16,19,31,0.08); border-radius: 20px; background: var(--bg-app); }
          .controls { flex-direction: row; }
          .trend { width: 130px; }
          .sparkline, .sparkline--empty { width: 130px; height: 30px; }
          .sparkline-label { max-width: 130px; }
        }
        CSS;
    }
}
