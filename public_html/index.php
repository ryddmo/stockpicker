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
use Stockpicker\Store\InstrumentRepository;
use Stockpicker\Store\OwnerCountRepository;
use Stockpicker\Store\QueueRepository;
use Stockpicker\Store\RunRepository;
use Stockpicker\Store\SettingsRepository;

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

    switch ($path) {
        case '/':
            send_json(200, [
                'status' => 'ok',
                'app' => 'stockpicker',
                'time' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
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
                'rows_written' => $result->rowsWritten,
                'by_source' => $result->bySource,
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
