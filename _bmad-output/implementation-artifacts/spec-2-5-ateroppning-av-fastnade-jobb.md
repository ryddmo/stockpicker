---
title: 'Story 2.5: Återöppning av fastnade jobb'
type: 'feature'
created: '2026-09-10'
status: 'ready-for-dev'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: '0d39e9b98dd79c77507b7cf8578e2cb05c786a6b'
context:
  - _bmad-output/implementation-artifacts/epic-2-context.md
  - _bmad-output/implementation-artifacts/spec-1-7-work-queue-och-tidsboxad-fetchrunner.md
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `FetchRunner` already reopens stale `claimed` rows at slice start
(`QueueRepository::reopenStale()`, Story 1.7) and a normal slice already leaves nothing
`claimed` — but `reopenStale()` was deliberately scoped `AND run_date = :run_date`. Since
`/cron/work` always runs for **today**, a slice killed mid-job near midnight (host restart,
SAPI timeout) leaves that job `claimed` for **yesterday's** run_date, where no future slice
ever looks — the instrument is unprocessed forever, exactly the failure this story exists to
prevent.

**Approach:** Extend stale recovery to `claimed` rows from any run_date older than
`settings.queue.stale_after`. A same-run_date stale row still reopens to `pending` as today
(unchanged). A stale `claimed` row from a **past** run_date is marked `failed` (decision,
2026-09-10) — that instrument's slot for that past day stays a gap (acceptable per the epic's
"a fraction of a percent missing"), the queue stays honest, and the count is surfaced in the
`slice complete` log. No backfill, no new setting. Add the explicit regression test that a
clean slice leaves zero `claimed` rows.

## Boundaries & Constraints

**Always:**
- Stale recovery runs first each slice, before `claimBatch`, exactly as today. A `claimed`
  row whose `claimed_at` is strictly older than `utc_now - queue.stale_after` seconds
  (default 900) is recovered; the boundary stays strict `<` and `claimed_at` stays the
  staleness clock. The current slice's own claims (`claimed_at = now`) are never candidates.
- A stale row **for the current run_date** reopens to `pending` with `claimed_at = NULL` and
  is then eligible for this slice's `claimBatch` — unchanged from Story 1.7. Counted in
  `FetchRunnerResult.reopened`.
- A normal slice that finishes within the timebox leaves **zero** `claimed` rows from that
  slice: every job ends `done` / `failed` / `pending` (terminal transition, timebox-leftover
  reopen, or the outer `\Throwable` → `markFailed`). This is a regression guard — assert it
  explicitly for a multi-job clean slice.
- A stale `claimed` row from a **past** run_date (`run_date < today`, `claimed_at` older than
  `stale_after`) is marked `failed` at slice start — never left `claimed`, never reopened,
  never re-dated. The count is surfaced in the `slice complete` `info` line (`stale_failed`)
  and on `FetchRunnerResult` (new `int` field). Its `owner_count_daily` slot for that day
  stays absent (a real gap).
- `queue.stale_after` is read via the existing `FetchRunner::numericSetting()` positive-or-default
  path. Serial, timeboxed slice behaviour is unchanged.

**Never:**
- No `work_queue` schema change (no new column, no new status value). No change to the
  `pending → claimed → done | failed` state machine or its guards.
- No persisted `reopened` / stale-recovery counter on `ingest_run`, no `bin/show-runs.php`
  column, no alarm — run-log enrichment is Story 2.6.
- No retry/backoff change (Story 2.4, done). No parallel claims. No pruning/retention of old
  `done` / `failed` rows.
- No re-enqueue of a whole past day's universe — only rows already in `work_queue` are touched.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Behavior | Error Handling |
|----------|--------------|-------------------|----------------|
| Stale, current run_date | `claimed` row for today, `claimed_at` > `stale_after` old | reopened `pending` at slice start, re-claimed this slice, processed; `reopened` +1 | — |
| Fresh claim, same slice | a job `claimBatch` just claimed (`claimed_at = now`) | never a stale candidate — processed normally | — |
| Just under the window | `claimed_at` exactly `now - stale_after` | **not** recovered (strict `<`) | — |
| Stale, past run_date | `claimed` row for an earlier run_date, older than `stale_after` (a slice killed near midnight) | marked `failed` at slice start; `stale_failed` +1; not reopened, not claimed, not re-dated | — |
| Stale, past run_date, not yet old | `claimed` row for an earlier run_date but `claimed_at` within `stale_after` (a slice still running from just before midnight) | left alone this slice — recovered on a later slice once older than `stale_after` | — |
| Clean multi-job slice | N jobs, all sources OK, finishes in time | all `done`; `SELECT COUNT(*) FROM work_queue WHERE status='claimed'` = 0; `reopened` = 0 | — |
| Killed slice then recovery | slice claims 5 jobs, process dies (no transition); a later slice ≥ `stale_after` later | the 5 rows recovered at that later slice's start | — |
| Empty queue | nothing `pending`, nothing stale | `claimed`/`reopened` = 0, no error | — |

</frozen-after-approval>

