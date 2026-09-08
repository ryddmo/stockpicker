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

    public function testMigrateCreatesBothTablesAndSeedsSettings(): void
    {
        [$code, $out] = $this->phinx('migrate', '-e', 'testing');
        self::assertSame(0, $code, $out);

        self::assertTrue($this->tableExists('instrument'));
        self::assertTrue($this->tableExists('settings'));

        $settings = $this->pdo->query('SELECT `key`, `value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame([
            'batch_size' => '25',
            'queue.stale_after' => '900',
            'rate.avanza' => '0.5',
            'rate.nordnet' => '0.5',
            'run_after' => '18:30',
        ], $this->sortByKey($settings));
    }

    public function testRollbackDropsBothTables(): void
    {
        self::assertSame(0, $this->phinx('migrate', '-e', 'testing')[0]);

        [$code, $out] = $this->phinx('rollback', '-e', 'testing', '-t', '0');
        self::assertSame(0, $code, $out);

        self::assertFalse($this->tableExists('instrument'));
        self::assertFalse($this->tableExists('settings'));
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

    private function dropAll(): void
    {
        foreach (['instrument', 'settings', 'phinxlog'] as $table) {
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
