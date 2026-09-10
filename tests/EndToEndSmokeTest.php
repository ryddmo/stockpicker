<?php

declare(strict_types=1);

namespace Stockpicker\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Stockpicker\Adapter\NormalizedRow;
use Stockpicker\Error\Transient;
use Stockpicker\Pipeline\Enqueue;
use Stockpicker\Pipeline\FetchRunner;
use Stockpicker\Store\IngestRun;
use Stockpicker\Store\InstrumentRepository;
use Stockpicker\Store\InstrumentSeeder;
use Stockpicker\Store\OwnerCountRepository;
use Stockpicker\Store\QueueRepository;
use Stockpicker\Store\RunRepository;
use Stockpicker\Store\SettingsRepository;
use Stockpicker\Tests\Store\StoreTestCase;
use Stockpicker\Tests\Support\FakeSourceAdapter;

/**
 * The epic exit criterion made executable: drive the whole Epic 1 pipe —
 * `Enqueue` -> a loop of time-boxed `FetchRunner` slices -> `owner_count_daily`
 * + `ingest_run` — as one run over the full `InstrumentSeeder::LIST` seed list
 * across multiple `/cron/work` passes, the way a night actually happens.
 *
 * Only the `SourceAdapter` boundary is faked (`FakeSourceAdapter` for avanza and
 * nordnet, plus the injected no-op sleeper); `Enqueue`, `FetchRunner`, and every
 * repository are the real production classes running against the real test DB.
 *
 * Asserts the three epic AC scenarios from the spec's I/O matrix:
 *   - per-source coverage for most seed instruments,
 *   - an unresolved-id instrument skipped without stopping the run,
 *   - a `Transient` job that recovers across passes,
 * plus reconciling `ingest_run` counters and a hard pass cap.
 */
final class EndToEndSmokeTest extends StoreTestCase
{
    private const RUN_DATE = '2026-09-09';
    private const PASS_CAP = 50;
    private const COVERAGE_THRESHOLD = 16;

