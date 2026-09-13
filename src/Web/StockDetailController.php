<?php

declare(strict_types=1);

namespace Stockpicker\Web;

use Stockpicker\Adapter\NormalizedRow;
use Stockpicker\Store\DerivedMetricsRepository;
use Stockpicker\Store\Instrument;
use Stockpicker\Store\WatchlistRepository;

/**
 * Story 4.3 — renders the authenticated `/stock/{isin}` Aktiedetalj page: a
 * header (instrument name, Watchlist star, Streak/Spike badges), a dual-line
 * Trend overlay (primary = the Source-switcher selection, secondary = the
 * other source, always fixed/dashed), a five-segment Range picker
 * (Dag/Vecka/30d/90d/Ar), and a Source switcher.
 *
 * Both sources' full metrics series are always fetched via
 * DerivedMetricsRepository::forIsin() (AD-14) — the Range picker only slices
 * the already-fetched arrays client-request-side (a plain link reload, no
 * client framework, AD-12), never a second query. Unknown-isin 404 is
 * resolved by the caller (public_html/index.php), same precedent as
 * /watchlist/toggle — this controller is only ever called with a known
 * Instrument.
 *
 * The range-gate/slice rules are exposed as small `public static` pure
 * functions (same pattern as LeaderboardController) so
 * StockDetailControllerTest can exercise them directly, without a database or
 * HTTP.
 */
final class StockDetailController
{
    public const RANGE_DAG = 'dag';
    public const RANGE_VECKA = 'vecka';
    public const RANGE_30D = '30d';
    public const RANGE_90D = '90d';
    public const RANGE_AR = 'ar';

    public function __construct(
        private readonly DerivedMetricsRepository $metrics,
        private readonly WatchlistRepository $watchlist,
    ) {
    }

    /**
     * Renders the full page for an already-resolved Instrument. $source and
     * $range are raw query values — unrecognized values fall back to the
     * defaults here too, so a garbage query string never 500s (same
     * tolerance as LeaderboardController::render()).
     */
    public function render(Instrument $instrument, string $source, string $range): string
    {
        $source = self::normalizeSource($source);
        $range = self::normalizeRange($range);
        $secondarySource = $source === NormalizedRow::SOURCE_NORDNET
            ? NormalizedRow::SOURCE_AVANZA
            : NormalizedRow::SOURCE_NORDNET;

        $seriesBySource = $this->metrics->forIsin($instrument->isin);
        $primarySeries = $seriesBySource[$source] ?? [];
        $secondarySeries = $seriesBySource[$secondarySource] ?? [];

        $starred = in_array($instrument->isin, $this->watchlist->starredIsins(), true);

        $latestPrimary = $primarySeries === [] ? null : end($primarySeries);
        $upStreak = $latestPrimary !== null && $latestPrimary['up_streak'] !== null
            ? (int) $latestPrimary['up_streak']
            : null;
        $spikeScore = $latestPrimary !== null && $latestPrimary['spike_score'] !== null
            ? (float) $latestPrimary['spike_score']
            : null;

        $badgesHtml = LeaderboardController::streakBadgeHtml($upStreak) . LeaderboardController::spikeBadgeHtml($spikeScore);

        if (self::isInsufficientHistory($primarySeries, $range)) {
            $chartHtml = '<p class="empty-state">' . self::e(self::insufficientHistoryMessage($primarySeries, $range)) . '</p>';
        } else {
            $primarySlice = self::sliceForRange($primarySeries, $range);
            $secondarySlice = self::sliceForRange($secondarySeries, $range);
            $chartHtml = self::trendOverlayHtml($primarySlice, $secondarySlice)
                . self::legendHtml($source, $secondarySource, count($primarySlice) >= 2, count($secondarySlice) >= 2);
        }

        return self::pageHtml($instrument, $source, $range, $badgesHtml, $starred, $chartHtml);
    }

    // -- Range gate/slice rules (pure, unit-testable) -------------------------

    public static function normalizeRange(string $range): string
    {
        return match ($range) {
            self::RANGE_VECKA, self::RANGE_30D, self::RANGE_90D, self::RANGE_AR => $range,
            default => self::RANGE_DAG,
        };
    }

