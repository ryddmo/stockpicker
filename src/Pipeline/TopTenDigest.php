<?php

declare(strict_types=1);

namespace Stockpicker\Pipeline;

use DateTimeImmutable;
use Stockpicker\Adapter\NormalizedRow;
use Stockpicker\Config;
use Stockpicker\Store\DerivedMetricsRepository;
use Stockpicker\Store\TradingHolidayRepository;

/**
 * spec-5-6 — a new, isolated pipeline step run after `/cron/derive`'s own
 * work: diffs today's Avanza-sourced top-10 (both Topplista rankings, "Flest
 * ägare" and "Stadig tillväxt") against the previous *trading* day's, and
 * emails a plain-text Swedish summary to the configured recipient. Never a
 * `Deriver` change — this only reads `owner_count_metrics` via
 * `DerivedMetricsRepository`'s `*AsOf()` methods and sends mail; the caller
 * (`public_html/index.php`) is responsible for catching and logging any
 * failure so it never affects `/cron/derive`'s own response or `ingest_run`
 * row (AD: digest failures must be invisible to derive's caller).
 *
 * Ranking basis is Avanza only (NormalizedRow::SOURCE_AVANZA), matching
 * Topplista's default/Alla-mode basis — not user-configurable.
 */
final class TopTenDigest
{
    /** How many top-10 slots each ranking is diffed over. */
    private const RANKING_LIMIT = 10;

    /** Per-ranking cap: beyond this many changes, the rest collapse into "+N till". */
    private const MAX_LISTED = 5;

    /** Sanity bound on walking backward for the previous trading day. */
    private const MAX_LOOKBACK_DAYS = 10;

    /** @var callable(string, string, string): bool */
    private $mailSender;

    /**
     * @param (callable(string, string, string): bool)|null $mailSender injected
     *        spy for tests; default wraps PHP's local mail()/sendmail, using
     *        Config::digest()'s From address. Not authenticated SMTP —
     *        Loopia's shared-hosting firewall blocks outbound SMTP to
     *        mailcluster.loopia.se entirely (confirmed live, 2026-09-15) —
     *        same local-delivery mechanism already used by
     *        UniverseSync/FetchRunner's alarm.email.
     */
    public function __construct(
        private readonly DerivedMetricsRepository $metrics,
        private readonly TradingHolidayRepository $holidays,
        private readonly Config $config,
        ?callable $mailSender = null,
    ) {
        $this->mailSender = $mailSender ?? $this->defaultMailSender();
    }

    /**
     * Entry point, called once per `/cron/derive` invocation that passes the
     * gate. `$runDate` is the Stockholm `Y-m-d` the gate already resolved —
     * never recomputed here. Skips silently (no email, no exception) when:
     *  - no previous trading day can be found within the lookback cap;
     *  - the previous trading day has no data at all yet (first-ever run);
     *  - nothing changed in either ranking.
     */
    public function run(string $runDate): void
    {
        $previousTradingDay = $this->resolvePreviousTradingDay($runDate);
        if ($previousTradingDay === null) {
            return;
        }

        $todayOwner = $this->metrics->topByOwnerCountAsOf(NormalizedRow::SOURCE_AVANZA, $runDate, self::RANKING_LIMIT);
        $priorOwner = $this->metrics->topByOwnerCountAsOf(NormalizedRow::SOURCE_AVANZA, $previousTradingDay, self::RANKING_LIMIT);
        $todayTrend = $this->metrics->topByTrendQualityAsOf(NormalizedRow::SOURCE_AVANZA, $runDate, self::RANKING_LIMIT);
        $priorTrend = $this->metrics->topByTrendQualityAsOf(NormalizedRow::SOURCE_AVANZA, $previousTradingDay, self::RANKING_LIMIT);

        if ($priorOwner === [] && $priorTrend === []) {
            // No previous trading day has any data yet (first-ever derive
            // run) — nothing meaningful to diff against. Skip, don't crash.
            return;
        }

        $ownerChanges = $this->diffRanking($todayOwner, $priorOwner);
        $trendChanges = $this->diffRanking($todayTrend, $priorTrend);

        if ($ownerChanges === [] && $trendChanges === []) {
            // No changes in either ranking -> no email.
            return;
        }

        $recipient = $this->config->digest()['recipient'];
        $subject = sprintf('Stockpicker – förändringar i topp 10 (%s)', $runDate);
        $body = $this->buildBody($runDate, $previousTradingDay, $ownerChanges, $trendChanges);

        if (!($this->mailSender)($recipient, $subject, $body)) {
            throw new \RuntimeException('TopTenDigest: mail sender returned false');
        }
    }

