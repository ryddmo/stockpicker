<?php

declare(strict_types=1);

namespace Stockpicker\Adapter;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Stockpicker\Error\AdapterError;
use Stockpicker\Error\NotFound;
use Stockpicker\Error\SchemaMismatch;
use Stockpicker\Store\Instrument;

/**
 * Avanza source adapter. Endpoints are unofficial (`_api/...`) and may change
 * without notice — every response is schema-checked at this boundary (AD-7).
 *
 * Verified 2026-09-09:
 *  - POST _api/search/filtered-search  → hits[].orderBookId (no ISIN in the hit)
 *  - GET  _api/market-guide/stock/{id} → isin, keyIndicators.numberOfOwners (int),
 *      keyIndicators.marketCapital.value, quote.last, historicalClosingPrices.oneDay
 */
final class AvanzaAdapter implements SourceAdapter
{
    use HandlesTransientHttp;

    private const BASE_URI = 'https://www.avanza.se';
    private const SEARCH_PATH = '/_api/search/filtered-search';
    private const STOCK_PATH = '/_api/market-guide/stock/';
    private const USER_AGENT = 'Mozilla/5.0 (compatible; stockpicker/1.x; personal use)';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function resolveId(Instrument $instrument): string
    {
        try {
            return $this->withOneRetry(fn (): string => $this->lookupOrderBookId($instrument));
        } catch (AdapterError $e) {
            $this->warn('avanza resolveId failed', $instrument, $e);

            throw $e;
        }
    }

    public function fetch(Instrument $instrument): NormalizedRow
    {
        if ($instrument->avanzaOrderbookId === null) {
            throw new NotFound(sprintf('avanza: %s has no avanza_orderbook_id', $instrument->isin));
        }

        // No withOneRetry here: FetchRunner (Story 2.4) owns every fetch retry,
        // so fetch() throws Transient on the first failure. withOneRetry stays
        // on resolveId().
        try {
            return $this->fetchDatapoint($instrument);
        } catch (AdapterError $e) {
            $this->warn('avanza fetch failed', $instrument, $e);

            throw $e;
        }
    }

    private function fetchDatapoint(Instrument $instrument): NormalizedRow
    {
        $id = $instrument->avanzaOrderbookId ?? '';
        $guide = $this->marketGuide($id, $instrument->isin);

        $isin = $guide['isin'] ?? null;
        if (!is_string($isin) || $isin === '') {
            throw new SchemaMismatch(sprintf('avanza market-guide/stock/%s: "isin" missing', $id));
        }
        if ($isin !== $instrument->isin) {
            throw new SchemaMismatch(sprintf(
                'avanza market-guide/stock/%s: isin %s does not match expected %s',
                $id,
                $isin,
                $instrument->isin,
            ));
        }

        $owners = $guide['keyIndicators']['numberOfOwners'] ?? null;
        if (!is_int($owners) || $owners < 0) {
            throw new SchemaMismatch(sprintf(
                'avanza market-guide/stock/%s: "keyIndicators.numberOfOwners" missing or not a non-negative int',
                $id,
            ));
        }

        return new NormalizedRow(
            isin: $instrument->isin,
            source: NormalizedRow::SOURCE_AVANZA,
            numberOfOwners: $owners,
            lastPrice: $this->lastPrice($guide),
            marketCap: $this->marketCap($guide),
            sourceTimestamp: null,
            fetchedAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }

    /**
     * @return array<mixed>
     */
    private function marketGuide(string $orderBookId, string $isinForMessage): array
    {
        try {
            return $this->requestJson(
                $this->http,
                'GET',
                self::BASE_URI . self::STOCK_PATH . rawurlencode($orderBookId),
                ['headers' => ['User-Agent' => self::USER_AGENT]],
            );
        } catch (SchemaMismatch $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof RequestException && $previous->getResponse()?->getStatusCode() === 404) {
                throw new NotFound(
                    sprintf('avanza: orderbook %s not found (stale id for %s)', $orderBookId, $isinForMessage),
                    0,
                    $previous,
                );
            }

            throw $e;
        }
    }

    /**
     * @param array<mixed> $guide
     */
    private function lastPrice(array $guide): ?float
    {
        foreach ([$guide['quote']['last'] ?? null, $guide['historicalClosingPrices']['oneDay'] ?? null] as $candidate) {
            if (is_int($candidate) || is_float($candidate)) {
                return (float) $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $guide
     */
    private function marketCap(array $guide): ?float
    {
        $value = $guide['keyIndicators']['marketCapital']['value'] ?? null;

        return (is_int($value) || is_float($value)) ? (float) $value : null;
    }

    private function lookupOrderBookId(Instrument $instrument): string
    {
        $search = $this->requestJson($this->http, 'POST', self::BASE_URI . self::SEARCH_PATH, [
            'json' => [
                'query' => $instrument->name,
                'searchFilter' => ['types' => ['STOCK']],
            ],
            'headers' => ['User-Agent' => self::USER_AGENT],
        ]);

        $hits = $search['hits'] ?? null;
        if (!is_array($hits)) {
            throw new SchemaMismatch('avanza filtered-search: "hits" missing or not a list');
        }

        foreach ($hits as $hit) {
            if (!is_array($hit) || ($hit['type'] ?? null) !== 'STOCK') {
                continue;
            }

            $orderBookId = $hit['orderBookId'] ?? null;
            if (!is_string($orderBookId) && !is_int($orderBookId)) {
                throw new SchemaMismatch('avanza filtered-search: STOCK hit without a scalar "orderBookId"');
            }
            $orderBookId = (string) $orderBookId;

            $guide = $this->marketGuide($orderBookId, $instrument->isin);
            $isin = $guide['isin'] ?? null;
            if (!is_string($isin) || $isin === '') {
                throw new SchemaMismatch(sprintf('avanza market-guide/stock/%s: "isin" missing', $orderBookId));
            }

            if ($isin === $instrument->isin) {
                return $orderBookId;
            }
        }

        throw new NotFound(sprintf('avanza: no STOCK hit for %s (%s)', $instrument->isin, $instrument->name));
    }

    private function warn(string $message, Instrument $instrument, AdapterError $error): void
    {
        $this->logger->warning($message, [
            'isin' => $instrument->isin,
            'name' => $instrument->name,
            'error' => $error::class,
            'message' => $error->getMessage(),
        ]);
    }
}
