<?php

declare(strict_types=1);

namespace Stockpicker\Adapter;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Stockpicker\Error\AdapterError;
use Stockpicker\Error\NotFound;
use Stockpicker\Error\SchemaMismatch;
use Stockpicker\Store\Instrument;

/**
 * Avanza source adapter. Endpoints are unofficial (`_api/...`) and may change
 * without notice — every response is schema-checked at this boundary (AD-7).
 *
 * Verified 2026-09-08:
 *  - POST _api/search/filtered-search  → hits[].orderBookId (no ISIN in the hit)
 *  - GET  _api/market-guide/stock/{id} → isin, keyIndicators.numberOfOwners
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
            $this->logger->warning('avanza resolveId failed', [
                'isin' => $instrument->isin,
                'name' => $instrument->name,
                'error' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function fetch(Instrument $instrument): mixed
    {
        throw new \LogicException('AvanzaAdapter::fetch() is implemented in Story 1.4');
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

            if ($this->isinOf($orderBookId) === $instrument->isin) {
                return $orderBookId;
            }
        }

        throw new NotFound(sprintf('avanza: no STOCK hit for %s (%s)', $instrument->isin, $instrument->name));
    }

    private function isinOf(string $orderBookId): string
    {
        $guide = $this->requestJson($this->http, 'GET', self::BASE_URI . self::STOCK_PATH . rawurlencode($orderBookId), [
            'headers' => ['User-Agent' => self::USER_AGENT],
        ]);

        $isin = $guide['isin'] ?? null;
        if (!is_string($isin) || $isin === '') {
            throw new SchemaMismatch(sprintf('avanza market-guide/stock/%s: "isin" missing', $orderBookId));
        }

        return $isin;
    }
}