    /**
     * Walks backward from `$runDate` day by day, skipping Saturday/Sunday and
     * `TradingHolidayRepository::isHoliday()` dates, capped at
     * self::MAX_LOOKBACK_DAYS as a sanity bound (mirrors the discipline of
     * `index.php`'s own weekday/holiday gate, but self-contained here since
     * that private helper isn't reachable from src/Pipeline/). Returns null
     * only if nothing qualifies within the cap — treated as "skip", never
     * as an error.
     */
    private function resolvePreviousTradingDay(string $runDate): ?string
    {
        $date = new DateTimeImmutable($runDate);

        for ($i = 1; $i <= self::MAX_LOOKBACK_DAYS; ++$i) {
            $candidate = $date->modify(sprintf('-%d day', $i));
            $weekday = (int) $candidate->format('N'); // 1 (Mon) .. 7 (Sun)
            if ($weekday >= 6) {
                continue;
            }
            if ($this->holidays->isHoliday($candidate->format('Y-m-d'))) {
                continue;
            }

            return $candidate->format('Y-m-d');
        }

        return null;
    }

    /**
     * Diffs one ranking's ordered isin lists, uncapped. Order: today's
     * entries/moves in today's rank order, then prior-only exits in the
     * prior list's rank order. An empty return means no changes at all
     * (same rank, in the same order).
     *
     * @param list<array<string, mixed>> $today
     * @param list<array<string, mixed>> $prior
     *
     * @return list<string>
     */
    private function diffRanking(array $today, array $prior): array
    {
        $priorRankByIsin = [];
        foreach ($prior as $index => $row) {
            $priorRankByIsin[(string) $row['isin']] = $index + 1;
        }

        $todayIsins = [];
        foreach ($today as $row) {
            $todayIsins[(string) $row['isin']] = true;
        }

        $lines = [];
        foreach ($today as $index => $row) {
            $isin = (string) $row['isin'];
            $rank = $index + 1;
            $name = (string) $row['name'];

            if (!isset($priorRankByIsin[$isin])) {
                $lines[] = sprintf('IN: %s (#%d)', $name, $rank);

                continue;
            }

            $priorRank = $priorRankByIsin[$isin];
            if ($priorRank !== $rank) {
                $arrow = $rank < $priorRank ? '↑' : '↓';
                $lines[] = sprintf('%s %s #%d→#%d', $name, $arrow, $priorRank, $rank);
            }
        }

        foreach ($prior as $index => $row) {
            $isin = (string) $row['isin'];
            if (!isset($todayIsins[$isin])) {
                $lines[] = sprintf('UT: %s (#%d)', (string) $row['name'], $index + 1);
            }
        }

        return $lines;
    }

    /**
     * Caps an already-computed change list at self::MAX_LISTED individual
     * entries, folding the rest into a single "+N till" line (spec's I/O
     * matrix: "More than 5 changes in one ranking").
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function capChanges(array $lines): array
    {
        if (count($lines) <= self::MAX_LISTED) {
            return $lines;
        }

        $rest = count($lines) - self::MAX_LISTED;

        return [...array_slice($lines, 0, self::MAX_LISTED), sprintf('+%d till', $rest)];
    }

    /**
     * @param list<string> $ownerChanges
     * @param list<string> $trendChanges
     */
    private function buildBody(string $runDate, string $previousTradingDay, array $ownerChanges, array $trendChanges): string
    {
        $lines = [
            sprintf('Förändringar i topp 10 (%s), jämfört med föregående handelsdag (%s).', $runDate, $previousTradingDay),
            '',
            'Flest ägare:',
            ...$this->renderSection($ownerChanges),
            '',
            'Stadig tillväxt:',
            ...$this->renderSection($trendChanges),
        ];

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<string> $changes
     *
     * @return list<string>
     */
    private function renderSection(array $changes): array
    {
        return $changes === [] ? ['Inga förändringar.'] : $this->capChanges($changes);
    }

    /**
     * Default sender: PHP's local mail()/sendmail, `From:` set to
     * Config::digest()'s address — chosen over authenticated SMTP because
     * Loopia's shared-hosting firewall blocks outbound connections to
     * mailcluster.loopia.se on every port (confirmed live, 2026-09-15;
     * PHPMailer's SMTP transport could not connect at all). Local mail()
     * goes through the server's own pickup, not an outbound network hop, so
     * it isn't subject to that block — the same mechanism already proven by
     * UniverseSync/FetchRunner's alarm.email. `Config::digest()` is only
     * read lazily, when a send is actually about to happen (run() already
     * returned early on every skip path). The subject/body carry Swedish
     * characters, so both are MIME/charset-encoded explicitly — mail() does
     * not do this on its own. `@mail()`'s bool return already matches this
     * callable's contract directly; a `false` return is handled by run()'s
     * own check, not raised here.
     *
     * @return callable(string, string, string): bool
     */
    private function defaultMailSender(): callable
    {
        return function (string $to, string $subject, string $message): bool {
            $from = $this->config->digest()['username'];
            $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $headers = "From: {$from}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . 'Content-Transfer-Encoding: 8bit';

            return @mail($to, $encodedSubject, $message, $headers);
        };
    }
}
