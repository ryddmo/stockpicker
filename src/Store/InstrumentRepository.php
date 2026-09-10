<?php

declare(strict_types=1);

namespace Stockpicker\Store;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Access to the `instrument` dimension table. In Epic 1 the only writer is
 * bin/seed-instruments.php via upsertSeed(); Epic 2's UniverseSync takes over
 * the nightly path (AD-3) via insert() / updateListAndName() / markInactive() /
 * reactivate() / setAvanzaId() and the write-once id caches. FetchRunner and
 * adapters read only.
 */
final class InstrumentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, Instrument> keyed by ISIN, ordered by ISIN
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT * FROM instrument ORDER BY isin') as $row) {
            $out[(string) $row['isin']] = Instrument::fromRow($row);
        }

        return $out;
    }

    /**
     * The active universe — every row with `last_seen IS NULL` (AD: active =
     * not delisted). `Enqueue` and `UniverseSync`'s id-resolution pass both
     * read this.
     *
     * @return array<string, Instrument> keyed by ISIN, ordered by ISIN
     */
    public function allActive(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT * FROM instrument WHERE last_seen IS NULL ORDER BY isin') as $row) {
            $out[(string) $row['isin']] = Instrument::fromRow($row);
        }

        return $out;
    }

    public function get(string $isin): ?Instrument
    {
        $stmt = $this->pdo->prepare('SELECT * FROM instrument WHERE isin = :isin');
        $stmt->execute(['isin' => $isin]);
        $row = $stmt->fetch();

        return $row === false ? null : Instrument::fromRow($row);
    }

    /**
     * Insert a seed row, or refresh only its name/list if the ISIN already
     * exists. first_seen is set once, on insert, to today in Europe/Stockholm.
     * The cached id columns and first_seen are never touched here.
     */
    public function upsertSeed(string $isin, string $name, string $list): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO instrument (isin, name, list, first_seen)
             VALUES (:isin, :name, :list, :first_seen)
             ON DUPLICATE KEY UPDATE name = VALUES(name), list = VALUES(list)'
        );

        $stmt->execute([
            'isin' => $isin,
            'name' => $name,
            'list' => $list,
            'first_seen' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d'),
        ]);
    }

    /**
     * Insert a newly listed instrument (UniverseSync, nightly path). `first_seen`
     * is the run date, passed in; `last_seen` and both id caches start NULL.
     *
     * A **no-op on a duplicate PK** (`ON DUPLICATE KEY UPDATE isin = isin`): a
     * within-run duplicate ISIN (two listing entries resolving to one ISIN) or a
     * concurrent `bin/universe-sync.php` + hourly `/cron/refill` must never raise
     * an uncaught PDOException.
     */
    public function insert(string $isin, string $name, string $list, string $firstSeen): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO instrument (isin, name, list, first_seen)
             VALUES (:isin, :name, :list, :first_seen)
             ON DUPLICATE KEY UPDATE isin = isin'
        );
        $stmt->execute([
            'isin' => $isin,
            'name' => $name,
            'list' => $list,
            'first_seen' => $firstSeen,
        ]);
    }

    /**
     * Follow a list move (LC↔MC↔SC↔First North) or a name change — `name` is the
     * Nordnet search string, so it is kept in step with the listing.
     */
    public function updateListAndName(string $isin, string $list, string $name): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE instrument SET list = :list, name = :name WHERE isin = :isin'
        );
        $stmt->execute(['list' => $list, 'name' => $name, 'isin' => $isin]);
    }

    /**
     * Delist: set `last_seen` (never delete — NFR7 keeps history). Guarded on
     * `last_seen IS NULL` so a re-run never moves an already-set date.
     */
    public function markInactive(string $isin, string $lastSeen): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE instrument SET last_seen = :last_seen WHERE isin = :isin AND last_seen IS NULL'
        );
        $stmt->execute(['last_seen' => $lastSeen, 'isin' => $isin]);
    }

    /**
     * Reactivate a delisted instrument whose orderbook id reappeared in the
     * listing: `last_seen` back to NULL. `first_seen` is deliberately untouched.
     */
    public function reactivate(string $isin): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE instrument SET last_seen = NULL WHERE isin = :isin AND last_seen IS NOT NULL'
        );
        $stmt->execute(['isin' => $isin]);
    }

    /**
     * **Unconditional** setter for the Avanza orderbook id — unlike the
     * write-once cacheAvanzaId(). Used only for the orderbookId-rotation case in
     * UniverseSync's walk (same resolved ISIN, a new orderbook id in the
     * listing), where the existing non-null id must be replaced.
     */
    public function setAvanzaId(string $isin, string $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE instrument SET avanza_orderbook_id = :id WHERE isin = :isin'
        );
        $stmt->execute(['id' => $id, 'isin' => $isin]);
    }

    /**
     * Write-once cache of the Avanza orderbook id: set it only when the column
     * is still NULL, never overwrite a resolved id. Used by bin/resolve-ids.php
     * (the scoped, interim second writer of `instrument` for Epic 1 — AD-3
     * exception; Epic 2's UniverseSync takes over the whole row).
     */
    public function cacheAvanzaId(string $isin, string $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE instrument SET avanza_orderbook_id = :id WHERE isin = :isin AND avanza_orderbook_id IS NULL'
        );
        $stmt->execute(['id' => $id, 'isin' => $isin]);
    }

    /**
     * Write-once cache of the Nordnet instrument id — see cacheAvanzaId().
     */
    public function cacheNordnetId(string $isin, string $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE instrument SET nordnet_instrument_id = :id WHERE isin = :isin AND nordnet_instrument_id IS NULL'
        );
        $stmt->execute(['id' => $id, 'isin' => $isin]);
    }
}
