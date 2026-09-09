<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Store;

use PHPUnit\Framework\TestCase;
use Stockpicker\Store\IngestRun;
use Stockpicker\Store\RunTable;

/**
 * Pure rendering — no database. Covers the I/O matrix row
 * "`show-runs` no DB rows | fresh DB | prints a header and 'no runs', exit 0".
 */
final class RunTableTest extends TestCase
{
    public function testEmptyListRendersTheHeaderAndNoRuns(): void
    {
        $out = RunTable::render([]);

        self::assertStringContainsString('run_date', $out);
        self::assertStringContainsString('started_at (UTC)', $out);
        self::assertStringContainsString('fail', $out);
        self::assertStringContainsString("\n---", $out, 'separator line');
        self::assertStringContainsString("no runs\n", $out);
        self::assertStringEndsWith("\n", $out);
    }

    public function testRowsRenderOneLineEachWithTheValuesAndAlignment(): void
    {
        $rows = [
            new IngestRun(3, 'fetch', '2026-09-10', '2026-09-10 18:31:00', '2026-09-10 18:32:00', 20, 20, 0),
            new IngestRun(2, 'enqueue', '2026-09-09', '2026-09-09 18:30:00', '2026-09-09 18:30:01', 18, 17, 1),
        ];

        $out = RunTable::render($rows);
        $lines = explode("\n", rtrim($out, "\n"));

        // header + separator + one line per row.
        self::assertCount(4, $lines);
        self::assertStringNotContainsString('no runs', $out);

        self::assertStringContainsString('fetch', $lines[2]);
        self::assertStringContainsString('2026-09-10', $lines[2]);
        self::assertStringContainsString('enqueue', $lines[3]);

        // Column alignment: every rendered line is the same width, and the
        // id / type / count columns line up under the header.
        $width = strlen($lines[0]);
        foreach ($lines as $line) {
            self::assertSame($width, strlen($line), "line width: {$line}");
        }
        self::assertSame(strpos($lines[0], 'type'), strpos($lines[2], 'fetch'));
    }
}
