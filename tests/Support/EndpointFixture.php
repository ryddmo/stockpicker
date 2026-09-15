<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Support;

final class EndpointFixture
{
    /** A guaranteed-unresolvable host (RFC 2606) — the hermetic default. */
    private const DEAD_UNIVERSE_BASE_URI = 'http://universe.stockpicker.invalid';

    /**
     * Fixed test credentials for the session/login config keys Story 4.2's
     * authenticated routes need (AuthController). Callers that only exercise
     * the token-guarded /cron/* endpoints never touch these.
     */
    private const SESSION_KEY = 'endpoint-fixture-session-key';
    private const LOGIN_USERNAME = 'endpoint-fixture-user';

    private string $root;

    /** @var resource|null */
    private $server;

    private string $base;

    /** @var resource|null */
    private $universeServer;

    private ?string $universeBase = null;

    private ?string $digestSpyFile = null;

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
     * spec-5-6 — opt-in, call BEFORE `start()`: makes `/cron/derive`'s
     * TopTenDigest step write every (to, subject, message) it would have
     * sent as one JSON line to a temp file instead of touching real SMTP
     * (via the front controller's STOCKPICKER_DIGEST_SPY_FILE test seam),
     * so a test can observe the digest crossing the real subprocess
     * boundary. Returns the file's path (`digestSpyContents()` reads it back).
     */
    public function enableDigestSpy(): string
    {
        $this->digestSpyFile = sys_get_temp_dir() . '/stockpicker-digest-spy-' . bin2hex(random_bytes(6)) . '.ndjson';
        file_put_contents($this->digestSpyFile, '');

        return $this->digestSpyFile;
    }

    /** The digest spy file's contents (one JSON line per attempted send), or '' if never enabled/never sent. */
    public function digestSpyContents(): string
    {
        return $this->digestSpyFile !== null ? (string) file_get_contents($this->digestSpyFile) : '';
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

        $configData = [
            'db' => $db,
            'cron_token' => $cronToken,
            'log_path' => 'var/log/stockpicker.log',
            'login_username' => self::LOGIN_USERNAME,
            'login_password_hash' => password_hash('endpoint-fixture-password', PASSWORD_BCRYPT),
            'session_key' => self::SESSION_KEY,
        ];
        if ($this->digestSpyFile !== null) {
            // The spy replaces TopTenDigest's mail sender entirely (real
            // PHPMailer/SMTP is never touched), but TopTenDigest::run() still
            // reads Config::digest()['recipient'] before invoking it -- so a
            // test that enabled the spy needs a "digest" section present.
            // Deliberately absent otherwise, so the default fixture still
            // exercises the "digest config missing" failure path as-is.
            $configData['digest'] = [
                'username' => 'digest-fixture@example.com',
                'password' => 'unused-with-spy',
                'recipient' => 'stockpicker@ryddmo.se',
            ];
        }
        file_put_contents($this->root . '/config.php', "<?php\nreturn " . var_export($configData, true) . ";\n");

        [$this->server, $this->base] = $this->spawnServer($this->root . '/public_html', null, array_filter([
            'STOCKPICKER_AVANZA_UNIVERSE_BASE_URI' => $this->universeBase ?? self::DEAD_UNIVERSE_BASE_URI,
            'STOCKPICKER_DIGEST_SPY_FILE' => $this->digestSpyFile,
        ], static fn (?string $v): bool => $v !== null));
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
    public function get(string $path, ?string $cookie = null): array
    {
        return $this->request('GET', $path, $cookie);
    }

    /** @return array{0: int, 1: string} */
    public function postJson(string $path, array $payload, ?string $cookie = null): array
    {
        return $this->request('POST', $path, $cookie, json_encode($payload, JSON_THROW_ON_ERROR), 'application/json');
    }

    /**
     * A validly signed session cookie value for the given expiry, matching
     * AuthController::issueCookieValue()'s format — bypasses /login so a
     * test can construct valid/expired/near-future cookies directly.
     */
    public function signedSessionCookie(int $exp): string
    {
        $payload = json_encode(['exp' => $exp], JSON_THROW_ON_ERROR);

        return base64_encode($payload) . '.' . hash_hmac('sha256', $payload, self::SESSION_KEY);
    }

    /** @return array{0: int, 1: string} */
    private function request(
        string $method,
        string $path,
        ?string $cookie = null,
        ?string $body = null,
        ?string $contentType = null,
    ): array {
        $options = ['ignore_errors' => true, 'timeout' => 15, 'method' => $method];

        $headerLines = [];
        if ($cookie !== null) {
            $headerLines[] = 'Cookie: ' . $cookie;
        }
        if ($body !== null) {
            $headerLines[] = 'Content-Type: ' . ($contentType ?? 'application/x-www-form-urlencoded');
        }
        if ($headerLines !== []) {
            $options['header'] = implode("\r\n", $headerLines);
        }
        if ($body !== null) {
            $options['content'] = $body;
        }

        $context = stream_context_create(['http' => $options]);
        $responseBody = file_get_contents($this->base . $path, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\\S+\\s+(\\d{3})#', $header, $matches)) {
                $status = (int) $matches[1];
            }
        }

        return [$status, (string) $responseBody];
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

        if ($this->digestSpyFile !== null && is_file($this->digestSpyFile)) {
            unlink($this->digestSpyFile);
        }
        $this->digestSpyFile = null;
    }

    public function logContents(): string
    {
        $path = $this->root . '/var/log/stockpicker.log';

        // The log file is created lazily by Monolog's StreamHandler on its
        // first write -- a request that never logs anything (e.g. a clean
        // digest skip) leaves it absent entirely. Absent is just "no log
        // output yet", not an error worth a PHP warning.
        return is_file($path) ? (string) file_get_contents($path) : '';
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
