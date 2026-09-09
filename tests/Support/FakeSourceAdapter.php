<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Support;

use Stockpicker\Adapter\NormalizedRow;
use Stockpicker\Adapter\SourceAdapter;
use Stockpicker\Store\Instrument;

/**
 * A hand-rolled SourceAdapter double shared by the pipeline / store tests.
 *
 * `fetch()` and `resolveId()` each pull from their own queue: the next entry is
 * returned when it is a value of the right type, or thrown when it is a
 * Throwable. Every call is recorded (`fetchCalls` / `resolveCalls`), so a test
 * can assert a source was — or was never — hit.
 */
final class FakeSourceAdapter implements SourceAdapter
{
    /** @var list<NormalizedRow|\Throwable> */
    public array $fetchResponses = [];

    /** @var list<string|\Throwable> */
    public array $resolveResponses = [];

    /** @var list<string> ISINs passed to fetch(), in order */
    public array $fetchCalls = [];

    /** @var list<string> ISINs passed to resolveId(), in order */
    public array $resolveCalls = [];

    public float $fetchDelaySeconds = 0.0;

    public function __construct(private readonly string $source = 'test')
    {
    }

    public function resolveId(Instrument $instrument): string
    {
        $this->resolveCalls[] = $instrument->isin;

        $next = array_shift($this->resolveResponses);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        if (is_string($next)) {
            return $next;
        }

        throw new \LogicException(sprintf('no queued resolveId response for %s / %s', $this->source, $instrument->isin));
    }

    public function fetch(Instrument $instrument): NormalizedRow
    {
        $this->fetchCalls[] = $instrument->isin;

        if ($this->fetchDelaySeconds > 0) {
            usleep((int) round($this->fetchDelaySeconds * 1_000_000));
        }

        $next = array_shift($this->fetchResponses);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        if ($next instanceof NormalizedRow) {
            return $next;
        }

        throw new \LogicException(sprintf('no queued fetch response for %s / %s', $this->source, $instrument->isin));
    }
}
