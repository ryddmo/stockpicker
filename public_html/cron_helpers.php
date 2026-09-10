<?php

declare(strict_types=1);

/**
 * Pure helpers for the cron endpoints, split out of index.php so they can be
 * unit-tested without booting the front controller (mirrors how the pipeline
 * classes keep their pure bits DB-free).
 */

/**
 * The `universe.resolve_timebox` setting as a positive float, or the 45.0 s
 * default when the raw value is absent (null) or unusable (non-numeric, zero,
 * negative) — the same positive-number-or-default rule FetchRunner applies to
 * its own settings. Capped at 120.0 s so a mis-set value can never let
 * `/cron/refill` run past the cron SAPI `max_execution_time` and be killed
 * mid-reconcile.
 */
function universe_resolve_timebox(?string $raw): float
{
    if ($raw !== null && is_numeric($raw) && (float) $raw > 0.0) {
        return min((float) $raw, 120.0);
    }

    return 45.0;
}
