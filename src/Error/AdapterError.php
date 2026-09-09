<?php

declare(strict_types=1);

namespace Stockpicker\Error;

use RuntimeException;

/**
 * Base for every typed error a SourceAdapter raises. The pipeline core catches
 * this one type and branches on the concrete subclass — never on a raw Guzzle
 * exception or an HTTP status code (AD-2, AD-7).
 */
abstract class AdapterError extends RuntimeException
{
}
