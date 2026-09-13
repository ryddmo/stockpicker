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
     * by topByTrendQuality()'s exclusion filter (bound as a query parameter,
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

        return $stmt->fetchAll();
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
     *
     * @return list<array<string, mixed>>
     */
    public function topByOwnerCount(string $source, int $limit): array
    {
        $stmt = $this->pdo->prepare(
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
            WHERE latest.rn = 1
              AND i.last_seen IS NULL
            ORDER BY latest.number_of_owners DESC, latest.isin ASC
            LIMIT :lim
            SQL
        );
        $stmt->bindValue(':source', $source, PDO::PARAM_STR);
        $stmt->bindValue(':lim', max(0, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Story 4.2 — Topplista's "Stadig tillväxt" ranking: the top `$limit`
     * active instruments for `$source` by `up_streak` DESC alone, on each
     * isin's *latest* row (see topByOwnerCount()). To "qualify" at all a row
     * must have a real ongoing streak (`up_streak >= 1` — the same condition
     * as the Streak badge) and must not be spiking: any row at or above
     * self::SPIKE_THRESHOLD is excluded from the ranking entirely (decided
     * 2026-09-13, spec-4-2) — a genuine spike must never outrank, or even
     * appear among, steady growers, however long its streak. No separate
     * sma_30-slope calculation. Flat/no-streak instruments (`up_streak` 0 or
     * NULL) never qualify either — this is what makes the zero-qualifiers
     * empty state ("Inga aktier med stadig tillväxt just nu.") reachable on
     * an otherwise data-rich day.
     *
     * @return list<array<string, mixed>>
     */
    public function topByTrendQuality(string $source, int $limit): array
    {
        $stmt = $this->pdo->prepare(
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
            WHERE latest.rn = 1
              AND i.last_seen IS NULL
              AND latest.up_streak >= 1
              AND (latest.spike_score IS NULL OR latest.spike_score < :spike_threshold)
            ORDER BY latest.up_streak DESC, latest.isin ASC
            LIMIT :lim
            SQL
        );
        $stmt->bindValue(':source', $source, PDO::PARAM_STR);
        $stmt->bindValue(':spike_threshold', self::SPIKE_THRESHOLD);
        $stmt->bindValue(':lim', max(0, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
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

        return array_map(
            static fn (array $row): array => [
                'as_of_date' => (string) $row['as_of_date'],
                'number_of_owners' => (int) $row['number_of_owners'],
            ],
            $stmt->fetchAll(),
        );
    }

    /**
     * Story 4.4 — `/list`'s combined search/filter/sort query: every active
     * instrument for `$source` (same "latest row per isin" + "active"
     * shape as topByOwnerCount()/topByTrendQuality()), narrowed by whichever
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

        return $stmt->fetchAll();
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
