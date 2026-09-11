<?php

declare(strict_types=1);

namespace Stockpicker\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Stockpicker\Store\SettingsRepository;
use Stockpicker\Tests\Store\StoreTestCase;
use Stockpicker\Tests\Support\EndpointFixture;

final class FrontControllerIntegrationTest extends StoreTestCase
{
    private EndpointFixture $endpoint;

    protected function setUp(): void
    {
        parent::setUp();
        $this->endpoint = new EndpointFixture();
        $this->startEndpoint();
    }

    private function startEndpoint(): void
    {
        $this->endpoint->start([
            'host' => getenv('STOCKPICKER_TEST_DB_HOST') ?: '127.0.0.1',
            'name' => getenv('STOCKPICKER_TEST_DB_NAME') ?: 'stockpicker_test',
            'user' => getenv('STOCKPICKER_TEST_DB_USER') ?: 'root',
            'pass' => getenv('STOCKPICKER_TEST_DB_PASS') ?: 'root',
            'charset' => 'utf8mb4',
        ]);
    }

    private function seedMatchedUniverse(): void
    {
        $rows = [
            ['SE0000001001', 'Alpha AB', 'LC', '1001'],
            ['SE0000001002', 'Beta AB', 'MC', '1002'],
            ['SE0000001003', 'Gamma AB', 'SC', '1003'],
            ['SE0000001004', 'Delta AB', 'First North', '1004'],
        ];
        $stmt = $this->pdo->prepare(
            "INSERT INTO instrument (isin, name, list, avanza_orderbook_id, nordnet_instrument_id, first_seen)
             VALUES (?, ?, ?, ?, ?, '2026-01-01')"
        );
        foreach ($rows as [$isin, $name, $list, $obId]) {
            $stmt->execute([$isin, $name, $list, $obId, 'nx-' . $obId]);
        }
    }

    protected function tearDown(): void
    {
        $this->endpoint->stop();
        parent::tearDown();
    }

    public function testRefillRunsUniverseSyncThenEnqueuesTheActiveUniverse(): void
    {
        $this->endpoint->stop();
        $this->endpoint = new EndpointFixture();
        $this->endpoint->startUniverseSource('happy');
        $this->startEndpoint();

        $this->seedMatchedUniverse();
        $this->setRunAfter('00:00');

        [$status, $body] = $this->endpoint->get('/cron/refill?token=test-token');
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $status, $body);
        self::assertSame('ok', $json['status']);
        self::assertSame(4, $json['created'], 'Enqueue runs over allActive() after the sync');
        $runDate = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
        self::assertSame($runDate, $json['run_date']);

