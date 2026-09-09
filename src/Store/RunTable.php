<?php

declare(strict_types=1);

namespace Stockpicker\Store;

/**
 * Renders a list of `IngestRun` rows as the aligned plain-text block
 * `bin/show-runs.php` prints over SSH. Pure and DB-free (like
 * `SourceIdResolver` is HTTP-free) so the rendering is unit-testable without a
 * database: the bin script keeps only arg parsing and the repository call.
 */
final class RunTable
{
    private const FORMAT = "%-6s  %-8s  %-10s  %-19s  %-19s  %6s  %6s  %6s\n";

    /**
     * The full text block: a header line, a `-` separator, then either the
     * single `"no runs\n"` line (empty input) or one formatted line per row.
     * Always ends with a trailing newline.
     *
     * @param list<IngestRun> $rows
     */
    public static function render(array $rows): string
    {
        $header = sprintf(
            self::FORMAT,
            'id',
            'type',
            'run_date',
            'started_at (UTC)',
            'finished_at (UTC)',
            'instr',
            'ok',
            'fail',
        );

        $out = $header;
        $out .= str_repeat('-', strlen(rtrim($header, "\n"))) . "\n";

        if ($rows === []) {
            return $out . "no runs\n";
        }

        foreach ($rows as $r) {
            $out .= sprintf(
                self::FORMAT,
                (string) $r->id,
                $r->runType,
                $r->runDate,
                $r->startedAt,
                $r->finishedAt,
                (string) $r->instrumentCount,
                (string) $r->okCount,
                (string) $r->failCount,
            );
        }

        return $out;
    }
}
