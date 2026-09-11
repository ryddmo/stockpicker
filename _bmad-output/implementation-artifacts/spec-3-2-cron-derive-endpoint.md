---
title: 'Story 3.2: /cron/derive endpoint'
type: 'feature'
created: '2026-09-11'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: 'e1ec9b60f8201017645054f78c559c2b0e67a4fb'
context:
  - _bmad-output/implementation-artifacts/epic-3-context.md
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The nightly sequence `/cron/refill → /cron/work (looped) → /cron/derive` has no
third leg yet — `Deriver` (Story 3.1) exists but nothing calls it from HTTP, so the Loopia
cron schedule cannot complete the sequence.

**Approach:** Add a `/cron/derive` case to the front controller (`public_html/index.php`),
token-authenticated and run-after-gated the same way as `/cron/refill`/`/cron/work`. The
view-based design (Story 3.1) means there is no materialization step to trigger, so the
endpoint's only job is to write one `ingest_run` row (`run_type: 'derive'`) marking the
nightly sequence's third leg as completed — giving `bin/show-runs.php` parity with the other
two stages (decided 2026-09-11, resolving the "what does derive do" gap; option B of three
considered — a pure no-op was rejected for leaving no run-history trace, and eagerly reading
the view per-instrument was rejected as the exact recompute cost Story 3.1's review deferred
until a real caller existed).

## Boundaries & Constraints

**Always:**
- Same auth shape as the existing cron routes: GET only (405 otherwise), `authorize_cron()`
  first, then reject any query param except `token` (400).
- Reuse the existing `run_after` setting/gate (`SettingsRepository::get('run_after')` +
  `cron_time()`): if now is before the window, respond `{"status":"window_closed", ...}`
  exactly like `/cron/refill`/`/cron/work`, same shape, no new setting key.
- `Deriver`/`DerivedMetricsRepository` stay read-only — this story never writes to
  `owner_count_daily` or `owner_count_metrics`.
- Past the window: write exactly one `ingest_run` row via `RunRepository::record()`
  (`run_type: 'derive'`, `instrumentCount`/`okCount` = `count(InstrumentRepository::allActive())`,
  `failCount: 0` — nothing in this endpoint can fail per-instrument), then respond `200`.
- `Deriver::forInstrument()` is NOT called by this endpoint — it stays unused by any caller
  until Story 3.3; this story only logs that the stage ran, it does not read the view per
  instrument.

**Never:**
- No materialized table, no refresh/rebuild SQL, no change to the view (settled in Story 3.1).
- No new query params, no per-endpoint token or run-after key.
- No loop over instruments calling `Deriver`/the view (rejected — see Approach).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Behavior | Error Handling |
|----------|--------------|-------------------|----------------|
| Valid token, window open | correct `?token=` | `200 {"status":"ok","run_date":...,"instrument_count":N}`; one `ingest_run` row written (`run_type='derive'`, `status='completed'`) | none |
| Missing/wrong token | absent or bad `token` | `403 {"error":"forbidden"}` | none |
| Extra query param | `?token=x&foo=1` | `400 {"error":"invalid request"}` | none |
| Non-GET method | POST | `405 {"error":"method not allowed"}` | none |
| Before `run_after` | now < window | `200 {"status":"window_closed", "run_date": ...}` | none |
| Unknown path with `derive` prefix mistyped | e.g. `/cron/derives` | `404` (existing default case) | none |

</frozen-after-approval>

## Code Map

