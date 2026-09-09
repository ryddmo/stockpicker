<?php

declare(strict_types=1);

namespace Stockpicker\Store;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Stockpicker\Adapter\SourceAdapter;
use Stockpicker\Error\AdapterError;
use Stockpicker\Error\NotFound;

/**
 * Story 1.7 interim source-id resolver — the testable core of
 * bin/resolve-ids.php (thin wrapper, like InstrumentSeeder / seed-instruments).
 *
 * For every `instrument` row missing a cached `avanza_orderbook_id` and/or
 * `nordnet_instrument_id`, call the matching adapter's pure `resolveId()` and
 * persist a hit write-once via `InstrumentRepository::cacheAvanzaId()` /
 * `cacheNordnetId()` (an already-set id is left alone). A NotFound /
 * SchemaMismatch / Transient logs a `warning` and the loop continues.
 *
 * This is a deliberate, scoped, interim second writer of `instrument` (the two
 * id columns only) ahead of Epic 2's UniverseSync — AD-3's accepted exception.
 */
final class SourceIdResolver
{
    /** @var array<string, array{field: string, cache: string}> */
    private const SOURCES = [
        'avanza' => ['field' => 'avanzaOrderbookId', 'cache' => 'cacheAvanzaId'],
        'nordnet' => ['field' => 'nordnetInstrumentId', 'cache' => 'cacheNordnetId'],
    ];

    /**
     * @param array<string, SourceAdapter> $adapters keyed 'avanza' | 'nordnet' (both required)
     *
     * @return array{resolved: int, skipped: int, failed: int}
     *              `resolved` / `failed` count per source attempt; `skipped`
     *              counts whole instruments that already had every id.
     *
     * @throws InvalidArgumentException when `$adapters` is missing a source
     */
    public static function resolve(
        InstrumentRepository $instruments,
        array $adapters,
        LoggerInterface $logger,
    ): array {
        foreach (array_keys(self::SOURCES) as $source) {
            if (!($adapters[$source] ?? null) instanceof SourceAdapter) {
                throw new InvalidArgumentException(sprintf('SourceIdResolver: no adapter provided for source "%s"', $source));
            }
        }

        $resolved = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($instruments->all() as $isin => $instrument) {
            $needed = array_filter(
                self::SOURCES,
                static fn (array $s): bool => $instrument->{$s['field']} === null,
            );

            if ($needed === []) {
                ++$skipped;

                continue;
            }

            foreach ($needed as $source => $spec) {
                try {
                    $id = $adapters[$source]->resolveId($instrument);

                    if ($id === '') {
                        // An empty id would be persisted write-once and
                        // permanently poison the column — treat it as a failure.
                        throw new NotFound(sprintf('%s resolveId returned an empty id for %s', $source, $isin));
                    }

                    $instruments->{$spec['cache']}((string) $isin, $id);
                    ++$resolved;
                } catch (AdapterError $e) {
                    ++$failed;
                    $logger->warning('resolve-ids: could not resolve source id', [
                        'isin' => $isin,
                        'source' => $source,
                        'error' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }

        return ['resolved' => $resolved, 'skipped' => $skipped, 'failed' => $failed];
    }
}
