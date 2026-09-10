<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Adapter;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use Stockpicker\Adapter\AvanzaAdapter;
use Stockpicker\Error\NotFound;
use Stockpicker\Error\RateLimited;
use Stockpicker\Error\SchemaMismatch;
use Stockpicker\Error\Transient;

final class AvanzaAdapterTest extends AdapterTestCase
{
    private function adapter(): AvanzaAdapter
    {
        return new AvanzaAdapter($this->client(), new NullLogger());
    }

    public function testResolvesOrderBookIdWhenAConfirmedHitMatchesTheIsin(): void
    {
        $this->queue([
            $this->json(['hits' => [
                ['type' => 'INDEX', 'orderBookId' => '1'],
                ['type' => 'STOCK', 'orderBookId' => '5247'],
            ]]),
            $this->json(['isin' => 'SE0015811963', 'name' => 'Investor B']),
        ]);

        self::assertSame('5247', $this->adapter()->resolveId($this->instrument()));
        $this->assertQueueDrained();
    }

    public function testSkipsHitsWhoseConfirmedIsinDiffersAndTakesTheMatchingOne(): void
    {
        $this->queue([
            $this->json(['hits' => [
                ['type' => 'STOCK', 'orderBookId' => '999'],
                ['type' => 'STOCK', 'orderBookId' => '5247'],
            ]]),
            $this->json(['isin' => 'SE0000000000']),
            $this->json(['isin' => 'SE0015811963']),
        ]);

        self::assertSame('5247', $this->adapter()->resolveId($this->instrument()));
        $this->assertQueueDrained();
    }

    public function testThrowsNotFoundWhenNoHitConfirmsTheIsin(): void
    {
        $this->queue([
            $this->json(['hits' => [['type' => 'STOCK', 'orderBookId' => '999']]]),
            $this->json(['isin' => 'SE0000000000']),
        ]);

        $this->expectException(NotFound::class);
        $this->adapter()->resolveId($this->instrument());
    }

    public function testThrowsNotFoundWhenThereAreNoStockHits(): void
    {
        $this->queue([$this->json(['hits' => []])]);

        $this->expectException(NotFound::class);
        $this->adapter()->resolveId($this->instrument());
    }

    public function testRetriesOnceAfterATransientFailureThenSucceeds(): void
    {
        $this->queue([
            new Response(503),
            $this->json(['hits' => [['type' => 'STOCK', 'orderBookId' => '5247']]]),
            $this->json(['isin' => 'SE0015811963']),
        ]);

        self::assertSame('5247', $this->adapter()->resolveId($this->instrument()));
        $this->assertQueueDrained();
    }

    public function testThrowsTransientWhenBothAttemptsFail(): void
    {
        $this->queue([
            new ConnectException('timeout', new Request('POST', 'filtered-search')),
            new Response(503),
        ]);

        $this->expectException(Transient::class);
        $this->adapter()->resolveId($this->instrument());
    }

    public function testThrowsSchemaMismatchWhenHitsFieldIsAbsent(): void
    {
        $this->queue([$this->json(['totalNumberOfHits' => 0])]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->resolveId($this->instrument());
    }

    public function testThrowsSchemaMismatchWhenAStockHitHasNoOrderBookId(): void
    {
        $this->queue([$this->json(['hits' => [['type' => 'STOCK']]])]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->resolveId($this->instrument());
    }

    public function testMapsNonTransientHttpErrorToSchemaMismatch(): void
    {
        $this->queue([new Response(403)]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->resolveId($this->instrument());
    }

    // --- fetch() ---------------------------------------------------------

    /**
     * @param array<string, mixed> $overrides
     */
    private function guide(array $overrides = []): array
    {
        return array_replace_recursive([
            'isin' => 'SE0015811963',
            'name' => 'Investor B',
            'keyIndicators' => [
                'numberOfOwners' => 533660,
                'marketCapital' => ['value' => 1230083289623.0, 'currency' => 'SEK'],
            ],
            'quote' => ['last' => 402.2],
            'historicalClosingPrices' => ['oneDay' => 407.95],
        ], $overrides);
    }

    private function resolved(): \Stockpicker\Store\Instrument
    {
        return $this->instrument(avanzaOrderbookId: '5247');
    }

    public function testFetchReturnsANormalizedRow(): void
    {
        $this->queue([$this->json($this->guide())]);

        $row = $this->adapter()->fetch($this->resolved());

        self::assertSame('SE0015811963', $row->isin);
        self::assertSame('avanza', $row->source);
        self::assertSame(533660, $row->numberOfOwners);
        self::assertSame(402.2, $row->lastPrice);
        self::assertSame(1230083289623.0, $row->marketCap);
        self::assertNull($row->sourceTimestamp);
        self::assertSame('UTC', $row->fetchedAt->getTimezone()->getName());
        $this->assertQueueDrained();
    }

    public function testFetchFallsBackToPreviousCloseWhenQuoteIsAbsent(): void
    {
        $guide = $this->guide();
        unset($guide['quote']);
        $this->queue([$this->json($guide)]);

        self::assertSame(407.95, $this->adapter()->fetch($this->resolved())->lastPrice);
    }

    public function testFetchLeavesLastPriceNullWhenNoPriceIsPresent(): void
    {
        $guide = $this->guide();
        unset($guide['quote'], $guide['historicalClosingPrices']);
        $this->queue([$this->json($guide)]);

        self::assertNull($this->adapter()->fetch($this->resolved())->lastPrice);
    }

    public function testFetchLeavesMarketCapNullWhenAbsent(): void
    {
        $guide = $this->guide();
        unset($guide['keyIndicators']['marketCapital']);
        $this->queue([$this->json($guide)]);

        self::assertNull($this->adapter()->fetch($this->resolved())->marketCap);
    }

    public function testFetchThrowsSchemaMismatchWhenOwnersMissing(): void
    {
        $guide = $this->guide();
        unset($guide['keyIndicators']['numberOfOwners']);
        $this->queue([$this->json($guide)]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->fetch($this->resolved());
    }

    public function testFetchThrowsSchemaMismatchWhenOwnersIsNotAnInt(): void
    {
        $this->queue([$this->json($this->guide(['keyIndicators' => ['numberOfOwners' => '533660']]))]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->fetch($this->resolved());
    }

    public function testFetchThrowsSchemaMismatchWhenOwnersIsNegative(): void
    {
        $this->queue([$this->json($this->guide(['keyIndicators' => ['numberOfOwners' => -1]]))]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->fetch($this->resolved());
    }

    public function testFetchThrowsSchemaMismatchOnIsinMismatch(): void
    {
        $this->queue([$this->json($this->guide(['isin' => 'SE0000000000']))]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->fetch($this->resolved());
    }

    public function testFetchThrowsNotFoundOnHttp404(): void
    {
        $this->queue([new Response(404)]);

        $this->expectException(NotFound::class);
        $this->adapter()->fetch($this->resolved());
    }

    public function testFetchThrowsNotFoundWhenInstrumentHasNoOrderbookId(): void
    {
        $this->expectException(NotFound::class);
        $this->adapter()->fetch($this->instrument());
    }

    public function testFetchThrowsTransientOnTheFirstFailure(): void
    {
        // FetchRunner (Story 2.4) owns the fetch retry now, so fetch() no longer
        // wraps fetchDatapoint() in withOneRetry() — it throws on the first 5xx
        // and never makes a second attempt.
        $this->queue([new Response(503), $this->json($this->guide())]);

        try {
            $this->adapter()->fetch($this->resolved());
            self::fail('expected a Transient');
        } catch (Transient) {
            // one attempt only: the queued success response is untouched
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
