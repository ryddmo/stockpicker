<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Story 4.2 — the `watchlist` table: Stefan's personal "worth following" list.
 * A row's mere existence means "starred"; there is no boolean column
 * (`Store\WatchlistRepository::toggle()` inserts/deletes accordingly). This
 * is the first non-pipeline write path in the system (AD-15) — everything
 * before this migration was written only by the nightly pipeline or
 * UniverseSync (AD-3); `/watchlist/toggle` is a human clicking a star.
 *
 * Same FK-with-RESTRICT idiom as owner_count_daily
 * (20260909140000_create_owner_count_daily.php): an isin can never be starred
 * unless it already exists in `instrument`, and `instrument` rows are never
 * deleted (delisting only sets last_seen — NFR7), so this FK can never block
 * a legitimate delist.
 */
final class CreateWatchlist extends AbstractMigration
{
    public function up(): void
    {
        $this->table('watchlist', ['id' => false, 'primary_key' => ['isin']])
            ->addColumn('isin', 'string', ['limit' => 12, 'null' => false])
            ->addColumn('starred_at', 'datetime', ['null' => false, 'comment' => 'UTC'])
            ->addForeignKey('isin', 'instrument', 'isin', ['delete' => 'RESTRICT', 'update' => 'RESTRICT'])
            ->create();
    }

    public function down(): void
    {
        $this->table('watchlist')->drop()->save();
    }
}
