<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Store;

use PDOException;
use Stockpicker\Store\WatchlistRepository;

/**
 * Story 4.2 — WatchlistRepository is the first non-pipeline writer in the
 * system (AD-15). Covers toggle on/off, starredIsins() reflecting state, and
 * the FK-violation behavior for an unknown isin (the /watchlist/toggle route
 * is expected to check existence itself and never let this exception
 * surface — see WatchlistRepository::toggle()'s docblock).
 */
final class WatchlistRepositoryTest extends StoreTestCase
{
    private const ISIN = 'SE0015811963';
    private const OTHER_ISIN = 'SE0000108656';

    private WatchlistRepository $watchlist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo->exec(
            "INSERT INTO instrument (isin, name, list, first_seen)
             VALUES ('" . self::ISIN . "', 'Investor B', 'LC', '2026-01-01')"
        );
        $this->pdo->exec(
            "INSERT INTO instrument (isin, name, list, first_seen)
             VALUES ('" . self::OTHER_ISIN . "', 'Atlas Copco A', 'LC', '2026-01-01')"
        );

        $this->watchlist = new WatchlistRepository($this->pdo);
    }

    public function testStarredIsinsIsEmptyBeforeAnyToggle(): void
    {
        self::assertSame([], $this->watchlist->starredIsins());
    }

    public function testTogglingAnUnstarredIsinStarsItAndReturnsTrue(): void
    {
        self::assertTrue($this->watchlist->toggle(self::ISIN));
        self::assertSame([self::ISIN], $this->watchlist->starredIsins());
    }

    public function testTogglingAnAlreadyStarredIsinUnstarsItAndReturnsFalse(): void
    {
        $this->watchlist->toggle(self::ISIN);

        self::assertFalse($this->watchlist->toggle(self::ISIN));
        self::assertSame([], $this->watchlist->starredIsins());
    }

    public function testTogglingTwiceMoreReturnsToTheStarredState(): void
    {
        self::assertTrue($this->watchlist->toggle(self::ISIN));
        self::assertFalse($this->watchlist->toggle(self::ISIN));
        self::assertTrue($this->watchlist->toggle(self::ISIN));
        self::assertSame([self::ISIN], $this->watchlist->starredIsins());
    }

    public function testStarredIsinsReflectsMultipleStarredInstrumentsOrderedByIsin(): void
    {
        $this->watchlist->toggle(self::ISIN);
        $this->watchlist->toggle(self::OTHER_ISIN);

        $expected = [self::ISIN, self::OTHER_ISIN];
        sort($expected);

        self::assertSame($expected, $this->watchlist->starredIsins());
    }

    public function testTogglingAnUnknownIsinThrowsForeignKeyViolation(): void
    {
        $this->expectException(PDOException::class);

        $this->watchlist->toggle('SE0000000000');
    }

    public function testTogglingADelistedInstrumentSucceeds(): void
    {
        // The migration's FK is deliberately not scoped to active instruments
        // — delisting only ever sets instrument.last_seen (NFR7, rows are
        // never deleted), so a delisted isin must still be starrable/
        // unstarrable like any other, never blocked by the watchlist FK.
        $delisted = 'SE0000108227';
        $this->pdo->exec(
            "INSERT INTO instrument (isin, name, list, first_seen, last_seen)
             VALUES ('{$delisted}', 'Delisted AB', 'LC', '2026-01-01', '2026-02-01')"
        );

        self::assertTrue($this->watchlist->toggle($delisted));
        self::assertSame([$delisted], $this->watchlist->starredIsins());
    }
}
