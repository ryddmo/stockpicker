<?php

declare(strict_types=1);

namespace Stockpicker\Pipeline;

use Stockpicker\Store\InstrumentRepository;
use Stockpicker\Store\QueueRepository;

/**
 * Fills `work_queue` with one `pending` job per active-universe instrument for
 * a run date (AD-5). The only creator of `pending` rows.
 *
 * Idempotent per `(isin, run_date)`: a second call for the same date inserts
 * nothing and changes no existing row. In Epic 1 the universe is every
 * `InstrumentRepository::all()` row (the seed list); `last_seen` filtering is
 * Epic 2.
 */
final class Enqueue
{
    public function __construct(
        private readonly QueueRepository $queue,
        private readonly InstrumentRepository $instruments,
    ) {
    }

    /**
     * @param string $runDate a Stockholm `Y-m-d` string, supplied by the caller
     *
     * @return int the number of `pending` rows actually created
     */
    public function run(string $runDate): int
    {
        $created = 0;

        foreach (array_keys($this->instruments->all()) as $isin) {
            if ($this->queue->enqueue((string) $isin, $runDate)) {
                ++$created;
            }
        }

        return $created;
    }
}
