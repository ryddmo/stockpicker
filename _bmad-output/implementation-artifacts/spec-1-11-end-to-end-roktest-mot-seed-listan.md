---
title: "Story 1.11: End-to-end smoke test against the seed list"
type: "feature"
created: "2026-09-09"
status: "done"
route: "dispatch"
review_loop_iteration: 0
baseline_commit: "323c4da675d55ee587687cd4ec5d8673117e4ae0"
context:
  - "{project-root}/AGENTS.md"
  - "{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md"
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Every Epic 1 layer is built and tested in isolation, but nothing exercises the
whole pipe — `Enqueue` → repeated time-boxed `FetchRunner` slices → `owner_count_daily` +
`ingest_run` — as one run over the seed list across multiple `/cron/work` passes, the way a
night actually happens. The epic's exit criterion ("proven end-to-end against the ~20-ISIN
seed list") has no automated guard.

**Approach:** Add one automated end-to-end test that runs the full pipe against the real
test DB with only the `SourceAdapter` boundary faked, driving `Enqueue` then a loop of
`FetchRunner` slices until `work_queue` drains, and asserting the three epic AC scenarios:
per-source coverage for most seed instruments, an unresolved-id instrument skipped without
stopping the run, and a `Transient` job that recovers across passes.

**Decisions (2026-09-09):**

- **Scope: automated test only.** The story ships `tests/EndToEndSmokeTest.php` and the
  sprint-status move. No operator script and no runbook changes. Running the pipe live
  against Loopia and eyeballing real Avanza/Nordnet rows stays part of the deferred first
  production deploy walkthrough (`deferred-work.md`), which Story 1.11 does not block on.
- **No production `bin/` or `src/` changes.** The test asserts against `owner_count_daily`
  / `ingest_run` with direct `$this->pdo` queries, as the sibling store tests do — no new
  repository method.

## Boundaries & Constraints

**Always:**

- The test extends `StoreTestCase`: real docker-compose MariaDB, self-skips when it is
  unreachable so `composer test` stays green without a DB.
- It drives the real `Enqueue`, `FetchRunner`, `QueueRepository`, `OwnerCountRepository`,
  `RunRepository`. Only `avanza` / `nordnet` are `FakeSourceAdapter`; `FetchRunner` gets an
  injected no-op sleeper (like `FetchRunnerTest`).
- The drain loop calls `FetchRunner::run($runDate, timebox)` repeatedly until
  `QueueRepository::countByStatus($runDate)` has no `pending` and no `claimed`, with a hard
  pass cap (~50) that fails with a clear message rather than looping forever.
- The full `InstrumentSeeder::LIST` (20 ISIN) is the universe; assertions use "most"
  (a fixed high threshold, e.g. ≥ 16) not "all", matching the epic AC wording.

**Never:**

- Do not modify pipeline, adapter, front-controller, repository, migration, `settings`, or
  `bin/` code, and do not touch `docs/`. This is a test-only story.
- Do not hit live Avanza/Nordnet from the test; do not perform or depend on the first
  production deploy.
- Do not add CI, a new dependency, or a new `Support/` helper if `FakeSourceAdapter` and
  the `FetchRunnerTest` patterns suffice.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Behavior | Error Handling |
|---|---|---|---|
| Full drain, sources OK | 20 seed instruments enqueued, both fake sources return rows | after ≥1 pass: `countByStatus` has only `done`/`failed`, summing to 20; ≥16 instruments have an `owner_count_daily` row per source for the run date; one `enqueue` + ≥1 `fetch` `ingest_run` rows whose `instrument_count`/`ok_count`/`fail_count` reconcile with the queue outcome | — |
| Unresolved source id | one seed instrument seeded with both cached ids `NULL` | that job ends `done`, writes no row, never blocks the loop; a `warning` containing `cached source id is null` names it | job never stuck `pending`/`claimed` |
| Transient then recovery | one instrument's nordnet fake throws `Transient` on first fetch, returns a row afterwards | job reopens to `pending`, a later pass drives it to `done` with both rows written; loop still finishes under the cap | `warning` containing `transient from source` logged |
| Pass cap exceeded | (guard) queue never drains | test fails with a message naming the remaining statuses | `self::fail()` |

