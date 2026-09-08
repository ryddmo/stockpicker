<?php

declare(strict_types=1);

namespace Stockpicker\Store;

use PDO;
use Stockpicker\Config;

/**
 * The single place a PDO connection to MariaDB is built, from the credentials
 * in config.php (Stockpicker\Config). Every repository takes the PDO this
 * returns; nothing else opens a connection.
 */
final class Database
{
    public static function connect(Config $c): PDO
    {
        $db = $c->db();

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $db['host'],
            $db['name'],
            $db['charset'],
        );

        return new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
