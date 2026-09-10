<?php

declare(strict_types=1);

namespace Stockpicker\Error;

/**
 * A retryable failure: connection error, timeout, or HTTP 5xx. `FetchRunner`
 * owns the retry for the fetch path (Story 2.4) — it retries `fetch()` on this
 * error with exponential backoff, bounded by `retry.max_attempts` and the slice
 * timebox; if it still fails the job is left `pending` for the next cron pass.
 * `withOneRetry()` still handles this for `resolveId()` and the universe adapter.
 *
 * An HTTP 429 is raised as {@see RateLimited} (a subtype) so `FetchRunner` can
 * widen that retry's backoff and reduce the per-source call rate without ever
 * branching on a raw HTTP status (AD-2).
 */
class Transient extends AdapterError
{
}
