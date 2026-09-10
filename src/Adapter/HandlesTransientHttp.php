<?php

declare(strict_types=1);

namespace Stockpicker\Adapter;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Stockpicker\Error\RateLimited;
use Stockpicker\Error\SchemaMismatch;
use Stockpicker\Error\Transient;

/**
 * Shared HTTP plumbing for the source adapters: issue a request and map every
 * Guzzle failure onto a typed AdapterError, and run an operation with a single
 * retry on Transient (AD-6). Guzzle's `http_errors` must stay enabled (the
 * default) so non-2xx responses surface as exceptions here.
 */
trait HandlesTransientHttp
{
    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed> the decoded JSON body
     *
     * @throws RateLimited    HTTP 429 (a Transient subtype)
     * @throws Transient      connection error, timeout, or HTTP 5xx
     * @throws SchemaMismatch any other non-2xx, or a body that is not a JSON array/object
     */
    private function requestJson(ClientInterface $http, string $method, string $uri, array $options = []): array
    {
        try {
            $response = $http->request($method, $uri, $options);
        } catch (ConnectException $e) {
            throw new Transient(sprintf('%s %s: connection failed (%s)', $method, $uri, $e->getMessage()), 0, $e);
        } catch (RequestException $e) {
            $status = $e->getResponse()?->getStatusCode() ?? 0;

            if ($status === 429) {
                throw new RateLimited(sprintf('%s %s: HTTP 429 (rate limited)', $method, $uri), 0, $e);
            }

            if ($status >= 500) {
                throw new Transient(sprintf('%s %s: HTTP %d', $method, $uri, $status), 0, $e);
            }

            throw new SchemaMismatch(sprintf('%s %s: unexpected HTTP %d', $method, $uri, $status), 0, $e);
        }

        $data = json_decode((string) $response->getBody(), true);

        if (!is_array($data)) {
            throw new SchemaMismatch(sprintf('%s %s: response body is not JSON', $method, $uri));
        }

        return $data;
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function withOneRetry(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Transient) {
            return $operation();
        }
    }
}
