<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Store;

use DateTimeImmutable;
use DateTimeZone;
use Stockpicker\Adapter\NormalizedRow;
use Stockpicker\Store\OwnerCountRepository;

final class OwnerCountRepositoryTest extends StoreTestCase
{
    private const ISIN = 'SE0015811963';

    private function repo(): OwnerCountRepository
    {
        $this->pdo->exec(
            "INSERT IGNORE INTO instrument (isin, name, list, first_seen)
             VALUES ('" . self::ISIN . "', 'Investor B', 'LC', '2026-01-01')"
        );

        return new OwnerCountRepository($this->pdo);
    }

    private function utc(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    private function row(
        int $owners = 500000,
        ?float $lastPrice = 402.20,
        ?float $marketCap = 1230000000000.0,
        ?DateTimeImmutable $sourceTimestamp = null,
        string $fetchedAt = '2026-06-15T09:00:00Z',
        string $source = NormalizedRow::SOURCE_AVANZA,
    ): NormalizedRow {
        return new NormalizedRow(
            self::ISIN,
            $source,
            $owners,
            $lastPrice,
            $marketCap,
            $sourceTimestamp,
            $this->utc($fetchedAt),
        );
    }

    public function testFirstWriteInsertsAndReturnsTrue(): void
    {
        $repo = $this->repo();

        self::assertTrue($repo->upsert($this->row(owners: 500000)));

        $stored = $repo->get(self::ISIN, 'avanza', '2026-06-15');
        self::assertNotNull($stored);
        self::assertSame(500000, (int) $stored['number_of_owners']);
        self::assertSame(402.20, (float) $stored['last_price']);
        self::assertSame('2026-06-15 09:00:00', $stored['fetched_at']);
    }

    public function testReWriteWithSameDataReturnsFalseAndChangesNothing(): void
    {
        $repo = $this->repo();
        $repo->upsert($this->row(owners: 500000));

        self::assertFalse($repo->upsert($this->row(owners: 500000)));
        self::assertSame(1, $repo->countForIsin(self::ISIN));
    }

    public function testReWriteWithDifferentOwnersDoesNotOverwrite(): void
    {
        $repo = $this->repo();
        $repo->upsert($this->row(owners: 500000, fetchedAt: '2026-06-15T09:00:00Z'));

        self::assertFalse($repo->upsert($this->row(owners: 511111, fetchedAt: '2026-06-15T20:00:00Z')));

        $stored = $repo->get(self::ISIN, 'avanza', '2026-06-15');
        self::assertSame(500000, (int) $stored['number_of_owners'], 'first observation is kept');
        self::assertSame('2026-06-15 09:00:00', $stored['fetched_at'], 'fetched_at not overwritten');
        self::assertSame(1, $repo->countForIsin(self::ISIN));
    }

    public function testAsOfDateComesFromSourceTimestampAndCrossesMidnightInStockholm(): void
    {
        $repo = $this->repo();

        // 2026-01-01 23:30 UTC -> Stockholm is +01:00 in winter -> 2026-01-02 00:30
        $repo->upsert($this->row(
            source: NormalizedRow::SOURCE_NORDNET,
            sourceTimestamp: $this->utc('2026-01-01T23:30:00Z'),
            fetchedAt: '2026-01-02T05:00:00Z',
        ));

        self::assertNotNull($repo->get(self::ISIN, 'nordnet', '2026-01-02'));
        self::assertNull($repo->get(self::ISIN, 'nordnet', '2026-01-01'));
    }

    public function testAsOfDateFallsBackToFetchedAtWhenNoSourceTimestamp(): void
    {
        $repo = $this->repo();

        $repo->upsert($this->row(sourceTimestamp: null, fetchedAt: '2026-06-15T21:00:00Z'));

        // Summer: Stockholm +02:00 -> 2026-06-15 23:00, still the 15th.
        self::assertNotNull($repo->get(self::ISIN, 'avanza', '2026-06-15'));
    }

    public function testBothSourcesForTheSameDayAreStoredSeparately(): void
    {
        $repo = $this->repo();

        self::assertTrue($repo->upsert($this->row(source: NormalizedRow::SOURCE_AVANZA)));
        self::assertTrue($repo->upsert($this->row(
            source: NormalizedRow::SOURCE_NORDNET,
            sourceTimestamp: $this->utc('2026-06-15T12:00:00Z'),
        )));

        self::assertSame(2, $repo->countForIsin(self::ISIN));
    }

    public function testNullPriceAndMarketCapAreStoredAsNull(): void
    {
        $repo = $this->repo();

        $repo->upsert($this->row(lastPrice: null, marketCap: null));

        $stored = $repo->get(self::ISIN, 'avanza', '2026-06-15');
        self::assertNull($stored['last_price']);
        self::assertNull($stored['market_cap']);
    }

    public function testLargeMarketCapAndPrecisePriceRoundTripExactly(): void
    {
        $repo = $this->repo();

        // 15 significant digits — beyond PHP's default float->string precision.
        $repo->upsert($this->row(lastPrice: 1234.5678, marketCap: 1234567890123.45));

        $stored = $repo->get(self::ISIN, 'avanza', '2026-06-15');
        self::assertSame('1234567890123.45', $stored['market_cap']);
        self::assertSame('1234.5678', $stored['last_price']);
    }

    public function testAsOfDateHandlesTheAutumnDstFallBackAcrossMidnight(): void
    {
        $repo = $this->repo();

        // Sweden leaves CEST (+02:00) for CET (+01:00) at 03:00 on 2026-10-25.
        // 2026-10-24 22:30 UTC is still +02:00 -> local 2026-10-25 00:30.
        $repo->upsert($this->row(
            source: NormalizedRow::SOURCE_NORDNET,
            sourceTimestamp: $this->utc('2026-10-24T22:30:00Z'),
        ));
        self::assertNotNull($repo->get(self::ISIN, 'nordnet', '2026-10-25'));

        // 2026-10-25 23:30 UTC is now +01:00 -> local 2026-10-26 00:30.
        $repo->upsert($this->row(
            source: NormalizedRow::SOURCE_AVANZA,
            sourceTimestamp: $this->utc('2026-10-25T23:30:00Z'),
        ));
        self::assertNotNull($repo->get(self::ISIN, 'avanza', '2026-10-26'));
    }

    public function testAsOfDateHelperIsPure(): void
    {
        $repo = $this->repo();

        self::assertSame(
            '2026-03-29',
            $repo->asOfDate($this->row(sourceTimestamp: $this->utc('2026-03-29T10:00:00Z'))),
        );
    }
}
