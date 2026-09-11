<?php

declare(strict_types=1);

namespace Stockpicker\Store;

/** Counts stale queue rows recovered at the start of a fetch slice. */
final readonly class QueueStaleRecoveryResult
{
    public function __construct(
        public int $reopened,
        public int $staleFailed,
    ) {
    }
}