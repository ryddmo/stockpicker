<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Story 1.6 — the append-only owner-count time series. One row per
 * (isin, source, as_of_date); a recorded row is never overwritten (AD-4).
 * `as_of_date` is a Europe/Stockholm calendar date; `fetched_at` is UTC.
 */
final class CreateOwnerCountDaily extends AbstractMigration
{
    public function up(): void
    {
        $this->table('owner_count_daily', [
            'id' => false,
            'primary_key' => ['isin', 'source', 'as_of_date'],
        ])
            ->addColumn('isin', 'string', ['limit' => 12, 'null' => false])
            ->addColumn('source', 'string', ['limit' => 16, 'null' => false, 'comment' => 'avanza | nordnet'])
            ->addColumn('as_of_date', 'date', ['null' => false])
            ->addColumn('number_of_owners', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('last_price', 'decimal', ['precision' => 18, 'scale' => 4, 'null' => true])
            ->addColumn('market_cap', 'decimal', ['precision' => 24, 'scale' => 2, 'null' => true])
            ->addColumn('fetched_at', 'datetime', ['null' => false, 'comment' => 'UTC'])
            ->addForeignKey('isin', 'instrument', 'isin', ['delete' => 'RESTRICT', 'update' => 'RESTRICT'])
            ->create();
    }

    public function down(): void
    {
        $this->table('owner_count_daily')->drop()->save();
    }
}
