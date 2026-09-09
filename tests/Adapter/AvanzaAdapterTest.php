<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Adapter;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use Stockpicker\Adapter\AvanzaAdapter;
use Stockpicker\Error\NotFound;
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
}
