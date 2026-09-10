---
title: 'Story 2.3: Delfeltolerans och fellogg per instrument'
type: 'feature'
created: '2026-09-10'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: 'e43cc68d4b3561828f43c687250a2a717afb99d8'
context:
  - _bmad-output/implementation-artifacts/epic-2-context.md
  - _bmad-output/implementation-artifacts/spec-1-7-work-queue-och-tidsboxad-fetchrunner.md
  - _bmad-output/implementation-artifacts/spec-1-8-minimal-korningslogg.md
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `FetchRunner` (Epic 1) already fails one job on a per-instrument `NotFound` /
`SchemaMismatch`, logs a `warning`, and continues the slice — that must not regress. Two gaps
remain against Epic 2's partial-failure requirement: (a) a `Transient` from the first source
(`avanza`) `break`s the source loop, so a source down for a whole slice blocks the **other**
source's data for every job it touches; (b) the run log has only per-job `ok_count` /
`fail_count` — no per-source split and no separate `SchemaMismatch` count, which Story 2.6
needs to surface and alarm on.

**Approach:** In the per-job source loop, stop letting a `Transient` from one source skip the
other — attempt every source with a cached id, write whatever lands (idempotent upsert), and
still reopen the job if any source was `Transient`. Tally each source's outcome (`ok` /
`not_found` / `schema_mismatch` / `transient`) across the slice and carry the split in
`FetchRunnerResult`, a structured `info` "slice complete" log line, and the `/cron/work`
JSON. Keep the existing per-instrument `warning`. No change to the fetch/write path, the queue
state transitions, or Story 2.4's retry/backoff scope.

## Boundaries & Constraints

**Always:**
- Regression guard (Epic 1 behaviour that must survive): a per-instrument `NotFound` /
  `SchemaMismatch` logs one `warning` per failing source (keys `isin`, `source`, `run_date`,
  `error` = exception class, `message`), job → `failed`, runner continues; an unexpected
  `\Throwable` → job `failed` at `error`; the slice never aborts; the working source's row is
  still written when the other source fails; a null cached id skips that source with a
  `warning` and is not counted.
- A `Transient` from any source no longer `break`s the source loop (decision, 2026-09-10 —
  the down-source case is in scope for 2.3; the attempt counter / backoff / per-source rate
  reduction stay in Story 2.4): every source with a non-null cached id is attempted and each
  returned row is `upsert`ed. If any source raised `Transient` the job is still reopened
  (`pending`); the OK source's row is already written and its re-fetch on retry is a harmless
  idempotent no-op. Job-state precedence unchanged: any `Transient` → `pending`; else any
  `NotFound`/`SchemaMismatch` → `failed`; else → `done`.
- Per-source tally over the slice, one bucket per source (`avanza`, `nordnet`): `ok` (a
  `fetch()` returned, upsert or not), `not_found`, `schema_mismatch`, `transient`.
  `SchemaMismatch` is counted separately from `NotFound` — it is the endpoint-shape-changed
  signal Story 2.6 alarms on. The tally is independent of the per-job `done`/`failed`/
  `reopened` counts, which stay exactly as they are.
- The split is exposed in exactly three places and **no schema change** (decision, 2026-09-10):
  `FetchRunnerResult` (new field), one `info` line `fetchrunner: slice complete` with the
  full breakdown, and the `/cron/work` response (`by_source`). The persistent `ingest_run`
  form, the `bin/show-runs.php` display, and the alarm are all Story 2.6 — "landing the data"
  here means computing and logging what 2.6 then persists and surfaces.
- External calls stay strictly serial and per-source throttled (`settings.rate.<source>`);
  the timebox still reopens every not-yet-processed job. Attempting the second source after a
  first-source `Transient` adds one throttled call per affected job — acceptable in the 75 s slice.

**Never:**
- No exponential backoff, attempt counter, per-source rate reduction, or per-source queue
  state — all Story 2.4 / 2.5. `Transient` still reopens the whole job with no memory of
  which source already succeeded.
- No change to `OwnerCountRepository::upsert()`, `NormalizedRow`, the adapters, the per-job
  `ingest_run` `ok_count`/`fail_count` semantics, `owner_count_daily`, or `work_queue`.
- No new alarm / notification / `ingest_run` display change / `owner_count_daily.ingest_run_id`
  link — all Story 2.6. No `ingest_run` retention.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Behavior | Error Handling |
