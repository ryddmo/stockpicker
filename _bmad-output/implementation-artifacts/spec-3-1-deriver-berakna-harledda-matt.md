---
title: 'Story 3.1: Deriver — beräkna härledda mått'
type: 'feature'
created: '2026-09-11'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: 'e6a115327eb9853749ed2f465edc68264cde4a51'
context:
  - _bmad-output/implementation-artifacts/epic-3-context.md
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `owner_count_daily` is a real, append-only time series (Epics 1–2) but only carries
raw daily levels — no change, trend, or anomaly signal an analyst can read at a glance.

**Approach:** Add a MariaDB SQL view (decided 2026-09-11 — no materialized table, no refresh
step) computing `delta_1d`, `pct_1d`, `sma_7`, `sma_30`, `sma_90`, `up_streak`, and
`spike_score` per `(isin, source)` via window functions, using the exact formulas already on
record in `addendum.md`. A new `DerivedMetricsRepository` in `src/Store/` reads it — no SQL
outside `src/Store/`. A thin `Deriver` pipeline filter (decided 2026-09-11 — added now, even
though it only delegates today, to keep the architecture spine's stated shape and give
Stories 3.2/3.3 one settled name to depend on) exposes it. Nothing writes to
`owner_count_daily`; it stays untouched.

## Boundaries & Constraints

**Always:**
- Formulas (from `addendum.md`, per `(isin, source)` ordered by `as_of_date`):
  `delta_1d` = `number_of_owners(t) − number_of_owners(t−1)`; `pct_1d` = `delta_1d /
  number_of_owners(t−1)` (a ratio, e.g. `0.05`, never ×100); `sma_7`/`sma_30`/`sma_90` = the
  mean of the trailing 7/30/90 **stored rows** (row-based window, not calendar-day range —
  matches the AC's plain "fewer than 7 datapoints" wording); `up_streak` = count of
  consecutive trailing rows with `delta_1d > 0` (strict; a flat or down day resets it to 0);
  `spike_score` = `(number_of_owners(t) − sma_30) / stddev(trailing 30 rows)`.
- A metric is `NULL`, never an error, whenever its window has fewer rows than it needs
  (`sma_7` before the 7th stored row, `sma_30`/`spike_score` before the 30th, `sma_90` before
  the 90th) — this is expected and will be the common case for weeks against the freshly
  bootstrapped universe.
- Gap handling (decision, 2026-09-11): `delta_1d` and `pct_1d` are `NULL` when the two
  consecutive stored rows they compare are **not exactly one calendar day apart**
  (`DATEDIFF` ≠ 1) — a value spanning a multi-day gap must never be reported as "the daily
  change." `sma_*`, `up_streak`, and `spike_score` stay row-based regardless of gaps (they
  already tolerate the occasional missing day by design). **Reconfirmed on review
  (2026-09-11):** `up_streak` is deliberately keyed on the always-available row-to-row
  direction, not on the gap-nulled `delta_1d` — addendum.md's plain-language formula reads
  literally as gap-breaking, but the row-based reading was chosen on purpose, for
  consistency with every other row-based metric here, and confirmed after review flagged the
  divergence.
- `pct_1d`'s denominator is guarded (`NULLIF(…, 0)`) — a zero prior count yields `NULL`, never
  a division error. Both operands of every division (`pct_1d`, `spike_score`) must be cast to
  a `DECIMAL` with enough scale to represent a small ratio — plain integer division truncates
  to MariaDB's 4-decimal default and silently rounds a real change to `0.0000` for any
  large-owner-count instrument (found on review, 2026-09-11).
