<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Store;

use Stockpicker\Store\InstrumentRepository;

final class InstrumentRepositoryTest extends StoreTestCase
{
    public function testGetReturnsNullForUnknownIsin(): void
    {
        $repo = new InstrumentRepository($this->pdo);

        self::assertNull($repo->get('SE0000000000'));
    }

    public function testUpsertSeedInsertsWithFirstSeenAndNullIds(): void
    {
        $repo = new InstrumentRepository($this->pdo);

        $repo->upsertSeed('SE0000108656', 'Ericsson B', 'LC');

        $row = $repo->get('SE0000108656');
        self::assertNotNull($row);
        self::assertSame('Ericsson B', $row->name);
        self::assertSame('LC', $row->list);
        self::assertNull($row->avanzaOrderbookId);
        self::assertNull($row->nordnetInstrumentId);
        self::assertNull($row->lastSeen);
        self::assertSame(
            (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Stockholm')))->format('Y-m-d'),
            $row->firstSeen,
        );
    }

    public function testUpsertSeedIsIdempotentAndPreservesResolvedIdsAndFirstSeen(): void
    {
        $repo = new InstrumentRepository($this->pdo);
        $repo->upsertSeed('SE0000108656', 'Ericsson B', 'LC');

        // Simulate Story 1.3 resolving ids and an older first_seen.
        $this->pdo->exec(
            "UPDATE instrument
                SET avanza_orderbook_id = '5479',
                    nordnet_instrument_id = '101',
                    first_seen = '2026-01-01'
              WHERE isin = 'SE0000108656'"
        );

        $repo->upsertSeed('SE0000108656', 'Telefonaktiebolaget LM Ericsson B', 'LC');

        $row = $repo->get('SE0000108656');
        self::assertNotNull($row);
        self::assertSame('Telefonaktiebolaget LM Ericsson B', $row->name, 'name is refreshed');
        self::assertSame('5479', $row->avanzaOrderbookId, 'resolved id preserved');
        self::assertSame('101', $row->nordnetInstrumentId, 'resolved id preserved');
        self::assertSame('2026-01-01', $row->firstSeen, 'first_seen not overwritten');
        self::assertCount(1, $repo->all(), 'no duplicate row');
    }

    public function testAllIsKeyedByIsin(): void
    {
        $repo = new InstrumentRepository($this->pdo);
        $repo->upsertSeed('SE0000108656', 'Ericsson B', 'LC');
        $repo->upsertSeed('SE0000115446', 'Volvo B', 'LC');

        $all = $repo->all();

        self::assertSame(['SE0000108656', 'SE0000115446'], array_keys($all));
        self::assertSame('Volvo B', $all['SE0000115446']->name);
    }
}