## Code Map

- `src/Store/QueueRepository.php:45-67` `reopenStale(int, DateTimeImmutable, string $runDate)`
  — keep the current-run_date `UPDATE ... SET status='pending', claimed_at=NULL WHERE
  status='claimed' AND run_date=:run_date AND claimed_at < :threshold`. **Add** a second
  `UPDATE ... SET status='failed' WHERE status='claimed' AND run_date < :run_date AND
  claimed_at < :threshold` (same `$threshold`). Change the return to a small
  `{reopened:int, staleFailed:int}` struct (or a readonly VO in `src/Store/`), or add a
  sibling method — the caller needs both counts.
- `src/Pipeline/FetchRunner.php:130` — the `reopenStale()` call; `:114` reads
  `queue.stale_after`. Capture the `staleFailed` count; add it to the `slice complete` `info`
  line (`:325-333`) and pass it to `FetchRunnerResult`. `runs->record('fetch', …)` unchanged
  (the stale-failed rows are **not** folded into this slice's `fail_count` — they belong to a
  past run).
- `src/Pipeline/FetchRunnerResult.php` — add `public int $staleFailed`; docblock note.
- `db/migrations/20260909150000_create_work_queue.php` — **no change** (a cross-run-date
  `WHERE status='claimed' AND claimed_at < …` is a post-filter on the `(status, run_date)`
  index; `work_queue` is small).
- `tests/Store/QueueRepositoryTest.php` — `testReopenStaleIsScopedToTheRunDate` currently
  asserts a past-date stale row is **untouched**; rewrite it to assert past-date stale
  `claimed` → `failed` while the current-date row still reopens. Keep
  `testReopenStaleReopensOnlyRowsOlderThanTheWindow` / `...BoundaryIsStrictlyOlderThan` green.
- `tests/Pipeline/FetchRunnerTest.php` — `testStaleClaimedRowIsReopenedAtSliceStartThenClaimed`
  stays green. Add: a clean multi-job slice leaves zero `claimed` rows (explicit AC2); a
  past-run_date stale `claimed` row is marked `failed` and `result->staleFailed === 1`.
- `tests/FrontControllerIntegrationTest.php` — `testWorkRunsOneSliceAndReturnsCounts` stays
  green (`reopened === 0`); optionally assert the JSON has no regression.
- `public_html/index.php:142-153` `/cron/work` — add `'stale_failed' => $result->staleFailed`
  to the JSON payload (parallels `reopened`).

## Tasks & Acceptance

**Execution:**
- [ ] `src/Store/QueueRepository.php` -- `reopenStale()` keeps reopening current-run_date stale `claimed` rows and now also marks past-run_date (`run_date < :run_date`) stale `claimed` rows `failed`; returns both counts -- a slice killed near midnight must not strand a job forever.
- [ ] `src/Pipeline/FetchRunner.php` -- capture the `staleFailed` count, add it to the `slice complete` `info` line and `FetchRunnerResult`; leave `runs->record('fetch', …)` `fail_count` untouched -- the operator can see stuck-job give-up happened without it polluting this slice's fetch tally.
- [ ] `src/Pipeline/FetchRunnerResult.php` + `public_html/index.php` -- add `staleFailed` / `stale_failed` alongside `reopened` -- surfaced in the `/cron/work` response.
- [ ] `tests/Store/QueueRepositoryTest.php` -- rewrite `testReopenStaleIsScopedToTheRunDate` to the new behaviour (past-date stale → `failed`, current-date stale → reopened); keep the window/boundary tests green -- the cross-run-date contract is pinned.
- [ ] `tests/Pipeline/FetchRunnerTest.php` -- add an explicit "clean multi-job slice leaves zero `claimed` rows" test (AC2) and a "past-run_date stale `claimed` row → `failed`, `result->staleFailed === 1`" test -- edge-case coverage.
- [ ] `docs/deploy.md` -- one line: a `/cron/work` slice now also fails stuck `claimed` rows left by a killed slice on an earlier day (`stale_failed` in the response) -- operator note.

**Acceptance Criteria:**
- Given a `work_queue` row `claimed` longer than `settings.queue.stale_after`, when a new slice starts, then it is recovered (reopened, or failed per the decision) before `claimBatch` runs — including a row left `claimed` for a **past** run_date by a killed slice.
- Given a normal slice that finishes within the timebox, when it returns, then no `work_queue` row from that slice is still `status='claimed'`.
- Given `composer test`, then the full suite is green with no regressions and the `pending → claimed → done | failed` state machine and its guards are unchanged.

## Implementation Notes

## Spec Change Log

## Review Triage Log

## Verification

**Commands:**
- `composer test -- --filter 'QueueRepositoryTest|FetchRunnerTest'` -- new + regression cases green.
- `composer test` -- full suite green, no regressions.

**Manual checks:**
- After a simulated killed slice (claim rows, do not transition) followed by a slice
  `stale_after` later: `SELECT status, COUNT(*) FROM work_queue GROUP BY status` shows the
  rows recovered, none left `claimed`.
