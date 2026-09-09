title: "Story 1.8: Minimal körningslogg (ingest_run)"
type: "feature"
created: "2026-09-09"
status: "done"
route: "dispatch"
review_loop_iteration: 0
baseline_commit: "c21aee3f79e8d0b697f091536592fc8aeabaef0e"
context:

- "{project-root}/AGENTS.md"
- "{project-root}/\_bmad-output/implementation-artifacts/epic-1-context.md"

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `FetchRunner` and `Enqueue` run and return counts, but nothing is written down — after an unattended night there is no inspectable trace that the pipe lived or roughly what it did (AD-11, FR13 minimal).

**Approach:** A Phinx migration for `ingest_run`, a PDO `RunRepository` that appends one row per pipeline run, and a `record(...)` call wired into `Enqueue::run()` and `FetchRunner::run()` so every invocation — including the timebox early-exit — leaves a row with its start/finish times and counts. A thin `bin/show-runs.php` prints recent rows over SSH.

**Decisions (2026-09-09):**

- **`ingest_run` shape:** surrogate `id` BIGINT UNSIGNED AUTO_INCREMENT PK; `run_type` VARCHAR(16) NOT NULL (`fetch` | `enqueue` in Epic 1; `universe_sync` in Epic 2); `run_date` DATE NOT NULL; `started_at` / `finished_at` DATETIME NOT NULL (UTC); `instrument_count`, `ok_count`, `fail_count` INT UNSIGNED NOT NULL. No FK. `run_date` is a deliberate addition beyond the epic's column list — it is the correlation key to `work_queue` and the natural filter for `bin/show-runs.php`.
- **One row per run, appended, never updated** (AD-11 "varje slice skriver"): a night is one `enqueue` row plus one `fetch` row per `/cron/work` slice. `RunRepository` only ever `INSERT`s.
- **The pipeline classes write their own row** (epic AC: "via `RunRepository`"; AD-11). `Enqueue` and `FetchRunner` each take a `RunRepository` as a new constructor dependency and call `record(...)` once, at the end of `run()`, before returning. Story 1.9's thin cron endpoints stay free of run-logging logic.
- **`FetchRunner` single exit:** restructure `run()` so the timebox path `break`s out of the job loop instead of `return`ing early — the `record(...)` call and the `FetchRunnerResult` are then built once, at the bottom, and the timebox slice is logged like any other. The per-job `catch \Throwable` already keeps the loop alive; a throw from a repository call outside the loop is not caught and (correctly) aborts without a row.
- **Count mapping.** `fetch`: `instrument_count = FetchRunnerResult.claimed`, `ok_count = done`, `fail_count = failed` (`reopened` / `rowsWritten` are not in the minimal row — Story 2.6's fuller log carries the per-source and row-level detail). `enqueue`: `instrument_count = count(universe)`, `ok_count = rows created`, `fail_count = 0`.
- **Times** are UTC `DateTimeImmutable`s captured in `run()` (`started_at` at entry, `finished_at` just before `record`), stored as naive `DATETIME` understood as UTC — same convention as `owner_count_daily.fetched_at` and `work_queue.claimed_at`.
- **`bin/show-runs.php`** — thin wrapper like `bin/seed-instruments.php`: `require bootstrap.php`, build `RunRepository` from `Database::connect()`, print the most recent N rows (default 20) as an aligned table; optional `--date=YYYY-MM-DD` filter; `catch \Throwable` → log `error` + `exit(1)`.
- **Per-datum run link deferred (decided 2026-09-09).** `owner_count_daily` is not touched and `OwnerCountRepository::upsert()` keeps its Story 1.7 signature. The spine's `ingest_run ||--o{ owner_count_daily` relationship is realized in Story 2.6 with a nullable `ingest_run_id` column — no backfill (NFR7). `RunRepository` stays append-only in this story. Recorded in `deferred-work.md`.

## Boundaries & Constraints

**Always:**

- All `ingest_run` DB access through `RunRepository` (PDO, no ORM); no SQL in `Enqueue` / `FetchRunner` / the bin script.
- `RunRepository` only appends — no update/delete methods in this story.
- `Enqueue::run()` still returns `int` (rows created); `FetchRunner::run()` still returns `FetchRunnerResult` with the same five fields. The run-log write is a side effect, not a signature change to the return types.
- New store integration tests self-skip when MariaDB is down; `composer test` stays green with no DB.
- `snake_case` singular table name, UTC datetimes, surrogate-`id` migration style — match `work_queue` (Story 1.7).

**Never:**

- No `ingest_run_id` column on `owner_count_daily` and no change to `OwnerCountRepository::upsert()` — the per-datum run link is deferred to Story 2.6 (see the decision above).
- No per-source `ok`/`fail` breakdown, no `SchemaMismatch` counter, no alarms (Story 2.6 / FR13 full).
- No `/cron/*` endpoints (Story 1.9), no `UniverseSync` / `run_type = 'universe_sync'` (Epic 2).
- No retention / pruning of old `ingest_run` rows (tracked in `deferred-work.md`).
- Do not modify `docs/deploy.md` or `bin/deploy.sh` (Story 1.10).

## I/O & Edge-Case Matrix

| Scenario                       | Input / State                                                          | Expected Behavior                                                                                                                                      | Error Handling      |
| ------------------------------ | ---------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------- |
| Migrate up/down                | `phinx migrate` / `rollback`                                           | `ingest_run` created with the surrogate `id` and the seven columns; `down()` drops it; `rollback -t 0` then `migrate` clean                            | Phinx non-zero exit |
| Enqueue writes a row           | `Enqueue::run('2026-09-09')` over a 20-instrument universe, 20 created | one `ingest_run` row: `run_type='enqueue'`, `run_date='2026-09-09'`, `instrument_count=20`, `ok_count=20`, `fail_count=0`, `finished_at >= started_at` | —                   |
| Enqueue re-run                 | queue already full, 0 created                                          | a second `enqueue` row with `ok_count=0` (the run still happened)                                                                                      | —                   |
| FetchRunner slice writes a row | slice claims 5, 4 done, 1 failed                                       | one row: `run_type='fetch'`, `instrument_count=5`, `ok_count=4`, `fail_count=1`                                                                        | —                   |
| Timebox early exit             | timebox trips after job 2 of 10                                        | still exactly one `fetch` row, counts reflecting the partial slice; leftovers already reopened by `FetchRunner`                                        | —                   |
| Empty queue slice              | `FetchRunner::run()` with nothing pending                              | one `fetch` row, all counts 0, `finished_at >= started_at`                                                                                             | —                   |
| Read recent                    | `RunRepository::recent(20)` after several runs                         | rows newest-first (by `id` desc), at most 20                                                                                                           | —                   |
| Read by date                   | `RunRepository::forRunDate('2026-09-09')`                              | only that run date's rows, oldest-first                                                                                                                | —                   |
| `show-runs` no DB rows         | fresh DB                                                               | prints a header and "no runs" (or an empty table), exit 0                                                                                              | —                   |

</frozen-after-approval>

## Code Map

- `db/migrations/20260909150000_create_work_queue.php` — the migration pattern to copy verbatim: `->table('…', ['id' => false, 'primary_key' => ['id']])->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])`, explicit `'null'`, UTC comment on datetimes, `up()`/`down()`. New timestamp must sort after this one.
- `src/Store/QueueRepository.php` / `src/Store/QueueJob.php` — the exact shape for `RunRepository` / `IngestRun`: ctor takes `PDO`, prepared statements, backtick-quoted identifiers, a readonly VO with `fromRow()`, `array_map(fn → VO::fromRow(...))` over `fetchAll()`.
- `src/Pipeline/Enqueue.php` — add `RunRepository` as a 3rd constructor arg; capture `$startedAt` at the top of `run()`, `record(...)` just before `return $created`.
- `src/Pipeline/FetchRunner.php` — add `RunRepository` as an 8th constructor arg (after `$logger`, before `?callable $sleep`). `run()` currently has an early `return` in the timebox branch (around the `array_slice` reopen loop) and a final `return`; collapse to one exit so the `record(...)` + `FetchRunnerResult` are built once. `$now` (already a UTC `DateTimeImmutable` captured at entry) is `started_at`.
- `src/Pipeline/FetchRunnerResult.php` — the five fields `record(...)` maps from (`claimed`/`done`/`failed`).
- `src/Store/Database.php` — `Database::connect(Config): PDO` for the bin script.
- `bin/seed-instruments.php` — the wrapper shape for `bin/show-runs.php` (bootstrap, build repo, do work, `catch \Throwable` → `error` + `exit(1)`).
- `tests/Store/StoreTestCase.php` — `createSchema()` / `dropSchema()` hand-mirror the migration DDL; add `ingest_run` (no FK, so drop order is not sensitive, but keep it tidy). The mirror-vs-migration drift is a known `deferred-work.md` item — follow the existing pattern.
- `tests/MigrationTest.php` — `dropAll()` list + `tableExists` assertions on migrate and rollback; add `ingest_run` and pin its PK / column set like `work_queue`.
- `tests/Pipeline/EnqueueTest.php` / `tests/Pipeline/FetchRunnerTest.php` — both construct the pipeline classes directly and will need the new `RunRepository` arg; `FetchRunnerTest` has a `runner()` helper and a `FakeSourceAdapter` (`tests/Support/`).

## Tasks & Acceptance

**Execution:**

- [x] `db/migrations/20260909160000_create_ingest_run.php` — `ingest_run` per the frozen shape (surrogate `id`; `run_type`, `run_date`, `started_at`, `finished_at`, `instrument_count`, `ok_count`, `fail_count`; plus `ix_ingest_run_run_date`). `down()` drops it.
- [x] `src/Store/IngestRun.php` — readonly VO: `id`, `runType`, `runDate`, `startedAt`, `finishedAt`, `instrumentCount`, `okCount`, `failCount`; `fromRow()`.
- [x] `src/Store/RunRepository.php` — ctor `(PDO)`. `record(...)` (returns the new id); `recent(int $limit = 20): list<IngestRun>` (id desc); `forRunDate(string $runDate, ?int $limit = null): list<IngestRun>` (id asc, bound `LIMIT :lim` when a limit is given). Datetimes bound as UTC `Y-m-d H:i:s`.
- [x] `src/Store/RunTable.php` — pure, DB-free `static render(list<IngestRun>): string` (header + separator + rows or `no runs`); extracted from the bin script so the empty-DB matrix row is unit-testable (mirrors the `SourceIdResolver` split of `bin/resolve-ids.php`).
- [x] `src/Pipeline/Enqueue.php` — new `RunRepository` dependency; write one `enqueue` row per `run()`.
- [x] `src/Pipeline/FetchRunner.php` — new `RunRepository` dependency; single-exit refactor; write one `fetch` row per `run()`, including the timebox path and the empty-queue path.
- [x] `bin/show-runs.php` — thin wrapper; parse + validate args → `recent($limit)` / `forRunDate($date, $limit)` → `echo RunTable::render(...)`; default 20, optional `--date=YYYY-MM-DD` / `--limit=N` (both paths honour `--limit`); a malformed `--date` (`createFromFormat('!Y-m-d')` rejects overflow) or `--limit <= 0` → usage line on STDERR + `exit(1)`; `catch \Throwable` → `error` + `exit(1)`.
- [x] `tests/Store/StoreTestCase.php` + `tests/MigrationTest.php` — add `ingest_run` to the DDL mirror, drop set, migrated-schema assertions, and the `ix_ingest_run_run_date` index check (via `indexColumns()`, like `work_queue`).
- [x] `tests/Store/RunRepositoryTest.php` — extends `StoreTestCase`; `record()` inserts and returns an id; UTC normalisation; `recent()` newest-first, honours an explicit limit, and defaults to the newest 20 (22-row case); `forRunDate()` scoped and oldest-first.
- [x] `tests/Store/RunTableTest.php` — plain `TestCase`, no DB; empty list → header + `no runs`; rows → one aligned line each with the id / type / counts present.
- [x] `tests/Store/ShowRunsScriptTest.php` — `exec()`s the real script (mirrors `MigrationTest::phinx()`, self-skips when the dev DB is unreachable/unmigrated): bad `--date` and bad `--limit` each exit non-zero with the usage line; `--date=1999-01-01` exits 0 and prints `no runs`.
- [x] `tests/Pipeline/EnqueueTest.php` — update construction; assert an `enqueue` `ingest_run` row is written with the right `run_type` / `run_date` / counts on first run and on a zero-created re-run.
- [x] `tests/Pipeline/FetchRunnerTest.php` — update construction; assert exactly one `fetch` row per `run()` with counts matching the `FetchRunnerResult`, for: a normal slice, the timebox early-exit (both variants), and an empty queue.

**Acceptance Criteria:**

- Given the Phinx migrations, when `phinx migrate` runs, then `ingest_run` exists with `started_at`, `finished_at`, `instrument_count`, `ok_count`, `fail_count`, `run_type` (and `run_date`); `rollback -t 0` then `migrate` is clean.
- Given an `Enqueue::run()` or `FetchRunner::run()` call, when it finishes — including a timebox early-exit — then exactly one `ingest_run` row is appended via `RunRepository` with the start/finish times and the counts for that run.
- Given several runs, when `bin/show-runs.php` is run over SSH, then the recent `ingest_run` rows are printed legibly, and `--date=YYYY-MM-DD` narrows to one run date.
- Given `composer test` with the DB up, then the new `RunRepository` / pipeline tests pass; with the DB down they skip and the suite stays green.

## Implementation Notes

- **2026-09-09 — implemented.**
  - `db/migrations/20260909160000_create_ingest_run.php` — surrogate `id` BIGINT UNSIGNED, the seven typed columns, UTC-comment datetimes, plus a non-unique `ix_ingest_run_run_date` index (matches the `work_queue` style; supports `forRunDate()` / `--date`). `down()` drops the table.
  - `src/Store/IngestRun.php` — readonly VO with `fromRow()`; `startedAt` / `finishedAt` held as naive UTC `Y-m-d H:i:s` strings, same convention as `QueueJob::claimedAt`.
  - `src/Store/RunRepository.php` — ctor `(PDO)`; `record()` (INSERT only, returns `lastInsertId()`), `recent(int $limit = 20)` (id desc, `LIMIT :lim` bound `PARAM_INT` like `QueueRepository::claimBatch`), `forRunDate(string $runDate, ?int $limit = null)` (id asc; appends the same bound `LIMIT :lim` only when a limit is passed). Datetimes normalised to UTC before binding.
  - `src/Pipeline/Enqueue.php` — `RunRepository` added as the 3rd ctor arg; `$startedAt` captured at entry, one `enqueue` row written before `return $created` (`instrument_count = count(universe)`, `ok_count = created`, `fail_count = 0`).
  - `src/Pipeline/FetchRunner.php` — `RunRepository` added as the 8th ctor arg (after `$logger`, before `?callable $sleep`). The timebox branch now `break`s instead of `return`ing; the `record('fetch', …)` call and the `FetchRunnerResult` are built once at the shared tail (`instrument_count = claimed`, `ok_count = done`, `fail_count = failed`). `$now` (UTC, captured at entry) is `started_at`.
  - `src/Store/RunTable.php` + `bin/show-runs.php` — the aligned-table rendering is a pure `RunTable::render(list<IngestRun>): string` (header + `-` separator + rows, or `"no runs\n"` for an empty list); the bin script is just arg parsing (`--limit=N` default 20 → `recent()`, `--date=YYYY-MM-DD` → `forRunDate()`) → `echo RunTable::render(...)`, `catch \Throwable` → `logger->error` + `exit(1)`. Split so the empty-DB I/O-matrix row is covered by a DB-free unit test (same pattern as `SourceIdResolver` vs `bin/resolve-ids.php`).
  - Tests: `tests/Store/RunRepositoryTest.php` + `tests/Store/RunTableTest.php` (new); `ingest_run` added to the `StoreTestCase` DDL mirror and the `MigrationTest` drop set + migrated-schema assertions; `EnqueueTest` / `FetchRunnerTest` updated for the new ctor arg and asserting the `ingest_run` rows (normal slice, timebox early-exit both variants, empty queue, zero-created enqueue re-run).
- **Verification:** `composer test` green (125 tests, 507 assertions, DB up); `php -l` clean on all new/changed files; `composer validate --strict` valid; `phinx migrate` / single-step `rollback` / re-`migrate` clean on the dev DB; `DESCRIBE ingest_run` shows the surrogate id + seven columns; `bin/show-runs.php` (default → "no runs" + exit 0, `--date`, `--limit`, bad-arg → usage + exit 1) exercised against the dev DB; boundary grep — no `ingest_run` SQL outside `src/Store/`.
- **Deviations from Code Map:** migration named `20260909160000` (spec wrote `2026090916xxxx`; sorts after `20260909150000_create_work_queue`). Table rendering extracted into `src/Store/RunTable.php` rather than kept inline in the bin script, to satisfy the Matrix Test Audit for the "no DB rows" row without a DB fixture.

## Spec Change Log

## Review Triage Log

### Iteration 2 (2026-09-09) — 3 layers (blind-hunter N=9, edge-case-hunter, verification-gap)

**Routed to patch:**

| #   | Finding                                                                                                                                             | Verdict | Evidence / fix                                                                                                                        |
| --- | --------------------------------------------------------------------------------------------------------------------------------------------------- | ------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| P1  | Date-filtered `--limit` behavior was implemented but not verified at repository level.                                                              | low     | `forRunDate()` includes a bound `LIMIT`; the focused repository test now inserts three rows for one date and asserts a two-row limit. |
| P2  | A caught unexpected job exception did not assert the persisted `fetch` run-log row.                                                                 | low     | The existing continuation test now checks one row with `instrument_count=2`, `ok_count=1`, and `fail_count=1`.                        |
| P3  | Malformed CLI argument tests were skipped whenever the development database was unavailable, despite validation occurring before the DB connection. | low     | Database readiness is now required only by the valid invocation test; malformed date and limit checks always execute.                 |
| P4  | Bootstrap failure occurred outside the script's documented throwable handler.                                                                       | low     | Bootstrap now runs inside the handler, with configured logging when available and `error_log()` as the fallback.                      |

**Rejected:**

| Finding                                                                                                | Verdict | Refutation                                                                                                                                                                                                                  |
| ------------------------------------------------------------------------------------------------------ | ------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Fixed-width `RunTable` columns may overflow for large ids, counters, or future `universe_sync` values. | false   | Story 1.8 explicitly limits `run_type` to `fetch`/`enqueue`; the chosen widths cover the current diagnostic output and future sizing belongs with the fuller Story 2.6 log.                                                 |
| `RunRepository` should validate `runType` and reject negative limits.                                  | false   | The repository is an internal typed writer; the frozen shape and callers constrain values, while CLI input validation rejects non-positive limits. Adding a second domain-validation surface is outside this minimal story. |
| Enqueue exceptions leave partial mutations without a run row.                                          | false   | The frozen decision requires one record at the end of a successful `run()` and explicitly states that repository failures outside the per-item handling abort without a row.                                                |
| Database-backed tests can pass while skipped when MariaDB is unavailable.                              | false   | Self-skipping without MariaDB is an explicit frozen constraint; the environment-specific integration verification is documented separately and the pure CLI validation now remains active.                                  |
| CLI script lacks populated-output and combined date/limit end-to-end tests.                            | low     | The repository-level limit assertion covers the changed SQL behavior; adding persistent development-DB fixture management to the subprocess test adds more risk than this diagnostic story warrants.                        |
| Sprint status still says `in-progress`.                                                                | patch   | Corrected to `review` to match the story artifact and completed implementation state.                                                                                                                                       |

### Iteration 1 (2026-09-09) — 3 layers (blind-hunter N=8, edge-case-hunter, verification-gap)

verification-gap: no gaps found.

**Routed to patch:**

| #   | Finding                                                                                                                                                                                                                                     | Verdict | Evidence / fix                                                                                                                                                                                                                                                                                    |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| P1  | `bin/show-runs.php` silently ignores `--limit` when `--date` is given (`forRunDate()` takes no limit), and `forRunDate()` has no `LIMIT` at all — a busy `run_date` dumps every slice row over SSH.                                         | low     | Confirmed at `bin/show-runs.php` (`$date !== null ? forRunDate($date) : recent($limit)`) and `RunRepository::forRunDate()` (no `LIMIT`). Fix: `forRunDate(string $runDate, ?int $limit = null)` with a bound `LIMIT` when given; the script passes `$limit` on both paths.                        |
| P2  | Well-formed-but-invalid CLI input fails silently: `--date=2026-13-45` passes the `\d{4}-\d{2}-\d{2}` regex, `--limit=0` passes `\d+`; both print "no runs", exit 0 — indistinguishable from a genuinely empty result for a diagnostic tool. | low     | Confirmed. Fix: validate `--date` with `DateTimeImmutable::createFromFormat('!Y-m-d', …)` and reject an impossible date; `--limit` → reject `<= 0` (usage + non-zero exit). Add one `exec()`-based test (like `MigrationTest::phinx()`) asserting a non-zero exit for a bad date and a bad limit. |
| P3  | `RunRepository::recent()`'s default limit of 20 is never asserted — only `recent(3)` is tested, so the I/O-matrix "at most 20" row and a regression in the default would pass CI.                                                           | low     | Confirmed in `RunRepositoryTest`. Fix: insert 22 rows, assert `count(recent()) === 20`.                                                                                                                                                                                                           |
| P4  | `ix_ingest_run_run_date` is added in the migration and the `StoreTestCase` mirror but pinned by no test — it is load-bearing for `forRunDate()` / `--date`, and Story 1.7's P3 set the precedent of asserting `work_queue`'s index.         | low     | Confirmed: `MigrationTest` asserts the PK and every column type for `ingest_run` but not the index. Fix: assert `ix_ingest_run_run_date` columns via the existing `indexColumns()` helper.                                                                                                        |

**Rejected:**

| Finding                                                                                                                                                                                                                | Verdict        | Refutation                                                                                                                                                                                                                                                                                                                                                      |
| ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ok_count` means different things per `run_type` (`fetch`: jobs done; `enqueue`: rows created), so an idempotent enqueue re-run logs `20 / 0 / 0` and looks alarming; no "skipped/already-present" count.              | reject         | The frozen Decisions define exactly this mapping ("`enqueue`: `ok_count = rows created`"). FR13-minimal is deliberately minimal; the readable, per-source, skip-aware log is Story 2.6. Fixing it edits the frozen block.                                                                                                                                       |
| Timebox early-exit row's counts don't add up (`instrument_count = claimed`, reopened jobs in neither `ok` nor `fail`), and `reopened` is dropped so a timeboxed slice looks like jobs vanished.                        | reject         | The frozen Decisions explicitly exclude `reopened` / `rowsWritten` from the minimal row ("Story 2.6's fuller log carries the … detail"). `work_queue.countByStatus()` is the source of truth for what's left. Spec-accepted.                                                                                                                                    |
| `--limit=N` is scope beyond the frozen spec (which says only "most recent N rows (default 20)" and "optional `--date` filter") with no Spec Change Log entry.                                                          | reject (false) | "the most recent N rows (default 20)" reasonably reads as N being caller-settable with a default of 20; a `--limit` flag is a faithful realization, not a scope expansion.                                                                                                                                                                                      |
| The frozen "a throw from a repository call outside the loop … correctly aborts the slice without a row" is untested.                                                                                                   | reject         | Spec-frozen behaviour; `record()` shares the connection that just did dozens of successful writes, so the trigger is near-unreachable; a negative-assertion "no catch here" test is brittle against legitimate future refactors.                                                                                                                                |
| `RunTable` uses `%-6s` for `id` and `%6s` for the counts / `%-8s` for `run_type`; a `BIGINT`/`INT` value or `run_type='universe_sync'` (13 chars) overflows and breaks alignment; the test only uses 1–2-digit values. | reject         | `universe_sync` is Epic 2 (`run_type` frozen as `fetch`/`enqueue` here); at this app's volume `id` stays well under 6 digits for years. Story 2.6 reworks the log and will size columns when `universe_sync` lands.                                                                                                                                             |
| `run_type` has no DB-level constraint, diverging from `work_queue.status`'s ENUM.                                                                                                                                      | reject (false) | `work_queue.status` is `VARCHAR(16)`, not an ENUM (Story 1.7 migration). `ingest_run.run_type` as `VARCHAR(16)` is _consistent_ with it and with `owner_count_daily.source`; values are code-enforced project-wide.                                                                                                                                             |
| `Enqueue` writes no `ingest_run` row if `queue->enqueue()` throws mid-loop — asymmetric with `FetchRunner`'s per-job `catch \Throwable`.                                                                               | reject         | `Enqueue`'s loop is pure inserts over ISINs that provably exist in `instrument` (FK can't fire); it is idempotent and the next `/cron/refill` completes it and logs a row. Its failure self-heals, unlike `FetchRunner`'s stuck-`claimed` jobs, so the missing per-item catch is justified. The docblock claims only that a _no-op_ run is logged, which holds. |

## Design Notes

- **Append-only, one row per slice** — matches AD-11's "varje slice skriver" and keeps `RunRepository` trivial. A night's story is reconstructed by reading all rows for a `run_date` in order: the `enqueue` row, then each `fetch` slice. No row is ever revised, so a crashed slice simply has no row — itself a signal.
- **`run_date` in the row** even though the epic's column list omits it: without it, correlating a `fetch` row to its `work_queue` jobs means guessing from timestamps. It costs one `DATE` column and both writers already hold the value.
- **Single exit in `FetchRunner::run()`** — the run-log write must happen on every path, and duplicating it at two `return`s invites drift. Collapsing the timebox branch to a `break` is a small, safe change (the leftover-reopen loop runs, then falls through to the shared tail).

## Verification

**Commands:**

- `docker compose up -d && vendor/bin/phinx migrate -e development` — exit 0, `ingest_run` created
- `vendor/bin/phinx rollback -e development -t 0 && vendor/bin/phinx migrate -e development` — clean down/up (note: a full `rollback -t 0` on a dev DB holding a real 36-char nnx id fails in the pre-existing Story 1.6 `WidenNordnetInstrumentId::down()` — unrelated; `MigrationTest` covers a clean full rollback on a throwaway DB)
- `composer test` — green (DB up: new tests run; DB down: skip)
- `composer validate --strict`, `php -l` on new files — clean
- boundary grep: no `ingest_run` SQL outside `src/Store/`
- `php bin/show-runs.php` against the dev DB after a manual `Enqueue` — prints the row

**Manual checks:**

- `DESCRIBE ingest_run;` — surrogate `id`, the seven typed columns, UTC-comment datetimes
