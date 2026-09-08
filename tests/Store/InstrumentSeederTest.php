<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Store;

use Stockpicker\Store\InstrumentRepository;
use Stockpicker\Store\InstrumentSeeder;

final class InstrumentSeederTest extends StoreTestCase
{
    public function testFirstRunInsertsTheWholeListWithFirstSeenAndNullIds(): void
    {
        $repo = new InstrumentRepository($this->pdo);

        $result = InstrumentSeeder::seed($repo);

        self::assertSame(['inserted' => 20, 'unchanged' => 0], $result);

        $all = $repo->all();
        self::assertCount(20, $all);
        self::assertSame(count(InstrumentSeeder::LIST), count($all));

        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
        foreach ($all as $instrument) {
            self::assertSame('LC', $instrument->list);
            self::assertSame($today, $instrument->firstSeen);
            self::assertNull($instrument->lastSeen);
            self::assertNull($instrument->avanzaOrderbookId);
            self::assertNull($instrument->nordnetInstrumentId);
        }
    }

    public function testReRunInsertsNothingAndPreservesResolvedIds(): void
    {
        $repo = new InstrumentRepository($this->pdo);
        InstrumentSeeder::seed($repo);

        $this->pdo->exec(
            "UPDATE instrument
                SET avanza_orderbook_id = '5479', first_seen = '2026-01-01'
              WHERE isin = 'SE0000108656'"
        );

        $result = InstrumentSeeder::seed($repo);

        self::assertSame(['inserted' => 0, 'unchanged' => 20], $result);
        self::assertCount(20, $repo->all());

        $ericsson = $repo->get('SE0000108656');
        self::assertNotNull($ericsson);
        self::assertSame('5479', $ericsson->avanzaOrderbookId);
        self::assertSame('2026-01-01', $ericsson->firstSeen);
    }
}
