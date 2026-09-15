<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Store;

use Stockpicker\Store\TradingHolidayRepository;

/**
 * The cron gate's holiday check (see public_html/index.php) — a row's mere
 * existence for a date means "not a trading day".
 */
final class TradingHolidayRepositoryTest extends StoreTestCase
{
    private TradingHolidayRepository $holidays;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo->exec(
            "INSERT INTO trading_holiday (holiday_date, description) VALUES ('2026-12-25', 'Juldagen')"
        );

        $this->holidays = new TradingHolidayRepository($this->pdo);
    }

    public function testIsHolidayTrueForAStoredDate(): void
    {
        self::assertTrue($this->holidays->isHoliday('2026-12-25'));
    }

    public function testIsHolidayFalseForAnOrdinaryWeekday(): void
    {
        self::assertFalse($this->holidays->isHoliday('2026-09-15'));
    }
}
