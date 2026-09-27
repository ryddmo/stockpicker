<?php

declare(strict_types=1);

namespace Stockpicker\Web;

use Stockpicker\Adapter\NormalizedRow;
use Stockpicker\Store\DerivedMetricsRepository;
use Stockpicker\Store\WatchlistRepository;

/**
 * Story 4.4 — renders the authenticated `/list` Fullständig lista page:
 * every active instrument for the selected source, as the same Leaderboard
 * row shape as Topplista (rank position dropped, star/badges/sparkline/delta
 * unchanged), with a Source switcher and combinable search/sort/filter
 * controls (search box, sort toggle, Steady-growth/Spike/Watchlist-only
 * toggles, a single-select Market filter).
 *
 * Search/sort/filters/source are all per-request query params, never stored
 * (AD-14) — this controller never reads or writes `settings`, same
 * precedent as LeaderboardController/StockDetailController. No pagination
 * (spec-4-4): the full filtered/sorted result renders in one page load, and
 * every visible row's sparkline is fetched in one batched query
 * (DerivedMetricsRepository::recentSeriesForIsins()) rather than Story 4.2's
 * per-row recentSeries() pattern, which is only acceptable at Topplista's
 * fixed 10-row scale.
 *
 * No client framework or build step (AD-12): the search box is a plain GET
 * form, sort/filter/source are plain links — every control's link/form
 * target carries forward all other currently-active params (Design Notes),
 * same pattern as LeaderboardController::url(). Badge/delta helpers
 * (hasStreak, isSpiking, isSparklineMuted, streakBadgeHtml, spikeBadgeHtml,
 * deltaChipHtml) are reused as-is from LeaderboardController (Boundaries &
 * Constraints, spec-4-4) rather than re-implemented here — same for the
 * `.row-body` markup itself (rowBodyHtml(), spec-5-1's namecol/trend/statcol
 * mobile layout fix).
 */
final class FullListController
{
    private const SPARKLINE_WINDOW_DAYS = 30;

    /** @var list<string> the literal stored `instrument.list` values (Code Map) */
    private const MARKETS = ['LC', 'MC', 'SC', 'First North'];

    public function __construct(
        private readonly DerivedMetricsRepository $metrics,
        private readonly WatchlistRepository $watchlist,
    ) {
    }

    /**
     * Renders the full page. $rawSource/$rawParams are the raw query values
     * as strings (or absent) — every value is normalized here, so a garbage
     * query string never 500s (same tolerance as LeaderboardController::
     * render()/StockDetailController::render()).
     *
     * @param array<string, string> $rawParams raw $_GET values, already
     *     coerced to string by the caller (public_html/index.php)
     */
    public function render(string $rawSource, array $rawParams): string
    {
        $source = self::normalizeSource($rawSource);
        $q = self::normalizeQuery($rawParams['q'] ?? null);
        $sort = self::normalizeSort($rawParams['sort'] ?? null);
        $growth = self::normalizeFlag($rawParams['growth'] ?? null);
        $spike = self::normalizeFlag($rawParams['spike'] ?? null);
        $watchlistOnly = self::normalizeFlag($rawParams['watchlist'] ?? null);
        $market = self::normalizeMarket($rawParams['market'] ?? null);

        $active = [
            'source' => $source,
            'q' => $q,
            'sort' => $sort,
            'growth' => $growth,
            'spike' => $spike,
            'watchlist' => $watchlistOnly,
            'market' => $market,
        ];

        $rows = $this->metrics->searchAndFilter($source, [
            'q' => $q,
            'growth' => $growth,
            'spike' => $spike,
            'watchlist' => $watchlistOnly,
            'market' => $market,
        ], $sort);

        $starred = array_flip($this->watchlist->starredIsins());

        if ($rows === []) {
            $bodyHtml = self::emptyStateHtml();
        } else {
            $isins = array_column($rows, 'isin');
            $seriesByIsin = $this->metrics->recentSeriesForIsins($isins, $source, self::SPARKLINE_WINDOW_DAYS);

            $bodyHtml = '';
            foreach ($rows as $row) {
                $isin = (string) $row['isin'];
                $bodyHtml .= $this->renderRow(
                    $row,
                    $seriesByIsin[$isin] ?? [],
                    isset($starred[$isin]),
                );
            }
        }

        return self::pageHtml($active, $bodyHtml, $rows !== []);
    }

