<?php

declare(strict_types=1);

namespace Stockpicker\Store;

use PDO;

/**
 * Read-only access to `trading_holiday` — full-day Nasdaq Stockholm closures
 * that fall on a weekday. A row's mere existence means "not a trading day",
 * same "existence is the signal" idiom as `WatchlistRepository`. Consulted by
 * the cron gate in `public_html/index.php` alongside `cron_is_allowed_weekday()`
 * so a weekday holiday (Midsommarafton, Juldagen, ...) is skipped the same way
 * a Saturday already is, instead of storing a day of flat/noisy ägarantal data.
 */
final class TradingHolidayRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function isHoliday(string $date): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM trading_holiday WHERE holiday_date = :date');
        $stmt->execute(['date' => $date]);

        return $stmt->fetchColumn() !== false;
    }
}
