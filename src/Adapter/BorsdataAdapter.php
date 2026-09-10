<?php

declare(strict_types=1);

namespace Stockpicker\Adapter;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Stockpicker\Error\AdapterError;
use Stockpicker\Error\SchemaMismatch;

/**
 * Börsdata universe/symbology adapter. NOT a SourceAdapter — Börsdata is used
 * only to define the tradable Swedish universe (Story 2.1), never for owner
 * counts. Its single job is `listUniverse()`: two REST calls
 * (`/v1/markets` + `/v1/instruments`), filtered to the four target Nasdaq
 * Stockholm markets, returned as a validated `list<UniverseEntry>`.
 *
 * All Börsdata HTTP, the base URL, the `authKey` auth, and every JSON-shape
 * assumption live inside this class (AD-1). Any missing / renamed / wrong-typed
 * field, an uncorrelatable market response, or an empty target-market universe
 * raises SchemaMismatch and returns nothing — never a partial list (AD-7).
 * Transient failures (connect error, timeout, 429, 5xx) map to Transient with a
 * single retry via HandlesTransientHttp.
 *
 * Börsdata API facts (confirm against the live `bin/show-universe.php` run and
 * record the specifics in the story's Implementation Notes):
 *  - Base https://apiservice.borsdata.se/v1 ; `?authKey=<key>` on every call.
 *  - Rate limit 100 calls / 10 s; 429 carries Retry-After. A sync is ~2 calls.
 *  - GET /v1/markets     -> { markets:     [ { id, name, countryId, isIndex, exchangeName } ] }
 *  - GET /v1/instruments -> { instruments: [ { insId, name, isin, ticker, instrument, marketId, ... } ] }
 *  - `instrument` type ids: 0 = Stocks (common shares), 1 = Pref (preference
 *    shares). Everything else (indices, sectors, currencies, ADRs, units, …) is
 *    excluded.
 *  - Swedish `countryId` is 1. The target market `name` strings are matched
 *    case-insensitively: "Large Cap" / "Mid Cap" / "Small Cap", and any name
 *    beginning "First North" — all constrained to `countryId === 1` so the
 *    identically named Nasdaq Copenhagen / Helsinki segments are excluded.
 */
final class BorsdataAdapter
{
    use HandlesTransientHttp;

    private const BASE_URI = 'https://apiservice.borsdata.se/v1';
    private const SWEDEN_COUNTRY_ID = 1;

    /** Börsdata `instrument` type ids kept in the universe: common shares + preference shares. */
    private const EQUITY_TYPE_IDS = [0, 1];

    /** All four distinct list labels must correlate to a live market, or the response is rejected. */
    private const TARGET_LABELS = [
        UniverseEntry::LIST_LC,
        UniverseEntry::LIST_MC,
        UniverseEntry::LIST_SC,
        UniverseEntry::LIST_FIRST_NORTH,
    ];

    public function __construct(
        private readonly ClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
    ) {
    }