    // -- Query param normalization (pure, unit-testable) -----------------------

    public static function normalizeSource(string $source): string
    {
        return $source === NormalizedRow::SOURCE_NORDNET
            ? NormalizedRow::SOURCE_NORDNET
            : NormalizedRow::SOURCE_AVANZA;
    }

    public static function normalizeQuery(?string $q): string
    {
        return $q === null ? '' : trim($q);
    }

    public static function normalizeSort(?string $sort): string
    {
        return $sort === DerivedMetricsRepository::SORT_PCT
            ? DerivedMetricsRepository::SORT_PCT
            : DerivedMetricsRepository::SORT_COUNT;
    }

    public static function normalizeFlag(?string $value): bool
    {
        return $value === '1';
    }

    /**
     * @return string|null one of self::MARKETS, or null when absent/unknown
     */
    public static function normalizeMarket(?string $market): ?string
    {
        return $market !== null && in_array($market, self::MARKETS, true) ? $market : null;
    }

    public static function emptyStateCopy(): string
    {
        return 'Inga resultat för dessa filter.';
    }

    /**
     * The zero-matches body (I/O & Edge-Case Matrix, spec-4-4): the copy
     * plus a "rensa filter" link back to a bare `/list` (no params at all,
     * dropping even Source — Design Notes/Acceptance Criteria). A pure
     * static so it can be exercised without a database or HTTP request.
     */
    public static function emptyStateHtml(): string
    {
        return '<p class="empty-state">' . self::e(self::emptyStateCopy())
            . ' <a href="/list">rensa filter</a></p>';
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
     * Same sparkline rendering as LeaderboardController::sparklineHtml() (its
     * own copy — that method is private and out of the reuse list, Boundaries
     * & Constraints spec-4-4), kept byte-for-byte equivalent so the rendered
     * row is visually identical to Topplista's.
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

    /**
     * @param array{source: string, q: string, sort: string, growth: bool, spike: bool, watchlist: bool, market: ?string} $active
     */
    private static function pageHtml(array $active, string $rowsHtml, bool $hasRows): string
    {
        $tabBar = self::tabBarHtml('topplista', $active['source']);
        $sourceSwitcher = self::sourceSwitcherHtml($active);
        $searchForm = self::searchFormHtml($active);
        $sortToggle = self::sortToggleHtml($active);
        $filterToggles = self::filterTogglesHtml($active);
        $marketFilter = self::marketFilterHtml($active);
        $rowHead = $hasRows ? self::rowHeadHtml() : '';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="sv">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Fullständig lista — stockpicker</title>
        <link rel="stylesheet" href="/assets/app.css">
        </head>
        <body>
        <div class="page">
          <div class="nav-strip">
            <div class="wordmark">Stockpicker</div>
            {$tabBar}
          </div>
          <header class="page-header">
            <h1>Fullständig lista</h1>
            <div class="controls">
              {$sourceSwitcher}
              {$searchForm}
              {$sortToggle}
              {$filterToggles}
              {$marketFilter}
            </div>
          </header>
          <main class="rows">
            {$rowHead}
            {$rowsHtml}
          </main>
        </div>
        <div class="wl-toast" id="wl-toast" role="status" aria-live="polite"></div>
        <script src="/assets/watchlist.js" defer></script>
        </body>
        </html>

        HTML;
    }

    /**
     * Desktop-only (≥900px, see app.css) column headers over the CSS-grid
     * table layout (design handbook §7). No Rank/Period columns here (this
     * list has neither), unlike LeaderboardController's rows--ranked variant.
     */
    private static function rowHeadHtml(): string
    {
        return <<<HTML
        <div class="row-head" role="row">
          <span class="col" role="columnheader"><span class="visually-hidden">Bevakning</span></span>
          <span class="col" role="columnheader">Bolag</span>
          <span class="col" role="columnheader">Trend</span>
          <span class="col" role="columnheader">Ägare</span>
          <span class="col" role="columnheader">Förändring</span>
        </div>
        HTML;
    }

    /**
     * Story 4.5 — the persistent two-tab bar (Topplista, Bevakningslista)
     * added to all four authenticated pages (Design Notes/Code Map,
     * spec-4-5), replacing the now-redundant "← Topplista" back-link (the
     * tab bar's own Topplista tab serves the identical purpose). No shared
     * layout file exists (Stories 4.3/4.4's already-accepted CSS
     * duplication debt), so this exact snippet is duplicated byte-for-byte
     * across LeaderboardController, StockDetailController,
     * WatchlistController and here — not a new gap. $active is 'topplista'
     * or 'watchlist'; Fullständig lista always marks 'topplista' active
     * (Boundaries & Constraints: it is reachable only via Topplista's own
     * footer action, never a tab of its own).
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

    /**
     * @param array{source: string, q: string, sort: string, growth: bool, spike: bool, watchlist: bool, market: ?string} $active
     */
    private static function sourceSwitcherHtml(array $active): string
    {
        $avanzaClass = $active['source'] === NormalizedRow::SOURCE_AVANZA ? 'tab tab--active' : 'tab';
        $nordnetClass = $active['source'] === NormalizedRow::SOURCE_NORDNET ? 'tab tab--active' : 'tab';
        $avanzaHref = self::e(self::url($active, ['source' => NormalizedRow::SOURCE_AVANZA]));
        $nordnetHref = self::e(self::url($active, ['source' => NormalizedRow::SOURCE_NORDNET]));

        return <<<HTML
        <div class="source-switcher" role="tablist" aria-label="Källa">
          <a class="{$avanzaClass}" href="{$avanzaHref}">Avanza</a>
          <a class="{$nordnetClass}" href="{$nordnetHref}">Nordnet</a>
        </div>
        HTML;
    }

    /**
     * A plain GET form (AD-12, no JS) — every other currently-active param
     * is carried forward as a hidden input, so submitting a new search term
     * never drops sort/filter/source.
     *
     * @param array{source: string, q: string, sort: string, growth: bool, spike: bool, watchlist: bool, market: ?string} $active
     */
    private static function searchFormHtml(array $active): string
    {
        $hidden = '';
        if ($active['source'] === NormalizedRow::SOURCE_NORDNET) {
            $hidden .= '<input type="hidden" name="source" value="nordnet">';
        }
        if ($active['sort'] === DerivedMetricsRepository::SORT_PCT) {
            $hidden .= '<input type="hidden" name="sort" value="pct">';
        }
        if ($active['growth']) {
            $hidden .= '<input type="hidden" name="growth" value="1">';
        }
        if ($active['spike']) {
            $hidden .= '<input type="hidden" name="spike" value="1">';
        }
        if ($active['watchlist']) {
            $hidden .= '<input type="hidden" name="watchlist" value="1">';
        }
        if ($active['market'] !== null) {
            $hidden .= '<input type="hidden" name="market" value="' . self::e($active['market']) . '">';
        }

        $qValue = self::e($active['q']);

        return <<<HTML
        <form class="search-form" method="get" action="/list" role="search">
          {$hidden}
          <input type="text" name="q" value="{$qValue}" placeholder="Sök bolag..." aria-label="Sök bolag">
          <button type="submit">Sök</button>
        </form>
        HTML;
    }

    /**
     * @param array{source: string, q: string, sort: string, growth: bool, spike: bool, watchlist: bool, market: ?string} $active
     */
    private static function sortToggleHtml(array $active): string
    {
        $countClass = $active['sort'] === DerivedMetricsRepository::SORT_COUNT ? 'tab tab--active' : 'tab';
        $pctClass = $active['sort'] === DerivedMetricsRepository::SORT_PCT ? 'tab tab--active' : 'tab';
        $countHref = self::e(self::url($active, ['sort' => DerivedMetricsRepository::SORT_COUNT]));
        $pctHref = self::e(self::url($active, ['sort' => DerivedMetricsRepository::SORT_PCT]));

        return <<<HTML
        <div class="sort-toggle" role="tablist" aria-label="Sortering">
          <a class="{$countClass}" href="{$countHref}">Flest ägare</a>
          <a class="{$pctClass}" href="{$pctHref}">% förändring</a>
        </div>
        HTML;
    }

    /**
     * Steady-growth/Spike/Watchlist-only — each an independent on/off toggle
     * (AND-combinable, unlike the single-select Market filter below).
     *
     * @param array{source: string, q: string, sort: string, growth: bool, spike: bool, watchlist: bool, market: ?string} $active
     */
    private static function filterTogglesHtml(array $active): string
    {
        $growthHref = self::e(self::url($active, ['growth' => !$active['growth']]));
        $spikeHref = self::e(self::url($active, ['spike' => !$active['spike']]));
        $watchlistHref = self::e(self::url($active, ['watchlist' => !$active['watchlist']]));

        $growthClass = $active['growth'] ? 'filter-toggle filter-toggle--active' : 'filter-toggle';
        $spikeClass = $active['spike'] ? 'filter-toggle filter-toggle--active' : 'filter-toggle';
        $watchlistClass = $active['watchlist'] ? 'filter-toggle filter-toggle--active' : 'filter-toggle';
        $growthPressed = $active['growth'] ? 'true' : 'false';
        $spikePressed = $active['spike'] ? 'true' : 'false';
        $watchlistPressed = $active['watchlist'] ? 'true' : 'false';

        return <<<HTML
        <div class="filter-toggles">
          <a class="{$growthClass}" href="{$growthHref}" aria-pressed="{$growthPressed}">Stadig tillväxt</a>
          <a class="{$spikeClass}" href="{$spikeHref}" aria-pressed="{$spikePressed}">Spik</a>
          <a class="{$watchlistClass}" href="{$watchlistHref}" aria-pressed="{$watchlistPressed}">Bevakade</a>
        </div>
        HTML;
    }

    /**
     * Single-select market filter — "Alla" (no filter) plus one link per
     * self::MARKETS value.
     *
     * @param array{source: string, q: string, sort: string, growth: bool, spike: bool, watchlist: bool, market: ?string} $active
     */
    private static function marketFilterHtml(array $active): string
    {
        $allClass = $active['market'] === null ? 'tab tab--active' : 'tab';
        $allHref = self::e(self::url($active, ['market' => null]));
        $links = sprintf('<a class="%s" href="%s">Alla</a>', $allClass, $allHref);

        foreach (self::MARKETS as $market) {
            $class = $active['market'] === $market ? 'tab tab--active' : 'tab';
            $href = self::e(self::url($active, ['market' => $market]));
            $links .= sprintf('<a class="%s" href="%s">%s</a>', $class, $href, self::e($market));
        }

        return '<div class="market-filter" role="tablist" aria-label="Lista">' . $links . '</div>';
    }

    /**
     * Builds the "/list" URL for $active with $overrides applied — every
     * other currently-active param is carried forward, omitting a param
     * entirely when it is the default (same AD-14-friendly, nothing-stored
     * pattern as LeaderboardController::url()/StockDetailController::url()).
     *
     * @param array{source: string, q: string, sort: string, growth: bool, spike: bool, watchlist: bool, market: ?string} $active
     * @param array<string, mixed> $overrides
     */
    private static function url(array $active, array $overrides): string
    {
        $merged = array_merge($active, $overrides);

        $params = [];
        if ($merged['source'] === NormalizedRow::SOURCE_NORDNET) {
            $params['source'] = 'nordnet';
        }
        if ($merged['q'] !== '') {
            $params['q'] = $merged['q'];
        }
        if ($merged['sort'] === DerivedMetricsRepository::SORT_PCT) {
            $params['sort'] = DerivedMetricsRepository::SORT_PCT;
        }
        if ($merged['growth']) {
            $params['growth'] = '1';
        }
        if ($merged['spike']) {
            $params['spike'] = '1';
        }
        if ($merged['watchlist']) {
            $params['watchlist'] = '1';
        }
        if ($merged['market'] !== null) {
            $params['market'] = $merged['market'];
        }

        return $params === [] ? '/list' : '/list?' . http_build_query($params);
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
