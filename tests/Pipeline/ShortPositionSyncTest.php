<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Pipeline;

use Psr\Log\NullLogger;
use Stockpicker\Adapter\ShortPosition;
use Stockpicker\Adapter\ShortPositionSource;
use Stockpicker\Error\SchemaMismatch;
use Stockpicker\Error\Transient;
use Stockpicker\Pipeline\ShortPositionSync;
use Stockpicker\Store\RunRepository;
use Stockpicker\Store\SettingsRepository;
use Stockpicker\Store\ShortPositionRepository;
use Stockpicker\Tests\Store\StoreTestCase;

/** spec-short-interest-data — the nightly FI snapshot step and its `shorts` run log. */
final class ShortPositionSyncTest extends StoreTestCase
{
    private const RUN_DATE = '2026-10-03';
    private const LEI = '54930044O54BK617EP80';

    private FakeShortPositionSource $source;

    /** @var list<array{to: string, subject: string, message: string}> */
    private array $sentMails = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = new FakeShortPositionSource();
        $this->sentMails = [];
        $this->pdo->exec("INSERT INTO settings (`key`, `value`) VALUES ('alarm.email', 'alarm@example.com')");
    }

    private function sync(): ShortPositionSync
    {
        return new ShortPositionSync(
            $this->source,
            new ShortPositionRepository($this->pdo),
            new RunRepository($this->pdo),
            new SettingsRepository($this->pdo),
            new NullLogger(),
            function (string $to, string $subject, string $message): bool {
                $this->sentMails[] = ['to' => $to, 'subject' => $subject, 'message' => $message];

                return true;
            },
        );
    }

    /** @return list<\Stockpicker\Store\IngestRun> */
    private function shortsRuns(): array
    {
        return array_values(array_filter(
            (new RunRepository($this->pdo))->forRunDate(self::RUN_DATE),
            static fn ($r): bool => $r->runType === 'shorts',
        ));
    }

    private function positionCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM short_position')->fetchColumn();
    }

    public function testNormalNightWritesTheSnapshotAndAnOkRun(): void
    {
        $this->source->result = [new ShortPosition(self::LEI, 'Elekta AB (publ)', 16.05, '2026-10-02')];

        self::assertSame(['status' => 'ok', 'rows' => 1], $this->sync()->run(self::RUN_DATE));

        $row = $this->pdo->query('SELECT snapshot_date, lei, position_pct, position_date FROM short_position')->fetch();
        self::assertSame(self::RUN_DATE, $row['snapshot_date']);
        self::assertSame(self::LEI, $row['lei']);
        self::assertSame('16.05', (string) $row['position_pct']);
        self::assertSame('2026-10-02', $row['position_date']);

        $runs = $this->shortsRuns();
        self::assertCount(1, $runs);
        self::assertSame('completed', $runs[0]->status);
        self::assertFalse($runs[0]->alarm);
        self::assertSame(1, $runs[0]->okCount);
        self::assertSame(['fi' => ['ok' => 1, 'not_found' => 0, 'schema_mismatch' => 0, 'transient' => 0]], $runs[0]->bySource);
        self::assertSame([], $this->sentMails);
    }

    public function testReRunSameNightEndsInTheSameState(): void
    {
        $this->source->result = [new ShortPosition(self::LEI, 'Elekta AB (publ)', 16.05, '2026-10-02')];

        $this->sync()->run(self::RUN_DATE);
        $this->sync()->run(self::RUN_DATE);

        self::assertSame(1, $this->positionCount());
    }

    public function testSchemaMismatchWritesNothingAlarmsAndMails(): void
    {
        $this->source->error = new SchemaMismatch('header lacks LEI');

        self::assertSame(['status' => 'schema_mismatch', 'rows' => 0], $this->sync()->run(self::RUN_DATE));

        self::assertSame(0, $this->positionCount());
        $runs = $this->shortsRuns();
        self::assertCount(1, $runs);
        self::assertSame('alarmed', $runs[0]->status);
        self::assertTrue($runs[0]->alarm);
        self::assertSame(1, $runs[0]->schemaMismatchCount);
        self::assertSame(['fi' => ['ok' => 0, 'not_found' => 0, 'schema_mismatch' => 1, 'transient' => 0]], $runs[0]->bySource);
        self::assertCount(1, $this->sentMails);
        self::assertSame('alarm@example.com', $this->sentMails[0]['to']);
    }

    public function testTransientLeavesThePreviousSnapshotCurrentAndFailsTheRun(): void
    {
        (new ShortPositionRepository($this->pdo))->upsertSnapshot(
            '2026-10-02',
            [new ShortPosition(self::LEI, 'Elekta AB (publ)', 15.80, '2026-10-01')],
            new \DateTimeImmutable('2026-10-02 18:00:00'),
        );
        $this->source->error = new Transient('FI down');

        self::assertSame(['status' => 'transient', 'rows' => 0], $this->sync()->run(self::RUN_DATE));

        self::assertSame(1, $this->positionCount());
        self::assertSame('2026-10-02', (new ShortPositionRepository($this->pdo))->latestSnapshotDate());
        $runs = $this->shortsRuns();
        self::assertSame('failed', $runs[0]->status);
        self::assertFalse($runs[0]->alarm);
        self::assertSame(['fi' => ['ok' => 0, 'not_found' => 0, 'schema_mismatch' => 0, 'transient' => 1]], $runs[0]->bySource);
        self::assertSame([], $this->sentMails);
    }

    public function testNotFoundIsTalliedAsNotFoundAndFailsTheRunWithoutAlarm(): void
    {
        $this->source->error = new \Stockpicker\Error\NotFound('file gone');

        self::assertSame(['status' => 'not_found', 'rows' => 0], $this->sync()->run(self::RUN_DATE));

        $run = $this->shortsRuns()[0];
        self::assertSame('failed', $run->status);
        self::assertFalse($run->alarm);
        self::assertSame(['fi' => ['ok' => 0, 'not_found' => 1, 'schema_mismatch' => 0, 'transient' => 0]], $run->bySource);
        self::assertSame([], $this->sentMails);
    }

    public function testUnexpectedErrorIsRecordedFailedAndRethrown(): void
    {
        $this->source->error = new \RuntimeException('boom');

        try {
            $this->sync()->run(self::RUN_DATE);
            self::fail('expected the RuntimeException to propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame('failed', $this->shortsRuns()[0]->status);
    }
}

final class FakeShortPositionSource implements ShortPositionSource
{
    /** @var list<ShortPosition> */
    public array $result = [];

    public ?\Throwable $error = null;

    public function fetchAggregated(): array
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->result;
    }
}
