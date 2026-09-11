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
    /**
     * The full text block: a header line, a `-` separator, then either the
     * single `"no runs\n"` line (empty input) or one formatted line per row.
     * Always ends with a trailing newline.
     *
     * @param list<IngestRun> $rows
     */
    public static function render(array $rows): string
    {
        $sourceDetails = array_map(
            static fn (IngestRun $row): string => self::sourceDetails($row),
            $rows,
        );
        $sourceWidth = max(array_merge([9], array_map('strlen', $sourceDetails)));
        $format = "%-6s  %-8s  %-10s  %-19s  %-19s  %-9s  %-5s  %6s  %6s  %6s  %-{$sourceWidth}s\n";
        $header = sprintf(
            $format,
            'id',
            'type',
            'run_date',
            'started_at (UTC)',
            'finished_at (UTC)',
            'status',
            'alarm',
            'instr',
            'ok',
            'fail',
            'by_source',
        );

        $out = $header;
        $out .= str_repeat('-', strlen(rtrim($header, "\n"))) . "\n";

        if ($rows === []) {
            return $out . "no runs\n";
        }

        foreach ($rows as $index => $r) {
            $out .= sprintf(
            $format,
                (string) $r->id,
                $r->runType,
                $r->runDate,
                $r->startedAt,
                $r->finishedAt,
                $r->status,
                $r->alarm ? 'YES' : 'no',
                (string) $r->instrumentCount,
                (string) $r->okCount,
                (string) $r->failCount,
                $sourceDetails[$index],
            );
        }

        return $out;
    }

    private static function sourceDetails(IngestRun $row): string
    {
        $parts = [];
        foreach ($row->bySource as $source => $counts) {
            $parts[] = $source . '=' . implode(',', array_map(
                static fn (string $key, int $value): string => $key . ':' . $value,
                array_keys($counts),
                $counts,
            ));
        }

        return $parts === [] ? '-' : implode(';', $parts);
    }
}
