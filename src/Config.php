<?php

declare(strict_types=1);

namespace Stockpicker;

use RuntimeException;

/**
 * Typed wrapper around the plain-array config.php that lives at the project
 * root, above the web root, and is never committed (AD-8).
 */
final class Config
{
    /** Log file path used when config.php does not set log_path. Relative to the project root. */
    public const DEFAULT_LOG_PATH = 'var/log/stockpicker.log';

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        private readonly array $data,
        private readonly string $root,
    ) {
    }

    /**
     * Load config.php from the project root.
     *
     * @param string|null $root Project root; defaults to the repository root
     *                          (one level above src/).
     *
     * @throws RuntimeException when config.php is missing or does not return an array.
     */
    public static function load(?string $root = null): self
    {
        $root = rtrim($root ?? \dirname(__DIR__), '/');
        $path = $root . '/config.php';

        if (!is_file($path)) {
            throw new RuntimeException(
                sprintf('config.php not found at expected path: %s (copy config.php.dist)', $path)
            );
        }

        $data = require $path;

        if (!\is_array($data)) {
            throw new RuntimeException(
                sprintf('config.php at %s must return an array, got %s', $path, get_debug_type($data))
            );
        }

        return new self($data, $root);
    }

    /**
     * Build a Config directly from an array. Useful for tests.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, ?string $root = null): self
    {
        return new self($data, rtrim($root ?? \dirname(__DIR__), '/'));
    }

    /**
     * Database connection parameters.
     *
     * @return array{host: string, name: string, user: string, pass: string, charset: string}
     */
    public function db(): array
    {
        $db = $this->data['db'] ?? null;

        if (!\is_array($db)) {
            throw new RuntimeException('config.php is missing the "db" section');
        }

        foreach (['host', 'name', 'user', 'pass'] as $key) {
            if (!isset($db[$key]) || !\is_string($db[$key])) {
                throw new RuntimeException(sprintf('config.php "db" section is missing the "%s" key', $key));
            }
        }

        return [
            'host' => $db['host'],
            'name' => $db['name'],
            'user' => $db['user'],
            'pass' => $db['pass'],
            'charset' => \is_string($db['charset'] ?? null) ? $db['charset'] : 'utf8mb4',
        ];
    }

    /**
     * Shared secret for the /cron/* endpoints.
     */
    public function cronToken(): string
    {
        $token = $this->data['cron_token'] ?? null;

        if (!\is_string($token) || $token === '') {
            throw new RuntimeException('config.php is missing a non-empty "cron_token"');
        }

        return $token;
    }

    /**
     * Absolute path to the log file. A relative log_path is resolved against
     * the project root; the default is var/log/stockpicker.log.
     */
    public function logPath(): string
    {
        $path = $this->data['log_path'] ?? self::DEFAULT_LOG_PATH;

        if (!\is_string($path) || $path === '') {
            $path = self::DEFAULT_LOG_PATH;
        }

        if (!$this->isAbsolute($path)) {
            $path = $this->root . '/' . ltrim($path, '/');
        }

        return $path;
    }

    /**
     * Project root this config was loaded from.
     */
    public function root(): string
    {
        return $this->root;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path);
    }
}
