<?php

declare(strict_types=1);

namespace Stockpicker\Store;

/**
 * One row of the `instrument` dimension table. ISIN is the natural key; the
 * Avanza/Nordnet ids are cached attributes resolved once (Story 1.3), null
 * until then. Dates are ISO-8601 calendar dates (Y-m-d).
 */
final readonly class Instrument
{
    public function __construct(
        public string $isin,
        public string $name,
        public string $list,
        public ?string $avanzaOrderbookId,
        public ?string $nordnetInstrumentId,
        public string $firstSeen,
        public ?string $lastSeen,
    ) {
    }

    /**
     * @param array<string, mixed> $row a row from `SELECT * FROM instrument`
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['isin'],
            (string) $row['name'],
            (string) $row['list'],
            $row['avanza_orderbook_id'] !== null ? (string) $row['avanza_orderbook_id'] : null,
            $row['nordnet_instrument_id'] !== null ? (string) $row['nordnet_instrument_id'] : null,
            (string) $row['first_seen'],
            $row['last_seen'] !== null ? (string) $row['last_seen'] : null,
        );
    }
}
