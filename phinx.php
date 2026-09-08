<?php

declare(strict_types=1);

/**
 * Phinx configuration. Wired but no migrations yet — the first migration is
 * Story 1.2.
 *
 * - production: DB credentials from config.php (Stockpicker\Config). Defined
 *   only when config.php loads; otherwise Phinx fails loudly that the
 *   environment is missing, rather than running against a fabricated DB.
 * - development: the MariaDB service from docker-compose.yml
 *   (docker compose up -d), reachable on 127.0.0.1:3306. Always available.
 * - testing: the same server, a throwaway database used by the PHPUnit
 *   migration test. Overridable via STOCKPICKER_TEST_DB_* env vars.
 */

require __DIR__ . '/vendor/autoload.php';

use Stockpicker\Config;

$environments = [
    'default_migration_table' => 'phinxlog',
    'default_environment' => 'development',
    'development' => [
        'adapter' => 'mysql',
        'host' => '127.0.0.1',
        'name' => 'stockpicker',
        'user' => 'stockpicker',
        'pass' => 'stockpicker',
        'port' => 3306,
        'charset' => 'utf8mb4',
    ],
    'testing' => [
        'adapter' => 'mysql',
        'host' => getenv('STOCKPICKER_TEST_DB_HOST') ?: '127.0.0.1',
        'name' => getenv('STOCKPICKER_TEST_DB_NAME') ?: 'stockpicker_test',
        'user' => getenv('STOCKPICKER_TEST_DB_USER') ?: 'root',
        'pass' => getenv('STOCKPICKER_TEST_DB_PASS') ?: 'root',
        'port' => 3306,
        'charset' => 'utf8mb4',
    ],
];

try {
    $db = Config::load(__DIR__)->db();
    $environments['production'] = [
        'adapter' => 'mysql',
        'host' => $db['host'],
        'name' => $db['name'],
        'user' => $db['user'],
        'pass' => $db['pass'],
        'port' => 3306,
        'charset' => $db['charset'],
    ];
} catch (\Throwable $e) {
    // Only complain when 'production' is actually the requested environment;
    // `phinx status -e development` on a fresh checkout is a supported path.
    if (in_array('production', $_SERVER['argv'] ?? [], true)) {
        fwrite(STDERR, "phinx: production environment unavailable: {$e->getMessage()}\n");
    }
}

return [
    'paths' => [
        'migrations' => __DIR__ . '/db/migrations',
        'seeds' => __DIR__ . '/db/seeds',
    ],
    'migration_paths' => [
        __DIR__ . '/db/migrations',
    ],
    'environments' => $environments,
    'version_order' => 'creation',
];
