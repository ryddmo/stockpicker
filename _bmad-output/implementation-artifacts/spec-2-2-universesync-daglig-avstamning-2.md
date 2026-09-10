---
title: 'Story 2.2: UniverseSync — daglig avstämning (v2, Avanza-listning)'
type: 'feature'
created: '2026-09-10'
status: 'done'
route: 'dispatch'
review_loop_iteration: 1
baseline_commit: '03c84f9de713d0ef4c3835d58143471c584a0165'
context:
  - _bmad-output/implementation-artifacts/epic-2-context.md
  - _bmad-output/implementation-artifacts/spec-2-2-universesync-daglig-avstamning.md
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `AvanzaUniverseAdapter::listUniverse()` returns the real Stockholm universe as
`list<UniverseEntry>{avanzaOrderbookId, name, list}`, but nothing consumes it. `instrument`
is still the hardcoded ~20-ISIN seed list, `/cron/refill` enqueues `InstrumentRepository::all()`,
`last_seen` is never written, and there is no path for additions, delistings, or list changes.

**Approach:** Add `UniverseSync` in `src/Pipeline/`. Nightly it fetches the Avanza listing and
reconciles `instrument` against it, matched on the cached Avanza `orderbookId`: additions (with
`first_seen`), delistings (`last_seen`, never deleted), list/name changes, reactivations. The
listing carries no ISIN, so each new `orderbookId` costs one `GET /_api/market-guide/stock/{id}`
call for its ISIN plus a Nordnet id lookup; this HTTP resolution is wall-clock-timeboxed in the
cron path and resumed on later passes. `/cron/refill` runs `UniverseSync` then `Enqueue` over the
active universe; the seed list leaves the nightly path. One `ingest_run` row records the churn.

## Boundaries & Constraints

**Always:**
- `instrument` is written **only** by `UniverseSync` in the nightly path (AD-3) — inserts,
  `last_seen`, `list`/`name`, both id caches. `upsertSeed()` stays for dev seeding;
  `SourceIdResolver`/`bin/resolve-ids.php` stay as an unchanged interim manual tool.
- Match on `orderbookId` (`instrument.avanza_orderbook_id` ↔ `UniverseEntry.avanzaOrderbookId`).
  A stored row with null `avanza_orderbook_id` is never delisted — it is reconciled only once
  its `orderbookId` is discovered (ISIN resolved from a listing entry, matched to the row by
  ISIN, id then cached).
- Active = `last_seen IS NULL`. Delisting sets `last_seen` = the run date (Stockholm `Y-m-d`,
  passed in, never recomputed) and keeps the row. A reappearing `orderbookId` is reactivated
  (`last_seen`→NULL); `first_seen` is never overwritten. Reconciliation is idempotent
  (unchanged listing → 0 writes, `ingest_run` `0/0/0`).
- Abort with **zero** `instrument` writes and **no** `ingest_run` row, then skip `Enqueue`, when:
  (a) `listUniverse()` raises `SchemaMismatch`/`Transient` — log `warning`; or (b) would-be
  delistings exceed `universe.max_delist` (setting, **default 25**) — log `error`. Guard (b) is
  evaluated before any write. `/cron/refill` returns HTTP 200 `{"status":"universe_sync_failed"}`
  in both cases (AD-7). A legitimate delisting larger than the cap needs a one-run operator bump.
- Per-instrument `resolveIsin()` and `NordnetAdapter::resolveId()` each catch
  `NotFound`/`SchemaMismatch`/`Transient`, log `warning`, and continue. An unresolved ISIN → not
  inserted this run (retried next). A missing Nordnet id → cached null, picked up later
  (`FetchRunner` already skips + logs a null cached id).
- Cron path: `run()` takes a wall-clock budget (`universe.resolve_timebox` setting, **default
  45** s). Once spent, no further `market-guide`/Nordnet calls; remaining new `orderbookId`s
  deferred to the next `/cron/refill` (universe converges unattended over ~3–4 weeks).
  Delistings and list/name changes (pure SQL) always apply in full. `bin/universe-sync.php`
  passes **no** budget — it is the recommended one-time first-run bootstrap (~20–30 min, SSH).
  Outgoing calls are strictly serial, spaced by `settings.rate.avanza` / `settings.rate.nordnet`.
- No DB schema change — `avanza_orderbook_id`, `nordnet_instrument_id`, `first_seen`,
  `last_seen` all exist. `ingest_run.run_type` = `'universe_sync'` (free-form column).

**Never:**
- No change to `listUniverse()`/`queryList()`, `AvanzaAdapter`, `NordnetAdapter` (beyond the new
  `AvanzaUniverseAdapter::resolveIsin()`), `UniverseEntry`, `SourceIdResolver`, `FetchRunner`,
  `owner_count_daily`, or the queue state machine. No lazy id resolution in `FetchRunner` (AD-3).
- No migration, no backfill, no row deletion (NFR7). No `owner_count_daily.ingest_run_id`, no
  per-source run-log columns, no alarm flag, no log rotation (all Story 2.6). No new `/cron/*` route.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Behavior | Error Handling |
