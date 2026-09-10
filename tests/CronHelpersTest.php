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
}
