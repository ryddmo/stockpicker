<?php

declare(strict_types=1);

namespace Stockpicker\Store;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Access to the `instrument` dimension table. In Epic 1 the only writer is
 * bin/seed-instruments.php via upsertSeed(); Epic 2's UniverseSync takes over
 * (AD-3). FetchRunner and adapters read only.
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
