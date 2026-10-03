<?php

declare(strict_types=1);

namespace Stockpicker\Web;

use Stockpicker\Adapter\NormalizedRow;
use Stockpicker\Store\DerivedMetricsRepository;
use Stockpicker\Store\Instrument;
use Stockpicker\Store\ShortPositionRepository;
use Stockpicker\Store\WatchlistRepository;

/**
 * Story 4.3 — renders the authenticated `/stock/{isin}` Aktiedetalj page: a
 * header (instrument name, Watchlist star, Streak/Spike badges), a dual-line
 * Trend overlay (primary = the Source-switcher selection, secondary = the
 * other source, always fixed/dashed), a five-segment Range picker
 * (Dag/Vecka/Månad/3 mån/År — calendar windows since
 * spec-calendar-period-metrics), and a Source switcher.
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
    public const RANGE_MANAD = 'manad';
    public const RANGE_3MAN = '3man';
    public const RANGE_AR = 'ar';

    /** Pre-spec-calendar-period-metrics URL values, still accepted. */
    private const LEGACY_RANGE_30D = '30d';
    private const LEGACY_RANGE_90D = '90d';

    public function __construct(
        private readonly DerivedMetricsRepository $metrics,
        private readonly WatchlistRepository $watchlist,
        private readonly ShortPositionRepository $shorts,
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
        // spec-short-interest-badge-ui — per issuer, so the same in every
        // Source mode; dated with FI's own position_date.
        $short = $this->shorts->currentForIsins([$instrument->isin])[$instrument->isin] ?? null;
        $badgesHtml .= LeaderboardController::shortBadgeWithDateHtml($short['pct'] ?? null, $short['position_date'] ?? null);

        if (self::isInsufficientHistory($primarySeries, $range)) {
            $chartHtml = '<p class="empty-state">' . self::e(self::insufficientHistoryMessage($primarySeries, $range)) . '</p>';
        } else {
            $primarySlice = self::sliceForRange($primarySeries, $range);
            $secondarySlice = self::sliceForRange($secondarySeries, $range);
            $chartHtml = self::trendOverlayHtml($primarySlice, $secondarySlice, $source, $range)
                . self::legendHtml($source, $secondarySource, count($primarySlice) >= 2, count($secondarySlice) >= 2);
        }

        return self::pageHtml($instrument, $source, $range, $badgesHtml, $starred, $chartHtml);
    }

    // -- Range gate/slice rules (pure, unit-testable) -------------------------

    public static function normalizeRange(string $range): string
    {
        return match ($range) {
            self::RANGE_VECKA, self::RANGE_MANAD, self::RANGE_3MAN, self::RANGE_AR => $range,
            self::LEGACY_RANGE_30D => self::RANGE_MANAD,
            self::LEGACY_RANGE_90D => self::RANGE_3MAN,
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
     * Calendar length of the range window in days (spec-calendar-period-
     * metrics): the window holds every row with `as_of_date >= last - N
     * days`. `null` (Dag) means "the last 2 rows" instead — the previous
     * trading day may be several calendar days back (weekend/holiday).
     */
    public static function windowDays(string $range): ?int
    {
        return match ($range) {
            self::RANGE_VECKA => 7,
            self::RANGE_MANAD => 30,
            self::RANGE_3MAN => 90,
            self::RANGE_AR => 365,
            default => null, // Dag
        };
    }

    /**
     * Calendar span (days) the primary history must cover before the range
     * renders a chart at all. År shares 3 mån's gate (Design Notes,
     * spec-4-3) — a sub-90-day "year" view would look exactly as thin as a
     * sub-90-day "3 mån" view. `null` (Dag) means the row-count gate: at
     * least 2 rows.
     */
    public static function gateDays(string $range): ?int
    {
        return match ($range) {
            self::RANGE_VECKA => 7,
            self::RANGE_MANAD => 30,
            self::RANGE_3MAN, self::RANGE_AR => 90,
            default => null, // Dag
        };
    }

    /**
     * @param list<array<string, mixed>> $series
     *
     * @return list<array<string, mixed>>
     */
    public static function sliceForRange(array $series, string $range): array
    {
        $days = self::windowDays($range);
        if ($days === null) {
            return array_slice($series, -2);
        }
        if ($series === []) {
            return [];
        }

        $last = end($series);
        $cutoff = self::shiftDate((string) $last['as_of_date'], -$days);

        // ISO Y-m-d strings compare correctly as strings.
        return array_values(array_filter(
            $series,
            static fn (array $row): bool => (string) $row['as_of_date'] >= $cutoff,
        ));
    }

    /**
     * The insufficiency gate is evaluated only against the *primary*
     * source's history (NFR6, Design Notes) — the secondary line renders
     * with whatever it has, un-gated. Insufficient when the history's first
     * date is later than `last - N days` (it does not yet span the window).
     *
     * @param list<array<string, mixed>> $primarySeries
     */
    public static function isInsufficientHistory(array $primarySeries, string $range): bool
    {
        return self::daysUntilSufficient($primarySeries, $range) > 0;
    }

    /**
     * Calendar days until the primary history spans the range's gate
     * (Dag: trading rows until there are 2). An empty series needs the full
     * gate span.
     *
     * @param list<array<string, mixed>> $primarySeries
     */
    public static function daysUntilSufficient(array $primarySeries, string $range): int
    {
        $gate = self::gateDays($range);
        if ($gate === null) {
            return max(0, 2 - count($primarySeries));
        }
        if ($primarySeries === []) {
            return $gate;
        }

        $first = new \DateTimeImmutable((string) $primarySeries[0]['as_of_date']);
        $last = new \DateTimeImmutable((string) end($primarySeries)['as_of_date']);
        $spanned = (int) $first->diff($last)->days;

        return max(0, $gate - $spanned);
    }

    /**
     * @param list<array<string, mixed>> $primarySeries
     */
    public static function insufficientHistoryMessage(array $primarySeries, string $range): string
    {
        $days = self::daysUntilSufficient($primarySeries, $range);

        return sprintf(
            'Inte tillräckligt med historik för det här intervallet ännu — kolla in igen om %d %s',
            $days,
            $days === 1 ? 'dag' : 'dagar',
        );
    }

    private static function shiftDate(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date))->modify(sprintf('%+d days', $days))->format('Y-m-d');
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
    private static function trendOverlayHtml(array $primarySlice, array $secondarySlice, string $source, string $range): string
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

        // Design handbook §8 "Standardisera diagrammens textalternativ": a
        // real summary (period, start, end, change) instead of a bare
        // "Trend" label — this is the chart's only accessible content, the
        // polylines carry no text of their own.
        $altText = self::e(self::chartAltText($primarySlice, $source, $range));

        // Secondary drawn first so the primary line renders on top.
        $svg = sprintf(
            '<svg class="trend-overlay" viewBox="0 0 %d %d" preserveAspectRatio="none" role="img" aria-label="%s">%s%s</svg>',
            $width,
            $height,
            $altText,
            $secondaryPolyline,
            $primaryPolyline,
        );

        return '<div class="trend-plot">' . self::yAxisHtml($primarySlice) . $svg . '</div>';
    }

    /**
     * Design handbook §8's "textalternativ med period, start, slut och
     * förändring" — period (rangeLabel), the primary slice's first/last
     * (as_of_date, number_of_owners), and the change between them, as one
     * sentence. Pure/static so it is unit-testable without a database.
     *
     * @param list<array<string, mixed>> $primarySlice
     */
    public static function chartAltText(array $primarySlice, string $source, string $range): string
    {
        $sourceLabel = self::sourceLabel($source);
        $rangeLabel = self::rangeLabel($range);

        if (count($primarySlice) < 2) {
            return sprintf('Ägarantal, %s, %s: otillräcklig historik för ett diagram.', $sourceLabel, $rangeLabel);
        }

        $first = reset($primarySlice);
        $last = end($primarySlice);
        $firstOwners = (int) $first['number_of_owners'];
        $lastOwners = (int) $last['number_of_owners'];
        $change = $lastOwners - $firstOwners;
        $pct = $firstOwners !== 0 ? ($change / $firstOwners) * 100 : 0.0;
        $sign = $change > 0 ? '+' : ($change < 0 ? '−' : '');

        return sprintf(
            'Ägarantal, %s, %s: %s (%s) till %s (%s), förändring %s%s (%s%s %%).',
            $sourceLabel,
            $rangeLabel,
            number_format($firstOwners, 0, ',', ' '),
            (string) $first['as_of_date'],
            number_format($lastOwners, 0, ',', ' '),
            (string) $last['as_of_date'],
            $sign,
            number_format(abs($change), 0, ',', ' '),
            $sign,
            number_format(abs($pct), 1, ',', ''),
        );
    }

    /**
     * Shared with rangePickerHtml() (dedup, design handbook §12) — the
     * Dag/Vecka/Månad/3 mån/År display label for a range value.
     */
    public static function rangeLabel(string $range): string
    {
        return match ($range) {
            self::RANGE_VECKA => 'Vecka',
            self::RANGE_MANAD => 'Månad',
            self::RANGE_3MAN => '3 mån',
            self::RANGE_AR => 'År',
            default => 'Dag',
        };
    }

    /**
     * The primary axis's tick column — min/mid/max of the *primary* slice's
     * actual values (never the secondary's: NFR6, each source keeps its own
     * scale, and this chart only ever numbers the source the reader picked).
     * Plain HTML/CSS, not SVG text: the <svg> above uses
     * `preserveAspectRatio="none"`, which stretches x and y independently to
     * fill whatever width the container gets — fine for a line's shape, but
     * it would non-uniformly squash/stretch SVG <text> glyphs on every
     * viewport but the one matching the viewBox's exact aspect ratio.
     * Positioning by `top: n%` inside a column the same height as the SVG
     * keeps a label exactly level with its value regardless of that stretch.
     *
     * @param list<array<string, mixed>> $primarySlice
     */
    private static function yAxisHtml(array $primarySlice): string
    {
        $labeled = array_map(
            static fn (array $tick): array => ['percent' => $tick['percent'], 'label' => self::tickLabel($tick['value'])],
            self::yAxisTicks($primarySlice),
        );
        $labeled = self::dedupeAdjacentLabels($labeled);

        $items = array_map(
            static fn (array $tick): string => sprintf(
                '<span class="y-axis-tick" style="top:%s%%">%s</span>',
                sprintf('%.2f', $tick['percent']),
                self::e($tick['label']),
            ),
            $labeled,
        );

        return '<div class="y-axis">' . implode('', $items) . '</div>';
    }

    /**
     * Rounding two genuinely different values to the same display label
     * (e.g. a "Dag" slice's two points, ~210963 and ~211050, both rounding
     * to "210k"/"211k" is fine, but a min/mid pair like 210963/211006 can
     * both land on "211k") would stack an identical-looking number twice at
     * different heights — confusing, not clean. Drops the middle tick when
     * it collides with either extreme; the two extremes always stay so the
     * axis never disappears entirely.
     *
     * @param list<array{percent: float, label: string}> $ticks
     *
     * @return list<array{percent: float, label: string}>
     */
    private static function dedupeAdjacentLabels(array $ticks): array
    {
        if (count($ticks) !== 3) {
            return $ticks;
        }

        if ($ticks[1]['label'] === $ticks[0]['label'] || $ticks[1]['label'] === $ticks[2]['label']) {
            return [$ticks[0], $ticks[2]];
        }

        return $ticks;
    }

    /**
     * Story backlog 2026-09-15 — up to 3 tick anchors (max, mid, min) at the
     * *actual* values, so each sits at the exact height scaledPoints() would
     * plot that value at (`percent` is measured from the top, matching
     * scaledPoints()'s y = height - ...). Only the displayed label is
     * rounded (tickLabel()); the position never is. A flat slice (every
     * value equal) collapses to one centered tick — three identical numbers
     * stacked on top of each other would say nothing a single one doesn't.
     *
     * @param list<array<string, mixed>> $primarySlice
     *
     * @return list<array{value: int, percent: float}>
     */
    public static function yAxisTicks(array $primarySlice): array
    {
        if ($primarySlice === []) {
            return [];
        }

        $values = array_map(static fn (array $row): int => (int) $row['number_of_owners'], $primarySlice);
        $min = min($values);
        $max = max($values);

        if ($min === $max) {
            return [['value' => $min, 'percent' => 50.0]];
        }

        $mid = intdiv($min + $max, 2);
        $range = $max - $min;

        return [
            ['value' => $max, 'percent' => 0.0],
            ['value' => $mid, 'percent' => (($max - $mid) / $range) * 100],
            ['value' => $min, 'percent' => 100.0],
        ];
    }

    /**
     * Rounds a raw owner count to a magnitude-appropriate "nice" number for
     * axis chrome and formats it compactly (e.g. 532481 -> "532k",
     * 8734 -> "8,73k", 450 -> "450") — the exact figure already lives
     * elsewhere on the page (header, legend); the axis only needs to read at
     * a glance. Rounds to roughly 3 significant figures: keeps the display
     * stable (the tick for a ~530k series won't jitter by single digits
     * between renders) without inventing a full nice-number/gridline
     * algorithm this narrow, gridline-less axis doesn't need.
     */
    public static function tickLabel(int $value): string
    {
        $digits = strlen((string) $value);
        $step = (int) (10 ** max(0, $digits - 3));
        $rounded = (int) round($value / $step) * $step;

        if ($rounded < 1000) {
            return (string) $rounded;
        }

        $decimals = match (true) {
            $digits >= 6 => 0,
            $digits === 5 => 1,
            default => 2,
        };

        $formatted = number_format($rounded / 1000, $decimals, '.', '');
        if ($decimals > 0) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return str_replace('.', ',', $formatted) . 'k';
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
        $avanzaLink = self::avanzaLinkHtml($instrument->avanzaOrderbookId);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="sv">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$eName} — stockpicker</title>
        <link rel="stylesheet" href="/assets/app.css">
        </head>
        <body>
        <div class="page">
          <div class="nav-strip">
            <div class="wordmark">Stockpicker</div>
            {$tabBar}
          </div>
          <header class="stock-header">
            <button type="button" class="{$starClass}" data-isin="{$eIsin}" aria-pressed="{$ariaPressed}" aria-label="{$starLabel}">{$starGlyph}</button>
            <h1>{$eName}</h1>
            <span class="badges">{$badgesHtml}</span>
            {$avanzaLink}
          </header>
          <div class="controls">
            {$sourceSwitcher}
            {$rangePicker}
          </div>
          <main class="chart-area">
            {$chartHtml}
          </main>
        </div>
        <div class="wl-toast" id="wl-toast" role="status" aria-live="polite"></div>
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

    /**
     * spec-5-2 — a link to this instrument's own page on Avanza
     * (`https://www.avanza.se/aktier/om-aktien.html/{orderbookId}`, verified
     * against live pages during planning; the bare orderbookId form resolves
     * without a name slug). `$orderbookId` is `Instrument::$avanzaOrderbookId`
     * verbatim — `null` when not yet resolved (or Nordnet-only), in which case
     * this silently omits the link rather than rendering a broken one.
     * `target="_blank" rel="noopener noreferrer"` keeps the authenticated
     * session open in this tab and blocks reverse-tabnabbing. Extracted as
     * its own pure static function (same precedent as
     * `LeaderboardController::rowBodyHtml()`) so it's unit-testable without a
     * database.
     */
    public static function avanzaLinkHtml(?string $orderbookId): string
    {
        if ($orderbookId === null || $orderbookId === '') {
            return '';
        }

        $href = self::e('https://www.avanza.se/aktier/om-aktien.html/' . rawurlencode($orderbookId));

        return <<<HTML
        <a class="avanza-link" href="{$href}" target="_blank" rel="noopener noreferrer" aria-label="Visa på Avanza, öppnas i en ny flik">Visa på Avanza ↗</a>
        HTML;
    }

    private static function rangePickerHtml(string $isin, string $source, string $range): string
    {
        $values = [self::RANGE_DAG, self::RANGE_VECKA, self::RANGE_MANAD, self::RANGE_3MAN, self::RANGE_AR];

        $links = '';
        foreach ($values as $value) {
            $class = $value === $range ? 'tab tab--active' : 'tab';
            $href = self::e(self::url($isin, $source, $value));
            $links .= sprintf('<a class="%s" href="%s">%s</a>', $class, $href, self::e(self::rangeLabel($value)));
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
}
