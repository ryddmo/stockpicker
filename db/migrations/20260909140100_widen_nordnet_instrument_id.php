<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Story 1.6 — Nordnet's `nnx_instrument_id` is a 36-char UUID (verified in
 * Story 1.3), which does not fit the original VARCHAR(32). Widen it before
 * anything persists a Nordnet id (Epic 2). Closes the deferred-work item.
 */
final class WidenNordnetInstrumentId extends AbstractMigration
{
    public function up(): void
    {
        $this->table('instrument')
            ->changeColumn('nordnet_instrument_id', 'string', ['limit' => 64, 'null' => true])
            ->update();
    }

    public function down(): void
    {
        // WARNING: if any nordnet_instrument_id longer than 32 chars has been
        // stored (the whole reason for widening), this narrowing errors in
        // strict mode / truncates otherwise. Clear or shorten those values
        // before rolling back.
        $this->table('instrument')
            ->changeColumn('nordnet_instrument_id', 'string', ['limit' => 32, 'null' => true])
            ->update();
    }
}
