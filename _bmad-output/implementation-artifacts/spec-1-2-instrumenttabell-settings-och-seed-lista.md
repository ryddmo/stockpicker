---
title: 'Story 1.2: Instrumenttabell, settings och seed-lista'
type: 'feature'
created: '2026-09-08'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: '9f975f191181e089daf1d0d9b9803795b60157df'
context:
  - '{project-root}/AGENTS.md'
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The skeleton (Story 1.1) has Phinx wired but no schema and no database access layer. Every later Epic 1 story needs a persisted instrument universe, operational parameters read every run, and a PDO path to the database.

**Approach:** One Phinx migration creating `instrument` and `settings` (seeding the five canonical `settings` keys), a thin PDO factory, `InstrumentRepository` and `SettingsRepository` in `src/Store/`, and an idempotent `bin/seed-instruments.php` that loads a hardcoded ~20-ISIN list through `InstrumentRepository`.

## Boundaries & Constraints

**Always:**
- All DB access goes through PDO repositories in `src/Store/`; no SQL in `bin/`, pipeline, or adapter code; no ORM. (AD-1 store boundary)
- `instrument` is written only by the universe path — in this epic that is `bin/seed-instruments.php`. ISIN is the natural key. (AD-3)
- The migration carries schema + only the five canonical `settings` keys (`run_after`, `batch_size`, `rate.avanza`, `rate.nordnet`, `queue.stale_after`) and runs via the existing `vendor/bin/phinx migrate` step in `docs/deploy.md`.
- `settings` values are opaque strings; readers parse. `run_after` = `HH:MM` (Europe/Stockholm); `rate.*` = calls/second (decimal); `batch_size`, `queue.stale_after` = integers (`queue.stale_after` in seconds).
- The PDO factory reads `Config::db()` and sets `ERRMODE_EXCEPTION`, `ATTR_EMULATE_PREPARES=false`, `utf8mb4`.
- The seed script is re-runnable: a second run adds no rows, keeps `first_seen`, and never overwrites a non-NULL cached id.

**Never:**
- No `owner_count_daily`, `work_queue`, or `ingest_run` tables (Stories 1.6–1.8). No id resolution, HTTP, or adapter code (Story 1.3) — seeded id columns stay `NULL`.
- No SQLite; MariaDB only. No Börsdata / live universe sync (Epic 2).
- Do not modify `docs/deploy.md` or `bin/deploy.sh` (Story 1.10).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Behavior | Error Handling |
|---|---|---|---|
| Migrate up/down | `phinx migrate` / `rollback` | up: both tables + 5 settings rows; down: both dropped | Phinx reports, non-zero exit |
| Seed first run | migrated DB, empty `instrument` | ~20 rows; `first_seen` = run date (Europe/Stockholm), `last_seen` + ids NULL | any row error → log `error`, `exit(1)` |
| Seed re-run | already seeded | 0 inserted; `first_seen` and non-NULL ids preserved | — |
| `SettingsRepository::get` unknown key | key absent | returns `null` | — |
| DB unreachable | bad creds / down | seed script `exit(1)` + `error` log; repo surfaces the PDO exception | — |

**Seed list (decided 2026-09-08):** the ~20 Nasdaq Stockholm Large Cap names below, all `list = 'LC'`. The implementer fills each `isin` from a cited public source (e.g. Nasdaq/issuer IR); ISINs are treated as best-effort and confirmed against live Avanza/Nordnet responses in Stories 1.4/1.5, where a wrong ISIN surfaces as `NotFound`. Names: Investor B, Volvo B, Atlas Copco A, Ericsson B, Hexagon B, Assa Abloy B, SEB A, Svenska Handelsbanken A, Swedbank A, Nordea Bank Abp, Sandvik, Essity B, EQT, Evolution, Boliden, Alfa Laval, Epiroc A, Telia Company, SKF B, Getinge B.

</frozen-after-approval>

## Code Map

