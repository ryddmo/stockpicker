<?php

declare(strict_types=1);

namespace Stockpicker\Error;

/**
 * A transient failure that is specifically an HTTP 429 (rate limited). The
 * adapter layer maps status `429` → `RateLimited` and every other transient
 * (connection error, timeout, 5xx) → plain {@see Transient}, so `FetchRunner`
 * can tell a rate limit from a server error without touching a raw HTTP status
 * (AD-2). On a `RateLimited` the runner widens that retry's backoff and halves
 * the in-memory call rate for that source for the rest of the slice.
 *
 * `Retry-After` response headers are not read.
 */
final class RateLimited extends Transient
{
}
