<?php

declare(strict_types=1);

namespace Stockpicker\Web;

use Stockpicker\Adapter\NormalizedRow;
use Stockpicker\Store\DerivedMetricsRepository;

/**
 * Story 4.5 — renders the authenticated `/watchlist` Bevakningslista page:
 * every currently-starred instrument for the selected source, as the same
 * Leaderboard row shape as Topplista/Fullständig lista (star/badges/
 * sparkline/delta unchanged), with a Source switcher and no other controls
 * (no search/sort/filter — a personal watchlist is small by construction,
 * Boundaries & Constraints spec-4-5).
 *
 * Reuses DerivedMetricsRepository::searchAndFilter() with the `watchlist`
 * filter Story 4.4 already built — no new repository method (spec-4-5). The
 * `watchlist` table has no source column (a starred isin's data is still
 * per-source), so Source is per-request only here too (AD-14), same
 * precedent as every other list page.
 *
 * Badge/delta helpers (isSparklineMuted, isSpiking, streakBadgeHtml,
 * spikeBadgeHtml, deltaChipHtml) are reused as-is from LeaderboardController
 * (Boundaries & Constraints, spec-4-5) rather than re-implemented here, same
 * as FullListController — same for the `.row-body` markup itself
 * (rowBodyHtml(), spec-5-1's namecol/trend/statcol mobile layout fix).
 */
final class WatchlistController
{
    private const SPARKLINE_WINDOW_DAYS = 30;

    public function __construct(
        private readonly DerivedMetricsRepository $metrics,
    ) {
    }

    /**
     * Renders the full page. $rawSource is the raw query value as a string
     * (or absent, coerced to '' by the caller) — unrecognized values fall
     * back to the default here too, so a garbage query string never 500s
     * (same tolerance as FullListController::render()).
     */
    public function render(string $rawSource): string
    {
        $source = self::normalizeSource($rawSource);

        $rows = $this->metrics->searchAndFilter($source, ['watchlist' => true], DerivedMetricsRepository::SORT_COUNT);

        if ($rows === []) {
            $bodyHtml = self::emptyStateHtml();
        } else {
            $isins = array_column($rows, 'isin');
            $seriesByIsin = $this->metrics->recentSeriesForIsins($isins, $source, self::SPARKLINE_WINDOW_DAYS);

            $bodyHtml = '';
            foreach ($rows as $row) {
                $isin = (string) $row['isin'];
                // Every row here is already guaranteed starred by construction
                // (searchAndFilter()'s 'watchlist' filter is `isin IN (SELECT
                // isin FROM watchlist)`) — no second query needed, and none of
                // the non-atomic-read race a separate starredIsins() lookup
                // would introduce against a concurrent /watchlist/toggle.
                $bodyHtml .= $this->renderRow($row, $seriesByIsin[$isin] ?? [], true);
            }
        }

        return self::pageHtml($source, $bodyHtml);
    }

    // -- Query param normalization (pure, unit-testable) -----------------------

    public static function normalizeSource(string $source): string
    {
        return $source === NormalizedRow::SOURCE_NORDNET
            ? NormalizedRow::SOURCE_NORDNET
            : NormalizedRow::SOURCE_AVANZA;
    }

    public static function emptyStateCopy(): string
    {
        return 'Inga aktier bevakade än.';
    }

    /**
     * The zero-starred body (I/O & Edge-Case Matrix, spec-4-5): the copy
     * plus a link back to `/` (no "rensa filter" — there is nothing to
     * clear, Code Map). A pure static so it can be exercised without a
     * database or HTTP request.
     */
    public static function emptyStateHtml(): string
    {
        return '<p class="empty-state">' . self::e(self::emptyStateCopy())
            . ' <a href="/">Till Topplista</a></p>';
    }

    // -- Row rendering -----------------------------------------------------

    /**
     * @param array<string, mixed> $row one searchAndFilter() row
     * @param list<array{as_of_date: string, number_of_owners: int}> $series
     */
    private function renderRow(array $row, array $series, bool $starred): string
    {
        $isin = (string) $row['isin'];
        $name = (string) $row['name'];
        $owners = (int) $row['number_of_owners'];
        $delta = $row['delta_1d'] !== null ? (int) $row['delta_1d'] : null;
        $pct = $row['pct_1d'] !== null ? (float) $row['pct_1d'] : null;
        $upStreak = $row['up_streak'] !== null ? (int) $row['up_streak'] : null;
        $spikeScore = $row['spike_score'] !== null ? (float) $row['spike_score'] : null;
        $muted = LeaderboardController::isSparklineMuted($row['sma_7']);

        $badgesHtml = LeaderboardController::streakBadgeHtml($upStreak) . LeaderboardController::spikeBadgeHtml($spikeScore);
        $sparklineHtml = self::sparklineHtml($series, $muted, $spikeScore, $delta);
        $deltaChipHtml = LeaderboardController::deltaChipHtml($delta, $pct);

        $eIsin = self::e($isin);
        $eName = self::e($name);
        $eOwners = self::e(number_format($owners, 0, ',', ' '));
        $starGlyph = $starred ? '★' : '☆';
        $starClass = $starred ? 'star star--filled' : 'star star--empty';
        $starLabel = self::e($starred ? 'Ta bort från bevakningslistan' : 'Lägg till i bevakningslistan');
        $ariaPressed = $starred ? 'true' : 'false';
        $rowBodyHtml = LeaderboardController::rowBodyHtml($eName, $badgesHtml, $sparklineHtml, $eOwners, $deltaChipHtml);

        return <<<HTML
        <div class="row">
          <button type="button" class="{$starClass}" data-isin="{$eIsin}" aria-pressed="{$ariaPressed}" aria-label="{$starLabel}">{$starGlyph}</button>
          <a class="row-body" href="/stock/{$eIsin}">
            {$rowBodyHtml}
          </a>
        </div>

        HTML;
    }

