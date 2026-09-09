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
        $this->endpoint->start([
            'host' => getenv('STOCKPICKER_TEST_DB_HOST') ?: '127.0.0.1',
            'name' => getenv('STOCKPICKER_TEST_DB_NAME') ?: 'stockpicker_test',
            'user' => getenv('STOCKPICKER_TEST_DB_USER') ?: 'root',
            'pass' => getenv('STOCKPICKER_TEST_DB_PASS') ?: 'root',
            'charset' => 'utf8mb4',
        ]);
    }

    protected function tearDown(): void
    {
        $this->endpoint->stop();
        parent::tearDown();
    }

    public function testRefillEnqueuesTodaysStockholmDate(): void
    {
        $this->seedInstrument();
        $this->setRunAfter('00:00');

        [$status, $body] = $this->endpoint->get('/cron/refill?token=test-token');
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $status);
        self::assertSame('ok', $json['status']);
        self::assertSame(1, $json['created']);
        $runDate = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
        self::assertSame($runDate, $json['run_date']);
        self::assertSame($runDate, $this->pdo->query('SELECT run_date FROM work_queue')->fetchColumn());
        self::assertSame($runDate, $this->pdo->query("SELECT run_date FROM ingest_run WHERE run_type = 'enqueue'")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM work_queue')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM ingest_run WHERE run_type = 'enqueue'")->fetchColumn());
    }

    public function testWorkRunsOneSliceAndReturnsCounts(): void
    {
        $this->seedInstrument();
        $this->setRunAfter('00:00');
        $this->endpoint->get('/cron/refill?token=test-token');

        [$status, $body] = $this->endpoint->get('/cron/work?token=test-token');
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $status);
        self::assertSame('ok', $json['status']);
        self::assertSame(1, $json['claimed']);
        self::assertSame(1, $json['done']);
        self::assertSame(0, $json['failed']);
        self::assertSame(0, $json['reopened']);
        self::assertSame(0, $json['rows_written']);
        $runDate = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
        self::assertSame($runDate, $json['run_date']);
        self::assertSame('done', $this->pdo->query('SELECT status FROM work_queue')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT instrument_count FROM ingest_run WHERE run_type = 'fetch'")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM ingest_run WHERE run_type = 'fetch'")->fetchColumn());
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