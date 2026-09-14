<?php

declare(strict_types=1);

namespace Stockpicker\Store;

use PDO;

/**
 * The only writer of `watchlist` (AD-15) — the first non-pipeline write path
 * in the system (Story 4.2). A row's mere existence means "starred"; there is
 * no boolean column.
 */
final class WatchlistRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<string> every starred isin, ordered by isin
     */
    public function starredIsins(): array
    {
        $stmt = $this->pdo->query('SELECT isin FROM watchlist ORDER BY isin');
        if ($stmt === false) {
            throw new \RuntimeException('query failed: SELECT isin FROM watchlist ORDER BY isin');
        }

        $out = [];
        foreach ($stmt as $row) {
            $out[] = (string) $row['isin'];
        }

        return $out;
    }

    /**
     * Flips one isin's starred state and returns the new state (true =
     * now starred, false = now unstarred).
     *
     * The check-and-branch runs inside a transaction with `SELECT ... FOR
     * UPDATE` for the existence check: two near-simultaneous calls for the
     * same isin (a double-click, two tabs) would otherwise both read "not
     * starred" and both attempt an INSERT, the second throwing an uncaught
     * PDOException on the duplicate key. `FOR UPDATE` on a non-matching row
     * takes a gap lock, so a second concurrent call blocks until the first
     * commits and then — because a locking read always sees the latest
     * committed data, even under REPEATABLE READ — observes the row the
     * first call just inserted and correctly takes the DELETE branch
     * instead of racing another INSERT.
     *
     * Starring an isin that does not exist in `instrument` fails loud with a
     * PDOException (FK violation on the INSERT) — same "let the caller
     * decide" idiom as OwnerCountRepository::upsert(). The
     * `/watchlist/toggle` route is the caller that must turn this into a
     * friendly 404: it checks existence itself via InstrumentRepository::get()
     * before ever calling toggle(), so this exception path is not expected to
     * be reached over HTTP — it exists so a direct/programmatic misuse of
     * this repository fails loudly rather than silently no-op'ing.
     */
    public function toggle(string $isin): bool
    {
        $this->pdo->beginTransaction();

        try {
            $check = $this->pdo->prepare('SELECT 1 FROM watchlist WHERE isin = :isin FOR UPDATE');
            $check->execute(['isin' => $isin]);

            if ($check->fetchColumn() !== false) {
                $delete = $this->pdo->prepare('DELETE FROM watchlist WHERE isin = :isin');
                $delete->execute(['isin' => $isin]);
                $this->pdo->commit();

                return false;
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO watchlist (isin, starred_at) VALUES (:isin, :starred_at)'
            );
            $insert->execute([
                'isin' => $isin,
                'starred_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->pdo->commit();

            return true;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }
}
