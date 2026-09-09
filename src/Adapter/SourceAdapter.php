<?php

declare(strict_types=1);

namespace Stockpicker\Adapter;

use Stockpicker\Store\Instrument;

/**
 * The port every external price/ownership source sits behind (AD-1). The
 * pipeline core talks only to this interface — no HTTP, endpoint path, or
 * source-specific field name leaks past an implementation in src/Adapter/.
 */
interface SourceAdapter
{
    /**
     * Resolve this source's internal instrument id from the instrument's ISIN.
     * Pure: no database access, no persistence.
     *
     * @return string the source id (e.g. Avanza orderBookId, Nordnet nnx_instrument_id)
     *
     * @throws \Stockpicker\Error\NotFound       no result carries the requested ISIN
     * @throws \Stockpicker\Error\Transient      retryable failure, already retried once
     * @throws \Stockpicker\Error\SchemaMismatch the response shape was not what we expect
     */
    public function resolveId(Instrument $instrument): string;

    /**
     * Fetch one normalized owner-count datapoint for the instrument.
     *
     * Declared here for the pipeline contract; implemented in Story 1.4
     * (Avanza) and Story 1.5 (Nordnet).
     */
    public function fetch(Instrument $instrument): mixed;
}
