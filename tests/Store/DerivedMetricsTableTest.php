<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Store;

use PHPUnit\Framework\TestCase;
use Stockpicker\Adapter\NormalizedRow;
use Stockpicker\Store\DerivedMetricsTable;
use Stockpicker\Store\Instrument;

/**
 * Pure rendering — no database. One case per rendering-relevant I/O matrix
 * row from spec-3-3: both sources have rows, one source has no rows, and the
 * null-handling / decimal-formatting rules that apply within a row.
 */
final class DerivedMetricsTableTest extends TestCase
{
    private function instrument(): Instrument
    {
        return new Instrument('SE0015811963', 'Investor B', 'LC', 'avanza-1', 'nordnet-1', '2026-01-01', null);
    }

    public function testBothSourcesRenderIdentityHeaderAndPerRowMetrics(): void
    {
        $bySource = [
            NormalizedRow::SOURCE_AVANZA => [
                ['as_of_date' => '2026-09-09', 'number_of_owners' => 1000, 'delta_1d' => null, 'pct_1d' => null, 'sma_7' => null, 'sma_30' => null, 'sma_90' => null, 'up_streak' => null, 'spike_score' => null],
                ['as_of_date' => '2026-09-10', 'number_of_owners' => 1010, 'delta_1d' => 10, 'pct_1d' => 0.01, 'sma_7' => null, 'sma_30' => null, 'sma_90' => null, 'up_streak' => 0, 'spike_score' => null],
            ],
            NormalizedRow::SOURCE_NORDNET => [
                ['as_of_date' => '2026-09-10', 'number_of_owners' => 500, 'delta_1d' => null, 'pct_1d' => null, 'sma_7' => null, 'sma_30' => null, 'sma_90' => null, 'up_streak' => null, 'spike_score' => null],
            ],
        ];

        $out = DerivedMetricsTable::render($this->instrument(), $bySource);

        self::assertStringStartsWith("SE0015811963  Investor B\n", $out);
        self::assertStringContainsString('== avanza ==', $out);
        self::assertStringContainsString('== nordnet ==', $out);
        self::assertStringContainsString('as_of_date', $out);
        self::assertStringContainsString('spike_score', $out);
        self::assertStringContainsString('2026-09-09', $out);
        self::assertStringContainsString('2026-09-10', $out);
        self::assertStringNotContainsString('no data', $out);
        self::assertStringEndsWith("\n", $out);

        // avanza block comes before nordnet block (fixed order, never merged).
        self::assertLessThan(strpos($out, '== nordnet =='), strpos($out, '== avanza =='));
    }

    public function testSourceWithNoRowsPrintsItsOwnNoDataBlock(): void
    {
        $bySource = [
            NormalizedRow::SOURCE_AVANZA => [
                ['as_of_date' => '2026-09-10', 'number_of_owners' => 1010, 'delta_1d' => 10, 'pct_1d' => 0.01, 'sma_7' => null, 'sma_30' => null, 'sma_90' => null, 'up_streak' => 0, 'spike_score' => null],
            ],
        ];

        $out = DerivedMetricsTable::render($this->instrument(), $bySource);

        self::assertStringContainsString('== avanza ==', $out);
        self::assertStringContainsString('== nordnet ==', $out);
        self::assertStringContainsString('2026-09-10', $out);

        $nordnetBlock = substr($out, (int) strpos($out, '== nordnet =='));
        self::assertStringContainsString("no data\n", $nordnetBlock);
    }

    public function testValidIsinWithNoRowsAtAllPrintsNoDataForBothBlocks(): void
    {
        $out = DerivedMetricsTable::render($this->instrument(), []);

        self::assertStringContainsString('== avanza ==', $out);
        self::assertStringContainsString('== nordnet ==', $out);
        self::assertSame(2, substr_count($out, "no data\n"));
    }

    public function testNullMetricsRenderAsDash(): void
    {
        $bySource = [
            NormalizedRow::SOURCE_AVANZA => [
                ['as_of_date' => '2026-09-09', 'number_of_owners' => 1000, 'delta_1d' => null, 'pct_1d' => null, 'sma_7' => null, 'sma_30' => null, 'sma_90' => null, 'up_streak' => null, 'spike_score' => null],
            ],
        ];

        $out = DerivedMetricsTable::render($this->instrument(), $bySource);
        $lines = explode("\n", rtrim($out, "\n"));
        $dataLine = current(array_filter($lines, static fn (string $l): bool => str_contains($l, '2026-09-09')));
        self::assertNotFalse($dataLine);

        self::assertStringContainsString('2026-09-09', $dataLine);
        self::assertStringContainsString('1000', $dataLine);
        // Seven nullable metric columns (delta_1d, pct_1d, sma_7, sma_30,
        // sma_90, up_streak, spike_score), all rendered as a lone "-".
        self::assertSame(7, preg_match_all('/(?<=\s)-(?=\s|$)/', $dataLine));
    }

    public function testDecimalMetricsFormatToFourAndTwoDecimalPlaces(): void
    {
        $bySource = [
            NormalizedRow::SOURCE_AVANZA => [
                [
                    'as_of_date' => '2026-09-10',
                    'number_of_owners' => 1010,
                    'delta_1d' => -5,
                    'pct_1d' => 0.1,
                    'sma_7' => 123.4,
                    'sma_30' => 200,
                    'sma_90' => 199.999,
                    'up_streak' => 0,
                    'spike_score' => -1.23456,
                ],
            ],
        ];

        $out = DerivedMetricsTable::render($this->instrument(), $bySource);

        self::assertStringContainsString('0.1000', $out);
        self::assertStringContainsString('123.40', $out);
        self::assertStringContainsString('200.00', $out); // sma_30 (int 200) and sma_90 (rounds from 199.999)
        self::assertStringContainsString('-1.2346', $out); // rounded to 4 places
        self::assertStringContainsString('-5', $out);
    }
}
