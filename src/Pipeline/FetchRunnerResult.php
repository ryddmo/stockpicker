<?php

declare(strict_types=1);

namespace Stockpicker\Pipeline;

/**
 * The outcome of one `FetchRunner::run()` slice — the seam Story 1.8's
 * `ingest_run` records.
 *
 * - `claimed`     jobs claimed by this slice (<= `batch_size`)
 * - `done`        jobs fully handled (every attempted source stored or skipped)
 * - `failed`      jobs with >= 1 NotFound/SchemaMismatch and no Transient
 * - `reopened`    jobs returned to `pending` this slice: stale one-offs +
 *                 Transient hits + timebox leftovers
 * - `rowsWritten` new `owner_count_daily` rows written via `OwnerCountRepository`
 */
final readonly class FetchRunnerResult
{
    public function __construct(
        public int $claimed,
        public int $done,
        public int $failed,
        public int $reopened,
        public int $rowsWritten,
    ) {
    }
}
