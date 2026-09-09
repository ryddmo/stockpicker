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
 * Nordnet source adapter. The public `stocklist` search needs only a
 * `client-id: NEXT` header, no session. Endpoint is unofficial and may change.
 *
 * Verified 2026-09-08:
 *  - GET api/2/instrument_search/query/stocklist?free_text_search=<name>
 *      → results[].instrument_info.isin
 *      → results[].nnx_info.nnx_instrument_id
 *      → results[].statistical_info.number_of_owners (+ statistics_timestamp) — Story 1.5
 */
final class NordnetAdapter implements SourceAdapter
{
    use HandlesTransientHttp;

    private const BASE_URI = 'https://www.nordnet.se';
    private const SEARCH_PATH = '/api/2/instrument_search/query/stocklist';
    private const USER_AGENT = 'Mozilla/5.0 (compatible; stockpicker/1.x; personal use)';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function resolveId(Instrument $instrument): string
    {
        try {
            return $this->withOneRetry(fn (): string => $this->lookupInstrumentId($instrument));
        } catch (AdapterError $e) {
            $this->logger->warning('nordnet resolveId failed', [
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
        throw new \LogicException('NordnetAdapter::fetch() is implemented in Story 1.5');
    }

    private function lookupInstrumentId(Instrument $instrument): string
    {
        $body = $this->requestJson($this->http, 'GET', self::BASE_URI . self::SEARCH_PATH, [
            'query' => ['free_text_search' => $instrument->name, 'limit' => 10],
            'headers' => [
                'client-id' => 'NEXT',
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/json',
            ],
        ]);

        $results = $body['results'] ?? null;
        if (!is_array($results)) {
            throw new SchemaMismatch('nordnet stocklist: "results" missing or not a list');
        }

        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            $isin = $result['instrument_info']['isin'] ?? null;
            if ($isin !== $instrument->isin) {
                continue;
            }

            $id = $result['nnx_info']['nnx_instrument_id'] ?? null;
            if (!is_string($id) && !is_int($id)) {
                throw new SchemaMismatch('nordnet stocklist: matched result without a scalar "nnx_info.nnx_instrument_id"');
            }

            return (string) $id;
        }

        throw new NotFound(sprintf('nordnet: no result with isin %s (%s)', $instrument->isin, $instrument->name));
    }
}
