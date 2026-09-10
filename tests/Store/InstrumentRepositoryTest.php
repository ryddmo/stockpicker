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

    public function testCacheAvanzaIdAndCacheNordnetIdSetNullColumns(): void
    {
        $repo = new InstrumentRepository($this->pdo);
        $repo->upsertSeed('SE0000108656', 'Ericsson B', 'LC');

        $repo->cacheAvanzaId('SE0000108656', '5479');
        $repo->cacheNordnetId('SE0000108656', '19fa390b-040f-45a9-8fa2-e7fd34e319ab');

        $row = $repo->get('SE0000108656');
        self::assertSame('5479', $row->avanzaOrderbookId);
        self::assertSame('19fa390b-040f-45a9-8fa2-e7fd34e319ab', $row->nordnetInstrumentId);
    }

    public function testCacheIdsAreWriteOnceAndNeverOverwriteAResolvedId(): void
    {
        $repo = new InstrumentRepository($this->pdo);
        $repo->upsertSeed('SE0000108656', 'Ericsson B', 'LC');
        $repo->cacheAvanzaId('SE0000108656', '5479');
        $repo->cacheNordnetId('SE0000108656', '101');

        $repo->cacheAvanzaId('SE0000108656', '9999');
        $repo->cacheNordnetId('SE0000108656', '202');

        $row = $repo->get('SE0000108656');
        self::assertSame('5479', $row->avanzaOrderbookId, 'existing id preserved');
        self::assertSame('101', $row->nordnetInstrumentId, 'existing id preserved');
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

    public function testAllActiveExcludesRowsWithLastSeenSet(): void
    {
        $repo = new InstrumentRepository($this->pdo);
        $repo->upsertSeed('SE0000108656', 'Ericsson B', 'LC');
        $repo->upsertSeed('SE0000115446', 'Volvo B', 'LC');
        $repo->markInactive('SE0000115446', '2026-09-10');

        $active = $repo->allActive();

        self::assertSame(['SE0000108656'], array_keys($active));
        self::assertCount(2, $repo->all(), 'the delisted row is kept, only hidden from allActive()');
    }

    public function testInsertSetsFirstSeenAndLeavesIdsAndLastSeenNull(): void
    {
        $repo = new InstrumentRepository($this->pdo);

        $repo->insert('SE0000108656', 'Ericsson B', 'LC', '2026-09-10');

        $row = $repo->get('SE0000108656');
        self::assertNotNull($row);
        self::assertSame('Ericsson B', $row->name);
        self::assertSame('LC', $row->list);
        self::assertSame('2026-09-10', $row->firstSeen);
        self::assertNull($row->avanzaOrderbookId);
        self::assertNull($row->nordnetInstrumentId);
        self::assertNull($row->lastSeen);
    }

    public function testInsertIsANoOpOnADuplicatePrimaryKey(): void
    {
        $repo = new InstrumentRepository($this->pdo);
        $repo->insert('SE0000108656', 'Ericsson B', 'LC', '2026-01-01');
        $repo->cacheAvanzaId('SE0000108656', '5479');

        // A second insert for the same ISIN must not raise and must not touch
        // the existing row (F2: within-run / concurrent duplicate ISIN).
        $repo->insert('SE0000108656', 'Something Else', 'MC', '2026-09-10');

        $row = $repo->get('SE0000108656');
        self::assertSame('Ericsson B', $row->name);
        self::assertSame('LC', $row->list);
        self::assertSame('2026-01-01', $row->firstSeen);
        self::assertSame('5479', $row->avanzaOrderbookId);
        self::assertCount(1, $repo->all());
    }

    public function testUpdateListAndNameChangesBothColumnsOnly(): void
    {
        $repo = new InstrumentRepository($this->pdo);
        $repo->insert('SE0000108656', 'Ericsson B', 'MC', '2026-01-01');
        $repo->cacheAvanzaId('SE0000108656', '5479');

        $repo->updateListAndName('SE0000108656', 'LC', 'Telefonaktiebolaget LM Ericsson B');

        $row = $repo->get('SE0000108656');
        self::assertSame('LC', $row->list);
        self::assertSame('Telefonaktiebolaget LM Ericsson B', $row->name);
        self::assertSame('5479', $row->avanzaOrderbookId, 'ids untouched');
        self::assertSame('2026-01-01', $row->firstSeen, 'first_seen untouched');
    }

    public function testMarkInactiveIsGuardedOnLastSeenIsNull(): void
    {
        $repo = new InstrumentRepository($this->pdo);
        $repo->insert('SE0000108656', 'Ericsson B', 'LC', '2026-01-01');

        $repo->markInactive('SE0000108656', '2026-09-10');
        $repo->markInactive('SE0000108656', '2026-09-20');

        self::assertSame('2026-09-10', $repo->get('SE0000108656')->lastSeen, 'a re-run never moves the date');
    }

    public function testReactivateClearsLastSeenAndLeavesFirstSeen(): void
    {
        $repo = new InstrumentRepository($this->pdo);
        $repo->insert('SE0000108656', 'Ericsson B', 'LC', '2026-01-01');
        $repo->markInactive('SE0000108656', '2026-09-10');

        $repo->reactivate('SE0000108656');

        $row = $repo->get('SE0000108656');
        self::assertNull($row->lastSeen);
        self::assertSame('2026-01-01', $row->firstSeen);
    }

    public function testSetAvanzaIdOverwritesAnExistingNonNullId(): void
    {
        $repo = new InstrumentRepository($this->pdo);
        $repo->insert('SE0000108656', 'Ericsson B', 'LC', '2026-01-01');
        $repo->cacheAvanzaId('SE0000108656', '5479');

        // Unlike cacheAvanzaId(), this replaces the id — the orderbookId-rotation case.
        $repo->setAvanzaId('SE0000108656', '999999');

        self::assertSame('999999', $repo->get('SE0000108656')->avanzaOrderbookId);
    }
}
