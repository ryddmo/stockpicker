<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Pipeline;

use Stockpicker\Pipeline\Enqueue;
use Stockpicker\Store\InstrumentRepository;
use Stockpicker\Store\InstrumentSeeder;
use Stockpicker\Store\QueueRepository;
use Stockpicker\Tests\Store\StoreTestCase;

final class EnqueueTest extends StoreTestCase
{
    private const RUN_DATE = '2026-09-09';

    public function testFirstRunEnqueuesTheWholeUniverseOnce(): void
    {
        $instruments = new InstrumentRepository($this->pdo);
        InstrumentSeeder::seed($instruments);
        $queue = new QueueRepository($this->pdo);

        $enqueue = new Enqueue($queue, $instruments);

        $universe = count(InstrumentSeeder::LIST);
        self::assertSame($universe, $enqueue->run(self::RUN_DATE));
        self::assertSame(['pending' => $universe], $queue->countByStatus(self::RUN_DATE));
    }

    public function testSecondRunForTheSameDateAddsNothing(): void
    {
        $instruments = new InstrumentRepository($this->pdo);
        InstrumentSeeder::seed($instruments);
        $queue = new QueueRepository($this->pdo);
        $enqueue = new Enqueue($queue, $instruments);

        $enqueue->run(self::RUN_DATE);
        // Move one job on to prove the re-run touches no existing row.
        $queue->claimBatch(self::RUN_DATE, 1, new \DateTimeImmutable('2026-09-09 18:00:00', new \DateTimeZone('UTC')));

        self::assertSame(0, $enqueue->run(self::RUN_DATE));

        $counts = $queue->countByStatus(self::RUN_DATE);
        self::assertSame(1, $counts['claimed']);
        self::assertSame(count(InstrumentSeeder::LIST) - 1, $counts['pending']);
    }

    public function testAnotherRunDateGetsItsOwnJobs(): void
    {
        $instruments = new InstrumentRepository($this->pdo);
        InstrumentSeeder::seed($instruments);
        $queue = new QueueRepository($this->pdo);
        $enqueue = new Enqueue($queue, $instruments);

        $enqueue->run(self::RUN_DATE);
        self::assertSame(count(InstrumentSeeder::LIST), $enqueue->run('2026-09-10'));
    }
}