    public static function normalizeSource(string $source): string
    {
        return $source === NormalizedRow::SOURCE_NORDNET
            ? NormalizedRow::SOURCE_NORDNET
            : NormalizedRow::SOURCE_AVANZA;
    }

    /**
     * Number of trailing rows the range window shows. `null` (Ar) means "all
     * available rows" — uncapped (Design Notes, spec-4-3).
     */
    public static function windowSize(string $range): ?int
    {
        return match ($range) {
            self::RANGE_VECKA => 7,
            self::RANGE_30D => 30,
            self::RANGE_90D => 90,
            self::RANGE_AR => null,
            default => 2, // Dag
        };
    }

    /**
     * Minimum primary-source row count required before the range renders a
     * chart at all. Ar shares 90d's floor (Design Notes, spec-4-3) — a
     * sub-90-row "year" view would look exactly as thin as a sub-90-row "90d"
     * view.
     */
    public static function gateThreshold(string $range): int
    {
        return match ($range) {
            self::RANGE_VECKA => 7,
            self::RANGE_30D => 30,
            self::RANGE_90D, self::RANGE_AR => 90,
            default => 2, // Dag
        };
    }

    /**
     * @param list<array<string, mixed>> $series
     *
     * @return list<array<string, mixed>>
     */
    public static function sliceForRange(array $series, string $range): array
    {
        $n = self::windowSize($range);

        return $n === null ? $series : array_slice($series, -$n);
    }

    /**
     * The insufficiency gate is evaluated only against the *primary*
     * source's row count (NFR6, Design Notes) — the secondary line renders
     * with whatever it has, un-gated.
     *
     * @param list<array<string, mixed>> $primarySeries
     */
    public static function isInsufficientHistory(array $primarySeries, string $range): bool
    {
        return count($primarySeries) < self::gateThreshold($range);
    }

    /**
     * @param list<array<string, mixed>> $primarySeries
     */
    public static function daysUntilSufficient(array $primarySeries, string $range): int
    {
        return max(0, self::gateThreshold($range) - count($primarySeries));
    }

    /**
     * @param list<array<string, mixed>> $primarySeries
     */
    public static function insufficientHistoryMessage(array $primarySeries, string $range): string
    {
        return sprintf(
            'Inte tillräckligt med historik för det här intervallet ännu — kolla in igen om %d dagar',
            self::daysUntilSufficient($primarySeries, $range),
        );
    }

    // -- Trend overlay ---------------------------------------------------------

    /**
     * The primary line's contextual color key, from the *last row of the
     * visible slice* — same signal LeaderboardController's sparkline uses
     * (muted/no-history over spike over delta direction). The secondary line
     * never uses this: it is always the fixed, non-contextual dashed style
     * (spec-4-3), regardless of its own trend direction.
     *
     * @param list<array<string, mixed>> $primarySlice
     */
    public static function primaryColorKey(array $primarySlice): string
    {
        if ($primarySlice === []) {
            return 'nohistory';
        }

        $last = end($primarySlice);
        if (LeaderboardController::isSparklineMuted($last['sma_7'])) {
            return 'nohistory';
        }

        $spikeScore = $last['spike_score'] !== null ? (float) $last['spike_score'] : null;
        if (LeaderboardController::isSpiking($spikeScore)) {
            return 'spike';
        }

        $delta = $last['delta_1d'] !== null ? (int) $last['delta_1d'] : null;
        if ($delta !== null && $delta > 0) {
            return 'positive';
        }
        if ($delta !== null && $delta < 0) {
            return 'negative';
        }

        return 'neutral';
    }

    /**
     * @param list<array<string, mixed>> $primarySlice
     * @param list<array<string, mixed>> $secondarySlice
     */
    private static function trendOverlayHtml(array $primarySlice, array $secondarySlice): string
    {
        $width = 320;
        $height = 120;
        $domain = self::sharedDateDomain($primarySlice, $secondarySlice);

        $secondaryPoints = self::scaledPoints($secondarySlice, $domain, $width, $height);
        $primaryPoints = self::scaledPoints($primarySlice, $domain, $width, $height);

        $secondaryPolyline = $secondaryPoints !== ''
            ? sprintf('<polyline class="trend-line trend-line--secondary" points="%s" />', self::e($secondaryPoints))
            : '';

        $primaryClass = 'trend-line trend-line--' . self::primaryColorKey($primarySlice);
        $primaryPolyline = $primaryPoints !== ''
            ? sprintf('<polyline class="%s" points="%s" />', $primaryClass, self::e($primaryPoints))
            : '';

        // Secondary drawn first so the primary line renders on top.
        return sprintf(
            '<svg class="trend-overlay" viewBox="0 0 %d %d" preserveAspectRatio="none" role="img" aria-label="Trend">%s%s</svg>',
            $width,
            $height,
            $secondaryPolyline,
            $primaryPolyline,
        );
    }

