<?php

declare(strict_types=1);

namespace Stockpicker\Store;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * The only DB access to `ingest_run` (AD-11): no run-log SQL lives in the
 * pipeline classes or `bin/show-runs.php`. A run is inserted at start and
 * completed exactly once, preserving an inspectable row when a slice aborts.
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
        $id = $this->start($runType, $runDate, $startedAt, $instrumentCount);
        $this->finish($id, $finishedAt, $instrumentCount, $okCount, $failCount);

        return $id;
    }

    public function start(string $runType, string $runDate, DateTimeImmutable $startedAt, int $instrumentCount = 0): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO `ingest_run`
                 (`run_type`, `run_date`, `started_at`, `instrument_count`,
                  `ok_count`, `fail_count`, `status`, `alarm`,
                  `schema_mismatch_count`, `by_source`)
             VALUES (:run_type, :run_date, :started_at, :instrument_count,
                     0, 0, :status, 0, 0, :by_source)'
        );
        $stmt->execute([
            'run_type' => $runType,
            'run_date' => $runDate,
            'started_at' => $this->utc($startedAt),
            'instrument_count' => $instrumentCount,
            'status' => 'running',
            'by_source' => '{}',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, array<string, int>> $bySource */
    public function finish(
        int $id,
        DateTimeImmutable $finishedAt,
        int $instrumentCount,
        int $okCount,
        int $failCount,
        array $bySource = [],
        string $status = 'completed',
        ?bool $alarm = null,
    ): void {
        $schemaMismatchCount = 0;
        foreach ($bySource as $counts) {
            $schemaMismatchCount += (int) ($counts['schema_mismatch'] ?? 0);
        }
        if ($schemaMismatchCount > 0) {
            $alarm = true;
        }
        $alarm ??= $schemaMismatchCount > 0;
        if ($alarm && $status === 'completed') {
            $status = 'alarmed';
        }

        $stmt = $this->pdo->prepare(
            'UPDATE `ingest_run`
             SET `finished_at` = :finished_at, `instrument_count` = :instrument_count,
                 `ok_count` = :ok_count, `fail_count` = :fail_count,
                 `status` = :status, `alarm` = :alarm,
                 `schema_mismatch_count` = :schema_mismatch_count,
                 `by_source` = :by_source
             WHERE `id` = :id AND `status` = :running'
        );
        $stmt->execute([
            'id' => $id,
            'finished_at' => $this->utc($finishedAt),
            'instrument_count' => $instrumentCount,
            'ok_count' => $okCount,
            'fail_count' => $failCount,
            'status' => $status,
            'alarm' => $alarm ? 1 : 0,
            'schema_mismatch_count' => $schemaMismatchCount,
            'by_source' => json_encode($bySource, JSON_THROW_ON_ERROR),
            'running' => 'running',
        ]);
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

    /** @return list<IngestRun> */
    public function alarmed(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `ingest_run` WHERE `alarm` = 1 ORDER BY `id` DESC LIMIT :lim');
        $stmt->bindValue(':lim', max(0, $limit), PDO::PARAM_INT);
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
