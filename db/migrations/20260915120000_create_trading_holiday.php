<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The `trading_holiday` table: full-day Nasdaq Stockholm closures that fall on
 * a weekday `cron_is_allowed_weekday()` would otherwise treat as a normal run
 * day. Story context: `run_weekdays` (dfe705e) already skips Saturdays/Sundays,
 * but a weekday public holiday (e.g. Midsommarafton, Juldagen) still passed
 * that gate, so the pipeline kept fetching and storing a day's worth of
 * ägarantal noise on days the exchange never opened — exactly the "flat
 * weekend" contamination the 2026-09-15 "Stadig tillväxt" backlog note
 * describes, just for holidays instead of weekends. `TradingHolidayRepository`
 * is the sole reader; `public_html/index.php`'s cron gate checks it alongside
 * `cron_is_allowed_weekday()` before enqueueing/fetching/deriving.
 *
 * A row's mere existence marks that date as a non-trading day — same
 * "existence is the signal" idiom as `watchlist`. `holiday_date` is the
 * primary key so the seed/insert is naturally idempotent per date.
 *
 * Seed dates are Nasdaq Stockholm's known 2026 full-day closures
 * (cross-referenced against public market-holiday calendars, 2026-09-15) —
 * deliberately excludes half-days (early-close sessions, e.g. the eve of
 * several of these): a half-day is still a trading day (Stefan, 2026-09-15),
 * so it must never end up in this table. 6 January (Trettondedag jul) is
 * left out of the seed for the same reason in reverse: sources disagreed on
 * whether Nasdaq Stockholm fully closes or just half-days it, and getting
 * that wrong by excluding a real trading day is worse than leaving one
 * ordinary/uncertain day ungated — add it once confirmed against Nasdaq's
 * own notice. This table is meant to be maintained by hand, once a year, the
 * same way `settings` is — there is no fixed formula for movable holidays,
 * so add next year's dates the same way before this list runs out.
 */
final class CreateTradingHoliday extends AbstractMigration
{
    public function up(): void
    {
        $this->table('trading_holiday', ['id' => false, 'primary_key' => ['holiday_date']])
            ->addColumn('holiday_date', 'date', ['null' => false])
            ->addColumn('description', 'string', ['limit' => 120, 'null' => false])
            ->create();

        $this->table('trading_holiday')->insert([
            ['holiday_date' => '2026-01-01', 'description' => 'Nyårsdagen'],
            // 2026-01-06 (Trettondedag jul) deliberately omitted — see class
            // docblock. Confirm full-closure status and add before January.
            ['holiday_date' => '2026-04-03', 'description' => 'Långfredagen'],
            ['holiday_date' => '2026-04-06', 'description' => 'Annandag påsk'],
            ['holiday_date' => '2026-05-01', 'description' => 'Första maj'],
            ['holiday_date' => '2026-05-14', 'description' => 'Kristi himmelsfärds dag'],
            ['holiday_date' => '2026-06-19', 'description' => 'Midsommarafton'],
            ['holiday_date' => '2026-12-25', 'description' => 'Juldagen'],
            ['holiday_date' => '2026-12-26', 'description' => 'Annandag jul'],
        ])->saveData();
    }

    public function down(): void
    {
        $this->table('trading_holiday')->drop()->save();
    }
}
