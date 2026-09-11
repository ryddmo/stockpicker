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
        // owner_count_daily first — it now has FKs to instrument and ingest_run.
        $this->pdo->exec('DROP TABLE IF EXISTS owner_count_daily');
        $this->pdo->exec('DROP TABLE IF EXISTS work_queue');
        $this->pdo->exec('DROP TABLE IF EXISTS ingest_run');
        $this->pdo->exec('DROP TABLE IF EXISTS instrument');
        $this->pdo->exec('DROP TABLE IF EXISTS settings');
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

        // Story 3.1 — mirrors db/migrations/20260911170000_create_owner_count_metrics_view.php.
        // Keep this in step with the migration; there is no automated check.
        $this->pdo->exec(
            <<<'SQL'
            CREATE VIEW owner_count_metrics AS
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
