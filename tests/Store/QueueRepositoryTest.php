<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Store;

use DateTimeImmutable;
use DateTimeZone;
use Stockpicker\Store\QueueRepository;

final class QueueRepositoryTest extends StoreTestCase
{
    private const RUN_DATE = '2026-09-09';

    /** @var list<string> */
    private array $isins = [];

    private function repo(int $instrumentCount = 3): QueueRepository
    {
        $this->isins = [];
        for ($i = 1; $i <= $instrumentCount; ++$i) {
            $isin = sprintf('SE00000000%02d', $i);
            $this->isins[] = $isin;
            $this->pdo->exec(
                "INSERT INTO instrument (isin, name, list, first_seen)
                 VALUES ('{$isin}', 'Test {$i}', 'LC', '2026-01-01')"
            );
        }

        return new QueueRepository($this->pdo);
    }

    private function utc(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    public function testEnqueueFreshInsertsPendingAndReturnsTrue(): void
    {
        $repo = $this->repo();

        self::assertTrue($repo->enqueue($this->isins[0], self::RUN_DATE));
        self::assertSame(['pending' => 1], $repo->countByStatus(self::RUN_DATE));
    }

    public function testEnqueueReRunReturnsFalseAndChangesNothing(): void
    {
        $repo = $this->repo();
        $repo->enqueue($this->isins[0], self::RUN_DATE);

        // Move it on so a second enqueue would be visible if it wrote anything.
        $now = $this->utc('2026-09-09T18:00:00Z');
        $repo->claimBatch(self::RUN_DATE, 10, $now);

        self::assertFalse($repo->enqueue($this->isins[0], self::RUN_DATE));
        self::assertSame(['claimed' => 1], $repo->countByStatus(self::RUN_DATE));
    }

    public function testClaimBatchHonoursLimitAndReturnsExactlyTheClaimedRows(): void
    {
        $repo = $this->repo(3);
        foreach ($this->isins as $isin) {
            $repo->enqueue($isin, self::RUN_DATE);
        }
        // A pending job for another run date must not be touched.
        $repo->enqueue($this->isins[0], '2026-09-10');

        $now = $this->utc('2026-09-09T18:00:00Z');
        $jobs = $repo->claimBatch(self::RUN_DATE, 2, $now);

        self::assertCount(2, $jobs);
        foreach ($jobs as $job) {
            self::assertSame('claimed', $job->status);
            self::assertSame('2026-09-09 18:00:00', $job->claimedAt);
            self::assertSame(self::RUN_DATE, $job->runDate);
        }
        // FIFO: the two lowest ids, in ascending order.
        $allIds = $this->pdo->query('SELECT id FROM work_queue ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(
            [(int) $allIds[0], (int) $allIds[1]],
            [$jobs[0]->id, $jobs[1]->id],
        );
        self::assertEqualsCanonicalizing(['claimed' => 2, 'pending' => 1], $repo->countByStatus(self::RUN_DATE));
        self::assertSame(['pending' => 1], $repo->countByStatus('2026-09-10'));
    }

    public function testClaimBatchReturnsEmptyWhenNothingPending(): void
    {
        $repo = $this->repo(1);

        self::assertSame([], $repo->claimBatch(self::RUN_DATE, 5, $this->utc('2026-09-09T18:00:00Z')));
    }

    public function testReopenStaleReopensOnlyRowsOlderThanTheWindow(): void
    {
        $repo = $this->repo(2);
        $repo->enqueue($this->isins[0], self::RUN_DATE);
        $repo->enqueue($this->isins[1], self::RUN_DATE);

        // isins[0] claimed 20 min ago, isins[1] claimed 5 min ago.
        $repo->claimBatch(self::RUN_DATE, 1, $this->utc('2026-09-09T18:00:00Z'));
        $repo->claimBatch(self::RUN_DATE, 1, $this->utc('2026-09-09T18:15:00Z'));

        $now = $this->utc('2026-09-09T18:20:00Z');
        $reopened = $repo->reopenStale(900, $now, self::RUN_DATE)->reopened; // 15 min window

        self::assertSame(1, $reopened);
        self::assertEqualsCanonicalizing(['pending' => 1, 'claimed' => 1], $repo->countByStatus(self::RUN_DATE));
    }

    public function testReopenStaleBoundaryIsStrictlyOlderThan(): void
    {
        $repo = $this->repo(1);
        $repo->enqueue($this->isins[0], self::RUN_DATE);
        $repo->claimBatch(self::RUN_DATE, 1, $this->utc('2026-09-09T18:00:00Z'));

        // Exactly 900s later — not strictly older, so not reopened.
        self::assertSame(0, $repo->reopenStale(900, $this->utc('2026-09-09T18:15:00Z'), self::RUN_DATE)->reopened);
        // One second past the window — reopened.
        self::assertSame(1, $repo->reopenStale(900, $this->utc('2026-09-09T18:15:01Z'), self::RUN_DATE)->reopened);
    }

    public function testReopenStaleReopensCurrentDateAndFailsPastDateRows(): void
    {
        $repo = $this->repo(3);
        $repo->enqueue($this->isins[0], '2026-09-08');
        $repo->enqueue($this->isins[1], self::RUN_DATE);
        $repo->enqueue($this->isins[2], '2026-09-08');

        // The stale rows are recovered according to their run date.
        $repo->claimBatch('2026-09-08', 1, $this->utc('2026-09-08T18:00:00Z'));
        $repo->claimBatch(self::RUN_DATE, 1, $this->utc('2026-09-09T17:00:00Z'));
        $repo->claimBatch('2026-09-08', 1, $this->utc('2026-09-09T17:50:00Z'));

        $recovery = $repo->reopenStale(900, $this->utc('2026-09-09T18:00:00Z'), self::RUN_DATE);

        self::assertSame(1, $recovery->reopened);
        self::assertSame(1, $recovery->staleFailed);
        self::assertEqualsCanonicalizing(['failed' => 1, 'claimed' => 1], $repo->countByStatus('2026-09-08'));
        self::assertSame(['pending' => 1], $repo->countByStatus(self::RUN_DATE));
    }

    public function testTransitionsOnlyApplyToClaimedRows(): void
    {
        $repo = $this->repo(3);
        foreach ($this->isins as $isin) {
            $repo->enqueue($isin, self::RUN_DATE);
        }
        $jobs = $repo->claimBatch(self::RUN_DATE, 3, $this->utc('2026-09-09T18:00:00Z'));

        $repo->markDone($jobs[0]->id);
        $repo->markFailed($jobs[1]->id);
        $repo->reopen($jobs[2]->id);

        self::assertEqualsCanonicalizing(
            ['done' => 1, 'failed' => 1, 'pending' => 1],
            $repo->countByStatus(self::RUN_DATE),
        );

        // A done row is not re-openable / re-failable.
        $repo->reopen($jobs[0]->id);
        $repo->markFailed($jobs[0]->id);
        self::assertSame(1, $repo->countByStatus(self::RUN_DATE)['done']);
    }

    public function testReopenClearsTheClaimTag(): void
    {
        $repo = $this->repo(1);
        $repo->enqueue($this->isins[0], self::RUN_DATE);
        $jobs = $repo->claimBatch(self::RUN_DATE, 1, $this->utc('2026-09-09T18:00:00Z'));
        $repo->reopen($jobs[0]->id);

        $reclaimed = $repo->claimBatch(self::RUN_DATE, 1, $this->utc('2026-09-09T19:00:00Z'));
        self::assertCount(1, $reclaimed);
        self::assertSame('2026-09-09 19:00:00', $reclaimed[0]->claimedAt);
    }

    public function testCountByStatusIsScopedToTheRunDate(): void
    {
        $repo = $this->repo(2);
        $repo->enqueue($this->isins[0], self::RUN_DATE);
        $repo->enqueue($this->isins[0], '2026-09-10');
        $repo->enqueue($this->isins[1], '2026-09-10');

        self::assertSame(['pending' => 1], $repo->countByStatus(self::RUN_DATE));
        self::assertSame(['pending' => 2], $repo->countByStatus('2026-09-10'));
        self::assertSame([], $repo->countByStatus('2099-01-01'));
    }
}
