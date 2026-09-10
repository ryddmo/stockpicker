<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Pipeline;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Stockpicker\Adapter\UniverseEntry;
use Stockpicker\Adapter\UniverseLister;
use Stockpicker\Error\NotFound;
use Stockpicker\Error\SchemaMismatch;
use Stockpicker\Error\Transient;
use Stockpicker\Pipeline\UniverseSync;
use Stockpicker\Store\InstrumentRepository;
use Stockpicker\Store\RunRepository;
use Stockpicker\Store\SettingsRepository;
use Stockpicker\Tests\Store\StoreTestCase;
use Stockpicker\Tests\Support\FakeSourceAdapter;

/**
 * One case per I/O & Edge-Case Matrix row (fake UniverseLister + FakeSourceAdapter
 * for Nordnet + the real test MariaDB), plus the review-pass-1 amendment cases:
 * an orderbookId rotation for a stable ISIN (no flip-flop), a prior failed
 * Nordnet id retried, the `count == max_delist` boundary, and the unusable
 * setting value.
 */
final class UniverseSyncTest extends StoreTestCase
{
    private const RUN_DATE = '2026-09-10';

    private FakeUniverseLister $universe;
    private FakeSourceAdapter $nordnet;
    private TestHandler $logHandler;
    private InstrumentRepository $instruments;

