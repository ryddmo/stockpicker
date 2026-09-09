<?php

declare(strict_types=1);

namespace Stockpicker\Store;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * The only DB access to `ingest_run` (AD-11): no run-log SQL lives in the
 * pipeline classes or `bin/show-runs.php`. Append-only — `record()` is the sole
 * writer and there is deliberately no update or delete method in this story.
 *
 * A night's story is reconstructed by reading every row for a `run_date` in
 * order: the `enqueue` row first, then each `fetch` slice.
 */
final class RunRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Append one row for a finished pipeline run and return its new id.
     * Datetimes are stored as naive UTC `Y-m-d H:i:s`.
     *
     * @param string $runType 'fetch' | 'enqueue' (Epic 1)
     * @param string $runDate the run's Stockholm `Y-m-d`
     */
    public function record(
        string $runType,
        string $runDate,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $finishedAt,
        int $instrumentCount,
        int $okCount,
        int $failCount,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO `ingest_run`
                 (`run_type`, `run_date`, `started_at`, `finished_at`,
                  `instrument_count`, `ok_count`, `fail_count`)
             VALUES (:run_type, :run_date, :started_at, :finished_at,
                     :instrument_count, :ok_count, :fail_count)'
        );
        $stmt->execute([
            'run_type' => $runType,
            'run_date' => $runDate,
            'started_at' => $this->utc($startedAt),
            'finished_at' => $this->utc($finishedAt),
            'instrument_count' => $instrumentCount,
            'ok_count' => $okCount,
            'fail_count' => $failCount,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * The most recent runs, newest first (by `id` desc).
     *
     * @return list<IngestRun>
     */
    public function recent(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `ingest_run` ORDER BY `id` DESC LIMIT :lim'
        );
        $stmt->bindValue(':lim', max(0, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row): IngestRun => IngestRun::fromRow($row),
            $stmt->fetchAll(),
        );
    }

    /**
     * Every run for one run date, oldest first (by `id` asc) — the order the
     * night actually happened in. `$limit`, when given, caps the result the
     * same way as `recent()`.
     *
     * @return list<IngestRun>
     */
    public function forRunDate(string $runDate, ?int $limit = null): array
    {
        $sql = 'SELECT * FROM `ingest_run` WHERE `run_date` = :run_date ORDER BY `id` ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT :lim';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':run_date', $runDate);
        if ($limit !== null) {
            $stmt->bindValue(':lim', max(0, $limit), PDO::PARAM_INT);
        }
        $stmt->execute();

        return array_map(
            static fn (array $row): IngestRun => IngestRun::fromRow($row),
            $stmt->fetchAll(),
        );
    }

    private function utc(DateTimeImmutable $t): string
    {
        return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