- `phinx.php` -- `development` env = compose MariaDB `127.0.0.1:3306`; `production` from `Config::db()`; `migration_paths` → `db/migrations`. No change.
- `src/Config.php` -- `Config::load()->db()` → `{host,name,user,pass,charset}`; `Config::fromArray()` is the test seam.
- `bootstrap.php` -- returns `['config'=>…, 'logger'=>…]`; the seed script reuses it. `Logging::logger()` for `error` logging.
- `db/migrations/.gitkeep`, `src/Store/.gitkeep` -- placeholders; delete the store one once real files exist.
- `docs/deploy.md` -- lines 149/174 already state `phinx migrate -e production` and "first migration seeds the canonical settings keys" — match that wording, do not edit.
- `tests/SmokeTest.php` -- pattern for isolated fixture roots + `Config::fromArray`.

## Tasks & Acceptance

**Execution:**
- [x] `db/migrations/20260908161500_create_instrument_and_settings.php` -- `up()` creates `instrument` and `settings` (all non-id columns `NOT NULL`) and inserts the 5 settings rows; `down()` drops both.
- [x] `src/Store/Database.php` -- `Database::connect(Config): PDO`, DSN from `db()`, `ERRMODE_EXCEPTION`, `ATTR_EMULATE_PREPARES=false`, `FETCH_ASSOC`, charset.
- [x] `src/Store/SettingsRepository.php` -- `get(): ?string`, `all(): array<string,string>`, `set()` upsert; `` `key` `` / `` `value` `` backtick-quoted.
- [x] `src/Store/InstrumentRepository.php` + `src/Store/Instrument.php` -- `all()` keyed by ISIN (ordered), `get(): ?Instrument`, `upsertSeed()` insert-or-refresh-name/list, never touching id columns or `first_seen`. `Instrument` is a readonly value object.
- [x] `src/Store/InstrumentSeeder.php` -- `const LIST` (20 decided LC names) + `seed(InstrumentRepository): array{inserted,unchanged}`. Extracted from `bin/` so it is unit-testable.
- [x] `bin/seed-instruments.php` -- thin wrapper: bootstrap → `Database::connect` → `InstrumentSeeder::seed`; prints `N inserted, M unchanged`; on any throwable logs `error` + `exit(1)`.
- [x] `phinx.php` -- added a `testing` environment (env-var overridable, defaults to `stockpicker_test` on the compose server) for the migration test.
- [x] `tests/Store/StoreTestCase.php` -- base: connects to the compose server, `markTestSkipped` when down, builds tables in a dedicated `stockpicker_test` DB per test.
- [x] `tests/Store/SettingsRepositoryTest.php`, `tests/Store/InstrumentRepositoryTest.php`, `tests/Store/InstrumentSeederTest.php`, `tests/Store/DatabaseTest.php`, `tests/MigrationTest.php` -- cover every I/O matrix row (see Matrix note in Implementation Notes).

**Acceptance Criteria:**
- Given a migrated DB, when `vendor/bin/phinx status` runs, then the migration shows applied and `settings` holds the 5 canonical keys with the agreed values.
- Given the migrated DB, when `php bin/seed-instruments.php` runs, then `instrument` holds the seed rows with `first_seen` set and `last_seen` + both id columns `NULL`.
- Given one prior seed run, when it runs again, then no rows change and exit code is 0.
- Given no database, when `composer test` runs, then the store tests skip (not fail) and the suite is green.
- Given `docker compose up -d`, when `composer test` runs, then the store integration tests execute and pass.

## Implementation Notes

