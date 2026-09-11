---
title: 'Story 3.3: Uttag av en akties serie och mått'
type: 'feature'
created: '2026-09-11'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: 'b21e4df5c1936a9a7670cf3a977c0b271379d9e5'
context:
  - _bmad-output/implementation-artifacts/epic-3-context.md
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `Deriver`/`DerivedMetricsRepository` (Story 3.1) and the nightly `/cron/derive`
trigger (Story 3.2) exist, but there is still no way to actually read one instrument's full
owner-count series and derived metrics — an analyst has no window into the data at all.

**Approach:** A read-only `bin/show-metrics.php` SSH script (decided in Story 3.1/epic
planning: extraction is a script, not a new HTTP endpoint), following `bin/show-runs.php`'s
exact shape: thin script (bootstrap, argv parse, repository/pipeline call, render, exit),
delegating to `Deriver::forInstrument(string $isin): array` for data and a new
`DerivedMetricsTable::render()` for output — pure and DB-free, mirroring `RunTable`.

## Boundaries & Constraints

**Always:**
- `--isin=` is required; missing or any unrecognized flag prints the usage line to STDERR and
  exits 1 (mirrors `bin/show-runs.php`'s `$usage()` pattern).
- `--isin=` value not found via `InstrumentRepository::get()` → STDERR `error: unknown isin:
  <value>\n`, exit 1 (distinct from the usage line — this is a valid flag, bad value).
- `--limit=N` optional, same validation as `show-runs.php` (`\d+`, `>0` or usage+exit1); when
  given, keep only the most recent `N` rows per source (`array_slice(..., -N)`), preserving
  chronological order. Default: full series, no cap — `Deriver::forInstrument()` already
  returns everything for the isin.
- Output prints the instrument identity once (isin + name), then each source present in the
  result as its own labeled block — sources are **never merged**, matching the same rule
  already applied to `owner_count_daily`/`owner_count_metrics`.
- A source with zero rows for a valid isin still gets its own block, printed as "no data" (same
  spirit as `RunTable`'s empty-input `"no runs"` line) — exit 0, this is a valid, successful
  query.
- `catch (\Throwable)` → log via `$services['logger']` if bootstrapped, else `error_log`; STDERR
  message; exit 1. Read-only throughout — no writes to any table.

**Never:**
- No new HTTP endpoint, no changes to `Deriver`, `DerivedMetricsRepository`, or the
  `owner_count_metrics` view (all settled in Stories 3.1/3.2).
- No lookup by name/ticker — `--isin=` only, matching "ISIN as the natural key everywhere."
- No merging of Avanza/Nordnet figures into one combined series.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Behavior | Error Handling |
|----------|--------------|-------------------|----------------|
| Valid isin, both sources have rows | `--isin=SE...` | Identity header + two source blocks, each row's metrics per the formulas | none |
| Valid isin, one source has no rows | e.g. only Avanza fetched so far | Avanza block with rows; Nordnet block prints "no data" | none |
| Missing `--isin=` | no flag given | usage line to STDERR, exit 1 | none |
| Unknown isin | well-formed but no matching instrument | `error: unknown isin: ...` to STDERR, exit 1 | none |
| `--limit=N` given | e.g. `--limit=5` | at most the 5 most recent rows per source, chronological order preserved | none |
| Malformed/non-positive `--limit` | `--limit=0` or `--limit=abc` | usage line to STDERR, exit 1 | none |

</frozen-after-approval>

## Code Map

- `bin/show-runs.php`, `src/Store/RunTable.php` — mirror these two files' exact shape/style
  (script: PHPDoc usage comment, `$usage` closure, argv loop, `try`/`catch` + `exit(1)`; render
  class: `final`, one `static render(...): string`, fixed-width `sprintf`, header + `-`
  separator, trailing newline).
- `bin/show-metrics.php` — **new**. `php bin/show-metrics.php --isin=SE... [--limit=N]`. Inside
  `try`: bootstrap, resolve `$isin`/`$limit` from argv, `Database::connect()`,
  `(new InstrumentRepository($pdo))->get($isin)` (null → unknown-isin path),
  `(new Deriver(new DerivedMetricsRepository($pdo)))->forInstrument($isin)`, apply `--limit`
  via `array_slice(..., -N)` per source, `echo DerivedMetricsTable::render(...)`.
- `src/Store/DerivedMetricsTable.php` — **new**. `static render(Instrument $instrument,
  array<string, list<array<string,mixed>>> $bySource): string`. Identity line (isin + name),
  then one block per `NormalizedRow::SOURCE_AVANZA`/`SOURCE_NORDNET` (fixed order): `==
  <source> ==`, column header (`as_of_date | owners | delta_1d | pct_1d | sma_7 | sma_30 |
  sma_90 | up_streak | spike_score`), one row per entry, `null` → `-`. `pct_1d`/`spike_score`
  to 4 decimals, `sma_*` to 2 (`sprintf('%.Nf', ...)`, guard `null` first).
- `src/Store/DerivedMetricsRepository.php:44` `forIsin()` / `src/Pipeline/Deriver.php:25`
  `forInstrument()`, `src/Store/InstrumentRepository.php:54` `get()`, `src/Store/Instrument.php`
  (`->isin`/`->name`), `src/Adapter/NormalizedRow.php` (`SOURCE_*` constants) — all reused as-is.
  Row keys are exactly the view's columns per
  `db/migrations/20260911170000_create_owner_count_metrics_view.php`.
- `tests/Store/RunTableTest.php`, `tests/Store/ShowRunsScriptTest.php` — mirror this
  pure-render-test / subprocess-`exec()`-test split (the latter self-skips via
  `requireDevelopmentDatabase()` when no dev DB).
- `tests/Store/DerivedMetricsTableTest.php` — **new**, pure, one case per rendering-relevant
  I/O matrix row.
- `tests/Store/ShowMetricsScriptTest.php` — **new**, mirrors `ShowRunsScriptTest.php`'s `show()`
  helper; argv cases unconditional, one DB-gated happy path seeded via
  `tests/Store/OwnerCountRepositoryTest.php`'s `row()`/`upsert()` pattern.

## Tasks & Acceptance

**Execution:**
- [x] `src/Store/DerivedMetricsTable.php` -- new pure render class, per Code Map -- output formatting isolated and unit-testable without a DB.
- [x] `bin/show-metrics.php` -- new thin script mirroring `bin/show-runs.php` -- the actual extraction entry point.
- [x] `tests/Store/DerivedMetricsTableTest.php` -- one case per rendering-relevant I/O matrix row -- pins header/formatting/null-handling.
- [x] `tests/Store/ShowMetricsScriptTest.php` -- argv-validation cases unconditionally, one DB-gated happy-path case -- pins exit codes and end-to-end behavior.

**Acceptance Criteria:**
- Given a known isin with rows in both sources, when the script runs, then it exits 0 and prints an identity header plus one correctly-labeled block per source with per-row metrics.
- Given an unknown isin, when the script runs, then it exits 1 with a `error: unknown isin: ...` STDERR message, not the generic usage line.
- Given `composer test`, then the full suite is green; `php -l bin/show-metrics.php` clean.

## Implementation Notes

`src/Store/DerivedMetricsTable.php`: new `final class` with one `static render(Instrument
$instrument, array $bySource): string`, mirroring `RunTable`'s shape exactly (fixed-width
`sprintf`, header + `-` separator, trailing newline, pure/DB-free). Output shape: an identity
line (`isin  name`), then one block per source in the fixed order
`NormalizedRow::SOURCE_AVANZA`, `NormalizedRow::SOURCE_NORDNET` — never merged. Each block is
`== <source> ==` followed by either the column-header/separator/rows table or a lone `no
data\n` line when that source's list is empty (covers both "isin has zero rows for this
source" and "isin has zero rows at all", since a missing key in `$bySource` and an empty list
are treated identically via `$bySource[$source] ?? []`). Row formatting: `delta_1d`/
`up_streak` are `null` -> `-` or cast to `(int)`; `pct_1d`/`spike_score` are `null` -> `-` or
`sprintf('%.4f', ...)`; `sma_7`/`sma_30`/`sma_90` are `null` -> `-` or `sprintf('%.2f', ...)`
-- matches the Code Map's decimal-precision rule exactly.

