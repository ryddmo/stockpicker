<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Support;

final class EndpointFixture
{
    /** A guaranteed-unresolvable host (RFC 2606) — the hermetic default. */
    private const DEAD_UNIVERSE_BASE_URI = 'http://universe.stockpicker.invalid';

    private string $root;

    /** @var resource|null */
    private $server;

    private string $base;

    /** @var resource|null */
    private $universeServer;

    private ?string $universeBase = null;

    /**
     * Start an opt-in canned Avanza universe source (a second `php -S`) BEFORE
     * `start()`, so the front controller's `AvanzaUniverseAdapter` points at it
     * via `STOCKPICKER_AVANZA_UNIVERSE_BASE_URI`. Without this the env var is
     * pinned to an unresolvable host, keeping the default hermetic.
     *
     * @param 'happy'|'schema_mismatch' $scenario
     */
    public function startUniverseSource(string $scenario = 'happy'): void
    {
        $dir = sys_get_temp_dir() . '/stockpicker-universe-src-' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/scenario', $scenario);
        file_put_contents($dir . '/router.php', self::universeRouterSource());

        [$this->universeServer, $this->universeBase] = $this->spawnServer($dir, $dir . '/router.php', []);
    }

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
        copy($repoRoot . '/public_html/cron_helpers.php', $this->root . '/public_html/cron_helpers.php');
        copy($repoRoot . '/public_html/.htaccess', $this->root . '/public_html/.htaccess');

        file_put_contents($this->root . '/config.php', "<?php\nreturn " . var_export([
            'db' => $db,
            'cron_token' => $cronToken,
            'log_path' => 'var/log/stockpicker.log',
        ], true) . ";\n");

        [$this->server, $this->base] = $this->spawnServer($this->root . '/public_html', null, [
            'STOCKPICKER_AVANZA_UNIVERSE_BASE_URI' => $this->universeBase ?? self::DEAD_UNIVERSE_BASE_URI,
        ]);
    }

    /**
     * @param array<string, string> $extraEnv
     *
     * @return array{0: resource, 1: string} the process handle and its base URL
     */
    private function spawnServer(string $docroot, ?string $router, array $extraEnv): array
    {
        $env = $extraEnv === [] ? null : array_merge(getenv(), $extraEnv);

        $command = [PHP_BINARY, '-S', '', '-t', $docroot];
        if ($router !== null) {
            $command[] = $router;
        }

        $lastError = '';
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $port = random_int(20000, 60000);
            $host = '127.0.0.1:' . $port;
            $command[2] = $host;

            $process = proc_open(
                $command,
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $router !== null ? $docroot : dirname($docroot),
                $env,
            );

            if (!is_resource($process)) {
                $lastError = 'proc_open failed';
                continue;
            }

            for ($i = 0; $i < 50; ++$i) {
                usleep(100_000);
                if (!proc_get_status($process)['running']) {
                    break;
                }
                $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($connection) {
                    fclose($connection);

                    return [$process, 'http://' . $host];
                }
            }

            proc_terminate($process);
            proc_close($process);
            $lastError = 'server did not start on port ' . $port;
        }

        throw new \RuntimeException('could not start endpoint server: ' . $lastError);
    }

    /** @return array{0: int, 1: string} */
    public function get(string $path): array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 15]]);
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
        foreach ([$this->server, $this->universeServer] as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        $this->server = null;
        $this->universeServer = null;

        foreach ([$this->root ?? null] as $dir) {
            if ($dir !== null && is_dir($dir)) {
                $this->removeDir($dir);
            }
        }
    }

    public function logContents(): string
    {
        return (string) file_get_contents($this->root . '/var/log/stockpicker.log');
    }

    private function removeDir(string $dir): void
    {
        foreach (['vendor', 'src'] as $link) {
            if (is_link($dir . '/' . $link)) {
                unlink($dir . '/' . $link);
            }
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    /**
     * A tiny router for `php -S`: it answers the two endpoints
     * `AvanzaUniverseAdapter` calls with canned JSON. The `schema_mismatch`
     * scenario returns a screener body with no `stocks` key so `listUniverse()`
     * raises `SchemaMismatch`.
     */
    private static function universeRouterSource(): string
    {
        return <<<'PHP'
            <?php

            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            $scenario = trim((string) file_get_contents(__DIR__ . '/scenario'));
            header('Content-Type: application/json');

            if ($path === '/_api/market-stock-filter/stocks') {
                if ($scenario === 'schema_mismatch') {
                    echo '{"rows":[]}';
                    return true;
                }

                $raw = json_decode((string) file_get_contents('php://input'), true);
                $marketPlace = $raw['filter']['marketPlaces'][0] ?? '';
                $byList = [
                    'se.xsto.large cap stockholm' => [['orderbookId' => '1001', 'type' => 'STOCK', 'name' => 'Alpha AB']],
                    'se.xsto.mid cap stockholm'   => [['orderbookId' => '1002', 'type' => 'STOCK', 'name' => 'Beta AB']],
                    'se.xsto.small cap stockholm' => [['orderbookId' => '1003', 'type' => 'STOCK', 'name' => 'Gamma AB']],
                    'se.fnse'                     => [['orderbookId' => '1004', 'type' => 'STOCK', 'name' => 'Delta AB']],
                ];
                $stocks = $byList[$marketPlace] ?? [];
                echo json_encode(['stocks' => $stocks, 'totalNumberOfOrderbooks' => count($stocks)]);
                return true;
            }

            if (preg_match('#^/_api/market-guide/stock/(.+)$#', (string) $path, $m)) {
                $isins = ['1001' => 'SE0000001001', '1002' => 'SE0000001002', '1003' => 'SE0000001003', '1004' => 'SE0000001004'];
                if (!isset($isins[$m[1]])) {
                    http_response_code(404);
                    echo '{}';
                    return true;
                }
                echo json_encode(['isin' => $isins[$m[1]], 'name' => 'Stock ' . $m[1]]);
                return true;
            }

            http_response_code(404);
            echo '{}';
            return true;
            PHP;
    }
}
