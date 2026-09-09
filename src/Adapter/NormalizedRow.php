<?php

declare(strict_types=1);

namespace Stockpicker\Adapter;

use DateTimeImmutable;

/**
 * One owner-count datapoint, normalized across sources — the contract every
 * SourceAdapter::fetch() returns and OwnerCountRepository (Story 1.6) stores.
 *
 * `as_of_date` is deliberately absent: the single Europe/Stockholm calendar
 * conversion belongs to Story 1.6, derived from `sourceTimestamp` when the
 * source provides one (Nordnet's `statistics_timestamp`), else the run date.
 * Avanza has no per-datum timestamp, so its rows carry `sourceTimestamp = null`.
 */
final readonly class NormalizedRow
{
    public const SOURCE_AVANZA = 'avanza';
    public const SOURCE_NORDNET = 'nordnet';

    public function __construct(
        public string $isin,
        public string $source,
        public int $numberOfOwners,
        public ?float $lastPrice,
        public ?float $marketCap,
        public ?DateTimeImmutable $sourceTimestamp,
        public DateTimeImmutable $fetchedAt,
    ) {
    }
}
