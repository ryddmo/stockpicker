<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Store;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Stockpicker\Config;
use Stockpicker\Store\Database;

/**
 * Base for the store integration tests. They need a real MariaDB (no SQLite —
 * the project targets MariaDB only), so they run against the docker-compose
 * `development` server and self-skip when it is unreachable, keeping
 * `composer test` green on a machine with no database.
 *
 * Tests use a dedicated `stockpicker_test` database (created via root) so they
 * never touch the schema the Phinx `development` environment manages. The
 * tables are (re)created fresh per test from DDL that hand-mirrors the Phinx
 * migrations in db/migrations/ — keep the two in step (the drift is a known
 * deferred-work.md item).
 */
abstract class StoreTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        $host = getenv('STOCKPICKER_TEST_DB_HOST') ?: '127.0.0.1';
        $user = getenv('STOCKPICKER_TEST_DB_USER') ?: 'root';
        $pass = getenv('STOCKPICKER_TEST_DB_PASS') ?: 'root';
        $name = getenv('STOCKPICKER_TEST_DB_NAME') ?: 'stockpicker_test';

        try {
            $root = new PDO(
                sprintf('mysql:host=%s;charset=utf8mb4', $host),
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (PDOException $e) {
            self::markTestSkipped('development MariaDB not reachable (docker compose up -d): ' . $e->getMessage());
        }

        $root->exec(sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4', $name));

        $this->pdo = Database::connect(Config::fromArray([
            'db' => [
                'host' => $host,
                'name' => $name,
                'user' => $user,
                'pass' => $pass,
                'charset' => 'utf8mb4',
            ],
            'cron_token' => 'test',
        ]));

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->dropSchema();
        }
    }

    private function dropSchema(): void
    {
        // The view first — it reads owner_count_daily.
        $this->pdo->exec('DROP VIEW IF EXISTS owner_count_metrics');
        // watchlist and owner_count_daily before instrument — both FK to it.
        $this->pdo->exec('DROP TABLE IF EXISTS watchlist');
        $this->pdo->exec('DROP TABLE IF EXISTS owner_count_daily');
        $this->pdo->exec('DROP TABLE IF EXISTS work_queue');
        $this->pdo->exec('DROP TABLE IF EXISTS ingest_run');
        $this->pdo->exec('DROP TABLE IF EXISTS instrument');
        $this->pdo->exec('DROP TABLE IF EXISTS settings');
        $this->pdo->exec('DROP TABLE IF EXISTS trading_holiday');
        $this->pdo->exec('DROP TABLE IF EXISTS short_position');
    }

    private function createSchema(): void
    {
        $this->dropSchema();

        $this->pdo->exec(
            'CREATE TABLE instrument (
                isin VARCHAR(12) NOT NULL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                list VARCHAR(20) NOT NULL,
                avanza_orderbook_id VARCHAR(32) NULL,
                nordnet_instrument_id VARCHAR(64) NULL,
                lei VARCHAR(20) NULL,
                first_seen DATE NOT NULL,
                last_seen DATE NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $this->pdo->exec(
            'CREATE TABLE settings (
                `key` VARCHAR(64) NOT NULL PRIMARY KEY,
                `value` VARCHAR(255) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        // spec-short-interest-data — mirrors
        // db/migrations/20261003120000_create_short_position.php (also the
        // `instrument.lei` column above). Keep this in step with the migration.
        $this->pdo->exec(
            'CREATE TABLE short_position (
                snapshot_date DATE NOT NULL,
                lei VARCHAR(20) NOT NULL,
                issuer_name VARCHAR(255) NOT NULL,
                position_pct DECIMAL(6,2) NOT NULL,
                position_date DATE NOT NULL,
                fetched_at DATETIME NOT NULL,
                PRIMARY KEY (snapshot_date, lei),
                KEY ix_short_position_lei (lei)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        // Mirrors db/migrations/20260915120000_create_trading_holiday.php.
        // Keep this in step with the migration; there is no automated check.
        $this->pdo->exec(
            'CREATE TABLE trading_holiday (
                holiday_date DATE NOT NULL PRIMARY KEY,
                description VARCHAR(120) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        // Story 4.2 — mirrors db/migrations/20260913120000_create_watchlist.php.
        // Keep this in step with the migration; there is no automated check.
        $this->pdo->exec(
            'CREATE TABLE watchlist (
                isin VARCHAR(12) NOT NULL PRIMARY KEY,
                starred_at DATETIME NOT NULL,
                CONSTRAINT fk_watchlist_isin FOREIGN KEY (isin) REFERENCES instrument (isin)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        // Story 2.6 — ingest_run must precede owner_count_daily because owner
        // facts carry a nullable FK to their producing run.
        $this->pdo->exec(
            "CREATE TABLE ingest_run (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                run_type VARCHAR(16) NOT NULL,
                run_date DATE NOT NULL,
                started_at DATETIME NOT NULL,
                finished_at DATETIME NULL,
                instrument_count INT UNSIGNED NOT NULL,
                ok_count INT UNSIGNED NOT NULL,
                fail_count INT UNSIGNED NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'running',
                alarm TINYINT(1) NOT NULL DEFAULT 0,
                schema_mismatch_count INT UNSIGNED NOT NULL DEFAULT 0,
                by_source TEXT NOT NULL,
                KEY ix_ingest_run_run_date (run_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->pdo->exec(
            'CREATE TABLE owner_count_daily (
                isin VARCHAR(12) NOT NULL,
                source VARCHAR(16) NOT NULL,
                as_of_date DATE NOT NULL,
                number_of_owners INT UNSIGNED NOT NULL,
                last_price DECIMAL(18,4) NULL,
                market_cap DECIMAL(24,2) NULL,
                fetched_at DATETIME NOT NULL,
                ingest_run_id BIGINT UNSIGNED NULL,
                PRIMARY KEY (isin, source, as_of_date),
                CONSTRAINT fk_owner_count_daily_isin FOREIGN KEY (isin) REFERENCES instrument (isin),
                CONSTRAINT fk_owner_count_daily_run FOREIGN KEY (ingest_run_id) REFERENCES ingest_run (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        // Story 3.1 — mirrors db/migrations/20260911170000_create_owner_count_metrics_view.php,
        // extended by spec-5-5 (20260914000000_extend_owner_count_metrics_view_multi_period_pct.php)
        // and now mirroring spec-calendar-period-metrics
        // db/migrations/20260929120000_calendar_period_metrics_view.php
        // (calendar-period pct_7d/30d/90d/365d, ≤5-day delta_1d). Keep this in
        // step with the migrations; there is no automated check.
        $this->pdo->exec(
            <<<'SQL'
            CREATE VIEW owner_count_metrics AS
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

        $this->pdo->exec(
            "CREATE TABLE work_queue (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                isin VARCHAR(12) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                run_date DATE NOT NULL,
                claimed_at DATETIME NULL,
                UNIQUE KEY uq_work_queue_isin_run_date (isin, run_date),
                KEY ix_work_queue_status_run_date (status, run_date),
                CONSTRAINT fk_work_queue_isin FOREIGN KEY (isin) REFERENCES instrument (isin)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

    }
}
