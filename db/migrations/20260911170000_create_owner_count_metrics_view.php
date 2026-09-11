<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Story 3.1 — the derived-metrics view over `owner_count_daily`. A MariaDB
 * SQL view, not a materialized table (decided 2026-09-11): no refresh/rebuild
 * step exists or is needed, because the view is recomputed on every read.
 *
 * Per (isin, source) ordered by as_of_date: delta_1d, pct_1d, sma_7, sma_30,
 * sma_90, up_streak, spike_score — formulas from addendum.md, see the spec's
 * Design Notes for the derivation of the CTE chain below. Nothing here writes
 * to owner_count_daily; it stays untouched (Phinx has no first-class view
 * helper, so raw execute() is used, matching 20260911100000_enrich_ingest_run
 * .php's precedent for hand-written SQL in a migration).
 */
final class CreateOwnerCountMetricsView extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            <<<'SQL'
            CREATE VIEW `owner_count_metrics` AS
            WITH base AS (
                SELECT
                    isin,
                    source,
                    as_of_date,
                    number_of_owners,
                    LAG(number_of_owners) OVER w AS prev_owners,
                    LAG(as_of_date) OVER w AS prev_date,
                    ROW_NUMBER() OVER w AS rn
                FROM owner_count_daily
                WINDOW w AS (PARTITION BY isin, source ORDER BY as_of_date)
            ),
            deltas AS (
                -- number_of_owners/prev_owners are INT UNSIGNED: MariaDB's
                -- default sql_mode (no NO_UNSIGNED_SUBTRACTION) makes a plain
                -- unsigned minus unsigned wrap around to a huge positive
                -- number on an actual decrease, instead of going negative --
                -- CAST to SIGNED first so a falling owner count subtracts to
                -- a real negative delta.
                SELECT
                    isin,
                    source,
                    as_of_date,
                    number_of_owners,
                    rn,
                    prev_owners,
                    prev_date,
                    (CAST(number_of_owners AS SIGNED) - CAST(prev_owners AS SIGNED)) AS raw_delta
                FROM base
            ),
            gapped AS (
                -- delta_1d / pct_1d are NULL unless the two rows being compared
                -- are exactly one calendar day apart (gap-handling decision,
                -- 2026-09-11) -- raw_delta itself stays gap-tolerant for the
                -- row-based metrics computed further down.
                SELECT
                    isin,
                    source,
                    as_of_date,
                    number_of_owners,
                    rn,
                    raw_delta,
                    CASE WHEN prev_date IS NOT NULL AND DATEDIFF(as_of_date, prev_date) = 1
                         THEN raw_delta
                    END AS delta_1d,
                    CASE WHEN prev_date IS NOT NULL AND DATEDIFF(as_of_date, prev_date) = 1
                         THEN CAST(raw_delta AS DECIMAL(20,10)) / NULLIF(CAST(prev_owners AS DECIMAL(20,10)), 0)
                    END AS pct_1d
                FROM deltas
            ),
            streaks AS (
                -- Gaps-and-islands grouping: every non-up row (raw_delta <= 0,
                -- or NULL on the partition's first row) starts a new group and
                -- is itself included in it as the group's anchor.
                SELECT
                    isin,
                    source,
                    as_of_date,
                    number_of_owners,
                    rn,
                    raw_delta,
                    delta_1d,
                    pct_1d,
                    SUM(CASE WHEN raw_delta > 0 THEN 0 ELSE 1 END)
                        OVER (PARTITION BY isin, source ORDER BY as_of_date) AS grp
                FROM gapped
            ),
            grouped AS (
                -- Running row count within the current group, anchor row
                -- included -- subtracting 1 in the final SELECT yields the
                -- number of consecutive up-days ending at this row.
                SELECT
                    isin,
                    source,
                    as_of_date,
                    number_of_owners,
                    rn,
                    raw_delta,
                    delta_1d,
                    pct_1d,
                    COUNT(*) OVER (PARTITION BY isin, source, grp ORDER BY as_of_date) AS grp_count
                FROM streaks
            ),
            metrics AS (
                SELECT
                    isin,
                    source,
                    as_of_date,
                    number_of_owners,
                    rn,
                    raw_delta,
                    delta_1d,
                    pct_1d,
                    grp_count,
                    AVG(number_of_owners) OVER w7 AS sma_7_raw,
                    AVG(number_of_owners) OVER w30 AS sma_30_raw,
                    AVG(number_of_owners) OVER w90 AS sma_90_raw,
                    STDDEV_SAMP(number_of_owners) OVER w30 AS stddev_30
                FROM grouped
                WINDOW
                    w7  AS (PARTITION BY isin, source ORDER BY as_of_date ROWS BETWEEN 6 PRECEDING AND CURRENT ROW),
                    w30 AS (PARTITION BY isin, source ORDER BY as_of_date ROWS BETWEEN 29 PRECEDING AND CURRENT ROW),
                    w90 AS (PARTITION BY isin, source ORDER BY as_of_date ROWS BETWEEN 89 PRECEDING AND CURRENT ROW)
            )
            SELECT
                isin,
                source,
                as_of_date,
                number_of_owners,
                delta_1d,
                pct_1d,
                CASE WHEN rn >= 7  THEN sma_7_raw  END AS sma_7,
                CASE WHEN rn >= 30 THEN sma_30_raw END AS sma_30,
                CASE WHEN rn >= 90 THEN sma_90_raw END AS sma_90,
                CASE
                    WHEN rn = 1 THEN NULL
                    WHEN raw_delta > 0 THEN grp_count - 1
                    ELSE 0
                END AS up_streak,
                CASE WHEN rn >= 30
                     THEN CAST(number_of_owners - sma_30_raw AS DECIMAL(20,10)) / NULLIF(CAST(stddev_30 AS DECIMAL(20,10)), 0)
                END AS spike_score
            FROM metrics
            SQL
        );
    }

    public function down(): void
    {
        $this->execute('DROP VIEW IF EXISTS `owner_count_metrics`');
    }
}
