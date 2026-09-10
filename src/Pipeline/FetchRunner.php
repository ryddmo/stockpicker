<?php

declare(strict_types=1);

namespace Stockpicker\Pipeline;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Stockpicker\Adapter\SourceAdapter;
use Stockpicker\Error\NotFound;
use Stockpicker\Error\RateLimited;
use Stockpicker\Error\SchemaMismatch;
use Stockpicker\Error\Transient;
use Stockpicker\Store\InstrumentRepository;
use Stockpicker\Store\OwnerCountRepository;
use Stockpicker\Store\QueueRepository;
use Stockpicker\Store\RunRepository;
use Stockpicker\Store\SettingsRepository;

/**
 * Drains `work_queue` within a wall-clock timebox (AD-5, NFR2). One slice:
 *
 *   1. reopen stale `claimed` rows (a crashed earlier slice)
 *   2. atomically claim up to `batch_size` `pending` jobs for the run date
 *   3. per job, before it: if the timebox elapsed, reopen this slice's still
 *      -`claimed` jobs and stop the loop; otherwise attempt Avanza then Nordnet,
 *      store each result via `OwnerCountRepository`, transition the job
 *   4. append one `fetch` row to `ingest_run` via `RunRepository` (AD-11) and
 *      return `FetchRunnerResult` counts
 *
 * `run()` has a single exit: the timebox path `break`s out of the job loop, so
 * the run-log write and the result are built once at the bottom and the timebox
 * slice is logged like any other. A throw from a repository call outside the
 * loop is not caught and (correctly) aborts the slice without a row.
 *
 * Calls are strictly serial and throttled: between two calls to the *same*
 * source, wait `1 / rate.<source>` seconds via the injected sleeper (NFR3,
 * AD-9). On a `Transient` from `fetch()` the runner retries per source per job
 * with exponential backoff (`retry.backoff_base * 2^(n-1)` s, clamped to
 * `retry.backoff_max`) up to `retry.max_attempts` total attempts, every backoff
 * sleep gated by the slice timebox (Story 2.4). A `RateLimited` (HTTP 429)
 * widens that retry's backoff (×4) and halves the in-memory call rate for that
 * source (floored at `base / 8`) for the rest of the slice. A source still
 * `Transient` after the loop reopens the whole job exactly as before. All
 * source access is through the `SourceAdapter` port and acts only on
 * `NormalizedRow` / the typed errors (AD-2). Owner-count rows are written only
 * via `OwnerCountRepository::upsert()`.
 */
final class FetchRunner
{
    private const SOURCES = [
        'avanza' => 'avanzaOrderbookId',
        'nordnet' => 'nordnetInstrumentId',
    ];

    private const DEFAULTS = [
        'batch_size' => 25,
        'queue.stale_after' => 900,
        'rate.avanza' => 0.5,
        'rate.nordnet' => 0.5,
        'retry.max_attempts' => 3,
        'retry.backoff_base' => 1.0,
        'retry.backoff_max' => 20.0,
    ];

    /**
     * A `RateLimited` (429) widens that retry's backoff by this factor and
     * halves the runtime call rate for the source, floored at `base / 8` (≤ 3
     * halvings). Structural, not operator knobs.
     */
    private const RATE_LIMIT_BACKOFF_FACTOR = 4.0;
    private const RATE_LIMIT_RATE_FLOOR_DIVISOR = 8.0;

    /** @var callable(float): void */
    private $sleep;

    /**
     * @param array<string, SourceAdapter> $adapters keyed 'avanza' | 'nordnet' (both required)
     * @param (callable(float): void)|null  $sleep    injected throttle; default is a real sleep
     *
     * @throws InvalidArgumentException when `$adapters` is missing a source
     */
    public function __construct(
        private readonly QueueRepository $queue,
        private readonly InstrumentRepository $instruments,
        private readonly OwnerCountRepository $ownerCounts,
        private readonly array $adapters,
        private readonly SettingsRepository $settings,
        private readonly LoggerInterface $logger,
        private readonly RunRepository $runs,
        ?callable $sleep = null,
    ) {
        foreach (array_keys(self::SOURCES) as $source) {
            if (!($adapters[$source] ?? null) instanceof SourceAdapter) {
                throw new InvalidArgumentException(sprintf('FetchRunner: no adapter provided for source "%s"', $source));
            }
        }

        $this->sleep = $sleep ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
    }

