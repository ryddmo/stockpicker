<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Adapter;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use Stockpicker\Adapter\NordnetAdapter;
use Stockpicker\Error\NotFound;
use Stockpicker\Error\RateLimited;
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

    // --- fetch() ---------------------------------------------------------

    private const STATS_TS_MS = 1788955452687;

    /**
     * @param array<string, mixed> $overrides
     */
    private function fetchResult(array $overrides = []): array
    {
        return array_replace_recursive([
            'instrument_info' => ['isin' => 'SE0015811963', 'name' => 'Investor AB ser. B'],
            'nnx_info' => ['nnx_instrument_id' => '19fa390b-040f-45a9-8fa2-e7fd34e319ab'],
            'statistical_info' => ['number_of_owners' => 69611, 'statistics_timestamp' => self::STATS_TS_MS],
            'price_info' => ['last' => ['price' => 401.4, 'decimals' => 2]],
            'company_info' => ['market_cap' => 1238152058906],
        ], $overrides);
    }

    private function resolved(): \Stockpicker\Store\Instrument
    {
        return $this->instrument(nordnetInstrumentId: '19fa390b-040f-45a9-8fa2-e7fd34e319ab');
    }

    public function testFetchReturnsANormalizedRow(): void
    {
        $this->queue([$this->json(['results' => [$this->fetchResult()]])]);

        $row = $this->adapter()->fetch($this->resolved());

        self::assertSame('SE0015811963', $row->isin);
        self::assertSame('nordnet', $row->source);
        self::assertSame(69611, $row->numberOfOwners);
        self::assertSame(401.4, $row->lastPrice);
        self::assertSame(1238152058906.0, $row->marketCap);
        self::assertSame('UTC', $row->fetchedAt->getTimezone()->getName());
        self::assertNotNull($row->sourceTimestamp);
        self::assertSame(intdiv(self::STATS_TS_MS, 1000), $row->sourceTimestamp->getTimestamp());
        self::assertSame('UTC', $row->sourceTimestamp->getTimezone()->getName());
        $this->assertQueueDrained();
    }

    public function testFetchLeavesPriceAndMarketCapNullWhenAbsent(): void
    {
        $result = $this->fetchResult();
        unset($result['price_info'], $result['company_info']);
        $this->queue([$this->json(['results' => [$result]])]);

        $row = $this->adapter()->fetch($this->resolved());
        self::assertNull($row->lastPrice);
        self::assertNull($row->marketCap);
    }

    public function testFetchThrowsSchemaMismatchWhenOwnersMissing(): void
    {
        $result = $this->fetchResult();
        unset($result['statistical_info']['number_of_owners']);
        $this->queue([$this->json(['results' => [$result]])]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->fetch($this->resolved());
    }

    public function testFetchThrowsSchemaMismatchWhenOwnersNegative(): void
    {
        $this->queue([$this->json(['results' => [
            $this->fetchResult(['statistical_info' => ['number_of_owners' => -5]]),
        ]])]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->fetch($this->resolved());
    }

    public function testFetchThrowsSchemaMismatchWhenOwnersIsNotAnInt(): void
    {
        $this->queue([$this->json(['results' => [
            $this->fetchResult(['statistical_info' => ['number_of_owners' => '69611']]),
        ]])]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->fetch($this->resolved());
    }

    public function testFetchThrowsSchemaMismatchWhenTimestampMissing(): void
    {
        $result = $this->fetchResult();
        unset($result['statistical_info']['statistics_timestamp']);
        $this->queue([$this->json(['results' => [$result]])]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->fetch($this->resolved());
    }

    public function testFetchThrowsSchemaMismatchWhenTimestampIsNotNumeric(): void
    {
        $this->queue([$this->json(['results' => [
            $this->fetchResult(['statistical_info' => ['statistics_timestamp' => '2026-09-09']]),
        ]])]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->fetch($this->resolved());
    }

    public function testFetchThrowsNotFoundWhenNoResultMatchesTheIsin(): void
    {
        $this->queue([$this->json(['results' => [
            $this->fetchResult(['instrument_info' => ['isin' => 'SE0000000000']]),
        ]])]);

        $this->expectException(NotFound::class);
        $this->adapter()->fetch($this->resolved());
    }

    public function testFetchThrowsNotFoundWhenInstrumentIsUnresolved(): void
    {
        $this->expectException(NotFound::class);
        $this->adapter()->fetch($this->instrument());
    }

    public function testFetchThrowsTransientOnTheFirstFailure(): void
    {
        // FetchRunner (Story 2.4) owns the fetch retry now, so fetch() no longer
        // wraps fetchDatapoint() in withOneRetry() — it throws on the first 5xx
        // and never makes a second attempt.
        $this->queue([
            new Response(503),
            $this->json(['results' => [$this->fetchResult()]]),
        ]);

        try {
            $this->adapter()->fetch($this->resolved());
            self::fail('expected a Transient');
        } catch (Transient) {
            self::assertSame(1, $this->mock->count(), 'fetch() made exactly one attempt');
        }
    }

    public function testFetchThrowsRateLimitedOnA429(): void
    {
        $this->queue([new Response(429)]);

        try {
            $this->adapter()->fetch($this->resolved());
            self::fail('expected a RateLimited');
        } catch (RateLimited $e) {
            // RateLimited is a Transient subtype, so withOneRetry()'s
            // catch (Transient) and FetchRunner's retry loop both still cover it.
            self::assertInstanceOf(Transient::class, $e);
        }
    }
}
