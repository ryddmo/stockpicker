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
    private const LOGIN_PASSWORD = 'correct-horse-battery-staple';
    private const SESSION_KEY = 'test-session-key';

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
        copy(self::REPO_ROOT . '/public_html/cron_helpers.php', $this->root . '/public_html/cron_helpers.php');
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
        [$status, $body] = $this->get('/health');

        self::assertSame(200, $status);

        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $json['status']);
        self::assertSame('stockpicker', $json['app']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $json['time']);
    }

    public function testRootWithoutCookieShowsLoginFormWithNoMessage(): void
    {
        [$status, $body] = $this->get('/');

        self::assertSame(200, $status);
        self::assertStringContainsString('<form', $body);
        self::assertStringNotContainsString('Fel användarnamn eller lösenord.', $body);
        self::assertStringNotContainsString('Sessionen har gått ut', $body);
    }

    public function testRootWithWrongMethodReturns405(): void
    {
        [$status, $body] = $this->post('/');

        self::assertSame(405, $status);
        self::assertSame(['error' => 'method not allowed'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testLoginWithWrongMethodReturns405(): void
    {
        [$status, $body] = $this->request('PUT', '/login');

        self::assertSame(405, $status);
        self::assertSame(['error' => 'method not allowed'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testCorrectLoginSetsSignedCookieAndRedirectsToRoot(): void
    {
        [$status, , $headers] = $this->postForm('/login', [
            'username' => 'stefan',
            'password' => self::LOGIN_PASSWORD,
        ]);

        self::assertSame(302, $status);
        self::assertSame('/', $this->locationHeader($headers));

        $cookie = $this->extractCookieValue($headers, 'stockpicker_session');
        self::assertNotNull($cookie);
        self::assertMatchesRegularExpression('#^[A-Za-z0-9+/=]+\.[0-9a-f]{64}$#', $cookie);
    }

    public function testWrongPasswordShowsInlineError(): void
    {
        [$status, $body] = $this->postForm('/login', [
            'username' => 'stefan',
            'password' => 'wrong-password',
        ]);

        self::assertSame(200, $status);
        self::assertStringContainsString('Fel användarnamn eller lösenord.', $body);
    }

    public function testExpiredCookieShowsExpiredMessageOnRoot(): void
    {
        $cookie = 'stockpicker_session=' . $this->signedCookieValue(time() - 3600);

        [$status, $body] = $this->get('/', $cookie);

        self::assertSame(200, $status);
        self::assertStringContainsString('Sessionen har gått ut. Logga in igen.', $body);
    }

    public function testTamperedCookieShowsLoginFormWithNoMessage(): void
    {
        $cookie = 'stockpicker_session=' . base64_encode('{"exp":9999999999}') . '.' . str_repeat('0', 64);

        [$status, $body] = $this->get('/', $cookie);

        self::assertSame(200, $status);
        self::assertStringContainsString('<form', $body);
        self::assertStringNotContainsString('Fel användarnamn eller lösenord.', $body);
        self::assertStringNotContainsString('Sessionen har gått ut', $body);
    }

    public function testAlreadyLoggedInRedirectsAwayFromLoginForm(): void
    {
        $cookie = 'stockpicker_session=' . $this->signedCookieValue(time() + 3600);

        [$status, , $headers] = $this->get('/login', $cookie);

        self::assertSame(302, $status);
        self::assertSame('/', $this->locationHeader($headers));
    }

    public function testExpiredCookieOnLoginShowsPlainFormWithNoMessage(): void
    {
        $cookie = 'stockpicker_session=' . $this->signedCookieValue(time() - 3600);

        [$status, $body] = $this->get('/login', $cookie);

        self::assertSame(200, $status);
        self::assertStringContainsString('<form', $body);
        self::assertStringNotContainsString('Fel användarnamn eller lösenord.', $body);
        self::assertStringNotContainsString('Sessionen har gått ut', $body);
    }

    // NB: "a valid session on / renders the real Topplista, not a login
    // form" now needs a real database (Story 4.2's LeaderboardController) and
    // so lives in FrontControllerIntegrationTest.php instead, which self-skips
    // cleanly without docker-compose's MariaDB — this file stays entirely
    // DB-free, matching its own doc comment above.

    public function testWatchlistToggleWithWrongMethodReturns405(): void
    {
        [$status, $body] = $this->get('/watchlist/toggle');

        self::assertSame(405, $status);
        self::assertSame(['error' => 'method not allowed'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testStockDetailWithWrongMethodReturns405(): void
    {
        // The method check in route_stock_detail() runs before require_session()
        // or any database connection, so this never needs a seeded instrument —
        // same DB-free precedent as every other 405 test in this file.
        [$status, $body] = $this->post('/stock/SE0000001001');

        self::assertSame(405, $status);
        self::assertSame(['error' => 'method not allowed'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testWatchlistToggleWithoutCookieReturns401MinimalTextBodyNotLoginHtml(): void
    {
        [$status, $body] = $this->post('/watchlist/toggle');

        self::assertSame(401, $status);
        self::assertStringNotContainsString('<form', $body);
        self::assertStringNotContainsString('<html', $body);
    }

    public function testWatchlistToggleWithExpiredCookieReturns401MinimalTextBodyNotLoginHtml(): void
    {
        $cookie = 'stockpicker_session=' . $this->signedCookieValue(time() - 3600);

        [$status, $body] = $this->post('/watchlist/toggle', $cookie);

        self::assertSame(401, $status);
        self::assertStringNotContainsString('<form', $body);
        self::assertStringNotContainsString('Sessionen har gått ut', $body);
    }

    public function testWatchlistToggleWithTamperedCookieReturns401MinimalTextBodyNotLoginHtml(): void
    {
        $cookie = 'stockpicker_session=' . base64_encode('{"exp":9999999999}') . '.' . str_repeat('0', 64);

        [$status, $body] = $this->post('/watchlist/toggle', $cookie);

        self::assertSame(401, $status);
        self::assertStringNotContainsString('<form', $body);
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

    public function testDeriveWithoutTokenReturns403BeforeOpeningDatabase(): void
    {
        [$status, $body] = $this->get('/cron/derive');

        self::assertSame(403, $status);
        self::assertSame(['error' => 'forbidden'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testDeriveWithWrongTokenReturns403BeforeOpeningDatabase(): void
    {
        [$status, $body] = $this->get('/cron/derive?token=wrong-token');

        self::assertSame(403, $status);
        self::assertSame(['error' => 'forbidden'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testDeriveWithUnexpectedQueryParameterReturns400BeforeOpeningDatabase(): void
    {
        [$status, $body] = $this->get('/cron/derive?token=test-token&unexpected=value');

        self::assertSame(400, $status);
        self::assertSame(['error' => 'invalid request'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testDeriveWithNonGetMethodReturns405(): void
    {
        [$status, $body] = $this->post('/cron/derive?token=test-token');

        self::assertSame(405, $status);
        self::assertSame(['error' => 'method not allowed'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
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
     * @return array{db: array<string,string>, cron_token: string, log_path: string, login_username: string, login_password_hash: string, session_key: string}
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
            'login_username' => 'stefan',
            'login_password_hash' => password_hash(self::LOGIN_PASSWORD, PASSWORD_BCRYPT),
            'session_key' => self::SESSION_KEY,
        ];
    }

    /**
     * Builds a validly signed session cookie value for a given expiry,
     * bypassing the /login flow — mirrors AuthController::issueCookieValue()'s
     * format so tests can construct expired/near-future cookies directly.
     */
    private function signedCookieValue(int $exp): string
    {
        $payload = json_encode(['exp' => $exp], JSON_THROW_ON_ERROR);

        return base64_encode($payload) . '.' . hash_hmac('sha256', $payload, self::SESSION_KEY);
    }

    private function extractCookieValue(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (stripos($header, 'Set-Cookie:') !== 0) {
                continue;
            }

            $value = trim(substr($header, strlen('Set-Cookie:')));
            $attribute = explode(';', $value, 2)[0];
            [$cookieName, $cookieValue] = array_pad(explode('=', $attribute, 2), 2, null);

            if ($cookieName === $name) {
                return $cookieValue;
            }
        }

        return null;
    }

    private function locationHeader(array $headers): ?string
    {
        foreach ($headers as $header) {
            if (stripos($header, 'Location:') === 0) {
                return trim(substr($header, strlen('Location:')));
            }
        }

        return null;
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
     * @return array{0: int, 1: string, 2: array<int, string>}
     */
    private function post(string $path, ?string $cookie = null): array
    {
        return $this->request('POST', $path, $cookie);
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array{0: int, 1: string, 2: array<int, string>}
     */
    private function postForm(string $path, array $fields, ?string $cookie = null): array
    {
        return $this->request('POST', $path, $cookie, http_build_query($fields));
    }

    /**
     * @return array{0: int, 1: string, 2: array<int, string>}
     */
    private function get(string $path, ?string $cookie = null): array
    {
        return $this->request('GET', $path, $cookie);
    }

    /**
     * @return array{0: int, 1: string, 2: array<int, string>}
     */
    private function request(string $method, string $path, ?string $cookie = null, ?string $body = null): array
    {
        $options = ['ignore_errors' => true, 'timeout' => 5, 'follow_location' => 0];
        if ($method !== 'GET') {
            $options['method'] = $method;
        }

        $headerLines = [];
        if ($cookie !== null) {
            $headerLines[] = 'Cookie: ' . $cookie;
        }
        if ($body !== null) {
            $headerLines[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        if ($headerLines !== []) {
            $options['header'] = implode("\r\n", $headerLines);
        }
        if ($body !== null) {
            $options['content'] = $body;
        }

        $ctx = stream_context_create(['http' => $options]);
        $responseBody = file_get_contents($this->base . $path, false, $ctx);
        $status = 0;
        $responseHeaders = $http_response_header ?? [];
        foreach ($responseHeaders as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }

        return [$status, (string) $responseBody, $responseHeaders];
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
