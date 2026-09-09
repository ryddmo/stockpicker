<?php

declare(strict_types=1);

namespace Stockpicker\Store;

/**
 * One row of the `ingest_run` table — the minimal trace a single pipeline run
 * leaves behind (AD-11). Append-only: a row is written once and never revised,
 * so a crashed slice simply has no row.
 *
 * `runDate` is a Stockholm `Y-m-d` string; `startedAt` / `finishedAt` are naive
 * UTC `Y-m-d H:i:s` strings — the same convention as `QueueJob::claimedAt`.
 */
final readonly class IngestRun
{
    public function __construct(
        public int $id,
        public string $runType,
        public string $runDate,
        public string $startedAt,
        public string $finishedAt,
        public int $instrumentCount,
        public int $okCount,
        public int $failCount,
    ) {
    }

    /**
     * @param array<string, mixed> $row a row from `SELECT * FROM ingest_run`
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['run_type'],
            (string) $row['run_date'],
            (string) $row['started_at'],
            (string) $row['finished_at'],
            (int) $row['instrument_count'],
            (int) $row['ok_count'],
            (int) $row['fail_count'],
        );
    }
}
