<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Pipeline;

use Stockpicker\Pipeline\Enqueue;
use Stockpicker\Store\InstrumentRepository;
use Stockpicker\Store\InstrumentSeeder;
use Stockpicker\Store\QueueRepository;
use Stockpicker\Store\RunRepository;
use Stockpicker\Tests\Store\StoreTestCase;

final class EnqueueTest extends StoreTestCase
{
    private const RUN_DATE = '2026-09-09';

    private function enqueue(): Enqueue
    {
        $instruments = new InstrumentRepository($this->pdo);
        InstrumentSeeder::seed($instruments);

        return new Enqueue(
            new QueueRepository($this->pdo),
            $instruments,
            new RunRepository($this->pdo),
        );
    }

    public function testFirstRunEnqueuesTheWholeUniverseOnce(): void
    {
        $queue = new QueueRepository($this->pdo);
        $enqueue = $this->enqueue();

        $universe = count(InstrumentSeeder::LIST);
        self::assertSame($universe, $enqueue->run(self::RUN_DATE));
        self::assertSame(['pending' => $universe], $queue->countByStatus(self::RUN_DATE));

        $runs = (new RunRepository($this->pdo))->forRunDate(self::RUN_DATE);
        self::assertCount(1, $runs);
        self::assertSame('enqueue', $runs[0]->runType);
        self::assertSame(self::RUN_DATE, $runs[0]->runDate);
        self::assertSame($universe, $runs[0]->instrumentCount);
        self::assertSame($universe, $runs[0]->okCount);
        self::assertSame(0, $runs[0]->failCount);
        self::assertGreaterThanOrEqual($runs[0]->startedAt, $runs[0]->finishedAt);
    }

    public function testSecondRunForTheSameDateAddsNothingButStillLogsARun(): void
    {
        $queue = new QueueRepository($this->pdo);
        $enqueue = $this->enqueue();

        $enqueue->run(self::RUN_DATE);
        // Move one job on to prove the re-run touches no existing row.
        $queue->claimBatch(self::RUN_DATE, 1, new \DateTimeImmutable('2026-09-09 18:00:00', new \DateTimeZone('UTC')));

        self::assertSame(0, $enqueue->run(self::RUN_DATE));

        $counts = $queue->countByStatus(self::RUN_DATE);
        self::assertSame(1, $counts['claimed']);
        self::assertSame(count(InstrumentSeeder::LIST) - 1, $counts['pending']);

        // The re-run still happened -> a second enqueue row, ok_count 0.
        $runs = (new RunRepository($this->pdo))->forRunDate(self::RUN_DATE);
        self::assertCount(2, $runs);
        self::assertSame('enqueue', $runs[1]->runType);
        self::assertSame(count(InstrumentSeeder::LIST), $runs[1]->instrumentCount);
        self::assertSame(0, $runs[1]->okCount);
        self::assertSame(0, $runs[1]->failCount);
    }

    public function testDelistedInstrumentsAreNotEnqueued(): void
    {
        $instruments = new InstrumentRepository($this->pdo);
        InstrumentSeeder::seed($instruments);
        $instruments->markInactive(InstrumentSeeder::LIST[0][0], '2026-09-08');

        $enqueue = new Enqueue(
            new QueueRepository($this->pdo),
            $instruments,
            new RunRepository($this->pdo),
        );

        $active = count(InstrumentSeeder::LIST) - 1;
        self::assertSame($active, $enqueue->run(self::RUN_DATE));
        self::assertSame(['pending' => $active], (new QueueRepository($this->pdo))->countByStatus(self::RUN_DATE));

        $runs = (new RunRepository($this->pdo))->forRunDate(self::RUN_DATE);
        self::assertSame($active, $runs[0]->instrumentCount);
    }

    public function testAnotherRunDateGetsItsOwnJobs(): void
    {
        $enqueue = $this->enqueue();

        $enqueue->run(self::RUN_DATE);
        self::assertSame(count(InstrumentSeeder::LIST), $enqueue->run('2026-09-10'));

        self::assertCount(1, (new RunRepository($this->pdo))->forRunDate('2026-09-10'));
    }
}