    public function run(string $runDate, float $timeboxSeconds): FetchRunnerResult
    {
        $start = microtime(true);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $batchSize = $this->intSetting('batch_size');
        $staleAfter = $this->intSetting('queue.stale_after');
        $rate = [
            'avanza' => $this->floatSetting('rate.avanza'),
            'nordnet' => $this->floatSetting('rate.nordnet'),
        ];
        // The base rate each slice restarts from — a 429 only lowers the runtime
        // `$rate` copy, floored at `$baseRate / 8`; `settings.rate.<source>` is
        // never written.
        $baseRate = $rate;

        // At least one attempt — a fractional or zero setting must never read
        // as "make no calls".
        $maxAttempts = max(1, $this->intSetting('retry.max_attempts'));
        $backoffBase = $this->floatSetting('retry.backoff_base');
        $backoffMax = $this->floatSetting('retry.backoff_max');

        $reopened = $this->queue->reopenStale($staleAfter, $now, $runDate);

        $jobs = $this->queue->claimBatch($runDate, $batchSize, $now);
        $claimed = count($jobs);

        $done = 0;
        $failed = 0;
        $rowsWritten = 0;
        $lastCalled = ['avanza' => false, 'nordnet' => false];

        // Per-source slice tally, one bucket per source so a source that is
        // never called still reports all-zero. Independent of the per-job
        // done/failed/reopened counts.
        $bySource = [];
        foreach (array_keys(self::SOURCES) as $source) {
            $bySource[$source] = [
                'ok' => 0,
                'not_found' => 0,
                'schema_mismatch' => 0,
                'transient' => 0,
                'retried' => 0,
                'rate_limited' => 0,
            ];
        }

        foreach ($jobs as $index => $job) {
            if (microtime(true) - $start >= $timeboxSeconds) {
                foreach (array_slice($jobs, $index) as $leftover) {
                    $this->queue->reopen($leftover->id);
                    ++$reopened;
                }

                break;
            }

            try {
                $instrument = $this->instruments->get($job->isin);
                if ($instrument === null) {
                    // FK RESTRICT makes this unreachable in Epic 1; fail the job
                    // loudly rather than crash the whole slice.
                    $this->logger->warning('fetchrunner: instrument row missing for claimed job', [
                        'isin' => $job->isin,
                        'run_date' => $job->runDate,
                    ]);
                    $this->queue->markFailed($job->id);
                    ++$failed;

                    continue;
                }

                $sawTransient = false;
                $failingSources = [];

                foreach (self::SOURCES as $source => $idField) {
                    if ($instrument->{$idField} === null) {
                        $this->logger->warning('fetchrunner: cached source id is null, skipping source', [
                            'isin' => $instrument->isin,
                            'source' => $source,
                            'run_date' => $job->runDate,
                        ]);

                        continue;
                    }

                    if ($lastCalled[$source]) {
                        // Same-source spacing, gated by the slice budget: after a
                        // 429 has reduced the rate this can be as long as
                        // `1 / (base_rate / 8)`, which must not run past the
                        // timebox.
                        $spacing = $rate[$source] > 0 ? 1.0 / $rate[$source] : 0.0;
                        if ($spacing > 0 && (microtime(true) - $start) + $spacing < $timeboxSeconds) {
                            ($this->sleep)($spacing);
                        }
                    }
                    $lastCalled[$source] = true;

                    // Retry loop, per source per job (Story 2.4). On Transient:
                    // sleep backoff(attempt) via the injected seam and call
                    // fetch() again, up to retry.max_attempts, always bounded by
                    // the slice timebox. A RateLimited (429) widens that backoff
                    // and halves the runtime rate for the rest of the slice.
                    $attempt = 0;
                    while (true) {
                        ++$attempt;
                        try {
                            $row = $this->adapters[$source]->fetch($instrument);
                            ++$bySource[$source]['ok'];
                            if ($this->ownerCounts->upsert($row, $job->runDate)) {
                                ++$rowsWritten;
                            }

                            break;
                        } catch (RateLimited $e) {
                            ++$bySource[$source]['rate_limited'];
                            $rate[$source] = max(
                                $baseRate[$source] / self::RATE_LIMIT_RATE_FLOOR_DIVISOR,
                                $rate[$source] / 2.0,
                            );
                            $wait = min($backoffMax, $this->backoff($attempt, $backoffBase) * self::RATE_LIMIT_BACKOFF_FACTOR);
                            $this->logger->warning('fetchrunner: rate limited by source, widening backoff and reducing rate', [
                                'isin' => $instrument->isin,
                                'source' => $source,
                                'run_date' => $job->runDate,
                                'attempt' => $attempt,
                                'error' => $e::class,
                                'new_rate' => $rate[$source],
                                'message' => $e->getMessage(),
                            ]);
                        } catch (Transient $e) {
                            $wait = min($backoffMax, $this->backoff($attempt, $backoffBase));
                        } catch (NotFound | SchemaMismatch $e) {
                            // Not retryable. SchemaMismatch is tallied separately
                            // from NotFound — the endpoint-shape-changed signal
                            // Story 2.6 alarms on.
                            $failingSources[] = $source;
                            ++$bySource[$source][$e instanceof SchemaMismatch ? 'schema_mismatch' : 'not_found'];
                            $this->logger->warning('fetchrunner: source failed for job', [
                                'isin' => $instrument->isin,
                                'source' => $source,
                                'run_date' => $job->runDate,
                                'error' => $e::class,
                                'message' => $e->getMessage(),
                            ]);

                            break;
                        }

                        // Shared Transient / RateLimited retry-or-give-up
                        // decision: the slice budget always wins.
                        if ($attempt >= $maxAttempts
                            || (microtime(true) - $start) + $wait >= $timeboxSeconds
                        ) {
                            $sawTransient = true;
                            ++$bySource[$source]['transient'];
                            $this->logger->warning('fetchrunner: source exhausted fetch retries, job reopened', [
                                'isin' => $instrument->isin,
                                'source' => $source,
                                'run_date' => $job->runDate,
                                'attempts' => $attempt,
                            ]);

                            break;
                        }

                        // A retry is actually going to happen — log it once, here,
                        // not on the final failed attempt.
                        $this->logger->warning('fetchrunner: transient from source, job will be retried', [
                            'isin' => $instrument->isin,
                            'source' => $source,
                            'run_date' => $job->runDate,
                            'attempt' => $attempt,
                            'error' => $e::class,
                            'message' => $e->getMessage(),
                        ]);

                        ($this->sleep)($wait);
                        ++$bySource[$source]['retried'];
                    }
                }

                if ($sawTransient) {
                    $this->queue->reopen($job->id);
                    ++$reopened;
                } elseif ($failingSources === []) {
                    $this->queue->markDone($job->id);
                    ++$done;
                } else {
                    $this->queue->markFailed($job->id);
                    ++$failed;
                }
            } catch (\Throwable $e) {
                // Any unexpected error (a raw PDOException, a bug) must not abort
                // the whole slice with jobs stuck `claimed`: fail this one job,
                // log loudly, and move on.
                $this->logger->error('fetchrunner: unexpected error handling job, marking failed', [
                    'isin' => $job->isin,
                    'run_date' => $job->runDate,
                    'error' => $e::class,
                    'message' => $e->getMessage(),
                ]);
                $this->queue->markFailed($job->id);
                ++$failed;
            }
        }

        $this->runs->record(
            'fetch',
            $runDate,
            $now,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $claimed,
            $done,
            $failed,
        );

        $this->logger->info('fetchrunner: slice complete', [
            'run_date' => $runDate,
            'by_source' => $bySource,
            'claimed' => $claimed,
            'done' => $done,
            'failed' => $failed,
            'reopened' => $reopened,
            'rows_written' => $rowsWritten,
        ]);

        return new FetchRunnerResult($claimed, $done, $failed, $reopened, $rowsWritten, $bySource);
    }

    /**
     * The un-clamped exponential backoff for a given attempt:
     * `backoff_base * 2^(n-1)` seconds. The caller clamps to `retry.backoff_max`
     * (and, for a 429, multiplies by the rate-limit factor first).
     */
    private function backoff(int $attempt, float $base): float
    {
        return $base * (2 ** ($attempt - 1));
    }

    private function intSetting(string $key): int
    {
        return (int) $this->numericSetting($key);
    }

    private function floatSetting(string $key): float
    {
        return (float) $this->numericSetting($key);
    }

    /**
     * Read a settings value that must be a positive number, falling back to the
     * hard default. A `null` (absent) key falls back silently; a *present* but
     * unusable value (non-numeric, zero, negative) falls back with one
     * `warning` naming the key and the raw value.
     */
    private function numericSetting(string $key): int|float
    {
        $default = self::DEFAULTS[$key];
        $raw = $this->settings->get($key);

        if ($raw === null) {
            return $default;
        }

        if (!is_numeric($raw) || $raw + 0 <= 0) {
            $this->logger->warning('fetchrunner: unusable setting value, using default', [
                'key' => $key,
                'value' => $raw,
                'default' => $default,
            ]);

            return $default;
        }

        return $raw + 0;
    }
}
