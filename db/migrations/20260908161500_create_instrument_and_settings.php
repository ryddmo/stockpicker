<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Story 1.2 — the first schema: the instrument dimension table and the
 * settings key/value table, plus the five canonical settings keys the
 * pipeline reads every run (AD-8). Fact, queue and run-log tables land with
 * their own stories (1.6–1.8).
 */
final class CreateInstrumentAndSettings extends AbstractMigration
{
    public function up(): void
    {
        $this->table('instrument', ['id' => false, 'primary_key' => ['isin']])
            ->addColumn('isin', 'string', ['limit' => 12, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('list', 'string', ['limit' => 20, 'null' => false, 'comment' => 'LC | MC | SC | First North'])
            ->addColumn('avanza_orderbook_id', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('nordnet_instrument_id', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('first_seen', 'date', ['null' => false])
            ->addColumn('last_seen', 'date', ['null' => true])
            ->create();

        $this->table('settings', ['id' => false, 'primary_key' => ['key']])
            ->addColumn('key', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('value', 'string', ['limit' => 255, 'null' => false])
            ->create();

        $this->table('settings')->insert([
            ['key' => 'run_after', 'value' => '18:30'],
            ['key' => 'batch_size', 'value' => '25'],
            ['key' => 'rate.avanza', 'value' => '0.5'],
            ['key' => 'rate.nordnet', 'value' => '0.5'],
            ['key' => 'queue.stale_after', 'value' => '900'],
        ])->saveData();
    }

    public function down(): void
    {
        $this->table('settings')->drop()->save();
        $this->table('instrument')->drop()->save();
    }
}
