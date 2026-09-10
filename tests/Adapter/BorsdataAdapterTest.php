<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Adapter;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\NullLogger;
use Stockpicker\Adapter\BorsdataAdapter;
use Stockpicker\Adapter\UniverseEntry;
use Stockpicker\Error\SchemaMismatch;
use Stockpicker\Error\Transient;

/**
 * One case per row of the Story 2.1 I/O & Edge-Case Matrix. Fixtures follow the
 * documented Börsdata shapes (github.com/Borsdata-Sweden/API wiki + Swagger):
 * `/v1/markets` -> { markets: [ { id, name, countryId, exchangeName } ] };
 * `/v1/instruments` -> { instruments: [ { insId, name, isin, instrument, marketId } ] };
 * `instrument` type 0 = common shares, 1 = preference shares. The real market
 * ids/names and per-list counts are to be pinned by `bin/show-universe.php`
 * against the live API and recorded in the spec's Implementation Notes.
 */
final class BorsdataAdapterTest extends AdapterTestCase
{
    private const KEY = 'bd-test-key';

    private function adapter(?TestHandler $handler = null): BorsdataAdapter
    {
        $logger = $handler !== null ? new Logger('test', [$handler]) : new NullLogger();

        return new BorsdataAdapter($this->client(), $logger, self::KEY);
    }

