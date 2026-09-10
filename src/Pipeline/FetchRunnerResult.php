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
 * - `bySource`    per-source outcome tally over the slice, one bucket per source
 *                 (`avanza`, `nordnet`), initialised from `FetchRunner::SOURCES`
 *                 so a never-called source still reports all-zero:
 *                 `ok` (a `fetch()` returned, upsert or not), `not_found`,
 *                 `schema_mismatch` (counted separately — the endpoint-shape
 *                 signal Story 2.6 alarms on), `transient`. Independent of the
 *                 per-job `done` / `failed` / `reopened` counts above. `ok`
 *                 counts a `fetch()` that returned even if the following
 *                 `upsert()` then throws — that job still ends `failed` via the
 *                 runner's outer `\Throwable` catch.
 */
final readonly class FetchRunnerResult
{
    /**
     * @param array<string, array{ok: int, not_found: int, schema_mismatch: int, transient: int}> $bySource
     */
    public function __construct(
        public int $claimed,
        public int $done,
        public int $failed,
        public int $reopened,
        public int $rowsWritten,
        public array $bySource,
    ) {
    }
}
