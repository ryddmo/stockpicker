<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Story 1.7 — the nightly fetch work queue. One job per (isin, run_date);
 * the state machine is `pending -> claimed -> done | failed` (AD-5). Only
 * `Enqueue` creates `pending`; only `FetchRunner` makes the other transitions
 * (by convention and repository method names, not triggers).
 *
 * `claimed_at` is a UTC timestamp and doubles as the staleness clock: a
 * `claimed` row older than `settings.queue.stale_after` seconds is reopened to
 * `pending` at the start of the next slice.
 */
final class CreateWorkQueue extends AbstractMigration
{
    public function up(): void
    {
        $this->table('work_queue', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('isin', 'string', ['limit' => 12, 'null' => false])
            ->addColumn('status', 'string', ['limit' => 16, 'null' => false, 'default' => 'pending', 'comment' => 'pending | claimed | done | failed'])
            ->addColumn('run_date', 'date', ['null' => false])
            ->addColumn('claimed_at', 'datetime', ['null' => true, 'comment' => 'UTC; also the staleness clock'])
            ->addIndex(['isin', 'run_date'], ['unique' => true, 'name' => 'uq_work_queue_isin_run_date'])
            ->addIndex(['status', 'run_date'], ['name' => 'ix_work_queue_status_run_date'])
            ->addForeignKey('isin', 'instrument', 'isin', ['delete' => 'RESTRICT', 'update' => 'RESTRICT'])
            ->create();
    }

    public function down(): void
    {
        $this->table('work_queue')->drop()->save();
    }
}
