<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * spec-short-interest-data — Finansinspektionen's aggregated short
 * positions (blankningsregistret), mapped to instruments by LEI.
 *
 *  - `instrument.lei`: the issuer's LEI, a write-once cached attribute
 *    resolved via GLEIF by ISIN. Written only by UniverseSync (AD-3), NULL
 *    until resolved. Share classes of one issuer share one LEI.
 *  - `short_position`: one row per (snapshot_date, lei) — `snapshot_date` is
 *    the Stockholm run date of the nightly FI fetch. Written only by
 *    ShortPositionSync (AD-3), upserted (AD-4). The "current" position is
 *    the rows of MAX(snapshot_date) only: FI's file lists only issuers that
 *    currently have a reported position, so an issuer missing from the
 *    latest snapshot has none — an older row never counts.
 */
final class CreateShortPosition extends AbstractMigration
{
    public function up(): void
    {
        $this->table('instrument')
            ->addColumn('lei', 'string', ['limit' => 20, 'null' => true, 'after' => 'nordnet_instrument_id'])
            ->update();

        $this->table('short_position', ['id' => false, 'primary_key' => ['snapshot_date', 'lei']])
            ->addColumn('snapshot_date', 'date', ['null' => false, 'comment' => 'Europe/Stockholm run date of the fetch'])
            ->addColumn('lei', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('issuer_name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('position_pct', 'decimal', ['precision' => 6, 'scale' => 2, 'null' => false])
            ->addColumn('position_date', 'date', ['null' => false])
            ->addColumn('fetched_at', 'datetime', ['null' => false, 'comment' => 'UTC'])
            ->addIndex(['lei'], ['name' => 'ix_short_position_lei'])
            ->create();
    }

    public function down(): void
    {
        $this->table('short_position')->drop()->save();
        $this->table('instrument')->removeColumn('lei')->update();
    }
}