|----------|--------------|-------------------|----------------|
| One `NotFound` mid-slice | job 1 avanza `NotFound`, nordnet ok; job 2 both ok | job 1 → `failed` (nordnet row written), job 2 → `done`, slice completes; `by_source.avanza`={ok:1,not_found:1}, `.nordnet`={ok:2} | one `warning` per failing source |
| `SchemaMismatch` mid-slice | job avanza `SchemaMismatch`, nordnet ok | job → `failed`, nordnet row written; `by_source.avanza.schema_mismatch`=1, `.not_found`=0 | `warning`, `error` = `…\SchemaMismatch` |
| Source down all slice | avanza `Transient` on every job, nordnet ok every job | every job reopened `pending`; **every nordnet row written**; `by_source.avanza.transient`=N, `.nordnet.ok`=N; `reopened`=N, `done`=0 | one `warning` per job per transient source |
| Both sources `Transient` for a job | avanza + nordnet both `Transient` | job reopened `pending`, no row for it, `.transient`+1 each | `warning` per source |
| Unexpected throw | job 1 adapter throws `\RuntimeException`; job 2 ok | job 1 → `failed`, job 2 → `done`, slice continues | `error` `unexpected error handling job` |
| Clean slice / empty queue | all ok / nothing claimed | all `done` / `claimed`=0; every unused bucket 0; one `fetch` `ingest_run` row; the `slice complete` line always emitted | N/A |

</frozen-after-approval>

## Code Map

- `src/Pipeline/FetchRunner.php:139-180` — the per-job source loop. `catch (Transient)`
  (`:160-169`) drops its `break` (record + continue). Split `catch (NotFound | SchemaMismatch)`
  (`:170-179`) into two arms so the buckets differ (keep the single `warning`). Add a
  `$bySource` accumulator; `++` the right bucket on each `fetch()` outcome incl. success
  (`:157`). Do **not** touch the `:182-191` job-state precedence or the `:192-204` `\Throwable`
  arm.
- `src/Pipeline/FetchRunner.php:45` `SOURCES` const — initialise `$bySource` buckets from it
  (a never-called source still reports all-zero). `:207-215` — after `runs->record('fetch', …)`
  (signature unchanged) add `logger->info('fetchrunner: slice complete', ['run_date'=>…,
  'by_source'=>$bySource, 'claimed'=>…, 'done'=>…, 'failed'=>…, 'reopened'=>…])` and pass
  `$bySource` to the result.
- `src/Pipeline/FetchRunnerResult.php` — `final readonly`, 5 `int` fields today. Add
  `array $bySource` (`array<string, array{ok:int,not_found:int,schema_mismatch:int,transient:int}>`);
  update the docblock.
- `public_html/index.php:129-151` `/cron/work` — add `'by_source' => $result->bySource` to
  the `send_json` payload; nothing else changes.
- `src/Store/RunRepository.php` / `IngestRun.php` / `db/migrations/` / `owner_count_daily` —
  **untouched** (decision: no schema change; the per-job `ingest_run` row is exactly as
  Story 1.8 shipped).
- `tests/Pipeline/FetchRunnerTest.php` — `FakeSourceAdapter` cases; helpers `RUN_DATE`,
  `seedInstruments()`, `enqueueAll()`, `logHandler` (`Monolog\Handler\TestHandler`),
  `fetchLogRows()` exist. Add: one source `Transient` for every job of a multi-job slice
  while the other succeeds throughout (every OK row written, all reopened, `by_source`);
  `SchemaMismatch` vs `NotFound` bucket; the `slice complete` line; `by_source` on a clean
  slice and empty queue. Regression cases stay green.
- `tests/FrontControllerIntegrationTest.php` `testWorkRunsOneSliceAndReturnsCounts` — add
  `by_source` to the asserted response shape.
- `tests/EndToEndSmokeTest.php:197-198` — sums `fetch` `ok_count`/`fail_count`; unaffected
  (those columns are unchanged).

## Tasks & Acceptance

**Execution:**
- [x] `src/Pipeline/FetchRunner.php` -- `Transient` no longer breaks the source loop; per-source slice tally (`ok` / `not_found` / `schema_mismatch` / `transient`, `SchemaMismatch` split from `NotFound`); a `fetchrunner: slice complete` `info` line with the `by_source` breakdown -- partial-failure tolerance + the per-source data Story 2.6 consumes.
- [x] `src/Pipeline/FetchRunnerResult.php` -- add `array $bySource` -- the split travels to the caller.
- [x] `public_html/index.php` -- `/cron/work` JSON gains `by_source` -- operator sees the per-source outcome of a slice.
- [x] `tests/Pipeline/FetchRunnerTest.php` -- one case per new I/O & Edge-Case Matrix row + regression cases stay green -- edge-case coverage.
- [x] `tests/FrontControllerIntegrationTest.php` -- assert `by_source` in the `/cron/work` response -- wiring verified.

