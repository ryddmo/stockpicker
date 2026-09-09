<?php

declare(strict_types=1);

namespace Stockpicker\Error;

/**
 * A retryable failure: connection error, timeout, HTTP 429, or 5xx. The
 * adapter retries once; if it still fails the job is left for a later run.
 */
final class Transient extends AdapterError
{
}