    /**
     * @return list<UniverseEntry> one entry per target-market instrument, ISIN-sorted, deduped by ISIN
     *
     * @throws \Stockpicker\Error\Transient      connect error / timeout / 429 / 5xx, already retried once
     * @throws \Stockpicker\Error\SchemaMismatch a consumed field is missing/renamed/wrong-typed, the market
     *                                           response cannot be correlated, or the target universe is empty
     */
    public function listUniverse(): array
    {
        try {
            return $this->withOneRetry(fn (): array => $this->buildUniverse());
        } catch (AdapterError $e) {
            $this->logger->warning('borsdata listUniverse failed', [
                'error' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @return list<UniverseEntry>
     */
    private function buildUniverse(): array
    {
        $labels = $this->targetMarketLabels();

        $out = [];
        $typesOnTargetMarkets = [];
        foreach ($this->instruments() as $raw) {
            $marketId = $raw['marketId'] ?? null;
            $label = (is_int($marketId) || is_string($marketId)) ? ($labels[(string) $marketId] ?? null) : null;
            if ($label === null) {
                continue; // not one of the four target markets
            }

            $type = $raw['instrument'] ?? null;
            if ((is_int($type) || is_string($type)) && !in_array($type, $typesOnTargetMarkets, true)) {
                $typesOnTargetMarkets[] = $type;
            }

            if (!in_array($type, self::EQUITY_TYPE_IDS, true)) {
                continue; // index / sector / currency / ADR / unit / …
            }

            $isin = $raw['isin'] ?? null;
            if (!is_string($isin) || !preg_match('/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/', $isin)) {
                $this->logger->warning('borsdata: dropping instrument with blank/malformed isin', [
                    'insId' => $raw['insId'] ?? null,
                    'name' => $raw['name'] ?? null,
                    'isin' => $isin,
                ]);

                continue;
            }

            $name = $raw['name'] ?? null;
            if (!is_string($name) || trim($name) === '') {
                $this->logger->warning('borsdata: dropping instrument with empty name', [
                    'insId' => $raw['insId'] ?? null,
                    'isin' => $isin,
                ]);

                continue;
            }

            $out[$isin] = new UniverseEntry($isin, trim($name), $label);
        }

        if ($out === []) {
            throw new SchemaMismatch(
                'borsdata /v1/instruments: target-market universe came back empty '
                . '(no instrument correlated to Large/Mid/Small Cap or First North)'
            );
        }

        ksort($out);

        $counts = array_count_values(array_map(static fn (UniverseEntry $e): string => $e->list, $out));
        sort($typesOnTargetMarkets);
        $this->logger->info('borsdata: universe built', [
            'markets' => $labels,
            'kept_instrument_types' => self::EQUITY_TYPE_IDS,
            'types_on_target_markets' => $typesOnTargetMarkets,
            'counts' => $counts,
            'total' => count($out),
        ]);

        return array_values($out);
    }

    /**
     * Build the `marketId => UniverseEntry::LIST_*` map for the four target
     * Swedish markets from `/v1/markets`. Raises if the response shape is wrong
     * or if any of the four distinct labels (LC, MC, SC, First North) fails to
     * correlate to a live market — a partial map would silently drop a cap tier.
     *
     * @return array<string, string>
     */
    private function targetMarketLabels(): array
    {
        $markets = $this->requestJson($this->http, 'GET', self::BASE_URI . '/markets', [
            'query' => ['authKey' => $this->apiKey],
            'headers' => ['Accept' => 'application/json'],
        ])['markets'] ?? null;

        if (!is_array($markets)) {
            throw new SchemaMismatch('borsdata /v1/markets: "markets" missing or not a list');
        }

        $labels = [];
        foreach ($markets as $market) {
            if (!is_array($market)) {
                throw new SchemaMismatch('borsdata /v1/markets: an entry is not an object');
            }

            $id = $market['id'] ?? null;
            if (!is_int($id) && !is_string($id)) {
                throw new SchemaMismatch('borsdata /v1/markets: "id" missing or not a scalar');
            }

            $name = $market['name'] ?? null;
            if (!is_string($name)) {
                throw new SchemaMismatch('borsdata /v1/markets: "name" missing or not a string');
            }

            if (($market['countryId'] ?? null) !== self::SWEDEN_COUNTRY_ID) {
                continue;
            }

            $label = $this->labelForMarketName($name);
            if ($label !== null) {
                $labels[(string) $id] = $label;
                $this->logger->info('borsdata: target market correlated', [
                    'id' => $id,
                    'name' => $name,
                    'label' => $label,
                ]);
            }
        }

        $missing = array_diff(self::TARGET_LABELS, array_unique(array_values($labels)));
        if ($missing !== []) {
            throw new SchemaMismatch(sprintf(
                'borsdata /v1/markets: target Swedish markets not correlated (countryId 1) — '
                . 'missing %s. Confirm the live market "name" strings against labelForMarketName().',
                implode(', ', $missing),
            ));
        }

        return $labels;
    }

    private function labelForMarketName(string $name): ?string
    {
        $normalized = strtoupper(trim($name));

        if (str_starts_with($normalized, 'FIRST NORTH')) {
            return UniverseEntry::LIST_FIRST_NORTH;
        }

        return match ($normalized) {
            'LARGE CAP' => UniverseEntry::LIST_LC,
            'MID CAP' => UniverseEntry::LIST_MC,
            'SMALL CAP' => UniverseEntry::LIST_SC,
            default => null,
        };
    }

    /**
     * @return list<array<mixed>> the `instruments` array
     */
    private function instruments(): array
    {
        $instruments = $this->requestJson($this->http, 'GET', self::BASE_URI . '/instruments', [
            'query' => ['authKey' => $this->apiKey],
            'headers' => ['Accept' => 'application/json'],
        ])['instruments'] ?? null;

        if (!is_array($instruments)) {
            throw new SchemaMismatch('borsdata /v1/instruments: "instruments" missing or not a list');
        }

        $rows = [];
        foreach ($instruments as $i => $instrument) {
            if (is_array($instrument)) {
                $rows[] = $instrument;

                continue;
            }

            $this->logger->warning('borsdata: dropping non-object entry in /v1/instruments', [
                'index' => $i,
                'type' => get_debug_type($instrument),
            ]);
        }

        return $rows;
    }
}