    /**
     * @param list<array<string, mixed>> $extra additional / overriding market entries
     *
     * @return array{markets: list<array<string, mixed>>}
     */
    private function markets(array $extra = []): array
    {
        return ['markets' => array_merge([
            ['id' => 1, 'name' => 'First North', 'countryId' => 1, 'exchangeName' => 'First North Stockholm'],
            ['id' => 7, 'name' => 'Large Cap', 'countryId' => 1, 'exchangeName' => 'Nasdaq Stockholm'],
            ['id' => 8, 'name' => 'Mid Cap', 'countryId' => 1, 'exchangeName' => 'Nasdaq Stockholm'],
            ['id' => 9, 'name' => 'Small Cap', 'countryId' => 1, 'exchangeName' => 'Nasdaq Stockholm'],
            // identically named foreign segment — excluded by countryId
            ['id' => 14, 'name' => 'Large Cap', 'countryId' => 2, 'exchangeName' => 'Nasdaq Copenhagen'],
            // Swedish but non-target venues
            ['id' => 30, 'name' => 'Spotlight', 'countryId' => 1, 'exchangeName' => 'Spotlight Stock Market'],
            ['id' => 31, 'name' => 'NGM Main Regulated', 'countryId' => 1, 'exchangeName' => 'NGM'],
        ], $extra)];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function rawInstrument(array $overrides = []): array
    {
        return array_replace([
            'insId' => 1,
            'name' => 'Some Company',
            'isin' => 'SE0000000001',
            'instrument' => 0,
            'marketId' => 7,
        ], $overrides);
    }

    /**
     * @param list<array<string, mixed>> $instruments
     *
     * @return array{instruments: list<array<string, mixed>>}
     */
    private function instruments(array $instruments): array
    {
        return ['instruments' => $instruments];
    }

    // --- Row 1: happy path ------------------------------------------------

    public function testReturnsOneEntryPerTargetMarketInstrumentSortedAndDeduped(): void
    {
        $this->queue([
            $this->json($this->markets()),
            $this->json($this->instruments([
                $this->rawInstrument(['insId' => 1, 'name' => 'Investor B', 'isin' => 'SE0015811963', 'instrument' => 0, 'marketId' => 7]),
                $this->rawInstrument(['insId' => 2, 'name' => 'SBB D', 'isin' => 'SE0011844091', 'instrument' => 1, 'marketId' => 8]),
                $this->rawInstrument(['insId' => 3, 'name' => 'Small Co', 'isin' => 'SE0000000123', 'instrument' => 0, 'marketId' => 9]),
                $this->rawInstrument(['insId' => 4, 'name' => 'FN Co', 'isin' => 'SE0000999999', 'instrument' => 0, 'marketId' => 1]),
                // duplicate ISIN — last wins, still one entry
                $this->rawInstrument(['insId' => 5, 'name' => 'Investor B (dup)', 'isin' => 'SE0015811963', 'instrument' => 0, 'marketId' => 7]),
            ])),
        ]);

        $universe = $this->adapter()->listUniverse();

        self::assertContainsOnlyInstancesOf(UniverseEntry::class, $universe);
        self::assertSame(
            ['SE0000000123', 'SE0000999999', 'SE0011844091', 'SE0015811963'],
            array_map(static fn (UniverseEntry $e): string => $e->isin, $universe),
        );
        self::assertSame(
            [UniverseEntry::LIST_SC, UniverseEntry::LIST_FIRST_NORTH, UniverseEntry::LIST_MC, UniverseEntry::LIST_LC],
            array_map(static fn (UniverseEntry $e): string => $e->list, $universe),
        );
        self::assertSame('Investor B (dup)', $universe[3]->name);
        self::assertCount(4, array_unique(array_map(static fn (UniverseEntry $e): string => $e->isin, $universe)));
        $this->assertQueueDrained();
    }

    // --- Row 2: non-target market --------------------------------------------

    public function testExcludesNonTargetMarketsAndNonEquityTypesSilently(): void
    {
        $handler = new TestHandler();
        $this->queue([
            $this->json($this->markets()),
            $this->json($this->instruments([
                $this->rawInstrument(['isin' => 'SE0015811963', 'instrument' => 0, 'marketId' => 7]),   // kept: LC
                $this->rawInstrument(['isin' => 'SE0000000002', 'instrument' => 0, 'marketId' => 30]),  // Spotlight
                $this->rawInstrument(['isin' => 'SE0000000003', 'instrument' => 0, 'marketId' => 31]),  // NGM
                $this->rawInstrument(['isin' => 'DK0000000004', 'instrument' => 0, 'marketId' => 14]),  // Copenhagen
                $this->rawInstrument(['isin' => 'SE0000000005', 'instrument' => 0, 'marketId' => 999]), // unknown market
                $this->rawInstrument(['isin' => 'SE0000000006', 'instrument' => 2, 'marketId' => 7]),   // index on LC
                $this->rawInstrument(['isin' => 'SE0000000007', 'instrument' => 6, 'marketId' => 7]),   // currency on LC
            ])),
        ]);

        $universe = $this->adapter($handler)->listUniverse();

        self::assertSame(['SE0015811963'], array_map(static fn (UniverseEntry $e): string => $e->isin, $universe));
        self::assertFalse($handler->hasWarningRecords(), 'silent exclusion must not log');
        $this->assertQueueDrained();
    }

    // --- Row 3: missing field ----------------------------------------------

    public function testRaisesSchemaMismatchWhenAMarketEntryHasNoName(): void
    {
        $handler = new TestHandler();
        $this->queue([
            $this->json(['markets' => [
                ['id' => 7, 'countryId' => 1, 'exchangeName' => 'Nasdaq Stockholm'],
            ]]),
        ]);

        try {
            $this->adapter($handler)->listUniverse();
            self::fail('expected SchemaMismatch');
        } catch (SchemaMismatch $e) {
            self::assertStringContainsString('name', $e->getMessage());
        }

        self::assertTrue($handler->hasWarningRecords());
    }

    public function testRaisesSchemaMismatchWhenInstrumentsKeyIsAbsent(): void
    {
        $this->queue([
            $this->json($this->markets()),
            $this->json(['rows' => []]),
        ]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->listUniverse();
    }

    // --- Row 4: junk single row ------------------------------------------

    public function testDropsSingleInstrumentWithBlankIsinAndKeepsTheRest(): void
    {
        $handler = new TestHandler();
        $this->queue([
            $this->json($this->markets()),
            $this->json($this->instruments([
                $this->rawInstrument(['insId' => 11, 'name' => 'Good Co', 'isin' => 'SE0015811963', 'marketId' => 7]),
                $this->rawInstrument(['insId' => 12, 'name' => 'Null Isin Co', 'isin' => null, 'marketId' => 7]),
                $this->rawInstrument(['insId' => 13, 'name' => 'Empty Isin Co', 'isin' => '', 'marketId' => 8]),
                $this->rawInstrument(['insId' => 14, 'name' => 'Bad Isin Co', 'isin' => 'not-an-isin', 'marketId' => 9]),
                $this->rawInstrument(['insId' => 15, 'name' => '   ', 'isin' => 'SE0000000123', 'marketId' => 1]),
                // all-digit 12-char value: real ISINs never look like this, and PHP would
                // otherwise coerce it to an int array key and break ksort ordering
                $this->rawInstrument(['insId' => 16, 'name' => 'Numeric Isin Co', 'isin' => '123456789012', 'marketId' => 9]),
            ])),
        ]);

        $universe = $this->adapter($handler)->listUniverse();

        self::assertSame(['SE0015811963'], array_map(static fn (UniverseEntry $e): string => $e->isin, $universe));
        self::assertTrue($handler->hasWarningRecords());
        $this->assertQueueDrained();
    }

    // --- Row 5: auth failure ---------------------------------------------

    public function testRaisesSchemaMismatchNamingTheStatusOnAuthFailure(): void
    {
        $this->queue([new Response(401), new Response(401)]);

        try {
            $this->adapter()->listUniverse();
            self::fail('expected SchemaMismatch');
        } catch (SchemaMismatch $e) {
            self::assertStringContainsString('401', $e->getMessage());
        }
    }

    // --- Row 6: rate limited -------------------------------------------

    public function testRetriesOnceAfter429ThenReturnsANormalResult(): void
    {
        $this->queue([
            new Response(429, ['Retry-After' => '1']),
            $this->json($this->markets()),
            $this->json($this->instruments([
                $this->rawInstrument(['isin' => 'SE0015811963', 'marketId' => 7]),
            ])),
        ]);

        $universe = $this->adapter()->listUniverse();

        self::assertSame(['SE0015811963'], array_map(static fn (UniverseEntry $e): string => $e->isin, $universe));
        $this->assertQueueDrained();
    }

    public function testRaisesTransientWhenBothAttemptsFail(): void
    {
        $this->queue([
            new Response(503),
            new ConnectException('timeout', new Request('GET', '/v1/markets')),
        ]);

        $this->expectException(Transient::class);
        $this->adapter()->listUniverse();
    }

    // --- Row 7: empty universe -----------------------------------------

    public function testRaisesSchemaMismatchWhenInstrumentsListIsEmpty(): void
    {
        $handler = new TestHandler();
        $this->queue([
            $this->json($this->markets()),
            $this->json($this->instruments([])),
        ]);

        $this->expectException(SchemaMismatch::class);
        try {
            $this->adapter($handler)->listUniverse();
        } finally {
            self::assertTrue($handler->hasWarningRecords());
        }
    }

    public function testRaisesSchemaMismatchWhenNoInstrumentIsOnATargetMarket(): void
    {
        $this->queue([
            $this->json($this->markets()),
            $this->json($this->instruments([
                $this->rawInstrument(['isin' => 'SE0000000002', 'marketId' => 30]),
                $this->rawInstrument(['isin' => 'SE0000000003', 'marketId' => 31]),
            ])),
        ]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->listUniverse();
    }

    public function testRaisesSchemaMismatchWhenNoTargetMarketCanBeCorrelated(): void
    {
        $this->queue([
            $this->json(['markets' => [
                ['id' => 30, 'name' => 'Spotlight', 'countryId' => 1, 'exchangeName' => 'Spotlight'],
                ['id' => 14, 'name' => 'Large Cap', 'countryId' => 2, 'exchangeName' => 'Nasdaq Copenhagen'],
            ]]),
        ]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->listUniverse();
    }

    public function testRaisesSchemaMismatchWhenOnlyThreeOfTheFourCapTiersMatch(): void
    {
        // "Small Cap" renamed with a suffix the matcher does not recognise: the
        // other three still correlate, but a partial map would silently drop SC.
        $this->queue([
            $this->json(['markets' => [
                ['id' => 1, 'name' => 'First North Stockholm', 'countryId' => 1, 'exchangeName' => 'First North'],
                ['id' => 7, 'name' => 'Large Cap', 'countryId' => 1, 'exchangeName' => 'Nasdaq Stockholm'],
                ['id' => 8, 'name' => 'Mid Cap', 'countryId' => 1, 'exchangeName' => 'Nasdaq Stockholm'],
                ['id' => 9, 'name' => 'Small Cap Stockholm', 'countryId' => 1, 'exchangeName' => 'Nasdaq Stockholm'],
            ]]),
        ]);

        try {
            $this->adapter()->listUniverse();
            self::fail('expected SchemaMismatch');
        } catch (SchemaMismatch $e) {
            self::assertStringContainsString('SC', $e->getMessage());
        }
    }

    public function testDropsNonObjectInstrumentEntriesWithAWarningAndKeepsTheRest(): void
    {
        $handler = new TestHandler();
        $this->queue([
            $this->json($this->markets()),
            $this->json(['instruments' => [
                $this->rawInstrument(['isin' => 'SE0015811963', 'marketId' => 7]),
                'not-an-object',
                42,
                null,
            ]]),
        ]);

        $universe = $this->adapter($handler)->listUniverse();

        self::assertSame(['SE0015811963'], array_map(static fn (UniverseEntry $e): string => $e->isin, $universe));
        self::assertTrue($handler->hasWarningThatContains('non-object entry'));
        $this->assertQueueDrained();
    }
}
