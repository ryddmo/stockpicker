<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Store;

use PDOException;
use PHPUnit\Framework\TestCase;
use Stockpicker\Config;
use Stockpicker\Store\Database;

final class DatabaseTest extends TestCase
{
    public function testConnectSurfacesThePdoExceptionWhenTheServerIsUnreachable(): void
    {
        $config = Config::fromArray([
            'db' => [
                // Local host, credentials that will never authenticate. Fails
                // fast whether the dev server is up (auth error) or down
                // (connection refused) — either way a PDOException.
                'host' => getenv('STOCKPICKER_TEST_DB_HOST') ?: '127.0.0.1',
                'name' => 'stockpicker_nope',
                'user' => 'stockpicker_nobody',
                'pass' => 'definitely-wrong',
                'charset' => 'utf8mb4',
            ],
            'cron_token' => 'test',
        ]);

        $this->expectException(PDOException::class);

        Database::connect($config);
    }
}
