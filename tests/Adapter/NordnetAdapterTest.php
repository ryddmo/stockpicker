<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Adapter;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use Stockpicker\Adapter\NordnetAdapter;
use Stockpicker\Error\NotFound;
use Stockpicker\Error\SchemaMismatch;
use Stockpicker\Error\Transient;

final class NordnetAdapterTest extends AdapterTestCase
{
    private function adapter(): NordnetAdapter
    {
        return new NordnetAdapter($this->client(), new NullLogger());
    }

    private function nnxResult(string $isin, int|string $id): array
    {
        return [
            'instrument_info' => ['isin' => $isin, 'name' => 'Investor AB ser. B'],
            'nnx_info' => ['nnx_instrument_id' => $id],
        ];
    }

    public function testResolvesNnxInstrumentIdForTheIsinMatchingResult(): void
    {
        $this->queue([$this->json(['results' => [
            $this->nnxResult('SE0000000000', 1),
            $this->nnxResult('SE0015811963', 16102308),
        ]])]);

        self::assertSame('16102308', $this->adapter()->resolveId($this->instrument()));
        $this->assertQueueDrained();
    }

    public function testThrowsNotFoundWhenNoResultCarriesTheIsin(): void
    {
        $this->queue([$this->json(['results' => [$this->nnxResult('SE0000000000', 1)]])]);

        $this->expectException(NotFound::class);
        $this->adapter()->resolveId($this->instrument());
    }

    public function testThrowsNotFoundWhenResultsAreEmpty(): void
    {
        $this->queue([$this->json(['results' => []])]);

        $this->expectException(NotFound::class);
        $this->adapter()->resolveId($this->instrument());
    }

    public function testRetriesOnceAfterATransientFailureThenSucceeds(): void
    {
        $this->queue([
            new Response(429),
            $this->json(['results' => [$this->nnxResult('SE0015811963', 16102308)]]),
        ]);

        self::assertSame('16102308', $this->adapter()->resolveId($this->instrument()));
        $this->assertQueueDrained();
    }

    public function testThrowsTransientWhenBothAttemptsFail(): void
    {
        $this->queue([
            new Response(503),
            new ConnectException('timeout', new Request('GET', 'stocklist')),
        ]);

        $this->expectException(Transient::class);
        $this->adapter()->resolveId($this->instrument());
    }

    public function testThrowsSchemaMismatchWhenResultsFieldIsAbsent(): void
    {
        $this->queue([$this->json(['rows' => 0])]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->resolveId($this->instrument());
    }

    public function testThrowsSchemaMismatchWhenTheMatchedResultLacksTheId(): void
    {
        $this->queue([$this->json(['results' => [[
            'instrument_info' => ['isin' => 'SE0015811963'],
            'nnx_info' => [],
        ]]])]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->resolveId($this->instrument());
    }

    public function testMapsNonTransientHttpErrorToSchemaMismatch(): void
    {
        $this->queue([new Response(400)]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->resolveId($this->instrument());
    }
}
