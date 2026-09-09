<?php

declare(strict_types=1);

namespace Stockpicker\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Exercises public_html/index.php through the PHP built-in server, against an
 * isolated temp project root — the real config.php and var/log/ are never
 * touched.
 */
final class FrontControllerTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/..';

    private string $root;

    /** @var resource|null */
    private $server;

    private string $base;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/stockpicker-fc-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/public_html', 0775, true);

        // Shared, unchanged code: symlink in.
        symlink(realpath(self::REPO_ROOT . '/vendor'), $this->root . '/vendor');
        symlink(realpath(self::REPO_ROOT . '/src'), $this->root . '/src');

        // Entry points: copy so __DIR__ resolves inside the temp root.
        copy(self::REPO_ROOT . '/bootstrap.php', $this->root . '/bootstrap.php');
        copy(self::REPO_ROOT . '/public_html/index.php', $this->root . '/public_html/index.php');
        copy(self::REPO_ROOT . '/public_html/.htaccess', $this->root . '/public_html/.htaccess');

        file_put_contents($this->root . '/config.php.dist', "<?php\nreturn " . var_export($this->configArray(), true) . ";\n");
        file_put_contents($this->root . '/config.php', "<?php\nreturn " . var_export($this->configArray(), true) . ";\n");

        $this->startServer();
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        $this->removeDir($this->root);
    }

    public function testHealthcheckReturns200Json(): void
    {
        [$status, $body] = $this->get('/');

        self::assertSame(200, $status);

        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $json['status']);
        self::assertSame('stockpicker', $json['app']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $json['time']);
    }

    public function testUnknownRouteReturns404Json(): void
    {
        [$status, $body] = $this->get('/nope');

        self::assertSame(404, $status);
        self::assertSame(['error' => 'not found'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testCronWithoutTokenReturns403BeforeOpeningDatabase(): void
    {
        [$status, $body] = $this->get('/cron/work');

        self::assertSame(403, $status);
        self::assertSame(['error' => 'forbidden'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testCronWithWrongTokenReturns403BeforeOpeningDatabase(): void
    {
        [$status, $body] = $this->get('/cron/refill?token=wrong-token');

        self::assertSame(403, $status);
        self::assertSame(['error' => 'forbidden'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testCronWithUnexpectedQueryParameterReturns400BeforeOpeningDatabase(): void
    {
        [$status, $body] = $this->get('/cron/work?token=test-token&unexpected=value');

        self::assertSame(400, $status);
        self::assertSame(['error' => 'invalid request'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testMissingConfigReturns500AndLogsError(): void
    {
        // Remove the config only from this temp root.
        unlink($this->root . '/config.php');

        [$status, $body] = $this->get('/');

        self::assertSame(500, $status);
        self::assertSame(['error' => 'internal server error'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));

        $logPath = $this->root . '/var/log/stockpicker.log';
        self::assertFileExists($logPath);
        $log = (string) file_get_contents($logPath);
        self::assertStringContainsString('.ERROR', $log);
        self::assertStringContainsString('bootstrap failed', $log);
    }

    /**
     * @return array{db: array<string,string>, cron_token: string, log_path: string}
     */
    private function configArray(): array
    {
        return [
            'db' => [
                'host' => '127.0.0.1',
                'name' => 'stockpicker',
                'user' => 'stockpicker',
                'pass' => 'stockpicker',
                'charset' => 'utf8mb4',
            ],
            'cron_token' => 'test-token',
            'log_path' => 'var/log/stockpicker.log',
        ];
    }

    private function startServer(): void
    {
        $lastErr = '';
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $port = random_int(20000, 60000);
            $host = '127.0.0.1:' . $port;
            $proc = proc_open(
                [PHP_BINARY, '-S', $host, '-t', $this->root . '/public_html'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $this->root
            );

            if (!is_resource($proc)) {
                $lastErr = 'proc_open failed';
                continue;
            }

            $this->server = $proc;
            $this->base = 'http://' . $host;

            for ($i = 0; $i < 50; $i++) {
                usleep(100_000);
                $status = proc_get_status($proc);
                if (!$status['running']) {
                    break;
                }
                $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($conn) {
                    fclose($conn);
                    return;
                }
            }

            $this->stopServer();
            $lastErr = 'server did not come up on port ' . $port;
        }

        self::fail('could not start PHP built-in server: ' . $lastErr);
    }

    private function stopServer(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $this->server = null;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function get(string $path): array
    {
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
        $body = file_get_contents($this->base . $path, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }

        return [$status, (string) $body];
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        // Unlink symlinks directly without descending into them.
        foreach (['vendor', 'src'] as $link) {
            if (is_link($dir . '/' . $link)) {
                unlink($dir . '/' . $link);
            }
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