|----------|--------------|-------------------|----------------|
| New orderbookId | in listing, no row matches by id or by resolved ISIN | ISIN resolved, row inserted `first_seen`=runDate, id cached, Nordnet lookup attempted; "added" | ISIN unresolved → `warning`, skip, retry next run; Nordnet fail → `warning`, null cached |
| Seed row adopted | resolved ISIN already has a row with null `avanza_orderbook_id` | id cached onto that row; list/name diff applied; "changed" if anything differed | as above |
| Delisted | active row, cached id absent from listing | `last_seen`=runDate, kept, excluded from `Enqueue`; "removed" | N/A |
| List/name change | id in both, `list` or `name` differs | column updated (`name` is the Nordnet search string); "changed" | N/A |
| Reactivation | row with `last_seen` set, its id reappears | `last_seen`→NULL, `first_seen` untouched; "added" | N/A |
| Unchanged / null-id no match | id in both same; or active null-id row whose ISIN never resolves from the listing | no write (null-id row stays active, never delisted) | N/A |
| Listing fails | `listUniverse()` raises | 0 writes, no `ingest_run`, `Enqueue` skipped, `{"status":"universe_sync_failed"}` | `warning` |
| Mass-delist guard | would-be delistings > `universe.max_delist` | 0 writes, no `ingest_run`, `Enqueue` skipped | `error` with the count |
| Budget spent | cron run, budget exhausted mid-resolution | reconciliation + delistings applied; new orderbookIds deferred; `ingest_run` written with counts so far | N/A |

</frozen-after-approval>

## Code Map

- `src/Adapter/AvanzaUniverseAdapter.php` — **add** `public resolveIsin(string $orderbookId):
  string`: `requestJson($http,'GET',BASE_URI.'/_api/market-guide/stock/'.rawurlencode($id))` in
  `withOneRetry()`; 404→`NotFound`; blank/missing `isin`→`SchemaMismatch` (via `schemaMismatch()`,
  logs `warning`). New `STOCK_PATH` const. Don't touch `listUniverse()`/`queryList()`. Introduce
  a narrow `UniverseLister` port (`listUniverse()` + `resolveIsin()`) that this class implements,
  so `UniverseSync` and its tests depend on a contract (mirrors `SourceAdapter`) — `final` stays.
- `src/Adapter/AvanzaAdapter.php:107` `marketGuide()` — request-shape + 404-mapping reference
  only; not shared (Story 2.1 chose no shared base class).
- `src/Store/InstrumentRepository.php` — **add** `allActive(): array<string,Instrument>`
  (`WHERE last_seen IS NULL`), `insert($isin,$name,$list,$firstSeen)` — **must be a no-op on a
  duplicate PK** (`INSERT … ON DUPLICATE KEY UPDATE isin = isin`, or equivalent) so a
  within-run / concurrent duplicate ISIN never raises an uncaught `PDOException`,
  `updateListAndName($isin,$list,$name)`, `markInactive($isin,$lastSeen)` (`… WHERE isin=:isin
  AND last_seen IS NULL`), `reactivate($isin)` (`SET last_seen=NULL …`), and
  `setAvanzaId($isin,$id)` — an **unconditional** setter (unlike write-once `cacheAvanzaId()`)
  used only for the id-rotation case in the walk. Reuse `all()`, `get()`, `cacheAvanzaId()`,
  `cacheNordnetId()`.
- `src/Store/Instrument.php` — VO already has `avanzaOrderbookId`, `nordnetInstrumentId`,
  `firstSeen`, `lastSeen`. No change.
- `src/Pipeline/UniverseSync.php` — **new**. Ctor `(UniverseLister $universe, SourceAdapter
  $nordnet, InstrumentRepository, SettingsRepository, RunRepository, LoggerInterface,
  ?callable $sleep=null)`. `run(string $runDate, ?float $timeboxSeconds=null): array`. Mirror
  `Enqueue`: UTC `started`/`finished`, `RunRepository::record()` last and only on success.
  Read `rate.avanza` / `rate.nordnet` / `universe.max_delist` **once** at the top of `run()`
  (not per call, like `FetchRunner`).
- `src/Pipeline/Enqueue.php:44` — `$this->instruments->all()` → `->allActive()`. Nothing else.
- `src/Pipeline/FetchRunner.php:236` `numericSetting()` — copy this positive-number-or-default
  pattern for `universe.resolve_timebox` / `universe.max_delist` / `rate.*` (unseeded keys
  absent → default). Don't modify `FetchRunner`.
- `src/Store/RunRepository.php` — `record('universe_sync',$runDate,$started,$finished,
  $activeCountAfter,$added,$removed)`. Signature unchanged.
