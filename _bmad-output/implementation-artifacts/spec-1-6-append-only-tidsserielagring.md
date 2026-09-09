---
title: 'Story 1.6: Append-only tidsserielagring'
type: 'feature'
created: '2026-09-09'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: 'd68de3ed20da1742880e06dd5a0a614a1fec49f0'
context:
  - '{project-root}/AGENTS.md'
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The adapters produce `NormalizedRow`s (Stories 1.4/1.5) but there is nowhere to store them. The pipeline needs an append-only, idempotent time-series table so history accrues and a re-run night never creates duplicates or overwrites a recorded value.

**Approach:** A Phinx migration for `owner_count_daily` keyed on `(isin, source, as_of_date)`, and `OwnerCountRepository::upsert(NormalizedRow): bool` that derives `as_of_date` (the single `Europe/Stockholm` calendar conversion — from `sourceTimestamp` when present, else the fetch time) and writes the row **first-write-wins**: an existing `(isin, source, as_of_date)` row is never modified.

## Boundaries & Constraints

**Always:**
- `as_of_date` = `(row.sourceTimestamp ?? row.fetchedAt)` converted to `Europe/Stockholm` and truncated to `Y-m-d`. This is the only place the tz conversion happens (per Story 1.1 Design Notes).
- `fetched_at` stored = `row.fetchedAt` (already UTC, set by the adapter), as a naive `DATETIME` understood as UTC.
- **No existing row is ever overwritten** (AD-4, NFR7): the write is `INSERT … ON DUPLICATE KEY UPDATE` that changes nothing on conflict. `upsert()` returns `true` when a new row was written, `false` when `(isin, source, as_of_date)` already existed.
- `number_of_owners` is stored as-is from the row (already a validated non-negative int). `last_price` / `market_cap` are nullable.
- Source partitioning (AD-3) is the caller's job; this repo writes whatever `source` the row carries.
- All access through `OwnerCountRepository` (PDO, no ORM); no SQL elsewhere.

**Never:**
- No `work_queue` / `Enqueue` / `FetchRunner` (Story 1.7), no `ingest_run` (Story 1.8), no cron endpoints (Story 1.9).
- No historical backfill, no "latest value wins" — the first observation for a calendar day is the record for that day.
- No `ingest_run_id` column yet (Story 1.8 adds the run link).
- Do not modify `docs/deploy.md` or `bin/deploy.sh` (Story 1.10).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Behavior | Error Handling |
|---|---|---|---|
| Migrate up/down | `phinx migrate` / `rollback` | up: `owner_count_daily` created with the composite PK; down: dropped | Phinx reports, non-zero exit |
| First write | no row for `(isin, source, as_of_date)` | row inserted; `upsert()` returns `true` | — |
| Re-write, same data | row already exists | nothing changes; returns `false` | — |
| Re-write, different owners | row exists, new `NormalizedRow` has a different `number_of_owners` | stored row is unchanged; returns `false` | — |
| Nordnet timestamp present | `sourceTimestamp` set (UTC) | `as_of_date` = that instant in `Europe/Stockholm` (e.g. `2026-01-01T23:30Z` → `2026-01-02`) | — |
| Avanza, no source timestamp | `sourceTimestamp` null | `as_of_date` = `fetchedAt` in `Europe/Stockholm` | — |
| Both sources, same day | two rows, `source='avanza'` and `source='nordnet'`, same `as_of_date` | both stored (composite key differs on `source`) | — |
| `last_price` / `market_cap` null | row carries nulls | columns stored `NULL` | — |
| Widen `instrument` column | `phinx migrate` | `instrument.nordnet_instrument_id` becomes `VARCHAR(64)` (fits the 36-char nnx UUID) | — |

**Decision (2026-09-09):** this story also carries a second migration `widen_nordnet_instrument_id` — `ALTER TABLE instrument MODIFY nordnet_instrument_id VARCHAR(64) NULL` — closing the `deferred-work.md` item ahead of Epic 2. `StoreTestCase` / `MigrationTest` DDL updated to `VARCHAR(64)` for that column.

</frozen-after-approval>

## Code Map

