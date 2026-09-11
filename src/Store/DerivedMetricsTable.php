<?php

declare(strict_types=1);

namespace Stockpicker\Store;

use Stockpicker\Adapter\NormalizedRow;

/**
 * Renders one instrument's full owner-count series and derived metrics
 * (Story 3.3) as the aligned plain-text block `bin/show-metrics.php` prints
 * over SSH. Pure and DB-free (like `RunTable`), so the rendering is
 * unit-testable without a database: the bin script keeps only arg parsing and
 * the repository/pipeline calls.
 */
final class DerivedMetricsTable
{
    /** Fixed block order — sources are never merged (epic-3-context.md). */
    private const SOURCES = [NormalizedRow::SOURCE_AVANZA, NormalizedRow::SOURCE_NORDNET];

    private const FORMAT = "%-10s | %10s | %9s | %13s | %10s | %10s | %10s | %9s | %11s\n";

    /**
     * The full text block: an identity line (isin + name), then one labeled
     * block per source in fixed order (avanza, then nordnet). A source absent
     * from `$bySource` (or present with an empty list) still gets its own
     * block, printed as `"no data"` instead of a table. Always ends with a
     * trailing newline.
     *
     * @param array<string, list<array<string, mixed>>> $bySource keyed by source
     */
    public static function render(Instrument $instrument, array $bySource): string
    {
        $out = sprintf("%s  %s\n", $instrument->isin, $instrument->name);

        foreach (self::SOURCES as $source) {
            $rows = $bySource[$source] ?? [];
            $out .= "\n== {$source} ==\n";
            $out .= $rows === [] ? "no data\n" : self::renderRows($rows);
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function renderRows(array $rows): string
    {
        $header = sprintf(
            self::FORMAT,
            'as_of_date',
            'owners',
            'delta_1d',
            'pct_1d(ratio)',
            'sma_7',
            'sma_30',
            'sma_90',
            'up_streak',
            'spike_score',
        );

        $out = $header;
        $out .= str_repeat('-', strlen(rtrim($header, "\n"))) . "\n";

        foreach ($rows as $row) {
            $out .= sprintf(
                self::FORMAT,
                (string) $row['as_of_date'],
                (string) $row['number_of_owners'],
                self::intOrDash($row['delta_1d']),
                self::decimalOrDash($row['pct_1d'], 4),
                self::decimalOrDash($row['sma_7'], 2),
                self::decimalOrDash($row['sma_30'], 2),
                self::decimalOrDash($row['sma_90'], 2),
                self::intOrDash($row['up_streak']),
                self::decimalOrDash($row['spike_score'], 4),
            );
        }

        return $out;
    }

    private static function intOrDash(mixed $value): string
    {
        return $value === null ? '-' : (string) (int) $value;
    }

    private static function decimalOrDash(mixed $value, int $decimals): string
    {
        return $value === null ? '-' : sprintf('%.' . $decimals . 'f', (float) $value);
    }
}
