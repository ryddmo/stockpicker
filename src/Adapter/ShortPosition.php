<?php

declare(strict_types=1);

namespace Stockpicker\Adapter;

/**
 * spec-short-interest-data — one normalized row of Finansinspektionen's
 * aggregated short-position file: an issuer (keyed by LEI — the file carries
 * no ISIN), its current aggregated net short position in percent, and the
 * date of the latest reported position. Every field is required; the adapter
 * raises SchemaMismatch rather than build one with a missing value (AD-7).
 */
final readonly class ShortPosition
{
    public function __construct(
        public string $lei,
        public string $issuerName,
        public float $positionPct,
        public string $positionDate,
    ) {
    }
}
