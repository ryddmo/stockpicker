<?php

declare(strict_types=1);

/**
 * Thin front controller. A hand-rolled path switch — no framework or SQL.
 */

use GuzzleHttp\Client;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Stockpicker\Adapter\AvanzaAdapter;
use Stockpicker\Adapter\AvanzaUniverseAdapter;
use Stockpicker\Adapter\NordnetAdapter;
use Stockpicker\Config;
use Stockpicker\Error\AdapterError;
use Stockpicker\Logging;
use Stockpicker\Pipeline\Enqueue;
use Stockpicker\Pipeline\FetchRunner;
use Stockpicker\Pipeline\UniverseSync;
use Stockpicker\Store\Database;
use Stockpicker\Store\DerivedMetricsRepository;
use Stockpicker\Store\InstrumentRepository;
use Stockpicker\Store\OwnerCountRepository;
use Stockpicker\Store\QueueRepository;
use Stockpicker\Store\RunRepository;
use Stockpicker\Store\SettingsRepository;
use Stockpicker\Store\WatchlistRepository;
use Stockpicker\Web\AuthController;
use Stockpicker\Web\LeaderboardController;
use Stockpicker\Web\SessionStatus;
use Stockpicker\Web\StockDetailController;

require_once __DIR__ . '/cron_helpers.php';

/** @var array{config: Config, logger: Logger} $services */
try {
    $services = require __DIR__ . '/../bootstrap.php';
    $logger = $services['logger'];
} catch (\Throwable $e) {
    // Bootstrap failed (most likely a missing/invalid config.php). Config-derived
    // paths are unavailable, so log at 'error' to the default log location and
    // fall back to error_log if even that fails.
    log_bootstrap_failure($e);
    send_json(500, ['error' => 'internal server error']);
    return;
}

