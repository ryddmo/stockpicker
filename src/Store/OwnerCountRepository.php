<?php

declare(strict_types=1);

namespace Stockpicker\Store;

use DateTimeZone;
use PDO;
use Stockpicker\Adapter\NormalizedRow;

/**
 * The only writer of `owner_count_daily` (AD-4): append-only, idempotent on
 * (isin, source, as_of_date). A recorded row is never modified — the first
 * observation of a calendar day is that day's record.
 */
final class OwnerCountRepository
{
    private const STOCKHOLM = 'Europe/Stockholm';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Store one datapoint, first-write-wins. Returns true when a new row was
     * written, false when (isin, source, as_of_date) already existed — in which
     * case the stored row is left exactly as it was (this method never updates
     * an existing row; the name follows the architecture spine's `upsert`).
     *
     * @throws \PDOException on a foreign-key violation when `$row->isin` is not
     *                       in `instrument` (fail loud — the caller decides).
     */
    public function upsert(NormalizedRow $row): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO owner_count_daily
                 (isin, source, as_of_date, number_of_owners, last_price, market_cap, fetched_at)
             VALUES (:isin, :source, :as_of_date, :owners, :last_price, :market_cap, :fetched_at)
             ON DUPLICATE KEY UPDATE fetched_at = fetched_at'
        );

        $stmt->execute([
            'isin' => $row->isin,
            'source' => $row->source,
            'as_of_date' => $this->asOfDate($row),
            'owners' => $row->numberOfOwners,
            // Format the DECIMAL columns as fixed-point strings: casting a float
            // to string uses PHP's `precision` ini (14 sig digits), which
            // truncates trillion-scale market caps in the last decimal place.
            'last_price' => $row->lastPrice === null ? null : number_format($row->lastPrice, 4, '.', ''),
            'market_cap' => $row->marketCap === null ? null : number_format($row->marketCap, 2, '.', ''),
            'fetched_at' => $row->fetchedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);

        // INSERT -> 1 affected row; the no-op ON DUPLICATE KEY UPDATE -> 0
        // (relies on the driver's default FOUND_ROWS=off — see Database).
        return $stmt->rowCount() === 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $isin, string $source, string $asOfDate): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM owner_count_daily WHERE isin = :isin AND source = :source AND as_of_date = :as_of_date'
        );
        $stmt->execute(['isin' => $isin, 'source' => $source, 'as_of_date' => $asOfDate]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function countForIsin(string $isin): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM owner_count_daily WHERE isin = :isin');
        $stmt->execute(['isin' => $isin]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The single Europe/Stockholm calendar conversion (Story 1.1 Design Notes):
     * from the source's own timestamp when it has one (Nordnet), else the
     * fetch time (Avanza has no per-datum timestamp).
     */
    public function asOfDate(NormalizedRow $row): string
    {
        return ($row->sourceTimestamp ?? $row->fetchedAt)
            ->setTimezone(new DateTimeZone(self::STOCKHOLM))
            ->format('Y-m-d');
    }
}
