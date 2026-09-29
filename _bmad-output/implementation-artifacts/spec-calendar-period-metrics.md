---
title: 'Calendar period metrics (Vecka / Månad / 3 mån / År)'
type: 'bugfix'
created: '2026-09-29'
status: 'done'
baseline_commit: '9260f17922da24529a33437c161893e274c47c97'
route: 'dispatch'
review_loop_iteration: 0
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Collection runs Mon–Fri only, skipping holidays (dfe705e, 3bae67f). But `owner_count_metrics` still requires an exact `DATEDIFF = N` against the row N *rows* back. As a result, `pct_7d`/`pct_90d`/`pct_365d` are effectively always NULL (the Topplista chips always show "–"), and `delta_1d`/`pct_1d` are NULL every Monday and after every holiday. The Aktiedetalj range picker (Dag/Vecka/30d/90d/År) is also row-counted, so "Vecka" actually covers ~9 calendar days and "30d" ~6 weeks.

**Approach:** Turn every period into a calendar period with the labels Stefan chose (2026-09-28): 7 days → **Vecka**, 30 days → **Månad**, 90 days → **3 mån**, and **År** (365 days) stays. Each period % compares against the latest stored row on or before `as_of_date − N days`. The 1-day change compares against the previous stored row (the previous trading day). Topplista shows four chips, **Vecka / Månad / 3 mån / År** (decided 2026-09-29). The Aktiedetalj range picker becomes **Dag / Vecka / Månad / 3 mån / År**, using calendar windows.

## Boundaries & Constraints