</frozen-after-approval>

## Code Map

- `tests/Pipeline/FetchRunnerTest.php` -- the pattern to follow: `seedInstruments()` raw
  inserts, `enqueueAll()`, `runner()` with an injected `fn (float) => …` sleeper,
  `fetchLogRows()`, `queueStatus()`, `ownerRowCount()`, `TestHandler` logger. Reuse the
  shape; add the multi-pass loop.
- `tests/Support/FakeSourceAdapter.php` -- `fetchResponses` is one FIFO queue per adapter,
  drained across all instruments and passes in claim order (`work_queue.id` asc, which is
  `Enqueue` insert order = `InstrumentRepository::all()` order). Queue responses in that
  order. `fetchCalls` records every ISIN hit. Do not modify.
- `tests/Store/StoreTestCase.php` -- base class; `$this->pdo`, mirrored schema for
  `instrument`, `settings`, `owner_count_daily`, `work_queue`, `ingest_run`.
- `src/Pipeline/Enqueue.php` -- `run($runDate)` enqueues one job per `all()` instrument,
  writes one `enqueue` `ingest_run` row.
- `src/Pipeline/FetchRunner.php` -- `run($runDate, $timeboxSeconds)`: reopen stale → claim
  ≤ `batch_size` (default 25) → per job fetch avanza+nordnet → transition → one `fetch`
  `ingest_run` row. `Transient` reopens the job; null cached id skips that source with a
  `warning`; all-sources-skipped ⇒ `markDone`.
- `src/Store/QueueRepository.php` -- `countByStatus($runDate)` returns `status => count`;
  drain = keys ⊆ `{done, failed}`.
- `src/Store/RunRepository.php` -- `forRunDate($runDate)` returns `IngestRun[]` oldest
  first; fields `runType`, `instrumentCount`, `okCount`, `failCount`.
- `src/Store/InstrumentSeeder.php` -- `LIST` (20 rows `[isin, name, list]`); the universe.
- `_bmad-output/implementation-artifacts/sprint-status.yaml` -- move Story 1.11 to
  `in-progress` at start, `review` at hand-off.

## Tasks & Acceptance

**Execution:**

- [x] `tests/EndToEndSmokeTest.php` -- new `StoreTestCase` test. Seed all 20
      `InstrumentSeeder::LIST` rows: 18 with non-null fake `avanza_orderbook_id` /
      `nordnet_instrument_id`, 1 with both `NULL` (unresolved-id case), 1 whose nordnet
      fake throws `Transient` once then succeeds. Queue `FakeSourceAdapter` responses in
      claim order. `Enqueue::run($runDate)`, then loop `FetchRunner::run($runDate, 60.0)`
      (injected no-op sleeper) until `countByStatus($runDate)` keys ⊆ `{done, failed}` or
      the ~50-pass cap trips (`self::fail()` with the remaining statuses). Assert every I/O
      matrix row: final queue composition, per-source `owner_count_daily` coverage ≥ 16,
      reconciling `ingest_run` counters (one `enqueue`; ≥1 `fetch`; summed `ok_count` +
      `fail_count` consistent with the queue), the null-id and transient `warning` lines
      via a `TestHandler`, and that the run took ≥ 2 passes (the `Transient` forces it).
- [x] `_bmad-output/implementation-artifacts/sprint-status.yaml` -- Story 1.11 →
      `in-progress` when work starts.

**Acceptance Criteria:**

- Given the docker-compose test DB is up, when `composer test` runs, then
  `EndToEndSmokeTest` drives `Enqueue` plus a loop of `FetchRunner` slices over the 20-ISIN
  seed list until `work_queue` holds only `done`/`failed` jobs, and passes.
- Given no test DB, when `composer test` runs, then `EndToEndSmokeTest` self-skips and the
  suite stays green.
