<?php

declare(strict_types=1);

namespace Stockpicker\Store;

/**
 * One row of the `work_queue` table — the unit `FetchRunner` claims and works.
 * ISIN is the natural key; `runDate` is a Stockholm `Y-m-d` string;
 * `claimedAt` is a naive UTC `Y-m-d H:i:s` string (null while `pending`).
 */
final readonly class QueueJob
{
    public function __construct(
        public int $id,
        public string $isin,
        public string $status,
        public string $runDate,
        public ?string $claimedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row a row from `SELECT * FROM work_queue`
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['isin'],
            (string) $row['status'],
            (string) $row['run_date'],
            $row['claimed_at'] !== null ? (string) $row['claimed_at'] : null,
        );
    }
}
