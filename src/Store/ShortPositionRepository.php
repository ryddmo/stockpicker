<?php

declare(strict_types=1);

namespace Stockpicker\Store;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Stockpicker\Adapter\ShortPosition;

/**
 * spec-short-interest-data — the only DB access to `short_position`
 * (AD-14). Written only by ShortPositionSync (AD-3).
 *
 * Snapshot semantics: every nightly fetch is stored under its Stockholm run
 * date (`snapshot_date`). The *current* position of an issuer is its row in
 * the latest snapshot (`MAX(snapshot_date)`) — FI's file lists only issuers
 * that currently have a reported position, so an issuer absent from the
 * latest snapshot has none, and an older row never counts.
 */
final class ShortPositionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Idempotent write of one night's snapshot, keyed on
     * (snapshot_date, lei) (AD-4): the day's rows are replaced by the new
     * file, so a re-run night ends in the same state as its last fetch.
     * All rows are written in one transaction — a failure leaves the
     * previous snapshot as the current one.
     *
     * @param list<ShortPosition> $positions
     *
     * @return int rows written
     */
    public function upsertSnapshot(string $snapshotDate, array $positions, DateTimeImmutable $fetchedAt): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO short_position
                 (snapshot_date, lei, issuer_name, position_pct, position_date, fetched_at)
             VALUES (:snapshot_date, :lei, :issuer_name, :position_pct, :position_date, :fetched_at)
             ON DUPLICATE KEY UPDATE
                 issuer_name = VALUES(issuer_name),
                 position_pct = VALUES(position_pct),
                 position_date = VALUES(position_date),
                 fetched_at = VALUES(fetched_at)'
        );
        $fetched = $fetchedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            // Replace the day's snapshot: a same-day re-run whose file no
            // longer lists an issuer must not leave its old row current.
            $this->pdo->prepare('DELETE FROM short_position WHERE snapshot_date = :snapshot_date')
                ->execute(['snapshot_date' => $snapshotDate]);
            foreach ($positions as $p) {
                $stmt->execute([
                    'snapshot_date' => $snapshotDate,
                    'lei' => $p->lei,
                    'issuer_name' => mb_substr($p->issuerName, 0, 255),
                    'position_pct' => number_format($p->positionPct, 2, '.', ''),
                    'position_date' => $p->positionDate,
                    'fetched_at' => $fetched,
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return count($positions);
    }

    /**
     * Each given ISIN's current short position: its issuer's row in the
     * latest snapshot, joined on `instrument.lei` (LEI only — never name
     * matching). Share classes of one issuer (same LEI) each get that
     * issuer's position. An ISIN with no resolved LEI, or whose issuer is
     * absent from the latest snapshot, is simply missing from the result —
     * an older snapshot row never counts.
     *
     * @param list<string> $isins
     *
     * @return array<string, array{pct: float, position_date: string}> keyed by ISIN
     */
    public function currentForIsins(array $isins): array
    {
        $isins = array_values(array_unique(array_filter($isins, 'is_string')));
        if ($isins === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($isins), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT i.isin, sp.position_pct, sp.position_date
               FROM instrument i
               JOIN short_position sp ON sp.lei = i.lei
              WHERE i.isin IN ($placeholders)
                AND sp.snapshot_date = (SELECT MAX(snapshot_date) FROM short_position)"
        );
        $stmt->execute($isins);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['isin']] = [
                'pct' => (float) $row['position_pct'],
                'position_date' => (string) $row['position_date'],
            ];
        }

        return $out;
    }

    /** The latest snapshot date, or null before the first successful fetch. */
    public function latestSnapshotDate(): ?string
    {
        $value = $this->pdo->query('SELECT MAX(snapshot_date) FROM short_position')->fetchColumn();

        return $value === null || $value === false ? null : (string) $value;
    }
}
