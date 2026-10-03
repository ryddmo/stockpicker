<?php

declare(strict_types=1);

namespace Stockpicker\Adapter;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ResponseException;
use Psr\Log\LoggerInterface;
use Stockpicker\Error\NotFound;
use Stockpicker\Error\SchemaMismatch;

/**
 * spec-short-interest-data — resolves an ISIN to its issuer's LEI through
 * GLEIF's public API (no key needed):
 *
 *   GET https://api.gleif.org/api/v1/lei-records?filter[isin]=<ISIN>
 *   → { data: [ { type: "lei-records", id: "<LEI>", attributes: {…} } ], … }
 *
 * Empty `data` (verified 2026-10-03: HTTP 200 with `"data":[]`) or a 404 →
 * NotFound; a missing `data` list or an id that is not a 20-character LEI →
 * SchemaMismatch. A listing-type call, so it keeps the one-shot retry
 * (Story 2.4). Throttled by the caller (UniverseSync, `rate.gleif`).
 */
final class GleifAdapter implements LeiResolver
{
    use HandlesTransientHttp;

    private const BASE_URI = 'https://api.gleif.org';
    private const LEI_RECORDS_PATH = '/api/v1/lei-records';
    private const USER_AGENT = 'Mozilla/5.0 (compatible; stockpicker/1.x; personal use)';

    /**
     * Overridable only for the integration seam (`STOCKPICKER_GLEIF_BASE_URI`,
     * or an explicit ctor argument) — EndpointFixture pins it to an
     * unresolvable host so `/cron/refill` tests never reach the real API.
     */
    private readonly string $baseUri;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly LoggerInterface $logger,
        ?string $baseUri = null,
    ) {
        $envBaseUri = getenv('STOCKPICKER_GLEIF_BASE_URI');

        $this->baseUri = rtrim(
            $baseUri ?? ($envBaseUri !== false && $envBaseUri !== '' ? $envBaseUri : self::BASE_URI),
            '/',
        );
    }

    public function resolveLei(string $isin): string
    {
        try {
            $body = $this->withOneRetry(fn (): array => $this->requestJson(
                $this->http,
                'GET',
                $this->baseUri . self::LEI_RECORDS_PATH,
                [
                    'query' => ['filter[isin]' => $isin],
                    'headers' => [
                        'User-Agent' => self::USER_AGENT,
                        'Accept' => 'application/vnd.api+json',
                    ],
                ],
            ));
        } catch (SchemaMismatch $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof ResponseException && $previous->getResponse()->getStatusCode() === 404) {
                throw new NotFound(sprintf('gleif lei-records (isin %s): not found', $isin), 0, $previous);
            }

            throw $e;
        }

        $data = $body['data'] ?? null;
        if (!is_array($data) || !array_is_list($data)) {
            throw $this->schemaMismatch(sprintf('gleif lei-records (isin %s): "data" missing or not a list', $isin));
        }

        if ($data === []) {
            throw new NotFound(sprintf('gleif lei-records (isin %s): no LEI record lists this ISIN', $isin));
        }

        $lei = is_array($data[0]) ? ($data[0]['id'] ?? null) : null;
        if (!is_string($lei) || !preg_match('/^[A-Z0-9]{20}$/', strtoupper(trim($lei)))) {
            throw $this->schemaMismatch(sprintf('gleif lei-records (isin %s): "data[0].id" missing or not a LEI', $isin));
        }

        return strtoupper(trim($lei));
    }

    private function schemaMismatch(string $message): SchemaMismatch
    {
        $this->logger->warning($message);

        return new SchemaMismatch($message);
    }
}
