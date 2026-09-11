<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Story 2.6 — lifecycle details and nullable owner-fact run linkage. */
final class EnrichIngestRun extends AbstractMigration
{
    public function up(): void
    {
        $this->table('ingest_run')
            ->changeColumn('finished_at', 'datetime', ['null' => true, 'comment' => 'UTC; null while running'])
            ->addColumn('status', 'string', ['limit' => 16, 'null' => false, 'default' => 'running'])
            ->addColumn('alarm', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('schema_mismatch_count', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('by_source', 'text', ['null' => true])
            ->update();

        $this->execute("UPDATE `ingest_run` SET `status` = 'completed', `by_source` = '{}' WHERE `by_source` IS NULL");
        $this->table('ingest_run')->changeColumn('by_source', 'text', ['null' => false])->update();

        $this->table('owner_count_daily')
            ->addColumn('ingest_run_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addForeignKey('ingest_run_id', 'ingest_run', 'id', ['delete' => 'SET_NULL', 'update' => 'RESTRICT'])
            ->update();
    }

    public function down(): void
    {
        $this->table('owner_count_daily')->dropForeignKey('ingest_run_id')->removeColumn('ingest_run_id')->update();
        $this->execute("UPDATE `ingest_run` SET `finished_at` = `started_at` WHERE `finished_at` IS NULL");
        $this->table('ingest_run')
            ->removeColumn('by_source')
            ->removeColumn('schema_mismatch_count')
            ->removeColumn('alarm')
            ->removeColumn('status')
            ->changeColumn('finished_at', 'datetime', ['null' => false, 'comment' => 'UTC'])
            ->update();
    }
}