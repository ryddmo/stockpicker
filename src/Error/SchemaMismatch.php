<?php

declare(strict_types=1);

namespace Stockpicker\Error;

/**
 * A source responded, but the payload did not match the expected schema — a
 * field is missing, renamed, or the wrong type. Never write a null downstream;
 * raise this instead (AD-7). The message names the offending field.
 */
final class SchemaMismatch extends AdapterError
{
}