- Given the run, when it completes, then ≥ 16 seed instruments have an `owner_count_daily`
  row per source for the run date and the `ingest_run` `enqueue` + `fetch` rows' counters
  reconcile with the final queue state.
- Given a seed instrument with unresolved ids and one with a first-pass `Transient`, when
  the loop runs, then it still drains within the pass cap, both jobs end non-`pending`/
  `claimed`, and the null-id and transient warnings are logged.
- Given the whole change, when reviewed, then only `tests/` and `sprint-status.yaml` are
  touched — no production code.

## Implementation Notes

- Delivered `tests/EndToEndSmokeTest.php` (one `StoreTestCase` test, ~27 assertions)
  plus the `sprint-status.yaml` move. No `src/`, `bin/`, migration, or `docs/` changes.
- Seed universe is the full 20-ISIN `InstrumentSeeder::LIST` sorted by ISIN =
  `InstrumentRepository::all()` order = `Enqueue` insert order = `claimBatch` (`ORDER BY id`)
  order, so a single FIFO `FakeSourceAdapter::fetchResponses` queue per source stays in
  sync with fetch order. Index 18 = the both-ids-`NULL` instrument, index 19 = the
  transient-once instrument (kept last so its pass-2 re-fetch responses append cleanly).
- `settings` table is left empty on purpose: `FetchRunner` falls back to its hard
  `DEFAULTS` (batch_size 25, stale_after 900, rate 0.5) silently when a key is absent.
- Verified: `composer test` green (152 tests) with docker-compose MariaDB up;
  `EndToEndSmokeTest` self-skips (via `StoreTestCase`) with no DB; run 3× stable.

## Spec Change Log

## Review Triage Log

Review loop 1 (2026-09-09) — three layers (blind-hunter, edge-case-hunter, verification-gap).