- `db/migrations/20260908161500_create_instrument_and_settings.php` -- the pattern: Phinx `table()` API, explicit `'null' => false`, `up()`/`down()`.
- `src/Store/Database.php` -- `Database::connect(Config): PDO` (exception mode, `FETCH_ASSOC`).
- `src/Store/InstrumentRepository.php` / `SettingsRepository.php` -- ctor-takes-`PDO`, backtick-quoting, `INSERT … ON DUPLICATE KEY UPDATE` style to follow.
- `src/Adapter/NormalizedRow.php` -- the input: `isin`, `source`, `numberOfOwners` (int), `lastPrice`/`marketCap` (?float), `sourceTimestamp` (?DateTimeImmutable UTC), `fetchedAt` (DateTimeImmutable UTC).
- `tests/Store/StoreTestCase.php` -- `createSchema()` mirrors the migration DDL; extend it with `owner_count_daily` and add the drop to `tearDown`.
- `tests/MigrationTest.php` -- drives the real migrations; add `owner_count_daily` to `dropAll()` and assert it after `migrate`.

## Tasks & Acceptance

**Execution:**
- [x] `db/migrations/20260909140000_create_owner_count_daily.php` -- Phinx `table()` API, composite PK `(isin, source, as_of_date)`, `number_of_owners` `integer` unsigned, `last_price` DECIMAL(18,4) NULL, `market_cap` DECIMAL(24,2) NULL, `fetched_at` DATETIME NOT NULL, FK `isin` → `instrument(isin)` RESTRICT.
- [x] `db/migrations/20260909140100_widen_nordnet_instrument_id.php` -- `changeColumn` to `VARCHAR(64)`; `down()` back to 32.
- [x] `src/Store/OwnerCountRepository.php` -- `upsert(NormalizedRow): bool` (`INSERT … ON DUPLICATE KEY UPDATE fetched_at = fetched_at`, `rowCount() === 1`); `asOfDate(NormalizedRow): string` (the single Europe/Stockholm conversion); `get()`, `countForIsin()` for inspection.
- [x] `tests/Store/StoreTestCase.php` -- shared `dropSchema()` (owner_count_daily first, FK), `owner_count_daily` DDL, `nordnet_instrument_id` → `VARCHAR(64)`.
- [x] `tests/Store/OwnerCountRepositoryTest.php` -- 8 tests, every matrix row incl. cross-midnight Stockholm and both-sources-same-day.
- [x] `tests/MigrationTest.php` -- `owner_count_daily` in the drop set + asserted post-migrate; `columnType()` helper asserts the widened column; both migrate/rollback tests cover all three tables.

**Acceptance Criteria:**
- Given the Phinx migrations, when `phinx migrate` runs, then `owner_count_daily` exists with a composite key on `(isin, source, as_of_date)` and columns `number_of_owners`, `last_price`, `market_cap`, `fetched_at`.
- Given a `NormalizedRow`, when `OwnerCountRepository::upsert()` is called, then a row is written with `as_of_date` as a `Europe/Stockholm` calendar date (from `sourceTimestamp` when present, else the fetch time) and `fetched_at` as a UTC timestamp.
- Given the same `(isin, source, as_of_date)` is written twice — including with a different `number_of_owners` — when `upsert()` runs again, then exactly one row exists and its stored values are unchanged.
- Given `composer test` with the DB up, when it runs, then the `owner_count_daily` tests pass; with the DB down they skip and the suite stays green.

## Implementation Notes

- **`ON DUPLICATE KEY UPDATE fetched_at = fetched_at`** (not `isin = isin`) — the no-op SET targets a non-key column so MariaDB reports 0 affected rows on the conflict path; `rowCount() === 1` then cleanly means "inserted". Verified against the dev DB: first `upsert` → `true`, second → `false`, stored `number_of_owners` and `fetched_at` unchanged even when the second row carried different values.
- **`asOfDate()` is pure** — `(sourceTimestamp ?? fetchedAt)->setTimezone('Europe/Stockholm')->format('Y-m-d')`. No clock, no `now()`. Cross-midnight case tested: `2026-01-01T23:30Z` → `as_of_date = 2026-01-02`.
- **DECIMAL round-trip** — PDO returns `last_price` as the string `"402.2000"`; tests cast to `float` for comparison. Stored history is exact (no binary-fp drift).
- **Widening migration** verified: `SHOW COLUMNS` reports `varchar(64)` for `instrument.nordnet_instrument_id` after `migrate`; `rollback -t 0` then `migrate` is clean. This closes the `deferred-work.md` entry (kept in the file per its append-only rule; addressed here).
- **Verified** (post-review): `composer test` — 75 tests / 259 assertions with the DB up; 75 tests / 24 skipped, green, with it down; no deprecations. `composer validate --strict` clean. `php -l` clean. `phinx migrate` / `rollback -t 0` / `migrate` clean against the dev DB. See the Review Triage Log for the iteration-1 patches (DECIMAL precision, migrated-schema assertions, DST tests, docblocks).

