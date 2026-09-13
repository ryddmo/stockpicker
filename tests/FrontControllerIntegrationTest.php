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

    public function testDeriveWritesOneIngestRunRowAndReturnsCounts(): void
    {
        $this->seedMatchedUniverse();
        $this->setRunAfter('00:00');

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

    public function testRootDefaultViewShowsTop10ByOwnerCountForAvanza(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000);
        $this->seedOwnerCount('SE0000001002', '2026-01-01', 5000);

        [$status, $body] = $this->endpoint->get('/', $this->validCookie());

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
        // The default view's own Nordnet/Stadig tillväxt links — read from the
        // controller's real output rather than a hand-typed query string.
        self::assertStringContainsString('href="/?source=nordnet"', $body);
        self::assertStringContainsString('href="/?ranking=steady"', $body);
    }

    public function testRootRendersTheSpikeStrokeClassForASpikingRowAndThePositiveClassForAGrowingRow(): void
    {
        $this->seedMatchedUniverse();

        // Alpha AB: 29 days of steady growth then a huge jump -> spike_score
        // >= 2 -> the sparkline must use the spike stroke class, not
        // positive/neutral, even though the last day is also numerically an
        // increase. Rendered on the default (Flest ägare) view, since
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

        [$status, $body] = $this->endpoint->get('/', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('sparkline-line--spike', $this->rowHtmlFor($body, 'SE0000001001'));
        self::assertStringContainsString('sparkline-line--positive', $this->rowHtmlFor($body, 'SE0000001002'));
    }

    public function testRootWithSourceNordnetRendersNordnetDataOnlyNeverMergedWithAvanza(): void
    {
        $this->seedMatchedUniverse();
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 100, NormalizedRow::SOURCE_AVANZA);
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 999999, NormalizedRow::SOURCE_NORDNET);

        [$status, $body] = $this->endpoint->get('/?source=nordnet', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('999 999', $body);
        self::assertSame(1, substr_count($body, 'class="row"'), 'only the one isin with Nordnet data may appear');
    }

    public function testRootWithRankingSteadyShowsZeroQualifiersMessageWhenNothingQualifies(): void
    {
        $this->seedMatchedUniverse();
        // Only one day of history anywhere -> up_streak is NULL/0 for
        // everything, so nothing qualifies for "Stadig tillväxt".
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000);

        [$status, $body] = $this->endpoint->get('/?ranking=steady', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('Inga aktier med stadig tillväxt just nu.', $body);
    }

    public function testRootWithRankingSteadyOrdersByUpStreakAndExcludesTheSpikingInstrument(): void
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
        [$rootStatus, $rootBody] = $this->endpoint->get('/', $cookie);
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

    public function testStockDetailRangeVeckaRendersTheChartWhenPrimaryHasSevenRows(): void
    {
        $this->seedMatchedUniverse();
        $start = new DateTimeImmutable('2026-02-01');
        for ($i = 0; $i < 7; ++$i) {
            $this->seedOwnerCount('SE0000001001', $start->modify("+{$i} days")->format('Y-m-d'), 1000 + $i * 5);
        }

        [$status, $body] = $this->endpoint->get('/stock/SE0000001001?range=vecka', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('trend-overlay', $body);
        self::assertStringNotContainsString('Inte tillräckligt med historik', $body);
    }

    public function testStockDetailRange30dShowsInsufficientHistoryMessageWhenPrimaryHasFewerThan30Rows(): void
    {
        $this->seedMatchedUniverse();
        $start = new DateTimeImmutable('2026-02-01');
        for ($i = 0; $i < 10; ++$i) {
            $this->seedOwnerCount('SE0000001001', $start->modify("+{$i} days")->format('Y-m-d'), 1000 + $i * 5);
        }

        [$status, $body] = $this->endpoint->get('/stock/SE0000001001?range=30d', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString(
            'Inte tillräckligt med historik för det här intervallet ännu — kolla in igen om 20 dagar',
            $body,
        );
    }

    public function testStockDetailRangeArRendersTheFullSeriesWhenPrimaryHasAtLeast90Rows(): void
    {
        $this->seedMatchedUniverse();
        $start = new DateTimeImmutable('2026-01-01');
        for ($i = 0; $i < 90; ++$i) {
            $this->seedOwnerCount('SE0000001001', $start->modify("+{$i} days")->format('Y-m-d'), 1000 + $i * 3);
        }

        [$status, $body] = $this->endpoint->get('/stock/SE0000001001?range=ar', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('trend-overlay', $body);
        self::assertStringNotContainsString('Inte tillräckligt med historik', $body);
    }

    public function testStockDetailRange90dRendersTheChartWhenPrimaryHasNinetyRows(): void
    {
        $this->seedMatchedUniverse();
        $start = new DateTimeImmutable('2026-01-01');
        for ($i = 0; $i < 90; ++$i) {
            $this->seedOwnerCount('SE0000001001', $start->modify("+{$i} days")->format('Y-m-d'), 1000 + $i * 3);
        }

        [$status, $body] = $this->endpoint->get('/stock/SE0000001001?range=90d', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString('trend-overlay', $body);
        self::assertStringNotContainsString('Inte tillräckligt med historik', $body);
    }

    public function testStockDetailDefaultDagViewShowsInsufficientHistoryForABrandNewInstrument(): void
    {
        $this->seedMatchedUniverse();
        // Only one stored day -> fewer than Dag's 2-row gate.
        $this->seedOwnerCount('SE0000001001', '2026-01-01', 1000);

        [$status, $body] = $this->endpoint->get('/stock/SE0000001001', $this->validCookie());

        self::assertSame(200, $status);
        self::assertStringContainsString(
            'Inte tillräckligt med historik för det här intervallet ännu — kolla in igen om 1 dagar',
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
            'kvalificerar om aktien har minst 1 dags obruten uppgångssvit och inte just nu spikar; sorteras med längst svit först',
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