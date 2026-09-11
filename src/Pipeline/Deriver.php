<?php

declare(strict_types=1);

namespace Stockpicker\Pipeline;

use Stockpicker\Store\DerivedMetricsRepository;

/**
 * Thin filter exposing `owner_count_metrics` (Story 3.1) to the rest of the
 * pipeline. Read-only, stateless — no SQL of its own, no `ingest_run` row
 * (there is nothing to "run": the view has no execution to log). Today it
 * only delegates; Story 3.2/3.3 decide whether it grows logic of its own,
 * but this is the settled name/shape they build on (epic-3-context.md).
 */
final class Deriver
{
    public function __construct(private readonly DerivedMetricsRepository $metrics)
    {
    }

    /**
     * @return array<string, list<array<string, mixed>>> keyed by source
     */
    public function forInstrument(string $isin): array
    {
        return $this->metrics->forIsin($isin);
    }
}