## Spec Change Log

- **2026-09-09 (Story 1.7, human-renegotiated) — `OwnerCountRepository::upsert()` and `asOfDate()` gained an additive `?string $asOfDateOverride = null` parameter.** New precedence: `row.sourceTimestamp` (Nordnet, FR5 unchanged) → `$asOfDateOverride` (the run's calendar date, passed by `FetchRunner`) → `row.fetchedAt` (unchanged fallback). Only the sourceless case (Avanza) changes — from the fetch clock to the run date — so a fetch/retry straddling local midnight can no longer split one night's observation across two `as_of_date`s. No change to storage semantics, the composite key, or first-write-wins. `asOfDate()` stays pure/public. This addresses the `deferred-work.md` item "Fix each night's `as_of_date` at Enqueue time…" (that entry is left in place per the file's append-only rule).

## Review Triage Log

### Iteration 1 (2026-09-09) — 3 layers (blind-hunter N=6, edge-case-hunter, verification-gap)

**Routed to patch:**

| # | Finding | Verdict | Evidence / fix |
|---|---|---|---|
| P1 | `rowCount()===1` for insert-vs-noop silently depends on the mysql driver's `FOUND_ROWS` staying off; nothing documents it and the DB-less runs skip these tests | medium | Confirmed: `Database::connect` never set the flag (default off = correct). Flipping it to `true` breaks `upsert()`'s return. Fix: strong comment in `Database`/`OwnerCountRepository` pointing at the guarding integration test `testReWriteWithSameDataReturnsFalse…`. (`PDO::MYSQL_ATTR_FOUND_ROWS` const is deprecated on 8.5 and absent on the 8.3 floor, so a comment + the test is the guard.) |
| P2 | Float→`DECIMAL` binding uses PHP's `precision` ini (14 sig digits); trillion-scale `market_cap` loses its last decimal | low→patch | Verified against the dev DB: `1234567890123.45` stored as `…123.40`. Fix: bind `last_price`/`market_cap` via `number_format($v, 4/2, '.', '')`. Added `testLargeMarketCapAndPrecisePriceRoundTripExactly`. |
| P3 | `MigrationTest` asserts only that `owner_count_daily` *exists* — not the composite PK, `int unsigned`, or uniqueness; `OwnerCountRepositoryTest` runs hand-written DDL, never the migration. A broken migration key ships green. | medium | Confirmed. Fix: `MigrationTest` now asserts the PK column list/order (`information_schema.statistics`), `number_of_owners` unsigned, and that a duplicate `(isin, source, as_of_date)` insert against the migrated schema is rejected (SQLSTATE 23000). |
| P4 | DST / cross-midnight `as_of_date` tested only for winter +01:00; `testAsOfDateHelperIsPure` uses a DST day but at 10:00 UTC (proves nothing); `market_cap` round-trip never asserted | low | Confirmed test gaps. Fix: `testAsOfDateHandlesTheAutumnDstFallBackAcrossMidnight` (both sides of the 2026-10-25 transition, cross-midnight) + the P2 round-trip test. |
| P5 | `upsert()` on an unknown ISIN throws a raw `PDOException` (FK RESTRICT); no `@throws`, no matrix row | low | Confirmed. Not reachable in Epic 1 (isin always comes from `instrument`); fail-loud is acceptable and Story 1.7 wraps each job in try/catch. Fix: `@throws \PDOException` on the docblock; rejected the "typed `UnknownInstrument` exception" (new public surface, guards an undemonstrated state). |
| P6 | Widening migration `down()` narrows back to `VARCHAR(32)` — truncates/errors if a 36-char UUID was stored | low | Confirmed. Fix: warning comment in `down()`. Rejected changing behaviour (single-step rollback is never used; `instrument` is dropped by the prior migration's `down()` immediately after). |

**Routed to defer** (appended to `deferred-work.md`):

| Finding | Verdict | Evidence |
|---|---|---|
| `StoreTestCase` mirror-DDL drifts from the real migrations (constraint name, column comments, FK `ON UPDATE`); a migration-only change passes the store suite silently | medium | Pre-existing pattern from Story 1.2, widened here (third table + a column change hand-edited in two places). P3 pins the migrated schema's key contract; the fuller fix (run real migrations in store tests, or assert schema equality) is its own change. |
| A nightly fetch split across a retry that straddles local midnight → two `owner_count_daily` rows on consecutive `as_of_date`s (Avanza, no `sourceTimestamp`) | low | Confirmed artifact of the frozen `as_of_date = (sourceTimestamp ?? fetchedAt)` rule. Resolution belongs to Story 1.7: fix the run's `as_of_date` at `Enqueue` time rather than per-fetch. |

**Rejected:**

| Finding | Verdict | Refutation |
|---|---|---|
| Design Notes still say `ON DUPLICATE KEY UPDATE isin = isin` | fix-doc | Not a code defect; the Design Notes paragraph was corrected in place to match the shipped `fetched_at = fetched_at`. |
| `upsert()` is a misleading name (never updates) | low | The spine fixes the name as `upsert`; the docblock now states plainly it never updates an existing row. Rename would deviate from the AC and spine. |
| No secondary index for by-date / cross-instrument reads | low | ~15k rows/year at seed scale, ~730k/year at full universe — a full scan is sub-ms for years. Add a `(as_of_date)` / `(source, as_of_date)` index when Epic 3's Deriver / analytics queries make it measurable. |
| Concurrent `upsert()` of the same PK → InnoDB deadlock surfaces as an exception | maybe-false / low | AD-5 runs one cron instance at a time; concurrent writes of the same `(isin, source, as_of_date)` are not a reachable state. |
| `number_of_owners` negative / `> 4294967295` not re-validated | false | The fetch adapters (1.4/1.5) validate it as a non-negative int before building `NormalizedRow`; the frozen block states it arrives "already a validated non-negative int"; no Swedish stock approaches 4.29e9 owners. |
| `sprint-status.yaml` says `in-progress` while under review | false | Correct mid-workflow state; step-05 moves it to `review`. Fixing it would edit this build's tracking artifacts. |
| `asOfDate()` `->setTimezone()` would mutate a passed mutable `DateTime` | false | `NormalizedRow.sourceTimestamp`/`fetchedAt` are declared `DateTimeImmutable` on a `readonly` DTO; not reachable. |

Post-patch: `composer test` green — 75 tests / 259 assertions (DB up), 75 / 24 skipped (DB down). `composer validate --strict` clean. `phinx rollback -t 0` + `migrate` clean. No deprecations.

## Design Notes

- **First-write-wins**, not last — AD-4 / NFR7 say a recorded value is never overwritten and there is no backfill. The first observation of a calendar day is that day's record; a later same-day fetch (cron retry, split timebox) is a no-op. `INSERT … ON DUPLICATE KEY UPDATE fetched_at = fetched_at` is the portable MariaDB idiom for "insert or ignore, and tell me via `rowCount()` whether it inserted" — the no-op `SET` must target a **non-key** column so MariaDB reports 0 affected rows on the duplicate path (1 on insert). Relies on the driver's default `FOUND_ROWS=off`.
- **One tz conversion** — `asOfDate()` is the only code that touches `Europe/Stockholm`. Using `row.fetchedAt` as the fallback basis (rather than a fresh `now()`) keeps `upsert()` a pure function of its argument and makes the tests deterministic without a clock.
- **`DECIMAL` for money** — prices/market cap as `DECIMAL` not float, to avoid binary-fp drift in stored history; the adapter's `?float` is cast on the way in.

## Verification

**Commands:**
- `docker compose up -d && vendor/bin/phinx migrate -e development` -- exit 0, `owner_count_daily` created
- `vendor/bin/phinx rollback -e development -t 0 && vendor/bin/phinx migrate -e development` -- clean down/up
- `composer test` -- green (DB up: owner-count tests run; DB down: skip)
- `composer validate --strict`, `php -l` on new files -- clean

**Manual checks:**
- `DESCRIBE owner_count_daily;` -- composite PK, `number_of_owners` INT UNSIGNED NOT NULL, price/mcap nullable DECIMAL, `fetched_at` DATETIME
