<?php

declare(strict_types=1);

namespace Stockpicker\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the pure cron-endpoint helpers split out of public_html/index.php.
 * Mirrors FetchRunnerTest's coverage of its sibling positive-or-default helper.
 */
final class CronHelpersTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/public_html/cron_helpers.php';
    }

    /**
     * @return iterable<string, array{0: ?string, 1: float}>
     */
    public static function timeboxCases(): iterable
    {
        yield 'unseeded key -> default' => [null, 45.0];
        yield 'a positive integer string' => ['30', 30.0];
        yield 'a positive decimal string' => ['12.5', 12.5];
        yield 'at the 120 s cap' => ['120', 120.0];
        yield 'above the cap is clamped to 120' => ['999', 120.0];
        yield 'zero -> default' => ['0', 45.0];
        yield 'negative -> default' => ['-1', 45.0];
        yield 'non-numeric -> default' => ['soon', 45.0];
        yield 'empty string -> default' => ['', 45.0];
    }

    #[DataProvider('timeboxCases')]
    public function testUniverseResolveTimebox(?string $raw, float $expected): void
    {
        self::assertSame($expected, universe_resolve_timebox($raw));
    }

    /**
     * @return iterable<string, array{0: ?string, 1: string, 2: bool}>
     */
    public static function allowedWeekdayCases(): iterable
    {
        $monday = '2026-09-14';
        $saturday = '2026-09-19';
        $sunday = '2026-09-20';

        yield 'unseeded key, Monday -> allowed (default Mon-Fri)' => [null, $monday, true];
        yield 'unseeded key, Saturday -> skipped (default Mon-Fri)' => [null, $saturday, false];
        yield 'unseeded key, Sunday -> skipped (default Mon-Fri)' => [null, $sunday, false];
        yield 'empty string -> default, Saturday skipped' => ['', $saturday, false];
        yield 'non-numeric garbage -> default, Saturday skipped' => ['garbage', $saturday, false];
        yield 'explicit weekdays only, Saturday -> skipped' => ['1,2,3,4,5', $saturday, false];
        yield 'every day allowed, Saturday -> allowed' => ['1,2,3,4,5,6,7', $saturday, true];
        yield 'every day allowed, Sunday -> allowed' => ['1,2,3,4,5,6,7', $sunday, true];
        yield 'only Saturday allowed, Saturday -> allowed' => ['6', $saturday, true];
        yield 'only Saturday allowed, Monday -> skipped' => ['6', $monday, false];
        yield 'whitespace around values is trimmed' => [' 1, 2 , 3 ', $monday, true];
    }

    #[DataProvider('allowedWeekdayCases')]
    public function testCronIsAllowedWeekday(?string $raw, string $date, bool $expected): void
    {
        $now = new \DateTimeImmutable($date, new \DateTimeZone('Europe/Stockholm'));
        self::assertSame($expected, cron_is_allowed_weekday($raw, $now));
    }
}
