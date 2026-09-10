<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Pipeline;

use DateTimeImmutable;
use DateTimeZone;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Stockpicker\Adapter\NormalizedRow;
use Stockpicker\Pipeline\Enqueue;
use Stockpicker\Pipeline\FetchRunner;
use Stockpicker\Store\InstrumentRepository;
use Stockpicker\Store\OwnerCountRepository;
use Stockpicker\Store\IngestRun;
use Stockpicker\Store\QueueRepository;
use Stockpicker\Store\RunRepository;
use Stockpicker\Store\SettingsRepository;
use Stockpicker\Tests\Store\StoreTestCase;
use Stockpicker\Tests\Support\FakeSourceAdapter;

final class FetchRunnerTest extends StoreTestCase
{
    private const RUN_DATE = '2026-09-09';

    private FakeSourceAdapter $avanza;
    private FakeSourceAdapter $nordnet;
    private TestHandler $logHandler;

    /** @var list<float> */
    private array $waits = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->avanza = new FakeSourceAdapter('avanza');
        $this->nordnet = new FakeSourceAdapter('nordnet');
        $this->logHandler = new TestHandler();
        $this->waits = [];
    }

    /**
     * @param list<array{string, ?string, ?string}> $rows [isin, avanzaId, nordnetId]
     */
    private function seedInstruments(array $rows): void
    {
        foreach ($rows as [$isin, $avanzaId, $nordnetId]) {
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
    }

    private function logger(): LoggerInterface
    {
        return new Logger('test', [$this->logHandler]);
    }

    private function runner(): FetchRunner
    {
        return new FetchRunner(
            new QueueRepository($this->pdo),
            new InstrumentRepository($this->pdo),
            new OwnerCountRepository($this->pdo),
            ['avanza' => $this->avanza, 'nordnet' => $this->nordnet],
            new SettingsRepository($this->pdo),
            $this->logger(),
            new RunRepository($this->pdo),
            function (float $seconds): void {
                $this->waits[] = $seconds;
            },
        );
    }

    private function enqueueAll(): void
    {
        (new Enqueue(
            new QueueRepository($this->pdo),
            new InstrumentRepository($this->pdo),
            new RunRepository($this->pdo),
        ))->run(self::RUN_DATE);
    }

    /**
     * The `fetch` rows written to `ingest_run` for the run date (excludes the
     * `enqueue` row `enqueueAll()` leaves), oldest first.
     *
     * @return list<IngestRun>
     */
    private function fetchLogRows(): array
    {
        return array_values(array_filter(
            (new RunRepository($this->pdo))->forRunDate(self::RUN_DATE),
            static fn (IngestRun $r): bool => $r->runType === 'fetch',
        ));
    }

    /**
     * The context of the single `fetchrunner: slice complete` info line.
     *
     * @return array<string, mixed>
     */
    private function sliceCompleteContext(): array
    {
        foreach ($this->logHandler->getRecords() as $record) {
            if ($record['message'] === 'fetchrunner: slice complete') {
                return $record['context'];
            }
        }

        self::fail('no "fetchrunner: slice complete" info line was logged');
    }

    private function row(string $source, string $isin, int $owners, ?DateTimeImmutable $sourceTs = null): NormalizedRow
    {
        return new NormalizedRow(
            $isin,
            $source,
            $owners,
            100.0,
            null,
            $sourceTs,
            new DateTimeImmutable('2026-09-09T18:05:00Z', new DateTimeZone('UTC')),
        );
    }

    private function queueStatus(string $isin): ?string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM work_queue WHERE isin = :i AND run_date = :d');
        $stmt->execute(['i' => $isin, 'd' => self::RUN_DATE]);
        $v = $stmt->fetchColumn();

        return $v === false ? null : (string) $v;
    }

    private function ownerRowCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM owner_count_daily')->fetchColumn();
    }

    public function testBothSourcesOkMarksDoneAndWritesTwoRows(): void
    {
        $this->seedInstruments([['SE0000000001', '5479', 'nx-1']]);
        $this->enqueueAll();
        $this->avanza->fetchResponses = [$this->row('avanza', 'SE0000000001', 1000)];
        $this->nordnet->fetchResponses = [
            $this->row('nordnet', 'SE0000000001', 2000, new DateTimeImmutable('2026-09-09T12:00:00Z', new DateTimeZone('UTC'))),
        ];

        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        self::assertSame('done', $this->queueStatus('SE0000000001'));
        self::assertSame(2, $this->ownerRowCount());
        self::assertSame(1, $result->claimed);
        self::assertSame(1, $result->done);
        self::assertSame(0, $result->failed);
        self::assertSame(0, $result->reopened);
        self::assertSame(2, $result->rowsWritten);

        $log = $this->fetchLogRows();
        self::assertCount(1, $log);
        self::assertSame(self::RUN_DATE, $log[0]->runDate);
        self::assertSame($result->claimed, $log[0]->instrumentCount);
        self::assertSame($result->done, $log[0]->okCount);
        self::assertSame($result->failed, $log[0]->failCount);
        self::assertGreaterThanOrEqual($log[0]->startedAt, $log[0]->finishedAt);
    }

    public function testNullSourceIdSkipsThatSourceWithAWarningAndStillMarksDone(): void
    {
        $this->seedInstruments([['SE0000000001', '5479', null]]);
        $this->enqueueAll();
        $this->avanza->fetchResponses = [$this->row('avanza', 'SE0000000001', 1000)];

        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        self::assertSame('done', $this->queueStatus('SE0000000001'));
        self::assertSame(1, $this->ownerRowCount());
        self::assertSame([], $this->nordnet->fetchCalls, 'null-id source is never called');
        self::assertSame(1, $result->rowsWritten);
        self::assertTrue($this->logHandler->hasWarningThatContains('cached source id is null'));
    }

    public function testNotFoundFromOneSourceMarksTheJobFailed(): void
    {
        $this->seedInstruments([['SE0000000001', '5479', 'nx-1']]);
        $this->enqueueAll();
        $this->avanza->fetchResponses = [new \Stockpicker\Error\NotFound('avanza: stale id')];
        $this->nordnet->fetchResponses = [
            $this->row('nordnet', 'SE0000000001', 2000, new DateTimeImmutable('2026-09-09T12:00:00Z', new DateTimeZone('UTC'))),
        ];

        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        self::assertSame('failed', $this->queueStatus('SE0000000001'));
        self::assertSame(1, $this->ownerRowCount(), 'the working source is still stored');
        self::assertSame(1, $result->failed);
        self::assertSame(0, $result->done);
        self::assertTrue($this->logHandler->hasWarningThatContains('source failed for job'));

        $log = $this->fetchLogRows();
        self::assertCount(1, $log);
        self::assertSame(1, $log[0]->instrumentCount);
        self::assertSame(0, $log[0]->okCount);
        self::assertSame(1, $log[0]->failCount);
    }

    public function testSchemaMismatchAlsoFailsTheJob(): void
    {
        $this->seedInstruments([['SE0000000001', '5479', 'nx-1']]);
        $this->enqueueAll();
        $this->avanza->fetchResponses = [$this->row('avanza', 'SE0000000001', 1000)];
        $this->nordnet->fetchResponses = [new \Stockpicker\Error\SchemaMismatch('nordnet: field gone')];

        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        self::assertSame('failed', $this->queueStatus('SE0000000001'));
        self::assertSame(1, $result->failed);
    }

    public function testTransientReopensTheJobAndTheRunnerContinues(): void
    {
        $this->seedInstruments([
            ['SE0000000001', 'a-1', 'nx-1'],
            ['SE0000000002', 'a-2', 'nx-2'],
        ]);
        $this->enqueueAll();

        // Job 1: avanza ok, nordnet transient -> job 1 reopened.
        // Job 2: both ok -> done.
        $this->avanza->fetchResponses = [
            $this->row('avanza', 'SE0000000001', 1000),
            $this->row('avanza', 'SE0000000002', 1100),
        ];
        $this->nordnet->fetchResponses = [
            new \Stockpicker\Error\Transient('nordnet: HTTP 503'),
            $this->row('nordnet', 'SE0000000002', 2200, new DateTimeImmutable('2026-09-09T12:00:00Z', new DateTimeZone('UTC'))),
        ];

        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        self::assertSame('pending', $this->queueStatus('SE0000000001'));
        self::assertSame('done', $this->queueStatus('SE0000000002'));
        self::assertSame(2, $result->claimed);
        self::assertSame(1, $result->done);
        self::assertSame(0, $result->failed);
        self::assertSame(1, $result->reopened);
        self::assertTrue($this->logHandler->hasWarningThatContains('transient from source'));
    }

    public function testTimeboxAlreadyElapsedReopensEveryClaimedJob(): void
    {
        $this->seedInstruments([
            ['SE0000000001', 'a-1', 'nx-1'],
            ['SE0000000002', 'a-2', 'nx-2'],
        ]);
        $this->enqueueAll();

        $result = $this->runner()->run(self::RUN_DATE, 0.0);

        self::assertSame('pending', $this->queueStatus('SE0000000001'));
        self::assertSame('pending', $this->queueStatus('SE0000000002'));
        self::assertSame(2, $result->claimed);
        self::assertSame(0, $result->done);
        self::assertSame(2, $result->reopened);
        self::assertSame([], $this->avanza->fetchCalls);

        // The timebox early-exit still leaves exactly one fetch row.
        $log = $this->fetchLogRows();
        self::assertCount(1, $log);
        self::assertSame(2, $log[0]->instrumentCount);
        self::assertSame(0, $log[0]->okCount);
        self::assertSame(0, $log[0]->failCount);
    }

    public function testTimeboxHitMidSliceReopensTheRemainingJobs(): void
    {
        $isins = ['SE0000000001', 'SE0000000002', 'SE0000000003'];
        $this->seedInstruments(array_map(static fn (string $i): array => [$i, 'a', 'nx'], $isins));
        $this->enqueueAll();

        // Each fetch burns 100 ms against a 20 ms timebox, so the box is
        // certain to trip after the first job — but the assertions below only
        // rely on invariants that hold wherever it trips, so this can't flake.
        $ts = new DateTimeImmutable('2026-09-09T12:00:00Z', new DateTimeZone('UTC'));
        $this->avanza->fetchDelaySeconds = 0.1;
        foreach ($isins as $isin) {
            $this->avanza->fetchResponses[] = $this->row('avanza', $isin, 1000);
            $this->nordnet->fetchResponses[] = $this->row('nordnet', $isin, 2000, $ts);
        }

        $result = $this->runner()->run(self::RUN_DATE, 0.02);

        self::assertSame(3, $result->claimed);
        self::assertSame(0, $result->failed);
        self::assertSame(3, $result->done + $result->reopened, 'every claimed job is done or reopened');
        self::assertGreaterThanOrEqual(1, $result->reopened, 'the box must trip before the last job');

        // No job is left stuck `claimed`; the leftovers are `pending`.
        foreach ($isins as $isin) {
            self::assertContains($this->queueStatus($isin), ['done', 'pending']);
        }
        self::assertSame('pending', $this->queueStatus('SE0000000003'), 'the last job never ran');

        // Single exit: one fetch row whatever path the timebox took, counts
        // matching the partial slice's result.
        $log = $this->fetchLogRows();
        self::assertCount(1, $log);
        self::assertSame($result->claimed, $log[0]->instrumentCount);
        self::assertSame($result->done, $log[0]->okCount);
        self::assertSame($result->failed, $log[0]->failCount);

        // The `slice complete` info line fires exactly once on the timebox-break
        // path too, with `by_source` reflecting only the jobs that actually ran.
        $sliceCompleteLines = array_filter(
            $this->logHandler->getRecords(),
            static fn ($r): bool => $r['message'] === 'fetchrunner: slice complete',
        );
        self::assertCount(1, $sliceCompleteLines);
        $context = $this->sliceCompleteContext();
        self::assertSame($result->bySource, $context['by_source']);
        self::assertSame($result->done, $result->bySource['avanza']['ok']);
        self::assertSame($result->done, $result->bySource['nordnet']['ok']);
    }

    public function testStaleClaimedRowIsReopenedAtSliceStartThenClaimed(): void
    {
        $this->seedInstruments([['SE0000000001', 'a-1', 'nx-1']]);
        $queue = new QueueRepository($this->pdo);
        $queue->enqueue('SE0000000001', self::RUN_DATE);

        // Claim it 20 minutes ago and leave it (a crashed earlier slice).
        $queue->claimBatch(
            self::RUN_DATE,
            1,
            (new DateTimeImmutable('now', new DateTimeZone('UTC')))->sub(new \DateInterval('PT20M')),
        );
        self::assertSame('claimed', $this->queueStatus('SE0000000001'));

        $this->avanza->fetchResponses = [$this->row('avanza', 'SE0000000001', 1000)];
        $this->nordnet->fetchResponses = [
            $this->row('nordnet', 'SE0000000001', 2000, new DateTimeImmutable('2026-09-09T12:00:00Z', new DateTimeZone('UTC'))),
        ];

        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        self::assertSame('done', $this->queueStatus('SE0000000001'));
        self::assertSame(1, $result->reopened, 'the stale row was reopened at slice start');
        self::assertSame(1, $result->claimed);
        self::assertSame(1, $result->done);
    }

    public function testSameSourceCallsAreSpacedButDifferentSourcesAreNot(): void
    {
        $this->seedInstruments([
            ['SE0000000001', 'a-1', 'nx-1'],
            ['SE0000000002', 'a-2', 'nx-2'],
        ]);
        $this->enqueueAll();
        $ts = new DateTimeImmutable('2026-09-09T12:00:00Z', new DateTimeZone('UTC'));
        $this->avanza->fetchResponses = [$this->row('avanza', 'SE0000000001', 1), $this->row('avanza', 'SE0000000002', 2)];
        $this->nordnet->fetchResponses = [$this->row('nordnet', 'SE0000000001', 3, $ts), $this->row('nordnet', 'SE0000000002', 4, $ts)];

        $this->runner()->run(self::RUN_DATE, 60.0);

        // rate.avanza = rate.nordnet = 0.5 -> 2 s spacing; one wait per source
        // for the second job, none within a job (different sources).
        self::assertSame([2.0, 2.0], $this->waits);
    }

    public function testEmptyQueueReturnsZeroCountsWithoutError(): void
    {
        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        self::assertSame(0, $result->claimed);
        self::assertSame(0, $result->done);
        self::assertSame(0, $result->failed);
        self::assertSame(0, $result->reopened);
        self::assertSame(0, $result->rowsWritten);

        // An empty slice still writes one fetch row, all counts 0.
        $log = (new RunRepository($this->pdo))->forRunDate(self::RUN_DATE);
        self::assertCount(1, $log);
        self::assertSame('fetch', $log[0]->runType);
        self::assertSame(0, $log[0]->instrumentCount);
        self::assertSame(0, $log[0]->okCount);
        self::assertSame(0, $log[0]->failCount);
        self::assertGreaterThanOrEqual($log[0]->startedAt, $log[0]->finishedAt);
    }

    public function testAvanzaRowIsDatedFromTheRunDateNotTheFetchClock(): void
    {
        // fetchedAt 2026-09-09T23:30Z -> Stockholm 2026-09-10; run date is the 9th.
        $this->seedInstruments([['SE0000000001', 'a-1', 'nx-1']]);
        $this->enqueueAll();
        $this->avanza->fetchResponses = [
            new NormalizedRow(
                'SE0000000001',
                'avanza',
                1000,
                null,
                null,
                null,
                new DateTimeImmutable('2026-09-09T23:30:00Z', new DateTimeZone('UTC')),
            ),
        ];
        $this->nordnet->fetchResponses = [
            $this->row('nordnet', 'SE0000000001', 2000, new DateTimeImmutable('2026-09-09T22:00:00Z', new DateTimeZone('UTC'))),
        ];

        $this->runner()->run(self::RUN_DATE, 60.0);

        $rows = $this->pdo->query(
            "SELECT source, as_of_date FROM owner_count_daily ORDER BY source"
        )->fetchAll();
        self::assertSame('avanza', $rows[0]['source']);
        self::assertSame('2026-09-09', $rows[0]['as_of_date'], 'Avanza dates from the run date');
        // Nordnet 2026-09-09T22:00Z -> Stockholm +02:00 -> 2026-09-10 (FR5: its own timestamp wins)
        self::assertSame('nordnet', $rows[1]['source']);
        self::assertSame('2026-09-10', $rows[1]['as_of_date'], 'Nordnet still dates from statistics_timestamp');
    }

    public function testConstructorRejectsAnIncompleteAdapterMap(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FetchRunner(
            new QueueRepository($this->pdo),
            new InstrumentRepository($this->pdo),
            new OwnerCountRepository($this->pdo),
            ['avanza' => $this->avanza], // nordnet missing
            new SettingsRepository($this->pdo),
            $this->logger(),
            new RunRepository($this->pdo),
        );
    }

    public function testAnUnexpectedThrowFailsOnlyThatJobAndTheSliceContinues(): void
    {
        $this->seedInstruments([
            ['SE0000000001', 'a-1', 'nx-1'],
            ['SE0000000002', 'a-2', 'nx-2'],
        ]);
        $this->enqueueAll();

        // Job 1: avanza throws a raw RuntimeException (not an AdapterError).
        // Job 2: both sources OK.
        $this->avanza->fetchResponses = [
            new \RuntimeException('boom — a bug, or a raw PDOException'),
            $this->row('avanza', 'SE0000000002', 1100),
        ];
        $this->nordnet->fetchResponses = [
            $this->row('nordnet', 'SE0000000002', 2200, new DateTimeImmutable('2026-09-09T12:00:00Z', new DateTimeZone('UTC'))),
        ];

        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        self::assertSame('failed', $this->queueStatus('SE0000000001'));
        self::assertSame('done', $this->queueStatus('SE0000000002'), 'later jobs still run');
        self::assertSame(2, $result->claimed);
        self::assertSame(1, $result->failed);
        self::assertSame(1, $result->done);
        self::assertTrue($this->logHandler->hasErrorThatContains('unexpected error handling job'));

        // Job 1's raw \RuntimeException from fetch() matched no typed catch and
        // incremented no bucket; only job 2's success shows for either source.
        self::assertSame(['ok' => 1, 'not_found' => 0, 'schema_mismatch' => 0, 'transient' => 0], $result->bySource['avanza']);
        self::assertSame(['ok' => 1, 'not_found' => 0, 'schema_mismatch' => 0, 'transient' => 0], $result->bySource['nordnet']);

        $run = (new RunRepository($this->pdo))->recent()[0];
        self::assertSame('fetch', $run->runType);
        self::assertSame(2, $run->instrumentCount);
        self::assertSame(1, $run->okCount);
        self::assertSame(1, $run->failCount);
    }

    public function testBatchSizeSettingCapsTheClaim(): void
    {
        (new SettingsRepository($this->pdo))->set('batch_size', '2');
        $isins = ['SE0000000001', 'SE0000000002', 'SE0000000003'];
        $this->seedInstruments(array_map(static fn (string $i): array => [$i, 'a', 'nx'], $isins));
        $this->enqueueAll();
        $ts = new DateTimeImmutable('2026-09-09T12:00:00Z', new DateTimeZone('UTC'));
        foreach ($isins as $isin) {
            $this->avanza->fetchResponses[] = $this->row('avanza', $isin, 1);
            $this->nordnet->fetchResponses[] = $this->row('nordnet', $isin, 2, $ts);
        }

        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        self::assertSame(2, $result->claimed);
        self::assertSame(2, $result->done);
        self::assertSame(1, (new QueueRepository($this->pdo))->countByStatus(self::RUN_DATE)['pending']);
    }

    public function testRateSettingChangesTheSameSourceSpacing(): void
    {
        (new SettingsRepository($this->pdo))->set('rate.avanza', '1'); // 1/s -> 1 s spacing
        $this->seedInstruments([
            ['SE0000000001', 'a-1', 'nx-1'],
            ['SE0000000002', 'a-2', 'nx-2'],
        ]);
        $this->enqueueAll();
        $ts = new DateTimeImmutable('2026-09-09T12:00:00Z', new DateTimeZone('UTC'));
        $this->avanza->fetchResponses = [$this->row('avanza', 'SE0000000001', 1), $this->row('avanza', 'SE0000000002', 2)];
        $this->nordnet->fetchResponses = [$this->row('nordnet', 'SE0000000001', 3, $ts), $this->row('nordnet', 'SE0000000002', 4, $ts)];

        $this->runner()->run(self::RUN_DATE, 60.0);

        // avanza spacing is now 1.0 s (not the 2.0 s default); nordnet stays 2.0.
        self::assertSame([1.0, 2.0], $this->waits);
    }

    public function testUnusableSettingValueFallsBackToDefaultWithAWarning(): void
    {
        (new SettingsRepository($this->pdo))->set('rate.avanza', 'nonsense');
        (new SettingsRepository($this->pdo))->set('batch_size', '0');
        $this->seedInstruments([
            ['SE0000000001', 'a-1', 'nx-1'],
            ['SE0000000002', 'a-2', 'nx-2'],
        ]);
        $this->enqueueAll();
        $ts = new DateTimeImmutable('2026-09-09T12:00:00Z', new DateTimeZone('UTC'));
        foreach (['SE0000000001', 'SE0000000002'] as $isin) {
            $this->avanza->fetchResponses[] = $this->row('avanza', $isin, 1);
            $this->nordnet->fetchResponses[] = $this->row('nordnet', $isin, 2, $ts);
        }

        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        // batch_size '0' -> default 25 (both jobs claimed); rate.avanza 'nonsense'
        // -> default 0.5 -> 2.0 s spacing.
        self::assertSame(2, $result->claimed);
        self::assertSame([2.0, 2.0], $this->waits);
        self::assertTrue($this->logHandler->hasWarningThatContains('unusable setting value'));
    }

    public function testASourceDownForTheWholeSliceStillLandsTheOtherSourceForEveryJob(): void
    {
        $isins = ['SE0000000001', 'SE0000000002', 'SE0000000003'];
        $this->seedInstruments(array_map(
            static fn (string $i): array => [$i, 'a-' . $i, 'nx-' . $i],
            $isins,
        ));
        $this->enqueueAll();
        $ts = new DateTimeImmutable('2026-09-09T12:00:00Z', new DateTimeZone('UTC'));
        foreach ($isins as $i => $isin) {
            $this->avanza->fetchResponses[] = new \Stockpicker\Error\Transient('avanza: HTTP 503');
            $this->nordnet->fetchResponses[] = $this->row('nordnet', $isin, 2000 + $i, $ts);
        }

        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        foreach ($isins as $isin) {
            self::assertSame('pending', $this->queueStatus($isin), 'every job reopened by the transient source');
        }
        self::assertSame(3, $this->ownerRowCount(), 'every nordnet row is written despite avanza being down');
        self::assertSame(['SE0000000001', 'SE0000000002', 'SE0000000003'], $this->nordnet->fetchCalls);
        self::assertSame(3, $result->claimed);
        self::assertSame(0, $result->done);
        self::assertSame(3, $result->reopened);
        self::assertSame(0, $result->failed);
        self::assertSame(
            ['ok' => 0, 'not_found' => 0, 'schema_mismatch' => 0, 'transient' => 3],
            $result->bySource['avanza'],
        );
        self::assertSame(
            ['ok' => 3, 'not_found' => 0, 'schema_mismatch' => 0, 'transient' => 0],
            $result->bySource['nordnet'],
        );
        self::assertSame($result->bySource, $this->sliceCompleteContext()['by_source']);
    }

    public function testBothSourcesTransientForOneJobReopensItWithNoRowAndCountsEachBucket(): void
    {
        $this->seedInstruments([['SE0000000001', 'a-1', 'nx-1']]);
        $this->enqueueAll();
        $this->avanza->fetchResponses = [new \Stockpicker\Error\Transient('avanza: HTTP 503')];
        $this->nordnet->fetchResponses = [new \Stockpicker\Error\Transient('nordnet: HTTP 503')];

        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        self::assertSame('pending', $this->queueStatus('SE0000000001'));
        self::assertSame(0, $this->ownerRowCount(), 'no row written for the job');
        self::assertSame(['SE0000000001'], $this->nordnet->fetchCalls, 'the second source is still attempted');
        self::assertSame(1, $result->reopened);
        self::assertSame(0, $result->done);
        self::assertSame(0, $result->failed);
        $transientOnly = ['ok' => 0, 'not_found' => 0, 'schema_mismatch' => 0, 'transient' => 1];
        self::assertSame($transientOnly, $result->bySource['avanza']);
        self::assertSame($transientOnly, $result->bySource['nordnet']);
    }

    public function testSchemaMismatchAndNotFoundLandInSeparateBuckets(): void
    {
        $this->seedInstruments([
            ['SE0000000001', 'a-1', 'nx-1'],
            ['SE0000000002', 'a-2', 'nx-2'],
        ]);
        $this->enqueueAll();
        $ts = new DateTimeImmutable('2026-09-09T12:00:00Z', new DateTimeZone('UTC'));
        // job 1: avanza SchemaMismatch, nordnet ok. job 2: avanza NotFound, nordnet ok.
        $this->avanza->fetchResponses = [
            new \Stockpicker\Error\SchemaMismatch('avanza: field gone'),
            new \Stockpicker\Error\NotFound('avanza: stale id'),
        ];
        $this->nordnet->fetchResponses = [
            $this->row('nordnet', 'SE0000000001', 2000, $ts),
            $this->row('nordnet', 'SE0000000002', 2100, $ts),
        ];

        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        self::assertSame('failed', $this->queueStatus('SE0000000001'));
        self::assertSame('failed', $this->queueStatus('SE0000000002'));
        self::assertSame(2, $this->ownerRowCount(), 'the working source is still stored for both jobs');
        self::assertSame(2, $result->failed);
        self::assertSame(
            ['ok' => 0, 'not_found' => 1, 'schema_mismatch' => 1, 'transient' => 0],
            $result->bySource['avanza'],
        );
        self::assertSame(
            ['ok' => 2, 'not_found' => 0, 'schema_mismatch' => 0, 'transient' => 0],
            $result->bySource['nordnet'],
        );
    }

    public function testTransientFromOneSourceAndNotFoundFromTheOtherOnTheSameJob(): void
    {
        // Reachable only now the `break` on Transient is gone: both sources are
        // attempted, one is transient and one is a hard miss.
        $this->seedInstruments([['SE0000000001', 'a-1', 'nx-1']]);
        $this->enqueueAll();
        $this->avanza->fetchResponses = [new \Stockpicker\Error\Transient('avanza: HTTP 503')];
        $this->nordnet->fetchResponses = [new \Stockpicker\Error\NotFound('nordnet: stale id')];

        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        // Any Transient wins the job-state precedence: reopened, not failed.
        self::assertSame('pending', $this->queueStatus('SE0000000001'));
        self::assertSame(1, $result->reopened);
        self::assertSame(0, $result->failed);
        self::assertSame(0, $result->done);
        self::assertSame(1, $result->bySource['avanza']['transient']);
        self::assertSame(1, $result->bySource['nordnet']['not_found']);
        self::assertSame(0, $result->bySource['avanza']['not_found']);
        self::assertSame(0, $result->bySource['nordnet']['transient']);
    }

    public function testOneNotFoundMidSliceLeavesEarlierJobsDoneAndSplitsTheBuckets(): void
    {
        // I/O matrix row 1: job 1 both ok -> done; job 2 avanza NotFound,
        // nordnet ok -> failed (nordnet row still written).
        $this->seedInstruments([
            ['SE0000000001', 'a-1', 'nx-1'],
            ['SE0000000002', 'a-2', 'nx-2'],
        ]);
        $this->enqueueAll();
        $ts = new DateTimeImmutable('2026-09-09T12:00:00Z', new DateTimeZone('UTC'));
        $this->avanza->fetchResponses = [
            $this->row('avanza', 'SE0000000001', 1000),
            new \Stockpicker\Error\NotFound('avanza: stale id'),
        ];
        $this->nordnet->fetchResponses = [
            $this->row('nordnet', 'SE0000000001', 2000, $ts),
            $this->row('nordnet', 'SE0000000002', 2100, $ts),
        ];

        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        self::assertSame('done', $this->queueStatus('SE0000000001'));
        self::assertSame('failed', $this->queueStatus('SE0000000002'));
        self::assertSame(3, $this->ownerRowCount(), 'job 2 nordnet row is still written');
        self::assertSame(1, $result->done);
        self::assertSame(1, $result->failed);
        self::assertSame(
            ['ok' => 1, 'not_found' => 1, 'schema_mismatch' => 0, 'transient' => 0],
            $result->bySource['avanza'],
        );
        self::assertSame(
            ['ok' => 2, 'not_found' => 0, 'schema_mismatch' => 0, 'transient' => 0],
            $result->bySource['nordnet'],
        );
    }

    public function testCleanSliceLogsSliceCompleteWithTheFullBreakdown(): void
    {
        $this->seedInstruments([['SE0000000001', '5479', 'nx-1']]);
        $this->enqueueAll();
        $this->avanza->fetchResponses = [$this->row('avanza', 'SE0000000001', 1000)];
        $this->nordnet->fetchResponses = [
            $this->row('nordnet', 'SE0000000001', 2000, new DateTimeImmutable('2026-09-09T12:00:00Z', new DateTimeZone('UTC'))),
        ];

        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        self::assertSame(['ok' => 1, 'not_found' => 0, 'schema_mismatch' => 0, 'transient' => 0], $result->bySource['avanza']);
        self::assertSame(['ok' => 1, 'not_found' => 0, 'schema_mismatch' => 0, 'transient' => 0], $result->bySource['nordnet']);

        $context = $this->sliceCompleteContext();
        self::assertSame(self::RUN_DATE, $context['run_date']);
        self::assertSame($result->bySource, $context['by_source']);
        self::assertSame(1, $context['claimed']);
        self::assertSame(1, $context['done']);
        self::assertSame(0, $context['failed']);
        self::assertSame(0, $context['reopened']);
    }

    public function testEmptyQueueStillLogsSliceCompleteWithAllZeroBuckets(): void
    {
        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        $zero = ['ok' => 0, 'not_found' => 0, 'schema_mismatch' => 0, 'transient' => 0];
        self::assertSame(['avanza' => $zero, 'nordnet' => $zero], $result->bySource);
        self::assertSame(['avanza' => $zero, 'nordnet' => $zero], $this->sliceCompleteContext()['by_source']);
    }

    public function testPeakMemoryStaysWellUnderTheWebPhpLimitForAFullBatch(): void
    {
        // batch_size default is 25, chosen for a comfortable margin under the
        // 256 MB web-PHP memory_limit (NFR8). Smoke guard only.
        $rows = [];
        $ts = new DateTimeImmutable('2026-09-09T12:00:00Z', new DateTimeZone('UTC'));
        for ($i = 1; $i <= 25; ++$i) {
            $isin = sprintf('SE00000000%02d', $i);
            $rows[] = [$isin, 'a-' . $i, 'nx-' . $i];
        }
        $this->seedInstruments($rows);
        $this->enqueueAll();
        foreach ($rows as [$isin]) {
            $this->avanza->fetchResponses[] = $this->row('avanza', $isin, 1000);
            $this->nordnet->fetchResponses[] = $this->row('nordnet', $isin, 2000, $ts);
        }

        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        self::assertSame(25, $result->done);
        self::assertLessThan(200 * 1024 * 1024, memory_get_peak_usage(true));
    }
}
