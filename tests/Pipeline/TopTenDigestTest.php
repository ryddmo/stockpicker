<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Pipeline;

use DateTimeImmutable;
use DateTimeZone;
use Stockpicker\Adapter\NormalizedRow;
use Stockpicker\Config;
use Stockpicker\Pipeline\TopTenDigest;
use Stockpicker\Store\DerivedMetricsRepository;
use Stockpicker\Store\OwnerCountRepository;
use Stockpicker\Store\TradingHolidayRepository;
use Stockpicker\Tests\Store\StoreTestCase;

/**
 * spec-5-6 — unit tests for TopTenDigest's diff/formatting/skip logic,
 * exercised through a spy $mailSender so no real SMTP is ever touched. Uses
 * the real MariaDB fixture (StoreTestCase) since the digest reads
 * `owner_count_metrics` through DerivedMetricsRepository, same as every
 * other repository test in this suite.
 */
final class TopTenDigestTest extends StoreTestCase
{
    private OwnerCountRepository $owners;
    private DerivedMetricsRepository $metrics;
    private TradingHolidayRepository $holidays;
    private Config $config;

    /** @var list<array{to: string, subject: string, message: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->owners = new OwnerCountRepository($this->pdo);
        $this->metrics = new DerivedMetricsRepository($this->pdo);
        $this->holidays = new TradingHolidayRepository($this->pdo);
        $this->config = Config::fromArray([
            'cron_token' => 'test',
            'digest' => [
                'username' => 'digest@example.com',
                'password' => 'secret',
                'recipient' => 'stockpicker@ryddmo.se',
            ],
        ]);
        $this->sent = [];
    }

    private function digest(): TopTenDigest
    {
        return new TopTenDigest(
            $this->metrics,
            $this->holidays,
            $this->config,
            function (string $to, string $subject, string $message): bool {
                $this->sent[] = ['to' => $to, 'subject' => $subject, 'message' => $message];

                return true;
            },
        );
    }

    private function insertInstrument(string $isin, string $name, string $list = 'LC'): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO instrument (isin, name, list, first_seen) VALUES (:isin, :name, :list, \'2026-01-01\')'
        );
        $stmt->execute(['isin' => $isin, 'name' => $name, 'list' => $list]);
    }

    private function seed(string $isin, string $asOfDate, int $owners, string $source = NormalizedRow::SOURCE_AVANZA): void
    {
        $row = new NormalizedRow(
            $isin,
            $source,
            $owners,
            null,
            null,
            null,
            new DateTimeImmutable($asOfDate . 'T12:00:00', new DateTimeZone('UTC')),
        );
        self::assertTrue($this->owners->upsert($row, $asOfDate), "seed row for {$isin}/{$asOfDate} should be new");
    }

    private function markHoliday(string $date, string $description = 'test holiday'): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO trading_holiday (holiday_date, description) VALUES (:date, :description)');
        $stmt->execute(['date' => $date, 'description' => $description]);
    }

    public function testEmailsSummaryWithInUtAndRankMovesOnAGenuineChange(): void
    {
        $this->insertInstrument('SE0000000001', 'Alpha AB');
        $this->insertInstrument('SE0000000002', 'Beta AB');
        $this->insertInstrument('SE0000000003', 'Gamma AB');
        $this->insertInstrument('SE0000000004', 'Delta AB');

        // Prior trading day (Tuesday 2026-06-02): rank order Alpha, Beta, Gamma.
        $this->seed('SE0000000001', '2026-06-02', 3000);
        $this->seed('SE0000000002', '2026-06-02', 2000);
        $this->seed('SE0000000003', '2026-06-02', 1000);

        // Today (Wednesday 2026-06-03): Beta overtakes Alpha (a rank move),
        // Gamma is gone (UT), Delta is new (IN).
        $this->seed('SE0000000001', '2026-06-03', 3000);
        $this->seed('SE0000000002', '2026-06-03', 3500);
        $this->seed('SE0000000004', '2026-06-03', 500);

        $this->digest()->run('2026-06-03');

        self::assertCount(1, $this->sent, 'a genuine change must send exactly one email');
        $email = $this->sent[0];
        self::assertSame('stockpicker@ryddmo.se', $email['to']);
        self::assertStringContainsString('2026-06-03', $email['subject']);
        self::assertStringContainsString('Flest ägare:', $email['message']);
        self::assertStringContainsString('Stadig tillväxt:', $email['message']);
        self::assertStringContainsString('Beta AB ↑ #2→#1', $email['message']);
        self::assertStringContainsString('Alpha AB ↓ #1→#2', $email['message']);
        self::assertStringContainsString('IN: Delta AB (#3)', $email['message']);
        self::assertStringContainsString('UT: Gamma AB (#3)', $email['message']);
    }

    public function testNoEmailWhenNothingChangedInEitherRanking(): void
    {
        $this->insertInstrument('SE0000000001', 'Alpha AB');
        $this->insertInstrument('SE0000000002', 'Beta AB');

        $this->seed('SE0000000001', '2026-06-02', 3000);
        $this->seed('SE0000000002', '2026-06-02', 2000);
        $this->seed('SE0000000001', '2026-06-03', 3000);
        $this->seed('SE0000000002', '2026-06-03', 2000);

        $this->digest()->run('2026-06-03');

        self::assertSame([], $this->sent, 'identical top-10 order must never send an email');
    }

    public function testNoEmailWhenNoPreviousTradingDayHasAnyDataYet(): void
    {
        $this->insertInstrument('SE0000000001', 'Alpha AB');
        // Only today's row exists -> 2026-06-02 (the resolved previous
        // trading day) has zero rows for either ranking: first-ever run.
        $this->seed('SE0000000001', '2026-06-03', 3000);

        $this->digest()->run('2026-06-03');

        self::assertSame([], $this->sent, 'no prior trading day data yet must skip, not crash');
    }

    public function testCapsAtFiveChangesPerRankingWithPlusNTill(): void
    {
        // Old AB stays at rank #1 on both days (unchanged -> not a "change"
        // itself), so the prior day's ranking is real data (not treated as
        // "no prior data yet") while every other line below is a fresh
        // entrant (IN) today: exactly 8 changes -> first 5 + "+3 till".
        $this->insertInstrument('SE0000000099', 'Old AB');
        $this->seed('SE0000000099', '2026-06-02', 100000);
        $this->seed('SE0000000099', '2026-06-03', 100000);

        for ($i = 1; $i <= 8; ++$i) {
            $isin = sprintf('SE00000001%02d', $i);
            $this->insertInstrument($isin, 'Entrant ' . $i);
            $this->seed($isin, '2026-06-03', 9000 - $i); // keeps a stable rank order
        }

        $this->digest()->run('2026-06-03');

        self::assertCount(1, $this->sent);
        $message = $this->sent[0]['message'];
        self::assertStringContainsString('+3 till', $message, '8 changes -> first 5 individually, +3 till for the rest');
        self::assertSame(5, substr_count($message, 'IN: Entrant '));
    }

    public function testWalksBackPastAWeekendToFindThePreviousTradingDay(): void
    {
        $this->insertInstrument('SE0000000001', 'Alpha AB');
        $this->insertInstrument('SE0000000002', 'Beta AB');

        // Friday 2026-06-05: prior trading day data. Saturday/Sunday have no
        // (and cannot have) rows. Monday 2026-06-08 is the run date.
        $this->seed('SE0000000001', '2026-06-05', 3000);
        $this->seed('SE0000000002', '2026-06-05', 2000);
        $this->seed('SE0000000001', '2026-06-08', 2500);
        $this->seed('SE0000000002', '2026-06-08', 3500);

        $this->digest()->run('2026-06-08');

        self::assertCount(1, $this->sent);
        self::assertStringContainsString('jämfört med föregående handelsdag (2026-06-05)', $this->sent[0]['message']);
        self::assertStringContainsString('Beta AB ↑ #2→#1', $this->sent[0]['message']);
    }

    public function testWalksBackPastAHolidayToFindThePreviousTradingDay(): void
    {
        $this->insertInstrument('SE0000000001', 'Alpha AB');
        $this->insertInstrument('SE0000000002', 'Beta AB');

        // Thursday 2026-06-04 is a holiday; Wednesday 2026-06-03 is the real
        // previous trading day for Friday 2026-06-05's run.
        $this->markHoliday('2026-06-04');
        $this->seed('SE0000000001', '2026-06-03', 3000);
        $this->seed('SE0000000002', '2026-06-03', 2000);
        $this->seed('SE0000000001', '2026-06-05', 2500);
        $this->seed('SE0000000002', '2026-06-05', 3500);

        $this->digest()->run('2026-06-05');

        self::assertCount(1, $this->sent);
        self::assertStringContainsString('jämfört med föregående handelsdag (2026-06-03)', $this->sent[0]['message']);
    }

    public function testSkipsSilentlyWithoutCrashingWhenNoTradingDayIsFoundWithinTheLookbackCap(): void
    {
        $this->insertInstrument('SE0000000001', 'Alpha AB');
        $this->seed('SE0000000001', '2026-06-10', 3000);

        // Every one of the 10 days before the run date is a holiday -> the
        // lookback cap is hit with nothing found. Must not throw.
        for ($i = 1; $i <= 10; ++$i) {
            $this->markHoliday((new DateTimeImmutable('2026-06-10'))->modify("-{$i} day")->format('Y-m-d'));
        }

        $this->digest()->run('2026-06-10');

        self::assertSame([], $this->sent);
    }

    public function testOnlyAvanzaIsUsedAsTheRankingBasisEvenWhenNordnetDiffersMore(): void
    {
        $this->insertInstrument('SE0000000001', 'Alpha AB');
        $this->insertInstrument('SE0000000002', 'Beta AB');

        // Avanza: perfectly flat, so neither the owner-count order nor the
        // (zero-delta, never-qualifying) trend ranking changes at all.
        $this->seed('SE0000000001', '2026-06-02', 3000, NormalizedRow::SOURCE_AVANZA);
        $this->seed('SE0000000002', '2026-06-02', 2000, NormalizedRow::SOURCE_AVANZA);
        $this->seed('SE0000000001', '2026-06-03', 3000, NormalizedRow::SOURCE_AVANZA);
        $this->seed('SE0000000002', '2026-06-03', 2000, NormalizedRow::SOURCE_AVANZA);

        // Nordnet: a big swap, but must never influence the digest.
        $this->seed('SE0000000001', '2026-06-02', 100, NormalizedRow::SOURCE_NORDNET);
        $this->seed('SE0000000002', '2026-06-02', 9000, NormalizedRow::SOURCE_NORDNET);
        $this->seed('SE0000000001', '2026-06-03', 9500, NormalizedRow::SOURCE_NORDNET);
        $this->seed('SE0000000002', '2026-06-03', 50, NormalizedRow::SOURCE_NORDNET);

        $this->digest()->run('2026-06-03');

        self::assertSame([], $this->sent, 'a Nordnet-only swap must never trigger a send');
    }
}
