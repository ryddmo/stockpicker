<?php

declare(strict_types=1);

namespace Stockpicker\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stockpicker\Config;
use Stockpicker\Logging;

final class SmokeTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/stockpicker-smoke-' . bin2hex(random_bytes(6));
        mkdir($this->fixtureRoot, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->fixtureRoot);
    }

    public function testAutoloadResolvesConfigClass(): void
    {
        self::assertTrue(class_exists(Config::class));
        self::assertTrue(class_exists(Logging::class));
    }

    public function testConfigLoadReturnsExpectedValues(): void
    {
        file_put_contents($this->fixtureRoot . '/config.php', <<<'PHP'
            <?php
            return [
                'db' => [
                    'host' => 'db.example.test',
                    'name' => 'sp_test',
                    'user' => 'sp_user',
                    'pass' => 'sp_pass',
                    'charset' => 'utf8mb4',
                ],
                'cron_token' => 'secret-token',
                'log_path' => 'var/log/stockpicker.log',
            ];
            PHP);

        $config = Config::load($this->fixtureRoot);

        self::assertSame([
            'host' => 'db.example.test',
            'name' => 'sp_test',
            'user' => 'sp_user',
            'pass' => 'sp_pass',
            'charset' => 'utf8mb4',
        ], $config->db());
        self::assertSame('secret-token', $config->cronToken());
        self::assertSame($this->fixtureRoot . '/var/log/stockpicker.log', $config->logPath());
    }

    public function testConfigDbDefaultsCharsetToUtf8mb4WhenOmitted(): void
    {
        $config = Config::fromArray([
            'db' => ['host' => 'h', 'name' => 'n', 'user' => 'u', 'pass' => 'p'],
            'cron_token' => 't',
        ], $this->fixtureRoot);

        self::assertSame('utf8mb4', $config->db()['charset']);
    }

    public function testConfigDbThrowsWhenSectionAbsent(): void
    {
        $config = Config::fromArray(['cron_token' => 't'], $this->fixtureRoot);

        $this->expectException(RuntimeException::class);
        $config->db();
    }

    public function testConfigDbThrowsWhenUserIsNonString(): void
    {
        $config = Config::fromArray([
            'db' => ['host' => 'h', 'name' => 'n', 'user' => 123, 'pass' => 'p'],
        ], $this->fixtureRoot);

        $this->expectException(RuntimeException::class);
        $config->db();
    }

    public function testConfigCronTokenThrowsOnEmptyString(): void
    {
        $config = Config::fromArray(['cron_token' => ''], $this->fixtureRoot);

        $this->expectException(RuntimeException::class);
        $config->cronToken();
    }

    public function testConfigLoadThrowsWhenMissingAndNamesThePath(): void
    {
        $expectedPath = $this->fixtureRoot . '/config.php';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expectedPath);

        Config::load($this->fixtureRoot);
    }

    public function testLoggerWritesALineToTheConfiguredPath(): void
    {
        $logPath = $this->fixtureRoot . '/var/log/stockpicker.log';
        $config = Config::fromArray([
            'db' => ['host' => 'h', 'name' => 'n', 'user' => 'u', 'pass' => 'p', 'charset' => 'utf8mb4'],
            'cron_token' => 't',
            'log_path' => $logPath,
        ], $this->fixtureRoot);

        $logger = Logging::logger($config);
        $logger->info('smoke test line');

        self::assertFileExists($logPath);
        self::assertStringContainsString('smoke test line', (string) file_get_contents($logPath));
        self::assertStringContainsString('stockpicker', (string) file_get_contents($logPath));
    }

    public function testLastDitchWritesToErrorLog(): void
    {
        $errorLog = $this->fixtureRoot . '/php-error.log';
        $previous = ini_set('error_log', $errorLog);

        try {
            Logging::lastDitch('bootstrap failed: boom');
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        self::assertFileExists($errorLog);
        self::assertStringContainsString('stockpicker bootstrap failed: boom', (string) file_get_contents($errorLog));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