        self::assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM work_queue')->fetchColumn());
        self::assertSame(
            $runDate,
            $this->pdo->query('SELECT run_date FROM work_queue LIMIT 1')->fetchColumn(),
        );
        self::assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM ingest_run WHERE run_type = 'universe_sync'")->fetchColumn(),
        );
        self::assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM ingest_run WHERE run_type = 'enqueue'")->fetchColumn(),
        );
        self::assertSame(
            4,
            (int) $this->pdo->query("SELECT instrument_count FROM ingest_run WHERE run_type = 'universe_sync'")->fetchColumn(),
        );
    }

    public function testRefillReturnsUniverseSyncFailedWhenTheListingIsUnreachable(): void
    {
        // The hermetic default env points the universe adapter at an
        // unresolvable host -> listUniverse() raises, Enqueue is skipped.
        $this->seedInstrument();
        $this->setRunAfter('00:00');

        [$status, $body] = $this->endpoint->get('/cron/refill?token=test-token');
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $status, $body);
        self::assertSame('universe_sync_failed', $json['status']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM work_queue')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM ingest_run')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM ingest_run WHERE alarm = 1")->fetchColumn());
    }

    public function testWorkRunsOneSliceAndReturnsCounts(): void
    {
        $this->seedInstrument();
        $this->setRunAfter('00:00');
        $runDate = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
        $this->pdo->prepare('INSERT INTO work_queue (isin, run_date) VALUES (?, ?)')
            ->execute(['SE0000000001', $runDate]);

        [$status, $body] = $this->endpoint->get('/cron/work?token=test-token');
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $status);
        self::assertSame('ok', $json['status']);
        self::assertSame(1, $json['claimed']);
        self::assertSame(1, $json['done']);
        self::assertSame(0, $json['failed']);
        self::assertSame(0, $json['reopened']);
        self::assertSame(0, $json['stale_failed']);
        self::assertSame(0, $json['rows_written']);
        $zero = ['ok' => 0, 'not_found' => 0, 'schema_mismatch' => 0, 'transient' => 0, 'retried' => 0, 'rate_limited' => 0];
        self::assertSame(['avanza' => $zero, 'nordnet' => $zero], $json['by_source']);
        $runDate = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
        self::assertSame($runDate, $json['run_date']);
        self::assertSame('done', $this->pdo->query('SELECT status FROM work_queue')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT instrument_count FROM ingest_run WHERE run_type = 'fetch'")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM ingest_run WHERE run_type = 'fetch'")->fetchColumn());
    }

    public function testWorkReturnsStaleFailedCountForPastRunDateClaim(): void
    {
        $this->seedInstrument();
        $this->setRunAfter('00:00');
        $pastRunDate = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))
            ->modify('-1 day')
            ->format('Y-m-d');
        $claimedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->sub(new \DateInterval('PT20M'))
            ->format('Y-m-d H:i:s');

        $this->pdo->prepare('INSERT INTO work_queue (isin, run_date, status, claimed_at) VALUES (?, ?, \'claimed\', ?)')
            ->execute(['SE0000000001', $pastRunDate, $claimedAt]);

        [$status, $body] = $this->endpoint->get('/cron/work?token=test-token');
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $status);
        self::assertSame('ok', $json['status']);
        self::assertSame(1, $json['stale_failed']);
        self::assertSame('failed', $this->pdo->query('SELECT status FROM work_queue')->fetchColumn());
    }

    public function testClosedWindowDoesNotRunPipeline(): void
    {
        $this->seedInstrument();
        $this->setRunAfter($this->futureRunAfter());

        [$status, $body] = $this->endpoint->get('/cron/work?token=test-token');
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $status);
        self::assertSame('window_closed', $json['status']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM work_queue')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ingest_run')->fetchColumn());
    }

    public function testRefillClosedWindowDoesNotRunPipeline(): void
    {
        $this->seedInstrument();
        $this->setRunAfter($this->futureRunAfter());

        [$status, $body] = $this->endpoint->get('/cron/refill?token=test-token');
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $status);
        self::assertSame('window_closed', $json['status']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM work_queue')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ingest_run')->fetchColumn());
    }

    public function testMissingRunAfterReturnsGenericServerError(): void
    {
        $this->seedInstrument();
        $this->pdo->exec("DELETE FROM settings WHERE `key` = 'run_after'");

        [$status, $body] = $this->endpoint->get('/cron/refill?token=test-token');

        self::assertSame(500, $status);
        self::assertSame(['error' => 'internal server error'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM work_queue')->fetchColumn());
        self::assertStringContainsString('unhandled exception in front controller', $this->endpoint->logContents());
    }

    public function testMalformedRunAfterReturnsGenericServerError(): void
    {
        $this->seedInstrument();
        $this->setRunAfter('not-a-time');

        [$status, $body] = $this->endpoint->get('/cron/work?token=test-token');

        self::assertSame(500, $status);
        self::assertSame(['error' => 'internal server error'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM work_queue')->fetchColumn());
    }

    private function seedInstrument(): void
    {
        $this->pdo->exec("INSERT INTO instrument (isin, name, list, first_seen) VALUES ('SE0000000001', 'Test', 'LC', '2026-01-01')");
    }

    private function setRunAfter(string $value): void
    {
        (new SettingsRepository($this->pdo))->set('run_after', $value);
    }

    private function futureRunAfter(): string
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm'));
        $future = $now->modify('+5 minutes');
        if ($future->format('Y-m-d') !== $now->format('Y-m-d')) {
            self::markTestSkipped('closed-window fixture cannot use a future same-day minute near midnight');
        }

        return $future->format('H:i');
    }
}