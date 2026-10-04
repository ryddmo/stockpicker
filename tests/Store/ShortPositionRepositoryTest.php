<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Store;

use DateTimeImmutable;
use DateTimeZone;
use Stockpicker\Adapter\ShortPosition;
use Stockpicker\Store\InstrumentRepository;
use Stockpicker\Store\ShortPositionRepository;

/** spec-short-interest-data — snapshot upsert and the current-position read. */
final class ShortPositionRepositoryTest extends StoreTestCase
{
    private const ELEKTA_LEI = '54930044O54BK617EP80';
    private const OTHER_LEI = '549300ABCDEFGHIJ1234';

    private ShortPositionRepository $repo;
    private InstrumentRepository $instruments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new ShortPositionRepository($this->pdo, self::now());
        $this->instruments = new InstrumentRepository($this->pdo);
    }

    /** The staleness clock, pinned so the fixed 2026-10-0x snapshots stay current. */
    private static function now(string $date = '2026-10-03'): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 12:00:00', new DateTimeZone('Europe/Stockholm'));
    }

    private function fetchedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-03 18:00:00', new DateTimeZone('UTC'));
    }

    private function instrument(string $isin, string $name, ?string $lei): void
    {
        $this->instruments->insert($isin, $name, 'LC', '2026-01-01');
        if ($lei !== null) {
            $this->instruments->cacheLei($isin, $lei);
        }
    }

    public function testUpsertWritesOneRowPerLeiAndIsIdempotent(): void
    {
        $rows = [
            new ShortPosition(self::ELEKTA_LEI, 'Elekta AB (publ)', 16.05, '2026-10-02'),
            new ShortPosition(self::OTHER_LEI, 'Other AB', 1.61, '2026-09-30'),
        ];

        self::assertSame(2, $this->repo->upsertSnapshot('2026-10-03', $rows, $this->fetchedAt()));
        $first = $this->pdo->query('SELECT * FROM short_position ORDER BY lei')->fetchAll();
        self::assertSame(2, $this->repo->upsertSnapshot('2026-10-03', $rows, $this->fetchedAt()));
        $second = $this->pdo->query('SELECT * FROM short_position ORDER BY lei')->fetchAll();

        self::assertSame($first, $second);
        self::assertCount(2, $second);
        $elekta = $second[0];
        self::assertSame('2026-10-03', $elekta['snapshot_date']);
        self::assertSame(self::ELEKTA_LEI, $elekta['lei']);
        self::assertSame('16.05', (string) $elekta['position_pct']);
        self::assertSame('2026-10-02', $elekta['position_date']);
        self::assertSame('2026-10-03 18:00:00', $elekta['fetched_at']);
        self::assertSame('2026-10-03', $this->repo->latestSnapshotDate());
    }

    public function testSameDayRewriteReplacesTheSnapshotSoADroppedIssuerIsNoLongerCurrent(): void
    {
        $this->instrument('SE0000163628', 'Elekta B', self::ELEKTA_LEI);
        $this->instrument('SE0000000002', 'Other', self::OTHER_LEI);
        $this->repo->upsertSnapshot('2026-10-03', [
            new ShortPosition(self::ELEKTA_LEI, 'Elekta AB (publ)', 16.05, '2026-10-02'),
            new ShortPosition(self::OTHER_LEI, 'Other AB', 2.0, '2026-10-02'),
        ], $this->fetchedAt());
        $this->repo->upsertSnapshot('2026-10-03', [
            new ShortPosition(self::OTHER_LEI, 'Other AB', 2.0, '2026-10-02'),
        ], $this->fetchedAt());

        self::assertSame(
            ['SE0000000002' => ['pct' => 2.0, 'position_date' => '2026-10-02']],
            $this->repo->currentForIsins(['SE0000163628', 'SE0000000002']),
        );
    }

    public function testCurrentForIsinsReturnsTheLatestSnapshotPercentage(): void
    {
        $this->instrument('SE0000163628', 'Elekta B', self::ELEKTA_LEI);
        $this->repo->upsertSnapshot('2026-10-02', [new ShortPosition(self::ELEKTA_LEI, 'Elekta AB (publ)', 15.10, '2026-10-01')], $this->fetchedAt());
        $this->repo->upsertSnapshot('2026-10-03', [new ShortPosition(self::ELEKTA_LEI, 'Elekta AB (publ)', 16.05, '2026-10-02')], $this->fetchedAt());

        self::assertSame(
            ['SE0000163628' => ['pct' => 16.05, 'position_date' => '2026-10-02']],
            $this->repo->currentForIsins(['SE0000163628']),
        );
    }

    public function testIssuerDroppedFromTheLatestSnapshotHasNoCurrentPosition(): void
    {
        $this->instrument('SE0000163628', 'Elekta B', self::ELEKTA_LEI);
        $this->instrument('SE0000000002', 'Other', self::OTHER_LEI);
        $this->repo->upsertSnapshot('2026-10-02', [
            new ShortPosition(self::ELEKTA_LEI, 'Elekta AB (publ)', 15.10, '2026-10-01'),
            new ShortPosition(self::OTHER_LEI, 'Other AB', 2.0, '2026-10-01'),
        ], $this->fetchedAt());
        $this->repo->upsertSnapshot('2026-10-03', [
            new ShortPosition(self::OTHER_LEI, 'Other AB', 2.5, '2026-10-02'),
        ], $this->fetchedAt());

        self::assertSame(
            ['SE0000000002' => ['pct' => 2.5, 'position_date' => '2026-10-02']],
            $this->repo->currentForIsins(['SE0000163628', 'SE0000000002']),
        );
    }

    public function testShareClassesWithTheSameLeiBothGetTheIssuersPosition(): void
    {
        $this->instrument('SE0000000010', 'Issuer A', self::ELEKTA_LEI);
        $this->instrument('SE0000000011', 'Issuer B', self::ELEKTA_LEI);
        $this->repo->upsertSnapshot('2026-10-03', [new ShortPosition(self::ELEKTA_LEI, 'Issuer AB', 5.5, '2026-10-02')], $this->fetchedAt());

        $current = $this->repo->currentForIsins(['SE0000000010', 'SE0000000011']);

        self::assertSame(5.5, $current['SE0000000010']['pct']);
        self::assertSame(5.5, $current['SE0000000011']['pct']);
    }

    public function testIsinWithoutLeiOrUnknownIsinIsAbsentAndEmptyInputIsEmpty(): void
    {
        $this->instrument('SE0000000020', 'No Lei', null);
        $this->repo->upsertSnapshot('2026-10-03', [new ShortPosition(self::ELEKTA_LEI, 'Elekta AB (publ)', 16.05, '2026-10-02')], $this->fetchedAt());

        self::assertSame([], $this->repo->currentForIsins(['SE0000000020', 'SE9999999999']));
        self::assertSame([], $this->repo->currentForIsins([]));
    }

    public function testNoSnapshotYetMeansNoCurrentPositions(): void
    {
        $this->instrument('SE0000163628', 'Elekta B', self::ELEKTA_LEI);

        self::assertNull($this->repo->latestSnapshotDate());
        self::assertSame([], $this->repo->currentForIsins(['SE0000163628']));
    }

    public function testCacheLeiIsWriteOnce(): void
    {
        $this->instrument('SE0000163628', 'Elekta B', self::ELEKTA_LEI);
        $this->instruments->cacheLei('SE0000163628', self::OTHER_LEI);

        self::assertSame(self::ELEKTA_LEI, $this->instruments->get('SE0000163628')?->lei);
    }

    public function testCurrentForIsinsIgnoresALatestSnapshotOlderThanSevenDays(): void
    {
        $this->instrument('SE0000163628', 'Elekta B', self::ELEKTA_LEI);
        $this->repo->upsertSnapshot('2026-10-03', [new ShortPosition(self::ELEKTA_LEI, 'Elekta AB (publ)', 16.05, '2026-10-02')], $this->fetchedAt());

        self::assertSame(
            ['SE0000163628' => ['pct' => 16.05, 'position_date' => '2026-10-02']],
            (new ShortPositionRepository($this->pdo, self::now('2026-10-10')))->currentForIsins(['SE0000163628']),
            'exactly 7 days old is still current',
        );
        self::assertSame([], (new ShortPositionRepository($this->pdo, self::now('2026-10-11')))->currentForIsins(['SE0000163628']), '8 days old is stale');
        self::assertSame('2026-10-11', ShortPositionRepository::freshSince(new DateTimeImmutable('2026-10-18 00:30:00', new DateTimeZone('Europe/Stockholm'))));
        self::assertSame('2026-10-11', ShortPositionRepository::freshSince(new DateTimeImmutable('2026-10-17 22:30:00', new DateTimeZone('UTC'))), 'today is the Stockholm date');
    }
}