    /**
     * Same sparkline rendering as LeaderboardController::sparklineHtml()/
     * FullListController::sparklineHtml() (both private and out of the
     * reuse list — same precedent as spec-4-4), kept byte-for-byte
     * equivalent so the rendered row is visually identical.
     *
     * @param list<array{as_of_date: string, number_of_owners: int}> $series
     */
    private static function sparklineHtml(array $series, bool $muted, ?float $spikeScore, ?int $delta): string
    {
        $count = count($series);

        if ($count < 2) {
            $label = self::e(LeaderboardController::sparklineNoHistoryLabel($count));

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
        } elseif (LeaderboardController::isSpiking($spikeScore)) {
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
            $svg .= '<span class="sparkline-label">' . self::e(LeaderboardController::sparklineNoHistoryLabel($count)) . '</span>';
        }

        return $svg;
    }

    // -- Page assembly -----------------------------------------------------

    private static function pageHtml(string $source, string $rowsHtml): string
    {
        $tabBar = self::tabBarHtml('watchlist', $source);
        $sourceSwitcher = self::sourceSwitcherHtml($source);
        $css = self::css();

        return <<<HTML
        <!DOCTYPE html>
        <html lang="sv">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Bevakningslista — stockpicker</title>
        <style>{$css}</style>
        </head>
        <body>
        <div class="page">
          {$tabBar}
          <header class="page-header">
            <div class="wordmark">STOCKPICKER</div>
            <h1>Bevakningslista</h1>
            <div class="controls">
              {$sourceSwitcher}
            </div>
          </header>
          <main class="rows">
            {$rowsHtml}
          </main>
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
     * duplicated byte-for-byte across LeaderboardController,
     * FullListController, StockDetailController and here — not a new gap.
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

    private static function sourceSwitcherHtml(string $source): string
    {
        $avanzaClass = $source === NormalizedRow::SOURCE_AVANZA ? 'tab tab--active' : 'tab';
        $nordnetClass = $source === NormalizedRow::SOURCE_NORDNET ? 'tab tab--active' : 'tab';
        $avanzaHref = self::e(self::url(NormalizedRow::SOURCE_AVANZA));
        $nordnetHref = self::e(self::url(NormalizedRow::SOURCE_NORDNET));

        return <<<HTML
        <div class="source-switcher" role="tablist" aria-label="Källa">
          <a class="{$avanzaClass}" href="{$avanzaHref}">Avanza</a>
          <a class="{$nordnetClass}" href="{$nordnetHref}">Nordnet</a>
        </div>
        HTML;
    }

    /**
     * Builds the "/watchlist" URL for a given source, omitting the query
     * param entirely for the Avanza default — same AD-14-friendly,
     * nothing-stored pattern as the other controllers' url() helpers.
     */
    private static function url(string $source): string
    {
        return $source === NormalizedRow::SOURCE_NORDNET
            ? '/watchlist?source=nordnet'
            : '/watchlist';
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
        h1 { font-size: 22px; font-weight: 800; margin: 4px 0 12px; }
        .controls { display: flex; flex-direction: column; gap: 8px; margin-bottom: 18px; }
        .source-switcher {
          display: inline-flex; background: var(--control-bg); border-radius: 9999px; padding: 3px; gap: 2px;
        }
        .tab {
          padding: 8px 14px; border-radius: 9999px; text-decoration: none;
          font-size: 13px; font-weight: 700; color: var(--text-secondary);
        }
        .source-switcher .tab--active, .tab-bar .tab--active { background: var(--text-primary); color: var(--bg-surface); }
        .rows { display: flex; flex-direction: column; gap: 8px; }
        .row {
          display: flex; align-items: center; gap: 10px;
          background: var(--bg-surface); border: 1px solid var(--row-border);
          border-radius: 16px; padding: 10px 12px;
        }
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
        .empty-state a { color: var(--brand); font-weight: 700; }
        @media (min-width: 900px) {
          .page { max-width: 960px; box-shadow: 0 12px 40px rgba(16,19,31,0.08); border-radius: 20px; background: var(--bg-app); }
          .controls { flex-direction: row; flex-wrap: wrap; }
          .trend { width: 130px; }
          .sparkline, .sparkline--empty { width: 130px; height: 30px; }
          .sparkline-label { max-width: 130px; }
        }
        CSS;
    }
}
