<?php

declare(strict_types=1);

namespace Stockpicker\Store;

use DateInterval;
use DateTimeImmutable;
use PDO;

/**
 * The only DB access to `work_queue` (AD-5): no SQL for the queue lives in the
 * pipeline. The state machine is `pending -> claimed -> done | failed`; only
 * `enqueue()` writes `pending` from scratch, and every other method only makes
 * a documented transition (each guarded by `WHERE status = 'claimed'`).
 *
 * `claimBatch()` tags the rows it claims with the slice's start instant
 * (`:now`, UTC) so a follow-up `SELECT` can read back exactly that set without
 * a worker-id column — safe under NFR2's one-cron-instance-at-a-time guarantee.
 */
final class QueueRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Insert a `pending` job for `(isin, run_date)`, idempotently. Returns true
     * when a new row was created, false when the pair already existed (no row
     * is changed — `ON DUPLICATE KEY UPDATE id = id`). Relies on the mysql
     * driver's default FOUND_ROWS=off (see Database) so `rowCount()` means
     * "rows actually inserted".
     */
    public function enqueue(string $isin, string $runDate): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO `work_queue` (`isin`, `run_date`) VALUES (:isin, :run_date)
             ON DUPLICATE KEY UPDATE `id` = `id`'
        );
        $stmt->execute(['isin' => $isin, 'run_date' => $runDate]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Reopen every `claimed` row for `$runDate` whose `claimed_at` is older than
     * `$staleAfterSeconds` before `$utcNow` — a crashed or cut-off earlier
     * slice left them stuck. Runs first each slice. Returns the number reopened.
     * Scoped to `$runDate` so a stale row from another day is not flipped to
     * `pending` only to sit unclaimable (the slice claims by run date).
     */
    public function reopenStale(int $staleAfterSeconds, DateTimeImmutable $utcNow, string $runDate): int
    {
        $threshold = $utcNow->sub(new DateInterval('PT' . max(0, $staleAfterSeconds) . 'S'));

        $stmt = $this->pdo->prepare(
            'UPDATE `work_queue`
                SET `status` = \'pending\', `claimed_at` = NULL
              WHERE `status` = \'claimed\' AND `run_date` = :run_date AND `claimed_at` < :threshold'
        );
        $stmt->execute([
            'run_date' => $runDate,
            'threshold' => $threshold->format('Y-m-d H:i:s'),
        ]);

        return $stmt->rowCount();
    }

    /**
     * Atomically claim up to `$limit` `pending` jobs for `$runDate`, tagging
     * them with `$utcNow`, then read those exact rows back.
     *
     * @return list<QueueJob>
     */
    public function claimBatch(string $runDate, int $limit, DateTimeImmutable $utcNow): array
    {
        if ($limit < 1) {
            return [];
        }

        $now = $utcNow->format('Y-m-d H:i:s');

        $claim = $this->pdo->prepare(
            'UPDATE `work_queue`
                SET `status` = \'claimed\', `claimed_at` = :now
              WHERE `status` = \'pending\' AND `run_date` = :run_date
              ORDER BY `id`
              LIMIT :lim'
        );
        $claim->bindValue(':now', $now);
        $claim->bindValue(':run_date', $runDate);
        $claim->bindValue(':lim', $limit, PDO::PARAM_INT);
        $claim->execute();

        if ($claim->rowCount() === 0) {
            return [];
        }

        $select = $this->pdo->prepare(
            'SELECT * FROM `work_queue`
              WHERE `status` = \'claimed\' AND `claimed_at` = :now AND `run_date` = :run_date
              ORDER BY `id`'
        );
        $select->execute(['now' => $now, 'run_date' => $runDate]);

        return array_map(
            static fn (array $row): QueueJob => QueueJob::fromRow($row),
            $select->fetchAll(),
        );
    }

    public function markDone(int $id): void
    {
        $this->transition($id, 'done', null);
    }

    public function markFailed(int $id): void
    {
        $this->transition($id, 'failed', null);
    }

    /**
     * Return a `claimed` job to `pending` (timebox leftover, stale one-off, or
     * a source raising `Transient`), clearing its claim tag.
     */
    public function reopen(int $id): void
    {
        $this->transition($id, 'pending', 'clear');
    }

    /**
     * @return array<string, int> status => count, for `$runDate` only
     */
    public function countByStatus(string $runDate): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT `status`, COUNT(*) AS c FROM `work_queue`
              WHERE `run_date` = :run_date GROUP BY `status`'
        );
        $stmt->execute(['run_date' => $runDate]);

        $out = [];
        foreach ($stmt as $row) {
            $out[(string) $row['status']] = (int) $row['c'];
        }

        return $out;
    }

    private function transition(int $id, string $to, ?string $claimedAt): void
    {
        $set = '`status` = :to';
        if ($claimedAt === 'clear') {
            $set .= ', `claimed_at` = NULL';
        }

        $stmt = $this->pdo->prepare(
            "UPDATE `work_queue` SET {$set} WHERE `id` = :id AND `status` = 'claimed'"
        );
        $stmt->execute(['to' => $to, 'id' => $id]);
    }
}
