<?php

declare(strict_types=1);

namespace Stockpicker\Adapter;

/**
 * One entry of the tradable Swedish universe as defined by Börsdata: an ISIN,
 * the instrument's display name (doubling as the Avanza/Nordnet search string,
 * mirroring Store\Instrument), and the normalized list label.
 *
 * This is the normalized contract BorsdataAdapter::listUniverse() returns and
 * UniverseSync (Story 2.2) consumes — never a raw Börsdata array. The `list`
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
        public string $isin,
        public string $name,
        public string $list,
    ) {
    }
}
