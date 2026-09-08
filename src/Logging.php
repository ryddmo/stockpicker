<?php

declare(strict_types=1);

namespace Stockpicker;

use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use RuntimeException;

/**
 * Builds the single application logger: Monolog writing to a file outside
 * public_html/ (AD-8, K12).
 */
final class Logging
{
    public const CHANNEL = 'stockpicker';

    /**
     * @throws RuntimeException when the log directory cannot be created.
     */
    public static function logger(Config $c): Logger
    {
        $path = $c->logPath();
        $dir = \dirname($path);

        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException(sprintf('Unable to create log directory: %s', $dir));
            }
        }

        $logger = new Logger(self::CHANNEL);
        $logger->pushHandler(new StreamHandler($path, Level::Debug));

        return $logger;
    }
}
