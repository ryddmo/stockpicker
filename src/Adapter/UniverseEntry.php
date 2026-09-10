<?php

declare(strict_types=1);

namespace Stockpicker\Adapter;

/**
 * One entry of the tradable Swedish universe: the Avanza `orderbookId` (carried
 * straight out of the screener listing), the instrument's display name (doubling
 * as the Nordnet search string, mirroring Store\Instrument), and the normalized
 * list label.
 *
 * ISIN is deliberately not here — the Avanza screener response does not carry it;
 * Story 2.2 (UniverseSync) resolves ISIN per instrument via
 * `market-guide/stock/{orderbookId}`.
 *
 * This is the normalized contract AvanzaUniverseAdapter::listUniverse() returns
 * and UniverseSync (Story 2.2) consumes — never a raw source array. The `list`
 * value is always one of the LIST_* constants below, which line up 1:1 with
 * Store\Instrument::$list (`LC | MC | SC | First North`).
 */
final readonly class UniverseEntry
{
    public const LIST_LC = 'LC';
    public const LIST_MC = 'MC';
    public const LIST_SC = 'SC';
    public const LIST_FIRST_NORTH = 'First North';

    public function __construct(
        public string $avanzaOrderbookId,
        public string $name,
        public string $list,
    ) {
    }
}
