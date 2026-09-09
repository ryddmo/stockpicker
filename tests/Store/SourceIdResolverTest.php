<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Store;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Stockpicker\Error\NotFound;
use Stockpicker\Store\InstrumentRepository;
use Stockpicker\Store\SourceIdResolver;
use Stockpicker\Tests\Support\FakeSourceAdapter;

/**
 * Covers the three `resolve-ids` rows of Story 1.7's I/O matrix.
 */
final class SourceIdResolverTest extends StoreTestCase
{
    private InstrumentRepository $repo;
    private FakeSourceAdapter $avanza;
    private FakeSourceAdapter $nordnet;
    private TestHandler $logHandler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new InstrumentRepository($this->pdo);
        $this->avanza = new FakeSourceAdapter('avanza');
        $this->nordnet = new FakeSourceAdapter('nordnet');
        $this->logHandler = new TestHandler();
    }

    private function seed(string $isin, ?string $avanzaId, ?string $nordnetId): void
    {
        $this->pdo->prepare(
            'INSERT INTO instrument (isin, name, list, avanza_orderbook_id, nordnet_instrument_id, first_seen)
             VALUES (:isin, :name, :list, :a, :n, :fs)'
        )->execute([
            'isin' => $isin,
            'name' => 'Test ' . $isin,
            'list' => 'LC',
            'a' => $avanzaId,
            'n' => $nordnetId,
            'fs' => '2026-01-01',
        ]);
    }

    private function runResolver(): array
    {
        return SourceIdResolver::resolve(
            $this->repo,
            ['avanza' => $this->avanza, 'nordnet' => $this->nordnet],
            new Logger('test', [$this->logHandler]),
        );
    }

    public function testInstrumentThatAlreadyHasBothIdsIsSkippedWithNoAdapterCall(): void
    {
        $this->seed('SE0000000001', '5479', 'nx-1');

        self::assertSame(['resolved' => 0, 'skipped' => 1, 'failed' => 0], $this->runResolver());
        self::assertSame([], $this->avanza->resolveCalls);
        self::assertSame([], $this->nordnet->resolveCalls);
    }

    public function testNullIdIsResolvedPersistedAndCounted(): void
    {
        $this->seed('SE0000000001', null, null);
        $this->avanza->resolveResponses = ['5479'];
        $this->nordnet->resolveResponses = ['19fa390b-040f-45a9-8fa2-e7fd34e319ab'];

        self::assertSame(['resolved' => 2, 'skipped' => 0, 'failed' => 0], $this->runResolver());

        $row = $this->repo->get('SE0000000001');
        self::assertSame('5479', $row->avanzaOrderbookId);
        self::assertSame('19fa390b-040f-45a9-8fa2-e7fd34e319ab', $row->nordnetInstrumentId);
    }

    public function testResolvesOnlyTheMissingSideAndLeavesTheSetIdAlone(): void
    {
        $this->seed('SE0000000001', 'already-set', null);
        $this->nordnet->resolveResponses = ['nx-new'];

        self::assertSame(['resolved' => 1, 'skipped' => 0, 'failed' => 0], $this->runResolver());
        self::assertSame([], $this->avanza->resolveCalls, 'the already-set side is never queried');

        $row = $this->repo->get('SE0000000001');
        self::assertSame('already-set', $row->avanzaOrderbookId);
        self::assertSame('nx-new', $row->nordnetInstrumentId);
    }

    public function testNotFoundLogsAWarningLeavesTheColumnNullAndTheLoopContinues(): void
    {
        $this->seed('SE0000000001', null, null);
        $this->seed('SE0000000002', null, null);

        // First instrument: avanza NotFound, nordnet ok.
        // Second instrument: both ok — proves the loop continued past the failure.
        $this->avanza->resolveResponses = [new NotFound('avanza: no hit for SE0000000001'), 'a-2'];
        $this->nordnet->resolveResponses = ['n-1', 'n-2'];

        self::assertSame(['resolved' => 3, 'skipped' => 0, 'failed' => 1], $this->runResolver());

        self::assertNull($this->repo->get('SE0000000001')->avanzaOrderbookId, 'failed id stays null');
        self::assertSame('n-1', $this->repo->get('SE0000000001')->nordnetInstrumentId);
        self::assertSame('a-2', $this->repo->get('SE0000000002')->avanzaOrderbookId);
        self::assertSame('n-2', $this->repo->get('SE0000000002')->nordnetInstrumentId);

        self::assertTrue($this->logHandler->hasWarningThatContains('could not resolve source id'));
    }

    public function testSchemaMismatchAndTransientAreAlsoCaughtAndCounted(): void
    {
        $this->seed('SE0000000001', null, null);
        $this->avanza->resolveResponses = [new \Stockpicker\Error\SchemaMismatch('avanza: shape changed')];
        $this->nordnet->resolveResponses = [new \Stockpicker\Error\Transient('nordnet: HTTP 503')];

        self::assertSame(['resolved' => 0, 'skipped' => 0, 'failed' => 2], $this->runResolver());

        $row = $this->repo->get('SE0000000001');
        self::assertNull($row->avanzaOrderbookId);
        self::assertNull($row->nordnetInstrumentId);
    }

    public function testEmptyUniverseIsAllZeroes(): void
    {
        self::assertSame(['resolved' => 0, 'skipped' => 0, 'failed' => 0], $this->runResolver());
    }

    public function testAnEmptyIdIsTreatedAsAFailureAndNeverPersisted(): void
    {
        $this->seed('SE0000000001', null, null);
        $this->avanza->resolveResponses = [''];
        $this->nordnet->resolveResponses = ['nx-ok'];

        self::assertSame(['resolved' => 1, 'skipped' => 0, 'failed' => 1], $this->runResolver());

        $row = $this->repo->get('SE0000000001');
        self::assertNull($row->avanzaOrderbookId, 'an empty id must not poison the column');
        self::assertSame('nx-ok', $row->nordnetInstrumentId);
        self::assertTrue($this->logHandler->hasWarningThatContains('could not resolve source id'));
    }

    public function testMissingAdapterIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SourceIdResolver::resolve($this->repo, ['avanza' => $this->avanza], new Logger('test', [$this->logHandler]));
    }
}
