---
title: 'Story 1.7: Work queue och tidsboxad FetchRunner'
type: 'feature'
created: '2026-09-09'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: '34af66d538088f08b3a7c1f2ead53fdd3be6eb4d'
context:
  - '{project-root}/AGENTS.md'
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The adapters can fetch and `OwnerCountRepository` can store, but nothing drives them. A nightly run must be splittable across many short URL-cron calls without exceeding the web-PHP execution-time limit, and it must be resumable if a call is cut off mid-way (NFR2, AD-5).

**Approach:** A `work_queue` table (`pending → claimed → done | failed`, idempotent per `(isin, run_date)`), an `Enqueue` filter that fills it for a run date from the active universe, and a `FetchRunner` filter that reopens stale `claimed` rows, claims up to `batch_size` jobs atomically, calls the Avanza and Nordnet adapters per job within a ~60–90 s timebox, stores each result via `OwnerCountRepository`, and transitions the job. All queue access goes through a new `QueueRepository` (PDO, no SQL elsewhere).

**Decisions (2026-09-09):**
- **`work_queue` shape:** surrogate `id` BIGINT UNSIGNED AUTO_INCREMENT PK; `UNIQUE (isin, run_date)` carries idempotency; `isin` FK → `instrument(isin)` RESTRICT; `status` VARCHAR(16) NOT NULL DEFAULT `'pending'`; `run_date` DATE NOT NULL; `claimed_at` DATETIME NULL (UTC, also the staleness clock). No `updated_at`.
- **Only `Enqueue` writes `pending`; only `FetchRunner` writes `claimed | done | failed`** (AD-5) — by convention and repository method names, not triggers.
- **Atomic claim, single instance (AD-5, NFR2):** `UPDATE ... SET status='claimed', claimed_at=:now WHERE status='pending' AND run_date=:run_date ORDER BY id LIMIT :batch_size`, then `SELECT ... WHERE status='claimed' AND claimed_at=:now` — `:now` (slice start instant, UTC) tags the rows this slice owns. Safe under NFR2's one-instance guarantee.
- **Stale reopen** runs first each slice: `claimed` rows with `claimed_at < utc_now - queue.stale_after` seconds → `pending`, `claimed_at = NULL`. `queue.stale_after` default `900` is unchanged.
- **Per-job outcome** (single `status`; attempt Avanza then Nordnet): any source `Transient` → job `pending` + `claimed_at` cleared, runner continues; else all attempted sources stored-or-skipped(null id) → `done`; else (≥1 `NotFound`/`SchemaMismatch`, no `Transient`) → `failed` with one `warning` per failing source. Story 2.3 adds per-instrument fault detail.
- **Timebox:** `run(string $runDate, float $timeboxSeconds)`. Before each claimed job, if `microtime(true) - start >= timeboxSeconds`, stop, reopen this slice's still-`claimed` jobs to `pending`, return cleanly.
- **Serial, throttled (NFR3, AD-9):** strictly serial; between two calls to the *same* source, wait `1 / rate.<source>` seconds (`0.5` → 2 s) via an injected `callable(float): void` (default real sleep). No exponential backoff (Story 2.4).
- **`Enqueue` universe:** every `InstrumentRepository::all()` row (seed list in Epic 1; `last_seen` filtering is Epic 2).
- **`run_date`** is a caller-supplied Stockholm `Y-m-d` string; Story 1.9's endpoints pass "today in Stockholm". No clock read in the pipeline.
- **`run()` returns `FetchRunnerResult`** (counts: `claimed`, `done`, `failed`, `reopened`, `rowsWritten`) — the seam Story 1.8's `ingest_run` records. No `ingest_run` / `RunRepository` here.
- **Interim source-id resolver (decided 2026-09-09, folds Story 1.3's deferred persistence into this story):** `bin/resolve-ids.php` — a thin SSH-run script like `bin/seed-instruments.php`. For every `InstrumentRepository::all()` row with a `null` `avanza_orderbook_id` and/or `nordnet_instrument_id`, call the matching adapter's `resolveId()`; on success persist via `InstrumentRepository::cacheAvanzaId()` / `cacheNordnetId()`; on `NotFound` / `SchemaMismatch` / `Transient` log a `warning` and continue. Prints `resolved / skipped / failed`. Idempotent — an already-set id is left alone. This is a scoped, interim second writer of `instrument` (the two id columns only) ahead of Epic 2's `UniverseSync`, which is the accepted exception AD-3 and Story 1.3 already anticipated.
- **`cacheAvanzaId()` / `cacheNordnetId()` are write-once:** `UPDATE instrument SET <col> = :id WHERE isin = :isin AND <col> IS NULL` — never overwrite a resolved id.
- **`as_of_date` fallback fixed at the run's date (decided 2026-09-09, renegotiates Story 1.6's frozen `OwnerCountRepository` surface):** `OwnerCountRepository::upsert(NormalizedRow $row, ?string $asOfDateOverride = null): bool`. Precedence, preserving FR5: **`row.sourceTimestamp` (Nordnet) wins when present** → its Stockholm calendar date; **else `$asOfDateOverride` when given** (the run date); **else `row.fetchedAt`** (today's fallback). So Nordnet still dates from `statistics_timestamp`; only the sourceless case (Avanza) changes — from the fetch clock to the run date — so a fetch/retry straddling local midnight cannot split one night's observation. `asOfDate()` gains the same optional second argument and stays pure/public. `FetchRunner` passes `job.runDate` on every `upsert` call. Story 1.6's spec gets a `Spec Change Log` entry; the `deferred-work.md` item is recorded as addressed there.

## Boundaries & Constraints

**Always:**
- All queue DB access through `QueueRepository` (PDO, no ORM); no SQL in `Enqueue` / `FetchRunner`.
- `Enqueue` is idempotent per `(isin, run_date)` — a second run for the same date inserts nothing and changes no existing row (`INSERT ... ON DUPLICATE KEY UPDATE id = id`).
- `FetchRunner` calls the two adapters through the `SourceAdapter` port only, acts solely on `NormalizedRow` / `SchemaMismatch | NotFound | Transient`, never on a raw exception or HTTP status (AD-2).
- An instrument whose needed cached id is `null` is skipped for that source by `FetchRunner` with a `warning` log line — `FetchRunner` never resolves an id (that is `bin/resolve-ids.php`, run once over SSH before the fetch run) and never looks one up lazily (AD-4 / epic context).
- `resolveId()` stays pure (Story 1.3) — only `bin/resolve-ids.php` persists; the adapters and `FetchRunner` do not.
- Owner-count rows are written only via `OwnerCountRepository::upsert()`; `FetchRunner` never touches `owner_count_daily` SQL directly.
- New store integration tests self-skip when MariaDB is down, like the existing ones; `composer test` stays green with no DB.

**Never:**
- No `ingest_run` / `RunRepository` (Story 1.8), no `/cron/*` endpoints or token check (Story 1.9), no `UniverseSync` / Börsdata (Epic 2).
- No exponential backoff, no rate-limit-aware scheduling beyond the fixed per-source spacing (Story 2.4).
- No per-instrument fault-log detail or per-source `ingest_run` counters (Story 2.3).
- No parallel or bulk source calls (NFR3).
- No Börsdata / Avanza / Nordnet universe list — `bin/resolve-ids.php` only resolves ids for the instruments already in the table.
- The only change to `OwnerCountRepository` is the additive `$asOfDateOverride` parameter — no change to storage semantics, the composite key, or first-write-wins.
- Do not modify `docs/deploy.md` or `bin/deploy.sh` (Story 1.10).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Behavior | Error Handling |
|---|---|---|---|
| Migrate up/down | `phinx migrate` / `rollback` | `work_queue` created with `UNIQUE (isin, run_date)` and the FK; `down()` drops it | Phinx non-zero exit |
| Enqueue fresh | active universe of N, empty queue | N `pending` rows for `run_date`; returns N | — |
| Enqueue re-run | queue already filled for `run_date` | no new rows, no row changed; returns 0 | — |
| Claim within batch | 30 `pending`, `batch_size` = 25 | 25 rows → `claimed` with this slice's `claimed_at`; the SELECT returns exactly those 25 | — |
| Job, both sources OK | claimed job, both ids cached, both adapters return a row | 2 `owner_count_daily` rows (via `upsert`), job → `done` | — |
| Job, one id null | claimed job, `nordnet_instrument_id` null | Avanza stored, Nordnet skipped + `warning`; job → `done` | — |
| Job, source `NotFound` | Avanza `NotFound`, Nordnet OK | Nordnet stored, `warning` logged for Avanza; job → `failed` | job stays `failed` for a later run |
| Job, source `Transient` | Nordnet raises `Transient` | job → `pending`, `claimed_at` cleared; runner continues to next job; no crash | retried on a later slice |
| Timebox hit | timebox elapses after job 3 of 25 | jobs 4–25 reopened to `pending`; `run()` returns cleanly with partial counts | — |
| Stale claimed row | `claimed` row, `claimed_at` older than `queue.stale_after` | reopened to `pending` at slice start, then eligible to claim | — |
| Same-source spacing | two Avanza calls in one slice | ≥ `1 / rate.avanza` seconds of injected wait between them | — |
| Empty queue | no `pending` rows for `run_date` | `run()` claims nothing, returns zero counts, no error | — |
| `upsert` override, Nordnet | `sourceTimestamp` set, override also passed | `sourceTimestamp` wins — `as_of_date` from `statistics_timestamp` (FR5 preserved) | — |
| `upsert` override, Avanza | `sourceTimestamp` null, override = run date D | `as_of_date = D` (not the fetch clock) | — |
| Retry across midnight | Avanza job claimed on `run_date` D, fetched 23:59 then re-fetched 00:05 next day | both writes use `as_of_date = D`; exactly one row for `(isin, 'avanza', D)` | — |
| `resolve-ids`, id present | instrument already has both ids | skipped, no adapter call | — |
| `resolve-ids`, resolves | id `null`, `resolveId()` returns an id | id persisted via `cacheXId()`; counted `resolved` | — |
| `resolve-ids`, `NotFound` | id `null`, source has no match | `warning` logged, id stays `null`, counted `failed`, script continues | script exit 0 |

</frozen-after-approval>

## Code Map

- `db/migrations/20260909140000_create_owner_count_daily.php` — Phinx `table()` pattern to copy (`['id' => false, ...]`, explicit `'null'`, `addForeignKey(... 'RESTRICT')`). New migration timestamp must sort after this.
- `src/Store/OwnerCountRepository.php` — repository style (ctor `PDO`, prepared stmts, backtick quoting); `upsert(NormalizedRow): bool` is the per-source store call.
- `src/Store/SettingsRepository.php` — `get(string): ?string`; `FetchRunner` reads/parses `batch_size`, `queue.stale_after`, `rate.avanza`, `rate.nordnet` (opaque strings, caller defaults).
- `src/Store/InstrumentRepository.php` — `all(): array<string,Instrument>` is `Enqueue`'s universe and `resolve-ids`' work list; `get()` gives `FetchRunner` the `Instrument` with cached ids. `upsertSeed()` shows the `UPDATE ... WHERE` write style for the new `cacheAvanzaId()` / `cacheNordnetId()`.
- `bin/seed-instruments.php` — the exact shape for `bin/resolve-ids.php`: `require bootstrap.php`, build repo from `Database::connect()`, do the work, `printf` a summary, `catch \Throwable` → log `error` + `exit(1)`.
- `src/Store/Database.php` — the `FOUND_ROWS` comment: `rowCount()` means "rows changed"; the claim `UPDATE ... LIMIT` relies on it.
- `src/Adapter/SourceAdapter.php` — port `fetch(Instrument): NormalizedRow` throwing `SchemaMismatch | NotFound | Transient`; `FetchRunner` depends only on this.
- `src/Adapter/AvanzaAdapter.php` / `NordnetAdapter.php` — `fetch()` already throws `NotFound` on a `null` cached id (a `null`-id check in `FetchRunner` before calling gives a clearer `warning`); both also expose the pure `resolveId(Instrument): string` that `bin/resolve-ids.php` uses. `bin/resolve-ids.php` constructs each with a real `GuzzleHttp\Client` — the first real-client construction site (an optional `HttpClientFactory` in `src/Adapter/` would be reused by Story 1.9).
- `src/Adapter/NormalizedRow.php` — `SOURCE_AVANZA` / `SOURCE_NORDNET`; readonly DTO passed straight to `upsert()`.
- `tests/Store/StoreTestCase.php`, `tests/MigrationTest.php` — hand-mirrored DDL + drop order + migrated-schema assertions; add `work_queue` (drop before `instrument`) and pin `UNIQUE (isin, run_date)` + FK like P3 did for `owner_count_daily`.
- `tests/Adapter/AdapterTestCase.php` — `instrument(...)` factory; for `FetchRunner` tests, a tiny hand-rolled `SourceAdapter` double returning queued rows/throwables is simplest.
- `src/Pipeline/` — only `.gitkeep` today; `Enqueue.php`, `FetchRunner.php`, `FetchRunnerResult.php`, namespace `Stockpicker\Pipeline`.

## Tasks & Acceptance

**Execution:**
- [x] `db/migrations/2026090915xxxx_create_work_queue.php` — `work_queue` per the frozen shape: surrogate `id`, `isin` (FK → instrument RESTRICT), `status` default `'pending'`, `run_date`, nullable `claimed_at`, `UNIQUE (isin, run_date)`. `down()` drops the table.
- [x] `src/Store/QueueRepository.php` — ctor `(PDO)`. Methods (names indicative): `enqueue(string $isin, string $runDate): bool`; `reopenStale(int $staleAfterSeconds, DateTimeImmutable $utcNow): int`; `claimBatch(string $runDate, int $limit, DateTimeImmutable $utcNow): list<QueueJob>`; `markDone(int $id): void`; `markFailed(int $id): void`; `reopen(int $id): void`; `countByStatus(string $runDate): array<string,int>`. Only the documented status transitions.
- [x] `src/Store/QueueJob.php` — readonly VO: `id`, `isin`, `status`, `runDate`, `?claimedAt`. `fromRow()` like `Instrument`.
- [x] `src/Pipeline/Enqueue.php` — ctor `(QueueRepository, InstrumentRepository)`. `run(string $runDate): int` — enqueue every `InstrumentRepository::all()` key, return the number of rows created.
- [x] `src/Pipeline/FetchRunner.php` — ctor `(QueueRepository, InstrumentRepository, OwnerCountRepository, array $adapters /* 'avanza'|'nordnet' => SourceAdapter */, SettingsRepository, LoggerInterface, callable $sleep = null)`. `run(string $runDate, float $timeboxSeconds): FetchRunnerResult` implementing: stale reopen → claim `batch_size` → per-job (timebox check, per-source fetch with same-source spacing, `upsert`, transition) → reopen leftovers on timebox → return counts.
- [x] `src/Pipeline/FetchRunnerResult.php` — readonly VO: `claimed`, `done`, `failed`, `reopened`, `rowsWritten` ints.
- [x] `src/Store/InstrumentRepository.php` — add write-once `cacheAvanzaId(string $isin, string $id): void` and `cacheNordnetId(string $isin, string $id): void` (`UPDATE ... WHERE isin = :isin AND <col> IS NULL`).
- [x] `src/Store/OwnerCountRepository.php` — add `?string $asOfDateOverride = null` to `upsert()` and `asOfDate()`; precedence `sourceTimestamp` → override → `fetchedAt`.
- [x] `src/Store/SourceIdResolver.php` — the testable resolver core (`resolve(InstrumentRepository, array $adapters, LoggerInterface): array{resolved,skipped,failed}`); write-once persistence via `cacheAvanzaId()` / `cacheNordnetId()`; per-source `try/catch (AdapterError)` → `warning` + continue.
- [x] `bin/resolve-ids.php` — thin wrapper (like `seed-instruments.php` around `InstrumentSeeder`): real `GuzzleHttp\Client` + the two adapters → `SourceIdResolver::resolve()` → `printf` the summary; `catch \Throwable` → `error` + `exit(1)`.
- [x] `_bmad-output/implementation-artifacts/spec-1-6-append-only-tidsserielagring.md` — append a `Spec Change Log` entry: the `$asOfDateOverride` parameter was added by Story 1.7 (human-renegotiated) to fix the midnight-split edge case. `_bmad-output/implementation-artifacts/deferred-work.md` — leave the entry (append-only) but the change log records it as addressed.
- [x] `tests/Store/QueueRepositoryTest.php` — extends `StoreTestCase`; covers enqueue fresh/re-run, claim honours `LIMIT` and marks only claimed rows, stale reopen boundary, each transition, `countByStatus`.
- [x] `tests/Store/InstrumentRepositoryTest.php` — `cacheAvanzaId` / `cacheNordnetId` set a null column and are a no-op on an already-set one.
- [x] `tests/Store/OwnerCountRepositoryTest.php` — with an override: Nordnet `sourceTimestamp` still wins; Avanza (no `sourceTimestamp`) uses the override, not `fetchedAt`; `null` override keeps today's behaviour.
- [x] `tests/Pipeline/EnqueueTest.php` — full universe enqueued once; second `run()` returns 0 and adds nothing (integration, via `StoreTestCase`).
- [x] `tests/Pipeline/FetchRunnerTest.php` — unit-level with fake `SourceAdapter`s + a spy sleeper + a real DB (or fully mocked repos): both-OK → `done` + 2 rows; null id → skip + `done`; `NotFound` → `failed`; `Transient` → `pending` + continue; timebox → leftovers reopened; same-source spacing waited. Cover every I/O matrix row.
- [x] `tests/Store/SourceIdResolverTest.php` — the three `resolve-ids` I/O-matrix rows explicitly: both ids present → `skipped`, `resolveId` never called; null id resolved → persisted + counted; `NotFound` → `warning`, column stays null, `failed` counted, loop continues to the next instrument (plus `SchemaMismatch`/`Transient` caught, resolve-only-the-missing-side, empty universe).
- [x] `tests/Support/FakeSourceAdapter.php` — the hand-rolled `SourceAdapter` double, shared by `FetchRunnerTest` and `SourceIdResolverTest` (separate `fetch` / `resolveId` response queues + call logs).
- [x] `tests/Store/StoreTestCase.php` + `tests/MigrationTest.php` — add `work_queue` to schema mirror, drop order, and migrated-schema assertions.

**Acceptance Criteria:**
- Given the Phinx migrations, when `phinx migrate` runs, then `work_queue` exists with `isin`, `status`, `run_date`, `claimed_at` and a unique `(isin, run_date)`; `rollback -t 0` then `migrate` is clean.
- Given the seed universe, when `Enqueue::run(runDate)` runs, then there is one `pending` row per instrument for that date, and a second call adds nothing.
- Given a filled queue, when `FetchRunner::run(runDate, timebox)` runs, then it claims at most `batch_size` jobs atomically, calls both adapters per job, stores results via `OwnerCountRepository`, sets each fully-handled job to `done`, and returns counts.
- Given a job whose source raises `Transient`, when `FetchRunner` handles it, then the job returns to `pending`, the runner continues, and nothing crashes.
- Given the timebox elapses mid-slice, when `FetchRunner` notices, then it stops, leaves the unprocessed jobs as `pending`, and returns cleanly.
- Given a `claimed` row older than `queue.stale_after`, when a new slice starts, then it is reopened to `pending` before claiming.
- Given `composer test` with the DB up, then the new queue/pipeline tests pass; with the DB down they skip and the suite stays green.
- Given a slice processing up to `batch_size` jobs via `FetchRunner` (NFR8), when it runs, then peak memory stays well under 256 MB and `batch_size` (default `25`) is documented as chosen for that margin (assert `memory_get_peak_usage` in a test as a smoke guard).
- Given a seed instrument with a `null` source id, when `bin/resolve-ids.php` runs and the source exposes its ISIN, then the id is persisted; when the source has no match, a `warning` is logged, the id stays `null`, and the script still exits 0.
- Given `FetchRunner` stores a datapoint, when it calls `OwnerCountRepository::upsert()` with the job's `run_date` as `$asOfDateOverride`, then a Nordnet row still dates from `statistics_timestamp` (FR5) and an Avanza row dates from the run date rather than the fetch clock.

## Implementation Notes

Implemented 2026-09-09.

- **Migration** `db/migrations/20260909150000_create_work_queue.php` — `['id' => false, 'primary_key' => ['id']]` + an explicit `addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])` gives the `BIGINT UNSIGNED AUTO_INCREMENT` surrogate. `UNIQUE (isin, run_date)` named `uq_work_queue_isin_run_date`; FK `isin → instrument(isin)` RESTRICT/RESTRICT. `status` `VARCHAR(16) NOT NULL DEFAULT 'pending'`. Verified `DESCRIBE` / `SHOW INDEX` on the dev DB.
- **`QueueRepository`** — every transition method routes through a private `transition()` that always guards `WHERE id = :id AND status = 'claimed'`, so only the documented moves land (a `done`/`failed` row is inert to a later `reopen`/`markFailed`). `enqueue()` is `INSERT … ON DUPLICATE KEY UPDATE id = id` and returns `rowCount() === 1` (relies on the driver's `FOUND_ROWS=off`, same as `OwnerCountRepository`). `claimBatch()` binds `LIMIT :lim` as `PDO::PARAM_INT` (native prepares — a string bind is a syntax error) and, beyond the frozen spec, also scopes the read-back `SELECT` with `AND run_date = :run_date` (a safe superset of `claimed_at = :now`; belt-and-suspenders, changes no documented behaviour). **`reopenStale(int, DateTimeImmutable, string $runDate)`** (P5) — the `UPDATE` is scoped `AND run_date = :run_date`, so a stale `claimed` row from another day is not flipped to `pending` only to sit unclaimable and inflate `reopened`.
- **`FetchRunner`** — the constructor (P3) throws `InvalidArgumentException` when `$adapters` lacks `avanza` or `nordnet`. `reopened` in `FetchRunnerResult` is the total of every job this slice returned to `pending`: stale one-offs (`reopenStale`, now run-date-scoped) + `Transient` hits + timebox leftovers. On `Transient` the source loop `break`s (the whole job is retried on a later slice, so calling the second source now is wasted work); the Avanza row already stored before a Nordnet `Transient` is a harmless idempotent no-op on retry. Same-source spacing: the first call to each source in a slice is free; every later call to that source waits `1 / rate.<source>` s via the injected sleeper (default real `usleep`). A `null` cached id is a skip + `warning`, never a failure — a job with both ids null still goes `done`. The whole per-job body runs inside `catch \Throwable` (P2): any non-`AdapterError` throw (raw `\PDOException`, a bug) logs at `error`, marks that one job `failed`, and the slice continues — it never aborts mid-way with jobs stuck `claimed`. **Settings** (P1) — `intSetting()` / `floatSetting()` share one `numericSetting()`: an absent key falls back silently; a *present* but unusable value (non-numeric, zero, negative) falls back to the hard default with one `warning` naming the key and the raw value. So a fat-fingered `rate.*` can no longer silently disable throttling.
- **`OwnerCountRepository`** — `upsert()` / `asOfDate()` gained `?string $asOfDateOverride = null`; precedence is `sourceTimestamp` → `$asOfDateOverride` → `fetchedAt`. `asOfDate()` stays pure/public. `FetchRunner` passes `job.runDate` on every call.
- **`SourceIdResolver` + `bin/resolve-ids.php`** — the resolver loop lives in `Stockpicker\Store\SourceIdResolver::resolve()` (testable, real-DB unit tests), exactly the `InstrumentSeeder` / `seed-instruments.php` split. `bin/resolve-ids.php` is now a thin wrapper: one real `GuzzleHttp\Client` shared by both adapters → `SourceIdResolver::resolve()` → `printf` the summary; `catch \Throwable` → `error` + `exit(1)`. `resolve()` throws `InvalidArgumentException` on an incomplete `$adapters` map (P3). Write-once persistence via `cacheAvanzaId()` / `cacheNordnetId()`; per-source `try/catch (AdapterError)` → `warning` + continue; an **empty-string id** from `resolveId()` (P4) is treated as a failure (`warning` + `++failed`) and never persisted, so it cannot permanently poison the column. Counter units differ by design (documented in the script and class docblocks): `resolved` / `failed` are per source attempt, `skipped` is per instrument (every id already cached). Ran live against the dev DB: first run `36 resolved, 0 skipped, 4 failed`; re-run `0 resolved, 17 skipped, 4 failed` (write-once holds; the 4 are genuine name-search misses, logged as `warning`, exit 0).
- **`work_queue` hot-path index** (P6) — the migration adds `ix_work_queue_status_run_date (status, run_date)` serving `claimBatch` and `countByStatus`; mirrored in `StoreTestCase` DDL and asserted in `MigrationTest`.

## Spec Change Log

- **2026-09-09 — `OwnerCountRepository::upsert()` / `asOfDate()` gained `?string $asOfDateOverride`** (human-renegotiated Story 1.6 frozen surface, per this spec's frozen decision). Recorded in `spec-1-6-append-only-tidsserielagring.md`'s Spec Change Log; the corresponding `deferred-work.md` item ("Fix each night's `as_of_date` at Enqueue time…") is left in place (append-only) and is addressed here.

## Review Triage Log

### Iteration 1 (2026-09-09) — 3 layers (blind-hunter N=10, edge-case-hunter, verification-gap)

**Routed to patch:**

| # | Finding | Verdict | Evidence / fix |
|---|---|---|---|
| P1 | `floatSetting()` isn't symmetric with `intSetting()` — a non-numeric / zero / negative `rate.avanza` \| `rate.nordnet` casts to `0.0` and the runner then silently skips all throttling (NFR3). Neither helper logs when it falls back. | medium | Confirmed at `FetchRunner::floatSetting()` (`return (float) $raw` for any non-null string) vs `intSetting()`'s `> 0 ? … : default`. A fat-fingered rate silently hammers an unofficial endpoint. Fix: `is_numeric` guard + positive-fallback in `floatSetting`; a `warning` in both helpers when a present value is unusable and the default is substituted. |
| P2 | The per-job source loop catches only `Transient` / `NotFound` / `SchemaMismatch`; any other throw (e.g. `\PDOException` from `upsert()` on a dropped connection) propagates out of `run()`, aborting the slice mid-way with jobs stuck `claimed` until stale-reopen and no `FetchRunnerResult` returned. | medium | Confirmed at `FetchRunner.php` per-job `try`. FR8 (full partial-failure tolerance) is Epic 2, but Epic 1's stated end state is unattended operation on the seed list, and the guard is minimal. Fix: wrap the per-job body in `catch \Throwable` → `error` log + `markFailed` + `++$failed` + `continue`. |
| P3 | `FetchRunner` / `SourceIdResolver` never check `$adapters` has both `avanza` and `nordnet` keys — an incomplete map fatals mid-slice on an undefined key, stranding `claimed` rows. | low | Unreachable from current callers but Story 1.9 adds a new one; the fix is a one-line constructor/entry precondition. Fix: throw `InvalidArgumentException` naming the missing key. |
| P4 | `SourceIdResolver` persists whatever `resolveId()` returns; an empty-string id would be written write-once, permanently poisoning the column (the `null` check no longer skips it, every future fetch calls the source with `''` → job `failed` forever, needs manual SQL to clear). | low→patch | `AvanzaAdapter` / `NordnetAdapter` `resolveId()` return `(string) $id` and only reject non-scalars, so `""` passes. Sticky/irreversible, one-line fix: `if ($id === '')` → warning + `++$failed`, don't persist. |
| P5 | `QueueRepository::reopenStale()` is not scoped by `run_date`; `FetchRunner::run()` calls it before claiming for the current date, so a stale `claimed` row from a *previous* day is flipped to `pending`, counted in `FetchRunnerResult::reopened` (which Story 1.8 persists), yet never claimed by the slice (`claimBatch` is date-scoped) — it lingers `pending` for the old date and the `reopened` count is misleading. | medium | Confirmed: `reopenStale` SQL has no `run_date` predicate; `claimBatch` does. Fix: add `AND run_date = :run_date` to `reopenStale`, thread `$runDate` through, add a cross-date test. |
| P6 | `work_queue` has no index serving its hot-path queries — `claimBatch` `(status, run_date)`, `reopenStale` `(status, claimed_at)`, `countByStatus` `(run_date)`. The sole `UNIQUE (isin, run_date)` index can't (wrong leading column), so every `/cron/work` tick full-scans. | low | Negligible at Epic 1 seed scale; Epic 2 scales this to the full universe with a scan every 5 min. Fix: `addIndex(['status', 'run_date'])` in the migration + the `StoreTestCase` DDL mirror + `MigrationTest`. |
| P7 | Test gaps: (a) every `FetchRunnerTest` runs against an empty `settings` table, so `intSetting`/`floatSetting` are only ever exercised on the `null → DEFAULTS` path — a regression that hard-codes or mis-wires the tunables stays green (verification-gap, pre-verified); (b) `testTimeboxHitMidSliceReopensTheRemainingJobs` leans on real sub-100 ms timing (`fetchDelaySeconds 0.04` vs timebox `0.02`) and flakes on a loaded box; (c) `claimBatch`'s FIFO `ORDER BY id` is never asserted. | medium | Confirmed against `FetchRunnerTest` / `QueueRepositoryTest` and `StoreTestCase::createSchema()` (settings seeded empty). Fix: add settings-driven cases (`batch_size='2'` → `claimed===2`, third job stays `pending`; `rate.avanza='1'` → `$waits` reflects it); harden the timebox test against CI timing; assert claim order in the `claimBatch` limit test. |

**Routed to defer** (appended to `deferred-work.md`):

| Finding | Verdict | Evidence |
|---|---|---|
| `work_queue` rows are never pruned — one row per instrument per run date, forever; no retention story in the migration, pipeline, or docs. | low | Within NFR8's stated tolerance today (~365k rows/yr at full universe), but the P6 scans and table size grow unbounded. A retention/cleanup step belongs with Story 1.8/2.6 observability or its own chore. |
| `batch_size` has no upper bound — a very large `settings` value makes `claimBatch` load that many `QueueJob` rows in one slice, voiding the NFR8 256 MB margin the default 25 was chosen for. | low | Needs operator misconfiguration; the default is safe. A `min($value, MAX)` clamp when NFR8 is actually measured (Story 1.10 probe) is the right time. |
| `run_date` is passed straight to SQL in `Enqueue` / `QueueRepository` with no format check — a malformed string (`''`, `'2026-9-9'`, trailing space) makes MySQL coerce the key and `FetchRunner` silently claim nothing, indistinguishable from an empty queue. | low | Not reachable from current callers (tests pass `Y-m-d`; Story 1.9 will mint it via `->format('Y-m-d')`). Story 1.9 should validate the date where it derives it. |
| A source stuck on `Transient` has no retry cap — the job ping-pongs `pending ↔ claimed` every slice until it eventually succeeds; extra load on the healthy source on every flake. | low | Explicitly Epic 2 by the FR coverage map (FR9: exponential backoff, rate-limit-aware, retry caps — Story 2.4). `upsert` idempotency makes the re-store of the healthy source benign. |

**Rejected:**

| Finding | Verdict | Refutation |
|---|---|---|
| No `warning` when the universe is empty — a nightly cron "succeeds" collecting nothing. | low | `Enqueue::run()` returning `0` is correct behaviour; run observability is Story 1.8 (`ingest_run`) and the Story 1.11 smoke test. Fix would add a branch for a non-defect. |
| `FetchRunner` reads the wall clock directly (`microtime`, `new DateTimeImmutable('now')`) — contradicts the spec's "No clock read in the pipeline". | false | The frozen decision forbids a clock-*derived* `run_date` only ("`run_date` is a caller-supplied Stockholm `Y-m-d` string … No clock read in the pipeline") and explicitly specifies `microtime(true) - start` for the timebox. `now` for the UTC claim tag is likewise in the frozen block. |
| Reopening the whole job on `Transient` re-fetches the already-successful source on retry (extra load, works against NFR3); no ping-pong cap. | low | Retry hardening (FR9 — backoff, caps) is Epic 2 per the FR coverage map. `upsert` is a first-write-wins no-op on the re-store. The Design Notes already acknowledge the wasted call. |
| Every failure logs at `warning`, nothing at `error`; a `SchemaMismatch` (API contract broke) is only a `warning`. | false (convention) | AD-11 / epic context fix the convention: `warning` for a schema deviation, `error` for an unexpected exception. Schema-deviation alarming is Story 2.6. (P2 adds `error` for the genuinely-unexpected-throw path.) |
| Undocumented deviations from the frozen `claimBatch` SQL (`AND run_date` in the read-back, early `return []`). | low | Already recorded in the spec's Implementation Notes; both are safe narrowings (every row claimed this slice is for `:run_date`). The fix is to edit this build's spec — out of scope. |
| `deferred-work.md` still reads as open for the `as_of_date` item; resolution is only in `spec-1-6`'s change log. | low | Deliberate per this spec's task ("leave the entry, append-only; the change log records it as addressed"). The 1.6 change-log entry is the record. |
| `FetchRunnerResult` lacks `skipped` (null-id skips) and remaining-`pending` counts; `reopened` conflates stale + Transient + timebox. | low | The field list (`claimed`, `done`, `failed`, `reopened`, `rowsWritten`) is frozen. Story 1.8 owns the `ingest_run` design and can query `countByStatus()` for what it needs. The `reopened` composition is documented in `FetchRunnerResult`. |
| `enqueue()`'s `INSERT … ON DUPLICATE KEY UPDATE id = id` burns an AUTO_INCREMENT value per duplicate row. | low | Harmless with `BIGINT UNSIGNED`. `INSERT IGNORE` would silence genuine errors (bad data, FK) and diverges from the established `OwnerCountRepository` / `SettingsRepository` idiom. |
| `testPeakMemoryStaysWellUnderTheWebPhpLimit` measures the whole PHPUnit process peak, not `FetchRunner`'s footprint — vacuous or flaky. | low | The spec AC asked for exactly this ("assert `memory_get_peak_usage` in a test as a smoke guard"). A real per-runner memory profile is out of scope; the NFR8 bound is verified for real in Story 1.10's Loopia probe. |
| Two overlapping `run()` slices with `$now` < 1 s apart — slice B's read-back `WHERE claimed_at = :now` matches slice A's crashed `claimed` rows; both process them. | low | The frozen decision names NFR2 (one cron instance at a time) as the guarantee that makes the second-granularity tag safe. Even if it happened, `upsert` first-write-wins makes it benign. |
| Instrument-seeding INSERT SQL is duplicated across three test files. | low | Test-only; `AdapterTestCase::instrument(...)` builds an `Instrument` object, not a DB row, so it isn't the shared helper these need. Low value, churn not worth it; the P7 FIFO assertion is the part worth doing. |

**Post-patch (2026-09-09):** P1–P7 all applied.
- P1 — `numericSetting()` (`is_numeric` + positive fallback + `warning` on an unusable present value); `intSetting`/`floatSetting` delegate.
- P2 — per-job body wrapped in `catch \Throwable` → `error` + `markFailed` + `++failed` + continue.
- P3 — `FetchRunner::__construct` and `SourceIdResolver::resolve()` throw `InvalidArgumentException` on a missing source.
- P4 — `SourceIdResolver` rejects an empty-string id (`warning` + `++failed`, not persisted).
- P5 — `QueueRepository::reopenStale()` gained `string $runDate` and `AND run_date = :run_date`; `FetchRunner::run()` passes it.
- P6 — `ix_work_queue_status_run_date (status, run_date)` in the migration + `StoreTestCase` DDL + `MigrationTest` assertion.
- P7 — new `FetchRunnerTest` cases: `batch_size='2'` → `claimed===2` + third job `pending`; `rate.avanza='1'` → `$waits === [1.0, 2.0]`; unusable value → default + `warning`; raw `\RuntimeException` → that job `failed`, slice continues; constructor precondition. `testTimeboxHitMidSlice` rewritten to assert timing-independent invariants (`claimed===3`, `done+reopened===3`, `failed===0`, last job `pending`, no job left `claimed`). `QueueRepositoryTest`: FIFO-lowest-ids assertion in the `claimBatch` limit test + `testReopenStaleIsScopedToTheRunDate`. `SourceIdResolverTest`: empty-id + missing-adapter cases.

Post-patch: `composer test` — **119 tests / 416 assertions green** (DB up); **119 tests / 66 skipped green** (DB down). `composer validate --strict` clean. `phinx` migrate + isolated rollback/migrate of `CreateWorkQueue` clean; `ix_work_queue_status_run_date` present on the dev DB. `php bin/resolve-ids.php` re-run still `0 resolved, 17 skipped, 4 failed`, exit 0.

## Design Notes

- **Why `claimed_at = :now` as the claim marker** — with NFR2 guaranteeing one cron instance at a time, the slice's start instant uniquely tags the rows it just claimed, so a second statement can `SELECT` them without a worker-id column. Stale reopen (run first) clears any rows a crashed earlier slice left `claimed`.
- **`rowCount()` on the claim `UPDATE`** gives the number actually claimed — enough for the `claimed` count without re-querying, and it confirms the follow-up `SELECT` size.
- **Timebox vs `batch_size`** — `batch_size` bounds memory (NFR8); the timebox bounds wall-clock against the URL-cron limit. At `rate.* = 0.5` (2 s/call) a job is ~4 s, so a 60–90 s slice completes ~15–22 jobs and the rest of a 25-job claim is reopened — expected, the next `/cron/work` picks them up.
- **Injected sleeper** keeps the throttle testable and lets `1.11`'s live run use the real spacing without a test ever sleeping for real.
- **`as_of_date` precedence** — `sourceTimestamp → override → fetchedAt` is the smallest change that both keeps FR5 (Nordnet dates from its own timestamp) and removes the only real split risk (Avanza, whose `sourceTimestamp` is always null, previously dated from the fetch clock). Passing the run date on every call is simplest and harmless for Nordnet since its branch is never reached.
- **Interim `instrument` writer** — `bin/resolve-ids.php` writing two id columns is a deliberate, scoped exception to AD-3's single-writer rule for Epic 1 only; Epic 2's `UniverseSync` takes over the whole row. Write-once `WHERE <col> IS NULL` means re-running it after `UniverseSync` exists can never clobber.

## Verification

**Commands:**
- `docker compose up -d && vendor/bin/phinx migrate -e development` — exit 0, `work_queue` created
- `vendor/bin/phinx rollback -e development -t 0 && vendor/bin/phinx migrate -e development` — clean down/up
- `composer test` — green (DB up: queue/pipeline tests run; DB down: skip)
- `composer validate --strict`, `php -l` on new files — clean
- boundary grep: no `INSERT`/`UPDATE`/`SELECT` against `work_queue` outside `src/Store/`; no `GuzzleHttp\Client` construction outside `src/Adapter/` and `bin/`
- `php -l bin/resolve-ids.php` — clean

**Manual checks:**
- `DESCRIBE work_queue;` — surrogate `id`, `status` default `pending`, unique `(isin, run_date)`, FK on `isin`
- `php bin/resolve-ids.php` against the dev DB with the seed list loaded — prints a `resolved / skipped / failed` summary; a second run resolves nothing new

### Results (2026-09-09)

- `vendor/bin/phinx migrate -e development` — exit 0, `work_queue` created. `DESCRIBE` / `SHOW INDEX`: `id BIGINT(20) UNSIGNED AUTO_INCREMENT PRI`, `status … DEFAULT 'pending'`, `uq_work_queue_isin_run_date (isin, run_date)` unique, FK on `isin`.
- `phinx rollback -e development -t 20260909140100` then `migrate` — clean down/up of `CreateWorkQueue` in isolation on the dev DB. **NOTE:** a full `rollback -t 0` on the dev DB now fails inside the *pre-existing* Story 1.6 `WidenNordnetInstrumentId::down()` (`SQLSTATE 22001` narrowing `nordnet_instrument_id` back to `VARCHAR(32)` once real 36-char nnx UUIDs are stored — exactly the case its own `down()` warning comment calls out, and Story 1.6 Review P6). Not introduced here. The automated `MigrationTest::testRollbackDropsEverything` runs a full clean `rollback -t 0` on a throwaway DB (no long ids) and passes, covering `work_queue` too.
- `composer test` — **119 tests / 416 assertions, green** with the DB up (44 new: `QueueRepositoryTest` 10, `FetchRunnerTest` 17, `SourceIdResolverTest` 8, `EnqueueTest` 3, `OwnerCountRepositoryTest` +4, `InstrumentRepositoryTest` +2, plus `MigrationTest` assertions). DB down: **119 tests, 66 skipped, green** (`docker compose stop mariadb`). (Counts are post-review-patch — see the Review Triage Log.)
- `composer validate --strict` — clean. `php -l` on all new files — clean.
- boundary greps — both clean (no `work_queue` SQL outside `src/Store/`; no `Client` construction outside `src/Adapter/` and `bin/`; no SQL in `src/Pipeline/`).
- `php bin/resolve-ids.php` live against the dev DB — run 1 `36 resolved, 0 skipped, 4 failed` (exit 0), run 2 `0 resolved, 17 skipped, 4 failed` (exit 0); write-once id caching confirmed, the 4 are real name-search misses logged as `warning`.
