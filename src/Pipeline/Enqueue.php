<?php

declare(strict_types=1);

namespace Stockpicker\Pipeline;

use DateTimeImmutable;
use DateTimeZone;
use Stockpicker\Store\InstrumentRepository;
use Stockpicker\Store\QueueRepository;
use Stockpicker\Store\RunRepository;

/**
 * Fills `work_queue` with one `pending` job per active-universe instrument for
 * a run date (AD-5). The only creator of `pending` rows.
 *
 * Idempotent per `(isin, run_date)`: a second call for the same date inserts
 * nothing and changes no existing row. The universe is every active instrument
 * (`InstrumentRepository::allActive()`, i.e. `last_seen IS NULL`) — a delisting
 * from `UniverseSync` drops out of the nightly path immediately.
 *
 * Every `run()` appends one `enqueue` row to `ingest_run` via `RunRepository`
 * (AD-11) — including a re-run that creates nothing, because the run still
 * happened.
 */
final class Enqueue
{
    public function __construct(
        private readonly QueueRepository $queue,
        private readonly InstrumentRepository $instruments,
        private readonly RunRepository $runs,
    ) {
    }

    /**
     * @param string $runDate a Stockholm `Y-m-d` string, supplied by the caller
     *
     * @return int the number of `pending` rows actually created
     */
    public function run(string $runDate): int
    {
        $startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $universe = array_keys($this->instruments->allActive());
        $created = 0;

        foreach ($universe as $isin) {
            if ($this->queue->enqueue((string) $isin, $runDate)) {
                ++$created;
            }
        }

        $this->runs->record(
            'enqueue',
            $runDate,
            $startedAt,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            count($universe),
            $created,
            0,
        );

        return $created;
    }
}
