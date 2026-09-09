<?php

declare(strict_types=1);

namespace Stockpicker\Error;

/**
 * The source has no record matching the requested ISIN (no hit, or hits whose
 * ISIN differs). The instrument is left as-is and the caller moves on.
 */
final class NotFound extends AdapterError
{
}
