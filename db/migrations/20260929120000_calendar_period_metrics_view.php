<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * spec-calendar-period-metrics — turns every period metric of the
 * `owner_count_metrics` view into a calendar period. Collection runs Mon–Fri
 * only (skipping holidays), so spec-5-5's exact `DATEDIFF = N` against the
 * row N *rows* back left pct_7d/pct_90d/pct_365d effectively always NULL and
 * delta_1d/pct_1d NULL every Monday and after every holiday.
 *
 * - delta_1d / pct_1d: against the previous stored row (previous trading
 *   day), only if it is at most 5 calendar days back.
 * - pct_7d / pct_30d (new) / pct_90d / pct_365d: against the latest stored
 *   row on or before as_of_date - N days, only if that row lies within N+5
 *   days. Found in a single window pass: LAST_VALUE over a numeric
 *   `RANGE BETWEEN UNBOUNDED PRECEDING AND N PRECEDING` frame ordered by
 *   TO_DAYS(as_of_date) (MariaDB has no date-interval RANGE frames).
 * - sma_7/30/90, up_streak and spike_score are unchanged.
 *
 * Phinx has no ALTER VIEW helper, so this drops and recreates the view;
 * down() restores the spec-5-5 view (20260914000000) verbatim.
 */
final class CalendarPeriodMetricsView extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('DROP VIEW IF EXISTS `owner_count_metrics`');

        $this->execute(
            <<<'SQL'
            CREATE VIEW `owner_count_metrics` AS
            WITH days AS (
                -- TO_DAYS() turns as_of_date into a plain integer day number:
                -- MariaDB has no date-interval RANGE frames, but a numeric
                -- RANGE frame over a day number gives the same calendar
                -- window ("every row at least N days before this one").
                SELECT
                    isin,
                    source,
                    as_of_date,
                    number_of_owners,
                    TO_DAYS(as_of_date) AS day_num
                FROM owner_count_daily
            ),
            base AS (
                -- anchor_*_N = the latest stored row on or before
                -- as_of_date - N days (an empty frame yields NULL).
                SELECT
                    isin,
                    source,
                    as_of_date,
                    number_of_owners,
                    LAG(number_of_owners) OVER w AS prev_owners,
                    LAG(as_of_date) OVER w AS prev_date,
                    LAST_VALUE(number_of_owners) OVER w7 AS anchor_owners_7,
                    LAST_VALUE(as_of_date) OVER w7 AS anchor_date_7,
                    LAST_VALUE(number_of_owners) OVER w30 AS anchor_owners_30,
                    LAST_VALUE(as_of_date) OVER w30 AS anchor_date_30,
                    LAST_VALUE(number_of_owners) OVER w90 AS anchor_owners_90,
                    LAST_VALUE(as_of_date) OVER w90 AS anchor_date_90,
                    LAST_VALUE(number_of_owners) OVER w365 AS anchor_owners_365,
                    LAST_VALUE(as_of_date) OVER w365 AS anchor_date_365,
                    ROW_NUMBER() OVER w AS rn
                FROM days
                WINDOW
                    w    AS (PARTITION BY isin, source ORDER BY as_of_date),
                    w7   AS (PARTITION BY isin, source ORDER BY day_num RANGE BETWEEN UNBOUNDED PRECEDING AND 7 PRECEDING),
                    w30  AS (PARTITION BY isin, source ORDER BY day_num RANGE BETWEEN UNBOUNDED PRECEDING AND 30 PRECEDING),
                    w90  AS (PARTITION BY isin, source ORDER BY day_num RANGE BETWEEN UNBOUNDED PRECEDING AND 90 PRECEDING),
                    w365 AS (PARTITION BY isin, source ORDER BY day_num RANGE BETWEEN UNBOUNDED PRECEDING AND 365 PRECEDING)
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
                    anchor_owners_7,
                    anchor_date_7,
                    anchor_owners_30,
                    anchor_date_30,
                    anchor_owners_90,
                    anchor_date_90,
                    anchor_owners_365,
                    anchor_date_365,
                    (CAST(number_of_owners AS SIGNED) - CAST(prev_owners AS SIGNED)) AS raw_delta
                FROM base
            ),
            gapped AS (
                -- Calendar-period rule (spec-calendar-period-metrics): the
                -- 1-day change compares against the previous stored row
                -- (previous trading day) only if it is at most 5 days back
                -- (covers Fri->Mon and Easter's Thu->Tue); pct_Nd compares
                -- against the anchor row only if it lies within N+5 days.
                -- Anything further back is NULL, never a misleading number.
                -- raw_delta itself stays gap-tolerant for the row-based
                -- metrics computed further down.
                SELECT
                    isin,
                    source,
                    as_of_date,
                    number_of_owners,
                    rn,
                    raw_delta,
                    CASE WHEN prev_date IS NOT NULL AND DATEDIFF(as_of_date, prev_date) <= 5
                         THEN raw_delta
                    END AS delta_1d,
                    CASE WHEN prev_date IS NOT NULL AND DATEDIFF(as_of_date, prev_date) <= 5
                         THEN CAST(raw_delta AS DECIMAL(20,10)) / NULLIF(CAST(prev_owners AS DECIMAL(20,10)), 0)
                    END AS pct_1d,
                    CASE WHEN anchor_date_7 IS NOT NULL AND DATEDIFF(as_of_date, anchor_date_7) <= 12
                         THEN CAST(CAST(number_of_owners AS SIGNED) - CAST(anchor_owners_7 AS SIGNED) AS DECIMAL(20,10)) / NULLIF(CAST(anchor_owners_7 AS DECIMAL(20,10)), 0)
                    END AS pct_7d,
                    CASE WHEN anchor_date_30 IS NOT NULL AND DATEDIFF(as_of_date, anchor_date_30) <= 35
                         THEN CAST(CAST(number_of_owners AS SIGNED) - CAST(anchor_owners_30 AS SIGNED) AS DECIMAL(20,10)) / NULLIF(CAST(anchor_owners_30 AS DECIMAL(20,10)), 0)
                    END AS pct_30d,
                    CASE WHEN anchor_date_90 IS NOT NULL AND DATEDIFF(as_of_date, anchor_date_90) <= 95
                         THEN CAST(CAST(number_of_owners AS SIGNED) - CAST(anchor_owners_90 AS SIGNED) AS DECIMAL(20,10)) / NULLIF(CAST(anchor_owners_90 AS DECIMAL(20,10)), 0)
                    END AS pct_90d,
                    CASE WHEN anchor_date_365 IS NOT NULL AND DATEDIFF(as_of_date, anchor_date_365) <= 370
                         THEN CAST(CAST(number_of_owners AS SIGNED) - CAST(anchor_owners_365 AS SIGNED) AS DECIMAL(20,10)) / NULLIF(CAST(anchor_owners_365 AS DECIMAL(20,10)), 0)
                    END AS pct_365d
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
                    pct_7d,
                    pct_30d,
                    pct_90d,
                    pct_365d,
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
                    pct_7d,
                    pct_30d,
                    pct_90d,
                    pct_365d,
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
                    pct_7d,
                    pct_30d,
                    pct_90d,
                    pct_365d,
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
                pct_7d,
                pct_30d,
                pct_90d,
                pct_365d,
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

        // Restores the spec-5-5 view SQL verbatim (not a reference to that
        // migration's class) -- ExtendOwnerCountMetricsViewMultiPeriodPct::up().
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
                    LAG(number_of_owners, 7) OVER w AS prev_owners_7,
                    LAG(as_of_date, 7) OVER w AS prev_date_7,
                    LAG(number_of_owners, 90) OVER w AS prev_owners_90,
                    LAG(as_of_date, 90) OVER w AS prev_date_90,
                    LAG(number_of_owners, 365) OVER w AS prev_owners_365,
                    LAG(as_of_date, 365) OVER w AS prev_date_365,
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
                    prev_owners_7,
                    prev_date_7,
                    prev_owners_90,
                    prev_date_90,
                    prev_owners_365,
                    prev_date_365,
                    (CAST(number_of_owners AS SIGNED) - CAST(prev_owners AS SIGNED)) AS raw_delta
                FROM base
            ),
            gapped AS (
                -- delta_1d / pct_1d / pct_7d / pct_90d / pct_365d are all
                -- NULL unless the two rows being compared are exactly N
                -- calendar days apart (gap-handling decision, 2026-09-11,
                -- extended to the three new periods by spec-5-5) -- raw_delta
                -- itself stays gap-tolerant for the row-based metrics
                -- computed further down.
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
                    END AS pct_1d,
                    CASE WHEN prev_date_7 IS NOT NULL AND DATEDIFF(as_of_date, prev_date_7) = 7
                         THEN CAST(CAST(number_of_owners AS SIGNED) - CAST(prev_owners_7 AS SIGNED) AS DECIMAL(20,10)) / NULLIF(CAST(prev_owners_7 AS DECIMAL(20,10)), 0)
                    END AS pct_7d,
                    CASE WHEN prev_date_90 IS NOT NULL AND DATEDIFF(as_of_date, prev_date_90) = 90
                         THEN CAST(CAST(number_of_owners AS SIGNED) - CAST(prev_owners_90 AS SIGNED) AS DECIMAL(20,10)) / NULLIF(CAST(prev_owners_90 AS DECIMAL(20,10)), 0)
                    END AS pct_90d,
                    CASE WHEN prev_date_365 IS NOT NULL AND DATEDIFF(as_of_date, prev_date_365) = 365
                         THEN CAST(CAST(number_of_owners AS SIGNED) - CAST(prev_owners_365 AS SIGNED) AS DECIMAL(20,10)) / NULLIF(CAST(prev_owners_365 AS DECIMAL(20,10)), 0)
                    END AS pct_365d
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
                    pct_7d,
                    pct_90d,
                    pct_365d,
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
                    pct_7d,
                    pct_90d,
                    pct_365d,
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
                    pct_7d,
                    pct_90d,
                    pct_365d,
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
                pct_7d,
                pct_90d,
                pct_365d,
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
}
