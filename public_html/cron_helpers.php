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

/**
 * Whether `$now` falls on a day the `run_weekdays` setting allows the
 * pipeline to run — a comma-separated list of ISO-8601 weekday numbers
 * (1=Monday .. 7=Sunday). An absent or unparseable value defaults to
 * Mon-Fri: owner counts don't move over the weekend, so there is no point
 * hitting Avanza/Nordnet then.
 */
function cron_is_allowed_weekday(?string $raw, \DateTimeImmutable $now): bool
{
    $days = array_filter(array_map('trim', explode(',', (string) $raw)));
    $valid = array_values(array_filter(
        $days,
        static fn (string $day): bool => ctype_digit($day) && (int) $day >= 1 && (int) $day <= 7,
    ));

    if ($valid === []) {
        $valid = ['1', '2', '3', '4', '5'];
    }

    return in_array($now->format('N'), $valid, true);
}