    /**
     * The calendar span both slices are positioned against — the union of
     * their `as_of_date` values, oldest to newest. Computed once per render
     * so both lines share one x-axis, since Avanza and Nordnet coverage can
     * genuinely differ for the same isin (Nordnet's instrument id resolves
     * separately from Avanza's, so its history can start later) — without a
     * shared domain, positioning by array index would stretch two different
     * calendar spans across the same pixel width.
     *
     * @param list<array<string, mixed>> $primarySlice
     * @param list<array<string, mixed>> $secondarySlice
     *
     * @return array{0: string, 1: string} [oldest as_of_date, newest as_of_date]
     */
    private static function sharedDateDomain(array $primarySlice, array $secondarySlice): array
    {
        $dates = [
            ...array_column($primarySlice, 'as_of_date'),
            ...array_column($secondarySlice, 'as_of_date'),
        ];

        if ($dates === []) {
            $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');

            return [$today, $today];
        }

        sort($dates);

        return [(string) $dates[0], (string) $dates[count($dates) - 1]];
    }

    /**
     * Scales one series' number_of_owners values to the given viewBox: x is
     * each point's `as_of_date` positioned within the shared $domain (so two
     * series with different row counts/date coverage still align by
     * calendar date, not by array index); y is normalized to this series'
     * own min/max, independently of the other series (LeaderboardController::
     * sparklineHtml()'s scaling technique, applied per-line) — each source
     * keeps its own magnitude scale so two very different population sizes
     * (NFR6: Avanza and Nordnet never share a scale) still overlay as
     * comparable shapes. Empty when fewer than 2 points (nothing to draw a
     * line through).
     *
     * @param list<array<string, mixed>> $slice
     * @param array{0: string, 1: string} $domain [oldest as_of_date, newest as_of_date]
     */
    public static function scaledPoints(array $slice, array $domain, int $width, int $height): string
    {
        $count = count($slice);
        if ($count < 2) {
            return '';
        }

        $values = array_map(static fn (array $row): int => (int) $row['number_of_owners'], $slice);
        $min = min($values);
        $max = max($values);
        $range = $max - $min;

        [$domainStart, $domainEnd] = $domain;
        $domainSpanDays = self::daysBetween($domainStart, $domainEnd);

        $points = [];
        foreach ($slice as $i => $row) {
            $x = $domainSpanDays === 0
                ? $width / 2
                : (self::daysBetween($domainStart, (string) $row['as_of_date']) / $domainSpanDays) * $width;
            $y = $range === 0 ? $height / 2 : $height - (($values[$i] - $min) / $range) * $height;
            $points[] = sprintf('%.2f,%.2f', $x, $y);
        }

        return implode(' ', $points);
    }

    /**
     * Whole calendar days from $from to $to (Y-m-d dates, UTC — these are
     * plain calendar dates, never wall-clock times, so DST cannot skew the
     * count). Signed: negative when $to precedes $from.
     */
    private static function daysBetween(string $from, string $to): int
    {
        $a = new \DateTimeImmutable($from, new \DateTimeZone('UTC'));
        $b = new \DateTimeImmutable($to, new \DateTimeZone('UTC'));

        return (int) $a->diff($b)->format('%r%a');
    }

    private static function legendHtml(
        string $primarySource,
        string $secondarySource,
        bool $primaryDrawn,
        bool $secondaryDrawn,
    ): string {
        $primaryItem = self::legendItemHtml($primarySource, 'legend-swatch--primary', $primaryDrawn);
        $secondaryItem = self::legendItemHtml($secondarySource, 'legend-swatch--secondary', $secondaryDrawn);

        return <<<HTML
        <p class="legend">
          {$primaryItem}
          {$secondaryItem}
        </p>
        HTML;
    }

