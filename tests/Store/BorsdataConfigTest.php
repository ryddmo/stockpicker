<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Store;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stockpicker\Config;

/**
 * Config contract for the Börsdata API key (Story 2.1). Mirrors the
 * cron_token checks in SmokeTest: a set key round-trips, a missing or empty
 * one throws before the adapter is ever constructed.
 */
final class BorsdataConfigTest extends TestCase
{
    public function testReturnsTheConfiguredKey(): void
    {
        $config = Config::fromArray(['borsdata' => ['api_key' => 'bd-live-key-123']]);

        self::assertSame('bd-live-key-123', $config->borsdataApiKey());
    }

    public function testThrowsWhenTheBorsdataSectionIsAbsent(): void
    {
        $config = Config::fromArray(['cron_token' => 't']);

        $this->expectException(RuntimeException::class);
        $config->borsdataApiKey();
    }

    public function testThrowsWhenTheKeyIsAnEmptyString(): void
    {
        $config = Config::fromArray(['borsdata' => ['api_key' => '']]);

        $this->expectException(RuntimeException::class);
        $config->borsdataApiKey();
    }

    public function testThrowsWhenTheKeyIsMissingFromTheSection(): void
    {
        $config = Config::fromArray(['borsdata' => []]);

        $this->expectException(RuntimeException::class);
        $config->borsdataApiKey();
    }

    public function testThrowsWhenTheKeyIsNotAString(): void
    {
        $config = Config::fromArray(['borsdata' => ['api_key' => 12345]]);

        $this->expectException(RuntimeException::class);
        $config->borsdataApiKey();
    }
}
