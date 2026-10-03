<?php

declare(strict_types=1);

namespace Stockpicker\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Stockpicker\Adapter\NormalizedRow;
use Stockpicker\Store\OwnerCountRepository;
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
        $this->allowAllWeekdays();

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

        // spec-short-interest-data — GleifAdapter is wired into UniverseSync:
        // the LEI pass ran against the pinned dead GLEIF host.
        $bySource = json_decode(
            (string) $this->pdo->query("SELECT by_source FROM ingest_run WHERE run_type = 'universe_sync'")->fetchColumn(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertArrayHasKey('gleif', $bySource);
        self::assertSame(4, $bySource['gleif']['transient'], 'each seeded instrument tried GLEIF once and failed transiently');
    }

    public function testRefillReturnsUniverseSyncFailedWhenTheListingIsUnreachable(): void
    {
        // The hermetic default env points the universe adapter at an
        // unresolvable host -> listUniverse() raises, Enqueue is skipped.
        $this->seedInstrument();
        $this->setRunAfter('00:00');
        $this->allowAllWeekdays();

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
        $this->allowAllWeekdays();
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
        $this->allowAllWeekdays();
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

    public function testWorkWeekendSkippedDoesNotRunPipeline(): void
    {
        $this->seedInstrument();
        $this->setRunAfter('00:00');
        $this->setRunWeekdays($this->aDifferentWeekdayThanToday());

        [$status, $body] = $this->endpoint->get('/cron/work?token=test-token');
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $status);
        self::assertSame('weekend_skipped', $json['status']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM work_queue')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ingest_run')->fetchColumn());
    }

    public function testWorkHolidaySkippedDoesNotRunPipeline(): void
    {
        $this->seedInstrument();
        $this->setRunAfter('00:00');
        $this->allowAllWeekdays();
        $this->markTodayAsHoliday();

        [$status, $body] = $this->endpoint->get('/cron/work?token=test-token');
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $status);
        self::assertSame('holiday_skipped', $json['status']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM work_queue')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ingest_run')->fetchColumn());
    }

    public function testDeriveWritesOneIngestRunRowAndReturnsCounts(): void
    {
        $this->seedMatchedUniverse();
        $this->setRunAfter('00:00');
        $this->allowAllWeekdays();

        [$status, $body] = $this->endpoint->get('/cron/derive?token=test-token');
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $status, $body);
        self::assertSame('ok', $json['status']);
        self::assertSame(4, $json['instrument_count']);
        $runDate = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
        self::assertSame($runDate, $json['run_date']);

        self::assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM ingest_run WHERE run_type = 'derive'")->fetchColumn(),
        );
        $row = $this->pdo->query("SELECT run_date, instrument_count, ok_count, fail_count, status FROM ingest_run WHERE run_type = 'derive'")->fetch();
        self::assertSame($runDate, $row['run_date']);
        self::assertSame(4, (int) $row['instrument_count']);
        self::assertSame(4, (int) $row['ok_count']);
        self::assertSame(0, (int) $row['fail_count']);
        self::assertSame('completed', $row['status']);
    }

    public function testDeriveClosedWindowDoesNotWriteARun(): void
    {
        $this->seedInstrument();
        $this->setRunAfter($this->futureRunAfter());

        [$status, $body] = $this->endpoint->get('/cron/derive?token=test-token');
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $status);
        self::assertSame('window_closed', $json['status']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ingest_run')->fetchColumn());
    }

    public function testDeriveWeekendSkippedDoesNotWriteARun(): void
    {
        $this->seedInstrument();
        $this->setRunAfter('00:00');
        $this->setRunWeekdays($this->aDifferentWeekdayThanToday());

        [$status, $body] = $this->endpoint->get('/cron/derive?token=test-token');
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $status);
        self::assertSame('weekend_skipped', $json['status']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ingest_run')->fetchColumn());
    }

    public function testDeriveHolidaySkippedDoesNotWriteARun(): void
    {
        $this->seedInstrument();
        $this->setRunAfter('00:00');
        $this->allowAllWeekdays();
        $this->markTodayAsHoliday();

        [$status, $body] = $this->endpoint->get('/cron/derive?token=test-token');
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $status);
        self::assertSame('holiday_skipped', $json['status']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ingest_run')->fetchColumn());
    }

    // -- /cron/derive: TopTenDigest (spec-5-6) -----------------------------------

    public function testDeriveSendsDigestEmailViaTheInjectedSpyOnAGenuineTopTenChange(): void
    {
        $this->endpoint->stop();
        $this->endpoint = new EndpointFixture();
        $this->endpoint->enableDigestSpy();
        $this->startEndpoint();

        $this->seedMatchedUniverse();
        $this->setRunAfter('00:00');
        $this->allowAllWeekdays();

        $runDate = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
        $previousTradingDay = $this->previousWeekday(new DateTimeImmutable($runDate));

        // Prior trading day: Alpha AB ahead of Beta AB.
        $this->seedOwnerCount('SE0000001001', $previousTradingDay, 3000);
        $this->seedOwnerCount('SE0000001002', $previousTradingDay, 2000);
        // Today: Beta AB overtakes Alpha AB -> a genuine rank-move diff.
        $this->seedOwnerCount('SE0000001001', $runDate, 3000);
        $this->seedOwnerCount('SE0000001002', $runDate, 3500);

        [$status, $body] = $this->endpoint->get('/cron/derive?token=test-token');
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $status, $body);
        self::assertSame('ok', $json['status'], "digest wiring must never affect derive's own response");
        self::assertSame($runDate, $json['run_date']);
        self::assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM ingest_run WHERE run_type = 'derive'")->fetchColumn(),
        );

        $spied = trim($this->endpoint->digestSpyContents());
        self::assertNotSame('', $spied, 'a genuine top-10 change must reach the injected mail sender');
        $sent = json_decode($spied, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('stockpicker@ryddmo.se', $sent['to']);
        self::assertStringContainsString($runDate, $sent['subject']);
        self::assertStringContainsString('Flest ägare:', $sent['message']);
        self::assertStringContainsString('Beta AB ↑ #2→#1', $sent['message']);
    }

    public function testDeriveOnlySendsTheDigestOnceEvenWhenHitTwiceForTheSameRunDate(): void
    {
        // Kundzon's URL-cron presets can't pin an exact time of day, only
        // fixed intervals (e.g. "every hour") -- so /cron/derive genuinely
        // does land inside the run_after window more than once some
        // evenings. A second hit for the same run_date must still write its
        // own ingest_run row (visibility into how many times it fired) but
        // must not re-send the digest.
        $this->endpoint->stop();
        $this->endpoint = new EndpointFixture();
        $this->endpoint->enableDigestSpy();
        $this->startEndpoint();

        $this->seedMatchedUniverse();
        $this->setRunAfter('00:00');
        $this->allowAllWeekdays();

        $runDate = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
        $previousTradingDay = $this->previousWeekday(new DateTimeImmutable($runDate));

        $this->seedOwnerCount('SE0000001001', $previousTradingDay, 3000);
        $this->seedOwnerCount('SE0000001002', $previousTradingDay, 2000);
        $this->seedOwnerCount('SE0000001001', $runDate, 3000);
        $this->seedOwnerCount('SE0000001002', $runDate, 3500);

        [$firstStatus] = $this->endpoint->get('/cron/derive?token=test-token');
        [$secondStatus, $secondBody] = $this->endpoint->get('/cron/derive?token=test-token');
        $secondJson = json_decode($secondBody, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $firstStatus);
        self::assertSame(200, $secondStatus);
        self::assertSame('ok', $secondJson['status'], "a repeat hit must not affect derive's own response");
        self::assertSame(
            2,
            (int) $this->pdo->query("SELECT COUNT(*) FROM ingest_run WHERE run_type = 'derive'")->fetchColumn(),
            'each hit still writes its own ingest_run row',
        );

        $spiedLines = array_filter(explode("\n", trim($this->endpoint->digestSpyContents())), static fn (string $l): bool => $l !== '');
        self::assertCount(1, $spiedLines, 'exactly one digest send across both hits, not two');
    }

    // -- /cron/derive: ShortPositionSync (spec-short-interest-data) ------------

    public function testDeriveShortsStepFailureNeverChangesTheResponseOrDigestAndRunsOncePerNight(): void
    {
        // EndpointFixture pins STOCKPICKER_FI_URL to an unresolvable host, so
        // the FI download fails Transient -- the step must record a failed
        // `shorts` run, write nothing, and leave derive + digest untouched.
        $this->endpoint->stop();
        $this->endpoint = new EndpointFixture();
        $this->endpoint->enableDigestSpy();
        $this->startEndpoint();

        $this->seedMatchedUniverse();
        $this->setRunAfter('00:00');
        $this->allowAllWeekdays();

        $runDate = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
        $previousTradingDay = $this->previousWeekday(new DateTimeImmutable($runDate));
        $this->seedOwnerCount('SE0000001001', $previousTradingDay, 3000);
        $this->seedOwnerCount('SE0000001002', $previousTradingDay, 2000);
        $this->seedOwnerCount('SE0000001001', $runDate, 3000);
        $this->seedOwnerCount('SE0000001002', $runDate, 3500);

        [$status, $body] = $this->endpoint->get('/cron/derive?token=test-token');
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->endpoint->get('/cron/derive?token=test-token');

        self::assertSame(200, $status, $body);
        self::assertSame(['status' => 'ok', 'run_date' => $runDate, 'instrument_count' => 4], $json);
        self::assertNotSame('', trim($this->endpoint->digestSpyContents()), 'the digest still went out');

        $shorts = $this->pdo->query("SELECT status, alarm FROM ingest_run WHERE run_type = 'shorts'")->fetchAll();
        self::assertCount(1, $shorts, 'one shorts run per night, inside the already-derived guard');
        self::assertSame('failed', $shorts[0]['status']);
        self::assertSame(0, (int) $shorts[0]['alarm']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM short_position')->fetchColumn());
    }

    public function testDeriveSendsNoDigestWhenDigestIsNotEnabled(): void
    {
        // Kill switch (2026-09-29): a fully configured digest section
        // without 'enabled' => true must never reach the mail sender.
        $this->endpoint->stop();
        $this->endpoint = new EndpointFixture();
        $this->endpoint->enableDigestSpy(enabled: false);
        $this->startEndpoint();

        $this->seedMatchedUniverse();
        $this->setRunAfter('00:00');
        $this->allowAllWeekdays();

        $runDate = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
        $previousTradingDay = $this->previousWeekday(new DateTimeImmutable($runDate));

        $this->seedOwnerCount('SE0000001001', $previousTradingDay, 3000);
        $this->seedOwnerCount('SE0000001002', $previousTradingDay, 2000);
        $this->seedOwnerCount('SE0000001001', $runDate, 3000);
        $this->seedOwnerCount('SE0000001002', $runDate, 3500);

        [$status, $body] = $this->endpoint->get('/cron/derive?token=test-token');
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $status, $body);
        self::assertSame('ok', $json['status']);
        self::assertSame('', trim($this->endpoint->digestSpyContents()), 'a disabled digest must not send');
        self::assertStringNotContainsString('top-10 digest failed', $this->endpoint->logContents());
    }

    public function testDeriveDigestFailureNeverAffectsDerivesOwnResponseOrIngestRunRow(): void
    {
        // enableBrokenDigest() switches the digest on but leaves out
        // username/recipient, so Config::digest() throws the moment
        // TopTenDigest tries to actually send -- the exact same try/catch in
        // index.php that would catch a real mail()-send failure catches this
        // too, so this exercises that failure path end to end without ever
        // needing a live mail transport in a test.
        $this->endpoint->stop();
        $this->endpoint = new EndpointFixture();
        $this->endpoint->enableBrokenDigest();
        $this->startEndpoint();

        $this->seedMatchedUniverse();
        $this->setRunAfter('00:00');
        $this->allowAllWeekdays();

        $runDate = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
        $previousTradingDay = $this->previousWeekday(new DateTimeImmutable($runDate));

        $this->seedOwnerCount('SE0000001001', $previousTradingDay, 3000);
        $this->seedOwnerCount('SE0000001002', $previousTradingDay, 2000);
        $this->seedOwnerCount('SE0000001001', $runDate, 3000);
        $this->seedOwnerCount('SE0000001002', $runDate, 3500);

        [$status, $body] = $this->endpoint->get('/cron/derive?token=test-token');
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $status, $body);
        self::assertSame('ok', $json['status']);
        self::assertSame(4, $json['instrument_count']);
        self::assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM ingest_run WHERE run_type = 'derive'")->fetchColumn(),
        );
        self::assertSame(
            'completed',
            $this->pdo->query("SELECT status FROM ingest_run WHERE run_type = 'derive'")->fetchColumn(),
        );
        self::assertStringContainsString('top-10 digest failed', $this->endpoint->logContents());
    }

    public function testDeriveSkipsDigestSilentlyWhenNoPreviousTradingDayHasDataYet(): void
    {
        $this->seedMatchedUniverse();
        $this->setRunAfter('00:00');
        $this->allowAllWeekdays();

        $runDate = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
        $this->seedOwnerCount('SE0000001001', $runDate, 3000);

        [$status, $body] = $this->endpoint->get('/cron/derive?token=test-token');
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $status, $body);
        self::assertSame('ok', $json['status']);
        self::assertStringNotContainsString(
            'top-10 digest failed',
            $this->endpoint->logContents(),
            'a first-ever run (no prior trading day data) must skip cleanly, without even needing digest config',
        );
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

    public function testRefillWeekendSkippedDoesNotRunPipeline(): void
    {
        $this->seedInstrument();
        $this->setRunAfter('00:00');
        $this->setRunWeekdays($this->aDifferentWeekdayThanToday());

        [$status, $body] = $this->endpoint->get('/cron/refill?token=test-token');
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $status);
        self::assertSame('weekend_skipped', $json['status']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM work_queue')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ingest_run')->fetchColumn());
    }

    public function testRefillHolidaySkippedDoesNotRunPipeline(): void
    {
        $this->seedInstrument();
        $this->setRunAfter('00:00');
        $this->allowAllWeekdays();
        $this->markTodayAsHoliday();

        [$status, $body] = $this->endpoint->get('/cron/refill?token=test-token');
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $status);
        self::assertSame('holiday_skipped', $json['status']);
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

    public function testDeriveMissingRunAfterReturnsGenericServerError(): void
    {
        $this->seedInstrument();
        $this->pdo->exec("DELETE FROM settings WHERE `key` = 'run_after'");

        [$status, $body] = $this->endpoint->get('/cron/derive?token=test-token');

        self::assertSame(500, $status);
        self::assertSame(['error' => 'internal server error'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ingest_run')->fetchColumn());
        self::assertStringContainsString('unhandled exception in front controller', $this->endpoint->logContents());
    }

    public function testDeriveMalformedRunAfterReturnsGenericServerError(): void
    {
        $this->seedInstrument();
        $this->setRunAfter('not-a-time');

        [$status, $body] = $this->endpoint->get('/cron/derive?token=test-token');

        self::assertSame(500, $status);
        self::assertSame(['error' => 'internal server error'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ingest_run')->fetchColumn());
    }

    // -- Story 4.2: / (Topplista) and /watchlist/toggle -----------------------

    public function testRootFlestAgareViewShowsTop10ByOwnerCountForAvanza(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000);
        $this->seedOwnerCount('SE0000001002', '2026-01-01', 5000);

        [$status, $body] = $this->endpoint->get('/?ranking=count', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('Topplista', $body);
        self::assertStringNotContainsString('<form', $body);
        self::assertStringContainsString('Beta AB', $body);
        // spec-5-1: renderRow()'s real output nests name/badges and owners/delta-chip
        // in the fixed mobile-layout wrapper columns, not just LeaderboardController::rowBodyHtml() in isolation.
        self::assertStringContainsString('class="namecol"', $body);
        self::assertStringContainsString('class="statcol"', $body);
        // Beta AB (5000 owners) must render before Alpha AB (1000) — descending by owner count.
        self::assertGreaterThan(
            strpos($body, 'Beta AB'),
            strpos($body, 'Alpha AB'),
        );
    }

    public function testRootRendersControllerGeneratedHrefsForSourceAndRankingSwitches(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000);

        [$status, $body] = $this->endpoint->get('/', $this->validCookie());

        self::assertSame(200, $status);
        // The default (Plusdagar) view's own Nordnet/Stadig tillväxt/Flest
        // ägare links — read from the controller's real output rather than
        // a hand-typed query string. Every link carries `ranking`
        // (spec-plusdagar-landing-cookie: a bare "/" means the remembered view).
        self::assertStringContainsString('href="/?source=nordnet&amp;ranking=plus"', $body);
        self::assertStringContainsString('href="/?ranking=steady"', $body);
        self::assertStringContainsString('href="/?ranking=count">Flest ägare</a>', $body);
    }

    public function testRootRendersTheSpikeStrokeClassForASpikingRowAndThePositiveClassForAGrowingRow(): void
    {
        $this->seedMatchedUniverse();

        // Alpha AB: 29 days of steady growth then a huge jump -> spike_score
        // >= 2 -> the sparkline must use the spike stroke class, not
        // positive/neutral, even though the last day is also numerically an
        // increase. Rendered on the Flest ägare view, since
        // Stadig tillväxt excludes spiking rows entirely (see the ranking
        // test above) and so never renders this row at all.
        $start = new DateTimeImmutable('2026-03-01');
        for ($i = 0; $i < 29; ++$i) {
            $this->seedOwnerCount('SE0000001001', $start->modify("+{$i} days")->format('Y-m-d'), 1000 + $i * 10);
        }
        $this->seedOwnerCount('SE0000001001', $start->modify('+29 days')->format('Y-m-d'), 1280 + 5000);

        // Beta AB: 8 clean up days -> sma_7 is present (not muted) and the
        // last delta is positive, with no spike -> the positive stroke class.
        foreach ([2000, 2010, 2020, 2030, 2040, 2050, 2060, 2070] as $i => $v) {
            $this->seedOwnerCount('SE0000001002', sprintf('2026-04-%02d', $i + 1), $v);
        }

        [$status, $body] = $this->endpoint->get('/?ranking=count', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('sparkline-line--spike', $this->rowHtmlFor($body, 'SE0000001001'));
        self::assertStringContainsString('sparkline-line--positive', $this->rowHtmlFor($body, 'SE0000001002'));
    }

    public function testRootWithSourceNordnetRendersNordnetDataOnlyNeverMergedWithAvanza(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 100, NormalizedRow::SOURCE_AVANZA);
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 999999, NormalizedRow::SOURCE_NORDNET);

        [$status, $body] = $this->endpoint->get('/?source=nordnet&ranking=count', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('999 999', $body);
        self::assertSame(1, substr_count($body, 'class="row"'), 'only the one isin with Nordnet data may appear');
    }

    public function testRootWithRankingSteadyShowsZeroQualifiersMessageWhenNothingQualifies(): void
    {
        $this->seedMatchedUniverse();
        // Over a month of history, but the last day is down -> up_streak 0
        // everywhere, so nothing qualifies for "Stadig tillväxt" (and the
        // short-history gate does not apply).
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000);
        $this->seedOwnerCount('SE0000001001', '2026-02-15', 990);

        [$status, $body] = $this->endpoint->get('/?ranking=steady', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('Inga aktier med stadig tillväxt just nu.', $body);
    }

    public function testRootWithRankingSteadyListsTheNonSpikingStreakerAndExcludesTheSpikingInstrument(): void
    {
        $this->seedMatchedUniverse();

        // Alpha AB: 29 days of steady growth then a huge jump -> highest
        // up_streak but spike_score >= 2 -> must be excluded entirely.
        $start = new DateTimeImmutable('2026-03-01');
        for ($i = 0; $i < 29; ++$i) {
            $this->seedOwnerCount('SE0000001001', $start->modify("+{$i} days")->format('Y-m-d'), 1000 + $i * 10);
        }
        $this->seedOwnerCount('SE0000001001', $start->modify('+29 days')->format('Y-m-d'), 1280 + 5000);

        // Beta AB: a clean short up-streak, no spike.
        foreach ([2000, 2010, 2020, 2030] as $i => $v) {
            $this->seedOwnerCount('SE0000001002', sprintf('2026-04-%02d', $i + 1), $v);
        }

        [$status, $body] = $this->endpoint->get('/?ranking=steady', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('Beta AB', $body);
        self::assertStringNotContainsString('Alpha AB', $body, 'the spiking instrument must never appear in Stadig tillväxt');
    }

    // -- spec-5-4: / with source=alla ------------------------------------------

    public function testRootDefaultViewIsStillAvanzaOnlyUnchangedFromStory42(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000);

        [$status, $body] = $this->endpoint->get('/', $this->validCookie());

        self::assertSame(200, $status);
        // Avanza (not Alla) is the active tab, and the default view's own
        // Avanza link omits `source` — Alla is first in the switcher's
        // tab order but is not the default landing source.
        self::assertStringContainsString('class="tab tab--active" href="/?ranking=plus">Avanza</a>', $body);
        self::assertStringNotContainsString('class="tab tab--active" href="/?source=alla&amp;ranking=plus">Alla</a>', $body);
        self::assertStringNotContainsString('stat-src', $body);
    }

    public function testRootSourceSwitcherShowsAllaAvanzaNordnetInThatOrderWithAllaFirst(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000);

        [$status, $body] = $this->endpoint->get('/?source=alla', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('class="tab tab--active" href="/?source=alla&amp;ranking=plus">Alla</a>', $body);

        $switcherHtml = $this->sourceSwitcherHtmlFor($body);
        $allaPos = strpos($switcherHtml, '>Alla<');
        $avanzaPos = strpos($switcherHtml, '>Avanza<');
        $nordnetPos = strpos($switcherHtml, '>Nordnet<');
        self::assertNotFalse($allaPos);
        self::assertNotFalse($avanzaPos);
        self::assertNotFalse($nordnetPos);
        self::assertLessThan($avanzaPos, $allaPos, 'Alla must render before Avanza');
        self::assertLessThan($nordnetPos, $avanzaPos, 'Avanza must render before Nordnet');
    }

    public function testRootWithSourceAllaShowsBothSourcesOwnerCountsSideBySideNeverSummed(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1234, NormalizedRow::SOURCE_AVANZA);
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 567, NormalizedRow::SOURCE_NORDNET);

        [$status, $body] = $this->endpoint->get('/?source=alla&ranking=count', $this->validCookie());

        self::assertSame(200, $status);
        $rowHtml = $this->rowHtmlFor($body, 'SE0000001001');
        self::assertStringContainsString('<span class="stat-line"><span class="stat-src">Avanza</span> 1 234</span><span class="stat-line"><span class="stat-src">Nordnet</span> 567</span>', $rowHtml);
        self::assertStringNotContainsString('1 801', $rowHtml, 'the two counts must never be summed');
    }

    public function testRootWithSourceAllaShowsIngenDataForAnIsinMissingFromNordnet(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1234, NormalizedRow::SOURCE_AVANZA);
        // No Nordnet row at all for SE0000001001.

        [$status, $body] = $this->endpoint->get('/?source=alla&ranking=count', $this->validCookie());

        self::assertSame(200, $status);
        $rowHtml = $this->rowHtmlFor($body, 'SE0000001001');
        self::assertStringContainsString('<span class="stat-line"><span class="stat-src">Avanza</span> 1 234</span><span class="stat-line"><span class="stat-src">Nordnet</span> ingen data</span>', $rowHtml);
    }

    public function testRootWithSourceAllaRanksByAvanzaOwnerCountWithNordnetShownAlongsideNeverAsTheRankingBasis(): void
    {
        $this->seedMatchedUniverse();
        // Alpha AB: lower Avanza count, but much higher Nordnet count -- if
        // Nordnet were ever used as the ranking basis, Alpha would rank
        // above Beta. It must not: Avanza's count is the sole ranking basis.
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000, NormalizedRow::SOURCE_AVANZA);
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 999999, NormalizedRow::SOURCE_NORDNET);
        $this->seedOwnerCount('SE0000001002', '2026-01-01', 5000, NormalizedRow::SOURCE_AVANZA);

        [$status, $body] = $this->endpoint->get('/?source=alla&ranking=count', $this->validCookie());

        self::assertSame(200, $status);
        self::assertGreaterThan(
            strpos($body, 'Beta AB'),
            strpos($body, 'Alpha AB'),
            'Beta AB (5000 Avanza owners) must rank above Alpha AB (1000 Avanza owners) despite Nordnet counts',
        );
    }

    public function testRootWithSourceAllaAndRankingSteadyRanksByTheSameIsinsAndOrderAsAvanzasStadigTillvaxt(): void
    {
        $this->seedMatchedUniverse();

        // Alpha AB: 29 days of steady growth then a huge jump -> highest
        // up_streak but spike_score >= 2 -> excluded from both modes.
        $start = new DateTimeImmutable('2026-03-01');
        for ($i = 0; $i < 29; ++$i) {
            $this->seedOwnerCount('SE0000001001', $start->modify("+{$i} days")->format('Y-m-d'), 1000 + $i * 10);
        }
        $this->seedOwnerCount('SE0000001001', $start->modify('+29 days')->format('Y-m-d'), 1280 + 5000);

        // Beta AB: a clean short up-streak, no spike. Nordnet data present
        // too, to prove it's display-only and never affects the ranking.
        foreach ([2000, 2010, 2020, 2030] as $i => $v) {
            $this->seedOwnerCount('SE0000001002', sprintf('2026-04-%02d', $i + 1), $v);
        }
        $this->seedOwnerCount('SE0000001002', '2026-04-04', 42, NormalizedRow::SOURCE_NORDNET);

        [, $avanzaBody] = $this->endpoint->get('/?ranking=steady', $this->validCookie());
        [$status, $allaBody] = $this->endpoint->get('/?source=alla&ranking=steady', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('Beta AB', $avanzaBody);
        self::assertStringContainsString('Beta AB', $allaBody);
        self::assertStringNotContainsString('Alpha AB', $allaBody, 'the spiking instrument must never appear in Stadig tillväxt, in Alla mode either');
        $betaRowHtml = $this->rowHtmlFor($allaBody, 'SE0000001002');
        self::assertStringContainsString('<span class="stat-line"><span class="stat-src">Avanza</span> 2 030</span><span class="stat-line"><span class="stat-src">Nordnet</span> 42</span>', $betaRowHtml);
        // The sparkline must be plotted from Avanza's real (4-day, muted --
        // fewer than the 7-day gate) trend, not accidentally empty:
        // recentSeries() is keyed by an exact `source` match, so if Alla
        // mode's series fetch ever regressed to querying source='alla'
        // literally, every row would silently fall back to the true
        // "sparkline--empty" state (<2 points) instead of a real, if muted,
        // polyline.
        self::assertStringContainsString('<polyline class="sparkline-line--nohistory"', $betaRowHtml);
        self::assertStringNotContainsString('sparkline sparkline--empty', $betaRowHtml);
    }

    public function testRootWithSourceAllaAndRankingSteadyShowsTheEmptyStateWhenNoInstrumentQualifies(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000);
        $this->seedOwnerCount('SE0000001001', '2026-02-15', 990);
        // No up-streak seeded anywhere -- nothing qualifies for Stadig
        // tillväxt, in Alla mode either. The Nordnet batch fetch must not
        // be attempted (nothing to fetch for) and must not error.

        [$status, $body] = $this->endpoint->get('/?source=alla&ranking=steady', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('Inga aktier med stadig tillväxt just nu.', $body);
    }

    // -- spec-stadig-tillvaxt-period: /?ranking=steady with a period ---------

    public function testRootRankingSteadyDefaultsToManadInItsOwnPeriodRowAndOrdersByPct30d(): void
    {
        $this->seedMatchedUniverse();
        // Alpha: +1 every weekday -> longest streak, ~2 % over the month.
        $this->seedPlusMonth('SE0000001001', 1000, 1);
        // Beta: +20 a day with a flat day on 2026-09-29 -> streak 2, but
        // ~40 % over the month -> ranks first on the period's growth.
        $this->seedPlusMonth('SE0000001002', 1000, 20, ['2026-09-29' => 0]);

        [$status, $body] = $this->endpoint->get('/?ranking=steady', $this->validCookie());

        self::assertSame(200, $status, $body);
        self::assertStringContainsString('class="tab tab--active" href="/?ranking=steady">Stadig tillväxt</a>', $body);
        $periodRow = $this->periodRowHtmlFor($body);
        self::assertStringContainsString('class="range-picker" role="tablist" aria-label="Period"', $periodRow);
        self::assertStringContainsString('class="tab tab--active" href="/?ranking=steady" aria-current="true">Månad</a>', $periodRow);
        self::assertStringContainsString('class="tab" href="/?ranking=steady&amp;period=vecka">Vecka</a>', $periodRow);
        self::assertStringContainsString('class="tab" href="/?ranking=steady&amp;period=3man">3 mån</a>', $periodRow);
        self::assertStringContainsString('class="tab" href="/?ranking=steady&amp;period=ar">År</a>', $periodRow);
        self::assertStringNotContainsString('Dölj spikar', $body, 'Stadig tillväxt always excludes spikes -- no toggle');
        // Its own row after the header controls, not inside them: by the
        // time the period row opens, every <div> opened since .controls
        // (that one included) must already be closed.
        $controlsStart = strpos($body, '<div class="controls">');
        $periodRowStart = strpos($body, '<div class="period-row">');
        self::assertNotFalse($controlsStart);
        self::assertNotFalse($periodRowStart);
        self::assertLessThan($periodRowStart, $controlsStart);
        $between = substr($body, $controlsStart, $periodRowStart - $controlsStart);
        self::assertSame(substr_count($between, '<div'), substr_count($between, '</div>'), '.controls must close before the period row');

        self::assertLessThan(strpos($body, 'Alpha AB'), strpos($body, 'Beta AB'), 'ordered by pct_30d, not streak length');
    }

    public function testRootRankingSteadyVeckaOrdersByPct7dAndSharesThePeriodWithPlusdagar(): void
    {
        $this->seedMatchedUniverse();
        // Alpha: +20 a day all month, then +1 the last week -> leads on
        // the month, trails on the week.
        $this->seedPlusMonth('SE0000001001', 1000, 20, [
            '2026-09-24' => 1, '2026-09-25' => 1, '2026-09-28' => 1,
            '2026-09-29' => 1, '2026-09-30' => 1, '2026-10-01' => 1,
        ]);
        // Beta: +1 a day, then +30 the last week.
        $this->seedPlusMonth('SE0000001002', 1000, 1, [
            '2026-09-24' => 30, '2026-09-25' => 30, '2026-09-28' => 30,
            '2026-09-29' => 30, '2026-09-30' => 30, '2026-10-01' => 30,
        ]);

        [, $manadBody] = $this->endpoint->get('/?ranking=steady', $this->validCookie());
        [$status, $body] = $this->endpoint->get('/?ranking=steady&period=vecka&spikes=exclude', $this->validCookie());

        self::assertSame(200, $status, $body);
        self::assertLessThan(strpos($manadBody, 'Beta AB'), strpos($manadBody, 'Alpha AB'), 'Månad: Alpha leads');
        self::assertLessThan(strpos($body, 'Alpha AB'), strpos($body, 'Beta AB'), 'Vecka: Beta leads on pct_7d');

        self::assertStringContainsString('class="tab tab--active" href="/?ranking=steady&amp;period=vecka" aria-current="true">Vecka</a>', $this->periodRowHtmlFor($body));
        // Switching steady -> plus keeps the period, and so does Flest
        // ägare (spec-topplista-market-filter — it ignores the period but
        // carries it so it survives the round trip).
        self::assertStringContainsString('class="tab" href="/?ranking=plus&amp;period=vecka">Plusdagar</a>', $body);
        self::assertStringContainsString('class="tab" href="/?ranking=count&amp;period=vecka">Flest ägare</a>', $body);
        // The period survives a source switch.
        self::assertStringContainsString(
            'href="/?source=nordnet&amp;ranking=steady&amp;period=vecka">Nordnet</a>',
            $this->sourceSwitcherHtmlFor($body),
        );
        // ?spikes=exclude is ignored in Stadig tillväxt and never carried.
        self::assertStringNotContainsString('spikes=', $body);
        self::assertStringNotContainsString('Dölj spikar', $body);
    }

    public function testRootRankingSteadyShowsShortHistoryEmptyStateWhenHistoryIsShorterThanThePeriod(): void
    {
        $this->seedMatchedUniverse();
        // 2026-09-22 .. 2026-10-01: 9 calendar days of history, all growing.
        foreach (['2026-09-22' => 100, '2026-09-23' => 105, '2026-09-24' => 110, '2026-09-25' => 115, '2026-09-28' => 120, '2026-09-29' => 130, '2026-09-30' => 140, '2026-10-01' => 150] as $date => $owners) {
            $this->seedOwnerCount('SE0000001001', $date, $owners);
        }

        [$status, $body] = $this->endpoint->get('/?ranking=steady', $this->validCookie());
        self::assertSame(200, $status);
        self::assertStringContainsString('För lite historik för månad ännu.', $body);
        self::assertStringNotContainsString('data-isin=', $body);
        self::assertStringNotContainsString('Inga aktier med stadig tillväxt', $body);
        self::assertStringContainsString('class="period-row"', $body, 'the period row stays so another period can be chosen');

        [$status, $body] = $this->endpoint->get('/?ranking=steady&period=ar', $this->validCookie());
        self::assertSame(200, $status);
        self::assertStringContainsString('För lite historik för ett år ännu.', $body);

        [$status, $body] = $this->endpoint->get('/?ranking=steady&period=vecka', $this->validCookie());
        self::assertSame(200, $status);
        self::assertStringContainsString('Alpha AB', $body, 'a week of history is enough for Vecka');
    }

    public function testRootRankingSteadyWithGarbagePeriodFallsBackToManad(): void
    {
        $this->seedMatchedUniverse();
        $this->seedPlusMonth('SE0000001001', 1000, 1);

        [$status, $body] = $this->endpoint->get('/?ranking=steady&period=xyz', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('class="tab tab--active" href="/?ranking=steady" aria-current="true">Månad</a>', $this->periodRowHtmlFor($body));
        self::assertStringContainsString('Alpha AB', $body);

        [$status] = $this->endpoint->get('/?ranking=steady&period[]=vecka', $this->validCookie());
        self::assertSame(200, $status, 'array-shaped params never 500');
    }

    public function testRootRankingTabsAreAlwaysPlainNames(): void
    {
        $this->seedMatchedUniverse();
        $this->seedPlusMonth('SE0000001001', 1000, 1);

        foreach (['/', '/?ranking=steady&period=3man', '/?ranking=plus&period=ar', '/?source=alla&ranking=plus'] as $path) {
            [$status, $body] = $this->endpoint->get($path, $this->validCookie());
            self::assertSame(200, $status, $path);
            self::assertSame(1, preg_match('~<div class="ranking-toggle"[^>]*>(.*?)</div>~s', $body, $m), $path);
            self::assertSame(['Flest ägare', 'Stadig tillväxt', 'Plusdagar'], array_map('trim', explode("\n", trim(strip_tags($m[1])))), $path);
        }
    }

    // -- spec-plusdagar: /?ranking=plus ------------------------------------------

    /**
     * Mon–Fri rows 2026-08-25 .. 2026-10-01 (D), owners +$step per day except
     * where $overrides gives that date's own delta.
     *
     * @param array<string, int> $overrides
     */
    private function seedPlusMonth(string $isin, int $base, int $step, array $overrides = [], string $source = NormalizedRow::SOURCE_AVANZA): void
    {
        $date = new DateTimeImmutable('2026-08-25');
        $end = new DateTimeImmutable('2026-10-01');
        $v = $base;
        $first = true;
        while ($date <= $end) {
            if ((int) $date->format('N') <= 5) {
                $key = $date->format('Y-m-d');
                if (!$first) {
                    $v += $overrides[$key] ?? $step;
                }
                $first = false;
                $this->seedOwnerCount($isin, $key, $v, $source);
            }
            $date = $date->modify('+1 day');
        }
    }

    public function testRootRankingPlusDefaultsToManadAndOrdersByPlusDaysThenNewOwners(): void
    {
        $this->seedMatchedUniverse();
        // Alpha: every day up by 1 -> all plus days, small net.
        $this->seedPlusMonth('SE0000001001', 1000, 1);
        // Beta: one bad day (as in "Stadig tillväxt" it would vanish) but huge net.
        $this->seedPlusMonth('SE0000001002', 50000, 100, ['2026-09-30' => -40]);
        // Gamma: flat all month -> net 0 -> never ranked.
        $this->seedPlusMonth('SE0000001003', 2000, 0);
        // Delta: net negative with mostly flat days -> never ranked.
        $this->seedPlusMonth('SE0000001004', 2000, 0, ['2026-09-22' => -1]);

        [$status, $body] = $this->endpoint->get('/?ranking=plus', $this->validCookie());

        self::assertSame(200, $status, $body);
        self::assertStringContainsString('class="tab tab--active" href="/?ranking=plus">Plusdagar</a>', $body);
        self::assertStringContainsString('class="tab tab--active" href="/?ranking=plus" aria-current="true">Månad</a>', $this->periodRowHtmlFor($body));
        self::assertStringContainsString('href="/?ranking=plus&amp;period=vecka">Vecka</a>', $body);
        self::assertStringContainsString('href="/?ranking=plus&amp;period=3man">3 mån</a>', $body);
        self::assertStringContainsString('href="/?ranking=plus&amp;period=ar">År</a>', $body);
        self::assertStringContainsString('href="/?ranking=plus&amp;spikes=exclude"><span aria-hidden="true">☐</span> Dölj spikar</a>', $body);

        self::assertStringContainsString('Alpha AB', $body);
        self::assertStringContainsString('Beta AB', $body);
        self::assertStringNotContainsString('Gamma AB', $body, 'net 0 must never rank');
        self::assertStringNotContainsString('Delta AB', $body, 'net negative must never rank');
        self::assertLessThan(strpos($body, 'Beta AB'), strpos($body, 'Alpha AB'), 'more plus days ranks first, regardless of new owners');

        // Window (2026-09-01, 2026-10-01] holds 22 weekdays, all data days.
        self::assertStringContainsString('22/22 · +22', strip_tags($this->rowHtmlFor($body, 'SE0000001001')));
        self::assertStringContainsString('21/22 · +2 060', strip_tags($this->rowHtmlFor($body, 'SE0000001002')));
        // Beta's bad day was 2026-09-30, not D: today's delta chip is positive.
        self::assertStringContainsString('delta-chip--positive', $this->rowHtmlFor($body, 'SE0000001002'));
    }

    public function testRootRankingPlusListsAnInstrumentWithOneDownDayThatStadigTillvaxtDrops(): void
    {
        $this->seedMatchedUniverse();
        $this->seedPlusMonth('SE0000001002', 50000, 100, ['2026-10-01' => -40]);

        [, $steadyBody] = $this->endpoint->get('/?ranking=steady', $this->validCookie());
        [$status, $plusBody] = $this->endpoint->get('/?ranking=plus', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringNotContainsString('Beta AB', $steadyBody);
        self::assertStringContainsString('Beta AB', $plusBody);
        self::assertStringContainsString('21/22 · +2 060', strip_tags($this->rowHtmlFor($plusBody, 'SE0000001002')));
        self::assertStringContainsString('delta-chip--negative', $this->rowHtmlFor($plusBody, 'SE0000001002'), 'the bad day is visible');
    }

    public function testRootRankingPlusPeriodVeckaUsesTheSevenDayWindowAndKeepsPeriodInLinks(): void
    {
        $this->seedMatchedUniverse();
        $this->seedPlusMonth('SE0000001001', 1000, 3);

        [$status, $body] = $this->endpoint->get('/?ranking=plus&period=vecka&source=nordnet', $this->validCookie());
        self::assertSame(200, $status);
        self::assertStringContainsString('Inga aktier med fler ägare under perioden.', $body, 'Nordnet has no data -> no qualifiers');
        self::assertStringContainsString('href="/?ranking=plus&amp;period=vecka">Avanza</a>', $body, 'period survives a source switch');

        [$status, $body] = $this->endpoint->get('/?ranking=plus&period=vecka', $this->validCookie());
        self::assertSame(200, $status);
        self::assertStringContainsString('class="tab tab--active" href="/?ranking=plus&amp;period=vecka">Plusdagar</a>', $body);
        self::assertStringContainsString('class="tab tab--active" href="/?ranking=plus&amp;period=vecka" aria-current="true">Vecka</a>', $this->periodRowHtmlFor($body));
        self::assertStringContainsString('5/5 · +15', strip_tags($this->rowHtmlFor($body, 'SE0000001001')));
        // spec-stadig-tillvaxt-period: the period is shared with Stadig
        // tillväxt (kept on switch); spec-topplista-market-filter — Flest
        // ägare carries it too.
        self::assertStringContainsString('href="/?ranking=steady&amp;period=vecka">Stadig tillväxt</a>', $body);
        self::assertStringContainsString('href="/?ranking=count&amp;period=vecka">Flest ägare</a>', $body);
    }

    public function testRootRankingPlusShowsShortHistoryEmptyStateFor3ManAndAr(): void
    {
        $this->seedMatchedUniverse();
        $this->seedPlusMonth('SE0000001001', 1000, 3); // ~37 days of history

        [$status, $body] = $this->endpoint->get('/?ranking=plus&period=3man', $this->validCookie());
        self::assertSame(200, $status);
        self::assertStringContainsString('För lite historik för 3 mån ännu.', $body);
        self::assertStringNotContainsString('data-isin=', $body);

        [$status, $body] = $this->endpoint->get('/?ranking=plus&period=ar', $this->validCookie());
        self::assertSame(200, $status);
        self::assertStringContainsString('För lite historik för ett år ännu.', $body);
    }

    public function testRootRankingPlusManadStillRanksWhenHistoryIsShorterThan30Days(): void
    {
        $this->seedMatchedUniverse();
        // Only 2026-09-24 .. 2026-10-01 (8 days of history): no history gate for Månad.
        foreach (['2026-09-24' => 100, '2026-09-25' => 110, '2026-09-28' => 120, '2026-09-29' => 130, '2026-09-30' => 140, '2026-10-01' => 150] as $date => $owners) {
            $this->seedOwnerCount('SE0000001001', $date, $owners);
        }

        [$status, $body] = $this->endpoint->get('/?ranking=plus', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringNotContainsString('För lite historik', $body);
        self::assertStringContainsString('5/5 · +50', strip_tags($this->rowHtmlFor($body, 'SE0000001001')));
    }

    public function testRootRankingPlusShowsNoQualifiersEmptyState(): void
    {
        $this->seedMatchedUniverse();
        $this->seedPlusMonth('SE0000001001', 1000, -2);

        [$status, $body] = $this->endpoint->get('/?ranking=plus', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('Inga aktier med fler ägare under perioden.', $body);
    }

    public function testRootRankingPlusSpikeToggleHidesOnlyTheSpikingInstrument(): void
    {
        $this->seedMatchedUniverse();
        // Alpha: 29 calendar days of growth, then a huge jump on D -> spike_score >= 2.
        $start = new DateTimeImmutable('2026-09-02');
        for ($i = 0; $i < 29; ++$i) {
            $this->seedOwnerCount('SE0000001001', $start->modify("+{$i} days")->format('Y-m-d'), 1000 + $i * 10);
        }
        $this->seedOwnerCount('SE0000001001', '2026-10-01', 1280 + 5000);
        // Beta: too little history for a spike_score -> never hidden.
        $this->seedOwnerCount('SE0000001002', '2026-09-30', 100);
        $this->seedOwnerCount('SE0000001002', '2026-10-01', 110);

        [, $defaultBody] = $this->endpoint->get('/?ranking=plus&period=vecka', $this->validCookie());
        [$status, $hiddenBody] = $this->endpoint->get('/?ranking=plus&period=vecka&spikes=exclude', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('Alpha AB', $defaultBody, 'spikes are included by default');
        self::assertStringContainsString('Beta AB', $defaultBody);
        self::assertStringNotContainsString('Alpha AB', $hiddenBody);
        self::assertStringContainsString('Beta AB', $hiddenBody);
        self::assertStringContainsString('class="spike-toggle spike-toggle--active" href="/?ranking=plus&amp;period=vecka"><span aria-hidden="true">☑</span> Dölj spikar</a>', $hiddenBody);
        self::assertStringContainsString('href="/?ranking=plus&amp;spikes=exclude">Månad</a>', $hiddenBody, 'spike toggle survives a period switch');
        self::assertStringContainsString(
            'href="/?source=nordnet&amp;ranking=plus&amp;period=vecka&amp;spikes=exclude">Nordnet</a>',
            $this->sourceSwitcherHtmlFor($hiddenBody),
            'spike toggle survives a source switch',
        );
    }

    public function testRootRankingPlusWithSourceAllaRanksOnAvanzaAndShowsNordnetAlongsideNeverSummed(): void
    {
        $this->seedMatchedUniverse();
        $this->seedPlusMonth('SE0000001001', 1000, 1);
        $this->seedPlusMonth('SE0000001002', 2000, 2);
        // Nordnet: Alpha booming, Beta falling -- must not affect the ranking.
        $this->seedPlusMonth('SE0000001001', 500, -5, [], NormalizedRow::SOURCE_NORDNET);
        $this->seedPlusMonth('SE0000001002', 700, 50, [], NormalizedRow::SOURCE_NORDNET);

        [, $avanzaBody] = $this->endpoint->get('/?ranking=plus', $this->validCookie());
        [$status, $allaBody] = $this->endpoint->get('/?source=alla&ranking=plus', $this->validCookie());

        self::assertSame(200, $status);
        self::assertLessThan(strpos($avanzaBody, 'Alpha AB'), strpos($avanzaBody, 'Beta AB'));
        self::assertLessThan(strpos($allaBody, 'Alpha AB'), strpos($allaBody, 'Beta AB'), 'Alla ranks exactly like Avanza');
        $alphaRow = $this->rowHtmlFor($allaBody, 'SE0000001001');
        self::assertStringContainsString('22/22 · +22', strip_tags($alphaRow), 'the chip is Avanza-derived');
        self::assertStringContainsString('<span class="stat-src">Avanza</span> 1 027</span>', $alphaRow);
        self::assertStringContainsString('<span class="stat-src">Nordnet</span> 365</span>', $alphaRow);
        self::assertStringNotContainsString('1 392', $alphaRow, 'never summed');
    }

    public function testRootRankingPlusWithGarbageParamsFallsBackToManadWithSpikesIncluded(): void
    {
        $this->seedMatchedUniverse();
        $this->seedPlusMonth('SE0000001001', 1000, 1);

        [$status, $body] = $this->endpoint->get('/?ranking=plus&period=xyz&spikes=7', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('class="tab tab--active" href="/?ranking=plus">Plusdagar</a>', $body);
        self::assertStringContainsString('class="tab tab--active" href="/?ranking=plus" aria-current="true">Månad</a>', $this->periodRowHtmlFor($body));
        self::assertStringContainsString('<span aria-hidden="true">☐</span> Dölj spikar', $body);
        self::assertStringContainsString('22/22 · +22', strip_tags($this->rowHtmlFor($body, 'SE0000001001')));

        [$status] = $this->endpoint->get('/?ranking[]=plus&period[]=x&spikes[]=y', $this->validCookie());
        self::assertSame(200, $status, 'array-shaped params never 500');
    }

    public function testRootOtherRankingModesShowNoPlusdagarControlsOrChip(): void
    {
        $this->seedMatchedUniverse();
        $this->seedPlusMonth('SE0000001001', 1000, 1);

        [$status, $body] = $this->endpoint->get('/?ranking=count', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('class="tab" href="/?ranking=plus">Plusdagar</a>', $body);
        // spec-topplista-market-filter — Flest ägare shows the period row,
        // but greyed out (covered in detail by the market-filter tests).
        self::assertStringContainsString('class="period-row period-row--muted"', $body);
        self::assertStringNotContainsString('Dölj spikar', $body);
        self::assertStringNotContainsString('badge--plusdays', $body);
    }

    // -- spec-topplista-market-filter: /?market= ------------------------------

    /**
     * Seeds one extra active instrument per market on top of
     * seedMatchedUniverse()'s four, so a market holds two instruments.
     */
    private function seedSecondPerMarket(): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO instrument (isin, name, list, avanza_orderbook_id, nordnet_instrument_id, first_seen)
             VALUES (?, ?, ?, ?, ?, '2026-01-01')"
        );
        foreach ([
            ['SE0000002001', 'Alpha Två AB', 'LC', '2001'],
            ['SE0000002002', 'Beta Två AB', 'MC', '2002'],
            ['SE0000002003', 'Gamma Två AB', 'SC', '2003'],
            ['SE0000002004', 'Delta Två AB', 'First North', '2004'],
        ] as [$isin, $name, $list, $obId]) {
            $stmt->execute([$isin, $name, $list, $obId, 'nx-' . $obId]);
        }
    }

    /** @return list<string> the isins of the rendered Topplista rows, in order */
    private function rowIsins(string $body): array
    {
        preg_match_all('#<a class="row-body" href="/stock/([A-Z0-9]+)">#', $body, $m);

        return $m[1];
    }

    public function testRootMarketNarrowsTheTopTenWithinTheMarket(): void
    {
        $this->seedMatchedUniverse();
        $stmt = $this->pdo->prepare(
            "INSERT INTO instrument (isin, name, list, avanza_orderbook_id, nordnet_instrument_id, first_seen)
             VALUES (?, ?, 'SC', ?, ?, '2026-01-01')"
        );
        // 12 SC instruments (plus Gamma) -> more than 10 SC qualifiers.
        for ($i = 1; $i <= 12; $i++) {
            $isin = sprintf('SE00000030%02d', $i);
            $stmt->execute([$isin, sprintf('Sc %02d AB', $i), (string) (3000 + $i), 'nx-' . (3000 + $i)]);
            $this->seedOwnerCount($isin, '2026-10-01', 100 + $i);
        }
        $this->seedOwnerCount('SE0000001003', '2026-10-01', 50);
        // LC/MC/First North with far more owners: would fill the Alla top 10.
        $this->seedOwnerCount('SE0000001001', '2026-10-01', 90000);
        $this->seedOwnerCount('SE0000001002', '2026-10-01', 80000);
        $this->seedOwnerCount('SE0000001004', '2026-10-01', 70000);

        [$status, $body] = $this->endpoint->get('/?ranking=count&market=SC', $this->validCookie());

        self::assertSame(200, $status, $body);
        $isins = $this->rowIsins($body);
        self::assertCount(10, $isins);
        $expected = [];
        for ($i = 12; $i >= 3; $i--) {
            $expected[] = sprintf('SE00000030%02d', $i);
        }
        self::assertSame($expected, $isins, 'the best 10 SC by owner count, not a post-filtered Alla top 10');
        self::assertStringContainsString('class="tab tab--active" href="/?ranking=count&amp;market=SC" aria-current="true">SC</a>', $this->marketRowHtmlFor($body));
    }

    public function testRootMarketNarrowsEachRankingModeInItsOwnOrder(): void
    {
        $this->seedMatchedUniverse();
        $this->seedSecondPerMarket();
        $stmt = $this->pdo->prepare(
            "INSERT INTO instrument (isin, name, list, avanza_orderbook_id, nordnet_instrument_id, first_seen)
             VALUES ('SE0000003002', 'Beta Tre AB', 'MC', '3002', 'nx-3002', '2026-01-01')"
        );
        $stmt->execute();
        // LC: Alpha (+5000/day) would top every mode under Alla.
        $this->seedPlusMonth('SE0000001001', 100000, 5000);
        // MC, one order per mode:
        //  Beta: most owners, lowest %, one down day (one plus day short).
        //  Beta Två: fewest owners, highest %.
        //  Beta Tre: middle owners/%, most new owners.
        $this->seedPlusMonth('SE0000001002', 50000, 10, ['2026-09-15' => -5]);
        $this->seedPlusMonth('SE0000002002', 1000, 5);
        $this->seedPlusMonth('SE0000003002', 2000, 8);

        $beta = 'SE0000001002';
        $betaTva = 'SE0000002002';
        $betaTre = 'SE0000003002';
        foreach ([
            '/?ranking=count&market=MC' => [$beta, $betaTre, $betaTva],
            '/?ranking=steady&market=MC' => [$betaTva, $betaTre, $beta],
            '/?ranking=plus&market=MC' => [$betaTre, $betaTva, $beta],
            // Alla source mode ranks on Avanza, narrowed to MC too.
            '/?source=alla&ranking=count&market=MC' => [$beta, $betaTre, $betaTva],
        ] as $path => $expected) {
            [$status, $body] = $this->endpoint->get($path, $this->validCookie());
            self::assertSame(200, $status, $path . "\n" . $body);
            self::assertSame($expected, $this->rowIsins($body), $path);
        }

        [, $alla] = $this->endpoint->get('/?ranking=plus', $this->validCookie());
        self::assertSame('SE0000001001', $this->rowIsins($alla)[0], 'Alla market still includes LC');
    }

    public function testRootPeriodAndMarketRowLinksCarryEveryOtherChoice(): void
    {
        $this->seedMatchedUniverse();
        $this->seedPlusMonth('SE0000001001', 1000, 1);

        [$status, $body] = $this->endpoint->get('/?ranking=plus&period=vecka&spikes=exclude&market=LC', $this->validCookie());
        self::assertSame(200, $status);
        self::assertStringContainsString(
            'href="/?ranking=plus&amp;period=3man&amp;spikes=exclude&amp;market=LC">3 mån</a>',
            $this->periodRowHtmlFor($body),
        );
        self::assertStringContainsString(
            'href="/?ranking=plus&amp;period=vecka&amp;spikes=exclude&amp;market=MC">MC</a>',
            $this->marketRowHtmlFor($body),
        );

        [$status, $body] = $this->endpoint->get('/?ranking=steady&period=3man&market=SC', $this->validCookie());
        self::assertSame(200, $status);
        self::assertStringContainsString(
            'href="/?ranking=steady&amp;period=vecka&amp;market=SC">Vecka</a>',
            $this->periodRowHtmlFor($body),
        );
        self::assertStringContainsString(
            'href="/?ranking=steady&amp;period=3man&amp;market=MC">MC</a>',
            $this->marketRowHtmlFor($body),
        );
        self::assertStringContainsString(
            'href="/?ranking=steady&amp;period=3man">Alla</a>',
            $this->marketRowHtmlFor($body),
        );
    }

    public function testRootWithoutMarketShowsAllaActiveAndTheUnnarrowedTopTen(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-10-01', 400);
        $this->seedOwnerCount('SE0000001002', '2026-10-01', 300);
        $this->seedOwnerCount('SE0000001003', '2026-10-01', 200);
        $this->seedOwnerCount('SE0000001004', '2026-10-01', 100);

        [$status, $body] = $this->endpoint->get('/?ranking=count', $this->validCookie());

        self::assertSame(200, $status, $body);
        self::assertSame(['SE0000001001', 'SE0000001002', 'SE0000001003', 'SE0000001004'], $this->rowIsins($body));
        $marketRow = $this->marketRowHtmlFor($body);
        self::assertStringContainsString('class="range-picker" role="tablist" aria-label="Marknad"', $marketRow);
        self::assertStringContainsString('class="tab tab--active" href="/?ranking=count" aria-current="true">Alla</a>', $marketRow);
        self::assertStringContainsString('class="tab" href="/?ranking=count&amp;market=LC">LC</a>', $marketRow);
        self::assertStringContainsString('class="tab" href="/?ranking=count&amp;market=MC">MC</a>', $marketRow);
        self::assertStringContainsString('class="tab" href="/?ranking=count&amp;market=SC">SC</a>', $marketRow);
        self::assertStringContainsString('class="tab" href="/?ranking=count&amp;market=First+North">First North</a>', $marketRow);
        self::assertStringNotContainsString('market=', $this->sourceSwitcherHtmlFor($body), 'Alla is omitted from links');
        self::assertStringContainsString('<a href="/list">Visa fullständig lista</a>', $body);
    }

    public function testRootGarbageMarketFallsBackToAlla(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-10-01', 400);
        $this->seedOwnerCount('SE0000001002', '2026-10-01', 300);

        foreach (['/?ranking=count&market=XX', '/?ranking=count&market[]=LC', '/?ranking=count&market=lc', "/?ranking=count&market=LC'%20OR%201=1"] as $path) {
            [$status, $body] = $this->endpoint->get($path, $this->validCookie());
            self::assertSame(200, $status, $path);
            self::assertSame(['SE0000001001', 'SE0000001002'], $this->rowIsins($body), $path);
            self::assertStringContainsString('class="tab tab--active" href="/?ranking=count" aria-current="true">Alla</a>', $this->marketRowHtmlFor($body), $path);
        }
    }

    public function testRootEmptyMarketShowsTheModesExistingEmptyState(): void
    {
        $this->seedMatchedUniverse();
        // Only LC has data; First North has no qualifiers in any mode.
        $this->seedPlusMonth('SE0000001001', 1000, 1);

        [$status, $body] = $this->endpoint->get('/?ranking=steady&market=First%20North', $this->validCookie());
        self::assertSame(200, $status);
        self::assertStringContainsString('Inga aktier med stadig tillväxt just nu.', $body);

        [$status, $body] = $this->endpoint->get('/?ranking=plus&market=First%20North', $this->validCookie());
        self::assertSame(200, $status);
        self::assertStringContainsString('Inga aktier med fler ägare under perioden.', $body);

        [$status, $body] = $this->endpoint->get('/?ranking=count&market=First%20North', $this->validCookie());
        self::assertSame(200, $status);
        self::assertStringContainsString('Inga aktier hittades.', $body);
        self::assertSame([], $this->rowIsins($body));
    }

    public function testRootModeSwitchesCarryPeriodAndMarket(): void
    {
        $this->seedMatchedUniverse();
        $this->seedSecondPerMarket();
        $this->seedOwnerCount('SE0000001001', '2026-10-01', 500);
        $this->seedOwnerCount('SE0000002001', '2026-10-01', 900);
        $this->seedOwnerCount('SE0000001002', '2026-10-01', 9999);

        [$status, $plusBody] = $this->endpoint->get('/?ranking=plus&period=vecka&market=LC', $this->validCookie());
        self::assertSame(200, $status);
        self::assertStringContainsString('class="tab" href="/?ranking=count&amp;period=vecka&amp;market=LC">Flest ägare</a>', $plusBody);

        // Follow it: Flest ägare ranks LC by total owners.
        [$status, $countBody] = $this->endpoint->get('/?ranking=count&period=vecka&market=LC', $this->validCookie());
        self::assertSame(200, $status);
        self::assertSame(['SE0000002001', 'SE0000001001'], $this->rowIsins($countBody));
        self::assertStringContainsString('class="tab tab--active" href="/?ranking=count&amp;period=vecka&amp;market=LC">Flest ägare</a>', $countBody);

        // And back to a period mode: Stadig tillväxt keeps both.
        self::assertStringContainsString('class="tab" href="/?ranking=steady&amp;period=vecka&amp;market=LC">Stadig tillväxt</a>', $countBody);
        self::assertStringContainsString('class="tab" href="/?ranking=plus&amp;period=vecka&amp;market=LC">Plusdagar</a>', $countBody);
        // The market row keeps the period; the spike toggle keeps the market.
        self::assertStringContainsString('class="tab" href="/?ranking=count&amp;period=vecka&amp;market=MC">MC</a>', $this->marketRowHtmlFor($countBody));
        self::assertStringContainsString('href="/?ranking=plus&amp;period=vecka&amp;spikes=exclude&amp;market=LC"', $plusBody);
    }

    public function testRootFlestAgarePeriodRowIsGreyedOutAndInert(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-10-01', 400);

        [$status, $body] = $this->endpoint->get('/?ranking=count', $this->validCookie());

        self::assertSame(200, $status);
        $periodRow = $this->periodRowHtmlFor($body);
        self::assertStringStartsWith('class="period-row period-row--muted"', $periodRow);
        self::assertStringNotContainsString('<a ', $periodRow, 'segments are not links');
        self::assertStringContainsString('<span class="tab tab--active" aria-current="true">Månad</span>', $periodRow);
        self::assertStringContainsString('<span class="tab">Vecka</span>', $periodRow);
        self::assertStringContainsString('<span class="period-note">Gäller inte Flest ägare</span>', $periodRow);

        // The remembered period stays marked.
        [, $body] = $this->endpoint->get('/?ranking=count&period=ar', $this->validCookie());
        self::assertStringContainsString('<span class="tab tab--active" aria-current="true">År</span>', $this->periodRowHtmlFor($body));
    }

    public function testRootHeaderHasTheSameThreeRowsInEveryMode(): void
    {
        $this->seedMatchedUniverse();
        $this->seedPlusMonth('SE0000001001', 1000, 1);

        foreach (['/?ranking=count', '/?ranking=steady', '/?ranking=plus'] as $path) {
            [$status, $body] = $this->endpoint->get($path, $this->validCookie());
            self::assertSame(200, $status, $path);
            $header = substr($body, (int) strpos($body, '<header'), (int) strpos($body, '</header>') - (int) strpos($body, '<header'));
            $controls = strpos($header, '<div class="controls">');
            $period = strpos($header, '<div class="period-row');
            $market = strpos($header, '<div class="market-row"');
            self::assertNotFalse($controls, $path);
            self::assertNotFalse($period, $path);
            self::assertNotFalse($market, $path);
            self::assertLessThan($period, $controls, $path);
            self::assertLessThan($market, $period, $path);
            foreach ([[$controls, $period], [$period, $market]] as [$from, $to]) {
                $between = substr($header, $from, $to - $from);
                self::assertSame(substr_count($between, '<div'), substr_count($between, '</div>'), $path . ': rows are siblings');
            }
        }
    }

    public function testRootSourceSwitchKeepsMarketAndPeriod(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-10-01', 400);

        foreach ([
            '/?ranking=count&period=vecka&market=SC' => ['/?source=alla&amp;ranking=count&amp;period=vecka&amp;market=SC', '/?source=nordnet&amp;ranking=count&amp;period=vecka&amp;market=SC'],
            '/?ranking=steady&period=3man&market=MC' => ['/?source=alla&amp;ranking=steady&amp;period=3man&amp;market=MC', '/?source=nordnet&amp;ranking=steady&amp;period=3man&amp;market=MC'],
            '/?source=nordnet&ranking=plus&market=LC' => ['/?source=alla&amp;ranking=plus&amp;market=LC', '/?ranking=plus&amp;market=LC'],
        ] as $path => $hrefs) {
            [$status, $body] = $this->endpoint->get($path, $this->validCookie());
            self::assertSame(200, $status, $path);
            $switcher = $this->sourceSwitcherHtmlFor($body);
            foreach ($hrefs as $href) {
                self::assertStringContainsString('href="' . $href . '"', $switcher, $path);
            }
        }
    }

    public function testRootFullListLinkCarriesMarket(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001004', '2026-10-01', 400);

        [$status, $body] = $this->endpoint->get('/?market=First%20North', $this->validCookie());
        self::assertSame(200, $status);
        self::assertStringContainsString('<a href="/list?market=First+North">Visa fullständig lista</a>', $body);

        [, $body] = $this->endpoint->get('/?source=nordnet&market=MC', $this->validCookie());
        self::assertStringContainsString('<a href="/list?source=nordnet&amp;market=MC">Visa fullständig lista</a>', $body);

        [, $body] = $this->endpoint->get('/?source=alla&market=LC', $this->validCookie());
        self::assertStringContainsString('<a href="/list?market=LC">Visa fullständig lista</a>', $body);
    }

    // -- spec-plusdagar-landing-cookie: Plusdagar landing + topplista_view ----

    /**
     * The labels of every active segment on the page, in document order:
     * tab bar, source, ranking, period, market.
     *
     * @return list<string>
     */
    private function activeLabels(string $body): array
    {
        preg_match_all('#class="tab tab--active"[^>]*>([^<]+)<#', $body, $m);

        return $m[1];
    }

    /** The raw `Set-Cookie: topplista_view=…` header line, or null when none was sent. */
    private function viewSetCookie(array $headers): ?string
    {
        foreach ($headers as $header) {
            if (stripos($header, 'Set-Cookie: topplista_view=') === 0) {
                return $header;
            }
        }

        return null;
    }

    /** The decoded value of the response's topplista_view Set-Cookie. */
    private function viewSetCookieValue(array $headers): string
    {
        $header = $this->viewSetCookie($headers);
        self::assertNotNull($header, 'expected a topplista_view Set-Cookie');
        preg_match('#^Set-Cookie: topplista_view=([^;]*)#i', $header, $m);

        return urldecode($m[1]);
    }

    private function viewCookie(string $query): string
    {
        return $this->validCookie() . '; topplista_view=' . rawurlencode($query);
    }

    public function testRootFreshVisitLandsOnPlusdagarManadAllaAvanzaWithoutSettingTheViewCookie(): void
    {
        $this->seedMatchedUniverse();
        $this->seedPlusMonth('SE0000001001', 1000, 1);

        [$status, $body, $headers] = $this->endpoint->getWithHeaders('/', $this->validCookie());

        self::assertSame(200, $status, $body);
        self::assertSame(['Topplista', 'Avanza', 'Plusdagar', 'Månad', 'Alla'], $this->activeLabels($body));
        self::assertStringContainsString('22/22 · +22', strip_tags($this->rowHtmlFor($body, 'SE0000001001')));
        self::assertNull($this->viewSetCookie($headers), 'a bare / never writes the cookie');
    }

    public function testRootExplicitViewRendersItAndWritesTheViewCookie(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-10-01', 400);

        [$status, $body, $headers] = $this->endpoint->getWithHeaders('/?ranking=count&market=LC', $this->validCookie());

        self::assertSame(200, $status, $body);
        self::assertSame(['Topplista', 'Avanza', 'Flest ägare', 'Månad', 'LC'], $this->activeLabels($body));
        self::assertSame('ranking=count&market=LC', $this->viewSetCookieValue($headers));

        $header = (string) $this->viewSetCookie($headers);
        self::assertStringContainsStringIgnoringCase('; HttpOnly', $header);
        self::assertStringContainsStringIgnoringCase('; SameSite=Lax', $header);
        self::assertStringContainsStringIgnoringCase('; path=/', $header);
        self::assertStringNotContainsStringIgnoringCase('; secure', $header, 'plain HTTP: no Secure flag');
        self::assertSame(1, preg_match('#Max-Age=(\d+)#i', $header, $m), $header);
        self::assertEqualsWithDelta(365 * 24 * 60 * 60, (int) $m[1], 60);
    }

    public function testRootBareRequestRendersTheRememberedView(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001003', '2026-10-01', 400, NormalizedRow::SOURCE_NORDNET);

        [$status, $body, $headers] = $this->endpoint->getWithHeaders(
            '/',
            $this->viewCookie('ranking=steady&period=vecka&market=SC&source=nordnet'),
        );

        self::assertSame(200, $status, $body);
        self::assertSame(['Topplista', 'Nordnet', 'Stadig tillväxt', 'Vecka', 'SC'], $this->activeLabels($body));
        self::assertNull($this->viewSetCookie($headers), 'rendering the remembered view does not rewrite it');
    }

    public function testRootQueryStringWinsAndMissingParamsTakeDefaultsNotCookieValues(): void
    {
        $this->seedMatchedUniverse();

        [$status, $body, $headers] = $this->endpoint->getWithHeaders(
            '/?ranking=steady',
            $this->viewCookie('ranking=plus&period=vecka&market=SC&source=nordnet'),
        );

        self::assertSame(200, $status, $body);
        self::assertSame(['Topplista', 'Avanza', 'Stadig tillväxt', 'Månad', 'Alla'], $this->activeLabels($body));
        self::assertSame('ranking=steady', $this->viewSetCookieValue($headers), 'the cookie is overwritten');
    }

    public function testRootClickingAllaFromAMarketViewReturnsToAllaAndUpdatesTheCookie(): void
    {
        $this->seedMatchedUniverse();

        [, $lcBody] = $this->endpoint->get('/?ranking=plus&period=vecka&market=LC', $this->validCookie());
        self::assertStringContainsString('class="tab" href="/?ranking=plus&amp;period=vecka">Alla</a>', $this->marketRowHtmlFor($lcBody));

        [, $defaultBody] = $this->endpoint->get('/?ranking=plus', $this->validCookie());
        self::assertStringContainsString('class="tab" href="/?ranking=plus&amp;market=LC">LC</a>', $this->marketRowHtmlFor($defaultBody));
        self::assertStringContainsString('class="tab tab--active" href="/?ranking=plus" aria-current="true">Alla</a>', $this->marketRowHtmlFor($defaultBody));

        // Following the Alla link from an LC view, even with LC remembered.
        [$status, $body, $headers] = $this->endpoint->getWithHeaders('/?ranking=plus', $this->viewCookie('ranking=plus&market=LC'));
        self::assertSame(200, $status);
        self::assertSame(['Topplista', 'Avanza', 'Plusdagar', 'Månad', 'Alla'], $this->activeLabels($body));
        self::assertSame('ranking=plus', $this->viewSetCookieValue($headers));
    }

    public function testRootEveryHeaderLinkCarriesRanking(): void
    {
        $this->seedMatchedUniverse();
        $this->seedPlusMonth('SE0000001001', 1000, 1);

        foreach (['/', '/?ranking=count', '/?ranking=steady&market=LC', '/?source=alla&ranking=plus&spikes=exclude'] as $path) {
            [$status, $body] = $this->endpoint->get($path, $this->validCookie());
            self::assertSame(200, $status, $path);
            self::assertSame(1, preg_match('~<header class="page-header">(.*?)</header>~s', $body, $m), $path);
            preg_match_all('~<a [^>]*href="([^"]*)"~', $m[1], $links);
            self::assertNotEmpty($links[1], $path);
            foreach ($links[1] as $href) {
                self::assertStringContainsString('ranking=', $href, $path);
            }
            self::assertSame(1, preg_match('~href="([^"]*)">Flest ägare</a>~', $body, $count), $path);
            self::assertStringContainsString('ranking=count', $count[1], $path);
        }
    }

    public function testRootGarbageViewCookieFallsBackToTheDefaults(): void
    {
        $this->seedMatchedUniverse();

        foreach (['%%%&ranking=xx&market=ZZ', 'ranking[]=count&market[]=LC&source[x]=nordnet', 'period=999&source=Alla'] as $raw) {
            $cookie = $this->validCookie() . '; topplista_view=' . $raw;
            [$status, $body, $headers] = $this->endpoint->getWithHeaders('/', $cookie);
            self::assertSame(200, $status, $raw);
            self::assertSame(['Topplista', 'Avanza', 'Plusdagar', 'Månad', 'Alla'], $this->activeLabels($body), $raw);
            self::assertNull($this->viewSetCookie($headers), $raw);
        }
    }

    public function testRootSpikesAreDroppedOutsidePlusdagar(): void
    {
        $this->seedMatchedUniverse();

        [$status, $body] = $this->endpoint->get('/', $this->viewCookie('ranking=count&spikes=exclude'));
        self::assertSame(200, $status);
        self::assertSame(['Topplista', 'Avanza', 'Flest ägare', 'Månad', 'Alla'], $this->activeLabels($body));
        self::assertStringNotContainsString('spikes=', $body);

        [, , $headers] = $this->endpoint->getWithHeaders('/?ranking=count&spikes=exclude', $this->validCookie());
        self::assertSame('ranking=count', $this->viewSetCookieValue($headers));

        [, , $headers] = $this->endpoint->getWithHeaders('/?ranking=plus&spikes=exclude', $this->validCookie());
        self::assertSame('ranking=plus&spikes=exclude', $this->viewSetCookieValue($headers));
    }

    public function testOtherRoutesNeverSetTheViewCookie(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-10-01', 400);
        $cookie = $this->viewCookie('ranking=steady&market=LC');

        foreach ([
            '/list', '/list?ranking=count&market=LC',
            '/stock/SE0000001001', '/stock/SE0000001001?range=vecka',
            '/watchlist', '/watchlist?source=nordnet',
            '/info', '/info?source=nordnet',
        ] as $path) {
            [$status, , $headers] = $this->endpoint->getWithHeaders($path, $cookie);
            self::assertSame(200, $status, $path);
            self::assertNull($this->viewSetCookie($headers), $path);
        }
    }

    public function testRootWithoutASessionNeverTouchesTheViewCookie(): void
    {
        [$status, $body, $headers] = $this->endpoint->getWithHeaders('/?ranking=count&market=LC', 'topplista_view=ranking%3Dsteady');

        self::assertSame(200, $status);
        self::assertStringContainsString('<form', $body);
        self::assertNull($this->viewSetCookie($headers));
    }

    public function testTopplistaTabFromAktiedetaljReturnsToTheRememberedView(): void
    {
        $this->seedMatchedUniverse();
        $this->seedPlusMonth('SE0000001001', 1000, 1);

        [, , $headers] = $this->endpoint->getWithHeaders('/?ranking=plus&period=vecka&market=LC', $this->validCookie());
        $remembered = $this->viewSetCookieValue($headers);
        self::assertSame('ranking=plus&period=vecka&market=LC', $remembered);

        [$status, $detail] = $this->endpoint->get('/stock/SE0000001001', $this->viewCookie($remembered));
        self::assertSame(200, $status);
        self::assertStringContainsString('class="tab tab--active" href="/">Topplista</a>', $detail);

        [$status, $body] = $this->endpoint->get('/', $this->viewCookie($remembered));
        self::assertSame(200, $status);
        self::assertSame(['Topplista', 'Avanza', 'Plusdagar', 'Vecka', 'LC'], $this->activeLabels($body));
        self::assertStringContainsString('Alpha AB', $body);
    }

    public function testRootJunkQueryRendersTheRememberedViewWithoutWritingTheCookie(): void
    {
        $this->seedMatchedUniverse();

        foreach (['/?fbclid=abc', '/?utm_source=x&utm_medium=y'] as $path) {
            [$status, $body, $headers] = $this->endpoint->getWithHeaders($path, $this->viewCookie('ranking=steady&market=SC'));
            self::assertSame(200, $status, $path);
            self::assertSame(['Topplista', 'Avanza', 'Stadig tillväxt', 'Månad', 'SC'], $this->activeLabels($body), $path);
            self::assertNull($this->viewSetCookie($headers), $path);
        }
    }

    public function testRootSourceOnlyRestoresTheRememberedViewWithThatSource(): void
    {
        $this->seedMatchedUniverse();

        [$status, $body, $headers] = $this->endpoint->getWithHeaders(
            '/?source=nordnet',
            $this->viewCookie('ranking=steady&period=vecka&market=LC'),
        );

        self::assertSame(200, $status, $body);
        self::assertSame(['Topplista', 'Nordnet', 'Stadig tillväxt', 'Vecka', 'LC'], $this->activeLabels($body));
        self::assertSame('source=nordnet&ranking=steady&period=vecka&market=LC', $this->viewSetCookieValue($headers));
    }

    public function testRootSourceOnlyWithoutACookieUsesTheDefaultsAndWritesTheCookie(): void
    {
        $this->seedMatchedUniverse();

        [$status, $body, $headers] = $this->endpoint->getWithHeaders('/?source=alla', $this->validCookie());

        self::assertSame(200, $status, $body);
        self::assertSame(['Topplista', 'Alla', 'Plusdagar', 'Månad', 'Alla'], $this->activeLabels($body));
        self::assertSame('source=alla&ranking=plus', $this->viewSetCookieValue($headers));
    }

    public function testTopplistasOwnTabInAllaRestoresTheSameView(): void
    {
        $this->seedMatchedUniverse();

        [, $body, $headers] = $this->endpoint->getWithHeaders('/?source=alla&ranking=steady&market=SC', $this->validCookie());
        $remembered = $this->viewSetCookieValue($headers);
        self::assertSame('source=alla&ranking=steady&market=SC', $remembered);
        self::assertSame(1, preg_match('~href="([^"]*)">Topplista</a>~', $body, $tab));
        $tabHref = html_entity_decode($tab[1]);
        self::assertSame('/?source=alla', $tabHref);

        [$status, $body, $headers] = $this->endpoint->getWithHeaders($tabHref, $this->viewCookie($remembered));
        self::assertSame(200, $status);
        self::assertSame(['Topplista', 'Alla', 'Stadig tillväxt', 'Månad', 'SC'], $this->activeLabels($body));
        self::assertSame($remembered, $this->viewSetCookieValue($headers));
    }

    public function testTopplistaTabFromOtherPagesInNordnetReturnsToTheRememberedView(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-10-01', 400, NormalizedRow::SOURCE_NORDNET);
        $remembered = 'source=nordnet&ranking=steady&period=vecka&market=LC';

        foreach (['/list?source=nordnet', '/watchlist?source=nordnet', '/stock/SE0000001001?source=nordnet', '/info?source=nordnet'] as $path) {
            [$status, $page] = $this->endpoint->get($path, $this->viewCookie($remembered));
            self::assertSame(200, $status, $path);
            self::assertSame(1, preg_match('~href="([^"]*)">Topplista</a>~', $page, $tab), $path);
            $tabHref = html_entity_decode($tab[1]);

            [$status, $body] = $this->endpoint->get($tabHref, $this->viewCookie($remembered));
            self::assertSame(200, $status, $path);
            self::assertSame(['Topplista', 'Nordnet', 'Stadig tillväxt', 'Vecka', 'LC'], $this->activeLabels($body), $path . ' → ' . $tabHref);
        }
    }

    // -- / period percentages (Vecka/Månad/3 mån/År line) --------------------

    public function testRootShowsAllFourPeriodPercentagesPopulatedWithMonToFriHistorySpanningAYear(): void
    {
        $this->seedMatchedUniverse();

        // Mon–Fri only (collection skips weekends) for well over a year:
        // every calendar period (7/30/90/365 days back) lands on a stored
        // row at most a few days before its offset.
        $count = $this->seedWeekdays('SE0000001001', '2025-06-02', 300, 1000, 5);
        self::assertSame(300, $count);

        [$status, $body] = $this->endpoint->get('/?ranking=count', $this->validCookie());

        self::assertSame(200, $status, $body);
        $rowHtml = $this->rowHtmlFor($body, 'SE0000001001');

        self::assertStringContainsString('class="period-pcts"', $rowHtml);
        self::assertStringContainsString('>Vecka<', $rowHtml);
        self::assertStringContainsString('>Månad<', $rowHtml);
        self::assertStringContainsString('>3 mån<', $rowHtml);
        self::assertStringContainsString('>År<', $rowHtml);
        self::assertSame(0, substr_count($rowHtml, 'period-pct--nohist'), 'all four periods must be populated with a year of Mon–Fri history');
        self::assertSame(4, substr_count($rowHtml, 'period-pct--positive'));
    }

    public function testRootShowsInsufficientHistoryMarkForPeriodsWithoutEnoughData(): void
    {
        $this->seedMatchedUniverse();
        // A single stored day -> none of the four periods has a
        // calendar-days-back comparison row yet.
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000);

        [$status, $body] = $this->endpoint->get('/?ranking=count', $this->validCookie());

        self::assertSame(200, $status);
        $rowHtml = $this->rowHtmlFor($body, 'SE0000001001');

        self::assertSame(4, substr_count($rowHtml, 'period-pct--nohist'), 'all four periods must show the insufficient-history mark');
        self::assertStringContainsString('title="Ingen jämförbar dag"', $rowHtml);
        self::assertStringContainsString('–', $rowHtml);
    }

    public function testRootShowsTheMondayDeltaChipAgainstFriday(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-09-25', 1000); // Fri
        $this->seedOwnerCount('SE0000001001', '2026-09-28', 1010); // Mon

        [$status, $body] = $this->endpoint->get('/?ranking=count', $this->validCookie());

        self::assertSame(200, $status);
        $rowHtml = $this->rowHtmlFor($body, 'SE0000001001');
        self::assertStringContainsString('+10 · 1,0 %', $rowHtml);
    }

    public function testRootWithSourceAllaShowsAvanzaDerivedPeriodPercentagesNeverNordnets(): void
    {
        $this->seedMatchedUniverse();

        // Avanza: 1000 -> 1070 over 7 days (+7.0%).
        foreach ([1000, 1010, 1020, 1030, 1040, 1050, 1060, 1070] as $i => $v) {
            $this->seedOwnerCount('SE0000001001', sprintf('2026-05-%02d', $i + 1), $v, NormalizedRow::SOURCE_AVANZA);
        }
        // Nordnet: 500 -> 430 over the same 7 days (-14.0%) -- must never
        // leak into the rendered period percentages, even though Alla mode
        // shows Nordnet's owner count alongside Avanza's.
        foreach ([500, 490, 480, 470, 460, 450, 440, 430] as $i => $v) {
            $this->seedOwnerCount('SE0000001001', sprintf('2026-05-%02d', $i + 1), $v, NormalizedRow::SOURCE_NORDNET);
        }

        [$status, $body] = $this->endpoint->get('/?source=alla&ranking=count', $this->validCookie());

        self::assertSame(200, $status);
        $rowHtml = $this->rowHtmlFor($body, 'SE0000001001');

        self::assertStringContainsString('+7,0 %', $rowHtml, 'the period percentage must be Avanza\'s (ranking basis), not Nordnet\'s');
        self::assertStringNotContainsString('-14,0 %', $rowHtml, 'Nordnet\'s own delta must never appear as a period percentage');
    }

    public function testListAndWatchlistAreUnaffectedBySourceAllaFallingBackToTheirOwnAvanzaDefault(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000, NormalizedRow::SOURCE_AVANZA);
        $cookie = $this->validCookie();
        $this->endpoint->postJson('/watchlist/toggle', ['isin' => 'SE0000001001'], $cookie);

        [$listStatus, $listBody] = $this->endpoint->get('/list?source=alla', $cookie);
        [$watchlistStatus, $watchlistBody] = $this->endpoint->get('/watchlist?source=alla', $cookie);

        self::assertSame(200, $listStatus);
        self::assertSame(200, $watchlistStatus);
        // Neither page's own Source switcher has an "Alla" tab (Alla is
        // scoped to Topplista only, spec-5-4's Boundaries) -- both silently
        // fall back to their own existing Avanza-default behavior for the
        // unrecognized value, the same convention already used for any
        // other garbage source string. (/list also has its own unrelated
        // "Alla" market-filter label elsewhere on the page, so the check is
        // scoped to the Source switcher markup specifically.)
        self::assertStringNotContainsString('>Alla<', $this->sourceSwitcherHtmlFor($listBody));
        self::assertStringNotContainsString('>Alla<', $this->sourceSwitcherHtmlFor($watchlistBody));
        self::assertStringContainsString('class="tab tab--active" href="/list">Avanza</a>', $listBody);
        self::assertStringContainsString('class="tab tab--active" href="/watchlist">Avanza</a>', $watchlistBody);
        self::assertStringContainsString('1 000', $listBody);
        self::assertStringContainsString('1 000', $watchlistBody);
    }

    public function testWatchlistToggleStarsAnInstrumentAndTheNewStateSurvivesAReload(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000);
        $cookie = $this->validCookie();

        [$status, $body] = $this->endpoint->postJson('/watchlist/toggle', ['isin' => 'SE0000001001'], $cookie);
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $status);
        self::assertSame('SE0000001001', $json['isin']);
        self::assertTrue($json['starred']);
        self::assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM watchlist WHERE isin = 'SE0000001001'")->fetchColumn(),
        );

        // Reload / — the star must render filled (persisted, not per-request).
        [$rootStatus, $rootBody] = $this->endpoint->get('/?ranking=count', $cookie);
        self::assertSame(200, $rootStatus);
        self::assertStringContainsString('star--filled', $rootBody);

        // Toggling again flips it back off.
        [$offStatus, $offBody] = $this->endpoint->postJson('/watchlist/toggle', ['isin' => 'SE0000001001'], $cookie);
        $offJson = json_decode($offBody, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(200, $offStatus);
        self::assertFalse($offJson['starred']);
        self::assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM watchlist')->fetchColumn(),
        );
    }

    public function testWatchlistToggleWithUnknownIsinReturns404Json(): void
    {
        [$status, $body] = $this->endpoint->postJson('/watchlist/toggle', ['isin' => 'SE9999999999'], $this->validCookie());

        self::assertSame(404, $status);
        self::assertSame(['error' => 'not found'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM watchlist')->fetchColumn());
    }

    // -- Story 4.4: /list (Fullständig lista) ----------------------------------

    public function testListDefaultViewShowsEveryActiveInstrumentForAvanzaOrderedByOwnerCountDesc(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000);
        $this->seedOwnerCount('SE0000001002', '2026-01-01', 5000);
        $this->seedOwnerCount('SE0000001003', '2026-01-01', 200);

        [$status, $body] = $this->endpoint->get('/list', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('Fullständig lista', $body);
        self::assertStringContainsString('Alpha AB', $body);
        self::assertStringContainsString('Beta AB', $body);
        self::assertStringContainsString('Gamma AB', $body);
        // spec-5-1: FullListController::renderRow()'s real output nests name/badges
        // and owners/delta-chip in the fixed mobile-layout wrapper columns.
        self::assertStringContainsString('class="namecol"', $body);
        self::assertStringContainsString('class="statcol"', $body);
        // Beta AB (5000) > Alpha AB (1000) > Gamma AB (200).
        self::assertGreaterThan(strpos($body, 'Beta AB'), strpos($body, 'Alpha AB'));
        self::assertGreaterThan(strpos($body, 'Alpha AB'), strpos($body, 'Gamma AB'));
    }

    public function testListSearchFiltersToNameMatchingRowsRegardlessOfOtherFilters(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000); // Alpha AB
        $this->seedOwnerCount('SE0000001002', '2026-01-01', 5000); // Beta AB

        [$status, $body] = $this->endpoint->get('/list?q=alpha', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('Alpha AB', $body);
        self::assertStringNotContainsString('Beta AB', $body);
    }

    public function testListSortPctOrdersRowsByPct1dDesc(): void
    {
        $this->seedMatchedUniverse();
        // Alpha AB: 1000 -> 1100 (+10%).
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000);
        $this->seedOwnerCount('SE0000001001', '2026-01-02', 1100);
        // Beta AB: 1000 -> 2000 (+100%).
        $this->seedOwnerCount('SE0000001002', '2026-01-01', 1000);
        $this->seedOwnerCount('SE0000001002', '2026-01-02', 2000);

        [$status, $body] = $this->endpoint->get('/list?sort=pct', $this->validCookie());

        self::assertSame(200, $status);
        // Beta AB (+100%) must render before Alpha AB (+10%) — descending by pct_1d.
        self::assertGreaterThan(strpos($body, 'Beta AB'), strpos($body, 'Alpha AB'));
    }

    public function testListGrowthFilterOnlyShowsSteadyGrowthQualifiers(): void
    {
        $this->seedMatchedUniverse();
        // Alpha AB: 29 days steady growth then a huge jump -> spiking, excluded.
        $start = new DateTimeImmutable('2026-03-01');
        for ($i = 0; $i < 29; ++$i) {
            $this->seedOwnerCount('SE0000001001', $start->modify("+{$i} days")->format('Y-m-d'), 1000 + $i * 10);
        }
        $this->seedOwnerCount('SE0000001001', $start->modify('+29 days')->format('Y-m-d'), 1280 + 5000);
        // Beta AB: a clean up-streak, no spike.
        foreach ([2000, 2010, 2020, 2030] as $i => $v) {
            $this->seedOwnerCount('SE0000001002', sprintf('2026-04-%02d', $i + 1), $v);
        }

        [$status, $body] = $this->endpoint->get('/list?growth=1', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('Beta AB', $body);
        self::assertStringNotContainsString('Alpha AB', $body);
    }

    public function testListSpikeFilterOnlyShowsSpikingRows(): void
    {
        $this->seedMatchedUniverse();
        $start = new DateTimeImmutable('2026-03-01');
        for ($i = 0; $i < 29; ++$i) {
            $this->seedOwnerCount('SE0000001001', $start->modify("+{$i} days")->format('Y-m-d'), 1000 + $i * 10);
        }
        $this->seedOwnerCount('SE0000001001', $start->modify('+29 days')->format('Y-m-d'), 1280 + 5000);
        foreach ([2000, 2010, 2020, 2030] as $i => $v) {
            $this->seedOwnerCount('SE0000001002', sprintf('2026-04-%02d', $i + 1), $v);
        }

        [$status, $body] = $this->endpoint->get('/list?spike=1', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('Alpha AB', $body);
        self::assertStringNotContainsString('Beta AB', $body);
    }

    public function testListWatchlistFilterOnlyShowsStarredIsins(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000); // Alpha AB
        $this->seedOwnerCount('SE0000001002', '2026-01-01', 5000); // Beta AB
        $cookie = $this->validCookie();
        $this->endpoint->postJson('/watchlist/toggle', ['isin' => 'SE0000001002'], $cookie);

        [$status, $body] = $this->endpoint->get('/list?watchlist=1', $cookie);

        self::assertSame(200, $status);
        self::assertStringContainsString('Beta AB', $body);
        self::assertStringNotContainsString('Alpha AB', $body);
    }

    public function testListMarketFilterOnlyShowsMatchingList(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000); // Alpha AB, LC
        $this->seedOwnerCount('SE0000001004', '2026-01-01', 500); // Delta AB, First North

        [$status, $body] = $this->endpoint->get('/list?market=' . urlencode('First North'), $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('Delta AB', $body);
        self::assertStringNotContainsString('Alpha AB', $body);
    }

    public function testListCarriesForwardAllActiveParamsOnOtherControlsAndTheActiveToggleOmitsItself(): void
    {
        $this->seedMatchedUniverse();

        [$status, $body] = $this->endpoint->get('/list?q=alpha&sort=pct&growth=1', $this->validCookie());

        self::assertSame(200, $status);
        // A different control's own link (the Nordnet source-switcher) must
        // carry every other currently-active param forward: q, sort, growth.
        // (Hrefs are HTML-escaped, so '&' renders as '&amp;'.)
        self::assertStringContainsString('href="/list?source=nordnet&amp;q=alpha&amp;sort=pct&amp;growth=1"', $body);
        // The active growth toggle's own link flips itself off (omits
        // growth) while still carrying q/sort forward.
        self::assertStringContainsString('href="/list?q=alpha&amp;sort=pct"', $body);
    }

    public function testListGrowthAndSpikeTogetherShowsTheZeroResultsEmptyStateEndToEnd(): void
    {
        $this->seedMatchedUniverse();
        // Steady 29-day growth then a huge jump -> spiking, which is exactly
        // the case Acceptance Criteria calls out as "contradictory in
        // practice": growth requires "not spiking", spike requires
        // "spiking", so the AND-combined result must be empty.
        $start = new DateTimeImmutable('2026-03-01');
        for ($i = 0; $i < 29; ++$i) {
            $this->seedOwnerCount('SE0000001001', $start->modify("+{$i} days")->format('Y-m-d'), 1000 + $i * 10);
        }
        $this->seedOwnerCount('SE0000001001', $start->modify('+29 days')->format('Y-m-d'), 1280 + 5000);

        [$status, $body] = $this->endpoint->get('/list?growth=1&spike=1', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('Inga resultat för dessa filter.', $body);
    }

    public function testListSearchTermRendersSafelyInTheSearchBoxValueAttribute(): void
    {
        $this->seedMatchedUniverse();

        $maliciousQ = '<script>alert(1)</script>';
        [$status, $body] = $this->endpoint->get('/list?' . http_build_query(['q' => $maliciousQ]), $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringNotContainsString($maliciousQ, $body);
        self::assertStringContainsString('value="&lt;script&gt;alert(1)&lt;/script&gt;"', $body);
    }

    public function testListZeroMatchesWithNordnetSourceActiveStillShowsABareRensaFilterLink(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000, NormalizedRow::SOURCE_NORDNET);

        [$status, $body] = $this->endpoint->get('/list?source=nordnet&q=nosuchcompany', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('Inga resultat för dessa filter.', $body);
        self::assertStringContainsString('href="/list"', $body);
        self::assertStringNotContainsString('href="/list?source=nordnet"', $body, 'the rensa filter link must drop source too, not just filters');
    }

    public function testListCombinedFiltersNarrowTheResultSetTogether(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000); // Alpha AB, LC
        $this->seedOwnerCount('SE0000001002', '2026-01-01', 5000); // Beta AB, MC

        [$status, $body] = $this->endpoint->get('/list?q=alpha&sort=pct&market=LC', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('Alpha AB', $body);
        self::assertStringNotContainsString('Beta AB', $body);
    }

    public function testListZeroMatchesShowsEmptyStateWithRensaFilterLink(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000);

        [$status, $body] = $this->endpoint->get('/list?q=nosuchcompany', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('Inga resultat för dessa filter.', $body);
        self::assertStringContainsString('href="/list"', $body);
    }

    public function testListSourceSwitchShowsNordnetsOwnInstrumentsAndPersistsSearchTerm(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 100, NormalizedRow::SOURCE_AVANZA);
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 999999, NormalizedRow::SOURCE_NORDNET);

        [$status, $body] = $this->endpoint->get('/list?source=nordnet&q=alpha', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('999 999', $body);
        // The search term persists in the rendered search box.
        self::assertStringContainsString('value="alpha"', $body);
    }

    public function testListSourceSwitchNeverMergesAvanzaAndNordnetData(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 100, NormalizedRow::SOURCE_AVANZA);
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 999999, NormalizedRow::SOURCE_NORDNET);

        [$status, $body] = $this->endpoint->get('/list?source=nordnet', $this->validCookie());

        self::assertSame(200, $status);
        self::assertSame(1, substr_count($body, 'class="row"'), 'only the one isin with Nordnet data may appear');
    }

    public function testListFooterLinkFromTopplistaPointsToListPreservingSource(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000, NormalizedRow::SOURCE_NORDNET);

        [$status, $body] = $this->endpoint->get('/?source=nordnet', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('href="/list?source=nordnet"', $body);
    }

    public function testListWithNoSessionShowsLoginForm(): void
    {
        $this->seedMatchedUniverse();

        [$status, $body] = $this->endpoint->get('/list');

        self::assertSame(200, $status);
        self::assertStringContainsString('<form', $body);
        self::assertStringContainsString('Logga in', $body);
    }

    // -- Story 4.3: /stock/{isin} (Aktiedetalj) --------------------------------

    public function testStockDetailDefaultViewShowsNameBothSourceLinesAndDagRange(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000, NormalizedRow::SOURCE_AVANZA);
        $this->seedOwnerCount('SE0000001001', '2026-01-02', 1010, NormalizedRow::SOURCE_AVANZA);
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 500, NormalizedRow::SOURCE_NORDNET);
        $this->seedOwnerCount('SE0000001001', '2026-01-02', 510, NormalizedRow::SOURCE_NORDNET);

        [$status, $body] = $this->endpoint->get('/stock/SE0000001001', $this->validCookie());

        self::assertSame(200, $status, $body);
        self::assertStringContainsString('Alpha AB', $body);
        self::assertStringContainsString('trend-line--secondary', $body, 'the other source always renders, dashed');
        self::assertStringContainsString('class="tab tab--active" href="/stock/SE0000001001">Dag</a>', $body);
        // spec-5-2: Alpha AB's seeded avanza_orderbook_id ('1001') must
        // surface as a link to its Avanza page.
        self::assertStringContainsString('href="https://www.avanza.se/aktier/om-aktien.html/1001"', $body);
    }

    public function testStockDetailOmitsTheAvanzaLinkWhenNoOrderbookIdIsCached(): void
    {
        $this->seedInstrument();
        $this->seedOwnerCount('SE0000000001', '2026-01-01', 1000);

        [$status, $body] = $this->endpoint->get('/stock/SE0000000001', $this->validCookie());

        self::assertSame(200, $status, $body);
        self::assertStringNotContainsString('class="avanza-link"', $body);
        self::assertStringNotContainsString('avanza.se', $body);
    }

    public function testStockDetailWithUnknownIsinReturns404(): void
    {
        $this->seedMatchedUniverse();

        [$status, $body] = $this->endpoint->get('/stock/SE9999999999', $this->validCookie());

        self::assertSame(404, $status);
        self::assertSame(['error' => 'not found'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testStockDetailWithNoSessionShowsLoginForm(): void
    {
        $this->seedMatchedUniverse();

        [$status, $body] = $this->endpoint->get('/stock/SE0000001001');

        self::assertSame(200, $status);
        self::assertStringContainsString('<form', $body);
        self::assertStringContainsString('Logga in', $body);
    }

    public function testStockDetailRangeVeckaRendersTheChartOnceMonToFriHistorySpansSevenDays(): void
    {
        $this->seedMatchedUniverse();
        // Mon 2026-02-02 .. Mon 2026-02-09: 6 trading rows spanning 7 days.
        $this->seedWeekdays('SE0000001001', '2026-02-02', 6, 1000, 6);

        [$status, $body] = $this->endpoint->get('/stock/SE0000001001?range=vecka', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('trend-overlay', $body);
        self::assertStringNotContainsString('Inte tillräckligt med historik', $body);

        // Y-axis (backlog 2026-09-15): max 1030 -> "1,03k", mid 1015 -> "1,02k", min 1000 -> "1k".
        self::assertSame(3, substr_count($body, 'class="y-axis-tick"'));
        self::assertStringContainsString('>1,03k<', $body);
        self::assertStringContainsString('>1,02k<', $body);
        self::assertStringContainsString('>1k<', $body);
    }

    public function testStockDetailRangeVeckaIsGatedWhenFiveTradingDaysSpanOnlyFourCalendarDays(): void
    {
        $this->seedMatchedUniverse();
        // Mon..Fri = 4 days spanned -> 3 more calendar days to go.
        $this->seedWeekdays('SE0000001001', '2026-02-02', 5, 1000, 6);

        [$status, $body] = $this->endpoint->get('/stock/SE0000001001?range=vecka', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString(
            'Inte tillräckligt med historik för det här intervallet ännu — kolla in igen om 3 dagar',
            $body,
        );
    }

    public function testStockDetailYAxisDropsTheMidTickWhenItsRoundedLabelCollidesWithAnExtreme(): void
    {
        $this->seedMatchedUniverse();
        // max 100999 -> "101k", mid 100499 -> "100k" (rounds down, same as
        // min) -> the mid tick must be dropped rather than stacking a second
        // "100k" at a different height on top of the real min tick.
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 100000);
        $this->seedOwnerCount('SE0000001001', '2026-01-02', 100999);

        [$status, $body] = $this->endpoint->get('/stock/SE0000001001', $this->validCookie());

        self::assertSame(200, $status, $body);
        self::assertSame(2, substr_count($body, 'class="y-axis-tick"'));
        self::assertStringContainsString('>101k<', $body);
        self::assertStringContainsString('>100k<', $body);
    }

    public function testStockDetailRangeManadShowsInsufficientHistoryInCalendarDays(): void
    {
        $this->seedMatchedUniverse();
        // Mon 2026-02-02 .. Fri 2026-02-13: 10 trading rows spanning 11 days.
        $this->seedWeekdays('SE0000001001', '2026-02-02', 10, 1000, 5);

        [$status, $body] = $this->endpoint->get('/stock/SE0000001001?range=manad', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString(
            'Inte tillräckligt med historik för det här intervallet ännu — kolla in igen om 19 dagar',
            $body,
        );
    }

    public function testStockDetailLegacyRange30dIsTreatedAsManad(): void
    {
        $this->seedMatchedUniverse();
        $this->seedWeekdays('SE0000001001', '2026-02-02', 10, 1000, 5);

        [$status, $body] = $this->endpoint->get('/stock/SE0000001001?range=30d', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('kolla in igen om 19 dagar', $body);
        self::assertMatchesRegularExpression('#<a class="tab tab--active" href="[^"]*range=manad">Månad</a>#', $body);
    }

    public function testStockDetailRangeManadRendersTheChartWithMonToFriHistorySpanningThirtyDays(): void
    {
        $this->seedMatchedUniverse();
        // Mon 2026-02-02 .. Wed 2026-03-04: 23 trading rows spanning 30 days.
        $this->seedWeekdays('SE0000001001', '2026-02-02', 23, 1000, 5);

        [$status, $body] = $this->endpoint->get('/stock/SE0000001001?range=manad', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('trend-overlay', $body);
        self::assertStringNotContainsString('Inte tillräckligt med historik', $body);
    }

    public function testStockDetailRangeArRendersOnceMonToFriHistorySpans3ManGate(): void
    {
        $this->seedMatchedUniverse();
        // Mon 2026-01-05 .. Mon 2026-04-06: 66 trading rows spanning 91 days.
        $this->seedWeekdays('SE0000001001', '2026-01-05', 66, 1000, 3);

        [$status, $body] = $this->endpoint->get('/stock/SE0000001001?range=ar', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('trend-overlay', $body);
        self::assertStringNotContainsString('Inte tillräckligt med historik', $body);
    }

    public function testStockDetailRange3ManRendersTheChartWithMonToFriHistorySpanningNinetyDays(): void
    {
        $this->seedMatchedUniverse();
        $this->seedWeekdays('SE0000001001', '2026-01-05', 66, 1000, 3);

        [$status, $body] = $this->endpoint->get('/stock/SE0000001001?range=3man', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('trend-overlay', $body);
        self::assertStringNotContainsString('Inte tillräckligt med historik', $body);
        self::assertMatchesRegularExpression('#<a class="tab tab--active" href="[^"]*range=3man">3 mån</a>#', $body);
    }

    public function testStockDetailLegacyRange90dIsTreatedAs3Man(): void
    {
        $this->seedMatchedUniverse();
        // Mon 2026-01-05 .. Thu 2026-04-02: 64 rows spanning 87 days -> 3 to go.
        $this->seedWeekdays('SE0000001001', '2026-01-05', 64, 1000, 3);

        [$status, $body] = $this->endpoint->get('/stock/SE0000001001?range=90d', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('kolla in igen om 3 dagar', $body);
        self::assertMatchesRegularExpression('#<a class="tab tab--active" href="[^"]*range=3man">3 mån</a>#', $body);
    }

    public function testStockDetailDefaultDagViewShowsInsufficientHistoryForABrandNewInstrument(): void
    {
        $this->seedMatchedUniverse();
        // Only one stored day -> fewer than Dag's 2-row gate.
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000);

        [$status, $body] = $this->endpoint->get('/stock/SE0000001001', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString(
            'Inte tillräckligt med historik för det här intervallet ännu — kolla in igen om 1 dag',
            $body,
        );
    }

public function testStockDetailWithSourceNordnetMakesNordnetThePrimaryLineAndAvanzaTheSecondary(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000, NormalizedRow::SOURCE_AVANZA);
        $this->seedOwnerCount('SE0000001001', '2026-01-02', 1010, NormalizedRow::SOURCE_AVANZA);
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 500, NormalizedRow::SOURCE_NORDNET);
        $this->seedOwnerCount('SE0000001001', '2026-01-02', 510, NormalizedRow::SOURCE_NORDNET);

        [$status, $body] = $this->endpoint->get('/stock/SE0000001001?source=nordnet', $this->validCookie());

        self::assertSame(200, $status);
        // The Source switcher's own generated href marks Nordnet active.
        self::assertStringContainsString('class="tab tab--active" href="/stock/SE0000001001?source=nordnet">Nordnet</a>', $body);
        // The legend names Avanza as the (always fixed/dashed) secondary line.
        self::assertStringContainsString('legend-swatch--secondary', $body);
        self::assertStringContainsString('Avanza</span>', $body);
    }

    public function testStockDetailWatchlistStarTogglesExactlyAsOnTheLeaderboard(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000);
        $cookie = $this->validCookie();

        [$toggleStatus, $toggleBody] = $this->endpoint->postJson('/watchlist/toggle', ['isin' => 'SE0000001001'], $cookie);
        $json = json_decode($toggleBody, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(200, $toggleStatus);
        self::assertTrue($json['starred']);

        [$status, $body] = $this->endpoint->get('/stock/SE0000001001', $cookie);

        self::assertSame(200, $status);
        self::assertStringContainsString('star--filled', $body);
    }

    // -- Story 4.5: /watchlist (Bevakningslista) and the shared tab bar -------

    public function testWatchlistShowsOnlyStarredInstrumentsForSelectedSource(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000); // Alpha AB
        $this->seedOwnerCount('SE0000001002', '2026-01-01', 5000); // Beta AB
        $cookie = $this->validCookie();
        $this->endpoint->postJson('/watchlist/toggle', ['isin' => 'SE0000001002'], $cookie);

        [$status, $body] = $this->endpoint->get('/watchlist', $cookie);

        self::assertSame(200, $status);
        self::assertStringContainsString('Bevakningslista', $body);
        self::assertStringContainsString('Beta AB', $body);
        self::assertStringNotContainsString('Alpha AB', $body);
        self::assertStringContainsString('star--filled', $body);
        // spec-5-1: WatchlistController::renderRow()'s real output nests name/badges
        // and owners/delta-chip in the fixed mobile-layout wrapper columns.
        self::assertStringContainsString('class="namecol"', $body);
        self::assertStringContainsString('class="statcol"', $body);
    }

    public function testWatchlistWithNoStarredInstrumentsShowsEmptyStateWithLinkToRoot(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000);

        [$status, $body] = $this->endpoint->get('/watchlist', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('Inga aktier bevakade än.', $body);
        self::assertStringContainsString('href="/"', $body);
    }

    public function testWatchlistStarToggleUnstarsTheInstrumentAndItIsGoneOnTheNextLoad(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000); // Alpha AB
        $cookie = $this->validCookie();
        $this->endpoint->postJson('/watchlist/toggle', ['isin' => 'SE0000001001'], $cookie);

        [$firstStatus, $firstBody] = $this->endpoint->get('/watchlist', $cookie);
        self::assertSame(200, $firstStatus);
        self::assertStringContainsString('Alpha AB', $firstBody);

        [$toggleStatus, $toggleBody] = $this->endpoint->postJson('/watchlist/toggle', ['isin' => 'SE0000001001'], $cookie);
        $json = json_decode($toggleBody, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(200, $toggleStatus);
        self::assertFalse($json['starred']);

        [$secondStatus, $secondBody] = $this->endpoint->get('/watchlist', $cookie);
        self::assertSame(200, $secondStatus);
        self::assertStringContainsString('Inga aktier bevakade än.', $secondBody);
    }

    public function testWatchlistSourceSwitchShowsNordnetDataForTheSameStarredIsinsNeverMergedWithAvanza(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 100, NormalizedRow::SOURCE_AVANZA);
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 999999, NormalizedRow::SOURCE_NORDNET);
        $cookie = $this->validCookie();
        $this->endpoint->postJson('/watchlist/toggle', ['isin' => 'SE0000001001'], $cookie);

        [$status, $body] = $this->endpoint->get('/watchlist?source=nordnet', $cookie);

        self::assertSame(200, $status);
        self::assertStringContainsString('999 999', $body);
        self::assertSame(1, substr_count($body, 'class="row"'), 'only the one starred isin may appear');
    }

    public function testWatchlistWithNoSessionShowsLoginForm(): void
    {
        [$status, $body] = $this->endpoint->get('/watchlist');

        self::assertSame(200, $status);
        self::assertStringContainsString('<form', $body);
        self::assertStringContainsString('Logga in', $body);
    }

    public function testTabBarAppearsOnAllFourAuthenticatedPagesWithTheCorrectTabMarkedActive(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000);
        $cookie = $this->validCookie();

        $topplistaActive = 'class="tab tab--active" href="/">Topplista</a>';
        $watchlistActive = 'class="tab tab--active" href="/watchlist">Bevakningslista</a>';
        $topplistaInactive = 'class="tab" href="/">Topplista</a>';
        $watchlistInactive = 'class="tab" href="/watchlist">Bevakningslista</a>';

        foreach (['/', '/list', '/stock/SE0000001001'] as $path) {
            [$status, $body] = $this->endpoint->get($path, $cookie);
            self::assertSame(200, $status, $path);
            self::assertStringContainsString($topplistaActive, $body, "{$path}: Topplista tab must be active");
            self::assertStringContainsString($watchlistInactive, $body, "{$path}: Bevakningslista tab must be present but inactive");
        }

        [$status, $body] = $this->endpoint->get('/watchlist', $cookie);
        self::assertSame(200, $status);
        self::assertStringContainsString($watchlistActive, $body, '/watchlist: Bevakningslista tab must be active');
        self::assertStringContainsString($topplistaInactive, $body, '/watchlist: Topplista tab must be present but inactive');
    }

    // -- spec-5-3: /info (Information) -----------------------------------------

    public function testInfoWithNoSessionShowsLoginForm(): void
    {
        [$status, $body] = $this->endpoint->get('/info');

        self::assertSame(200, $status);
        self::assertStringContainsString('<form', $body);
        self::assertStringContainsString('Logga in', $body);
    }

    public function testInfoWithValidSessionShowsAllFourPagesAllFourSymbolsAndTheStadigTillvaxtRule(): void
    {
        [$status, $body] = $this->endpoint->get('/info', $this->validCookie());

        self::assertSame(200, $status, $body);
        self::assertStringContainsString('Topplista', $body);
        self::assertStringContainsString('Fullständig lista', $body);
        self::assertStringContainsString('Bevakningslista', $body);
        self::assertStringContainsString('Aktiedetalj', $body);
        self::assertStringContainsString('🔥', $body);
        self::assertStringContainsString('⚡', $body);
        self::assertStringContainsString('☆', $body);
        self::assertStringContainsString('★', $body);
        self::assertStringContainsString('Delta-chip', $body);
        self::assertStringContainsString(
            'kvalificerar om aktien har minst 1 dags obruten uppgångssvit och inte just nu spikar.',
            $body,
        );
        self::assertStringContainsString(
            'sorteras de kvalificerade aktierna efter ägarantalets procentuella ökning under vald period',
            $body,
        );
    }

    public function testRootHasAVisibleFooterLinkToInfoDistinctFromTheFullListLink(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000);

        [$status, $body] = $this->endpoint->get('/', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('class="info-link"><a href="/info"', $body);
    }

    public function testInfoRouteRoundTripPreservesNordnetSourceFromTheTopplistaFooterLink(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000, NormalizedRow::SOURCE_NORDNET);

        [, $rootBody] = $this->endpoint->get('/?source=nordnet', $this->validCookie());
        self::assertStringContainsString('class="info-link"><a href="/info?source=nordnet"', $rootBody);

        [$status, $infoBody] = $this->endpoint->get('/info?source=nordnet', $this->validCookie());
        self::assertSame(200, $status);
        self::assertStringContainsString('class="tab tab--active" href="/?source=nordnet">Topplista</a>', $infoBody);
    }

    private function seedOwnerCount(
        string $isin,
        string $asOfDate,
        int $owners,
        string $source = NormalizedRow::SOURCE_AVANZA,
    ): void {
        $row = new NormalizedRow(
            $isin,
            $source,
            $owners,
            null,
            null,
            null,
            new DateTimeImmutable($asOfDate . 'T12:00:00', new DateTimeZone('UTC')),
        );
        (new OwnerCountRepository($this->pdo))->upsert($row, $asOfDate);
    }

    /**
     * Seeds `$count` Mon–Fri rows (collection skips weekends) from `$start`,
     * owners `$base + i * $step`. Returns the number of rows seeded.
     */
    private function seedWeekdays(string $isin, string $start, int $count, int $base, int $step): int
    {
        $date = new DateTimeImmutable($start);
        for ($i = 0; $i < $count; ++$i) {
            while ((int) $date->format('N') >= 6) {
                $date = $date->modify('+1 day');
            }
            $this->seedOwnerCount($isin, $date->format('Y-m-d'), $base + $i * $step);
            $date = $date->modify('+1 day');
        }

        return $count;
    }

    private function validCookie(): string
    {
        return 'stockpicker_session=' . $this->endpoint->signedSessionCookie(time() + 3600);
    }

    /**
     * Slices out one Leaderboard row's own markup (from its `data-isin`
     * attribute to the row `<div>`'s closing tag) so a test can assert on
     * that row's sparkline stroke class without accidentally matching a
     * different row on the same page.
     */
    private function rowHtmlFor(string $body, string $isin): string
    {
        $start = strpos($body, 'data-isin="' . $isin . '"');
        self::assertNotFalse($start, "no row found for isin {$isin}");

        $end = strpos($body, '</div>', $start);
        self::assertNotFalse($end, "row for isin {$isin} has no closing </div>");

        return substr($body, $start, $end - $start);
    }

    /**
     * Slices out the Source switcher's own markup (`.source-switcher` to its
     * closing `</div>`) so a test can assert on its tab order/contents
     * without accidentally matching an unrelated same-labeled control
     * elsewhere on the page (e.g. /list's "Alla" market filter option).
     */
    private function sourceSwitcherHtmlFor(string $body): string
    {
        $start = strpos($body, 'class="source-switcher"');
        self::assertNotFalse($start, 'no source-switcher found in body');

        $end = strpos($body, '</div>', $start);
        self::assertNotFalse($end, 'source-switcher has no closing </div>');

        return substr($body, $start, $end - $start);
    }

    /**
     * spec-stadig-tillvaxt-period — slices out Topplista's period row
     * (`.period-row` up to the market row that follows it).
     */
    private function periodRowHtmlFor(string $body): string
    {
        $start = strpos($body, 'class="period-row');
        self::assertNotFalse($start, 'no period-row found in body');

        $end = strpos($body, 'class="market-row"', $start);
        self::assertNotFalse($end, 'period-row is not followed by the market row');

        return substr($body, $start, $end - $start);
    }

    /**
     * spec-topplista-market-filter — slices out Topplista's market row
     * (`.market-row` up to the end of the header).
     */
    private function marketRowHtmlFor(string $body): string
    {
        $start = strpos($body, 'class="market-row"');
        self::assertNotFalse($start, 'no market-row found in body');

        $end = strpos($body, '</header>', $start);
        self::assertNotFalse($end, 'market-row is not inside the header');

        return substr($body, $start, $end - $start);
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

    private function setRunWeekdays(string $value): void
    {
        (new SettingsRepository($this->pdo))->set('run_weekdays', $value);
    }

    /** Neutralizes the run_weekdays gate so a test isn't flaky depending on which real day it runs. */
    private function allowAllWeekdays(): void
    {
        $this->setRunWeekdays('1,2,3,4,5,6,7');
    }

    /** A weekday number (1-7) guaranteed to differ from today's, for exercising the weekend-skip gate. */
    private function aDifferentWeekdayThanToday(): string
    {
        $today = (int) (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('N');

        return (string) (($today % 7) + 1);
    }

    /**
     * The nearest earlier weekday (Mon-Fri), skipping Sat/Sun only -- mirrors
     * TopTenDigest::resolvePreviousTradingDay()'s weekday rule for a test
     * environment where no trading_holiday rows are marked.
     */
    private function previousWeekday(DateTimeImmutable $date): string
    {
        do {
            $date = $date->modify('-1 day');
        } while ((int) $date->format('N') >= 6);

        return $date->format('Y-m-d');
    }

    /** Inserts today's date into trading_holiday, for exercising the holiday-skip gate. */
    private function markTodayAsHoliday(): void
    {
        $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
        $stmt = $this->pdo->prepare(
            'INSERT INTO trading_holiday (holiday_date, description) VALUES (:date, :description)'
        );
        $stmt->execute(['date' => $today, 'description' => 'Test holiday']);
    }
}