<?php

declare(strict_types=1);

namespace Stockpicker\Web;

use Stockpicker\Adapter\NormalizedRow;
use Stockpicker\Store\DerivedMetricsRepository;
use Stockpicker\Store\ShortPositionRepository;
use Stockpicker\Store\WatchlistRepository;

/**
 * Story 4.2 — renders the authenticated `/` Topplista page: a Source
 * switcher (Avanza/Nordnet), a Ranking-mode toggle ("Flest ägare" / "Stadig
 * tillväxt" / "Plusdagar"), and the top-10 Leaderboard rows for the resolved
 * (source, ranking) pair, each with its Watchlist star, badges, sparkline,
 * owner count and delta chip. The header is the same three rows in every
 * mode (spec-topplista-market-filter): row 1 source switcher + ranking
 * toggle; row 2 the shared period row (Vecka | Månad | 3 mån | År — greyed
 * out and inert in Flest ägare, with "Dölj spikar" to its right in
 * Plusdagar only); row 3 the market filter (Alla | LC | MC | SC | First
 * North), which narrows the top 10 *within* the market. In Plusdagar each
 * row carries a "{plus}/{data} · +{new}" chip. spec-short-interest-badge-ui
 * — every row carries a "Blankad X %" badge when its issuer is shorted at or
 * above ShortPositionRepository::BADGE_THRESHOLD_PCT, and the period row
 * ends with a "Dölj blankade" toggle in every mode (`?shorts=exclude`).
 *
 * Source, ranking, period, market and the spike and short toggles come from the
 * query string and — spec-plusdagar-landing-cookie, a documented AD-14
 * exception — from the `topplista_view` cookie, per resolveView(): with no
 * view param the remembered view is rendered (cookie untouched); with only
 * `source` the remembered view is rendered with that source; any other
 * view param is authoritative (missing params take their defaults, never
 * cookie values). The last two write the normalized view back to the
 * cookie via the front controller; no cookie means the defaults
 * (Plusdagar · Månad · Alla · Avanza). The cookie is a personal UI
 * preference only, never server-side storage: this controller never reads
 * or writes `settings`. resolveView()/normalizeView()/serializeView()/
 * parseViewCookie() are the pure helpers the front controller wires;
 * render() falls back to the default for any unknown value.
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
    /**
     * Flest ägare — no longer the landing view (spec-plusdagar-landing-
     * cookie): RANKING_PLUS is the default, and every header link carries
     * `ranking` explicitly, so `?ranking=count` is how this mode is reached.
     */
    public const RANKING_COUNT = 'count';
    public const RANKING_STEADY = 'steady';
    public const RANKING_PLUS = 'plus';

    /**
     * spec-plusdagar / spec-stadig-tillvaxt-period — the period query
     * values shared by Stadig tillväxt and Plusdagar, mapped to their
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
     * spec-short-interest-badge-ui — `?shorts=exclude` ("Dölj blankade")
     * hides instruments whose issuer is at or above
     * ShortPositionRepository::BADGE_THRESHOLD_PCT in the latest FI
     * snapshot. A view param in every mode (unlike `spikes`).
     */
    public const SHORTS_EXCLUDE = 'exclude';

    /** Lowercase Swedish month abbreviations for the Aktiedetalj "(FI 2 okt)" date. */
    private const SWEDISH_MONTHS = ['jan', 'feb', 'mar', 'apr', 'maj', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];

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

    /**
     * spec-plusdagar-landing-cookie — the cookie remembering the last
     * Topplista view: its value is the URL-style query string of the
     * normalized view (serializeView()), written only by `/`.
     */
    public const VIEW_COOKIE_NAME = 'topplista_view';
    public const VIEW_COOKIE_TTL_SECONDS = 365 * 24 * 60 * 60;

    /** The raw param keys that make up a Topplista view. */
    private const VIEW_PARAMS = ['source', 'ranking', 'period', 'spikes', 'market', 'shorts'];

    private const TOP_N = 10;
    private const SPARKLINE_WINDOW_DAYS = 30;

    public function __construct(
        private readonly DerivedMetricsRepository $metrics,
        private readonly WatchlistRepository $watchlist,
        private readonly ShortPositionRepository $shorts,
    ) {
    }

    /**
     * Renders the full page for the given raw source, ranking, period,
     * spikes and market query values. Unrecognized values fall back to the
     * defaults here (Avanza, Plusdagar, Månad, spikes included, Alla), so
     * a garbage query string never 500s.
     */
    public function render(
        string $source,
        string $rankingMode,
        string $period = '',
        string $spikes = '',
        string $market = '',
        string $shorts = '',
    ): string {
        $source = self::normalizeSource($source);
        $excludeShorted = $shorts === self::SHORTS_EXCLUDE;
        $rankingMode = self::normalizeRanking($rankingMode);
        $period = self::normalizePeriod($period);
        $excludeSpikes = $spikes === self::SPIKES_EXCLUDE;
        // spec-topplista-market-filter — same whitelist as Fullständig
        // lista's `?market=`; anything else (garbage) means Alla (null).
        $market = FullListController::normalizeMarket($market);

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
                : $this->metrics->topByPlusDays($rankingSource, $days, $excludeSpikes, self::TOP_N, $market, $excludeShorted);
        } elseif ($rankingMode === self::RANKING_STEADY) {
            // spec-stadig-tillvaxt-period — sorted by the period's % growth;
            // every period is history-gated (the % needs a row N days back).
            // Spikes are always excluded here, so `?spikes` is ignored.
            $excludeSpikes = false;
            $days = self::PERIODS[$period]['days'];
            $insufficientHistory = !$this->metrics->historySpansDays($rankingSource, $days);
            $rows = $insufficientHistory
                ? []
                : $this->metrics->topByTrendQualityForPeriod($rankingSource, $days, self::TOP_N, $market, $excludeShorted);
        } else {
            $rows = $this->metrics->topByOwnerCount($rankingSource, self::TOP_N, $market, $excludeShorted);
        }

        $starred = array_flip($this->watchlist->starredIsins());

        $nordnetOwners = [];
        if ($source === self::SOURCE_ALL && $rows !== []) {
            $isins = array_map(static fn (array $row): string => (string) $row['isin'], $rows);
            $nordnetOwners = $this->metrics->latestOwnerCountForIsins($isins, NormalizedRow::SOURCE_NORDNET);
        }

        // spec-short-interest-badge-ui — one lookup per render, never per row.
        $shortPositions = $rows === []
            ? []
            : $this->shorts->currentForIsins(array_map(static fn (array $row): string => (string) $row['isin'], $rows));

        if ($rows === []) {
            $bodyHtml = '<p class="empty-state">'
                . self::e(self::emptyStateCopy($rankingMode, $period, $insufficientHistory)) . '</p>';
        } else {
            $bodyHtml = '';
            foreach ($rows as $i => $row) {
                $isin = (string) $row['isin'];
                $rowNordnetOwners = $source === self::SOURCE_ALL ? ($nordnetOwners[$isin] ?? null) : null;
                $bodyHtml .= $this->renderRow(
                    $row,
                    $source,
                    $rankingMode,
                    $i + 1,
                    isset($starred[$isin]),
                    $rowNordnetOwners,
                    $shortPositions[$isin]['pct'] ?? null,
                );
            }
        }

        return self::pageHtml($source, $rankingMode, $period, $excludeSpikes, $market, $bodyHtml, $rows !== [], $excludeShorted);
    }

    /**
     * 'count' | 'steady' | anything else (including garbage) falls back to
     * the 'plus' default (spec-plusdagar-landing-cookie: Plusdagar is the
     * landing view) — a garbage query value never 500s.
     */
    public static function normalizeRanking(string $rankingMode): string
    {
        return match ($rankingMode) {
            self::RANKING_COUNT => self::RANKING_COUNT,
            self::RANKING_STEADY => self::RANKING_STEADY,
            default => self::RANKING_PLUS,
        };
    }

    /**
     * spec-plusdagar-landing-cookie — normalizes a raw param array (`$_GET`
     * or a parsed cookie; untrusted either way) into the canonical view.
     * Non-string values (e.g. `ranking[]=x`) and unknown values fall back
     * to that param's default; `spikes` survives only in Plusdagar, the
     * same rule as the header URLs.
     *
     * @param array<mixed> $raw
     *
     * @return array{source: string, ranking: string, period: string, spikes: bool, market: ?string, shorts: bool}
     */
    public static function normalizeView(array $raw): array
    {
        $str = [];
        foreach (self::VIEW_PARAMS as $key) {
            $value = $raw[$key] ?? '';
            $str[$key] = is_string($value) ? $value : '';
        }

        $ranking = self::normalizeRanking($str['ranking']);

        return [
            'source' => self::normalizeSource($str['source']),
            'ranking' => $ranking,
            'period' => self::normalizePeriod($str['period']),
            'spikes' => $ranking === self::RANKING_PLUS && $str['spikes'] === self::SPIKES_EXCLUDE,
            'market' => FullListController::normalizeMarket($str['market']),
            'shorts' => $str['shorts'] === self::SHORTS_EXCLUDE,
        ];
    }

    /**
     * spec-plusdagar-landing-cookie — the three-way authority rule for `/`,
     * given the raw query params (`$_GET`) and the raw `topplista_view`
     * cookie (both untrusted):
     *  - no view param at all (empty query, or only junk like `fbclid` /
     *    `utm_*`): the remembered view (no cookie: the defaults); the
     *    cookie is not written;
     *  - `source` is the only view param (the Topplista tab on every page,
     *    `/?source=nordnet|alla`): the remembered view with that source
     *    replacing the stored one; the merged view is written;
     *  - any of `ranking`/`period`/`market`/`spikes`/`shorts` present: authoritative
     *    — missing params take their defaults (never cookie values) and
     *    the normalized view is written.
     *
     * @param array<mixed> $get
     *
     * @return array{view: array{source: string, ranking: string, period: string, spikes: bool, market: ?string, shorts: bool}, write: bool}
     */
    public static function resolveView(array $get, mixed $cookie): array
    {
        $present = array_values(array_filter(
            self::VIEW_PARAMS,
            static fn (string $key): bool => array_key_exists($key, $get),
        ));

        if ($present === []) {
            return ['view' => self::parseViewCookie($cookie), 'write' => false];
        }

        if ($present === ['source']) {
            $view = self::parseViewCookie($cookie);
            $view['source'] = self::normalizeSource(is_string($get['source']) ? $get['source'] : '');

            return ['view' => $view, 'write' => true];
        }

        return ['view' => self::normalizeView($get), 'write' => true];
    }

    /**
     * spec-plusdagar-landing-cookie — the cookie value for a normalized
     * view: the same query string url() emits (non-default params omitted,
     * `ranking` always present), without the leading "/?".
     *
     * @param array{source: string, ranking: string, period: string, spikes: bool, market: ?string, shorts: bool} $view
     */
    public static function serializeView(array $view): string
    {
        return http_build_query(self::viewParams(
            $view['source'],
            $view['ranking'],
            $view['period'],
            $view['spikes'],
            $view['market'],
            $view['shorts'],
        ));
    }

    /**
     * spec-plusdagar-landing-cookie — parses a raw `topplista_view` cookie
     * (untrusted: missing, non-string or garbage all mean the defaults for
     * whatever is unusable) back through normalizeView().
     *
     * @return array{source: string, ranking: string, period: string, spikes: bool, market: ?string, shorts: bool}
     */
    public static function parseViewCookie(mixed $cookie): array
    {
        $raw = [];
        if (is_string($cookie) && $cookie !== '') {
            parse_str($cookie, $raw);
        }

        return self::normalizeView($raw);
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
     * fallback rather than a blank page. Plusdagar (3 mån/År) and Stadig
     * tillväxt (every period) distinguish "the source's history is shorter
     * than the period window" ($insufficientHistory) from "nothing
     * qualifies"; both use the same short-history copy.
     */
    public static function emptyStateCopy(
        string $rankingMode,
        string $period = self::PERIOD_DEFAULT,
        bool $insufficientHistory = false,
    ): string {
        if ($insufficientHistory && ($rankingMode === self::RANKING_PLUS || $rankingMode === self::RANKING_STEADY)) {
            return sprintf('För lite historik för %s ännu.', self::PERIODS[self::normalizePeriod($period)]['phrase']);
        }

        if ($rankingMode === self::RANKING_PLUS) {
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
     * spec-short-interest-badge-ui — the badge/filter condition: a current
     * (latest-snapshot) position at or above
     * ShortPositionRepository::BADGE_THRESHOLD_PCT. Null (no LEI, or issuer
     * absent from the latest snapshot) never qualifies.
     */
    public static function isShorted(?float $pct): bool
    {
        return $pct !== null && $pct >= ShortPositionRepository::BADGE_THRESHOLD_PCT;
    }

    /** "15,8 %" — one decimal, decimal comma, a normal space before `%`. */
    private static function shortPctText(float $pct): string
    {
        return number_format($pct, 1, ',', '') . ' %';
    }

    /**
     * spec-short-interest-badge-ui — the "Blankad 15,8 %" badge, last in
     * every row's badge row. Quiet grey (`badge--nohist`): context to
     * weigh, not a verdict. Empty below the threshold or with no position.
     */
    public static function shortBadgeHtml(?float $pct): string
    {
        if ($pct === null || !self::isShorted($pct)) {
            return '';
        }

        return '<span class="badge badge--nohist badge--short" title="Aggregerad blankning enligt Finansinspektionen">'
            . self::e('Blankad ' . self::shortPctText($pct)) . '</span>';
    }

    /**
     * spec-short-interest-badge-ui — Aktiedetalj's variant:
     * "Blankad 15,8 % (FI 2 okt)", where the date is FI's own
     * `position_date` (day without zero-padding + lowercase Swedish month
     * abbreviation). An unparseable date drops the parenthesis rather than
     * printing garbage.
     */
    public static function shortBadgeWithDateHtml(?float $pct, ?string $positionDate): string
    {
        if ($pct === null || !self::isShorted($pct)) {
            return '';
        }

        $text = 'Blankad ' . self::shortPctText($pct);
        $date = $positionDate !== null ? self::swedishShortDate($positionDate) : null;
        if ($date !== null) {
            $text .= ' (FI ' . $date . ')';
        }

        return '<span class="badge badge--nohist badge--short" title="Aggregerad blankning enligt Finansinspektionen">'
            . self::e($text) . '</span>';
    }

    /** "2026-10-02" → "2 okt"; null when not a valid Y-m-d date. */
    public static function swedishShortDate(string $ymd): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
        if ($d === false || $d->format('Y-m-d') !== $ymd) {
            return null;
        }

        return $d->format('j') . ' ' . self::SWEDISH_MONTHS[(int) $d->format('n') - 1];
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
     * @param array<string, mixed> $row one topByOwnerCount()/topByTrendQualityForPeriod()/topByPlusDays() row
     * @param ?int $nordnetOwners only meaningful when $source is
     *   self::SOURCE_ALL (null otherwise) — Nordnet's latest owner count for
     *   this row's isin, or null when Nordnet has no stored data for it
     *   ("ingen data", never a misleading zero).
     */
    private function renderRow(
        array $row,
        string $source,
        string $rankingMode,
        int $rank,
        bool $starred,
        ?int $nordnetOwners,
        ?float $shortPct,
    ): string {
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
        $badgesHtml .= self::shortBadgeHtml($shortPct);
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
        ?string $market,
        string $rowsHtml,
        bool $hasRows,
        bool $excludeShorted = false,
    ): string {
        $tabBar = self::tabBarHtml('topplista', $source);
        $homeHref = self::e(self::homeHref($source));
        $sourceSwitcher = self::sourceSwitcherHtml($source, $rankingMode, $period, $excludeSpikes, $market, $excludeShorted);
        $rankingToggle = self::rankingToggleHtml($source, $rankingMode, $period, $excludeSpikes, $market, $excludeShorted);
        $periodRow = self::periodRowHtml($source, $rankingMode, $period, $excludeSpikes, $market, $excludeShorted);
        $marketRow = self::marketRowHtml($source, $rankingMode, $period, $excludeSpikes, $market, $excludeShorted);
        $fullListHref = self::e(self::fullListUrl($source, $market));
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
        <link rel="icon" type="image/png" href="/assets/favicon.png">
        </head>
        <body>
        <div class="page">
          <div class="nav-strip">
            <a class="wordmark" href="{$homeHref}"><img src="/assets/stockpicker-logo-horizontal.png" alt="Stockpicker" class="wordmark-logo"></a>
            {$tabBar}
          </div>
          <header class="page-header">
            <h1>Topplista</h1>
            <p class="subtitle">Ägarantal, rankade. Spikar uppmärksammas, döljs inte.</p>
            <div class="controls">
              {$sourceSwitcher}
              {$rankingToggle}
            </div>
            {$periodRow}
            {$marketRow}
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
     * Shared by tabBarHtml()'s Topplista/Bevakningslista tabs and the
     * wordmark/home link (pageHtml()) — one source of truth for "what does
     * carrying the source forward mean from here".
     *
     * spec-5-4 (review round, iteration 1): unlike fullListUrl()/infoUrl()
     * (whose targets don't support Alla, so dropping the param is
     * harmless), Topplista itself *does* support Alla — so it must carry
     * SOURCE_ALL forward too, or clicking home while already in Alla mode
     * silently resets the view back to Avanza.
     */
    private static function sourceSuffix(string $source): string
    {
        return match ($source) {
            self::SOURCE_ALL => '?source=alla',
            NormalizedRow::SOURCE_NORDNET => '?source=nordnet',
            default => '',
        };
    }

    /**
     * Where the wordmark/home link (pageHtml()) goes — per the design
     * handbook's 2026-10-06 wordmark-is-a-link decision ("samma mål och
     * URL-regler som flikfältets Topplista-flik").
     */
    private static function homeHref(string $source): string
    {
        return '/' . self::sourceSuffix($source);
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
        $suffix = self::sourceSuffix($source);
        $topplistaHref = self::e('/' . $suffix);
        $watchlistHref = self::e('/watchlist' . $suffix);

        return <<<HTML
        <div class="tab-bar" role="tablist" aria-label="Sidor">
          <a class="{$topplistaClass}" href="{$topplistaHref}">Topplista</a>
          <a class="{$watchlistClass}" href="{$watchlistHref}">Bevakningslista</a>
        </div>
        HTML;
    }

    private static function sourceSwitcherHtml(
        string $source,
        string $rankingMode,
        string $period,
        bool $excludeSpikes,
        ?string $market,
        bool $excludeShorted = false,
    ): string {
        // spec-5-4 — Alla shown first, before Avanza and Nordnet (Intent).
        // spec-topplista-market-filter — period and market (every mode) and
        // the spike toggle (plus only) survive a source switch.
        $allaClass = $source === self::SOURCE_ALL ? 'tab tab--active' : 'tab';
        $avanzaClass = $source === NormalizedRow::SOURCE_AVANZA ? 'tab tab--active' : 'tab';
        $nordnetClass = $source === NormalizedRow::SOURCE_NORDNET ? 'tab tab--active' : 'tab';
        $allaHref = self::e(self::url(self::SOURCE_ALL, $rankingMode, $period, $excludeSpikes, $market, $excludeShorted));
        $avanzaHref = self::e(self::url(NormalizedRow::SOURCE_AVANZA, $rankingMode, $period, $excludeSpikes, $market, $excludeShorted));
        $nordnetHref = self::e(self::url(NormalizedRow::SOURCE_NORDNET, $rankingMode, $period, $excludeSpikes, $market, $excludeShorted));

        return <<<HTML
        <div class="source-switcher" role="tablist" aria-label="Källa">
          <a class="{$allaClass}" href="{$allaHref}">Alla</a>
          <a class="{$avanzaClass}" href="{$avanzaHref}">Avanza</a>
          <a class="{$nordnetClass}" href="{$nordnetHref}">Nordnet</a>
        </div>
        HTML;
    }

    private static function rankingToggleHtml(
        string $source,
        string $rankingMode,
        string $period,
        bool $excludeSpikes,
        ?string $market,
        bool $excludeShorted = false,
    ): string {
        $countClass = $rankingMode === self::RANKING_COUNT ? 'tab tab--active' : 'tab';
        $steadyClass = $rankingMode === self::RANKING_STEADY ? 'tab tab--active' : 'tab';
        $plusClass = $rankingMode === self::RANKING_PLUS ? 'tab tab--active' : 'tab';
        // spec-topplista-market-filter — all three modes share the period
        // and market, so every mode switch keeps both (Flest ägare carries
        // the period too, so it survives a round trip through it); only
        // Plusdagar carries the spike toggle. Plain labels: the period lives
        // in the period row, never in a tab.
        $countHref = self::e(self::url($source, self::RANKING_COUNT, $period, false, $market, $excludeShorted));
        $steadyHref = self::e(self::url($source, self::RANKING_STEADY, $period, false, $market, $excludeShorted));
        $plusHref = self::e(self::url($source, self::RANKING_PLUS, $period, $excludeSpikes, $market, $excludeShorted));

        return <<<HTML
        <div class="ranking-toggle" role="tablist" aria-label="Rankningsläge">
          <a class="{$countClass}" href="{$countHref}">Flest ägare</a>
          <a class="{$steadyClass}" href="{$steadyHref}">Stadig tillväxt</a>
          <a class="{$plusClass}" href="{$plusHref}">Plusdagar</a>
        </div>
        HTML;
    }

    /**
     * spec-stadig-tillvaxt-period / spec-topplista-market-filter — the
     * period row (header row 2), rendered in every mode: a compact
     * segmented pill "Vecka | Månad | 3 mån | År" (same `.range-picker`
     * markup as Aktiedetalj's Range picker), plus — in Plusdagar only — the
     * checkbox-styled "☐/☑ Dölj spikar" link to its right (Stadig tillväxt
     * always excludes spikes), and — in every mode — "☐/☑ Dölj blankade"
     * after it (spec-short-interest-badge-ui; a live link even in Flest ägare's
     * muted row). In Flest ägare the period doesn't apply: the
     * row is greyed out (`.period-row--muted`), its segments are inert
     * `<span>`s rather than links, the remembered period stays marked, and
     * a muted note says "Gäller inte Flest ägare". Plain links, no JS
     * (AD-12); source, market and the other controls survive each click.
     */
    private static function periodRowHtml(
        string $source,
        string $rankingMode,
        string $period,
        bool $excludeSpikes,
        ?string $market,
        bool $excludeShorted = false,
    ): string {
        $inert = $rankingMode === self::RANKING_COUNT;

        $segments = '';
        foreach (self::PERIODS as $key => $def) {
            $label = self::e($def['label']);
            $active = $key === $period;
            if ($inert) {
                $segments .= $active
                    ? "<span class=\"tab tab--active\" aria-current=\"true\">{$label}</span>"
                    : "<span class=\"tab\">{$label}</span>";
                continue;
            }
            $href = self::e(self::url($source, $rankingMode, $key, $excludeSpikes, $market, $excludeShorted));
            $segments .= $active
                ? "<a class=\"tab tab--active\" href=\"{$href}\" aria-current=\"true\">{$label}</a>"
                : "<a class=\"tab\" href=\"{$href}\">{$label}</a>";
        }

        $extraHtml = '';
        if ($inert) {
            $extraHtml = '<span class="period-note">Gäller inte Flest ägare</span>';
        } elseif ($rankingMode === self::RANKING_PLUS) {
            $spikeHref = self::e(self::url($source, self::RANKING_PLUS, $period, !$excludeSpikes, $market, $excludeShorted));
            $spikeClass = $excludeSpikes ? 'spike-toggle spike-toggle--active' : 'spike-toggle';
            $spikeGlyph = $excludeSpikes ? '☑' : '☐';
            $extraHtml = "<a class=\"{$spikeClass}\" href=\"{$spikeHref}\"><span aria-hidden=\"true\">{$spikeGlyph}</span> Dölj spikar</a>";
        }

        // spec-short-interest-badge-ui — "Dölj blankade", a live link in
        // every mode (including Flest ägare's muted row), right of the
        // note / "Dölj spikar" / pill.
        $shortHref = self::e(self::url($source, $rankingMode, $period, $excludeSpikes, $market, !$excludeShorted));
        $shortClass = $excludeShorted ? 'spike-toggle short-toggle spike-toggle--active' : 'spike-toggle short-toggle';
        $shortGlyph = $excludeShorted ? '☑' : '☐';
        $extraHtml .= "<a class=\"{$shortClass}\" href=\"{$shortHref}\"><span aria-hidden=\"true\">{$shortGlyph}</span> Dölj blankade</a>";
        // One wrapper for the pill's right-hand neighbours, so they stack
        // under each other on a narrow viewport instead of squeezing.
        $extraHtml = '<span class="period-extras">' . $extraHtml . '</span>';

        $rowClass = $inert ? 'period-row period-row--muted' : 'period-row';
        $pickerAttrs = $inert ? ' aria-disabled="true"' : '';

        return <<<HTML
        <div class="{$rowClass}">
          <div class="range-picker" role="tablist" aria-label="Period"{$pickerAttrs}>{$segments}</div>
          {$extraHtml}
        </div>
        HTML;
    }

    /**
     * spec-topplista-market-filter — the market filter (header row 3, every
     * mode): "Alla | LC | MC | SC | First North" as the same `.range-picker`
     * segmented control as the period row. Same values as Fullständig
     * lista's `?market=` (FullListController::MARKETS); Alla (no narrowing)
     * is the default and omitted from URLs. Source, ranking, period and the
     * spike toggle survive each click.
     */
    private static function marketRowHtml(
        string $source,
        string $rankingMode,
        string $period,
        bool $excludeSpikes,
        ?string $market,
        bool $excludeShorted = false,
    ): string {
        $options = array_merge([null], FullListController::MARKETS);

        $segments = '';
        foreach ($options as $option) {
            $href = self::e(self::url($source, $rankingMode, $period, $excludeSpikes, $option, $excludeShorted));
            $label = self::e($option ?? 'Alla');
            $segments .= $option === $market
                ? "<a class=\"tab tab--active\" href=\"{$href}\" aria-current=\"true\">{$label}</a>"
                : "<a class=\"tab\" href=\"{$href}\">{$label}</a>";
        }

        return <<<HTML
        <div class="market-row">
          <div class="range-picker" role="tablist" aria-label="Marknad">{$segments}</div>
        </div>
        HTML;
    }

    /**
     * Builds the "/" URL for a given (source, ranking[, period, spikes,
     * market]) combination. `ranking` is always emitted (spec-plusdagar-
     * landing-cookie: a bare "/" means "the remembered view", so no header
     * link may be bare); every other param is omitted when it is the
     * default. `period` and `market` are carried in every mode
     * (spec-topplista-market-filter — Flest ägare ignores the period but
     * keeps it for the next mode switch); `spikes` only in Plusdagar; `shorts`
     * in every mode, only when on (spec-short-interest-badge-ui).
     */
    private static function url(
        string $source,
        string $rankingMode,
        string $period = self::PERIOD_DEFAULT,
        bool $excludeSpikes = false,
        ?string $market = null,
        bool $excludeShorted = false,
    ): string {
        return '/?' . http_build_query(self::viewParams($source, $rankingMode, $period, $excludeSpikes, $market, $excludeShorted));
    }

    /**
     * The query params shared by url() and serializeView().
     *
     * @return array<string, string>
     */
    private static function viewParams(
        string $source,
        string $rankingMode,
        string $period,
        bool $excludeSpikes,
        ?string $market,
        bool $excludeShorted = false,
    ): array {
        $params = [];
        if ($source === self::SOURCE_ALL) {
            $params['source'] = 'alla';
        } elseif ($source === NormalizedRow::SOURCE_NORDNET) {
            $params['source'] = 'nordnet';
        }
        $params['ranking'] = self::normalizeRanking($rankingMode);
        if ($period !== self::PERIOD_DEFAULT) {
            $params['period'] = $period;
        }
        if ($rankingMode === self::RANKING_PLUS && $excludeSpikes) {
            $params['spikes'] = self::SPIKES_EXCLUDE;
        }
        if ($excludeShorted) {
            $params['shorts'] = self::SHORTS_EXCLUDE;
        }
        if ($market !== null) {
            $params['market'] = $market;
        }

        return $params;
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
     * regression of this docblock's guarantee. spec-topplista-market-filter
     * — the chosen market is carried too (`?market=`, same values as
     * Fullständig lista's own market filter), so the list opens on the same
     * market.
     */
    private static function fullListUrl(string $source, ?string $market = null): string
    {
        $params = [];
        if ($source === NormalizedRow::SOURCE_NORDNET) {
            $params['source'] = 'nordnet';
        }
        if ($market !== null) {
            $params['market'] = $market;
        }

        return $params === [] ? '/list' : '/list?' . http_build_query($params);
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
