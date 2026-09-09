<?php

declare(strict_types=1);

namespace Stockpicker\Tests;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Drives the real Phinx migration against a throwaway `testing` database
 * (docker compose `development` server). Self-skips when that server is down.
 */
final class MigrationTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/..';

    private PDO $pdo;

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
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name),
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $this->dropAll();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->dropAll();
        }
    }

    public function testMigrateCreatesTheSchemaAndSeedsSettings(): void
    {
        [$code, $out] = $this->phinx('migrate', '-e', 'testing');
        self::assertSame(0, $code, $out);

        self::assertTrue($this->tableExists('instrument'));
        self::assertTrue($this->tableExists('settings'));
        self::assertTrue($this->tableExists('owner_count_daily'));
        self::assertTrue($this->tableExists('work_queue'));
        self::assertTrue($this->tableExists('ingest_run'));

        // Story 1.6 widened this column to hold the 36-char nnx UUID.
        self::assertSame('varchar(64)', $this->columnType('instrument', 'nordnet_instrument_id'));

        // The append-only / first-write-wins contract depends on this exact
        // composite primary key and on number_of_owners being unsigned.
        self::assertSame(['isin', 'source', 'as_of_date'], $this->primaryKey('owner_count_daily'));
        self::assertStringContainsString('unsigned', $this->columnType('owner_count_daily', 'number_of_owners'));

        // A duplicate (isin, source, as_of_date) must be rejected by the real schema.
        $this->pdo->exec(
            "INSERT INTO instrument (isin, name, list, first_seen)
             VALUES ('SE0000000000', 'Test', 'LC', '2026-01-01')"
        );
        $ins = "INSERT INTO owner_count_daily
                    (isin, source, as_of_date, number_of_owners, fetched_at)
                VALUES ('SE0000000000', 'avanza', '2026-01-01', 100, '2026-01-01 00:00:00')";
        $this->pdo->exec($ins);
        try {
            $this->pdo->exec($ins);
            self::fail('the migrated schema allowed a duplicate composite key');
        } catch (\PDOException $e) {
            self::assertSame('23000', $e->getCode());
        }

        // work_queue: surrogate BIGINT UNSIGNED id, status default 'pending',
        // unique (isin, run_date), FK on isin.
        self::assertSame(['id'], $this->primaryKey('work_queue'));
        self::assertStringContainsString('bigint', $this->columnType('work_queue', 'id'));
        self::assertStringContainsString('unsigned', $this->columnType('work_queue', 'id'));

        // Hot-path index for claimBatch / countByStatus (status, run_date).
        self::assertSame(
            ['status', 'run_date'],
            $this->indexColumns('work_queue', 'ix_work_queue_status_run_date'),
        );

        $this->pdo->exec(
            "INSERT INTO work_queue (isin, run_date) VALUES ('SE0000000000', '2026-01-01')"
        );
        $status = $this->pdo->query(
            "SELECT status FROM work_queue WHERE isin = 'SE0000000000' AND run_date = '2026-01-01'"
        )->fetchColumn();
        self::assertSame('pending', $status, 'status defaults to pending');

        try {
            $this->pdo->exec(
                "INSERT INTO work_queue (isin, run_date) VALUES ('SE0000000000', '2026-01-01')"
            );
            self::fail('the migrated schema allowed a duplicate (isin, run_date)');
        } catch (\PDOException $e) {
            self::assertSame('23000', $e->getCode());
        }

        try {
            $this->pdo->exec(
                "INSERT INTO work_queue (isin, run_date) VALUES ('XX0000000000', '2026-01-01')"
            );
            self::fail('the migrated schema allowed a work_queue row with no matching instrument');
        } catch (\PDOException $e) {
            self::assertSame('23000', $e->getCode());
        }

        // ingest_run (Story 1.8): surrogate BIGINT UNSIGNED id, the seven typed
        // columns, unsigned counters. Append-only — no FK.
        self::assertSame(['id'], $this->primaryKey('ingest_run'));
        self::assertStringContainsString('bigint', $this->columnType('ingest_run', 'id'));
        self::assertStringContainsString('unsigned', $this->columnType('ingest_run', 'id'));
        self::assertSame('varchar(16)', $this->columnType('ingest_run', 'run_type'));
        self::assertSame('date', $this->columnType('ingest_run', 'run_date'));
        self::assertStringContainsString('datetime', $this->columnType('ingest_run', 'started_at'));
        self::assertStringContainsString('datetime', $this->columnType('ingest_run', 'finished_at'));
        foreach (['instrument_count', 'ok_count', 'fail_count'] as $col) {
            self::assertStringContainsString('int', $this->columnType('ingest_run', $col));
            self::assertStringContainsString('unsigned', $this->columnType('ingest_run', $col));
        }
        // Correlation-key index for forRunDate() / bin/show-runs.php --date.
        self::assertSame(
            ['run_date'],
            $this->indexColumns('ingest_run', 'ix_ingest_run_run_date'),
        );

        $settings = $this->pdo->query('SELECT `key`, `value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame([
            'batch_size' => '25',
            'queue.stale_after' => '900',
            'rate.avanza' => '0.5',
            'rate.nordnet' => '0.5',
            'run_after' => '18:30',
        ], $this->sortByKey($settings));
    }

    public function testRollbackDropsEverything(): void
    {
        self::assertSame(0, $this->phinx('migrate', '-e', 'testing')[0]);

        [$code, $out] = $this->phinx('rollback', '-e', 'testing', '-t', '0');
        self::assertSame(0, $code, $out);

        self::assertFalse($this->tableExists('instrument'));
        self::assertFalse($this->tableExists('settings'));
        self::assertFalse($this->tableExists('owner_count_daily'));
        self::assertFalse($this->tableExists('work_queue'));
        self::assertFalse($this->tableExists('ingest_run'));
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function phinx(string ...$args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(self::REPO_ROOT . '/vendor/bin/phinx') . ' '
            . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';

        $output = [];
        $code = 0;
        exec('cd ' . escapeshellarg(realpath(self::REPO_ROOT)) . ' && ' . $cmd, $output, $code);

        return [$code, implode("\n", $output)];
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :t'
        );
        $stmt->execute(['t' => $table]);

        return (int) $stmt->fetchColumn() === 1;
    }

    private function columnType(string $table, string $column): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT COLUMN_TYPE FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
        );
        $stmt->execute(['t' => $table, 'c' => $column]);

        return strtolower((string) $stmt->fetchColumn());
    }

    /**
     * @return list<string> the PK columns in key order
     */
    private function primaryKey(string $table): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT column_name FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :t AND index_name = 'PRIMARY'
             ORDER BY seq_in_index"
        );
        $stmt->execute(['t' => $table]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return list<string> the index's columns in key order (empty if absent)
     */
    private function indexColumns(string $table, string $indexName): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT column_name FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i
             ORDER BY seq_in_index'
        );
        $stmt->execute(['t' => $table, 'i' => $indexName]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function dropAll(): void
    {
        // owner_count_daily and work_queue first — FK to instrument.
        foreach (['ingest_run', 'owner_count_daily', 'work_queue', 'instrument', 'settings', 'phinxlog'] as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS `$table`");
        }
    }

    /**
     * @param array<string, string> $rows
     * @return array<string, string>
     */
    private function sortByKey(array $rows): array
    {
        ksort($rows);

        return $rows;
    }
}
