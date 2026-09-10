---
title: 'Story 2.2: UniverseSync — daglig avstämning'
type: 'feature'
created: '2026-09-10'
status: 'in-progress'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: '3b6b657da633b7944e55a23463b2e72f38a411c4'
context:
  - _bmad-output/implementation-artifacts/epic-2-context.md
  - _bmad-output/implementation-artifacts/spec-2-1-borsdata-adapter-for-universumlistan.md
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Story 2.1 built `BorsdataAdapter::listUniverse()` but nothing consumes it.
The `instrument` table is still the hardcoded ~20-ISIN seed list, `/cron/refill` still
enqueues that seed list, and there is no mechanism to add newly listed companies, retire
delisted ones, or follow list changes (LC↔MC↔SC↔First North).

**Approach:** Add `UniverseSync` in `src/Pipeline/`. Once per night it calls
`BorsdataAdapter::listUniverse()`, reconciles the `instrument` table against it
(additions with `first_seen`, delistings marked inactive via `last_seen`, list/name
changes applied), triggers Avanza/Nordnet id resolution for instruments still missing a
cached id, logs the churn, and appends one `ingest_run` row. `/cron/refill` runs
`UniverseSync` then `Enqueue` over the **active** universe; the seed list is retired from
the nightly path.

## Boundaries & Constraints

**Decisions (resolved 2026-09-10):**
- **Live Börsdata verification:** the operator runs `php bin/show-universe.php` against the
  real API and pastes the output. Before `UniverseSync` is implemented, the agent pins
  `BorsdataAdapter::labelForMarketName()` / `EQUITY_TYPE_IDS` to the real response, records
  the market names / type ids / per-list counts in the Story 2.1 Implementation Notes, and
  re-captures `tests/Adapter/BorsdataAdapterTest.php` fixtures from the real payloads.
  Implementation of this story does not start until that output is in hand.
