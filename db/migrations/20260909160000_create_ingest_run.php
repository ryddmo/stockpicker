<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Story 1.8 — the minimal nightly run log (AD-11, FR13 minimal). One appended
 * row per pipeline run: a night is one `enqueue` row plus one `fetch` row per
 * `/cron/work` slice. Written only by `RunRepository`, never updated, never
 * pruned in this story.
 *
 * `run_date` is a deliberate addition beyond the epic's column list — it is the
 * correlation key back to `work_queue` and the natural filter for
 * `bin/show-runs.php`. `started_at` / `finished_at` are naive `DATETIME`s
 * understood as UTC — same convention as `owner_count_daily.fetched_at` and
 * `work_queue.claimed_at`. No FK.
 */
final class CreateIngestRun extends AbstractMigration
{
    public function up(): void
    {
        $this->table('ingest_run', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('run_type', 'string', ['limit' => 16, 'null' => false, 'comment' => 'fetch | enqueue (Epic 1); universe_sync (Epic 2)'])
            ->addColumn('run_date', 'date', ['null' => false])
            ->addColumn('started_at', 'datetime', ['null' => false, 'comment' => 'UTC'])
            ->addColumn('finished_at', 'datetime', ['null' => false, 'comment' => 'UTC'])
            ->addColumn('instrument_count', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('ok_count', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('fail_count', 'integer', ['signed' => false, 'null' => false])
            ->addIndex(['run_date'], ['name' => 'ix_ingest_run_run_date'])
            ->create();
    }

    public function down(): void
    {
        $this->table('ingest_run')->drop()->save();
    }
}