    /**
     * A source whose slice has fewer than 2 points has no line drawn at all
     * (scaledPoints() returns '' below that) — its legend entry must say so
     * rather than promising a swatch/color for a line that isn't there.
     */
    private static function legendItemHtml(string $source, string $swatchClass, bool $drawn): string
    {
        $label = self::e(self::sourceLabel($source));

        if (!$drawn) {
            return '<span class="legend-item legend-item--nodata">' . $label . ' (ingen data ännu)</span>';
        }

        return '<span class="legend-item"><span class="legend-swatch ' . $swatchClass . '"></span>' . $label . '</span>';
    }

    private static function sourceLabel(string $source): string
    {
        return $source === NormalizedRow::SOURCE_NORDNET ? 'Nordnet' : 'Avanza';
    }

    // -- Page assembly -----------------------------------------------------

    private static function pageHtml(
        Instrument $instrument,
        string $source,
        string $range,
        string $badgesHtml,
        bool $starred,
        string $chartHtml,
    ): string {
        $isin = $instrument->isin;
        $eIsin = self::e($isin);
        $eName = self::e($instrument->name);
        $starGlyph = $starred ? '★' : '☆';
        $starClass = $starred ? 'star star--filled' : 'star star--empty';
        $starLabel = self::e($starred ? 'Ta bort från bevakningslistan' : 'Lägg till i bevakningslistan');
        $ariaPressed = $starred ? 'true' : 'false';

        $tabBar = self::tabBarHtml('topplista', $source);
        $rangePicker = self::rangePickerHtml($isin, $source, $range);
        $sourceSwitcher = self::sourceSwitcherHtml($isin, $source, $range);
        $css = self::css();

        return <<<HTML
        <!DOCTYPE html>
        <html lang="sv">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$eName} — stockpicker</title>
        <style>{$css}</style>
        </head>
        <body>
        <div class="page">
          {$tabBar}
          <header class="stock-header">
            <button type="button" class="{$starClass}" data-isin="{$eIsin}" aria-pressed="{$ariaPressed}" aria-label="{$starLabel}">{$starGlyph}</button>
            <h1>{$eName}</h1>
            <span class="badges">{$badgesHtml}</span>
          </header>
          <div class="controls">
            {$sourceSwitcher}
            {$rangePicker}
          </div>
          <main class="chart-area">
            {$chartHtml}
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
     * spec-4-5), replacing the now-redundant "← Topplista" back-link (the
     * tab bar's own Topplista tab serves the identical purpose). No shared
     * layout file exists (Stories 4.3/4.4's already-accepted CSS
     * duplication debt), so this exact snippet is duplicated byte-for-byte
     * across LeaderboardController, FullListController,
     * WatchlistController and here — not a new gap. $active is 'topplista'
     * or 'watchlist'; Aktiedetalj always marks 'topplista' active
     * (Boundaries & Constraints: Fullständig lista/Aktiedetalj are
     * reachable only via Topplista's own footer action, never a tab of
     * their own).
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

    private static function rangePickerHtml(string $isin, string $source, string $range): string
    {
        $labels = [
            self::RANGE_DAG => 'Dag',
            self::RANGE_VECKA => 'Vecka',
            self::RANGE_30D => '30d',
            self::RANGE_90D => '90d',
            self::RANGE_AR => 'År',
        ];

        $links = '';
        foreach ($labels as $value => $label) {
            $class = $value === $range ? 'tab tab--active' : 'tab';
            $href = self::e(self::url($isin, $source, $value));
            $links .= sprintf('<a class="%s" href="%s">%s</a>', $class, $href, self::e($label));
        }

        return '<div class="range-picker" role="tablist" aria-label="Intervall">' . $links . '</div>';
    }

    private static function sourceSwitcherHtml(string $isin, string $source, string $range): string
    {
        $avanzaClass = $source === NormalizedRow::SOURCE_AVANZA ? 'tab tab--active' : 'tab';
        $nordnetClass = $source === NormalizedRow::SOURCE_NORDNET ? 'tab tab--active' : 'tab';
        $avanzaHref = self::e(self::url($isin, NormalizedRow::SOURCE_AVANZA, $range));
        $nordnetHref = self::e(self::url($isin, NormalizedRow::SOURCE_NORDNET, $range));

        return <<<HTML
        <div class="source-switcher" role="tablist" aria-label="Källa">
          <a class="{$avanzaClass}" href="{$avanzaHref}">Avanza</a>
          <a class="{$nordnetClass}" href="{$nordnetHref}">Nordnet</a>
        </div>
        HTML;
    }

    /**
     * Builds the "/stock/{isin}" URL for a given (source, range) pair,
     * omitting a query param entirely when it is the default — same
     * AD-14-friendly pattern as LeaderboardController::url() (per-request
     * only, nothing stored server-side).
     */
    private static function url(string $isin, string $source, string $range): string
    {
        $params = [];
        if ($source === NormalizedRow::SOURCE_NORDNET) {
            $params['source'] = 'nordnet';
        }
        if ($range !== self::RANGE_DAG) {
            $params['range'] = $range;
        }

        $base = '/stock/' . rawurlencode($isin);

        return $params === [] ? $base : $base . '?' . http_build_query($params);
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
          --secondary-line: #98A2B3;
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
        .stock-header { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .stock-header h1 { font-size: 20px; font-weight: 800; margin: 0; flex: 1 1 auto; min-width: 4em; }
        .star {
          background: none; border: none; cursor: pointer; font-size: 20px; line-height: 1;
          padding: 8px; min-width: 44px; min-height: 44px;
        }
        .star--filled { color: var(--star-filled); }
        .star--empty { color: var(--star-empty); }
        .badges { display: flex; gap: 4px; flex-wrap: wrap; }
        .badge {
          font-size: 9px; font-weight: 800; text-transform: uppercase;
          border-radius: 9999px; padding: 3px 7px; white-space: nowrap;
        }
        .badge--streak { background: var(--brand-tint); color: var(--brand); }
        .badge--spike { background: var(--spike-bg); color: var(--spike-text); }
        .badge--nohist { background: var(--nohist-bg); color: var(--text-muted); }
        .controls { display: flex; flex-direction: column; gap: 8px; margin: 16px 0; }
        .source-switcher, .range-picker {
          display: inline-flex; background: var(--control-bg); border-radius: 9999px; padding: 3px; gap: 2px;
          flex-wrap: wrap;
        }
        .tab {
          padding: 8px 14px; border-radius: 9999px; text-decoration: none;
          font-size: 13px; font-weight: 700; color: var(--text-secondary);
        }
        .source-switcher .tab--active, .tab-bar .tab--active { background: var(--text-primary); color: var(--bg-surface); }
        .range-picker .tab--active {
          background: var(--bg-surface); color: var(--brand);
          box-shadow: 0 1px 3px rgba(16,19,31,0.12);
        }
        .chart-area {
          background: var(--bg-surface); border: 1px solid var(--row-border);
          border-radius: 16px; padding: 16px;
        }
        .trend-overlay { width: 100%; height: 220px; }
        .trend-line { fill: none; stroke-width: 2.5px; }
        .trend-line--positive { stroke: var(--positive); }
        .trend-line--negative { stroke: var(--negative); }
        .trend-line--neutral { stroke: var(--brand); }
        .trend-line--spike { stroke: var(--spike-text); }
        .trend-line--nohistory { stroke: var(--text-muted); stroke-dasharray: 2 3; }
        .trend-line--secondary { stroke: var(--secondary-line); stroke-width: 2px; stroke-dasharray: 4 4; }
        .legend { display: flex; gap: 16px; margin: 10px 0 0; font-size: 12px; color: var(--text-secondary); }
        .legend-item { display: inline-flex; align-items: center; gap: 6px; }
        .legend-swatch { display: inline-block; width: 14px; height: 3px; border-radius: 2px; }
        .legend-swatch--primary { background: var(--brand); }
        .legend-swatch--secondary { background: var(--secondary-line); }
        .legend-item--nodata { color: var(--text-muted); font-style: italic; }
        .empty-state { color: var(--text-secondary); font-size: 13.5px; margin: 0; }
        @media (min-width: 900px) {
          .page { max-width: 960px; box-shadow: 0 12px 40px rgba(16,19,31,0.08); border-radius: 20px; background: var(--bg-app); }
          .controls { flex-direction: row; }
        }
        CSS;
    }
}