- The view is the **only** new object; `Deriver` (per the epic's Requirements) never writes to
  `owner_count_daily` or any table — read-only, no exceptions, no logging, no `ingest_run` row
  (there is nothing to "run" — a view has no execution to log).
- `DerivedMetricsRepository` (new, `src/Store/`) is the sole reader of the view from PHP —
  same "no SQL outside `src/Store/`" rule as every other repository.
- `Deriver` (new, `src/Pipeline/`) is a thin filter delegating to `DerivedMetricsRepository` —
  no SQL, no state, no timebox, no `ingest_run` row (nothing to log for a stateless read).
- MariaDB 10.11 window-function support for `STDDEV_SAMP()`/`AVG()` with a `ROWS` frame is
  assumed but must be verified against the real server (see Verification) — this project has
  no prior view or this class of SQL to copy.

**Never:**
- No materialized table, no refresh/rebuild step, no `/cron/derive` wiring (Story 3.2 — its
  shape is now open again given the view decision; not this story's problem).
- No extraction script or read endpoint (Story 3.3).
- No change to `owner_count_daily`, `OwnerCountRepository`, `NormalizedRow`, or any existing
  migration. No backfill, no historical reprocessing.
- No spike-classification / "is this notable" logic — only the raw numeric `spike_score`;
  interpreting it is explicitly a future analysis-layer concern.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Behavior | Error Handling |
|----------|--------------|-------------------|----------------|
| First-ever row for (isin, source) | no prior row | all seven metrics `NULL` | none |
| 2nd–6th row, consecutive days | `delta_1d`/`pct_1d` computable, `sma_7` not yet | `delta_1d`, `pct_1d` set; `sma_7/30/90`, `spike_score` `NULL`; `up_streak` counts what exists | none |
| 7th consecutive row | 7 rows, no gaps | `sma_7` set; `sma_30`, `sma_90`, `spike_score` still `NULL` | none |
| 30th consecutive row | 30 rows, no gaps | `sma_30`, `spike_score` set (stddev over 30 rows) | none |
| Gap: rows 2 days apart | `as_of_date` jumps e.g. Mon→Wed | `delta_1d`, `pct_1d` = `NULL` for that row; `sma_*`/`up_streak`/`spike_score` unaffected (row-based) | none |
| Prior count is zero | `number_of_owners(t−1) = 0` | `pct_1d` = `NULL`; `delta_1d` still computed | none |
| Down or flat day | `delta_1d <= 0` | `up_streak` resets to 0 for that row | none |
| Two sources, same isin | Avanza and Nordnet rows for the same day | each source's metrics computed independently, never combined | none |

</frozen-after-approval>

## Code Map

- `db/migrations/` — **new** migration, e.g. `20260912000000_create_owner_count_metrics_view.php`,
  `$this->execute("CREATE VIEW owner_count_metrics AS ...")` (Phinx has no first-class view
  helper; raw `execute()` is the established idiom — see
  `20260911100000_enrich_ingest_run.php`'s backfill `UPDATE`s). `down()` = `DROP VIEW
  owner_count_metrics`.
- `src/Store/OwnerCountRepository.php` — reference only, **do not modify**; its `get()` /
  `countForIsin()` shapes show the existing read-method style to mirror.
- `src/Store/DerivedMetricsRepository.php` — **new**. Ctor `(PDO $pdo)`, matching every other
  repository. Read-only: `SELECT * FROM owner_count_metrics WHERE isin = ? [AND source = ?]`
  behind typed methods (see Design Notes for the shape). `forIsin(string $isin): array`
  keyed by source, mirroring `InstrumentRepository`'s keyed-array return style.
- `src/Pipeline/Deriver.php` — **new**. Ctor `(private readonly DerivedMetricsRepository
  $metrics)`, mirroring the other pipeline classes' constructor-injection style.
  `forInstrument(string $isin): array` delegates straight to
  `DerivedMetricsRepository::forIsin()` — no logic of its own yet; Story 3.2 decides whether
  it grows one.
- `tests/Store/StoreTestCase.php:81-146` `createSchema()` — hand-mirrors every migration
  (`instrument`, `settings`, `ingest_run`, `owner_count_daily`, `work_queue`). **Add** a
  `CREATE VIEW owner_count_metrics AS ...` mirror after the `owner_count_daily` table is
  created (no view exists in this file today — first one). `dropSchema()` needs `DROP VIEW
  IF EXISTS owner_count_metrics` before dropping `owner_count_daily`.
- `tests/Store/OwnerCountRepositoryTest.php:row()` — reuse this `NormalizedRow` builder +
  `OwnerCountRepository::upsert()` to seed fixture rows across several `as_of_date`s; there is
  no direct-SQL seeding helper for `owner_count_daily` today.
- `ARCHITECTURE-SPINE.md` line 44 lists a `Normalizer` pipeline filter — **it was never built**;
  `src/Pipeline/` has no such class (normalization happens inline in each adapter's `fetch()`).
  Ignore that stage; it is stale documentation, not a dependency for this story.
- `tests/Store/DerivedMetricsRepositoryTest.php` — **new**, one case per I/O & Edge-Case
  Matrix row, seeded via the `row()`/`upsert()` pattern above against the real MariaDB
  (`StoreTestCase`).

## Tasks & Acceptance

**Execution:**
- [x] `db/migrations/<ts>_create_owner_count_metrics_view.php` -- `CREATE VIEW owner_count_metrics` with `delta_1d`/`pct_1d`/`sma_7`/`sma_30`/`sma_90`/`up_streak`/`spike_score` per the formulas above, gap-aware `delta_1d`/`pct_1d`, `down()` drops it -- the story.
- [x] `src/Store/DerivedMetricsRepository.php` -- new read-only repository over the view, `forIsin()` keyed by source -- keeps SQL out of `Deriver` and every future caller.
- [x] `src/Pipeline/Deriver.php` -- thin filter delegating to the repository -- settles the name Story 3.2/3.3 build on.
- [x] `tests/Store/StoreTestCase.php` -- mirror the view in `createSchema()`/`dropSchema()` -- integration tests can query it.
- [x] `tests/Store/DerivedMetricsRepositoryTest.php` -- one case per I/O & Edge-Case Matrix row -- edge-case coverage, incl. the gap and zero-prior-count cases.
- [x] `tests/Pipeline/DeriverTest.php` -- confirms delegation returns exactly what the repository returns -- regression guard on the thin wrapper.
- [ ] `db/migrations/<ts>_create_owner_count_metrics_view.php` + `tests/Store/StoreTestCase.php` -- cast both operands of `pct_1d` and `spike_score`'s division to a `DECIMAL` with enough scale (not plain integer division, which truncates to 4 decimals) -- fixes a real precision bug found on review.
- [ ] `db/migrations/<ts>_create_owner_count_metrics_view.php` -- `down()` uses `DROP VIEW IF EXISTS` -- matches the test helpers' robustness, survives a re-run rollback.
- [ ] `tests/Store/DerivedMetricsRepositoryTest.php` -- add a decreasing-owner-count case asserting `delta_1d`/`pct_1d` are correctly negative (regression guard on the unsigned-subtraction bug already caught once) -- and a 90-row case asserting `sma_90` is non-null and correct, `NULL` before row 90.
- [ ] `tests/MigrationTest.php::testRollbackDropsEverything` -- also assert `owner_count_metrics` no longer exists after rollback -- the view's `down()` path is currently unverified by any test.

**Acceptance Criteria:**
- Given a `(isin, source)` series with at least 30 consecutive daily rows, when the view is queried, then every metric requiring 30 rows or fewer (`delta_1d`, `pct_1d`, `sma_7`, `sma_30`, `up_streak`, `spike_score`) is a non-null number matching the stated formulas (verified against a hand-computed fixture); `sma_90` stays `NULL` until a 90th row exists.
- Given a `(isin, source)` series with at least 90 consecutive daily rows, when the view is queried, then `sma_90` is also a non-null number matching the stated formula.
- Given a large `number_of_owners` value (e.g. hundreds of thousands) with a small day-over-day change, when `pct_1d` is computed, then the true ratio is preserved (not truncated to `0.0000` by integer-scale division).
- Given a series with a gap larger than one day, when the view is queried for the row after the gap, then `delta_1d` and `pct_1d` are `NULL` for that row while the row-based metrics are unaffected.
- Given a series with fewer than 7/30/90 rows, when the view is queried, then the metrics requiring more history are `NULL`, never an error.
- Given `composer test`, then the full suite is green with no regressions; `php -l` clean on all new PHP.

## Implementation Notes

Migration: `db/migrations/20260911170000_create_owner_count_metrics_view.php`. Verified
live against the docker-compose MariaDB 10.11 (`vendor/bin/phinx migrate -e development`,
manual seeded `SELECT`s) before trusting the automated tests, per the Verification section.

**Bug caught by the manual check, fixed before it reached the tests:** `number_of_owners`
and `prev_owners` are `INT UNSIGNED`. MariaDB's default `sql_mode` (no
`NO_UNSIGNED_SUBTRACTION`) makes an unsigned-minus-unsigned subtraction produce an
**unsigned** result — a real day-over-day *decrease* wrapped around into a huge positive
number (`DESCRIBE owner_count_metrics` first showed `delta_1d` as `bigint(20) unsigned`).
That would have both reported a decrease as an enormous positive `delta_1d`/`pct_1d` *and*
made `up_streak`'s `raw_delta > 0` test misclassify every decrease as an "up" day. Fixed by
casting both operands to `SIGNED` before subtracting (`raw_delta` in the `deltas` CTE);
confirmed with a manual seeded row where owners drops to 0 then to 50 — `delta_1d` now
reads `-1030`, not a huge unsigned wraparound value.

**`up_streak` formula, made precise (the Design Notes sketch left this implicit):** the
"gaps and islands" grouping puts each non-up row (`raw_delta <= 0`, or `NULL` on a
partition's first row) together with the run of up-rows that *follows* it, as that group's
anchor. A plain `COUNT(*)` within the group therefore counts the anchor row too, so the
final `up_streak` is `grp_count - 1` on an up row (not `grp_count` as the sketch's gate
implied), `0` on a down/flat row, and `NULL` only on the series' very first row (no prior
row exists at all — matches the I/O matrix's "first-ever row: all seven metrics NULL").
Confirmed against a hand-traced 6-row fixture (mixed up/down/up) and the 30-row
strictly-increasing fixture (`up_streak = 29` on the 30th row, i.e. 29 increases over 30
days).

`spike_score` uses `STDDEV_SAMP` per the Design Notes. Its value on the real server differs
from an independent high-precision (Python `Decimal`) hand computation by ~3e-7 on a
30-row fixture — ordinary floating-point noise from MariaDB's incremental windowed
aggregate, not a formula error; `DerivedMetricsRepositoryTest` asserts with a small delta
rather than exact equality for that reason.

`DerivedMetricsRepository::forIsin()` groups the view's rows by `source` into
`array<string, list<array>>` (each value the ordered per-date series) — the view returns
many rows per `(isin, source)`, not one, so "keyed by source" from the Code Map means one
list per source rather than one row per source.

Fixed a latent regression the new view exposed in `tests/MigrationTest.php`: its `dropAll()`
dropped every table but not `owner_count_metrics`, so a second `phinx migrate` in the same
`testing` database (as `testRollbackDropsEverything`'s `setUp()` does) failed with "table
already exists" on `CREATE VIEW`. Added the `DROP VIEW IF EXISTS` there, plus a small
assertion in `testMigrateCreatesTheSchemaAndSeedsSettings` that the view exists and is
queryable right after a real `phinx migrate`.

`composer test`: 241 tests, 1177 assertions, all green (run twice to rule out order
flakiness). `php -l` clean on every new/changed PHP file.

**2026-09-11 — review pass 1 patches applied.** `pct_1d` and `spike_score` now divide
`DECIMAL(20,10)`-cast operands instead of plain integers — live-verified `10/500000` returns
`0.00002000000000`, not the previously-truncated `0.0000` (same cast applied to both the
migration and the `StoreTestCase` mirror, kept byte-identical). Migration `down()` is now
`DROP VIEW IF EXISTS`. Added a decreasing-series test locking in the unsigned-subtraction fix,
and a 90-row hand-computed `sma_90` test (`NULL` at row 89, correct at row 90).
`testRollbackDropsEverything` now also asserts the view is gone after rollback.
`composer test`: 243 tests, 1280 assertions, green.

## Spec Change Log

- **2026-09-11 — review pass 1, renegotiation.** Review flagged that the frozen boundary's
  `up_streak` decision (row-based, gap-tolerant) reads against addendum.md's literal
  `delta_1d`-based formula (which would break on any gap). Put to the human directly rather
  than treated as a silent implementation bug: **confirmed row-based/gap-tolerant**, as
  originally specified — no code change. The frozen text now records this as reconfirmed on
  review, not merely an unexamined first draft. Also amended (non-frozen): the `pct_1d`/
  `spike_score` division precision requirement, made explicit after review found plain
  integer division silently truncating real changes to `0.0000` for large-owner-count
  instruments; two ACs corrected (the "every metric non-null at 30 rows" AC wrongly implied
  `sma_90` too — split into a 30-row AC and a separate 90-row AC).

## Review Triage Log

Review pass 1 (2026-09-11) — blind-hunter, edge-case-hunter, verification-gap.

| # | Finding | Verdict | Route | Evidence |
|---|---------|---------|-------|----------|
| F1 | `pct_1d` (and `spike_score`) divide two `SIGNED`-cast integers; MariaDB's default division scale is `0 + div_precision_increment` = 4 decimals, so a real change on a large-owner-count instrument (e.g. `10/500000`) truncates to `0.0000` — indistinguishable from no change. Verified live against the project's MariaDB container. | high | patch | blind-hunter, live-verified. Fix: cast both operands to `DECIMAL` with sufficient scale before dividing. |
| F2 | `up_streak` is keyed on the always-available `raw_delta`, not the gap-nulled `delta_1d` that addendum.md's plain-language formula names — a real, human-owned interpretive gap inside the frozen block, not an implementation bug (the code faithfully followed the frozen spec). | medium | renegotiated with the human directly (frozen-block content) | blind-hunter + edge-case-hunter (independently, medium confidence). Human confirmed the row-based reading; recorded in Spec Change Log + the frozen text itself. No code change. |
| F3 | The AC "every metric is a non-null number" at 30 rows contradicts the same spec's own I/O matrix and the correctly-implemented `sma_90` (needs 90 rows) — a self-contradiction in non-frozen AC prose only; the code and the frozen matrix were already correct. | low | bad_spec (text-only; no re-implementation needed) | edge-case-hunter, high confidence. Fixed by splitting into a 30-row AC and a 90-row AC; no code changed because none was wrong. |
| F4 | No regression test seeds a decreasing owner-count series — the exact shape of the unsigned-subtraction wraparound bug the implementer found and fixed manually. A future edit removing the `SIGNED` cast would ship with `composer test` green. | medium | patch | blind-hunter. Fix: add a decreasing-series test asserting `delta_1d`/`pct_1d` are correctly negative. |
| F5 | `testRollbackDropsEverything` asserts five tables are gone after `phinx rollback -t 0` but never checks `owner_count_metrics` — the new migration's entire `down()` path is unverified by any test. | medium | patch | blind-hunter + verification-gap, independently, same finding. Fix: add the view to that test's assertions. |
| F6 | No test seeds 90 rows to exercise the `sma_90`/`rn >= 90` branch — a unique code path with zero direct verification (30-row coverage exists, 90-row does not). | medium | patch | blind-hunter. Fix: add a 90-row case. |
| F7 | The view stacks six CTEs with `PARTITION BY isin, source` window functions computed before any caller `WHERE isin = ?` filter; MariaDB's optimizer cannot generally push a predicate through a window partition, so a single-instrument read (Story 3.3) recomputes every window over the *entire* table. No `EXPLAIN`/timing check exists despite the epic's "stay cheap enough" constraint. | — | defer | blind-hunter. Real structural concern, but unverifiable at today's 1-day data volume and explicitly anticipated by the architecture spine ("materialize later if `EXPLAIN` ever tells us to"). Revisit once Story 3.3 has a real caller and months of data. |
| F8 | `DerivedMetricsRepository` doesn't validate the `source` argument — an unknown value silently returns an empty result rather than erroring. | low | reject | blind-hunter. No caller passes anything but the two established `NormalizedRow::SOURCE_*` constants; adding validation is more than a direct correction for an undemonstrated caller bug. |
| F9 | `DeriverTest`'s "no data" case uses a known-but-empty ISIN, not a genuinely nonexistent one. | false | reject | blind-hunter. Refuted: the view has no join back to `instrument`, so a nonexistent ISIN and a known-empty one produce byte-identical query results — the existing test already covers the only behaviorally distinct path. |
| F10 | `DerivedMetricsRepositoryTest` runs against `StoreTestCase`'s hand-mirrored `CREATE VIEW` copy, not the real Phinx-migrated view; a future edit could desync the two and tests would stay green while the deployed view diverges. | — | defer (already tracked) | verification-gap. Same structural gap as the existing Story 1.6 `deferred-work.md` item ("run tests against real Phinx migrations, not hand-written mirror DDL") — systemic across the whole suite, not introduced by this story. No new entry needed. |
| — | Migration's `down()` uses a bare `DROP VIEW` with no `IF EXISTS`, unlike the test helpers' more defensive mirrors — a re-run rollback would abort with a SQL error instead of completing. | low | patch | edge-case-hunter. Fix: add `IF EXISTS`, matching the existing test-helper pattern. |

**Patches:** F1, F4, F5, F6, and the `down()` `IF EXISTS` fix → re-engaged implementation
subagent. F3 fixed directly as spec text (no code change needed). F2 renegotiated directly
with the human (frozen-block content, resolved without a full revert since it required no
code change either way). **Defer:** F7 (new), F10 (already tracked, no new entry). No
`intent_gap` requiring a revert; `review_loop_iteration` stays 0.

## Design Notes

`up_streak` is the one non-obvious piece — a "gaps and islands" run-length count. Sketch
(CTE chain inside the view; MariaDB 10.11 supports non-recursive CTEs):

```sql
CREATE VIEW owner_count_metrics AS
WITH base AS (
    SELECT isin, source, as_of_date, number_of_owners,
           LAG(number_of_owners) OVER w AS prev_owners,
           LAG(as_of_date)       OVER w AS prev_date
    FROM owner_count_daily
    WINDOW w AS (PARTITION BY isin, source ORDER BY as_of_date)
),
deltas AS (
    SELECT *,
           (number_of_owners - prev_owners) AS raw_delta,
           CASE WHEN DATEDIFF(as_of_date, prev_date) = 1
                THEN number_of_owners - prev_owners END AS delta_1d
    FROM base
),
streaks AS (
    SELECT *,
           SUM(CASE WHEN raw_delta > 0 THEN 0 ELSE 1 END)
               OVER (PARTITION BY isin, source ORDER BY as_of_date) AS grp
    FROM deltas
)
SELECT isin, source, as_of_date, number_of_owners,
       delta_1d,
       CASE WHEN DATEDIFF(as_of_date, prev_date) = 1
            THEN delta_1d / NULLIF(prev_owners, 0) END AS pct_1d,
       AVG(number_of_owners) OVER (PARTITION BY isin, source ORDER BY as_of_date
           ROWS BETWEEN 6 PRECEDING AND CURRENT ROW) AS sma_7,
       -- sma_30 / sma_90: same shape, 29 / 89 PRECEDING; NULL until enough rows via
       -- a ROW_NUMBER() OVER (...) >= N guard (window frames alone don't NULL early rows).
       (number_of_owners - AVG(number_of_owners) OVER (... 29 PRECEDING ...))
           / NULLIF(STDDEV_SAMP(number_of_owners) OVER (... 29 PRECEDING ...), 0) AS spike_score,
       COUNT(*) OVER (PARTITION BY isin, source, grp ORDER BY as_of_date) AS up_streak
FROM streaks;
```

`up_streak` on a down/flat row: it still gets a same-group `COUNT(*)`, so gate it with a
`CASE WHEN raw_delta > 0 THEN COUNT(...) ELSE 0 END` in the final `SELECT` — the streak
resets to 0 on that row, it does not read the *previous* streak's length.

`sma_30`/`sma_90`/`spike_score` need an explicit `< N rows so far` → `NULL` guard (a
`ROW_NUMBER() OVER (PARTITION BY isin, source ORDER BY as_of_date)` compared against
7/30/90) — a `ROWS BETWEEN 29 PRECEDING AND CURRENT ROW` frame silently uses however many
rows exist near the partition's start rather than raising or nulling, which would violate the
"NULL until the 30th row" rule above.

## Verification

**Commands:**
- `composer test -- --filter DerivedMetricsRepositoryTest` -- all matrix cases green.
- `composer test` -- full suite green, no regressions.
- `php -l db/migrations/<ts>_create_owner_count_metrics_view.php` -- no syntax errors.

**Manual checks:**
- `vendor/bin/phinx migrate -e <local>` against the docker-compose MariaDB, then
  `DESCRIBE owner_count_metrics;` / a manual `SELECT` with hand-seeded rows spanning a gap,
  to confirm `STDDEV_SAMP() OVER (...)` and the `ROW_NUMBER()` null-guard behave as designed
  on the real server before trusting the automated tests alone.