**Acceptance Criteria:**
- Given a `FetchRunner` slice where one instrument returns `NotFound` or `SchemaMismatch`, when the error occurs, then it is logged per instrument, the job → `failed`, and the runner proceeds to the next job (no Epic 1 regression).
- Given a source that errors on every call during a slice, when the slice runs, then the other source's data still lands for every job, and the per-source split (`FetchRunnerResult.bySource`, the `slice complete` log line, `/cron/work` `by_source`) shows each source's `ok` / `not_found` / `schema_mismatch` / `transient` with `SchemaMismatch` counted separately.
- Given `composer test`, then the full suite is green with no regressions and the per-job `ingest_run` `ok_count` / `fail_count` semantics are unchanged.

## Implementation Notes

- **2026-09-10 — implemented.**
  - `src/Pipeline/FetchRunner.php` — `$bySource` accumulator initialised from `self::SOURCES` (every source reports, all-zero when never called), declared beside the other per-slice counters before the job loop. In the per-source `try`: `++$bySource[$source]['ok']` immediately after `fetch()` returns (before `upsert`, per "a `fetch()` returned, upsert or not"). The `catch (Transient)` **drops its `break`** — it records `++…['transient']`, sets `$sawTransient`, logs the existing `warning`, and falls through so the next source is still attempted. The old `catch (NotFound | SchemaMismatch)` is split into two arms (`catch (NotFound)` → `not_found`, `catch (SchemaMismatch)` → `schema_mismatch`), each pushing `$failingSources[]` and logging the unchanged single `warning` (`error` = exception class). The `:182` job-state precedence block and the outer `catch \Throwable` arm are untouched. After `runs->record('fetch', …)` (signature unchanged) a single `logger->info('fetchrunner: slice complete', ['run_date', 'by_source', 'claimed', 'done', 'failed', 'reopened'])` line, then `$bySource` is passed as the new 6th arg to `FetchRunnerResult`.
  - `src/Pipeline/FetchRunnerResult.php` — added `public array $bySource` as the 6th required constructor arg (the sole construction site is `FetchRunner::run()`) with the `array<string, array{ok,not_found,schema_mismatch,transient}>` docblock.
  - `public_html/index.php` — `/cron/work` `send_json` payload gains `'by_source' => $result->bySource`; nothing else changed. `RunRepository` / `IngestRun` / migrations / `owner_count_daily` untouched (no schema change — Story 2.6 persists this).
  - Tests: `tests/Pipeline/FetchRunnerTest.php` gains a `sliceCompleteContext()` helper and four cases — source down for the whole slice (every OK row written, all reopened, `by_source` split, asserted equal to the `slice complete` context), `SchemaMismatch` vs `NotFound` separate buckets, clean-slice `slice complete` breakdown, empty-queue all-zero buckets. `tests/FrontControllerIntegrationTest.php::testWorkRunsOneSliceAndReturnsCounts` asserts the all-zero `by_source` shape (the seeded instrument has null ids → both sources skipped).
- **2026-09-10 — review pass 1 patches (R1–R7):** the two hard-failure `catch` arms
  collapsed to one `catch (NotFound | SchemaMismatch $e)` + a one-line bucket selector;
  `rows_written` added to the `slice complete` line; `FetchRunnerResult.bySource` `ok`
  docblock clarified re: an upsert that throws; `FetchRunnerTest` gained cases for
  `Transient`+`NotFound` on one job (→ `pending`), I/O matrix row 1 (mixed slice), a bucket
  assertion on the unexpected-`\Throwable` case, and the `slice complete` line on the
  timebox-`break` path (exactly once, buckets = jobs run); `docs/deploy.md` now shows the
  `/cron/work` JSON response incl. `by_source`.
- **Verification:** `composer test` — 217 tests / 926 assertions green. `php -l` clean on all
  changed files. `EndToEndSmokeTest` (sums `fetch` `ok_count`/`fail_count`) unchanged.

## Spec Change Log

## Review Triage Log

Review pass 1 (2026-09-10) — blind-hunter, edge-case-hunter, verification-gap. No `bad_spec` /
`intent_gap`; the implementation follows the spec. All findings are implementation-level.

