<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Adapter;

use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use Stockpicker\Adapter\GleifAdapter;
use Stockpicker\Error\NotFound;
use Stockpicker\Error\SchemaMismatch;
use Stockpicker\Error\Transient;

/** spec-short-interest-data — ISIN → issuer LEI via GLEIF's lei-records filter. */
final class GleifAdapterTest extends AdapterTestCase
{
    private function adapter(): GleifAdapter
    {
        return new GleifAdapter($this->client(), new NullLogger(), 'https://gleif.test/');
    }

    public function testResolvesDataZeroId(): void
    {
        $this->queue([$this->json(['data' => [['type' => 'lei-records', 'id' => '54930044O54BK617EP80', 'attributes' => []]]])]);

        self::assertSame('54930044O54BK617EP80', $this->adapter()->resolveLei('SE0000163628'));

        $request = $this->mock->getLastRequest();
        self::assertNotNull($request);
        self::assertSame('gleif.test', $request->getUri()->getHost());
        self::assertSame('/api/v1/lei-records', $request->getUri()->getPath());
        self::assertSame('filter%5Bisin%5D=SE0000163628', $request->getUri()->getQuery());
        self::assertQueueDrained();
    }

    public function testEmptyDataIsNotFound(): void
    {
        $this->queue([$this->json(['data' => [], 'meta' => []])]);

        $this->expectException(NotFound::class);
        $this->adapter()->resolveLei('SE0000000001');
    }

    public function testHttp404IsASchemaMismatchNotNotFound(): void
    {
        $this->queue([new Response(404)]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->resolveLei('SE0000000001');
    }

    public function testMissingDataIsASchemaMismatch(): void
    {
        $this->queue([$this->json(['errors' => []])]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->resolveLei('SE0000000001');
    }

    public function testNonLeiIdIsASchemaMismatch(): void
    {
        $this->queue([$this->json(['data' => [['id' => 'not-a-lei']]])]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->resolveLei('SE0000000001');
    }

    public function testServerErrorTwiceIsTransient(): void
    {
        $this->queue([new Response(500), new Response(503)]);

        try {
            $this->adapter()->resolveLei('SE0000000001');
            self::fail('expected Transient');
        } catch (Transient) {
            self::assertQueueDrained();
        }
    }
}
