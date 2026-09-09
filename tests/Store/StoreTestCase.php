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
 * never touch the schema the Phinx `development` environment manages. The two
 * tables are (re)created fresh per test from DDL that mirrors
 * db/migrations/20260908161500_create_instrument_and_settings.php — keep them
 * in step.
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
        // owner_count_daily first — it has an FK to instrument.
        $this->pdo->exec('DROP TABLE IF EXISTS owner_count_daily');
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

        $this->pdo->exec(
            'CREATE TABLE owner_count_daily (
                isin VARCHAR(12) NOT NULL,
                source VARCHAR(16) NOT NULL,
                as_of_date DATE NOT NULL,
                number_of_owners INT UNSIGNED NOT NULL,
                last_price DECIMAL(18,4) NULL,
                market_cap DECIMAL(24,2) NULL,
                fetched_at DATETIME NOT NULL,
                PRIMARY KEY (isin, source, as_of_date),
                CONSTRAINT fk_owner_count_daily_isin FOREIGN KEY (isin) REFERENCES instrument (isin)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
}