| # | Finding | Verdict | Route | Evidence |
|---|---|---|---|---|
| 1 | Per-source coverage asserted `>= 16` while the all-fakes universe deterministically yields exactly 19 covered + 0 failed; a pipeline regression dropping ≤3 seed instruments to `failed` passes every assertion green (verification-gap V1, blind-hunter #4). | medium | patch | Confirmed: `COUNT(*) == perSourceCovered*2`, `keys ⊆ {done,failed}`, `sum == 20`, and `sumFail == failedCount` all hold with `perSourceCovered == 16, failedCount == 3`. Tighten to `assertSame(19, $perSourceCovered)` + `assertSame(0, $failedCount)`; still satisfies the frozen "≥ 16" AC. |
| 2 | `SELECT COUNT(*) FROM owner_count_daily` is unscoped; relies on `StoreTestCase` recreating the schema each test (edge-case-hunter E1, blind-hunter #8). | low | patch | Currently correct (schema dropped+recreated in `setUp`, all rows share `as_of_date` = run date). Folded into finding 1's patch: scope the count to the run date. |
| 3 | Owner-count assertions check row existence only, never a persisted value; a smoke test for "the whole pipe works" should prove the payload round-trips (blind-hunter #6). | low | patch | Folded in: assert one instrument's persisted `number_of_owners` per source equals the fake's value. |
| 4 | `sprint-status.yaml` moves the story to `in-progress`, not `review`; Code Map says "`review` at hand-off" (blind-hunter #1). | false | reject | `in-progress` is the correct value while step-04 review is running; the `review` transition is a later workflow step, not part of this diff. |
| 5 | Claim-order = ISIN-sort coupling is asserted nowhere; fragile if `InstrumentRepository::all()` collation ever diverges from PHP `strcmp` (blind-hunter #2, edge-case-hunter E5). | low | reject | ISINs are uppercase ASCII alphanumerics; `utf8mb4_general_ci` and `strcmp` agree on that set. A desync cannot cause a false pass — `FakeSourceAdapter` throws `LogicException` → job `failed` → assertions fail. Sibling `FetchRunnerTest` relies on the same ordering without an explicit guard. |
| 6 | `settings` seeding undocumented (blind-hunter #3). | false | reject | `FetchRunner::numericSetting()` returns the hard default silently on an absent key; the empty table is the intended path and is now noted in Implementation Notes. |
| 7 | Null-id / transient `warning` assertions check only the message substring, not that the record names the instrument/source (edge-case-hunter E2/E3). | low | reject | Matrix row 3 asks only for the substring; row 2 says "names it" but the `FetchRunner` already logs `isin` in context and sibling `FetchRunnerTest` uses the same substring-only check. Not worth diverging from the established pattern. |
| 8 | "End-to-end" test skips the `/cron/work` HTTP layer — token auth and the run-after-time guard (blind-hunter #5). | false | reject | Out of scope by the frozen Intent and Boundaries (only the `SourceAdapter` boundary is faked; drive `Enqueue`+`FetchRunner` directly). Story 1.9 covers the cron endpoint. |
| 9 | Frozen "Decisions" says assertions use "direct `$this->pdo` queries … no new repository method", but the test calls `OwnerCountRepository::get()` etc. (blind-hunter #7). | false | reject | No production surface added (diff touches no `src/`); every method used pre-exists. The decision's real constraint — add no repository method — is met. Frozen wording cannot be edited and the mismatch is cosmetic. |
| 10 | `avanza` rows pass a null source timestamp, `nordnet` rows pass `$ts` — asymmetry unexplained (blind-hunter #10). | low | reject | Intentional and correct: it exercises both `OwnerCountRepository::asOfDate()` precedence branches (source timestamp vs run-date override). Cosmetic; a clarifying comment is added in the patch. |
| 11 | `EndToEndSmokeTest` self-skips without a manually-started DB and the repo has no CI, so the epic's only end-to-end guard runs in no unattended path (verification-gap V2). | medium (unverified harm) | defer | Real but pre-existing: every `StoreTestCase` test behaves this way and the frozen spec bars adding CI. Recorded in `deferred-work.md`; hand-off note says to run `docker compose up -d && composer test` once before accepting the epic. |
| 12 | `assertGreaterThanOrEqual(2, $passes)` looser than `assertSame(2, $passes)` (verification-gap V4). | low | reject | The spec Design Notes deliberately specify "≥ 2 passes"; `== 2` would be brittle to legitimate batch/timebox changes. Fix would edit this build's spec. |

Routing: no `intent_gap` / `bad_spec` → no loopback. Findings 1–3 + a comment for 10 → one patch to the implementation subagent. Finding 11 → `deferred-work.md`.

## Design Notes

- `FakeSourceAdapter` has a single FIFO `fetchResponses` queue per adapter (no per-ISIN
  keying), so responses must be enqueued in the exact order `FetchRunner` will fetch:
  claim order is `work_queue.id` asc, i.e. `Enqueue` insert order, i.e.
  `InstrumentRepository::all()` order. `FetchRunnerTest` does this by seeding responses per
  job in the same order — mirror it. The transient case adds a wrinkle: that instrument is
  fetched again on a later pass, so its second (success) response must sit after the first
  pass's remaining responses. Keeping the transient instrument last in the universe makes
  the ordering tractable.
- With `batch_size` 25 (default) all 20 jobs are claimed in pass 1; only the reopened
  transient job needs pass 2. Asserting "≥ 2 passes" both proves the multi-pass path and
  guards against a regression that completes everything in one slice.
- Prefer looping `FetchRunner::run()` directly over `EndpointFixture`: the test must inject
  fake adapters and per-pass responses, which the HTTP path cannot.

## Verification

**Commands:**

- `composer test` -- expected: all pass; `EndToEndSmokeTest` self-skips without the
  docker-compose MariaDB.
- `docker compose up -d && composer test` -- expected: `EndToEndSmokeTest` runs and passes.

**Manual checks:**

- Confirm `git status` after the change lists only `tests/EndToEndSmokeTest.php` and
  `_bmad-output/implementation-artifacts/sprint-status.yaml`.
