<?php

declare(strict_types=1);

/**
 * Thin front controller. A hand-rolled path switch — no framework, no business
 * logic. Cron endpoints and the read view arrive in later stories.
 */

use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Stockpicker\Config;

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
    } catch (\Throwable) {
        // A failing log write (unwritable path, full disk) must not stop the
        // 500 response below.
    }
    send_json(500, ['error' => 'internal server error']);
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

    error_log('stockpicker ' . $message);
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
