<?php

declare(strict_types=1);

namespace Stockpicker\Adapter;

/**
 * spec-short-interest-data — the narrow port ShortPositionSync depends on:
 * fetch the current aggregated short positions per issuer. Mirrors
 * UniverseLister — the pipeline talks to this contract, never to a concrete
 * adapter, so no HTTP, URL or file-format parsing leaks past src/Adapter/
 * (AD-1). FiShortPositionAdapter is the only production implementation.
 */
interface ShortPositionSource
{
    /**
     * @return list<ShortPosition> every issuer currently in the file, one per LEI
     *
     * @throws \Stockpicker\Error\Transient      the download failed twice (connect / timeout / 429 / 5xx)
     * @throws \Stockpicker\Error\SchemaMismatch an unexpected 4xx, an unreadable file, a missing/renamed
     *                                           header column, an unparseable value, or no data rows at all
     */
    public function fetchAggregated(): array;
}