try {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = is_string($path) ? rtrim($path, '/') : '';
    if ($path === '') {
        $path = '/';
    }

    if (str_starts_with($path, '/stock/')) {
        route_stock_detail($services, substr($path, 7));
        return;
    }

    switch ($path) {
        case '/health':
            send_json(200, [
                'status' => 'ok',
                'app' => 'stockpicker',
                'time' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            break;

        case '/':
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
                send_json(405, ['error' => 'method not allowed']);
                break;
            }

            if (!require_session($services['config'])) {
                break;
            }

            $pdo = Database::connect($services['config']);
            $controller = new LeaderboardController(
                new DerivedMetricsRepository($pdo),
                new WatchlistRepository($pdo),
            );

            $source = $_GET['source'] ?? '';
            $ranking = $_GET['ranking'] ?? '';
            $source = is_string($source) ? $source : '';
            $ranking = is_string($ranking) ? $ranking : '';

            render_html(200, $controller->render($source, $ranking));
            break;

        case '/watchlist/toggle':
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                send_json(405, ['error' => 'method not allowed']);
                break;
            }

            // Same session validation as require_session(), but a 401 +
            // minimal text body instead of the login page's HTML (AD-12) —
            // watchlist.js tells a toggle failure from a dead session by
            // status code alone, never by sniffing the response body.
            $auth = new AuthController($services['config']);
            $cookie = $_COOKIE[AuthController::COOKIE_NAME] ?? null;
            $cookie = is_string($cookie) ? $cookie : null;

            if ($auth->sessionStatus($cookie) !== SessionStatus::Valid) {
                render_text(401, "unauthorized\n");
                break;
            }

            $payload = json_decode((string) file_get_contents('php://input'), true);
            $isin = is_array($payload) && isset($payload['isin']) && is_string($payload['isin'])
                ? $payload['isin']
                : null;

            if ($isin === null || $isin === '') {
                send_json(400, ['error' => 'invalid request']);
                break;
            }

            $pdo = Database::connect($services['config']);
            $instruments = new InstrumentRepository($pdo);

            if ($instruments->get($isin) === null) {
                send_json(404, ['error' => 'not found']);
                break;
            }

            $starred = (new WatchlistRepository($pdo))->toggle($isin);
            send_json(200, ['isin' => $isin, 'starred' => $starred]);
            break;

        case '/login':
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            if ($method !== 'GET' && $method !== 'POST') {
                send_json(405, ['error' => 'method not allowed']);
                break;
            }

            $auth = new AuthController($services['config']);
            $cookie = $_COOKIE[AuthController::COOKIE_NAME] ?? null;
            $cookie = is_string($cookie) ? $cookie : null;

            if ($method === 'GET') {
                if ($auth->sessionStatus($cookie) === SessionStatus::Valid) {
                    http_response_code(302);
                    header('Location: /');
                    break;
                }

                render_html(200, $auth->renderLoginPage());
                break;
            }

            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            $username = is_string($username) ? $username : '';
            $password = is_string($password) ? $password : '';

            if (!$auth->login($username, $password)) {
                render_html(200, $auth->renderLoginPage('Fel användarnamn eller lösenord.'));
                break;
            }

            $isHttps = !empty($_SERVER['HTTPS'])
                || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null) === 'https';
            setcookie(AuthController::COOKIE_NAME, $auth->issueCookieValue(), [
                'expires' => time() + AuthController::SESSION_TTL_SECONDS,
                'path' => '/',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            http_response_code(302);
            header('Location: /');
            break;

        case '/cron/refill':
        case '/cron/work':
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
                send_json(405, ['error' => 'method not allowed']);
                break;
            }

            authorize_cron($services['config']);

            if (count($_GET) !== 1 || !array_key_exists('token', $_GET)) {
                send_json(400, ['error' => 'invalid request']);
                break;
            }

            $pdo = Database::connect($services['config']);
            $settings = new SettingsRepository($pdo);
            $runAfter = $settings->get('run_after');
            if ($runAfter === null) {
                throw new \RuntimeException('missing required setting: run_after');
            }

            $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Stockholm'));
            $runAfterTime = cron_time($runAfter, $now);
            if ($now < $runAfterTime) {
                send_json(200, [
                    'status' => 'window_closed',
                    'run_date' => $now->format('Y-m-d'),
                ]);
                break;
            }

            $runDate = $now->format('Y-m-d');
            $instruments = new InstrumentRepository($pdo);
            $queue = new QueueRepository($pdo);
            $runs = new RunRepository($pdo);

            if ($path === '/cron/refill') {
                $http = new Client(['timeout' => 20, 'connect_timeout' => 10]);
                $sync = new UniverseSync(
                    new AvanzaUniverseAdapter($http, $logger),
                    new NordnetAdapter($http, $logger),
                    $instruments,
                    $settings,
                    $runs,
                    $logger,
                );

                try {
                    $sync->run($runDate, universe_resolve_timebox($settings->get('universe.resolve_timebox')));
                } catch (AdapterError $e) {
                    // UniverseSync already logged the cause at warning/error; just
                    // note the endpoint outcome and skip Enqueue.
                    $logger->debug('cron/refill: universe sync failed, skipping enqueue', ['error' => $e::class]);
                    send_json(200, [
                        'status' => 'universe_sync_failed',
                        'run_date' => $runDate,
                    ]);
                    break;
                }

                $created = (new Enqueue($queue, $instruments, $runs))->run($runDate);
                send_json(200, [
                    'status' => 'ok',
                    'run_date' => $runDate,
                    'created' => $created,
                ]);
                break;
            }

            $http = new Client(['timeout' => 20, 'connect_timeout' => 10]);
            $runner = new FetchRunner(
                $queue,
                $instruments,
                new OwnerCountRepository($pdo),
                [
                    'avanza' => new AvanzaAdapter($http, $logger),
                    'nordnet' => new NordnetAdapter($http, $logger),
                ],
                $settings,
                $logger,
                $runs,
            );
            $result = $runner->run($runDate, 75.0);
            send_json(200, [
                'status' => 'ok',
                'run_date' => $runDate,
                'claimed' => $result->claimed,
                'done' => $result->done,
                'failed' => $result->failed,
                'reopened' => $result->reopened,
                'stale_failed' => $result->staleFailed,
                'rows_written' => $result->rowsWritten,
                'by_source' => $result->bySource,
            ]);
            break;

        case '/cron/derive':
            // View-based design (Story 3.1): owner_count_metrics is a plain SQL view, so
            // there is nothing to materialize here. This deliberately never touches
            // Deriver/DerivedMetricsRepository — it only logs that the stage ran.
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
                send_json(405, ['error' => 'method not allowed']);
                break;
            }

            authorize_cron($services['config']);

            if (count($_GET) !== 1 || !array_key_exists('token', $_GET)) {
                send_json(400, ['error' => 'invalid request']);
                break;
            }

            $pdo = Database::connect($services['config']);
            $settings = new SettingsRepository($pdo);
            $runAfter = $settings->get('run_after');
            if ($runAfter === null) {
                throw new \RuntimeException('missing required setting: run_after');
            }

            $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Stockholm'));
            $runAfterTime = cron_time($runAfter, $now);
            if ($now < $runAfterTime) {
                send_json(200, [
                    'status' => 'window_closed',
                    'run_date' => $now->format('Y-m-d'),
                ]);
                break;
            }

            $runDate = $now->format('Y-m-d');
            $count = count((new InstrumentRepository($pdo))->allActive());
            (new RunRepository($pdo))->record('derive', $runDate, $now, $now, $count, $count, 0);

            send_json(200, [
                'status' => 'ok',
                'run_date' => $runDate,
                'instrument_count' => $count,
            ]);
            break;

        default:
            send_json(404, ['error' => 'not found']);
            break;
    }
} catch (\Throwable $e) {
    try {
        $logger->error('unhandled exception in front controller', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    } catch (\Throwable $logError) {
        // A failing log write (unwritable path, full disk) must not stop the
        // 500 response below — but it must not vanish either.
        Logging::lastDitch(sprintf(
            'log write failed while handling: %s (%s); original: %s (%s)',
            $logError->getMessage(),
            $logError::class,
            $e->getMessage(),
            $e::class,
        ));
    }
    send_json(500, ['error' => 'internal server error']);
}

function authorize_cron(Config $config): void
{
    $token = $_GET['token'] ?? null;
    if (!is_string($token) || $token === '' || !hash_equals($config->cronToken(), $token)) {
        send_json(403, ['error' => 'forbidden']);
        exit;
    }
}

/**
 * Gate for any route that needs a logged-in session. Unlike authorize_cron(),
 * this never calls exit — it renders the login form itself (with the right
 * inline message) and returns false, so the caller can just `break` out of
 * its switch case. Returns true when the session is valid, letting the
 * caller proceed with its own response. Reusable as-is by Stories 4.2+.
 */
function require_session(Config $config): bool
{
    $auth = new AuthController($config);
    $cookie = $_COOKIE[AuthController::COOKIE_NAME] ?? null;
    $cookie = is_string($cookie) ? $cookie : null;
    $status = $auth->sessionStatus($cookie);

    if ($status === SessionStatus::Valid) {
        return true;
    }

    $message = $status === SessionStatus::Expired
        ? 'Sessionen har gått ut. Logga in igen.'
        : null;

    render_html(200, $auth->renderLoginPage($message));

    return false;
}

/**
 * Story 4.3 — `/stock/{isin}` dispatch. `$isin` is the raw remainder after
 * the `/stock/` prefix (public_html/index.php's route match), unvalidated
 * until the InstrumentRepository::get() lookup below — the exact
 * unknown-isin-to-404 precedent as `/watchlist/toggle`.
 *
 * @param array{config: Config, logger: Logger} $services
 */
function route_stock_detail(array $services, string $isin): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        send_json(405, ['error' => 'method not allowed']);
        return;
    }

    if (!require_session($services['config'])) {
        return;
    }

    $pdo = Database::connect($services['config']);
    $instrument = (new InstrumentRepository($pdo))->get($isin);
    if ($instrument === null) {
        send_json(404, ['error' => 'not found']);
        return;
    }

    $controller = new StockDetailController(
        new DerivedMetricsRepository($pdo),
        new WatchlistRepository($pdo),
    );

    $source = $_GET['source'] ?? '';
    $range = $_GET['range'] ?? '';
    $source = is_string($source) ? $source : '';
    $range = is_string($range) ? $range : '';

    render_html(200, $controller->render($instrument, $source, $range));
}