- **Id-resolution volume:** `UniverseSync` timeboxes id resolution. It applies all
  reconciliation first (pure SQL, fast), then resolves missing Avanza/Nordnet ids until a
  wall-clock budget is spent — new setting `universe.resolve_timebox` (seconds, default
  `45`, read via the same positive-number-or-default pattern as `FetchRunner`'s settings).
  Instruments left unresolved are picked up on subsequent `/cron/refill` passes; this is
  exactly how `FetchRunner` already treats a null cached id.
- **Churn logging:** `UniverseSync` appends one `ingest_run` row with
  `run_type='universe_sync'`, `instrument_count` = active universe size after the sync,
  `ok_count` = added (incl. reactivations), `fail_count` = removed (delistings). The
  "changed", "reactivated", "ids_resolved" and "ids_failed" counts go in a structured
  `info` log line `universe sync complete {...}`. No schema migration.

**Always:**
- `instrument` is written **only** by `UniverseSync` now (AD-3), including the id lookup
  and caching. The interim `SourceIdResolver` / `bin/resolve-ids.php` become tools
  `UniverseSync` reuses, not independent writers in the nightly path.
- A `SchemaMismatch` / `Transient` from `BorsdataAdapter` aborts the sync with **no**
  change to `instrument` and **no** `ingest_run` row — a bad Börsdata response must never
  cause a mass delisting (AD-7). Logged at `warning`; `/cron/refill` returns a non-2xx or
  an explicit error status and `Enqueue` does not run.
- Reconciliation is idempotent: a second `UniverseSync` on an unchanged Börsdata list
  makes zero row changes and its `ingest_run` row shows 0/0/0.
- Active = `last_seen IS NULL`. A delisted ISIN gets `last_seen` = today (Stockholm),
  is never deleted, and is excluded from `Enqueue`. An ISIN that reappears in Börsdata
  after being delisted is reactivated (`last_seen` back to `NULL`); `first_seen` is
  never overwritten.
- New Avanza/Nordnet id lookups go through the existing `SourceAdapter::resolveId()`
  port (Story 1.3); a `NotFound` / `SchemaMismatch` / `Transient` is logged per
  instrument and the sync continues — an unresolved instrument simply waits for a later
  sync (FetchRunner already skips + logs a null id).
- Nordnet id column is already `VARCHAR(64)` (migration `20260909140100`) — no new
  migration for column width.

**Never:**
- No change to `BorsdataAdapter`, `UniverseEntry`, `owner_count_daily`, `FetchRunner`
  fetch behaviour, or the queue state machine.
- No lazy id resolution in `FetchRunner` (AD-3) — unchanged.
- No historical backfill, no deletion of `instrument` or `owner_count_daily` rows (NFR7).
- No `owner_count_daily.ingest_run_id` link, no per-source run-log columns, no log
  rotation — all Story 2.6.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| New ISIN | in Börsdata list, not in `instrument` | row inserted, `first_seen`=today, `last_seen`=NULL; id resolution attempted for both sources | per-source `warning` on lookup failure, sync continues |
| Delisted ISIN | active in `instrument`, absent from Börsdata list | `last_seen`=today, row kept, excluded from next `Enqueue` | N/A |
| List change | ISIN in both, Börsdata `list` differs | `instrument.list` updated; counted as "changed" | N/A |
| Name change | ISIN in both, Börsdata `name` differs | `instrument.name` updated (it is the resolve search string); counted as "changed" | N/A |
| Reactivation | ISIN with `last_seen` set reappears in Börsdata list | `last_seen`→NULL, `first_seen` untouched; counted as "added" | N/A |
| Unchanged | ISIN in both, same list + name | no write | N/A |
| Börsdata fails | `listUniverse()` raises `SchemaMismatch` or `Transient` | no `instrument` write, no `ingest_run` row, `Enqueue` skipped | `/cron/refill` → error status; `warning` logged |
| Idempotent re-run | `UniverseSync` twice, list unchanged | 2nd run: 0 writes, `ingest_run` row 0 added / 0 removed / 0 changed | N/A |

</frozen-after-approval>

## Code Map

- `src/Pipeline/UniverseSync.php` — **new**. Ctor `(BorsdataAdapter, InstrumentRepository,
  array<string,SourceAdapter> $resolvers, SettingsRepository, RunRepository,
  LoggerInterface)`, `run(string $runDate): array`. Mirror `Enqueue`'s shape: UTC
  started/finished `DateTimeImmutable`, `RunRepository` call at the end.
- `src/Pipeline/Enqueue.php:44` — swap `$this->instruments->all()` for `allActive()`;
  nothing else changes.
- `src/Store/InstrumentRepository.php` — add `UniverseSync` writer methods `insert()`,
  `updateListAndName()`, `markInactive(isin,lastSeen)`, `reactivate(isin)`, and
  `allActive(): array<string,Instrument>` (WHERE `last_seen IS NULL`). `upsertSeed()`
  stays (local/dev seeding only); `cacheAvanzaId()`/`cacheNordnetId()` reused.
- `src/Store/SourceIdResolver.php:43` — `resolve()` loops instruments missing an id and
  caches hits write-once. Add an optional deadline (`?float $deadline`) so `UniverseSync`
  stops mid-loop; return `resolved`/`failed`/`remaining`. `bin/resolve-ids.php` passes none.
- `src/Store/RunRepository.php:32` — `record()` `run_type` is free-form (`'universe_sync'`
  already noted in `20260909160000_create_ingest_run.php:25`); no signature change.
- `src/Pipeline/FetchRunner.php:139` — already skips + `warning`s a null cached id; the
  timeboxed resolution relies on that (no change here).
- `public_html/index.php:88` — `/cron/refill` branch: build Guzzle `Client`,
  `BorsdataAdapter` (`$config->borsdataApiKey()`), Avanza/Nordnet adapters; run
  `UniverseSync` then `Enqueue`. `AdapterError` from the sync → log, error JSON status,
  **skip** `Enqueue`. `/cron/work` untouched.
- `src/Adapter/{BorsdataAdapter,UniverseEntry}.php` — consumed as-is; `LIST_*` map 1:1 to
  `instrument.list`.
- `tests/Store/StoreTestCase.php:85` — DDL mirror needs **no** change (reuses `ingest_run`
  columns; `nordnet_instrument_id` already `VARCHAR(64)` via migration `20260909140100`).
- `bin/universe-sync.php` — **new** thin SSH wrapper (pattern: `bin/resolve-ids.php`).
- `docs/deploy.md`, `_bmad-output/implementation-artifacts/deferred-work.md` — add
  `borsdata.api_key` to the config checklist; annotate the "BLOCKS Story 2.2" entry done.
- Tests to update: `tests/Pipeline/EnqueueTest.php`, `tests/Pipeline/FetchRunnerTest.php`,
  `tests/Store/InstrumentRepositoryTest.php`, `tests/FrontControllerIntegrationTest.php`;
  reuse `tests/Support/FakeSourceAdapter.php`.

## Tasks & Acceptance

**Execution:**
- [ ] `src/Store/InstrumentRepository.php` -- add `insert()`, `updateListAndName()`, `markInactive()`, `reactivate()`, `allActive()` -- `UniverseSync` is the single `instrument` writer (AD-3).
- [ ] `src/Pipeline/UniverseSync.php` -- new pipeline filter: fetch Börsdata universe, diff against `instrument`, apply additions / delistings / list+name changes / reactivations, then timeboxed id resolution (`universe.resolve_timebox`, default 45s), append one `run_type='universe_sync'` `ingest_run` row + a structured churn log line, return counts -- the story.
- [ ] `src/Pipeline/Enqueue.php` -- enqueue `allActive()` instead of `all()` -- delisted instruments must not get nightly jobs.
- [ ] `src/Store/SourceIdResolver.php` -- add an optional deadline so `UniverseSync` can stop resolution mid-loop; return resolved / failed / remaining counts -- bounds first-sync cost.
- [ ] `public_html/index.php` -- `/cron/refill` runs `UniverseSync` then `Enqueue`; a Börsdata `AdapterError` aborts before `Enqueue` with an error status -- AC: refill drives the real universe, the seed list is retired.
- [ ] `bin/universe-sync.php` -- thin SSH wrapper around `UniverseSync` (pattern: `bin/resolve-ids.php`) -- manual run / first bootstrap.
- [ ] `docs/deploy.md` -- add `borsdata.api_key` to the config checklist -- operator setup.
- [ ] `tests/Pipeline/UniverseSyncTest.php` -- one case per I/O & Edge-Case Matrix row against a fake `BorsdataAdapter` + fake resolvers + real MariaDB (`StoreTestCase`) -- edge-case coverage.
- [ ] Update `tests/Pipeline/EnqueueTest.php`, `tests/Store/InstrumentRepositoryTest.php`, `tests/FrontControllerIntegrationTest.php` -- reflect active-only enqueue and the new `/cron/refill` wiring -- no regressions.

**Acceptance Criteria:**
- Given a stored list and a fresh Börsdata list, when `UniverseSync` runs, then additions get `first_seen` + an id-resolution attempt, absent ISINs get `last_seen` and go inactive (not deleted), and list changes update `instrument.list`.
- Given `UniverseSync` has run, when the run is logged, then `ingest_run` (or the agreed dedicated row) carries the added / removed / changed counts.
- Given `/cron/refill`, when it is called after `run_after`, then it runs `UniverseSync` then `Enqueue` over the active universe and the seed list is not consulted.
- Given a `SchemaMismatch` from Börsdata, when `/cron/refill` runs, then no `instrument` row changes, no `ingest_run` row is written, and `Enqueue` does not run.
- Given `composer test`, then the full suite is green with no regressions.
- Given `bin/seed-instruments.php`, then it still works for local dev (seed rows are just ordinary active instruments a later real sync will delist).

## Implementation Notes

## Spec Change Log

## Review Triage Log

## Design Notes

Diff shape — build three sets from `array_keys($borsdata)` vs `$instruments->allActive()`
and the full `$instruments->all()` (to spot reactivations):

```php
$fresh   = [];                       // isin => UniverseEntry
foreach ($universe as $e) { $fresh[$e->isin] = $e; }
$stored  = $this->instruments->all();          // isin => Instrument (active + inactive)
$added = $removed = $changed = $reactivated = 0;
foreach ($fresh as $isin => $e) {
    $cur = $stored[$isin] ?? null;
    if ($cur === null)            { $this->instruments->insert($isin, $e->name, $e->list, $runDate); $added++; $toResolve[] = $isin; }
    elseif ($cur->lastSeen !== null) { $this->instruments->reactivate($isin); $added++; if ($cur->list !== $e->list || $cur->name !== $e->name) $this->instruments->updateListAndName(...); }
    elseif ($cur->list !== $e->list || $cur->name !== $e->name) { $this->instruments->updateListAndName($isin, $e->list, $e->name); $changed++; }
}
foreach ($stored as $isin => $cur) {
    if ($cur->lastSeen === null && !isset($fresh[$isin])) { $this->instruments->markInactive($isin, $runDate); $removed++; }
}
```

Then id resolution over every row still missing an id (not just `$toResolve` — a prior
sync may have left failures), via `SourceIdResolver` with a `microtime(true) + timebox`
deadline. `record()` the `ingest_run` row last, only on success (`instrument_count`=active
count, `ok_count`=added, `fail_count`=removed); the structured `info` line carries
`changed`, `reactivated`, `ids_resolved`, `ids_failed`, `ids_remaining`.

`run_date` here is the Stockholm `Y-m-d` already computed by the `/cron/refill` handler —
pass it in, do not recompute (matches `Enqueue`).

## Verification

**Commands:**
- `composer test -- --filter UniverseSyncTest` -- expected: all matrix cases green.
- `composer test` -- expected: full suite green, no regressions.
- `php -l src/Pipeline/UniverseSync.php` -- expected: no syntax errors.

**Manual checks:**
- `php bin/show-universe.php` against the live API (operator-run, prerequisite) — record
  real market names / type ids / per-list counts in the Story 2.1 Implementation Notes;
  refresh adapter fixtures; pin `labelForMarketName()` / `EQUITY_TYPE_IDS`.
- After a local `bin/universe-sync.php` run against a seeded DB: `bin/show-runs.php` shows
  a `universe_sync` row; a delisted seed ISIN has `last_seen` set; `SELECT COUNT(*) FROM
  instrument WHERE last_seen IS NULL` matches the Börsdata active count.
- Grep `src/Pipeline` for `guzzle` / `apiservice` / `borsdata.se` — expected: no hits
  (all Börsdata HTTP stays in the adapter, AD-1).