    private FakeSourceAdapter $avanza;
    private FakeSourceAdapter $nordnet;
    private TestHandler $logHandler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->avanza = new FakeSourceAdapter('avanza');
        $this->nordnet = new FakeSourceAdapter('nordnet');
        $this->logHandler = new TestHandler();
    }

    public function testFullPipeDrainsTheSeedListAcrossPassesAndReconciles(): void
    {
        // --- Universe: all 20 seed rows, in claim order (work_queue.id asc =
        // Enqueue insert order = InstrumentRepository::all() order = ISIN asc). ---
        $seed = InstrumentSeeder::LIST;
        usort($seed, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        $isins = array_column($seed, 0);
        self::assertCount(20, $isins);

        // The unresolved-id instrument (both cached ids NULL) and the transient
        // instrument. Keeping the transient one last in claim order keeps the
        // FIFO response ordering tractable (spec Design Notes): its pass-2 fetch
        // sits cleanly after every pass-1 response.
        $nullIdIsin = $isins[18];
        $transientIsin = $isins[19];

        foreach ($isins as $isin) {
            if ($isin === $nullIdIsin) {
                $this->seedInstrument($isin, null, null);
            } else {
                $this->seedInstrument($isin, 'a-' . $isin, 'nx-' . $isin);
            }
        }

        // --- Queue FakeSourceAdapter responses in exact fetch order. ---
        // Pass 1: every instrument except the null-id one is fetched avanza then
        // nordnet, in claim order. The transient instrument's nordnet call throws
        // `Transient` on every attempt (FetchRunner retries to `retry.max_attempts`
        // then gives up, so the job reopens); its avanza row is already written.
        // `row()` gives nordnet a source timestamp and avanza none (matching the
        // real adapters), so both `OwnerCountRepository::asOfDate()` precedence
        // branches run: nordnet dates from `$ts`, avanza from the run-date override.
        // `$ts` (12:00Z -> 14:00 Stockholm) keeps the nordnet rows on the run date.
        $ts = new DateTimeImmutable('2026-09-09T12:00:00Z', new DateTimeZone('UTC'));
        foreach ($isins as $isin) {
            if ($isin === $nullIdIsin) {
                continue; // null cached ids -> FetchRunner skips both sources
            }

            $this->avanza->fetchResponses[] = $this->row('avanza', $isin, 1_000);
            if ($isin === $transientIsin) {
                // one queued Transient per attempt up to the default cap of 3.
                $this->nordnet->fetchResponses[] = new Transient('nordnet: HTTP 503');
                $this->nordnet->fetchResponses[] = new Transient('nordnet: HTTP 503');
                $this->nordnet->fetchResponses[] = new Transient('nordnet: HTTP 503');
            } else {
                $this->nordnet->fetchResponses[] = $this->row('nordnet', $isin, 2_000, $ts);
            }
        }
        // Pass 2: the reopened transient job is re-fetched on both sources.
        $this->avanza->fetchResponses[] = $this->row('avanza', $transientIsin, 1_000);
        $this->nordnet->fetchResponses[] = $this->row('nordnet', $transientIsin, 2_000, $ts);

        // --- Run the pipe: Enqueue, then a drain loop of FetchRunner slices. ---
        $enqueued = (new Enqueue(
            new QueueRepository($this->pdo),
            new InstrumentRepository($this->pdo),
            new RunRepository($this->pdo),
        ))->run(self::RUN_DATE);
        self::assertSame(20, $enqueued);

        $passes = 0;
        while (true) {
            $this->runner()->run(self::RUN_DATE, 60.0);
            ++$passes;

            $status = (new QueueRepository($this->pdo))->countByStatus(self::RUN_DATE);
            if (array_diff(array_keys($status), ['done', 'failed']) === []) {
                break;
            }
            if ($passes >= self::PASS_CAP) {
                self::fail(sprintf(
                    'work_queue did not drain within %d passes; remaining statuses: %s',
                    self::PASS_CAP,
                    json_encode($status, JSON_THROW_ON_ERROR),
                ));
            }
        }

        // --- Scenario: Transient then recovery — the run needs >= 2 passes. ---
        self::assertGreaterThanOrEqual(2, $passes, 'the first-pass Transient forces a second pass');

        // --- Scenario: Full drain — queue holds only done/failed, summing to 20. ---
        $status = (new QueueRepository($this->pdo))->countByStatus(self::RUN_DATE);
        self::assertSame([], array_diff(array_keys($status), ['done', 'failed']));
        self::assertSame(20, array_sum($status));
        $doneCount = $status['done'] ?? 0;
        $failedCount = $status['failed'] ?? 0;
        // The all-fakes universe is deterministic: nothing fails, so 19 of the 20
        // instruments (all but the null-id one) get both source rows.
        self::assertSame(0, $failedCount, 'no job fails in the all-fakes universe');

        // Each of the 18 always-valid instruments (all but the null-id and the
        // transient one) ends `done`.
        $alwaysValid = array_values(array_diff($isins, [$nullIdIsin, $transientIsin]));
        self::assertCount(18, $alwaysValid);
        foreach ($alwaysValid as $isin) {
            self::assertSame('done', $this->queueStatus($isin));
        }

        // --- Scenario: per-source owner_count_daily coverage. ---
        $ownerCounts = new OwnerCountRepository($this->pdo);
        $perSourceCovered = 0;
        foreach ($isins as $isin) {
            $hasAvanza = $ownerCounts->get($isin, 'avanza', self::RUN_DATE) !== null;
            $hasNordnet = $ownerCounts->get($isin, 'nordnet', self::RUN_DATE) !== null;
            if ($hasAvanza && $hasNordnet) {
                ++$perSourceCovered;
            }
        }
        // Deterministic result: exactly 19 (well above the frozen AC's "most"
        // threshold of >= 16). Only the null-id instrument writes no row.
        self::assertGreaterThanOrEqual(self::COVERAGE_THRESHOLD, $perSourceCovered);
        self::assertSame(19, $perSourceCovered, 'every instrument but the null-id one is covered per source');
        self::assertSame(
            $perSourceCovered * 2,
            (int) $this->pdo
                ->query("SELECT COUNT(*) FROM owner_count_daily WHERE as_of_date = '" . self::RUN_DATE . "'")
                ->fetchColumn(),
        );

        // --- End-to-end value round-trip: the fake's NormalizedRow payload is
        // persisted intact through OwnerCountRepository, not just a row shell. ---
        $sampleIsin = $alwaysValid[0];
        self::assertSame(1000, (int) $ownerCounts->get($sampleIsin, 'avanza', self::RUN_DATE)['number_of_owners']);
        self::assertSame(2000, (int) $ownerCounts->get($sampleIsin, 'nordnet', self::RUN_DATE)['number_of_owners']);

        // --- Scenario: ingest_run counters reconcile with the final queue. ---
        $runs = (new RunRepository($this->pdo))->forRunDate(self::RUN_DATE);
        $enqueueRuns = array_values(array_filter($runs, static fn (IngestRun $r): bool => $r->runType === 'enqueue'));
        $fetchRuns = array_values(array_filter($runs, static fn (IngestRun $r): bool => $r->runType === 'fetch'));

        self::assertCount(1, $enqueueRuns, 'exactly one enqueue run row');
        self::assertGreaterThanOrEqual(1, count($fetchRuns), 'at least one fetch run row');
        self::assertSame(20, $enqueueRuns[0]->instrumentCount);
        self::assertSame(20, $enqueueRuns[0]->okCount);

        // done/failed are terminal single transitions, so the fetch rows' ok/fail
        // counts sum to the final queue composition.
        $sumOk = array_sum(array_map(static fn (IngestRun $r): int => $r->okCount, $fetchRuns));
        $sumFail = array_sum(array_map(static fn (IngestRun $r): int => $r->failCount, $fetchRuns));
        self::assertSame($doneCount, $sumOk, 'summed fetch ok_count reconciles with queue done count');
        self::assertSame($failedCount, $sumFail, 'summed fetch fail_count reconciles with queue failed count');
        self::assertSame(20, $sumOk + $sumFail);

        // --- Scenario: unresolved source id — done, no row, never blocks. ---
        self::assertSame('done', $this->queueStatus($nullIdIsin));
        self::assertSame(0, $ownerCounts->countForIsin($nullIdIsin), 'the null-id instrument writes no row');
        self::assertNotContains($nullIdIsin, $this->avanza->fetchCalls, 'null-id source is never called');
        self::assertNotContains($nullIdIsin, $this->nordnet->fetchCalls, 'null-id source is never called');
        self::assertTrue($this->logHandler->hasWarningThatContains('cached source id is null'));

        // --- Scenario: transient then recovery — done, both rows, retried. ---
        self::assertSame('done', $this->queueStatus($transientIsin));
        self::assertNotNull($ownerCounts->get($transientIsin, 'avanza', self::RUN_DATE));
        self::assertNotNull($ownerCounts->get($transientIsin, 'nordnet', self::RUN_DATE));
        self::assertSame(
            4,
            count(array_filter($this->nordnet->fetchCalls, static fn (string $i): bool => $i === $transientIsin)),
            'the transient job is retried to the cap on pass 1 (3 calls) then re-fetched once it recovers on pass 2',
        );
        self::assertTrue($this->logHandler->hasWarningThatContains('transient from source'));
    }

    /**
     * A fresh FetchRunner per pass (as `/cron/work` would be a fresh request),
     * sharing the fake adapters' FIFO state and the log handler across passes.
     */
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
            static function (float $seconds): void {
                // injected no-op sleeper — the throttle is exercised by
                // FetchRunnerTest; here it must not slow the drain loop.
            },
        );
    }

    private function logger(): LoggerInterface
    {
        return new Logger('e2e', [$this->logHandler]);
    }

    private function seedInstrument(string $isin, ?string $avanzaId, ?string $nordnetId): void
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
}
