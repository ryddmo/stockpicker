<?php

declare(strict_types=1);

namespace Stockpicker\Pipeline;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Stockpicker\Adapter\SourceAdapter;
use Stockpicker\Adapter\UniverseLister;
use Stockpicker\Error\AdapterError;
use Stockpicker\Error\NotFound;
use Stockpicker\Error\SchemaMismatch;
use Stockpicker\Store\Instrument;
use Stockpicker\Store\InstrumentRepository;
use Stockpicker\Store\RunRepository;
use Stockpicker\Store\SettingsRepository;

/**
 * Nightly reconciliation of the `instrument` table against the live Avanza
 * listing (AD-3: the single nightly writer of `instrument`). Step one of
 * `/cron/refill`, ahead of `Enqueue`.
 *
 * Matched on the cached Avanza `orderbookId`
 * (`instrument.avanza_orderbook_id` ↔ `UniverseEntry.avanzaOrderbookId`):
 * additions (with `first_seen`), delistings (`last_seen`, never deleted),
 * list/name changes, reactivations, and orderbookId rotation for a stable ISIN.
 * The listing carries no ISIN, so each not-yet-known `orderbookId` costs one
 * `resolveIsin()` call; the still-missing Nordnet ids are then resolved in a
 * second pass. Both HTTP passes are wall-clock-timeboxed in the cron path and
 * resumed on later runs.
 *
 * Every abort path writes **nothing** — no `instrument` row, no `ingest_run`
 * row — and the caller then skips `Enqueue`:
 *   (a) `listUniverse()` raises `SchemaMismatch` / `Transient` → logged `warning`,
 *       rethrown;
 *   (b) would-be delistings exceed `universe.max_delist` (default 25) → logged
 *       `error`, `SchemaMismatch` thrown. Evaluated before any write.
 *
 * `RunRepository::record('universe_sync', …)` runs last and only on success —
 * mirroring `Enqueue` / `FetchRunner`.
 */
final class UniverseSync
{
    private const DEFAULTS = [
        'universe.max_delist' => 25,
        'rate.avanza' => 0.5,
        'rate.nordnet' => 0.5,
    ];

    /** @var callable(float): void */
    private $sleep;

    /** @var array<string, float|null> per-source instant of the last outgoing call */
    private array $lastCallAt = ['avanza' => null, 'nordnet' => null];

    /**
     * @param (callable(float): void)|null $sleep injected throttle; default is a real sleep
     */
    public function __construct(
        private readonly UniverseLister $universe,
        private readonly SourceAdapter $nordnet,
        private readonly InstrumentRepository $instruments,
        private readonly SettingsRepository $settings,
        private readonly RunRepository $runs,
        private readonly LoggerInterface $logger,
        ?callable $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
    }

