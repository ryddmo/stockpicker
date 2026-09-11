<?php

declare(strict_types=1);

namespace Stockpicker\Store;

use PDO;

/**
 * Read-only access to the `owner_count_metrics` view (Story 3.1) — the sole
 * reader of it from PHP, same "no SQL outside src/Store/" rule as every other
 * repository. The view itself computes everything; this class only shapes
 * the read.
 */
final class DerivedMetricsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * One (isin, source) series, ordered by as_of_date. Empty array when the
     * series does not exist (unknown isin, or no rows yet for that source).
     *
     * @return list<array<string, mixed>>
     */
    public function forIsinAndSource(string $isin, string $source): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM owner_count_metrics WHERE isin = :isin AND source = :source ORDER BY as_of_date'
        );
        $stmt->execute(['isin' => $isin, 'source' => $source]);

        return $stmt->fetchAll();
    }

    /**
     * Every source's series for one isin, keyed by source — mirrors
     * InstrumentRepository's keyed-array return style. A source with no
     * stored rows is simply absent from the result.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function forIsin(string $isin): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM owner_count_metrics WHERE isin = :isin ORDER BY source, as_of_date'
        );
        $stmt->execute(['isin' => $isin]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['source']][] = $row;
        }

        return $out;
    }
}
