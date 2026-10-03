<?php

declare(strict_types=1);

namespace Stockpicker\Pipeline;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Stockpicker\Adapter\ShortPositionSource;
use Stockpicker\Error\AdapterError;
use Stockpicker\Error\NotFound;
use Stockpicker\Error\SchemaMismatch;
use Stockpicker\Store\RunRepository;
use Stockpicker\Store\SettingsRepository;
use Stockpicker\Store\ShortPositionRepository;

/**
 * spec-short-interest-data — the nightly snapshot of Finansinspektionen's
 * aggregated short positions. The single writer of `short_position` (AD-3).
 * Runs once per night as an isolated step at the tail of `/cron/derive`
 * (inside its once-per-day guard).
 *
 * Fetches through the ShortPositionSource port, upserts the whole file under
 * the run date (AD-4), and records one `ingest_run` row of type `shorts`:
 *  - success → `completed`, ok_count = rows written;
 *  - SchemaMismatch → `alarmed` (alarm flag + e-mail like UniverseSync), nothing written;
 *  - Transient → `failed`, nothing written; the previous snapshot stays current.
 *
 * Never throws an AdapterError: the caller's route response and the digest
 * must not depend on FI (spec). Unexpected non-adapter errors (e.g. a DB
 * failure) are recorded as `failed` and rethrown for the caller's own
 * catch-and-log.
 */
final class ShortPositionSync
{
    public const RUN_TYPE = 'shorts';

    /** @var callable(string, string, string): bool */
    private $sendMail;

    public function __construct(
        private readonly ShortPositionSource $source,
        private readonly ShortPositionRepository $positions,
        private readonly RunRepository $runs,
        private readonly SettingsRepository $settings,
        private readonly LoggerInterface $logger,
        ?callable $sendMail = null,
    ) {
        $this->sendMail = $sendMail ?? static function (string $to, string $subject, string $message): bool {
            return @mail($to, $subject, $message);
        };
    }

    /**
     * @param string $runDate Stockholm `Y-m-d` — the snapshot date
     *
     * @return array{status: string, rows: int} status: ok | schema_mismatch | not_found | transient
     */
    public function run(string $runDate): array
    {
        $started = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $runId = $this->runs->start(self::RUN_TYPE, $runDate, $started);

        try {
            $rows = $this->source->fetchAggregated();
            $written = $this->positions->upsertSnapshot($runDate, $rows, new DateTimeImmutable('now', new DateTimeZone('UTC')));
        } catch (AdapterError $e) {
            $mismatch = $e instanceof SchemaMismatch;
            $this->logger->warning('short-position sync: FI fetch failed, previous snapshot stays current', [
                'error' => $e::class,
                'message' => $e->getMessage(),
            ]);
            // A SchemaMismatch is recorded like any other step's alarm
            // (RunRepository turns it into `alarmed`); a Transient is `failed`.
            $this->runs->finish(
                $runId,
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
                0,
                0,
                1,
                ['fi' => self::tally(match (true) {
                    $mismatch => 'schema_mismatch',
                    $e instanceof NotFound => 'not_found',
                    default => 'transient',
                })],
                $mismatch ? 'completed' : 'failed',
                $mismatch,
            );
            if ($mismatch) {
                $this->sendAlarm($runDate, $runId);
            }

            return ['status' => $mismatch ? 'schema_mismatch' : ($e instanceof NotFound ? 'not_found' : 'transient'), 'rows' => 0];
        } catch (\Throwable $e) {
            $this->runs->finish($runId, new DateTimeImmutable('now', new DateTimeZone('UTC')), 0, 0, 1, [], 'failed');

            throw $e;
        }

        $this->runs->finish(
            $runId,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $written,
            $written,
            0,
            ['fi' => self::tally('ok', $written)],
        );
        $this->logger->info('short-position sync complete', ['run_date' => $runDate, 'rows' => $written]);

        return ['status' => 'ok', 'rows' => $written];
    }

    /**
     * The same four-key by_source shape as the other runs.
     *
     * @return array{ok: int, not_found: int, schema_mismatch: int, transient: int}
     */
    private static function tally(string $outcome, int $count = 1): array
    {
        $tally = ['ok' => 0, 'not_found' => 0, 'schema_mismatch' => 0, 'transient' => 0];
        $tally[$outcome] = $count;

        return $tally;
    }

    private function sendAlarm(string $runDate, int $runId): void
    {
        $recipient = $this->settings->get('alarm.email');
        if (is_string($recipient) && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            ($this->sendMail)($recipient, 'stockpicker schema mismatch alarm', sprintf(
                "Run %d (shorts) on %s recorded a schema mismatch in FI's short-position file.\n",
                $runId,
                $runDate,
            ));
        }
    }
}
