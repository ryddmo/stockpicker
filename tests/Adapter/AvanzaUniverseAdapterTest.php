<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Adapter;

use GuzzleHttp\Psr7\Response;
use Psr\Log\AbstractLogger;
use Stockpicker\Adapter\AvanzaUniverseAdapter;
use Stockpicker\Adapter\UniverseEntry;
use Stockpicker\Error\NotFound;
use Stockpicker\Error\SchemaMismatch;
use Stockpicker\Error\Transient;

final class AvanzaUniverseAdapterTest extends AdapterTestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new RecordingLogger();
    }

    private function adapter(): AvanzaUniverseAdapter
    {
        return new AvanzaUniverseAdapter($this->client(), $this->logger);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function stock(string $orderbookId, string $name, array $overrides = []): array
    {
        return array_replace([
            'orderbookId' => $orderbookId,
            'companyId' => '1234',
            'type' => 'STOCK',
            'name' => $name,
            'shortName' => $name,
            'currency' => 'SEK',
            'countryCode' => 'SE',
            'marketPlaceCode' => 'XSTO',
            'numberOfOwners' => 1000,
            'marketCap' => 1_000_000_000,
        ], $overrides);
    }

    /**
     * @param list<array<string, mixed>> $stocks
     */
    private function screener(array $stocks, ?int $totalNumberOfOrderbooks = null): Response
    {
        return $this->json([
            'stocks' => $stocks,
            'pagination' => ['offset' => 0, 'limit' => 5000],
            'totalNumberOfOrderbooks' => $totalNumberOfOrderbooks ?? count($stocks),
            'filterOptions' => [],
        ]);
    }

    /**
     * Queue one well-formed response per target list (LC, MC, SC, First North).
     *
     * @param list<array<string, mixed>>|null $lc
     * @param list<array<string, mixed>>|null $mc
     * @param list<array<string, mixed>>|null $sc
     * @param list<array<string, mixed>>|null $fn
     */
    private function queueFourLists(?array $lc = null, ?array $mc = null, ?array $sc = null, ?array $fn = null): void
    {
        $this->queue([
            $this->screener($lc ?? [$this->stock('5247', 'Investor B')]),
            $this->screener($mc ?? [$this->stock('26268', 'Mid Co')]),
            $this->screener($sc ?? [$this->stock('310318', 'Small Co')]),
            $this->screener($fn ?? [$this->stock('550035', 'First North Co')]),
        ]);
    }

    public function testHappyPathReturnsOneEntryPerStockRowSortedAndLabelled(): void
    {
        // Ids are fed out of ascending order (5364 before 5247; the First North id
        // 5300 sits numerically between the two LC ids) so the sort is actually
        // exercised, not a no-op against pre-ordered fixtures.
        $this->queueFourLists(
            lc: [$this->stock('5364', 'Volvo B'), $this->stock('5247', 'Investor B')],
            mc: [$this->stock('26268', 'Mid Co')],
            sc: [$this->stock('310318', 'Small Co')],
            fn: [$this->stock('5300', 'First North Co')],
        );

        $entries = $this->adapter()->listUniverse();

        self::assertContainsOnlyInstancesOf(UniverseEntry::class, $entries);
        self::assertSame(
            ['5247', '5300', '5364', '26268', '310318'],
            array_map(static fn (UniverseEntry $e): string => $e->avanzaOrderbookId, $entries),
        );
        self::assertSame(
            [
                UniverseEntry::LIST_LC,          // 5247
                UniverseEntry::LIST_FIRST_NORTH, // 5300
                UniverseEntry::LIST_LC,          // 5364
                UniverseEntry::LIST_MC,          // 26268
                UniverseEntry::LIST_SC,          // 310318
            ],
            array_map(static fn (UniverseEntry $e): string => $e->list, $entries),
        );
        self::assertSame('Investor B', $entries[0]->name);
        $this->assertQueueDrained();
        self::assertSame([], $this->logger->warnings);
    }

    public function testNonStockRowsAreExcludedSilently(): void
    {
        $this->queueFourLists(lc: [
            $this->stock('5247', 'Investor B'),
            $this->stock('900', 'Some Fund', ['type' => 'FUND']),
            $this->stock('901', 'An Index', ['type' => 'INDEX']),
        ]);

        $entries = $this->adapter()->listUniverse();

        self::assertSame(
            ['5247', '26268', '310318', '550035'],
            array_map(static fn (UniverseEntry $e): string => $e->avanzaOrderbookId, $entries),
        );
        self::assertSame([], $this->logger->warnings);
        $this->assertQueueDrained();
    }

    public function testBlankNameRowIsDroppedWithAWarningAndTheRestReturned(): void
    {
        $this->queueFourLists(lc: [
            $this->stock('5247', 'Investor B'),
            $this->stock('5364', '   '),
        ]);

        $entries = $this->adapter()->listUniverse();

        self::assertSame(
            ['5247', '26268', '310318', '550035'],
            array_map(static fn (UniverseEntry $e): string => $e->avanzaOrderbookId, $entries),
        );
        self::assertCount(1, $this->logger->warnings);
        self::assertStringContainsString('blank name', $this->logger->warnings[0]['message']);
        $this->assertQueueDrained();
    }

    public function testNonScalarOrderbookIdRowIsDroppedWithAWarning(): void
    {
        $this->queueFourLists(lc: [
            $this->stock('5247', 'Investor B'),
            $this->stock('5364', 'Volvo B', ['orderbookId' => ['nested']]),
        ]);

        $entries = $this->adapter()->listUniverse();

        self::assertSame(
            ['5247', '26268', '310318', '550035'],
            array_map(static fn (UniverseEntry $e): string => $e->avanzaOrderbookId, $entries),
        );
        self::assertCount(1, $this->logger->warnings);
        self::assertStringContainsString('non-scalar orderbookId', $this->logger->warnings[0]['message']);
        self::assertSame('array', $this->logger->warnings[0]['context']['orderbookId']);
        $this->assertQueueDrained();
    }

    public function testMissingStocksKeyRaisesSchemaMismatchAndReturnsNothing(): void
    {
        $this->queue([$this->json(['rows' => []])]);

        try {
            $this->adapter()->listUniverse();
            self::fail('expected SchemaMismatch');
        } catch (SchemaMismatch $e) {
            self::assertStringContainsString('"stocks" missing', $e->getMessage());
        }

        self::assertNotSame([], $this->logger->warnings);
    }

    public function testAConsumedRowFieldBeingAbsentIsHandled(): void
    {
        // `name` entirely absent on a row → dropped with a warning, rest returned.
        $row = $this->stock('5364', 'Volvo B');
        unset($row['name']);
        $this->queueFourLists(lc: [$this->stock('5247', 'Investor B'), $row]);

        $entries = $this->adapter()->listUniverse();

        self::assertSame(
            ['5247', '26268', '310318', '550035'],
            array_map(static fn (UniverseEntry $e): string => $e->avanzaOrderbookId, $entries),
        );
        self::assertCount(1, $this->logger->warnings);
        $this->assertQueueDrained();
    }

    public function testATargetListWithRowsButNoUsableStockRowsRaisesSchemaMismatch(): void
    {
        // MC returns a non-empty response, but every row is filtered out.
        $this->queue([
            $this->screener([$this->stock('5247', 'Investor B')]),
            $this->screener([
                $this->stock('900', 'A Fund', ['type' => 'FUND']),
                $this->stock('901', 'An Index', ['type' => 'INDEX']),
            ]),
        ]);

        try {
            $this->adapter()->listUniverse();
            self::fail('expected SchemaMismatch');
        } catch (SchemaMismatch $e) {
            self::assertStringContainsString('MC', $e->getMessage());
            self::assertStringContainsString('no usable', $e->getMessage());
        }

        self::assertNotSame([], $this->logger->warnings);
    }

    public function testTruncatedTargetListResponseRaisesSchemaMismatch(): void
    {
        // LC reports 163 matches but returns only 2 rows — a truncated page.
        $this->queue([
            $this->screener(
                [$this->stock('5247', 'Investor B'), $this->stock('5364', 'Volvo B')],
                totalNumberOfOrderbooks: 163,
            ),
        ]);

        try {
            $this->adapter()->listUniverse();
            self::fail('expected SchemaMismatch');
        } catch (SchemaMismatch $e) {
            self::assertStringContainsString('LC', $e->getMessage());
            self::assertStringContainsString('truncated', $e->getMessage());
        }

        self::assertNotSame([], $this->logger->warnings);
    }

    public function testEmptyTargetListRaisesSchemaMismatchNamingTheList(): void
    {
        $this->queue([
            $this->screener([$this->stock('5247', 'Investor B')]),
            $this->screener([]),
        ]);

        try {
            $this->adapter()->listUniverse();
            self::fail('expected SchemaMismatch');
        } catch (SchemaMismatch $e) {
            self::assertStringContainsString('MC', $e->getMessage());
            self::assertStringContainsString('empty', $e->getMessage());
        }

        self::assertNotSame([], $this->logger->warnings);
    }

    public function testRateLimitedThenSuccessOnRetryYieldsNormalResult(): void
    {
        $this->queue([
            new Response(429),
            $this->screener([$this->stock('5247', 'Investor B')]),
            $this->screener([$this->stock('26268', 'Mid Co')]),
            $this->screener([$this->stock('310318', 'Small Co')]),
            $this->screener([$this->stock('550035', 'First North Co')]),
        ]);

        $entries = $this->adapter()->listUniverse();

        self::assertCount(4, $entries);
        $this->assertQueueDrained();
    }

    public function testTransientRaisedWhenTheRetryAlsoFails(): void
    {
        $this->queue([new Response(429), new Response(503)]);

        $this->expectException(Transient::class);
        $this->adapter()->listUniverse();
    }

    public function testOther4xxRaisesSchemaMismatchNamingTheStatus(): void
    {
        $this->queue([new Response(400)]);

        try {
            $this->adapter()->listUniverse();
            self::fail('expected SchemaMismatch');
        } catch (SchemaMismatch $e) {
            self::assertStringContainsString('400', $e->getMessage());
        }
    }

    public function testDuplicateOrderbookIdAcrossListsKeepsTheFirstAndWarns(): void
    {
        $this->queueFourLists(
            lc: [$this->stock('5247', 'Investor B')],
            mc: [$this->stock('5247', 'Investor B (dup)'), $this->stock('26268', 'Mid Co')],
        );

        $entries = $this->adapter()->listUniverse();

        $byId = [];
        foreach ($entries as $entry) {
            $byId[$entry->avanzaOrderbookId] = $entry;
        }
        self::assertSame(UniverseEntry::LIST_LC, $byId['5247']->list);
        self::assertSame('Investor B', $byId['5247']->name);
        self::assertCount(4, $entries);
        self::assertCount(1, $this->logger->warnings);
        self::assertStringContainsString('duplicate orderbookId', $this->logger->warnings[0]['message']);
        $this->assertQueueDrained();
    }

    public function testResolveIsinReturnsTheIsinFromMarketGuide(): void
    {
        $this->queue([$this->json(['isin' => 'SE0000108656', 'name' => 'Ericsson B'])]);

        self::assertSame('SE0000108656', $this->adapter()->resolveIsin('5479'));
        $this->assertQueueDrained();
        self::assertSame([], $this->logger->warnings);
    }

    public function testResolveIsinIssuesAGetToTheMarketGuidePath(): void
    {
        $this->queue([$this->json(['isin' => 'SE0015811963'])]);

        $this->adapter()->resolveIsin('5247');

        $request = $this->mock->getLastRequest();
        self::assertNotNull($request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/_api/market-guide/stock/5247', $request->getUri()->getPath());
    }

    public function testResolveIsin404RaisesNotFound(): void
    {
        $this->queue([new Response(404)]);

        $this->expectException(NotFound::class);
        $this->adapter()->resolveIsin('999999');
    }

    public function testResolveIsinBlankIsinRaisesSchemaMismatchAndWarns(): void
    {
        $this->queue([$this->json(['isin' => '   '])]);

        try {
            $this->adapter()->resolveIsin('5479');
            self::fail('expected SchemaMismatch');
        } catch (SchemaMismatch $e) {
            self::assertStringContainsString('isin', $e->getMessage());
        }

        self::assertNotSame([], $this->logger->warnings);
    }

    public function testResolveIsinMissingIsinRaisesSchemaMismatch(): void
    {
        $this->queue([$this->json(['name' => 'Ericsson B'])]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->resolveIsin('5479');
    }

    public function testResolveIsinRetriesOnceAfter429ThenSucceeds(): void
    {
        $this->queue([new Response(429), $this->json(['isin' => 'SE0000108656'])]);

        self::assertSame('SE0000108656', $this->adapter()->resolveIsin('5479'));
        $this->assertQueueDrained();
    }

    public function testResolveIsinRaisesTransientWhenTheRetryAlsoFails(): void
    {
        $this->queue([new Response(429), new Response(503)]);

        $this->expectException(Transient::class);
        $this->adapter()->resolveIsin('5479');
    }

    public function testPaginationOverflowRaisesSchemaMismatch(): void
    {
        $rows = [];
        for ($i = 0; $i < 5000; $i++) {
            $rows[] = $this->stock((string) (10000 + $i), 'Co ' . $i);
        }
        $this->queue([$this->screener($rows)]);

        try {
            $this->adapter()->listUniverse();
            self::fail('expected SchemaMismatch');
        } catch (SchemaMismatch $e) {
            self::assertStringContainsString('limit', $e->getMessage());
        }

        self::assertNotSame([], $this->logger->warnings);
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $warnings = [];

    /**
     * @param array<mixed> $context
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        if ($level === 'warning') {
            $this->warnings[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
        }
    }
}
