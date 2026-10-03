<?php

declare(strict_types=1);

namespace Stockpicker\Store;

use PDO;

/**
 * Read-only access to the `owner_count_metrics` view (Story 3.1) — the sole
 * reader of it from PHP, same "no SQL outside src/Store/" rule as every other
 * repository. The view itself computes everything; this class only shapes
 * the read.
 */
final class DerivedMetricsRepository
{
    /**
     * Story 4.2/spec-4-2 — the single source of truth for the spike
     * threshold: `spike_score >= 2` marks a row spiking, upward only. Shared
     * by topByTrendQualityForPeriod()'s exclusion filter (bound as a query parameter,
     * never a raw literal) and LeaderboardController::isSpiking() (the Spike
     * badge condition), so the two can never drift apart.
     */
    public const SPIKE_THRESHOLD = 2.0;

    /**
     * Story 4.4/spec-4-4 — the two `/list` sort values. `SORT_COUNT` (the
     * default) orders by latest `number_of_owners` desc, same as
     * topByOwnerCount(); `SORT_PCT` orders by `pct_1d` desc. Both always add
     * `, isin ASC` as the tie-breaker (same convention as Story 4.2's
     * ranking queries).
     */
    public const SORT_COUNT = 'count';
    public const SORT_PCT = 'pct';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * One (isin, source) series, ordered by as_of_date. Empty array when the
     * series does not exist (unknown isin, or no rows yet for that source).
     *
     * @return list<array<string, mixed>>
     */
    public function forIsinAndSource(string $isin, string $source): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM owner_count_metrics WHERE isin = :isin AND source = :source ORDER BY as_of_date'
        );
        $stmt->execute(['isin' => $isin, 'source' => $source]);

        return array_values($stmt->fetchAll());
    }

    /**
     * Every source's series for one isin, keyed by source — mirrors
     * InstrumentRepository's keyed-array return style. A source with no
     * stored rows is simply absent from the result.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function forIsin(string $isin): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM owner_count_metrics WHERE isin = :isin ORDER BY source, as_of_date'
        );
        $stmt->execute(['isin' => $isin]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['source']][] = $row;
        }

        return $out;
    }

    /**
     * Story 4.2 — Topplista's "Flest ägare" ranking: the top `$limit` active
     * instruments for `$source` by their *latest* `number_of_owners`, highest
     * first. "Latest" is the most recent `as_of_date` row per isin (a
     * ROW_NUMBER() over as_of_date desc, filtered to rn = 1) — never a
     * historical peak. "Active" means `instrument.last_seen IS NULL`. Joined
     * against `instrument` for `name`/`list`. Never merges sources (NFR6).
     * A non-null `$market` narrows to `instrument.list = $market` *before*
     * the limit (spec-topplista-market-filter), as do the other two rankings.
     *
     * @return list<array<string, mixed>>
     */
    public function topByOwnerCount(string $source, int $limit, ?string $market = null, bool $excludeShorted = false): array
    {
        $marketCondition = self::marketCondition($market);
        $shortCondition = self::shortedCondition($excludeShorted);

        $stmt = $this->pdo->prepare(
            <<<SQL
            WITH latest AS (
                SELECT
                    m.*,
                    ROW_NUMBER() OVER (PARTITION BY m.isin ORDER BY m.as_of_date DESC) AS rn
                FROM owner_count_metrics m
                WHERE m.source = :source
            )
            SELECT
                latest.isin, latest.source, latest.as_of_date, latest.number_of_owners,
                latest.delta_1d, latest.pct_1d, latest.pct_7d, latest.pct_30d, latest.pct_90d, latest.pct_365d,
                latest.sma_7, latest.sma_30, latest.sma_90,
                latest.up_streak, latest.spike_score,
                i.name, i.list
            FROM latest
            JOIN instrument i ON i.isin = latest.isin
            WHERE latest.rn = 1
              AND i.last_seen IS NULL
              {$marketCondition}
              {$shortCondition}
            ORDER BY latest.number_of_owners DESC, latest.isin ASC
            LIMIT :lim
            SQL
        );
        $stmt->bindValue(':source', $source, PDO::PARAM_STR);
        self::bindMarket($stmt, $market);
        self::bindShorted($stmt, $excludeShorted);
        $stmt->bindValue(':lim', max(0, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return array_values($stmt->fetchAll());
    }

    /**
     * spec-stadig-tillvaxt-period — Topplista's "Stadig tillväxt" ranking
     * for a chosen period (Vecka 7 / Månad 30 / 3 mån 90 / År 365 days).
     * To qualify, an isin's latest row must be active
     * (`instrument.last_seen IS NULL`), have a real ongoing streak
     * (`up_streak >= 1`, the Streak badge condition) and not be spiking
     * (any row at or above self::SPIKE_THRESHOLD is excluded outright,
     * decided 2026-09-13, spec-4-2 — however long its streak). Flat/
     * no-streak rows (0 or NULL) never qualify, which keeps the
     * zero-qualifiers empty state reachable. Ordered by the period's percentage
     * growth (`pct_7d`/`pct_30d`/`pct_90d`/`pct_365d`) DESC; rows lacking
     * that percentage (NULL, e.g. a new listing) come after every row with
     * one, then `up_streak` DESC, then `isin` ASC. The `$days` → column
     * mapping is a fixed whitelist (self::periodPctColumn()), never
     * interpolated from input.
     *
     * @return list<array<string, mixed>>
     *
     * @throws \InvalidArgumentException when `$days` is not 7/30/90/365
     */
    public function topByTrendQualityForPeriod(string $source, int $days, int $limit, ?string $market = null, bool $excludeShorted = false): array
    {
        $col = self::periodPctColumn($days);
        $marketCondition = self::marketCondition($market);
        $shortCondition = self::shortedCondition($excludeShorted);

        $stmt = $this->pdo->prepare(
            <<<SQL
            WITH latest AS (
                SELECT
                    m.*,
                    ROW_NUMBER() OVER (PARTITION BY m.isin ORDER BY m.as_of_date DESC) AS rn
                FROM owner_count_metrics m
                WHERE m.source = :source
            )
            SELECT
                latest.isin, latest.source, latest.as_of_date, latest.number_of_owners,
                latest.delta_1d, latest.pct_1d, latest.pct_7d, latest.pct_30d, latest.pct_90d, latest.pct_365d,
                latest.sma_7, latest.sma_30, latest.sma_90,
                latest.up_streak, latest.spike_score,
                i.name, i.list
            FROM latest
            JOIN instrument i ON i.isin = latest.isin
            WHERE latest.rn = 1
              AND i.last_seen IS NULL
              AND latest.up_streak >= 1
              AND (latest.spike_score IS NULL OR latest.spike_score < :spike_threshold)
              {$marketCondition}
              {$shortCondition}
            ORDER BY latest.{$col} IS NULL, latest.{$col} DESC, latest.up_streak DESC, latest.isin ASC
            LIMIT :lim
            SQL
        );
        $stmt->bindValue(':source', $source, PDO::PARAM_STR);
        $stmt->bindValue(':spike_threshold', self::SPIKE_THRESHOLD);
        self::bindMarket($stmt, $market);
        self::bindShorted($stmt, $excludeShorted);
        $stmt->bindValue(':lim', max(0, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return array_values($stmt->fetchAll());
    }

    /**
     * spec-topplista-market-filter — the optional `i.list = :market`
     * narrowing shared by the three Topplista rankings (same condition as
     * searchAndFilter()'s market filter). Null/'' means no narrowing; the
     * value itself is always bound, never interpolated.
     */
    private static function marketCondition(?string $market): string
    {
        return $market !== null && $market !== '' ? 'AND i.list = :market' : '';
    }

    private static function bindMarket(\PDOStatement $stmt, ?string $market): void
    {
        if ($market !== null && $market !== '') {
            $stmt->bindValue(':market', $market, PDO::PARAM_STR);
        }
    }

    /**
     * spec-short-interest-badge-ui — "Dölj blankade": drops instruments whose
     * issuer (matched by LEI only) is in the *latest* FI snapshot at or above
     * ShortPositionRepository::BADGE_THRESHOLD_PCT. Applied before LIMIT, so
     * the top N refills. A NULL `i.lei`, an issuer absent from the latest
     * snapshot, or an empty `short_position` table never matches — the row
     * is kept. Same "current" semantics as ShortPositionRepository::currentForIsins().
     */
    private static function shortedCondition(bool $excludeShorted): string
    {
        return $excludeShorted
            ? 'AND NOT EXISTS (
                  SELECT 1 FROM short_position sp
                  WHERE sp.lei = i.lei
                    AND sp.snapshot_date = (SELECT MAX(snapshot_date) FROM short_position)
                    AND sp.position_pct >= :short_threshold
              )'
            : '';
    }

    private static function bindShorted(\PDOStatement $stmt, bool $excludeShorted): void
    {
        if ($excludeShorted) {
            $stmt->bindValue(':short_threshold', (string) ShortPositionRepository::BADGE_THRESHOLD_PCT, PDO::PARAM_STR);
        }
    }

    /**
     * Whitelisted period-length → view column mapping for
     * topByTrendQualityForPeriod().
     */
    private static function periodPctColumn(int $days): string
    {
        return match ($days) {
            7 => 'pct_7d',
            30 => 'pct_30d',
            90 => 'pct_90d',
            365 => 'pct_365d',
            default => throw new \InvalidArgumentException(sprintf('Unsupported period length: %d days', $days)),
        };
    }

    /**
     * spec-plusdagar — Topplista's "Plusdagar" ranking over a calendar
     * window of `$days` days (Vecka 7 / Månad 30 / 3 mån 90 / År 365).
     *
     * D is `$source`'s latest `as_of_date` (over all its rows); the window
     * is `(D − $days, D]`. Read-time only, straight off `owner_count_daily`
     * (no view/table/migration):
     *  - a window row is a *data day* when its previous stored row lies at
     *    most 5 calendar days back (same tolerance as the view's delta_1d);
     *    a data day whose delta is >= 0 is a *plus day* (flat days count).
     *  - `new_owners` = owners on the instrument's latest row minus owners
     *    on its latest row on or before D − `$days`, or — when no such row
     *    exists (a new listing) — minus the first row inside the window.
     *  - only instruments with at least one row in the window and
     *    `new_owners > 0` qualify; only active instruments
     *    (`last_seen IS NULL`).
     *  - ordered by `plus_days` DESC (a raw count, never a share), then
     *    `new_owners` DESC, then `isin` ASC.
     *  - `$excludeSpikes` drops instruments whose *latest*
     *    `owner_count_metrics.spike_score >= self::SPIKE_THRESHOLD`; a NULL
     *    score is never excluded.
     *
     * Owner counts are CAST to SIGNED before any subtraction —
     * `number_of_owners` is unsigned and a negative difference otherwise
     * overflows (SQLSTATE 22003). Each row carries the same latest-metrics
     * columns as topByOwnerCount() plus `plus_days`, `data_days` and
     * `new_owners`. Never merges sources (NFR6).
     *
     * @return list<array<string, mixed>>
     */
    public function topByPlusDays(string $source, int $days, bool $excludeSpikes, int $limit, ?string $market = null, bool $excludeShorted = false): array
    {
        $marketCondition = self::marketCondition($market);
        $shortCondition = self::shortedCondition($excludeShorted);
        $spikeCondition = $excludeSpikes
            ? 'AND (latest.spike_score IS NULL OR latest.spike_score < :spike_threshold)'
            : '';

        $stmt = $this->pdo->prepare(
            <<<SQL
            WITH bounds AS (
                SELECT MAX(as_of_date) AS d, MAX(as_of_date) - INTERVAL :days DAY AS cutoff
                FROM owner_count_daily
                WHERE source = :source_bounds
            ),
            series AS (
                SELECT
                    o.isin,
                    o.as_of_date,
                    CAST(o.number_of_owners AS SIGNED) AS owners,
                    LAG(o.as_of_date) OVER (PARTITION BY o.isin ORDER BY o.as_of_date) AS prev_date,
                    CAST(LAG(o.number_of_owners) OVER (PARTITION BY o.isin ORDER BY o.as_of_date) AS SIGNED) AS prev_owners,
                    o.as_of_date > b.cutoff AS in_win,
                    ROW_NUMBER() OVER (PARTITION BY o.isin ORDER BY o.as_of_date DESC) AS rn_latest,
                    ROW_NUMBER() OVER (PARTITION BY o.isin, o.as_of_date > b.cutoff ORDER BY o.as_of_date DESC) AS rn_grp_desc,
                    ROW_NUMBER() OVER (PARTITION BY o.isin, o.as_of_date > b.cutoff ORDER BY o.as_of_date ASC) AS rn_grp_asc
                FROM owner_count_daily o
                CROSS JOIN bounds b
                WHERE o.source = :source_series
                  AND o.as_of_date <= b.d
            ),
            flagged AS (
                SELECT
                    s.*,
                    (s.in_win = 1 AND s.prev_date IS NOT NULL AND DATEDIFF(s.as_of_date, s.prev_date) <= 5) AS is_data_day
                FROM series s
            ),
            agg AS (
                SELECT
                    f.isin,
                    SUM(f.in_win) AS window_rows,
                    SUM(f.is_data_day) AS data_days,
                    SUM(f.is_data_day = 1 AND f.owners - f.prev_owners >= 0) AS plus_days,
                    MAX(CASE WHEN f.rn_latest = 1 THEN f.owners END)
                        - COALESCE(
                            MAX(CASE WHEN f.in_win = 0 AND f.rn_grp_desc = 1 THEN f.owners END),
                            MAX(CASE WHEN f.in_win = 1 AND f.rn_grp_asc = 1 THEN f.owners END)
                        ) AS new_owners
                FROM flagged f
                GROUP BY f.isin
            ),
            latest AS (
                SELECT
                    m.*,
                    ROW_NUMBER() OVER (PARTITION BY m.isin ORDER BY m.as_of_date DESC) AS rn
                FROM owner_count_metrics m
                WHERE m.source = :source_metrics
            )
            SELECT
                latest.isin, latest.source, latest.as_of_date, latest.number_of_owners,
                latest.delta_1d, latest.pct_1d, latest.pct_7d, latest.pct_30d, latest.pct_90d, latest.pct_365d,
                latest.sma_7, latest.sma_30, latest.sma_90,
                latest.up_streak, latest.spike_score,
                i.name, i.list,
                agg.plus_days, agg.data_days, agg.new_owners
            FROM agg
            JOIN latest ON latest.isin = agg.isin AND latest.rn = 1
            JOIN instrument i ON i.isin = agg.isin
            WHERE i.last_seen IS NULL
              AND agg.window_rows > 0
              AND agg.new_owners > 0
              {$spikeCondition}
              {$marketCondition}
              {$shortCondition}
            ORDER BY agg.plus_days DESC, agg.new_owners DESC, agg.isin ASC
            LIMIT :lim
            SQL
        );
        $stmt->bindValue(':days', max(0, $days), PDO::PARAM_INT);
        $stmt->bindValue(':source_bounds', $source, PDO::PARAM_STR);
        $stmt->bindValue(':source_series', $source, PDO::PARAM_STR);
        $stmt->bindValue(':source_metrics', $source, PDO::PARAM_STR);
        if ($excludeSpikes) {
            $stmt->bindValue(':spike_threshold', self::SPIKE_THRESHOLD);
        }
        self::bindMarket($stmt, $market);
        self::bindShorted($stmt, $excludeShorted);
        $stmt->bindValue(':lim', max(0, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_map(
            static function (array $row): array {
                $row['plus_days'] = (int) $row['plus_days'];
                $row['data_days'] = (int) $row['data_days'];
                $row['new_owners'] = (int) $row['new_owners'];

                return $row;
            },
            $stmt->fetchAll(),
        ));
    }

    /**
     * spec-plusdagar — whether `$source`'s stored history spans at least
     * `$days` calendar days: its earliest `as_of_date` lies on or before
     * its latest `as_of_date` − `$days`. False when the source has no rows
     * at all. Drives Plusdagar's "För lite historik för {period} ännu."
     * empty state for 3 mån / År.
     */
    public function historySpansDays(string $source, int $days): bool
    {
        $stmt = $this->pdo->prepare(
            <<<'SQL'
            SELECT MIN(as_of_date) <= MAX(as_of_date) - INTERVAL :days DAY AS spans
            FROM owner_count_daily
            WHERE source = :source
            SQL
        );
        $stmt->bindValue(':days', max(0, $days), PDO::PARAM_INT);
        $stmt->bindValue(':source', $source, PDO::PARAM_STR);
        $stmt->execute();

        $spans = $stmt->fetchColumn();

        return $spans !== null && $spans !== false && (int) $spans === 1;
    }

    /**
     * Story 4.2 — the Leaderboard row Sparkline's data: the last `$days`
     * `number_of_owners` values for one (isin, source), oldest first (fewer
     * than `$days` when less history exists — no padding/backfill, NFR7).
     * Reads the raw fact table directly (not the view) since only the plain
     * value series is needed here, not the derived metrics.
     *
     * @return list<array{as_of_date: string, number_of_owners: int}>
     */
    public function recentSeries(string $isin, string $source, int $days): array
    {
        $stmt = $this->pdo->prepare(
            <<<'SQL'
            SELECT as_of_date, number_of_owners FROM (
                SELECT as_of_date, number_of_owners
                FROM owner_count_daily
                WHERE isin = :isin AND source = :source
                ORDER BY as_of_date DESC
                LIMIT :lim
            ) recent
            ORDER BY as_of_date ASC
            SQL
        );
        $stmt->bindValue(':isin', $isin, PDO::PARAM_STR);
        $stmt->bindValue(':source', $source, PDO::PARAM_STR);
        $stmt->bindValue(':lim', max(0, $days), PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_map(
            static fn (array $row): array => [
                'as_of_date' => (string) $row['as_of_date'],
                'number_of_owners' => (int) $row['number_of_owners'],
            ],
            $stmt->fetchAll(),
        ));
    }

    /**
     * Story 4.4 — `/list`'s combined search/filter/sort query: every active
     * instrument for `$source` (same "latest row per isin" + "active"
     * shape as topByOwnerCount()/topByTrendQualityForPeriod()), narrowed by whichever
     * of `$filters` are present, all combined with AND. No `LIMIT` (the spec
     * renders the full result in one page load).
     *
     * `$filters` (every key optional, absent/false/null/'' means "not
     * active"):
     *  - 'q': string — case-insensitive substring match on instrument name.
     *  - 'growth': bool — the exact Topplista "Stadig tillväxt" qualifying
     *    rule (`up_streak >= 1` AND not spiking).
     *  - 'spike': bool — `spike_score >= self::SPIKE_THRESHOLD`.
     *  - 'watchlist': bool — isin exists in `watchlist`.
     *  - 'market': string — exact match on `instrument.list` (the literal
     *    stored values, e.g. 'LC'/'MC'/'SC'/'First North').
     *
     * `$sort` is self::SORT_COUNT (default) or self::SORT_PCT; any other
     * value falls back to SORT_COUNT so a garbage query value never 500s.
     *
     * @param array{q?: ?string, growth?: bool, spike?: bool, watchlist?: bool, market?: ?string} $filters
     *
     * @return list<array<string, mixed>>
     */
    public function searchAndFilter(string $source, array $filters, string $sort): array
    {
        $conditions = ['latest.rn = 1', 'i.last_seen IS NULL'];
        $params = ['source' => $source];

        $q = $filters['q'] ?? null;
        if ($q !== null && $q !== '') {
            $conditions[] = 'LOWER(i.name) LIKE LOWER(:q)';
            $params['q'] = '%' . self::escapeLike($q) . '%';
        }

        if (!empty($filters['growth'])) {
            $conditions[] = 'latest.up_streak >= 1';
            $conditions[] = '(latest.spike_score IS NULL OR latest.spike_score < :spike_threshold_growth)';
            $params['spike_threshold_growth'] = self::SPIKE_THRESHOLD;
        }

        if (!empty($filters['spike'])) {
            $conditions[] = 'latest.spike_score >= :spike_threshold_spike';
            $params['spike_threshold_spike'] = self::SPIKE_THRESHOLD;
        }

        if (!empty($filters['watchlist'])) {
            $conditions[] = 'latest.isin IN (SELECT isin FROM watchlist)';
        }

        $market = $filters['market'] ?? null;
        if ($market !== null && $market !== '') {
            $conditions[] = 'i.list = :market';
            $params['market'] = $market;
        }

        $orderBy = $sort === self::SORT_PCT
            ? 'latest.pct_1d DESC, latest.isin ASC'
            : 'latest.number_of_owners DESC, latest.isin ASC';

        $sql = sprintf(
            <<<'SQL'
            WITH latest AS (
                SELECT
                    m.*,
                    ROW_NUMBER() OVER (PARTITION BY m.isin ORDER BY m.as_of_date DESC) AS rn
                FROM owner_count_metrics m
                WHERE m.source = :source
            )
            SELECT
                latest.isin, latest.source, latest.as_of_date, latest.number_of_owners,
                latest.delta_1d, latest.pct_1d, latest.sma_7, latest.sma_30, latest.sma_90,
                latest.up_streak, latest.spike_score,
                i.name, i.list
            FROM latest
            JOIN instrument i ON i.isin = latest.isin
            WHERE %s
            ORDER BY %s
            SQL,
            implode(' AND ', $conditions),
            $orderBy,
        );

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();

        return array_values($stmt->fetchAll());
    }

    /**
     * Story 4.4 — the batched sparkline data for every visible `/list` row:
     * one query for the last `$days` `number_of_owners` values per isin,
     * instead of Story 4.2's per-row recentSeries() call (unacceptable
     * N+1 shape at full-list scale). Same "last N per isin, oldest first"
     * shape as recentSeries(), extended with a PARTITION-BY-isin window
     * function to cap rows-per-isin in one round trip.
     *
     * Every requested isin is present as a key, even when it has no stored
     * rows for `$source` (empty list, same "no padding" contract as
     * recentSeries()).
     *
     * @param list<string> $isins
     *
     * @return array<string, list<array{as_of_date: string, number_of_owners: int}>>
     */
    public function recentSeriesForIsins(array $isins, string $source, int $days): array
    {
        $isins = array_values(array_unique($isins));

        $out = [];
        foreach ($isins as $isin) {
            $out[$isin] = [];
        }

        if ($isins === []) {
            return $out;
        }

        $placeholders = implode(',', array_fill(0, count($isins), '?'));
        $stmt = $this->pdo->prepare(
            <<<SQL
            SELECT isin, as_of_date, number_of_owners FROM (
                SELECT
                    isin, as_of_date, number_of_owners,
                    ROW_NUMBER() OVER (PARTITION BY isin ORDER BY as_of_date DESC) AS rn
                FROM owner_count_daily
                WHERE source = ? AND isin IN ($placeholders)
            ) recent
            WHERE rn <= ?
            ORDER BY isin ASC, as_of_date ASC
            SQL
        );

        $position = 1;
        $stmt->bindValue($position++, $source, PDO::PARAM_STR);
        foreach ($isins as $isin) {
            $stmt->bindValue($position++, $isin, PDO::PARAM_STR);
        }
        $stmt->bindValue($position, max(0, $days), PDO::PARAM_INT);
        $stmt->execute();

        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['isin']][] = [
                'as_of_date' => (string) $row['as_of_date'],
                'number_of_owners' => (int) $row['number_of_owners'],
            ];
        }

        return $out;
    }

    /**
     * spec-5-6 — TopTenDigest's "Flest ägare" snapshot for one exact
     * calendar date: mirrors topByOwnerCount()'s column list, JOIN and
     * "active only" filter, but queries `m.as_of_date = :date` directly
     * instead of the ROW_NUMBER "latest" CTE — one row per isin/source/date
     * already exists, so no window function is needed to pin a specific day.
     * Empty when there is no data at all for that date (e.g. the very first
     * derive run, or a day before the instrument existed).
     *
     * @return list<array<string, mixed>>
     */
    public function topByOwnerCountAsOf(string $source, string $asOfDate, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            <<<'SQL'
            SELECT
                m.isin, m.source, m.as_of_date, m.number_of_owners,
                m.delta_1d, m.pct_1d, m.pct_7d, m.pct_30d, m.pct_90d, m.pct_365d,
                m.sma_7, m.sma_30, m.sma_90,
                m.up_streak, m.spike_score,
                i.name, i.list
            FROM owner_count_metrics m
            JOIN instrument i ON i.isin = m.isin
            WHERE m.source = :source
              AND m.as_of_date = :date
              AND i.last_seen IS NULL
            ORDER BY m.number_of_owners DESC, m.isin ASC
            LIMIT :lim
            SQL
        );
        $stmt->bindValue(':source', $source, PDO::PARAM_STR);
        $stmt->bindValue(':date', $asOfDate, PDO::PARAM_STR);
        $stmt->bindValue(':lim', max(0, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return array_values($stmt->fetchAll());
    }

    /**
     * spec-5-6 — TopTenDigest's "Stadig tillväxt" snapshot for one exact
     * calendar date: same qualifying rule as topByTrendQualityForPeriod() (`up_streak
     * >= 1`, spike-excluded), pinned to `m.as_of_date = :date` instead of the
     * "latest per isin" CTE. See topByOwnerCountAsOf() for why no window
     * function is needed here.
     *
     * @return list<array<string, mixed>>
     */
    public function topByTrendQualityAsOf(string $source, string $asOfDate, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            <<<'SQL'
            SELECT
                m.isin, m.source, m.as_of_date, m.number_of_owners,
                m.delta_1d, m.pct_1d, m.pct_7d, m.pct_30d, m.pct_90d, m.pct_365d,
                m.sma_7, m.sma_30, m.sma_90,
                m.up_streak, m.spike_score,
                i.name, i.list
            FROM owner_count_metrics m
            JOIN instrument i ON i.isin = m.isin
            WHERE m.source = :source
              AND m.as_of_date = :date
              AND i.last_seen IS NULL
              AND m.up_streak >= 1
              AND (m.spike_score IS NULL OR m.spike_score < :spike_threshold)
            ORDER BY m.up_streak DESC, m.isin ASC
            LIMIT :lim
            SQL
        );
        $stmt->bindValue(':source', $source, PDO::PARAM_STR);
        $stmt->bindValue(':date', $asOfDate, PDO::PARAM_STR);
        $stmt->bindValue(':spike_threshold', self::SPIKE_THRESHOLD);
        $stmt->bindValue(':lim', max(0, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return array_values($stmt->fetchAll());
    }

    /**
     * spec-5-4 — Topplista's "Alla" mode: each isin's *latest*
     * `number_of_owners` for `$source`, keyed by isin. Mirrors
     * recentSeriesForIsins()'s batch-over-isins shape (one query, a
     * ROW_NUMBER() PARTITION BY isin window, rn = 1) but unlike it does
     * NOT pad missing isins with an empty entry — an isin with no
     * `owner_count_daily` rows for `$source` is simply absent from the
     * returned array, and that absence IS the "no data for this source"
     * signal the caller renders as "ingen data" instead of a misleading
     * zero (NFR6).
     *
     * @param list<string> $isins
     *
     * @return array<string, int>
     */
    public function latestOwnerCountForIsins(array $isins, string $source): array
    {
        $isins = array_values(array_unique($isins));

        if ($isins === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($isins), '?'));
        $stmt = $this->pdo->prepare(
            <<<SQL
            SELECT isin, number_of_owners FROM (
                SELECT
                    isin, number_of_owners,
                    ROW_NUMBER() OVER (PARTITION BY isin ORDER BY as_of_date DESC) AS rn
                FROM owner_count_daily
                WHERE source = ? AND isin IN ($placeholders)
            ) latest
            WHERE rn = 1
            SQL
        );

        $position = 1;
        $stmt->bindValue($position++, $source, PDO::PARAM_STR);
        foreach ($isins as $isin) {
            $stmt->bindValue($position++, $isin, PDO::PARAM_STR);
        }
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['isin']] = (int) $row['number_of_owners'];
        }

        return $out;
    }

    /**
     * Escapes LIKE's own wildcard characters in user input so a search
     * term containing '%' or '_' is matched literally, not as a wildcard.
     * MariaDB's default LIKE escape character is '\' — no ESCAPE clause
     * needed as long as '\' itself is escaped too.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
