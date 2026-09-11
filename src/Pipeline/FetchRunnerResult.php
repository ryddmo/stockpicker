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
 * - `staleFailed` stale `claimed` jobs from a past run date marked `failed` at
 *                 slice start; these belong to the past day, not this slice
 * - `rowsWritten` new `owner_count_daily` rows written via `OwnerCountRepository`
 * - `bySource`    per-source outcome tally over the slice, one bucket per source
 *                 (`avanza`, `nordnet`), initialised from `FetchRunner::SOURCES`
 *                 so a never-called source still reports all-zero:
 *                 `ok` (a `fetch()` returned, upsert or not), `not_found`,
 *                 `schema_mismatch` (counted separately — the endpoint-shape
 *                 signal Story 2.6 alarms on), `transient` (one per job whose
 *                 fetch retries were all exhausted → job reopened `pending`),
 *                 `retried` (backoff retries actually taken for that source this
 *                 slice) and `rate_limited` (HTTP 429s seen). Independent of the
 *                 per-job `done` / `failed` / `reopened` counts above. `ok`
 *                 counts a `fetch()` that returned even if the following
 *                 `upsert()` then throws — that job still ends `failed` via the
 *                 runner's outer `\Throwable` catch.
 */
final readonly class FetchRunnerResult
{
    /**
     * @param array<string, array{ok: int, not_found: int, schema_mismatch: int, transient: int, retried: int, rate_limited: int}> $bySource
     */
    public function __construct(
        public int $claimed,
        public int $done,
        public int $failed,
        public int $reopened,
        public int $staleFailed,
        public int $rowsWritten,
        public array $bySource,
    ) {
    }
}