- **Migration column nullability** — Phinx's `addColumn` did not default `name`/`list`/`first_seen` to `NOT NULL`; set `'null' => false` explicitly so the migrated schema matches the spec and the test DDL. Verified with `DESCRIBE` after `rollback -t 0` + re-`migrate`.
- **Seeding extracted to `InstrumentSeeder`** — the spec put the `const` list in `bin/`; moved it to `src/Store/InstrumentSeeder.php` (`const LIST` + static `seed()`) so the I/O matrix's "seed first run" / "seed re-run" rows get real unit coverage. `bin/seed-instruments.php` is now a ~15-line wrapper. No SQL leaked into `bin/`.
- **`upsertSeed` returns `void`** as specced; the seeder counts inserted-vs-unchanged with a pre-`get()` check rather than relying on driver `rowCount()` semantics for `INSERT … ON DUPLICATE KEY UPDATE`.
- **Store tests use a dedicated `stockpicker_test` database** created via the compose root account, never the `stockpicker` DB the Phinx `development` env manages. `StoreTestCase` DDL mirrors the migration — a header comment flags the two must stay in step. Added a `testing` Phinx env so `MigrationTest` drives the *real* migration file (subprocess `vendor/bin/phinx migrate/rollback -e testing`) rather than duplicated DDL.
- **Matrix coverage** — Migrate up/down: `MigrationTest`. Seed first run / re-run: `InstrumentSeederTest` (+ `InstrumentRepositoryTest` at unit level). `SettingsRepository::get` unknown key: `SettingsRepositoryTest::testGetReturnsNullForUnknownKey`. DB unreachable: `DatabaseTest` (connect surfaces `PDOException`) and manual `php bin/seed-instruments.php` with the container stopped → `exit 1` + `stockpicker.ERROR: seed-instruments failed` in `var/log/stockpicker.log`.
- **Verified**: `composer test` green — 25 tests / 165 assertions with `docker compose up -d`; 25 tests / 12 skipped (store + migration) with the DB down. `composer validate --strict` clean. `php -l` clean on all new files. `vendor/bin/phinx list` / `status -e development` still resolve. Seed script: `20 inserted, 0 unchanged` then `0 inserted, 20 unchanged`.
- **ISINs are best-effort** (per the frozen decision) — several Swedish large caps changed ISIN in post-2021 splits (Atlas Copco, Hexagon, Boliden, Evolution); Stories 1.4/1.5 confirm each against live Avanza/Nordnet responses, where a wrong ISIN surfaces as `NotFound`.

## Spec Change Log

## Review Triage Log

- 2026-09-08 — the step-04 multi-agent review (blind-hunter / edge-case-hunter / verification-gap) was skipped at the user's choice. Verification (`composer test`, `composer validate`, `php -l`, phinx migrate/rollback, seed idempotency) all passed; no findings triaged. A separate `/code-review` or `bmad-walkthrough` pass can still be run against this diff.

## Design Notes

- **Seed list as a `bin/` script, not a data migration** — the ~20-ISIN list is dev scaffolding Epic 2's Börsdata `UniverseSync` replaces; a script keeps it out of immutable migration history and exercises the repository write path before `FetchRunner`. The migration seeds only `settings` (per `docs/deploy.md:174`). Story 1.10 wires the script into the runbook.
- **Proposed `settings` seed values**, all ops-tunable without deploy: `run_after=18:30` (Avanza refreshes ~18–19 CET weekdays), `batch_size=25` (one slice covers ~20 ISIN within web-PHP 256 MB), `rate.avanza=0.5`, `rate.nordnet=0.5` (2 s spacing), `queue.stale_after=900` (15 min).
- **Store tests are integration tests** against the compose `development` DB and self-skip when it is down — no SQLite allowed, so this keeps `composer test` green without Docker. First DB-touching test; build the skip helper cleanly for reuse.

## Verification

**Commands:**
- `composer validate --strict` -- still valid
- `docker compose up -d && vendor/bin/phinx migrate -e development` -- exit 0, both tables created
- `vendor/bin/phinx status -e development` -- the new migration listed as up
- `php bin/seed-instruments.php` -- exit 0, "N inserted"; second run "0 inserted, N unchanged"
- `composer test` -- green with store tests running (DB up) or skipped (DB down)
- `php -l` on each new PHP file -- no syntax errors

**Manual checks:**
- `SELECT * FROM settings;` -- 5 canonical keys, agreed values
- `SELECT isin, first_seen, last_seen, avanza_orderbook_id FROM instrument LIMIT 5;` -- ids + `last_seen` NULL, `first_seen` today
