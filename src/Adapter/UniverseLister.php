<?php

declare(strict_types=1);

namespace Stockpicker\Adapter;

/**
 * The narrow port UniverseSync (Story 2.2) depends on: fetch the tradable
 * Swedish universe listing, and resolve one listing entry's ISIN. Mirrors
 * SourceAdapter — the pipeline core talks to this contract, never to a concrete
 * adapter, so no HTTP or endpoint path leaks past src/Adapter/ (AD-1).
 *
 * AvanzaUniverseAdapter is the only production implementation.
 */
interface UniverseLister
{
    /**
     * @return list<UniverseEntry> the full target universe (LC + MC + SC + First North)
     *
     * @throws \Stockpicker\Error\Transient      a target-list call failed twice
     * @throws \Stockpicker\Error\SchemaMismatch a broken response shape, an unexpected 4xx, or any empty target list
     */
    public function listUniverse(): array;

    /**
     * Resolve the ISIN for one listing entry, keyed by its Avanza orderbook id.
     * The screener response carries no ISIN, so this is one extra HTTP call per
     * not-yet-known instrument.
     *
     * @throws \Stockpicker\Error\NotFound       the orderbook id is unknown (404)
     * @throws \Stockpicker\Error\Transient      retryable failure, already retried once
     * @throws \Stockpicker\Error\SchemaMismatch the response carried no usable `isin`
     */
    public function resolveIsin(string $orderbookId): string;
}
