<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Adapter;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Stockpicker\Store\Instrument;

/**
 * Shared plumbing for the source-adapter tests: a Guzzle client backed by a
 * MockHandler queue (no real network — the handler throws if the code makes
 * an unqueued request) and a helper to assert the queue was fully consumed.
 */
abstract class AdapterTestCase extends TestCase
{
    protected MockHandler $mock;

    protected function setUp(): void
    {
        $this->mock = new MockHandler();
    }

    protected function client(): Client
    {
        return new Client(['handler' => HandlerStack::create($this->mock)]);
    }

    /**
     * @param iterable<\Psr\Http\Message\ResponseInterface|\Throwable> $responses
     */
    protected function queue(iterable $responses): void
    {
        foreach ($responses as $response) {
            $this->mock->append($response);
        }
    }

    protected function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    protected function assertQueueDrained(): void
    {
        self::assertSame(0, $this->mock->count(), 'the adapter left mocked responses unconsumed');
    }

    protected function instrument(
        string $isin = 'SE0015811963',
        string $name = 'Investor B',
        ?string $avanzaOrderbookId = null,
        ?string $nordnetInstrumentId = null,
    ): Instrument {
        return new Instrument($isin, $name, 'LC', $avanzaOrderbookId, $nordnetInstrumentId, '2026-09-08', null);
    }
}
