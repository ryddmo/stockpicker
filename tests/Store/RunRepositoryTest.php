<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Store;

use DateTimeImmutable;
use DateTimeZone;
use Stockpicker\Store\IngestRun;
use Stockpicker\Store\RunRepository;

final class RunRepositoryTest extends StoreTestCase
{
    private function utc(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    public function testRecordInsertsAndReturnsANewId(): void
    {
        $repo = new RunRepository($this->pdo);

        $id = $repo->record(
            'enqueue',
            '2026-09-09',
            $this->utc('2026-09-09T18:00:00Z'),
            $this->utc('2026-09-09T18:00:02Z'),
            20,
            20,
            0,
        );

        self::assertGreaterThan(0, $id);

        $rows = $repo->recent();
        self::assertCount(1, $rows);

        $row = $rows[0];
        self::assertInstanceOf(IngestRun::class, $row);
        self::assertSame($id, $row->id);
        self::assertSame('enqueue', $row->runType);
        self::assertSame('2026-09-09', $row->runDate);
        self::assertSame('2026-09-09 18:00:00', $row->startedAt);
        self::assertSame('2026-09-09 18:00:02', $row->finishedAt);
        self::assertSame(20, $row->instrumentCount);
        self::assertSame(20, $row->okCount);
        self::assertSame(0, $row->failCount);
    }

    public function testRecordNormalisesTimesToUtc(): void
    {
        $repo = new RunRepository($this->pdo);

        $repo->record(
            'fetch',
            '2026-09-09',
            new DateTimeImmutable('2026-09-09 20:00:00', new DateTimeZone('Europe/Stockholm')),
            new DateTimeImmutable('2026-09-09 20:01:30', new DateTimeZone('Europe/Stockholm')),
            5,
            4,
            1,
        );

        $row = $repo->recent()[0];
        // Stockholm is +02:00 in September -> 18:00 / 18:01:30 UTC.
        self::assertSame('2026-09-09 18:00:00', $row->startedAt);
        self::assertSame('2026-09-09 18:01:30', $row->finishedAt);
    }

    public function testRecentIsNewestFirstAndHonoursTheLimit(): void
    {
        $repo = new RunRepository($this->pdo);
        for ($i = 0; $i < 5; ++$i) {
            $repo->record(
                'fetch',
                '2026-09-09',
                $this->utc('2026-09-09T18:00:00Z'),
                $this->utc('2026-09-09T18:00:01Z'),
                $i,
                $i,
                0,
            );
        }

        $rows = $repo->recent(3);
        self::assertCount(3, $rows);
        self::assertSame([4, 3, 2], array_map(static fn (IngestRun $r): int => $r->instrumentCount, $rows));
    }

    public function testRecentDefaultsToTwentyNewestRows(): void
    {
        $repo = new RunRepository($this->pdo);
        for ($i = 0; $i < 22; ++$i) {
            $repo->record(
                'fetch',
                '2026-09-09',
                $this->utc('2026-09-09T18:00:00Z'),
                $this->utc('2026-09-09T18:00:01Z'),
                $i,
                $i,
                0,
            );
        }

        $rows = $repo->recent();
        self::assertCount(20, $rows);
        // Newest 20: instrument_count 21 down to 2.
        self::assertSame(21, $rows[0]->instrumentCount);
        self::assertSame(2, $rows[19]->instrumentCount);
    }

    public function testForRunDateIsScopedAndOldestFirst(): void
    {
        $repo = new RunRepository($this->pdo);
        $repo->record('enqueue', '2026-09-09', $this->utc('2026-09-09T18:00:00Z'), $this->utc('2026-09-09T18:00:01Z'), 20, 20, 0);
        $repo->record('fetch', '2026-09-09', $this->utc('2026-09-09T18:05:00Z'), $this->utc('2026-09-09T18:06:00Z'), 5, 5, 0);
        $repo->record('fetch', '2026-09-09', $this->utc('2026-09-09T18:07:00Z'), $this->utc('2026-09-09T18:08:00Z'), 4, 4, 0);
        $repo->record('enqueue', '2026-09-10', $this->utc('2026-09-10T18:00:00Z'), $this->utc('2026-09-10T18:00:01Z'), 20, 0, 0);

        $rows = $repo->forRunDate('2026-09-09');
        self::assertCount(3, $rows);
        self::assertSame('enqueue', $rows[0]->runType);
        self::assertSame('fetch', $rows[1]->runType);
        self::assertSame(4, $rows[2]->instrumentCount);

        $limited = $repo->forRunDate('2026-09-09', 2);
        self::assertCount(2, $limited);
        self::assertSame(5, $limited[1]->instrumentCount);

        self::assertCount(1, $repo->forRunDate('2026-09-10'));
        self::assertSame([], $repo->forRunDate('2099-01-01'));
    }
}