    /** @var list<float> */
    private array $waits = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->universe = new FakeUniverseLister();
        $this->nordnet = new FakeSourceAdapter('nordnet');
        $this->logHandler = new TestHandler();
        $this->instruments = new InstrumentRepository($this->pdo);
        $this->waits = [];
    }

    private function sync(): UniverseSync
    {
        $logger = new Logger('test');
        $logger->pushHandler($this->logHandler);

        return new UniverseSync(
            $this->universe,
            $this->nordnet,
            $this->instruments,
            new SettingsRepository($this->pdo),
            new RunRepository($this->pdo),
            $logger,
            function (float $seconds): void {
                $this->waits[] = $seconds;
            },
        );
    }

    private function seed(
        string $isin,
        string $name,
        string $list,
        ?string $avanzaId = null,
        ?string $nordnetId = null,
        ?string $lastSeen = null,
        string $firstSeen = '2026-01-01',
    ): void {
        $this->pdo->prepare(
            'INSERT INTO instrument (isin, name, list, avanza_orderbook_id, nordnet_instrument_id, first_seen, last_seen)
             VALUES (:isin, :name, :list, :a, :n, :fs, :ls)'
        )->execute([
            'isin' => $isin,
            'name' => $name,
            'list' => $list,
            'a' => $avanzaId,
            'n' => $nordnetId,
            'fs' => $firstSeen,
            'ls' => $lastSeen,
        ]);
    }

    /** @return list<\Stockpicker\Store\IngestRun> */
    private function runsLogged(): array
    {
        return (new RunRepository($this->pdo))->forRunDate(self::RUN_DATE);
    }

    // --- I/O & Edge-Case Matrix rows ------------------------------------------

    public function testNewOrderbookIdIsResolvedInsertedIdCachedAndNordnetLookedUp(): void
    {
        $this->universe->entries = [new UniverseEntry('1001', 'Alpha AB', UniverseEntry::LIST_LC)];
        $this->universe->isinByObId = ['1001' => 'SE0000000001'];
        $this->nordnet->resolveResponses = ['NX-1'];

        $counts = $this->sync()->run(self::RUN_DATE);

        $row = $this->instruments->get('SE0000000001');
        self::assertNotNull($row);
        self::assertSame('Alpha AB', $row->name);
        self::assertSame(UniverseEntry::LIST_LC, $row->list);
        self::assertSame(self::RUN_DATE, $row->firstSeen);
        self::assertNull($row->lastSeen);
        self::assertSame('1001', $row->avanzaOrderbookId);
        self::assertSame('NX-1', $row->nordnetInstrumentId);
        self::assertSame(1, $counts['added']);
        self::assertSame(1, $counts['ids_resolved']);

        $logged = $this->runsLogged();
        self::assertCount(1, $logged);
        self::assertSame('universe_sync', $logged[0]->runType);
        self::assertSame(1, $logged[0]->instrumentCount);
        self::assertSame(1, $logged[0]->okCount);
        self::assertSame(0, $logged[0]->failCount);

        // The churn `info` line is the only durable record of the sub-counts.
        $complete = array_values(array_filter(
            $this->logHandler->getRecords(),
            static fn ($record): bool => $record->message === 'universe sync complete',
        ));
        self::assertCount(1, $complete);
        self::assertSame(
            ['run_date', 'added', 'removed', 'changed', 'reactivated', 'ids_resolved', 'ids_failed', 'deferred', 'active_after'],
            array_keys($complete[0]->context),
        );
        self::assertSame(self::RUN_DATE, $complete[0]->context['run_date']);
        self::assertSame(1, $complete[0]->context['added']);
        self::assertSame(1, $complete[0]->context['ids_resolved']);
        self::assertSame(0, $complete[0]->context['deferred']);
        self::assertSame(1, $complete[0]->context['active_after']);
    }

    public function testNewOrderbookIdWithUnresolvableIsinIsSkippedAndRetriable(): void
    {
        $this->universe->entries = [new UniverseEntry('1001', 'Alpha AB', UniverseEntry::LIST_LC)];
        $this->universe->isinByObId = ['1001' => new NotFound('no market-guide row')];

        $counts = $this->sync()->run(self::RUN_DATE);

        self::assertSame([], $this->instruments->all());
        self::assertSame(0, $counts['added']);
        self::assertSame(1, $counts['ids_failed']);
        self::assertTrue($this->logHandler->hasWarningThatContains('could not resolve ISIN'));
        // The run still succeeded -> one ingest_run row.
        self::assertCount(1, $this->runsLogged());
    }

    public function testNewOrderbookIdWithNordnetFailureCachesNullAndWarns(): void
    {
        $this->universe->entries = [new UniverseEntry('1001', 'Alpha AB', UniverseEntry::LIST_LC)];
        $this->universe->isinByObId = ['1001' => 'SE0000000001'];
        $this->nordnet->resolveResponses = [new NotFound('nordnet has no isin')];

        $counts = $this->sync()->run(self::RUN_DATE);

        self::assertNull($this->instruments->get('SE0000000001')->nordnetInstrumentId);
        self::assertSame(1, $counts['added']);
        self::assertSame(1, $counts['ids_failed']);
        self::assertSame(1, $this->runsLogged()[0]->okCount);
        self::assertTrue($this->logHandler->hasWarningThatContains('could not resolve Nordnet id'));
    }

    public function testNullIdSeedRowIsAdoptedByResolvedIsinAndListNameDiffApplied(): void
    {
        $this->seed('SE0000000009', 'Old Name', UniverseEntry::LIST_MC, avanzaId: null, nordnetId: 'NX-9');
        $this->universe->entries = [new UniverseEntry('1009', 'New Name', UniverseEntry::LIST_LC)];
        $this->universe->isinByObId = ['1009' => 'SE0000000009'];

        $counts = $this->sync()->run(self::RUN_DATE);

        $row = $this->instruments->get('SE0000000009');
        self::assertSame('1009', $row->avanzaOrderbookId);
        self::assertSame('New Name', $row->name);
        self::assertSame(UniverseEntry::LIST_LC, $row->list);
        self::assertSame('2026-01-01', $row->firstSeen, 'first_seen untouched on adoption');
        self::assertSame(0, $counts['added']);
        self::assertSame(1, $counts['changed']);
        self::assertSame(0, $counts['removed'], 'a null-id row is never a delisting');
    }

    public function testDelistedRowGetsLastSeenAndIsExcludedFromTheActiveUniverse(): void
    {
        $this->seed('SE0000000001', 'Alpha AB', UniverseEntry::LIST_LC, avanzaId: '1001', nordnetId: 'NX-1');
        $this->seed('SE0000000005', 'Gone AB', UniverseEntry::LIST_LC, avanzaId: '1005', nordnetId: 'NX-5');
        $this->universe->entries = [new UniverseEntry('1001', 'Alpha AB', UniverseEntry::LIST_LC)];

        $counts = $this->sync()->run(self::RUN_DATE);

        $gone = $this->instruments->get('SE0000000005');
        self::assertSame(self::RUN_DATE, $gone->lastSeen);
        self::assertArrayNotHasKey('SE0000000005', $this->instruments->allActive());
        self::assertSame(1, $counts['removed']);
        self::assertSame(1, $this->runsLogged()[0]->failCount);
    }

    public function testListOrNameChangeUpdatesTheColumn(): void
    {
        $this->seed('SE0000000001', 'Alpha AB', UniverseEntry::LIST_MC, avanzaId: '1001', nordnetId: 'NX-1');
        $this->universe->entries = [new UniverseEntry('1001', 'Alpha AB', UniverseEntry::LIST_LC)];

        $counts = $this->sync()->run(self::RUN_DATE);

        self::assertSame(UniverseEntry::LIST_LC, $this->instruments->get('SE0000000001')->list);
        self::assertSame(1, $counts['changed']);
        self::assertSame([], $this->universe->resolveIsinCalls, 'a matched id needs no market-guide call');
    }

    public function testReappearingOrderbookIdReactivatesWithoutTouchingFirstSeen(): void
    {
        $this->seed(
            'SE0000000001',
            'Alpha AB',
            UniverseEntry::LIST_LC,
            avanzaId: '1001',
            nordnetId: 'NX-1',
            lastSeen: '2026-05-01',
            firstSeen: '2025-01-01',
        );
        $this->universe->entries = [new UniverseEntry('1001', 'Alpha AB', UniverseEntry::LIST_LC)];

        $counts = $this->sync()->run(self::RUN_DATE);

        $row = $this->instruments->get('SE0000000001');
        self::assertNull($row->lastSeen);
        self::assertSame('2025-01-01', $row->firstSeen);
        self::assertSame(1, $counts['reactivated']);
        self::assertSame(1, $this->runsLogged()[0]->okCount, 'reactivations count into ok_count');
    }

    public function testUnchangedListingMakesZeroWritesAndLogsZeroChurn(): void
    {
        $this->seed('SE0000000001', 'Alpha AB', UniverseEntry::LIST_LC, avanzaId: '1001', nordnetId: 'NX-1');
        $this->seed('SE0000000009', 'Ghost AB', UniverseEntry::LIST_SC, avanzaId: null, nordnetId: 'NX-9');
        $this->universe->entries = [new UniverseEntry('1001', 'Alpha AB', UniverseEntry::LIST_LC)];
        $this->universe->isinByObId = ['1001' => 'SE0000000001'];

        $counts = $this->sync()->run(self::RUN_DATE);

        self::assertSame(
            ['added' => 0, 'removed' => 0, 'changed' => 0, 'reactivated' => 0],
            array_intersect_key($counts, array_flip(['added', 'removed', 'changed', 'reactivated'])),
        );
        // The null-id ghost row stays active — never delisted.
        self::assertArrayHasKey('SE0000000009', $this->instruments->allActive());
        $logged = $this->runsLogged()[0];
        self::assertSame(0, $logged->okCount);
        self::assertSame(0, $logged->failCount);
    }

    public function testListingFailureAbortsWithNoWritesAndNoRunLog(): void
    {
        $this->seed('SE0000000001', 'Alpha AB', UniverseEntry::LIST_LC, avanzaId: '1001', nordnetId: 'NX-1');
        $this->universe->listError = new SchemaMismatch('avanza screener shape changed');

        try {
            $this->sync()->run(self::RUN_DATE);
            self::fail('expected SchemaMismatch');
        } catch (SchemaMismatch) {
            // expected
        }

        self::assertNull($this->instruments->get('SE0000000001')->lastSeen);
        self::assertSame([], $this->runsLogged());
        self::assertTrue($this->logHandler->hasWarningThatContains('listing fetch failed'));
    }

    public function testListingTransientAbortsWithNoWritesAndNoRunLog(): void
    {
        $this->seed('SE0000000001', 'Alpha AB', UniverseEntry::LIST_LC, avanzaId: '1001', nordnetId: 'NX-1');
        $this->universe->listError = new Transient('avanza screener 503');

        $this->expectException(Transient::class);
        try {
            $this->sync()->run(self::RUN_DATE);
        } finally {
            self::assertSame([], $this->runsLogged());
        }
    }

    public function testMassDelistGuardAbortsBeforeAnyWrite(): void
    {
        (new SettingsRepository($this->pdo))->set('universe.max_delist', '2');
        foreach (['1', '2', '3'] as $n) {
            $this->seed("SE000000000{$n}", "Co {$n}", UniverseEntry::LIST_LC, avanzaId: "100{$n}", nordnetId: "NX-{$n}");
        }
        $this->universe->entries = [new UniverseEntry('9999', 'Survivor', UniverseEntry::LIST_LC)];
        $this->universe->isinByObId = ['9999' => 'SE0000009999'];

        try {
            $this->sync()->run(self::RUN_DATE);
            self::fail('expected SchemaMismatch');
        } catch (SchemaMismatch $e) {
            self::assertStringContainsString('max_delist', $e->getMessage());
        }

        foreach (['1', '2', '3'] as $n) {
            self::assertNull($this->instruments->get("SE000000000{$n}")->lastSeen);
        }
        self::assertNull($this->instruments->get('SE0000009999'), 'no insert either');
        self::assertSame([], $this->runsLogged());
        self::assertTrue($this->logHandler->hasErrorThatContains('exceed universe.max_delist'));
    }

    public function testDelistCountEqualToMaxDelistDoesNotTrip(): void
    {
        // 3 seeds, the listing keeps only 1001 -> delist set size 2, cap 2:
        // count == cap is allowed (only `> cap` aborts).
        (new SettingsRepository($this->pdo))->set('universe.max_delist', '2');
        foreach (['1', '2', '3'] as $n) {
            $this->seed("SE000000000{$n}", "Co {$n}", UniverseEntry::LIST_LC, avanzaId: "100{$n}", nordnetId: "NX-{$n}");
        }
        $this->universe->entries = [new UniverseEntry('1001', 'Co 1', UniverseEntry::LIST_LC)];

        $counts = $this->sync()->run(self::RUN_DATE);

        self::assertSame(2, $counts['removed']);
        self::assertSame(self::RUN_DATE, $this->instruments->get('SE0000000002')->lastSeen);
        self::assertSame(self::RUN_DATE, $this->instruments->get('SE0000000003')->lastSeen);
        self::assertCount(1, $this->runsLogged());
    }

    public function testUnusableMaxDelistZeroFallsBackToTheDefaultAndDelistingsApply(): void
    {
        (new SettingsRepository($this->pdo))->set('universe.max_delist', '0');
        foreach (['1', '2', '3'] as $n) {
            $this->seed("SE000000000{$n}", "Co {$n}", UniverseEntry::LIST_LC, avanzaId: "100{$n}", nordnetId: "NX-{$n}");
        }
        $this->universe->entries = [new UniverseEntry('1001', 'Co 1', UniverseEntry::LIST_LC)];

        $counts = $this->sync()->run(self::RUN_DATE);

        // '0' is non-positive -> warning + the built-in default of 25, so the
        // 2 would-be delistings are well under the cap and apply.
        self::assertTrue($this->logHandler->hasWarningThatContains('unusable setting value'));
        self::assertSame(2, $counts['removed']);
        self::assertSame(self::RUN_DATE, $this->instruments->get('SE0000000002')->lastSeen);
        self::assertSame(self::RUN_DATE, $this->instruments->get('SE0000000003')->lastSeen);
        self::assertCount(1, $this->runsLogged());
    }

    public function testTwoListingEntriesResolvingToTheSameIsinAreReconciledOnce(): void
    {
        $this->universe->entries = [
            new UniverseEntry('1001', 'Alpha AB', UniverseEntry::LIST_LC),
            new UniverseEntry('2001', 'Alpha AB', UniverseEntry::LIST_LC),
        ];
        $this->universe->isinByObId = ['1001' => 'SE0000000001', '2001' => 'SE0000000001'];
        $this->nordnet->resolveResponses = [new NotFound('x')];

        $counts = $this->sync()->run(self::RUN_DATE);

        self::assertCount(1, $this->instruments->all());
        self::assertSame('1001', $this->instruments->get('SE0000000001')->avanzaOrderbookId, 'first orderbookId wins');
        self::assertSame(1, $counts['added']);
        self::assertSame(['1001', '2001'], $this->universe->resolveIsinCalls);
        self::assertTrue($this->logHandler->hasWarningThatContains('resolved to the same ISIN'));
    }

    public function testBudgetSpentAppliesSqlReconciliationButDefersNewOrderbookIds(): void
    {
        $this->seed('SE0000000001', 'Alpha AB', UniverseEntry::LIST_MC, avanzaId: '1001', nordnetId: 'NX-1');
        $this->seed('SE0000000005', 'Gone AB', UniverseEntry::LIST_LC, avanzaId: '1005', nordnetId: 'NX-5');
        $this->universe->entries = [
            new UniverseEntry('1001', 'Alpha AB', UniverseEntry::LIST_LC),
            new UniverseEntry('2002', 'Newcomer AB', UniverseEntry::LIST_LC),
        ];
        $this->universe->isinByObId = ['2002' => 'SE0000002002'];

        $counts = $this->sync()->run(self::RUN_DATE, 0.0);

        self::assertSame(UniverseEntry::LIST_LC, $this->instruments->get('SE0000000001')->list, 'list change applied');
        self::assertSame(self::RUN_DATE, $this->instruments->get('SE0000000005')->lastSeen, 'delisting applied');
        self::assertNull($this->instruments->get('SE0000002002'), 'new orderbookId deferred');
        self::assertSame(1, $counts['changed']);
        self::assertSame(1, $counts['removed']);
        self::assertGreaterThanOrEqual(1, $counts['deferred']);
        self::assertSame([], $this->universe->resolveIsinCalls);
        self::assertCount(1, $this->runsLogged());
    }

    // --- Review-pass-1 amendment cases --------------------------------------

    public function testOrderbookIdRotationForAStableIsinReconcilesWithoutFlipFlop(): void
    {
        $this->seed('SE0000000001', 'Alpha AB', UniverseEntry::LIST_LC, avanzaId: '1001', nordnetId: 'NX-1');
        $this->universe->entries = [new UniverseEntry('2001', 'Alpha AB', UniverseEntry::LIST_LC)];
        $this->universe->isinByObId = ['2001' => 'SE0000000001'];

        $first = $this->sync()->run(self::RUN_DATE);

        $row = $this->instruments->get('SE0000000001');
        self::assertSame('2001', $row->avanzaOrderbookId, 'id rotated to the new orderbookId');
        self::assertNull($row->lastSeen, 'the live row is not delisted');
        self::assertSame(0, $first['removed']);
        self::assertSame(0, $first['added']);
        self::assertSame(0, $first['changed']);

        // A second, unchanged run makes zero writes (no reactivate/re-delist loop).
        $this->universe->resolveIsinCalls = [];
        $second = $this->sync()->run('2026-09-11');

        self::assertSame('2001', $this->instruments->get('SE0000000001')->avanzaOrderbookId);
        self::assertSame([], $this->universe->resolveIsinCalls, 'the id now matches — no market-guide call');
        self::assertSame(
            ['added' => 0, 'removed' => 0, 'changed' => 0, 'reactivated' => 0],
            array_intersect_key($second, array_flip(['added', 'removed', 'changed', 'reactivated'])),
        );
    }

    public function testAPriorRunsFailedNordnetIdIsRetriedAndCached(): void
    {
        $this->seed('SE0000000001', 'Alpha AB', UniverseEntry::LIST_LC, avanzaId: '1001', nordnetId: null);
        $this->universe->entries = [new UniverseEntry('1001', 'Alpha AB', UniverseEntry::LIST_LC)];
        $this->nordnet->resolveResponses = ['NX-1'];

        $counts = $this->sync()->run(self::RUN_DATE);

        self::assertSame('SE0000000001', $this->nordnet->resolveCalls[0] ?? null);
        self::assertSame('NX-1', $this->instruments->get('SE0000000001')->nordnetInstrumentId);
        self::assertSame(1, $counts['ids_resolved']);
    }

    public function testUnusableMaxDelistSettingWarnsAndUsesTheDefault(): void
    {
        (new SettingsRepository($this->pdo))->set('universe.max_delist', 'nonsense');
        $this->seed('SE0000000001', 'Alpha AB', UniverseEntry::LIST_LC, avanzaId: '1001', nordnetId: 'NX-1');
        $this->universe->entries = [new UniverseEntry('1001', 'Alpha AB', UniverseEntry::LIST_LC)];

        $this->sync()->run(self::RUN_DATE);

        self::assertTrue($this->logHandler->hasWarningThatContains('unusable setting value'));
        self::assertCount(1, $this->runsLogged());
    }

    public function testThrottleSpacesConsecutiveCallsToTheSameSource(): void
    {
        (new SettingsRepository($this->pdo))->set('rate.avanza', '1');
        $this->universe->entries = [
            new UniverseEntry('1001', 'A', UniverseEntry::LIST_LC),
            new UniverseEntry('1002', 'B', UniverseEntry::LIST_MC),
        ];
        $this->universe->isinByObId = ['1001' => 'SE0000000001', '1002' => 'SE0000000002'];
        $this->nordnet->resolveResponses = [new NotFound('x'), new NotFound('y')];

        $this->sync()->run(self::RUN_DATE);

        // The first avanza call does not wait; the second waits ~1 s (1 / rate).
        self::assertNotEmpty($this->waits);
        self::assertGreaterThan(0.0, max($this->waits));
    }
}

final class FakeUniverseLister implements UniverseLister
{
    /** @var list<UniverseEntry> */
    public array $entries = [];

    public ?\Throwable $listError = null;

    /** @var array<string, string|\Throwable> orderbookId => resolved ISIN or a throwable */
    public array $isinByObId = [];

    /** @var list<string> */
    public array $resolveIsinCalls = [];

    public function listUniverse(): array
    {
        if ($this->listError !== null) {
            throw $this->listError;
        }

        return $this->entries;
    }

    public function resolveIsin(string $orderbookId): string
    {
        $this->resolveIsinCalls[] = $orderbookId;

        $result = $this->isinByObId[$orderbookId] ?? new NotFound("no isin queued for {$orderbookId}");
        if ($result instanceof \Throwable) {
            throw $result;
        }

        return $result;
    }
}
