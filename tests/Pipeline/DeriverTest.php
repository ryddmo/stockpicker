<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Pipeline;

use DateTimeImmutable;
use DateTimeZone;
use Stockpicker\Adapter\NormalizedRow;
use Stockpicker\Pipeline\Deriver;
use Stockpicker\Store\DerivedMetricsRepository;
use Stockpicker\Store\OwnerCountRepository;
use Stockpicker\Tests\Store\StoreTestCase;

/**
 * Deriver is a thin filter — this only guards that it delegates straight to
 * DerivedMetricsRepository::forIsin() and adds nothing of its own.
 */
final class DeriverTest extends StoreTestCase
{
    private const ISIN = 'SE0015811963';

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo->exec(
            "INSERT IGNORE INTO instrument (isin, name, list, first_seen)
             VALUES ('" . self::ISIN . "', 'Investor B', 'LC', '2026-01-01')"
        );
    }

    private function seed(string $asOfDate, int $owners, string $source = NormalizedRow::SOURCE_AVANZA): void
    {
        $row = new NormalizedRow(
            self::ISIN,
            $source,
            $owners,
            null,
            null,
            null,
            new DateTimeImmutable($asOfDate . 'T12:00:00', new DateTimeZone('UTC')),
        );

        (new OwnerCountRepository($this->pdo))->upsert($row, $asOfDate);
    }

    public function testForInstrumentReturnsExactlyWhatTheRepositoryReturns(): void
    {
        $this->seed('2026-01-01', 1000, NormalizedRow::SOURCE_AVANZA);
        $this->seed('2026-01-02', 1010, NormalizedRow::SOURCE_AVANZA);
        $this->seed('2026-01-01', 500, NormalizedRow::SOURCE_NORDNET);

        $repository = new DerivedMetricsRepository($this->pdo);
        $deriver = new Deriver($repository);

        self::assertSame($repository->forIsin(self::ISIN), $deriver->forInstrument(self::ISIN));
    }

    public function testForInstrumentWithNoDataReturnsExactlyWhatTheRepositoryReturns(): void
    {
        $repository = new DerivedMetricsRepository($this->pdo);
        $deriver = new Deriver($repository);

        self::assertSame([], $deriver->forInstrument(self::ISIN));
        self::assertSame($repository->forIsin(self::ISIN), $deriver->forInstrument(self::ISIN));
    }
}
