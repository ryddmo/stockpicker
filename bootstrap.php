<?php

declare(strict_types=1);

/**
 * Project bootstrap. Sets up autoloading, a UTC default timezone, config, and
 * the logger. Returns the shared services array.
 *
 * @return array{config: \Stockpicker\Config, logger: \Monolog\Logger}
 */

require __DIR__ . '/vendor/autoload.php';

date_default_timezone_set('UTC');

$config = \Stockpicker\Config::load(__DIR__);
$logger = \Stockpicker\Logging::logger($config);

return [
    'config' => $config,
    'logger' => $logger,
];