`bin/show-metrics.php`: thin script mirroring `bin/show-runs.php`'s structure one-for-one —
`$usage` closure, `try`/argv-loop/`catch (\Throwable)`. Argv: `--isin=(.+)` (empty value falls
through to the generic "unrecognized flag" branch, which is fine — it still hits `$usage()`);
`--limit=(\d+)` with the same `<= 0` -> usage() guard as `show-runs.php`. After the loop, a
still-null `$isin` also triggers `$usage()` (the "missing flag" case). Only past all of that is
`Database::connect()` opened, so every argv-validation failure — including the unrecognized
"malformed `--limit`" case, which naturally routes through the same "else -> usage()" branch as
any other bad flag — never touches the DB. `InstrumentRepository::get($isin) === null` prints
the distinct `error: unknown isin: <isin>` message and exits 1, matching the frozen boundary
that this is a different failure mode from a malformed flag. `--limit` slicing is applied
per-source with `array_slice($rows, -$limit)` after `Deriver::forInstrument()` returns, exactly
per the Code Map.

Tests: `DerivedMetricsTableTest` (pure) covers both-sources-have-rows, one-source-has-no-rows,
the zero-rows-at-all case (both blocks `no data`), all-nullable-metrics-render-as-`-`, and the
4-decimal/2-decimal rounding rules. `ShowMetricsScriptTest` mirrors `ShowRunsScriptTest`'s
`show()` subprocess helper: four unconditional argv cases (missing isin, unrecognized flag,
`--limit=0`, `--limit=abc`) that never touch the DB, plus two DB-gated cases
(`requireDevelopmentDatabase()`, checking `SELECT isin FROM owner_count_metrics LIMIT 1` so the
skip fires until Story 3.1's view migration has run) — unknown isin, and a happy path that
seeds one `instrument` row + one `avanza` `owner_count_daily` row via `OwnerCountRepository`
(mirroring `OwnerCountRepositoryTest`'s `row()`/`upsert()` pattern), asserts the identity line,
the `avanza` block's row, and the `nordnet` block's `no data`, then cleans up in `finally`.

No changes to `Deriver`, `DerivedMetricsRepository`, `InstrumentRepository`, `Instrument`,
`NormalizedRow`, or the `owner_count_metrics` view — all reused as-is per the frozen "Never"
list.

## Spec Change Log

## Review Triage Log

Review pass 1 (2026-09-11) — blind-hunter, edge-case-hunter, verification-gap.

| # | Finding | Verdict | Route | Evidence |
|---|---------|---------|-------|----------|
| F1 | `renderRows()` reads `as_of_date`/`number_of_owners` directly (no `-`-dash guard, unlike the other seven columns) — claimed risk of blank/misaligned output if either is ever `null`. | false | reject | blind-hunter. Refuted: both are `NOT NULL` columns in `owner_count_daily` (the view's source table); independently confirmed by edge-case-hunter's schema check. No code path can produce a null here. |
| F2 | The nine row fields are read via bare `$row['key']` with no `??` fallback — claimed risk of "Undefined array key" warnings if `Deriver::forInstrument()` ever omits a key. | false | reject | blind-hunter. Refuted: `owner_count_metrics`'s `SELECT` (`db/migrations/20260911170000_create_owner_count_metrics_view.php`) unconditionally returns all eleven columns for every row it produces — a row either has every key (possibly `null` values, already dash-guarded) or the row doesn't exist at all. No caller can produce a partial row. |
| F3 | `--isin=` is never normalized (trim/uppercase); a lowercase-but-otherwise-valid ISIN reports the misleading "unknown isin" error instead of matching. | low | patch | blind-hunter. Real but minor UX gap; trivial fix (`strtoupper(trim($isin))` before lookup). |
| F4 | Passing `--isin=`/`--limit=` twice silently lets the later value win, with no error — inconsistent with the strict rejection of an actually-unrecognized flag. | — | defer | blind-hunter + edge-case-hunter (independently, same root cause). Verified: `bin/show-runs.php`'s own argv loop has the identical last-wins-on-repeat behavior for `--date=`/`--limit=` — this diff mirrors the established (permissive) convention per the spec's own instruction to mirror that script, not a new defect. |
| F5 | `testLimitCapsOutputToTheMostRecentRowsInChronologicalOrder` seeds only `avanza` rows, so the per-source `array_slice` in the loop `foreach ($bySource as $source => $rows) { array_slice($rows, -$limit) }` is never exercised with two sources of differing lengths at once. | low | patch | blind-hunter. Real coverage gap (code reads correct on inspection, but untested with >1 source); trivial fix — seed a second source with a different row count in the existing test. |
| F6 | `DerivedMetricsTable::render()` iterates only the hardcoded `self::SOURCES = [AVANZA, NORDNET]`; any other key in `$bySource` would be silently dropped. | false | reject | blind-hunter + edge-case-hunter (independently). Refuted: `NormalizedRow` defines exactly two `SOURCE_*` constants in the whole codebase, and only `AvanzaAdapter`/`NordnetAdapter` ever write a `source` value — no caller can produce a third key today. Not a live defect. |
| F7 | No cap/truncation on formatted values before the fixed-width `sprintf` columns — an unusually wide value (e.g. a very large owner count or `sma_*`) could exceed its column width and misalign the table. | low | reject | edge-case-hunter. Real in principle but unlikely at this project's realistic owner-count scale (low hundreds of thousands at most), cosmetic-only if it did happen (no crash/data loss), and mirrors `RunTable`'s identical fixed-width-with-no-truncation design, which this story was explicitly told to copy — fix would add guard complexity to every column for a case never demonstrated reachable. |
| F8 | `pct_1d`'s column header gives no unit — the value is a raw ratio (`0.05`), not a percentage, which the column name alone doesn't make clear to an operator reading raw SSH output. | low | patch | blind-hunter. Real minor labeling ambiguity; trivial fix (clarify the header, e.g. `pct_1d(ratio)`). |
| F9 | Both new DB-gated tests (`testValidIsinPrintsIdentityAndOneBlockPerSource`, `testLimitCapsOutputToTheMostRecentRowsInChronologicalOrder`) do their `INSERT`/`upsert()` setup *before* entering the `try` block, so if setup itself throws (e.g. a duplicate-PK from a previously orphaned run), the `finally` cleanup never runs, permanently wedging that ISIN. | medium (if triggered) | defer | blind-hunter + edge-case-hunter (independently, same root cause, two locations). Verified pre-existing: `tests/Store/ShowRunsScriptTest.php::testAlarmsFlagShowsAlarmedRunsOnly` (already shipped, Story 1.8) has the identical setup-outside-try/cleanup-only-in-finally shape — this diff faithfully mirrors that established (already-reviewed) sibling test, per the spec's own instruction to mirror it, not a defect newly introduced here. |
| F10 | Spec's own Code Map text says "one DB-gated happy path" for `ShowMetricsScriptTest.php`, but two now exist after the Tasks & Acceptance Verification step's `--limit` coverage fix. | — | reject | edge-case-hunter (claims check). Rejected per triage rule: the only fix is editing this build's spec's own descriptive text, not code. |

**Patches:** F3, F5, F8 → re-engaged implementation subagent. **Defer:** F4, F9 (both pre-existing conventions this story mirrors, not introduced by it). **Reject:** F1, F2, F6, F7, F10.

## Verification

**Commands run:**
- `php -l bin/show-metrics.php` / `php -l src/Store/DerivedMetricsTable.php` -- no syntax
  errors.
- `composer test -- --filter DerivedMetrics` -- 15 tests, 266 assertions, green.
- `composer test -- --filter ShowMetrics` -- 6 tests, 18 assertions, green (dev DB was
  reachable and migrated in this environment, so the two DB-gated cases ran for real rather
  than skipping).
- `composer test` (full suite) -- 262 tests, 1352 assertions, green, no regressions.
- Manual: against the docker-compose `development` MariaDB (already migrated), ran
  `php bin/show-metrics.php --isin=FI4000297767` against a real instrument with no
  `owner_count_daily` rows yet (both blocks correctly print `no data`, exit 0), then seeded an
  8-day `avanza` series (including a weekend gap) via `OwnerCountRepository::upsert()`,
  confirmed column alignment, gap-aware `null` `delta_1d`/`pct_1d` across the gap, `sma_7`
  appearing only once 7 rows exist, and `--limit=3` correctly keeping the 3 most recent rows in
  chronological order — then deleted the seeded rows to leave the dev DB clean.