function render_html(int $status, string $body): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo $body;
}

/**
 * Minimal plain-text response — used by /watchlist/toggle's 401 so an
 * expired/invalid session never renders the login page's HTML there
 * (AD-12): watchlist.js only needs the status code to decide to navigate to
 * /login, never the body.
 */
function render_text(int $status, string $body): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $body;
}

function cron_time(string $runAfter, \DateTimeImmutable $now): \DateTimeImmutable
{
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $runAfter, $matches)) {
        throw new \RuntimeException('invalid setting: run_after');
    }

    return $now->setTime((int) $matches[1], (int) $matches[2]);
}

/**
 * Best-effort 'error' log when bootstrap itself failed (no config, so no
 * configured logger). Writes to the default log path outside public_html/,
 * then falls back to error_log().
 */
function log_bootstrap_failure(\Throwable $e): void
{
    $message = sprintf('bootstrap failed: %s (%s)', $e->getMessage(), $e::class);

    try {
        $path = \dirname(__DIR__) . '/' . Config::DEFAULT_LOG_PATH;
        $dir = \dirname($path);
        if (is_dir($dir) || @mkdir($dir, 0775, true) || is_dir($dir)) {
            $logger = new Logger('stockpicker');
            $logger->pushHandler(new StreamHandler($path, Level::Error));
            $logger->error($message);
            return;
        }
    } catch (\Throwable) {
        // fall through
    }

    Logging::lastDitch($message);
}

/**
 * @param array<string, mixed> $body
 */
function send_json(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
}