- `public_html/index.php:88` `/cron/refill` — before `new Enqueue(...)`: build `$http=new
  Client(['timeout'=>20,'connect_timeout'=>10])`, `new UniverseSync(new
  AvanzaUniverseAdapter($http,$logger), new NordnetAdapter($http,$logger), $instruments,
  $settings, $runs, $logger)`; `try { $sync->run($runDate, universe_resolve_timebox($settings)); }
  catch (\Stockpicker\Error\AdapterError $e) { $logger->warning(...); send_json(200,
  ['status'=>'universe_sync_failed','run_date'=>$runDate]); break; }`. New `universe_resolve_timebox()`
  helper: positive-number-or-`45.0` from `universe.resolve_timebox` (mirror the existing
  cron helpers' style). Keep every existing guard. `/cron/work` untouched.
- `bin/universe-sync.php` — **new** thin SSH wrapper (pattern: `bin/resolve-ids.php`): bootstrap,
  `Client` (`timeout` 30), repos + adapters + settings, `UniverseSync::run($stockholmToday)` with
  no timebox, `printf` counts, `catch (\Throwable)` → `$logger->error`, STDERR, `exit(1)`.
- `tests/Store/StoreTestCase.php:81` `createSchema()` — DDL mirror already has
  `instrument.avanza_orderbook_id` + `last_seen` and `ingest_run` unchanged; verify, expect no edit.
- `tests/Support/FakeSourceAdapter.php` — reuse as the Nordnet double. Universe adapter +
  `resolveIsin`: a local `FakeUniverseLister` in `UniverseSyncTest`, and Guzzle `MockHandler`
  (`AdapterTestCase`) for the `resolveIsin` unit test.
- `tests/Support/EndpointFixture.php` — needs an **opt-in** seam so one `/cron/refill` happy-path
  integration test can reach a working `listUniverse()` (e.g. an env var read by the universe
  adapter for its base URI, pointed at a second `php -S` serving canned screener + market-guide
  JSON; keep the default env hermetic). Do not leave the route's success path uncovered at the
  integration level.
- Update: `tests/Pipeline/EnqueueTest.php`, `tests/Store/InstrumentRepositoryTest.php`,
  `tests/FrontControllerIntegrationTest.php` (keep a happy-path `/cron/refill` assertion,
  add the failure-branch one).

## Tasks & Acceptance

**Execution:**
- [ ] `src/Adapter/AvanzaUniverseAdapter.php` -- add `resolveIsin()` + a `UniverseLister` port the class implements -- `UniverseSync` needs an ISIN per new listing entry; all Avanza universe HTTP stays here (AD-1) and the pipeline depends on a contract.
- [ ] `src/Store/InstrumentRepository.php` -- add `allActive()`, dup-PK-safe `insert()`, `updateListAndName()`, `markInactive()`, `reactivate()`, unconditional `setAvanzaId()` -- `UniverseSync` is the single nightly `instrument` writer (AD-3); id rotation and concurrent runs must not crash it.
- [ ] `src/Pipeline/UniverseSync.php` -- new pipeline filter: fetch listing, mass-delist guard (before any write), reconcile the listing (additions / list+name changes / reactivations / **orderbookId rotation for a stable ISIN**), a **timeboxed id-resolution pass over every active row still missing an id** (not only newly discovered ones), delistings, one `run_type='universe_sync'` `ingest_run` row + a structured churn `info` log line, return counts -- the story.
- [ ] `src/Pipeline/Enqueue.php` -- enqueue `allActive()` not `all()` -- delisted instruments get no nightly jobs.
- [ ] `public_html/index.php` -- `/cron/refill` runs `UniverseSync` (timebox via a new `universe_resolve_timebox()` positive-or-45 helper) then `Enqueue`; an `AdapterError` / mass-delist abort returns `{"status":"universe_sync_failed"}` and skips `Enqueue` -- refill drives the real universe; seed list leaves the nightly path.
- [ ] `bin/universe-sync.php` -- thin SSH wrapper, `UniverseSync` with no timebox -- first-run bootstrap / manual reconcile.
- [ ] `tests/Pipeline/UniverseSyncTest.php` -- one case per I/O & Edge-Case Matrix row (fake `UniverseLister` + `FakeSourceAdapter` + real MariaDB), **plus**: `count == max_delist` does not trip; an orderbookId rotation for a stable ISIN reconciles (no flip-flop); a prior run's failed Nordnet id is retried and cached on the next run; `numericSetting()` unusable value → warning + default -- edge-case coverage.
- [ ] `tests/Adapter/AvanzaUniverseAdapterTest.php` -- add `resolveIsin` cases (happy, 404→`NotFound`, blank/missing isin→`SchemaMismatch`, 429→retry, retry-also-fails→`Transient`) -- adapter contract.
- [ ] `tests/FrontControllerIntegrationTest.php` + `tests/Support/EndpointFixture.php` -- add the opt-in universe-source seam; keep a happy-path `/cron/refill` case asserting `{status:ok,created:N}` + a `universe_sync` and an `enqueue` `ingest_run` row + `work_queue` over `allActive()`; add the failure-branch case -- the production nightly endpoint's wiring stays verified.
- [ ] `tests/FrontControllerTest.php` or a small unit test -- pin `universe_resolve_timebox()` (unseeded→45.0, `"30"`→30.0, `"0"`/`"-1"`/non-numeric→45.0) -- mirrors `FetchRunnerTest`'s helper test.
- [ ] `tests/Pipeline/EnqueueTest.php`, `tests/Store/InstrumentRepositoryTest.php` -- active-only enqueue + the new repo methods -- no regressions.
- [ ] `docs/deploy.md` -- document `bin/universe-sync.php` (bootstrap) and `universe.resolve_timebox` / `universe.max_delist`; correct the bootstrap estimate (~740 names × ~4 s serial ≈ 45–50 min) and the unattended-convergence figure (~2 weeks at a 45 s/pass budget); state that null-`avanza_orderbook_id` seed rows are **adopted by ISIN or linger active**, not delisted -- operator setup.

**Acceptance Criteria:**
- Given a seeded `instrument` table and a fresh Avanza listing, when `UniverseSync` runs to completion, then every listing `orderbookId` **whose ISIN resolves** has an active row (inserted or adopted) with `avanza_orderbook_id` cached (an `orderbookId` whose `market-guide` ISIN lookup 404s / returns blank is counted in `ids_failed` and retried next run); every active row missing a Nordnet id has had a lookup attempted this run; active rows whose `orderbookId` left the listing have `last_seen` set and are kept; list/name diffs are applied.
- Given an active row whose cached `avanza_orderbook_id` changed in the listing (same resolved ISIN, new orderbookId), when `UniverseSync` runs, then the row's cached id is updated to the new one, the row is **not** delisted, and a second unchanged run makes zero writes (no flip-flop).
- Given a prior run left an active row with a null `nordnet_instrument_id` (a transient failure), when the next `UniverseSync` runs within budget, then it re-attempts and caches the id.
- Given a successful run, then exactly one `ingest_run` row `run_type='universe_sync'` carries `instrument_count`=active count after, `ok_count`=added incl. reactivations, `fail_count`=removed, and an `info` log line carries changed / reactivated / ids_resolved / ids_failed / deferred.
- Given `/cron/refill` after `run_after`, then it runs `UniverseSync` then `Enqueue` over `allActive()`, returns `{"status":"ok","created":N}`, and the seed list is not consulted.
- Given `listUniverse()` raises `SchemaMismatch` or the mass-delist guard trips, when `/cron/refill` runs, then no `instrument` row changes, no `ingest_run` row, `Enqueue` does not run, response `{"status":"universe_sync_failed"}`.
- Given `composer test`, then the full suite is green with no regressions; `bin/seed-instruments.php` still seeds local dev.

## Implementation Notes

**2026-09-10 — implemented (after the pass-1 `bad_spec` loopback), review passes 1–2 applied.**

- `UniverseLister` port (`listUniverse()` + `resolveIsin()`); `AvanzaUniverseAdapter implements`
  it, stays `final`. Base URI overridable via `STOCKPICKER_AVANZA_UNIVERSE_BASE_URI` / ctor arg
  — the integration seam only; production unchanged.
- `resolveIsin()`: `GET market-guide/stock/{id}` via `withOneRetry`; 404 → `NotFound` (through
  `getPrevious()`), blank/missing `isin` → `SchemaMismatch`.
- `UniverseSync::run()` follows Design Notes steps 1–6. `ok_count` = `added + reactivated`,
  `fail_count` = `removed`. `changed` / `reactivated` / `ids_resolved` / `ids_failed` /
  `deferred` / `active_after` in the `info` "universe sync complete" line. Within-run ISIN
  dedupe (`$seenIsin`) so two entries mapping to one ISIN don't flip-flop `setAvanzaId`.
- Step 5 retry pass covers every active row missing a Nordnet id (the "picked up later"
  mechanism). Avanza-id gaps are not retried here (id comes only from a listing match).
- `universe_resolve_timebox()` in `public_html/cron_helpers.php` (split out to be unit-tested):
  positive-or-45.0, capped at 120.0 s.
- `InstrumentRepository`: `insert()` is `ON DUPLICATE KEY UPDATE isin = isin` (dup-PK-safe);
  `reactivate()` guarded `AND last_seen IS NOT NULL`; `setAvanzaId()` unconditional (rotation).
- Deferred to `deferred-work.md`: `ingest_run` churn richness + `ok/fail` overload (Story 2.6);
  a canned Nordnet source for `EndpointFixture`; a `UNIQUE` index on `avanza_orderbook_id`.
- Verification: `composer test` 210 green (from 166); `php -l` clean; no HTTP/endpoint strings
  in `src/Pipeline/`.

## Spec Change Log

- **2026-09-10 — review pass 1 (bad_spec loopback, `review_loop_iteration` 0→1).**
  - **Triggering findings:** (F1) the implemented walk re-attempts id resolution only for
    newly discovered orderbookIds; an active row matched by orderbookId every run never gets a
    failed Nordnet id retried, contradicting the spec's own "picked up later" boundary and
    `epic-2-context.md` ("skipped and logged until the next `UniverseSync`"). (F3) an
    orderbookId change for a stable ISIN put the old id in `$delist`, adopted the new id via a
    `cacheAvanzaId()` that no-ops on the non-null column, then delisted the live row — a
    permanent nightly reactivate/re-delist flip-flop with a stale cached id.
  - **Amended (non-frozen only):** Design Notes now specify (a) a timeboxed id-resolution pass
    over **every** active row still missing an id, and (b) explicit orderbookId-rotation
    handling (unconditional `setAvanzaId()`, drop the ISIN from `$delist`). Code Map + Tasks
    add `InstrumentRepository::setAvanzaId()`, a dup-PK-safe `insert()`, reading `rate.*` once
    per run, building the Nordnet-lookup VO from the persisted row, an opt-in
    `EndpointFixture` seam so the `/cron/refill` happy path stays integration-tested, a
    `universe_resolve_timebox()` helper test, and `docs/deploy.md` number/behaviour
    corrections. New ACs for id rotation and the retry pass.
  - **Known-bad state avoided:** silent per-instrument owner-count gaps that never self-heal;
    a self-perpetuating churn loop; an uncaught `PDOException` → HTTP 500 with partial writes;
    a production endpoint whose happy-path wiring ships unverified.
  - **KEEP (worked well, must survive re-derivation):** the `UniverseLister` port +
    `AvanzaUniverseAdapter implements` it (`final` preserved); `resolveIsin()` with
    404→`NotFound`, blank→`SchemaMismatch`, `withOneRetry`; the mass-delist guard evaluated
    from the orderbookId set **before any write**; `record('universe_sync', …)` last and only
    on success; `Enqueue` → `allActive()`; `InstrumentRepository` methods incl. `markInactive`
    guarded on `last_seen IS NULL`, `reactivate` leaving `first_seen`; the injected-`$sleep`
    throttle test seam; `numericSetting()` mirroring `FetchRunner`; `bin/universe-sync.php`
    (no timebox); the `docs/deploy.md` restructure around `bin/universe-sync.php`; the full
    34-case I/O-matrix test structure.

## Review Triage Log

Review pass 1 (2026-09-10) — blind-hunter, edge-case-hunter, verification-gap.

| # | Finding | Verdict | Route | Evidence |
|---|---------|---------|-------|----------|
| F1 | Known-orderbookId branch (`UniverseSync.php:163-180`) only reactivates / updates list+name; a failed Nordnet id is never retried for a row that matches by id every run. "Picked up later" has no mechanism. | medium | bad_spec | Contradicts `epic-2-context.md` ("an instrument without an id is skipped and logged **until the next UniverseSync**") and the frozen boundary. Manual `bin/resolve-ids.php` exists but the spec promised automatic. |
| F3 | orderbookId rotation for a stable ISIN → old id in `$delist`, new id adopted (`cacheAvanzaId` no-ops on non-null column), row delisted though live → permanent nightly flip-flop, stale cached id. | medium | bad_spec | Verified `UniverseSync.php:204-251`; not in the I/O matrix; untested. Rare trigger, severe + silent + self-perpetuating when hit. |
| F2 | `$stored` captured once (`:109`), never refreshed; two listing entries resolving to one ISIN, or a concurrent `/cron/refill` + `bin/universe-sync.php`, → second `insert()` → duplicate-PK `PDOException`, not `AdapterError`, uncaught → HTTP 500, partial writes, no `ingest_run` row. | medium | patch (folded into loopback) | Bootstrap script is explicitly operator-run and may overlap the hourly cron. Fix: re-`get($isin)` before insert + dup-PK-safe `insert()`. |
| F4 | `/cron/refill` happy path lost all test coverage — `testRefillEnqueuesTodaysStockholmDate` rewritten to the failure branch, `EndpointFixture` pins `HTTPS_PROXY` to `.invalid` so no integration test can reach `listUniverse()`. Handler wiring (ctor args, order, `{status:ok,created:N}`) unverified. All 3 reviewers. | medium | patch (folded) | Verified in the diff. A regression in the production nightly endpoint would ship green. Fix: opt-in `EndpointFixture` seam + one success assertion. |
| F7 | `throttle()` calls `numericSetting()` → `settings->get()` per source per new instrument (~1480 `SELECT`s / ~740 warning lines if misconfigured, during a bootstrap). | low | patch (folded) | Verified `UniverseSync.php:296-307`. `FetchRunner` reads its settings once per run. |
| F15 | New/adopted rows pass a synthetic `Instrument` VO (`firstSeen=$runDate`, forced-null id/lastSeen) to `nordnet->resolveId()`; works only because `resolveId` reads just `name`+`isin`. | low | patch (folded) | Verified `:226`. Fix: build the VO from the persisted row. |
| F17 | `universe_resolve_timebox()` (positive-or-45 helper in `index.php`) has no test; `FetchRunner`'s sibling helper is pinned by `FetchRunnerTest`. | low | patch (folded) | grep: only `index.php` + `docs/deploy.md`. Inverting the guard ships green. |
| F18 | `UniverseSync::numericSetting()` unusable-value branch (warning + fallback) untested — only the valid `'1'` is exercised for `max_delist`. | low | patch (folded) | A broken guard could make the cap 0 (refill always aborts) or unbounded. |
| F13b | No boundary test that `count(delist) == max_delist` does **not** trip while `> max_delist` does. | low | patch (folded) | `if (count($delist) > $maxDelist)` is correct but unverified at the boundary. |
| F9 | `docs/deploy.md` bootstrap estimate "~20–30 min" understates (~740 × ~4 s serial ≈ 45–50 min); "~3–4 weeks" convergence unreconciled with a 45 s/pass budget (~2 weeks). The **frozen** boundary carries the same optimistic figures. | low | patch (folded); frozen wording flagged to human | Arithmetic. deploy.md is fixable now; the frozen block's "~3–4 weeks"/"~20–30 min" needs a human edit (conservative, not wrong). |
| F10 | `docs/deploy.md` says seed rows "the first real `UniverseSync` will delist if they are not in the listing" — but null-`avanza_orderbook_id` rows are never delisted (adopted by ISIN or linger). | low | patch (folded) | Verified against the code's `$inst->avanzaOrderbookId !== null` delist condition. |
| F6 | Listing failure / mass-delist guard skips `Enqueue` for the whole universe and returns HTTP 200 (Loopia cron sees success); no alert. | low | reject | Deliberate spec design; hourly `/cron/refill` retries bound the loss to ≤1 h; alerting is Story 2.6 ("no alarm flag" — frozen Never). Fix > direct correction. |
| F5 / blind#6 / edge#3 | Reconciliation writes aren't wrapped in a transaction; a mid-run non-`AdapterError` throw or a hard SAPI timeout kill leaves `instrument` partially reconciled with no `ingest_run` row. | low | reject | Additions/updates/reactivations are idempotent & resumable; the only bad window is a hard kill during the short I/O-free delist loop or before `record()`; `/cron/work` already proves ~75 s SAPI headroom vs the 45 s budget. A real fix needs new transaction plumbing across two repos. Noted for a future hardening story. |
| — | blind#6b: a non-`AdapterError` from `resolveIsin()` / `listUniverse()` escapes the `catch (AdapterError)`. | false | reject | Both are `: string` / `AdapterError`-only by contract; a non-`AdapterError` implies a code bug, not a runtime path. |
| F8 | Deferred new-id resolution has no tier priority — a new Large Cap waits behind First North names (orderbookId order). | low | reject | The recommended `bin/universe-sync.php` bootstrap resolves everything at once; new LC listings are rare; tier ranking adds ordering logic beyond a direct correction. Noted. |
| F11 / F12 | `universe.resolve_timebox` default lives only in `index.php`'s helper, not `UniverseSync::DEFAULTS`; `ok_count`/`fail_count` overload (a healthy 30-name delisting shows `fail_count=30`); `changed`/`reactivated`/`ids_*` are log-only, not queryable. | low | defer | Run-log richness is explicitly Story 2.6 (frozen Never: "no per-source run-log columns — all Story 2.6"). Recorded so 2.6 revisits the `universe_sync` row semantics. |
| F13a | Mass-delist guard throws `SchemaMismatch` (a business-rule guard, not a shape error) — muddies `SchemaMismatch` logs / catch-sites. | low | reject | Works (`SchemaMismatch extends AdapterError`, message is explicit); a dedicated exception class adds public surface for no functional gain. |
| F14 | `resolveIsin()` 404 handling catches `SchemaMismatch` then inspects `getPrevious()` for a 404, rather than mapping the status directly like the cited `AvanzaAdapter::marketGuide()`. | low | reject | Works and is tested (`testResolveIsin404RaisesNotFound`); fragility to a future `requestJson()` change is speculative; the refactor is more than a direct correction. |
| F16 | edge-case-hunter (confidence low): mass-delist guard comment claims the listing is "complete" but `listUniverse()` supposedly raises only on a fully-empty target list, so a truncated response could silently delist ≤ `max_delist` live names. | false | reject | Refuted — Story 2.1's `queryList()` has a truncation guard (`count` vs `totalNumberOfOrderbooks`) plus a "rows but no usable STOCK rows" guard, both tested (`testTruncatedTargetListResponseRaisesSchemaMismatch`). Residual risk is Story 2.1's surface, triaged there. |
| F19 | `bin/universe-sync.php` untested; `printf` keys not pinned to `run()`'s return shape. | low | reject | Keys verified to match; consistent with the untested `bin/resolve-ids.php`; loud failure mode. |

**Patches:** F2, F4, F7, F9, F10, F13b, F15, F17, F18 — all folded into this loopback's spec
amendment so re-derivation handles them. **Defer:** F11/F12 → `deferred-work.md`. No
`intent_gap`. `review_loop_iteration` 0 → 1.

Review pass 2 (2026-09-10, post-loopback) — blind-hunter, edge-case-hunter, verification-gap.
No `bad_spec` / `intent_gap`: the re-implementation follows the amended spec; F1/F3 confirmed
fixed (step-5 retry pass; rotation branch + `unset($delist[$isin])`). Remaining findings are
implementation-level.

| # | Finding | Verdict | Route | Evidence |
|---|---------|---------|-------|----------|
| P2-1 | `resolveIsin()`'s outgoing HTTP shape (GET, `/_api/market-guide/stock/{id}`, base-URI seam) is asserted nowhere — `MockHandler` ignores the request, and the `/cron/refill` happy-path integration test pre-seeds all four rows fully matched so `resolveIsin` / `insert` / the Nordnet pass never execute (the canned `market-guide` route + `schema_mismatch` scenario are dead in tests). | medium | patch | verification-gap (pre-verified) + blind + edge. A wrong path / verb / `self::BASE_URI` regression ships green. Fix: a request-asserting `resolveIsin` unit test (`MockHandler::getLastRequest()`); full new-instrument e2e through `/cron/refill` needs a canned Nordnet source too → see P2-defer-1. |
| P2-2 | `testDelistCountEqualToMaxDelistDoesNotTrip` actually exercises `count 2 < cap 3`, never `count == cap`. An off-by-one to `>=` in the guard would ship green and turn every legitimate in-bounds mass delisting into an hourly `universe_sync_failed`. | medium | patch | verification-gap (pre-verified). Fix: 3 seeds, listing keeps `1001`, `universe.max_delist='2'` → delist set size 2 == cap 2; assert success, `removed===2`, one `universe_sync` row. |
| P2-3 | `UniverseSync::numericSetting()`'s non-positive branch (`\|\| $raw + 0 <= 0`) is untested — only the non-numeric half (`'nonsense'`) is hit, and the fallback value (25) is never asserted in force. Dropping that half pins the cap at 0 → permanent abort, no failing test. | medium | patch | verification-gap (pre-verified). Fix: `universe.max_delist='0'` + a few would-be delistings; assert the run completes and the delistings apply. |
| P2-4 | Two listing entries whose `resolveIsin` returns the **same ISIN** (dual line / recently-rotated orderbook): iteration A inserts + caches obId 100; iteration B takes the rotation branch → `setAvanzaId(X,200)` + `unset($delist[X])`; next run flips it back — a permanent per-run `setAvanzaId` flip-flop plus one wasted `resolveIsin` call/run, never converges. | low | patch | edge-case-hunter. Rare trigger, self-perpetuating, one instrument, cached id stays a valid orderbookId for the same security so owner-count fetch still works. Fix: track resolved ISINs within a `run()`; first entry wins, a later entry with an already-handled ISIN is skipped + `warning`. |
| P2-5 | `InstrumentRepository::reactivate()` is an unconditional `UPDATE … SET last_seen = NULL WHERE isin = :isin`, unlike its guarded sibling `markInactive()` (`AND last_seen IS NULL`). Inconsistent; loses idempotency protection under a concurrent run. | low | patch | blind-hunter. Fix: add `AND last_seen IS NOT NULL`. Direct correction, matches the sibling. |
| P2-6 | `universe_resolve_timebox()` enforces only a lower bound (`> 0.0`). A `universe.resolve_timebox` set larger than the cron SAPI `max_execution_time` lets `/cron/refill` be killed mid-reconcile (partial writes, no `ingest_run` row). | low | patch | edge-case-hunter + blind. Fix: `min($v, 120.0)` on the positive branch; update `CronHelpersTest`. `/cron/work` proves ~75 s SAPI headroom, so 120 is a safe ceiling. |
| P2-7 | `InstrumentRepository::insert()` docblock claims reliance on "the mysql driver's default `FOUND_ROWS=off` so `rowCount()` would mean 'rows actually inserted'" — the method returns `void`, no caller reads `rowCount()`, and `ON DUPLICATE KEY UPDATE` `rowCount()` semantics don't match that claim. | low | patch | blind-hunter. Fix: drop the misleading sentence. |
| P2-8 | The single `info` "universe sync complete" line is the only durable record of `changed` / `reactivated` / `ids_*` / `deferred` (they are not in `ingest_run`), yet `UniverseSyncTest` only asserts `hasInfoThatContains('universe sync complete')`, never the context values. | low | patch | blind-hunter. Fix: assert the log context (all eight keys + a couple of values) in ≥1 `UniverseSyncTest` case. |
| P2-9 | `docs/deploy.md` still frames `bin/resolve-ids.php` as the backfill for the known large-cap Nordnet misses (Handelsbanken A, Nordea, Epiroc A) — post-2.2 those active rows already have their Avanza id from the listing, and re-running `resolveId()` for Nordnet repeats the exact call `UniverseSync` already made and logged. | low | patch | blind-hunter. Fix: `resolve-ids.php` is legacy/dev-only now; the persistent Nordnet misses need a manual `UPDATE instrument SET nordnet_instrument_id = …`. Also add one line that `universe_sync` `ingest_run` rows use `fail_count` for delistings (the P2-defer overload). |
| P2-10 | Every listing-failure / mass-delist abort is logged twice at inconsistent levels — `UniverseSync` logs `warning`/`error` then rethrows, and `/cron/refill`'s `catch (AdapterError)` logs `warning` again. | low | patch | blind-hunter. Fix: the handler drops its redundant message to `debug` (or omits it), keeping the `send_json`. |
| P2-11 | AC "when `UniverseSync` runs to completion, then **every** listing `orderbookId` has an active row" contradicts the I/O matrix "New orderbookId → ISIN unresolved → skip, retry next run". | low | patch | edge-case-hunter (claim). AC text is non-frozen. Fix: "every listing `orderbookId` **whose ISIN resolves** has an active row". |
| — | `orderbookId` reassignment across **different** instruments (Avanza reuses a retired id for another company) → the matched branch overwrites the surviving row's name/list, `resolveIsin` never called. | low | reject | Needs Avanza to reuse a retired orderbookId — undemonstrated, and no evidence it ever has. The fix (re-verify ISIN in the matched branch) means a `resolveIsin` call for every entry every run, defeating the "known ids cost no HTTP" design. |
| — | Many orderbookId rotations in one listing → `count($delist) > max_delist` at step 2 (before step 4 cancels the rotations) → hourly `universe_sync_failed` though nothing is delisted. | low | reject | The guard-before-any-write invariant is deliberate (AD-7). A mass id-scheme migration is undemonstrated and has the documented one-run `universe.max_delist` bump as recovery. |
| — | Non-`AdapterError` from `run()` after partial writes → HTTP 500, partial state, no `ingest_run` row (`/cron/refill` catches only `AdapterError`). | low | reject | Carried from pass-1 F5. Additions / list-name / reactivations are idempotent & resumable; a `PDOException` is a genuine fault that should surface loudly; hourly retry reconverges. |
| — | `deferred` merges the step-4 (awaiting ISIN) and step-5 (awaiting Nordnet id) backlogs into one number. | low | reject | `ids_failed` context + the log lines distinguish them; richer churn telemetry is Story 2.6 (P2-defer / F11-F12). |
| — | `UniverseSync` takes the (2-method) `SourceAdapter $nordnet` rather than a bespoke `IdResolver` port, unlike the `UniverseLister` it introduced. | low | reject | `SourceAdapter` is already the canonical narrow id-resolution port (Story 1.3), implemented by `FakeSourceAdapter`; a new port ripples into `FetchRunner` / fakes for no functional gain. |
| — | Frozen I/O matrix "New orderbookId" row says "Nordnet lookup attempted" as part of insertion, but resolution moved to step 5 and is skipped past the deadline. | low | reject | Not a defect — the row is inserted and step 5's retry pass (the F1 fix) fills the id on a later run; the frozen "Budget spent" row covers the deadline case. Matrix wording is a touch loose (frozen — a human could tighten it) but the behaviour is correct and tested. |
| — | Count `++` on a 0-row guarded write (`insert()` dup, `markInactive()`/`reactivate()` no-op) over-reports under concurrency. | low | reject | On a clean re-run the code never reaches those branches (`testUnchangedListingMakesZeroWritesAndLogsZeroChurn` proves 0/0/0); a concurrent-race blip in one `ingest_run` row is cosmetic. |
| P2-defer-1 | No canned Nordnet source in `EndpointFixture` — step 5 (Nordnet id resolution) can never be integration-tested without a live call; a full new-instrument `/cron/refill` e2e is therefore out of reach. | — | defer | Test-infra gap. Record so a future test-hardening pass adds a Nordnet stub server alongside the Avanza one. |
| P2-defer-2 | No `UNIQUE` index on `instrument.avanza_orderbook_id`; the whole reconciliation pivots on that column and `setAvanzaId()` is an unconditional writer. | — | defer | Frozen "Never: no migration" blocks the safeguard here. Record for a hardening story: add a unique index (nullable columns allow multiple NULLs, so it is safe). |

**Patches:** P2-1 … P2-11 → re-engaged implementation subagent. **Defer:** P2-defer-1,
P2-defer-2 → `deferred-work.md`. No `bad_spec` / `intent_gap`; `review_loop_iteration` stays 1.

## Design Notes

`run(string $runDate, ?float $timeboxSeconds)` — order matters; every abort path writes nothing:

1. `$listing = listUniverse()` (catch `AdapterError` → `warning`, rethrow). `$fresh` = entries
   keyed by `orderbookId`. `$stored` = `instruments->all()` (active + inactive, by ISIN);
   `$storedByObId` = the subset with a non-null cached id.
2. **Mass-delist guard, before any write.** `$delist` = active rows whose non-null cached id is
   absent from `$fresh` (pure; the listing is complete — `listUniverse()` raises on empty /
   truncated / no-usable-rows). `count($delist) > universe.max_delist` (read once, default 25)
   → `error` log + throw. `count == max_delist` is allowed.
3. Read `rate.avanza` / `rate.nordnet` once. `$deadline = $timeboxSeconds === null ? null :
   microtime(true) + $timeboxSeconds`.
4. **Walk `$fresh` by `orderbookId`:**
   - **Matched in `$storedByObId`:** reactivate if `lastSeen`, `updateListAndName` on a diff.
     Pure SQL, applied regardless of the deadline.
   - **Unmatched, deadline passed:** `deferred++`, continue.
   - **Unmatched, within deadline:** throttle+`resolveIsin($obId)` (catch `AdapterError` →
     `warning`, `idsFailed++`, continue). Then, on the **freshly re-read** row for that ISIN
     (`instruments->get($isin)`):
     - no row → dup-PK-safe `insert($isin, name, list, $runDate)`, `added++`.
     - row with null cached id → adopt: `cacheAvanzaId`; reactivate / `updateListAndName` as needed.
     - row with a **different** non-null cached id (**orderbookId rotation**) →
       `setAvanzaId($isin, $obId)` (unconditional) **and drop `$isin` from `$delist`** — same
       instrument, new id; not a delisting. Reconcile list/name.
5. **Timeboxed id-resolution pass** over `instruments->allActive()` (or the earlier snapshot)
   for every row still `nordnetInstrumentId === null` and not handled in step 4: while within
   `$deadline`, throttle+`nordnet->resolveId($vo)` where `$vo` is built from the **persisted
   row** (`instruments->get`), empty string treated as `NotFound` (like `SourceIdResolver`),
   cache on success (`idsResolved++`) else `warning` + `idsFailed++`. This is what "picked up
   later" means — a transient failure heals on a subsequent run. (Avanza-id gaps are **not**
   retried here — the id comes only from a listing match; the manual `bin/resolve-ids.php`
   covers avanza-id backfill via name search.)
6. `markInactive($isin, $runDate)` for each remaining `$delist` (`removed++`). Then the `info`
   churn line and `runs->record('universe_sync', $runDate, $started, now, count(allActive()),
   $added, $removed)` — last, only on success.

`throttle($source)` keeps a per-source `?float $lastCallAt`, sleeps `max(0, 1/rate - elapsed)`
via the injected `$sleep` (test seam, like `FetchRunner`).

## Verification

**Commands:**
- `composer test -- --filter UniverseSyncTest` -- all matrix + amendment cases green.
- `composer test -- --filter 'AvanzaUniverseAdapterTest|FrontControllerIntegrationTest'` -- green.
- `composer test` -- full suite green, no regressions.
- `php -l src/Pipeline/UniverseSync.php` -- no syntax errors.

**Manual checks:**
- After `php bin/universe-sync.php` on a seeded DB: `php bin/show-runs.php` shows a
  `universe_sync` row; a seed ISIN absent from the listing has `last_seen` set;
  `SELECT COUNT(*) FROM instrument WHERE last_seen IS NULL` ≈ listing size.
- Grep `src/Pipeline` for `guzzle` / `avanza.se` / `market-guide` / `market-stock-filter` -- no hits.
