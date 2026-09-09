<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Support;

final class EndpointFixture
{
    private string $root;

    /** @var resource|null */
    private $server;

    private string $base;

    /**
     * @param array{host: string, name: string, user: string, pass: string, charset: string} $db
     */
    public function start(array $db, string $cronToken = 'test-token'): void
    {
        $this->root = sys_get_temp_dir() . '/stockpicker-endpoint-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/public_html', 0775, true);

        $repoRoot = dirname(__DIR__, 2);
        symlink(realpath($repoRoot . '/vendor'), $this->root . '/vendor');
        symlink(realpath($repoRoot . '/src'), $this->root . '/src');
        copy($repoRoot . '/bootstrap.php', $this->root . '/bootstrap.php');
        copy($repoRoot . '/public_html/index.php', $this->root . '/public_html/index.php');
        copy($repoRoot . '/public_html/.htaccess', $this->root . '/public_html/.htaccess');

        file_put_contents($this->root . '/config.php', "<?php\nreturn " . var_export([
            'db' => $db,
            'cron_token' => $cronToken,
            'log_path' => 'var/log/stockpicker.log',
        ], true) . ";\n");

        $lastError = '';
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $port = random_int(20000, 60000);
            $host = '127.0.0.1:' . $port;
            $process = proc_open(
                [PHP_BINARY, '-S', $host, '-t', $this->root . '/public_html'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $this->root,
            );

            if (!is_resource($process)) {
                $lastError = 'proc_open failed';
                continue;
            }

            $this->server = $process;
            $this->base = 'http://' . $host;
            for ($i = 0; $i < 50; ++$i) {
                usleep(100_000);
                if (!proc_get_status($process)['running']) {
                    break;
                }
                $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($connection) {
                    fclose($connection);
                    return;
                }
            }

            $this->stop();
            $lastError = 'server did not start on port ' . $port;
        }

        throw new \RuntimeException('could not start endpoint server: ' . $lastError);
    }

    /** @return array{0: int, 1: string} */
    public function get(string $path): array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
        $body = file_get_contents($this->base . $path, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\\S+\\s+(\\d{3})#', $header, $matches)) {
                $status = (int) $matches[1];
            }
        }

        return [$status, (string) $body];
    }

    public function stop(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $this->server = null;

        if (!isset($this->root) || !is_dir($this->root)) {
            return;
        }

        foreach (['vendor', 'src'] as $link) {
            if (is_link($this->root . '/' . $link)) {
                unlink($this->root . '/' . $link);
            }
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function logContents(): string
    {
        return (string) file_get_contents($this->root . '/var/log/stockpicker.log');
    }
}