| # | Finding | Verdict | Route | Evidence |
|---|---------|---------|-------|----------|
| R1 | Removing the `Transient` `break` newly makes "`Transient` from one source **and** `NotFound`/`SchemaMismatch` from the other, same job" reachable, and no test asserts the precedence (`$sawTransient` wins → job `pending`, not `failed`). A regression in the `:182` block would permanently `fail` a retryable job and silently drop one source's data, suite green. | medium | patch | verification-gap (pre-verified) + blind. Every existing `$sawTransient` case has `$failingSources` empty. Fix: a `FetchRunnerTest` case — avanza `Transient` + nordnet `NotFound` → `pending`, `reopened=1`, `failed=0`, `bySource.avanza.transient=1`, `bySource.nordnet.not_found=1`. |
| R2 | I/O matrix row 1 ("One `NotFound` mid-slice": job 1 `failed`, job 2 `done`, `by_source.avanza={ok:1,not_found:1}`) has no covering test — `testSchemaMismatchAndNotFoundLandInSeparateBuckets` fails **both** jobs, so a source with `ok` and `not_found` both non-zero across one slice is never exercised. | low | patch | blind + verification-gap. Matrix Test Audit requires every row covered. Fix: a 2-job slice, avanza ok on job 1 + `NotFound` on job 2, nordnet ok both. |
| R3 | I/O matrix row 5 (unexpected `\Throwable`) test does not assert `$result->bySource` stays all-zero for the throwing source — `++…['ok']` sits right after `fetch()`, so a future edit moving it ahead of the call would inflate `ok` undetected. | low | patch | edge-case-hunter + verification-gap. Fix: extend `testAnUnexpectedThrowFailsOnlyThatJobAndTheSliceContinues` with a bucket assertion. |
| R4 | The `fetchrunner: slice complete` `info` line omits `rows_written`, though the field is on `FetchRunnerResult` and in the `/cron/work` JSON — it is the single structured line Story 2.6 consumes. | low | patch | blind. Fix: add `'rows_written' => $rowsWritten` to the line's context. |
| R5 | `catch (NotFound $e)` and `catch (SchemaMismatch $e)` are ~10 near-identical lines each (same `warning` call, differing only in the bucket key) — two copies to keep in sync. | low | patch | blind. Fix: one `catch (NotFound \| SchemaMismatch $e)` with `$e instanceof SchemaMismatch ? 'schema_mismatch' : 'not_found'` and a single `warning`. (The spec Code Map said "split into two arms" for counting; a one-line selector achieves the same with no duplication.) |
| R6 | No test asserts the `slice complete` line still fires on the timebox-`break` path, nor that it is logged **exactly once** (`sliceCompleteContext()` silently returns the first match). | low | patch | blind + edge-case-hunter. Fix: extend a timebox test to assert the line fires with `by_source` covering only the jobs that ran; add `assertCount(1, …)` for the message in one case. |
| R7 | `docs/deploy.md` documents the `/cron/work` request `curl` but never the response body (unlike the `/` healthcheck), so the new operator-facing `by_source` key has no documented shape. | low | patch | blind. Fix: one line in `docs/deploy.md` showing the `/cron/work` JSON incl. `by_source`. |
| R8 | `FetchRunnerResult.bySource.ok` is incremented before `OwnerCountRepository::upsert()`; a `PDOException` from `upsert()` leaves `ok=1` on a job that ends `failed` with no row. | low | reject | Spec-consistent — `ok` is defined as "a `fetch()` returned, upsert or not" and semantically means "the source answered". A mid-upsert `PDOException` is rare, hits the outer `catch (\Throwable)` → `error` log + job `failed`; the `slice complete` line carries both. A one-line docblock note is folded into R5's patch as courtesy. |
| — | `/cron/work` `by_source` is only asserted through the HTTP JSON for the all-zero case (both integration tests seed null-id instruments). | low | reject | The full nested shape (`['avanza'=>$zero,'nordnet'=>$zero]`) **is** asserted through `send_json`/`json_encode`; buckets always carry 4 int keys so none can encode as `[]`; non-zero int values surviving a verbatim pass-through is not a realistic regression, and the unit tests pin the computed values. |
| — | `sprint-status.yaml` (`in-progress`) vs spec (`in-review`) disagree. | — | reject | Expected mid-workflow state — the sprint file syncs to `review` at step-05. |

**Patches:** R1–R7 → re-engaged implementation subagent. No `bad_spec` / `intent_gap`;
`review_loop_iteration` stays 0.

## Design Notes

One map, initialised from `SOURCES` so every source reports:

```php
$bySource = [];
foreach (array_keys(self::SOURCES) as $s) {
    $bySource[$s] = ['ok' => 0, 'not_found' => 0, 'schema_mismatch' => 0, 'transient' => 0];
}
// in the source loop, per outcome:
//   success             → ++$bySource[$source]['ok']
//   catch Transient      → ++$bySource[$source]['transient'];       (no break)
//   catch NotFound       → ++$bySource[$source]['not_found'];        $failingSources[] = $source;
//   catch SchemaMismatch → ++$bySource[$source]['schema_mismatch'];  $failingSources[] = $source;
```

The job-state block (`:182`) is unchanged — `$sawTransient` still comes from the `Transient`
catch, `$failingSources` from the other two. Only the `break` is removed and the one catch is
split in two for counting.

## Verification

**Commands:**
- `composer test -- --filter FetchRunnerTest` -- new + regression cases green.
- `composer test` -- full suite green, no regressions.
- `php -l src/Pipeline/FetchRunner.php` -- no syntax errors.

**Manual checks:**
- `tests/EndToEndSmokeTest.php` still sums `fetch` `ok_count`/`fail_count` to the queue
  done/failed counts (the per-job `ingest_run` columns are unchanged).