- `public_html/index.php:51-159` — the hand-rolled path `switch`; add a new
  `case '/cron/derive':` block (own block, not merged into the `/cron/refill`/`/cron/work`
  case — those share Queue/HTTP-adapter setup this route doesn't need). Mirror lines 62-89
  (method check, `authorize_cron`, param check, `run_after` gate) verbatim, then: get
  `$now`/`$runDate` as the existing code does, `count((new InstrumentRepository($pdo))->allActive())`,
  `(new RunRepository($pdo))->record('derive', $runDate, $now, $now, $count, $count, 0)`,
  respond `200`.
- `public_html/index.php:182-198` — `authorize_cron()`/`cron_time()`, reuse as-is, no changes.
- `src/Store/InstrumentRepository.php:44` — `allActive(): array<isin, Instrument>`, for the count.
- `src/Store/RunRepository.php:47-90` — `record(runType, runDate, startedAt, finishedAt,
  instrumentCount, okCount, failCount): int`, the single-call start+finish form; use directly
  (no `Deriver` involvement — it keeps its no-logging contract from Story 3.1).
- `tests/FrontControllerIntegrationTest.php` — add cases mirroring
  `testWorkRunsOneSliceAndReturnsCounts` (~L113) and `testClosedWindowDoesNotRunPipeline`
  (~L164): real DB via `StoreTestCase`, `EndpointFixture::get('/cron/derive?token=test-token')`.
- `tests/FrontControllerTest.php` — add auth/param/method cases mirroring the existing
  `/cron/work` rows (no DB, built-in PHP server).
- `tests/Support/EndpointFixture.php` — reuse `get()` as-is, no changes.

## Tasks & Acceptance

**Execution:**
- [x] `public_html/index.php` -- add `/cron/derive` case: auth + param + run-after gate, then log one `ingest_run` row and respond `200` -- completes the nightly HTTP sequence with run-history parity.
- [x] `tests/FrontControllerTest.php` -- auth (403), extra-param (400), non-GET (405) cases for `/cron/derive` -- parity with `/cron/refill`/`/cron/work` coverage.
- [x] `tests/FrontControllerIntegrationTest.php` -- window-closed case, and a happy-path case asserting the `200` body shape and the resulting `ingest_run` row (`run_type='derive'`, counts, `status='completed'`) -- confirms real end-to-end behavior against MariaDB.

**Acceptance Criteria:**
- Given a valid token and the window open, when `GET /cron/derive` is called, then it responds `200 {"status":"ok",...}` and exactly one `ingest_run` row with `run_type='derive'` exists for that `run_date`.
- Given `composer test`, then the full suite is green; `php -l public_html/index.php` clean.

## Implementation Notes

Added the `/cron/derive` case to `public_html/index.php` as its own `case` block (not
merged with `/cron/refill`/`/cron/work`), mirroring the auth/param/run-after-gate lines from
the existing cases verbatim. Past the gate it does exactly the Code Map's three calls:
`count((new InstrumentRepository($pdo))->allActive())`, then
`(new RunRepository($pdo))->record('derive', $runDate, $now, $now, $count, $count, 0)`, then
responds `200 {"status":"ok","run_date":...,"instrument_count":N}`. No `Deriver` or
`DerivedMetricsRepository` involvement, per the frozen intent — this story only logs that
the stage ran.

`RunRepository::record()`'s single-call start+finish form naturally sets `status='completed'`
(no alarm path — `by_source` defaults to `{}`, `schema_mismatch_count` stays 0), matching the
acceptance criterion without any extra logic.

Test coverage mirrors the existing `/cron/work` rows:
- `tests/FrontControllerTest.php` (no DB, built-in PHP server): missing-token (403),
  wrong-token (403), extra-param (400), non-GET (405). The 405 case needed a small new
  `post()` helper alongside the existing `get()` — no POST helper existed in this file before
  (the `/cron/work` and `/cron/refill` cases had a method check in the code but no test
  exercised it either; added one for `/cron/derive` per this story's explicit edge-case row).
- `tests/FrontControllerIntegrationTest.php` (real MariaDB via `StoreTestCase`):
  `testDeriveWritesOneIngestRunRowAndReturnsCounts` seeds the same 4-instrument matched
  universe fixture used by the refill test, asserts the `200` body shape
  (`status`/`run_date`/`instrument_count`) and the resulting single `ingest_run` row
  (`run_type='derive'`, `instrument_count`/`ok_count=4`, `fail_count=0`,
  `status='completed'`); `testDeriveClosedWindowDoesNotWriteARun` mirrors
  `testClosedWindowDoesNotRunPipeline`, asserting `window_closed` and zero `ingest_run` rows
  when now is before `run_after`.

Nothing in the Code Map or Intent required changes to `Deriver`, `DerivedMetricsRepository`,
`RunRepository`, `InstrumentRepository`, or `EndpointFixture` — all reused as-is.

## Spec Change Log

## Review Triage Log

Review pass 1 (2026-09-11) — blind-hunter, edge-case-hunter, verification-gap.

| # | Finding | Verdict | Route | Evidence |
|---|---------|---------|-------|----------|
| F1 | No in-repo cue (comment) explaining that `/cron/derive` deliberately never calls `Deriver`/`DerivedMetricsRepository` — a future reader could "fix" that as an oversight. | low | patch | blind-hunter. Trivial one-line comment addition, no production logic change. |
| F2 | `src/Pipeline/Deriver.php`'s docblock still says "Story 3.2/3.3 decide whether it grows logic of its own" — stale now that 3.2 (this diff) has settled the question for itself. | low | patch | blind-hunter. Trivial doc update. |
| F3 | `FrontControllerTest::post()` duplicates `get()`'s stream-context/status-parsing logic almost verbatim — a second copy of the header-regex that can drift. | low | patch | blind-hunter. Test-only, trivial extraction (shared `request(method, path)` helper), no public surface added. |
| F4 | Two `/cron/derive` requests that both pass the `run_after` gate for the same `run_date` (concurrent call, or a retried Loopia cron trigger) each write their own `ingest_run` row — no uniqueness/idempotency guard. | medium | defer | blind-hunter + edge-case-hunter (independently, same root cause). Verified: `db/migrations/20260909160000_create_ingest_run.php` has only a non-unique index on `run_date`, no `UNIQUE(run_type, run_date)`. Grepped `Enqueue.php`/`UniverseSync.php` for any duplicate-prevention logic — none exists for `enqueue`/`universe_sync` runs either. Pre-existing systemic gap across every cron route, not introduced by this diff, which only mirrors the established (unguarded) pattern. |
| F5 | The auth/param/`run_after`-gate block is now duplicated a third time (`/cron/refill`+`/cron/work` already shared one duplicate; `/cron/derive` adds a third copy) instead of factored into a shared helper. | — | defer | blind-hunter. The duplication pattern already existed between `/cron/refill` and `/cron/work` before this diff; this story follows the established (duplicative) style per its own Code Map instruction to keep `/cron/derive` as its own block. Refactoring all three routes is a pre-existing concern, not caused by this story. |
| F6 | No test exercises `/cron/derive` with zero active instruments. | false | reject | blind-hunter. Verified: `count()` on an empty array is `0` with no special-casing anywhere in the path, and `RunRepository::record()` has no branch on zero counts — there is no distinct behavior to diverge from what 4-instrument coverage already exercises. Not a functional gap. |
| F7 | No test covers `/cron/derive` with `run_after` missing or malformed, unlike the identical guard already tested for `/cron/refill`/`/cron/work` (`testMissingRunAfterReturnsGenericServerError`, `testMalformedRunAfterReturnsGenericServerError`). | low | patch | verification-gap, pre-verified (grepped `tests/` for `cron/derive`, confirmed no such case exists). Coverage gap only — the guard code itself is correct (mirrors the sibling routes' `throw`), so no current bad outcome; a future edit that weakened it would ship undetected. |
| F8 | `docs/deploy.md:134-135` still says `/cron/derive` "is not registered yet — it stays a 404" and instructs adding the URL-cron job only "then" — now stale, since this diff ships the endpoint. An operator following the runbook as written would never schedule the third cron job, so the nightly sequence's third leg would never run in production. | medium | patch | verification-gap, self-verified via `grep -n "cron/derive" docs/deploy.md`. Fix is a doc-only update (correct the two lines, note the job in the "registered" checklist), no code/spec change. |


## Verification

**Commands run:**
- `php -l public_html/index.php` -- no syntax errors.
- `composer test -- --filter FrontController` -- 20 tests, 84 assertions, green (includes the
  6 new `/cron/derive` cases: 4 in `FrontControllerTest`, 2 in
  `FrontControllerIntegrationTest`).
- `composer test` (full suite) -- 249 tests, 1301 assertions, green, no regressions.