    /**
     * @param string     $runDate        Stockholm `Y-m-d`, supplied by the caller (never recomputed)
     * @param float|null $timeboxSeconds wall-clock budget for the two HTTP passes; null = unbounded (`bin/universe-sync.php`)
     *
     * @return array{added: int, removed: int, changed: int, reactivated: int, ids_resolved: int, ids_failed: int, deferred: int, active_after: int}
     *
     * @throws AdapterError listing fetch failed, or the mass-delist guard tripped
     */
    public function run(string $runDate, ?float $timeboxSeconds = null): array
    {
        $started = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // 1. Fetch the listing. A bad response aborts with zero writes.
        try {
            $listing = $this->universe->listUniverse();
        } catch (AdapterError $e) {
            $this->logger->warning('universe sync: listing fetch failed, aborting with no writes', [
                'error' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }

        /** @var array<string, \Stockpicker\Adapter\UniverseEntry> $fresh keyed by orderbookId */
        $fresh = [];
        foreach ($listing as $entry) {
            $fresh[$entry->avanzaOrderbookId] = $entry;
        }

        $stored = $this->instruments->all();
        /** @var array<string, Instrument> $storedByObId active + inactive rows with a cached id */
        $storedByObId = [];
        foreach ($stored as $inst) {
            if ($inst->avanzaOrderbookId !== null) {
                $storedByObId[$inst->avanzaOrderbookId] = $inst;
            }
        }

        // 2. Mass-delist guard — before any write. A stored row with a null
        //    cached id is never a delisting candidate (it is reconciled only
        //    once its orderbookId is discovered).
        $maxDelist = (int) $this->numericSetting('universe.max_delist');
        /** @var array<string, string> $delist  isin => isin */
        $delist = [];
        foreach ($storedByObId as $obId => $inst) {
            if ($inst->lastSeen === null && !isset($fresh[$obId])) {
                $delist[$inst->isin] = $inst->isin;
            }
        }
        if (count($delist) > $maxDelist) {
            $this->logger->error('universe sync: would-be delistings exceed universe.max_delist, aborting with no writes', [
                'delist_count' => count($delist),
                'max_delist' => $maxDelist,
            ]);

            throw new SchemaMismatch(sprintf(
                'universe sync: %d would-be delistings exceed universe.max_delist (%d)',
                count($delist),
                $maxDelist,
            ));
        }

        // 3. Read rates once; arm the deadline.
        $rate = [
            'avanza' => (float) $this->numericSetting('rate.avanza'),
            'nordnet' => (float) $this->numericSetting('rate.nordnet'),
        ];
        $deadline = $timeboxSeconds === null ? null : microtime(true) + $timeboxSeconds;

        $added = 0;
        $changed = 0;
        $reactivated = 0;
        $idsResolved = 0;
        $idsFailed = 0;
        $deferred = 0;

        /** @var array<string, true> ISINs already reconciled in this run (dedupe) */
        $seenIsin = [];

        // 4. Walk the fresh listing by orderbookId. (Array keys stringify to int
        //    for numeric ids — the entry's own field is the canonical string.)
        foreach ($fresh as $entry) {
            $obId = $entry->avanzaOrderbookId;
            $matched = $storedByObId[$obId] ?? null;

            if ($matched !== null) {
                // Pure SQL — applied regardless of the deadline.
                if ($matched->lastSeen !== null) {
                    $this->instruments->reactivate($matched->isin);
                    ++$reactivated;
                }
                if ($matched->list !== $entry->list || $matched->name !== $entry->name) {
                    $this->instruments->updateListAndName($matched->isin, $entry->list, $entry->name);
                    ++$changed;
                }

                continue;
            }

            if ($deadline !== null && microtime(true) >= $deadline) {
                ++$deferred;

                continue;
            }

            try {
                $this->throttle('avanza', $rate['avanza']);
                $isin = $this->universe->resolveIsin($obId);
            } catch (AdapterError $e) {
                $this->logger->warning('universe sync: could not resolve ISIN for a listed orderbookId', [
                    'orderbook_id' => $obId,
                    'error' => $e::class,
                    'message' => $e->getMessage(),
                ]);
                ++$idsFailed;

                continue;
            }

            if (isset($seenIsin[$isin])) {
                // Two listing entries resolved to the same ISIN this run — an
                // Avanza data glitch. Reconcile it once (against the first
                // orderbookId seen) and skip the rest, or the id would
                // flip-flop between them every night.
                $this->logger->warning('universe sync: two listed orderbookIds resolved to the same ISIN, skipping the later one', [
                    'orderbook_id' => $obId,
                    'isin' => $isin,
                ]);

                continue;
            }
            $seenIsin[$isin] = true;

            // Re-read the row for that ISIN (F2: guard the within-run / concurrent
            // duplicate-ISIN race — insert() is also dup-PK-safe).
            $row = $this->instruments->get($isin);

            if ($row === null) {
                $this->instruments->insert($isin, $entry->name, $entry->list, $runDate);
                $this->instruments->cacheAvanzaId($isin, $obId);
                ++$added;

                continue;
            }

            if ($row->avanzaOrderbookId === null) {
                // Adopt a null-id row (e.g. a dev seed row) by resolved ISIN.
                $this->instruments->cacheAvanzaId($isin, $obId);
                if ($row->lastSeen !== null) {
                    $this->instruments->reactivate($isin);
                    ++$reactivated;
                }
                if ($row->list !== $entry->list || $row->name !== $entry->name) {
                    $this->instruments->updateListAndName($isin, $entry->list, $entry->name);
                    ++$changed;
                }

                continue;
            }

            if ($row->avanzaOrderbookId !== $obId) {
                // orderbookId rotation for a stable ISIN — same instrument, new
                // id. Replace the id and drop it from the delist set; it is not
                // a delisting.
                $this->instruments->setAvanzaId($isin, $obId);
                unset($delist[$isin]);
                if ($row->lastSeen !== null) {
                    $this->instruments->reactivate($isin);
                    ++$reactivated;
                }
                if ($row->list !== $entry->list || $row->name !== $entry->name) {
                    $this->instruments->updateListAndName($isin, $entry->list, $entry->name);
                    ++$changed;
                }
            }
        }

        // 5. Timeboxed Nordnet id-resolution pass over every active row still
        //    missing an id — a transient failure on an earlier run heals here
        //    ("picked up later"). Avanza-id gaps are not retried here (the id
        //    comes only from a listing match).
        foreach ($this->instruments->allActive() as $isin => $inst) {
            if ($inst->nordnetInstrumentId !== null) {
                continue;
            }
            if ($deadline !== null && microtime(true) >= $deadline) {
                ++$deferred;

                continue;
            }

            try {
                $this->throttle('nordnet', $rate['nordnet']);
                $id = $this->nordnet->resolveId($inst);
                if ($id === '') {
                    throw new NotFound(sprintf('nordnet resolveId returned an empty id for %s', $isin));
                }
                $this->instruments->cacheNordnetId($isin, $id);
                ++$idsResolved;
            } catch (AdapterError $e) {
                $this->logger->warning('universe sync: could not resolve Nordnet id', [
                    'isin' => $isin,
                    'error' => $e::class,
                    'message' => $e->getMessage(),
                ]);
                ++$idsFailed;
            }
        }

        // 6. Delistings (pure SQL), then the churn line and the run-log row.
        $removed = 0;
        foreach ($delist as $isin) {
            $this->instruments->markInactive($isin, $runDate);
            ++$removed;
        }

        $activeAfter = count($this->instruments->allActive());

        $this->logger->info('universe sync complete', [
            'run_date' => $runDate,
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
            'reactivated' => $reactivated,
            'ids_resolved' => $idsResolved,
            'ids_failed' => $idsFailed,
            'deferred' => $deferred,
            'active_after' => $activeAfter,
        ]);

        $this->runs->record(
            'universe_sync',
            $runDate,
            $started,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $activeAfter,
            $added + $reactivated,
            $removed,
        );

        return [
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
            'reactivated' => $reactivated,
            'ids_resolved' => $idsResolved,
            'ids_failed' => $idsFailed,
            'deferred' => $deferred,
            'active_after' => $activeAfter,
        ];
    }

    /**
     * Between two calls to the *same* source, wait `1 / rate` seconds via the
     * injected sleeper (NFR3, AD-9). The first call to a source never waits.
     */
    private function throttle(string $source, float $rate): void
    {
        $last = $this->lastCallAt[$source] ?? null;
        if ($last !== null && $rate > 0) {
            $wait = 1.0 / $rate - (microtime(true) - $last);
            if ($wait > 0) {
                ($this->sleep)($wait);
            }
        }
        $this->lastCallAt[$source] = microtime(true);
    }

    /**
     * A settings value that must be a positive number, falling back to the hard
     * default. An absent key falls back silently; a present-but-unusable value
     * (non-numeric, zero, negative) falls back with one `warning`. Mirrors
     * FetchRunner::numericSetting().
     */
    private function numericSetting(string $key): int|float
    {
        $default = self::DEFAULTS[$key];
        $raw = $this->settings->get($key);

        if ($raw === null) {
            return $default;
        }

        if (!is_numeric($raw) || $raw + 0 <= 0) {
            $this->logger->warning('universe sync: unusable setting value, using default', [
                'key' => $key,
                'value' => $raw,
                'default' => $default,
            ]);

            return $default;
        }

        return $raw + 0;
    }
}