**Always:** Offsets are fixed day counts: 7 / 30 / 90 / 365. A comparison row counts only if it lies within **N+5 calendar days** before `as_of_date`; the 1-day change uses a tolerance of **≤5 days** (this covers Fri→Mon and Easter's Thu→Tue). Otherwise the value is NULL, and a gap never produces a misleading number. `sma_7/30/90`, `up_streak` and `spike_score` stay unchanged. Sources are never merged (NFR6). All UI copy is in Swedish (NFR12). The view change ships as a new Phinx migration (DROP + CREATE, with a `down()` that restores the spec-5-5 view verbatim), mirrored in `StoreTestCase::createSchema()`.

**Never:** No backfill (NFR7). No materialized table or new writer. No new client-side JS. Don't edit already-applied migrations. No new SQL for range slicing: Aktiedetalj keeps slicing the `forIsin()` result in PHP.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected |
|---|---|---|
| Monday | Rows Fri + Mon | Mon `delta_1d`/`pct_1d` = Mon − Fri |
| Post-holiday | Rows Thu (Apr 2), Tue (Apr 7), Easter gap | Tue delta vs Thu (5 days) |
| Long gap | Previous row 8 days earlier | `delta_1d`/`pct_1d` NULL |
| Vecka on a Wednesday after Wed holiday | Row d−7 missing, d−8 present | `pct_7d` uses d−8 |
| Short history | First row 20 days ago | `pct_30d`/`pct_90d`/`pct_365d` NULL, `pct_7d` set |
| Stale anchor | Nearest row ≤ d−30 is 40 days back | `pct_30d` NULL |
| Old URL | `?range=30d` / `?range=90d` | Treated as `manad` / `3man` |
| Range gate | Primary history spans < window | "Inte tillräckligt med historik … om {n} dagar", n = calendar days until it spans |

</frozen-after-approval>

## Code Map

- `db/migrations/20260914000000_extend_owner_count_metrics_view_multi_period_pct.php` -- current view; copy its CTE chain and its `up()` SQL as the new `down()`.
- `tests/Store/StoreTestCase.php:160-300` -- hand mirror of the view; must match the new migration exactly.
- `src/Store/DerivedMetricsRepository.php` -- `topByOwnerCount`, `topByTrendQuality`, `topBy*AsOf` column lists: add `pct_30d`. `searchAndFilter` doesn't select period pcts; leave it.
- `src/Web/LeaderboardController.php:221-305` -- `periodPctItemHtml`/`periodPctsHtml`/`renderRow`: four chips; labels `Vecka`, `Månad`, `3 mån`, `År`.
- `public_html/assets/app.css:348-360, 491-496` -- shared CSS (the AGENTS.md "duplicated CSS" pitfall is stale). The desktop ranked grid's last column is 220px; widen it so four chips fit.
- `src/Web/StockDetailController.php:34-176, 300-310, 685-730` -- `RANGE_*` constants, `normalizeRange`, `windowSize`/`gateThreshold`/`sliceForRange`/`isInsufficientHistory`/`daysUntilSufficient`, `rangeLabel`, `rangePickerHtml`.
- `src/Web/InfoController.php:74` -- copy "(Dag/Vecka/30d/90d/År)"; describe the four period chips too.
- Tests: `tests/Store/DerivedMetricsRepositoryTest.php`, `tests/Web/LeaderboardControllerTest.php`, `tests/Web/StockDetailControllerTest.php`, `tests/FrontControllerIntegrationTest.php` (43 period/range references to update).

## Tasks & Acceptance

**Execution:**
- [x] `db/migrations/20260929120000_calendar_period_metrics_view.php` -- recreate the view: previous-row delta with the ≤5-day tolerance; `pct_7d/30d/90d/365d` via a `TO_DAYS(as_of_date)` numeric `RANGE BETWEEN UNBOUNDED PRECEDING AND N PRECEDING` frame (`LAST_VALUE` of owners and date), plus the N+5 tolerance check -- a single-pass window, not a correlated subquery.
- [x] `tests/Store/StoreTestCase.php` -- mirror the view.
- [x] `src/Store/DerivedMetricsRepository.php` -- select `pct_30d`.
- [x] `src/Web/LeaderboardController.php` + `public_html/assets/app.css` -- four chips and new labels; widen the column.
- [x] `src/Web/StockDetailController.php` -- ranges `dag|vecka|manad|3man|ar` (legacy `30d`/`90d` mapped). Windows: rows with `as_of_date >= last − N days` (Dag stays the last 2 rows). Gate: `first_date > last − N` → insufficient; År shares 3 mån's gate. `daysUntilSufficient` returns calendar days.
- [x] `src/Web/InfoController.php` -- update the copy.
- [x] Tests -- Mon–Fri fixtures covering every matrix row; update the existing assertions.
- [x] `docs/backlog.md` -- mark 2026-09-28 item 3 FIXED.

**Acceptance Criteria:**
- Given Mon–Fri data spanning ≥ 1 year, when Topplista renders, then all four chips show numbers.
- Given the migration, when rolled back, then the spec-5-5 view is restored.
- Given 390px width, when Topplista renders, then the four chips fit without clipping.

## Design Notes

Why fixed day counts instead of calendar months: nearest-row-on-or-before already absorbs the ±1-day difference, and it keeps one mechanism for all four periods. The numeric RANGE frame works around MariaDB's lack of date-interval RANGE frames; verify it on MariaDB 10.11 (docker-compose) before relying on it.

## Verification

**Commands:**
- `docker compose up -d && composer test` -- expected: green, with **no skipped** Store/integration tests.

**Manual checks:**
- Topplista and `/stock/{isin}?range=manad` in a browser at 390px and desktop width.

## Implementation Notes

- View column names kept (`pct_7d`/`pct_90d`/`pct_365d`) plus new `pct_30d`, so no caller had to rename. The numeric `RANGE BETWEEN UNBOUNDED PRECEDING AND N PRECEDING` frame over `TO_DAYS(as_of_date)` (computed in a leading `days` CTE) works on MariaDB 10.11 (docker-compose); verified by hand against a fixture and by the store tests.
- Migration verified with Phinx on docker MariaDB: `migrate` adds `pct_30d`; `rollback` restores a `SHOW CREATE VIEW` byte-identical to the spec-5-5 view. Migration SQL and `StoreTestCase` mirror are textually identical (modulo backticks).
- Aktiedetalj: `windowDays()`/`gateDays()` replace `windowSize()`/`gateThreshold()`. Dag keeps the row-based rule (last 2 rows; gate/“om n dagar” = rows missing up to 2). År is now capped at 365 days (was uncapped). An empty series reports the full gate span as n.
- Desktop ranked grid's period column 220px → 260px, chip gap 12px on desktop; `.period-pcts` may wrap (mobile and desktop) as a no-clip safety net.

## Spec Change Log

## Review Triage Log

Pass 1 (2026-09-29):

| # | Source | Finding | Verdict | Evidence / route |
|---|---|---|---|---|
| 1 | verification-gap | pct_30d/pct_365d N+5 tolerance never tested off an exact anchor | medium | Fixtures land exactly on d−30/d−365; reverting to `= N` stays green → patch (tests) |
| 2 | blind | Long-period boundaries (35/36, 95/96, 370/371) untested | medium | Same root cause as #1 → patch (tests) |
| 3 | blind | Rolling back view alone breaks `pct_30d` selects | false | Code and migration always ship/roll back together; same as spec-5-5 |
| 4 | blind | rsync→migrate window selects missing `pct_30d` | low | Pre-existing, already deferred (spec-5-5) → defer |
| 5 | blind | No perf check of the rewritten view | maybe-false | Needs production timing/EXPLAIN; would be medium → defer |
| 6 | blind | "–" tooltip "Otillräcklig historik" wrong for stale anchor | low | Direct text change → patch |
| 7 | blind+edge | "om 1 dagar" singular; n may land on a weekend | low | Singular: direct fix → patch. Weekend: estimate copy, would need holiday logic → reject |
| 8 | blind | Dag draws across long gap while delta chip hidden | low | Pre-existing (Dag was always last 2 rows) → reject |
| 9 | blind | EXPERIENCE.md / DESIGN.md / spine column list stale | medium | Future agents follow stale range/gate docs → patch (docs) |
| 10 | blind | backlog "Still open: Månad chip" line contradicts FIXED | low | Direct deletion → patch |
| 11 | blind | AGENTS.md duplicated-CSS pitfall stale | low | Agent-context file → defer |
| 12 | blind | "Wednesday holiday" fixture 2026-12-23 isn't a holiday | low | Direct correction to 2025-12-31 → patch |
| 13 | blind+edge | 260px `nowrap` column may overflow on large pcts | maybe-false | Needs browser check; allowing wrap is a direct CSS change → patch |
| 14 | blind | View mirror / no-skip not enforced automatically | low | Pre-existing, documented pitfall → reject |
| 15 | blind | deferred-work entry `source_spec: none` | low | Direct correction → patch |
| 16 | edge | Secondary series sliced from its own last date | low | Pre-existing independent slicing; sources refresh the same night → reject |
| 17 | edge | Gate passes but only 0–1 rows in window after outage | low | Requires a >7-day collection outage; the legend already handles <2 points → reject |
| 18 | edge | Closure >5 days nulls delta | false | No Nasdaq Stockholm closure exceeds 5 calendar days; Easter Thu→Tue = 5 (spec-decided tolerance) |
| 19 | edge | Test anchor `while` loop can spin forever | low | Direct bound + assert → patch |
| 20 | edge | Dag n is a row count, not calendar days | false | Spec: "Dag stays the last 2 rows"; matrix gate row applies to calendar ranges |
