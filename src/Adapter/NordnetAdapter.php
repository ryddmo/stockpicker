<?php

declare(strict_types=1);

namespace Stockpicker\Adapter;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Stockpicker\Error\AdapterError;
use Stockpicker\Error\NotFound;
use Stockpicker\Error\SchemaMismatch;
use Stockpicker\Store\Instrument;

/**
 * Nordnet source adapter. The public `stocklist` search needs only a
 * `client-id: NEXT` header, no session. Endpoint is unofficial and may change,
 * so every response is schema-checked at this boundary (AD-7).
 *
 * Verified 2026-09-09 (GET api/2/instrument_search/query/stocklist?free_text_search=<name>):
 *  - results[].instrument_info.isin
 *  - results[].nnx_info.nnx_instrument_id                (resolveId — Story 1.3)
 *  - results[].statistical_info.number_of_owners (int)
 *  - results[].statistical_info.statistics_timestamp (epoch ms)
 *  - results[].price_info.last.price (float), results[].company_info.market_cap (int)
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
            $this->warn('nordnet resolveId failed', $instrument, $e);

            throw $e;
        }
    }

    public function fetch(Instrument $instrument): NormalizedRow
    {
        if ($instrument->nordnetInstrumentId === null) {
            throw new NotFound(sprintf('nordnet: %s has no nordnet_instrument_id', $instrument->isin));
        }

        try {
            return $this->withOneRetry(fn (): NormalizedRow => $this->fetchDatapoint($instrument));
        } catch (AdapterError $e) {
            $this->warn('nordnet fetch failed', $instrument, $e);

            throw $e;
        }
    }

    private function lookupInstrumentId(Instrument $instrument): string
    {
        $result = $this->matchByIsin($this->searchResults($instrument->name), $instrument->isin);
        if ($result === null) {
            throw new NotFound(sprintf('nordnet: no result with isin %s (%s)', $instrument->isin, $instrument->name));
        }

        $id = $result['nnx_info']['nnx_instrument_id'] ?? null;
        if (!is_string($id) && !is_int($id)) {
            throw new SchemaMismatch('nordnet stocklist: matched result without a scalar "nnx_info.nnx_instrument_id"');
        }

        return (string) $id;
    }

    private function fetchDatapoint(Instrument $instrument): NormalizedRow
    {
        $result = $this->matchByIsin($this->searchResults($instrument->name), $instrument->isin);
        if ($result === null) {
            throw new NotFound(sprintf('nordnet: no result with isin %s (%s)', $instrument->isin, $instrument->name));
        }

        $owners = $result['statistical_info']['number_of_owners'] ?? null;
        if (!is_int($owners) || $owners < 0) {
            throw new SchemaMismatch(
                'nordnet stocklist: "statistical_info.number_of_owners" missing or not a non-negative int'
            );
        }

        $timestampMs = $result['statistical_info']['statistics_timestamp'] ?? null;
        if (!is_int($timestampMs) && !is_float($timestampMs)) {
            throw new SchemaMismatch('nordnet stocklist: "statistical_info.statistics_timestamp" missing or not numeric');
        }

        return new NormalizedRow(
            isin: $instrument->isin,
            source: NormalizedRow::SOURCE_NORDNET,
            numberOfOwners: $owners,
            lastPrice: $this->numeric($result['price_info']['last']['price'] ?? null),
            marketCap: $this->numeric($result['company_info']['market_cap'] ?? null),
            sourceTimestamp: (new DateTimeImmutable('@' . intdiv((int) $timestampMs, 1000)))
                ->setTimezone(new DateTimeZone('UTC')),
            fetchedAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }

    /**
     * @return list<array<mixed>> the `results` array
     */
    private function searchResults(string $name): array
    {
        $body = $this->requestJson($this->http, 'GET', self::BASE_URI . self::SEARCH_PATH, [
            'query' => ['free_text_search' => $name, 'limit' => 10],
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

        return array_values(array_filter($results, 'is_array'));
    }

    /**
     * @param list<array<mixed>> $results
     *
     * @return array<mixed>|null
     */
    private function matchByIsin(array $results, string $isin): ?array
    {
        foreach ($results as $result) {
            if (($result['instrument_info']['isin'] ?? null) === $isin) {
                return $result;
            }
        }

        return null;
    }

    private function numeric(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) ? (float) $value : null;
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
