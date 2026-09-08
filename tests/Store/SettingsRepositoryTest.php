<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Store;

use Stockpicker\Store\SettingsRepository;

final class SettingsRepositoryTest extends StoreTestCase
{
    public function testGetReturnsNullForUnknownKey(): void
    {
        $repo = new SettingsRepository($this->pdo);

        self::assertNull($repo->get('nope.not.here'));
    }

    public function testSetThenGetRoundTrips(): void
    {
        $repo = new SettingsRepository($this->pdo);

        $repo->set('run_after', '18:30');

        self::assertSame('18:30', $repo->get('run_after'));
    }

    public function testSetUpsertsOnConflict(): void
    {
        $repo = new SettingsRepository($this->pdo);

        $repo->set('batch_size', '25');
        $repo->set('batch_size', '40');

        self::assertSame('40', $repo->get('batch_size'));
        self::assertCount(1, $repo->all());
    }

    public function testAllReturnsKeyValueMap(): void
    {
        $repo = new SettingsRepository($this->pdo);
        $repo->set('rate.avanza', '0.5');
        $repo->set('queue.stale_after', '900');

        self::assertEqualsCanonicalizing(
            ['rate.avanza' => '0.5', 'queue.stale_after' => '900'],
            $repo->all(),
        );
    }
}
