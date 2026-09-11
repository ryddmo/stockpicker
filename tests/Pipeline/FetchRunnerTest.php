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
    /**
     * A `by_source` bucket in canonical key order — keeps `assertSame` (which is
     * order-sensitive on arrays) terse across the matrix cases.
     *
     * @return array{ok: int, not_found: int, schema_mismatch: int, transient: int, retried: int, rate_limited: int}
     */
    private function bucket(
        int $ok = 0,
        int $notFound = 0,
        int $schemaMismatch = 0,
        int $transient = 0,
        int $retried = 0,
        int $rateLimited = 0,
    ): array {
        return [
            'ok' => $ok,
            'not_found' => $notFound,
            'schema_mismatch' => $schemaMismatch,
            'transient' => $transient,
            'retried' => $retried,
            'rate_limited' => $rateLimited,
        ];
    }

    /** How many log records carry exactly this message. */
    private function logMessageCount(string $message): int
    {
        return count(array_filter(
            $this->logHandler->getRecords(),
            static fn ($r): bool => $r['message'] === $message,
        ));
    }

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

        // Job 1: avanza ok, nordnet transient on every attempt (retries then
        // gives up) -> job 1 reopened. Job 2: both ok -> done.
        $this->avanza->fetchResponses = [
            $this->row('avanza', 'SE0000000001', 1000),
            $this->row('avanza', 'SE0000000002', 1100),
        ];
        $this->nordnet->fetchResponses = [
            new \Stockpicker\Error\Transient('nordnet: HTTP 503'),
            new \Stockpicker\Error\Transient('nordnet: HTTP 503'),
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
        // 3 attempts (default retry.max_attempts) -> 2 backoff sleeps of 1 s, 2 s
        // before job 1 gives up; job 2's calls then add the same-source spacing.
        self::assertSame([1.0, 2.0, 2.0, 2.0], $this->waits);
        self::assertSame(2, $result->bySource['nordnet']['retried']);
        self::assertSame(1, $result->bySource['nordnet']['transient']);
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

    public function testCleanMultiJobSliceLeavesNoClaimedRows(): void
    {
        $this->seedInstruments([
            ['SE0000000001', 'a-1', 'nx-1'],
            ['SE0000000002', 'a-2', 'nx-2'],
        ]);
        $this->enqueueAll();
        $ts = new DateTimeImmutable('2026-09-09T12:00:00Z', new DateTimeZone('UTC'));
        $this->avanza->fetchResponses = [
            $this->row('avanza', 'SE0000000001', 1000),
            $this->row('avanza', 'SE0000000002', 1100),
        ];
        $this->nordnet->fetchResponses = [
            $this->row('nordnet', 'SE0000000001', 2000, $ts),
            $this->row('nordnet', 'SE0000000002', 2100, $ts),
        ];

        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        self::assertSame(2, $result->done);
        self::assertSame(0, $result->staleFailed);
        self::assertSame(0, (new QueueRepository($this->pdo))->countByStatus(self::RUN_DATE)['claimed'] ?? 0);
    }

    public function testPastRunDateStaleClaimIsFailedAndSurfaced(): void
    {
        $this->seedInstruments([['SE0000000001', 'a-1', 'nx-1']]);
        $queue = new QueueRepository($this->pdo);
        $queue->enqueue('SE0000000001', '2026-09-08');
        $queue->claimBatch(
            '2026-09-08',
            1,
            (new DateTimeImmutable('now', new DateTimeZone('UTC')))->sub(new \DateInterval('PT20M')),
        );

        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        self::assertSame(['failed' => 1], $queue->countByStatus('2026-09-08'));
        self::assertSame(1, $result->staleFailed);
        self::assertSame(0, $result->reopened);
        self::assertSame(0, $result->claimed);
        self::assertSame(1, $this->sliceCompleteContext()['stale_failed']);
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
        self::assertSame($this->bucket(ok: 1), $result->bySource['avanza']);
        self::assertSame($this->bucket(ok: 1), $result->bySource['nordnet']);

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
            // avanza throws on every attempt: 3 per job (retry.max_attempts).
            $this->avanza->fetchResponses[] = new \Stockpicker\Error\Transient('avanza: HTTP 503');
            $this->avanza->fetchResponses[] = new \Stockpicker\Error\Transient('avanza: HTTP 503');
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
        // 3 jobs x (1 give-up) transient; 3 jobs x 2 retries each.
        self::assertSame($this->bucket(transient: 3, retried: 6), $result->bySource['avanza']);
        self::assertSame($this->bucket(ok: 3), $result->bySource['nordnet']);
        self::assertSame($result->bySource, $this->sliceCompleteContext()['by_source']);
    }

    public function testBothSourcesTransientForOneJobReopensItWithNoRowAndCountsEachBucket(): void
    {
        $this->seedInstruments([['SE0000000001', 'a-1', 'nx-1']]);
        $this->enqueueAll();
        $this->avanza->fetchResponses = array_fill(0, 3, new \Stockpicker\Error\Transient('avanza: HTTP 503'));
        $this->nordnet->fetchResponses = array_fill(0, 3, new \Stockpicker\Error\Transient('nordnet: HTTP 503'));

        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        self::assertSame('pending', $this->queueStatus('SE0000000001'));
        self::assertSame(0, $this->ownerRowCount(), 'no row written for the job');
        self::assertSame(
            ['SE0000000001', 'SE0000000001', 'SE0000000001'],
            $this->nordnet->fetchCalls,
            'the second source is still attempted, and retried to the cap',
        );
        self::assertSame(1, $result->reopened);
        self::assertSame(0, $result->done);
        self::assertSame(0, $result->failed);
        // each source retried to the cap (2 retries) then gave up (transient 1).
        $transientOnly = $this->bucket(transient: 1, retried: 2);
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
        self::assertSame($this->bucket(notFound: 1, schemaMismatch: 1), $result->bySource['avanza']);
        self::assertSame($this->bucket(ok: 2), $result->bySource['nordnet']);
    }

    public function testTransientFromOneSourceAndNotFoundFromTheOtherOnTheSameJob(): void
    {
        // Reachable only now the `break` on Transient is gone: both sources are
        // attempted, one is transient and one is a hard miss.
        $this->seedInstruments([['SE0000000001', 'a-1', 'nx-1']]);
        $this->enqueueAll();
        $this->avanza->fetchResponses = array_fill(0, 3, new \Stockpicker\Error\Transient('avanza: HTTP 503'));
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
        self::assertSame($this->bucket(ok: 1, notFound: 1), $result->bySource['avanza']);
        self::assertSame($this->bucket(ok: 2), $result->bySource['nordnet']);
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

        self::assertSame($this->bucket(ok: 1), $result->bySource['avanza']);
        self::assertSame($this->bucket(ok: 1), $result->bySource['nordnet']);

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

        $zero = $this->bucket();
        self::assertSame(['avanza' => $zero, 'nordnet' => $zero], $result->bySource);
        self::assertSame(['avanza' => $zero, 'nordnet' => $zero], $this->sliceCompleteContext()['by_source']);
    }

    // --- Story 2.4: retry with exponential backoff --------------------------

    public function testTransientThenSuccessRetriesAfterABackoffSleepAndStoresTheRow(): void
    {
        $this->seedInstruments([['SE0000000001', 'a-1', 'nx-1']]);
        $this->enqueueAll();
        $ts = new DateTimeImmutable('2026-09-09T12:00:00Z', new DateTimeZone('UTC'));
        $this->avanza->fetchResponses = [
            new \Stockpicker\Error\Transient('avanza: HTTP 503'),
            $this->row('avanza', 'SE0000000001', 1000),
        ];
        $this->nordnet->fetchResponses = [$this->row('nordnet', 'SE0000000001', 2000, $ts)];

        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        self::assertSame('done', $this->queueStatus('SE0000000001'));
        self::assertSame(2, $this->ownerRowCount());
        self::assertSame(1, $result->done);
        self::assertSame(0, $result->reopened);
        // exactly one backoff sleep: backoff_base * 2^0 = 1.0 s.
        self::assertSame([1.0], $this->waits);
        self::assertSame($this->bucket(ok: 1, retried: 1), $result->bySource['avanza']);
        self::assertTrue($this->logHandler->hasWarningThatContains('transient from source'));
    }

    public function testTransientOnEveryAttemptExhaustsTheCapAndReopensTheJob(): void
    {
        $this->seedInstruments([['SE0000000001', 'a-1', 'nx-1']]);
        $this->enqueueAll();
        $ts = new DateTimeImmutable('2026-09-09T12:00:00Z', new DateTimeZone('UTC'));
        $this->avanza->fetchResponses = array_fill(0, 5, new \Stockpicker\Error\Transient('avanza: HTTP 503'));
        $this->nordnet->fetchResponses = [$this->row('nordnet', 'SE0000000001', 2000, $ts)];

        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        self::assertSame('pending', $this->queueStatus('SE0000000001'), 'left pending for the next cron pass');
        self::assertSame(1, $this->ownerRowCount(), 'the working source still landed');
        self::assertSame(0, $result->done);
        self::assertSame(1, $result->reopened);
        // retry.max_attempts = 3 -> 2 backoff sleeps (1 s, 2 s), then give up.
        self::assertSame([1.0, 2.0], $this->waits);
        self::assertSame($this->bucket(transient: 1, retried: 2), $result->bySource['avanza']);
        self::assertSame(3, count($this->avanza->fetchCalls), 'exactly max_attempts fetch() calls');
        // The give-up warning fires exactly once, only on the final attempt.
        self::assertSame(1, $this->logMessageCount('fetchrunner: source exhausted fetch retries, job reopened'));
        self::assertSame(2, $this->logMessageCount('fetchrunner: transient from source, job will be retried'));
    }

    public function testTheTimeboxCutsTheRetryLoopShortAfterOneRetry(): void
    {
        // backoff_base 10 -> attempt 1 waits 10 s (fits the 15 s box, retry
        // taken); attempt 2 would wait 20 s, so elapsed + 20 >= 15 stops the
        // loop before the max_attempts cap.
        (new SettingsRepository($this->pdo))->set('retry.backoff_base', '10');
        $this->seedInstruments([['SE0000000001', 'a-1', 'nx-1']]);
        $this->enqueueAll();
        $ts = new DateTimeImmutable('2026-09-09T12:00:00Z', new DateTimeZone('UTC'));
        $this->avanza->fetchResponses = array_fill(0, 5, new \Stockpicker\Error\Transient('avanza: HTTP 503'));
        $this->nordnet->fetchResponses = [$this->row('nordnet', 'SE0000000001', 2000, $ts)];

        $result = $this->runner()->run(self::RUN_DATE, 15.0);

        self::assertSame('pending', $this->queueStatus('SE0000000001'));
        self::assertSame(1, $result->reopened);
        // exactly one backoff sleep before the box cuts the loop short.
        self::assertSame([10.0], $this->waits);
        self::assertSame($this->bucket(transient: 1, retried: 1), $result->bySource['avanza']);
        self::assertSame(2, count($this->avanza->fetchCalls), 'one retry, then the box wins before the cap');
        self::assertSame(1, $this->logMessageCount('fetchrunner: source exhausted fetch retries, job reopened'));
    }

    public function test429WidensTheBackoffAndHalvesTheRuntimeRateThenSucceeds(): void
    {
        $this->seedInstruments([['SE0000000001', 'a-1', 'nx-1']]);
        $this->enqueueAll();
        $ts = new DateTimeImmutable('2026-09-09T12:00:00Z', new DateTimeZone('UTC'));
        $this->avanza->fetchResponses = [
            new \Stockpicker\Error\RateLimited('avanza: HTTP 429 (rate limited)'),
            $this->row('avanza', 'SE0000000001', 1000),
        ];
        $this->nordnet->fetchResponses = [$this->row('nordnet', 'SE0000000001', 2000, $ts)];

        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        self::assertSame('done', $this->queueStatus('SE0000000001'));
        self::assertSame(2, $this->ownerRowCount());
        // widened backoff: min(backoff_max, backoff(1) * 4) = 4.0 s (vs 1.0 plain).
        self::assertSame([4.0], $this->waits);
        self::assertSame($this->bucket(ok: 1, retried: 1, rateLimited: 1), $result->bySource['avanza']);
        self::assertTrue($this->logHandler->hasWarningThatContains('rate limited'));
    }

    public function test429LowersTheSameSourceSpacingForLaterJobsInTheSlice(): void
    {
        $this->seedInstruments([
            ['SE0000000001', 'a-1', 'nx-1'],
            ['SE0000000002', 'a-2', 'nx-2'],
        ]);
        $this->enqueueAll();
        $ts = new DateTimeImmutable('2026-09-09T12:00:00Z', new DateTimeZone('UTC'));
        // job 1 avanza: 429 once then ok; job 2 avanza: ok.
        $this->avanza->fetchResponses = [
            new \Stockpicker\Error\RateLimited('avanza: HTTP 429'),
            $this->row('avanza', 'SE0000000001', 1000),
            $this->row('avanza', 'SE0000000002', 1100),
        ];
        $this->nordnet->fetchResponses = [
            $this->row('nordnet', 'SE0000000001', 2000, $ts),
            $this->row('nordnet', 'SE0000000002', 2100, $ts),
        ];

        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        self::assertSame('done', $this->queueStatus('SE0000000001'));
        self::assertSame('done', $this->queueStatus('SE0000000002'));
        // [0] job-1 widened backoff (4.0); [1] job-2 avanza spacing now
        // 1 / (0.5 / 2) = 4.0 s (was 2.0 at the base rate); [2] job-2 nordnet
        // spacing unchanged at 2.0 s.
        self::assertSame([4.0, 4.0, 2.0], $this->waits);
        self::assertSame(2, $result->done);
        self::assertSame(1, $result->bySource['avanza']['rate_limited']);
    }

    public function testRepeated429sDriveTheRateToTheFloorAndTheSpacingPlateaus(): void
    {
        // 4 avanza-only jobs, avanza returns 429 on every attempt. Each job
        // halves the runtime rate 3x; after 3 halvings it hits the floor
        // base_rate / 8 (0.5 / 8 = 0.0625) and stays there, so the same-source
        // spacing plateaus at 1 / 0.0625 = 16 s and never grows past it.
        $isins = ['SE0000000001', 'SE0000000002', 'SE0000000003', 'SE0000000004'];
        $this->seedInstruments(array_map(static fn (string $i): array => [$i, 'a-' . $i, null], $isins));
        $this->enqueueAll();
        $this->avanza->fetchResponses = array_fill(0, 12, new \Stockpicker\Error\RateLimited('avanza: HTTP 429'));

        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        foreach ($isins as $isin) {
            self::assertSame('pending', $this->queueStatus($isin), 'a 429-on-every-attempt job reopens pending');
        }
        // job 1: [4, 8] backoff (no spacing, first call); jobs 2-4 each add a
        // 16 s spacing then [4, 8] backoff. The spacing entry is always 16.0.
        self::assertSame(
            [4.0, 8.0, 16.0, 4.0, 8.0, 16.0, 4.0, 8.0, 16.0, 4.0, 8.0],
            $this->waits,
        );
        self::assertSame(16.0, max($this->waits), 'spacing plateaus at 1 / (base_rate / 8) and never grows past it');
        self::assertSame(16.0, $this->waits[2]);
        self::assertSame(16.0, $this->waits[5]);
        self::assertSame(16.0, $this->waits[8]);
        // per job: rate_limited >= 1 and transient = 1 (retries all exhausted).
        self::assertSame(
            $this->bucket(transient: 4, retried: 8, rateLimited: 12),
            $result->bySource['avanza'],
        );
        self::assertSame(4, $result->reopened);
        self::assertSame(4, $this->logMessageCount('fetchrunner: source exhausted fetch retries, job reopened'));
    }

    public function testAReducedRateSpacingThatWouldOverrunTheTimeboxIsSkipped(): void
    {
        // base rate 0.1 -> spacing 10 s; one 429 on job 1 halves it to 0.05 ->
        // spacing 20 s. Job 2's same-source spacing (20 s) would overrun the
        // 12 s box, so it is skipped — but the fetch itself still happens.
        (new SettingsRepository($this->pdo))->set('rate.avanza', '0.1');
        $this->seedInstruments([
            ['SE0000000001', 'a-1', null],
            ['SE0000000002', 'a-2', null],
        ]);
        $this->enqueueAll();
        $this->avanza->fetchResponses = [
            new \Stockpicker\Error\RateLimited('avanza: HTTP 429'),
            $this->row('avanza', 'SE0000000001', 1000),
            $this->row('avanza', 'SE0000000002', 1100),
        ];

        $result = $this->runner()->run(self::RUN_DATE, 12.0);

        self::assertSame('done', $this->queueStatus('SE0000000001'));
        self::assertSame('done', $this->queueStatus('SE0000000002'));
        // only the job-1 widened backoff (4 s) — the 20 s spacing before job 2
        // is gated out.
        self::assertSame([4.0], $this->waits);
        self::assertNotContains(20.0, $this->waits);
        self::assertContains('SE0000000002', $this->avanza->fetchCalls, 'the fetch still runs, only the sleep is skipped');
        self::assertSame($this->bucket(ok: 2, retried: 1, rateLimited: 1), $result->bySource['avanza']);
    }

    public function testARetriedTransientThenAHardMissEndsTheJobFailedNotPending(): void
    {
        // Deliberate precedence: the *final* classification of a source wins.
        // A retry taken on attempt 1 (Transient) does not make the job
        // retryable when attempt 2 returns a definitive NotFound — $sawTransient
        // is only set when the retry loop itself gives up on a transient.
        $this->seedInstruments([['SE0000000001', 'a-1', null]]);
        $this->enqueueAll();
        $this->avanza->fetchResponses = [
            new \Stockpicker\Error\Transient('avanza: HTTP 503'),
            new \Stockpicker\Error\NotFound('avanza: stale id'),
        ];

        $result = $this->runner()->run(self::RUN_DATE, 60.0);

        self::assertSame('failed', $this->queueStatus('SE0000000001'));
        self::assertSame(1, $result->failed);
        self::assertSame(0, $result->reopened);
        self::assertSame([1.0], $this->waits, 'the attempt-1 retry sleep was taken');
        self::assertSame($this->bucket(notFound: 1, retried: 1), $result->bySource['avanza']);
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
