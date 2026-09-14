---
title: 'Procentuell utveckling över flera perioder'
type: 'feature'
created: '2026-09-14'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
context: []
baseline_commit: '1b9a54d54332d657e28e5a4d30d8d2d5874914e3'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Topplista rows only show today's delta (`pct_1d`) — Stefan can't tell
whether a trend has held over a week, a quarter, or a year without opening Aktiedetalj.

**Approach:** Extend the `owner_count_metrics` view (Story 3.1) with three new derived
columns — `pct_7d`, `pct_90d`, `pct_365d` — computed exactly like the existing `pct_1d`:
percentage change vs. the row exactly N calendar days earlier in the same `(isin,
source)` partition, `NULL` whenever that exact-N-days-back row doesn't exist (not
enough history yet, or a data gap crosses the window) — the same gap-aware discipline
Story 3.1 already established for `pct_1d`, extended to three new offsets rather than
row-counted like `sma_N`. This is the one story in Epic 5 allowed to touch
`src/Pipeline/Deriver`'s domain (the view; `Deriver.php` itself needs no code change —
it already delegates via `SELECT *`/named columns). Topplista rows gain a new line
below the existing row body showing Vecka (`pct_7d`)/90d/År percentages, each colored
by sign or a clear insufficient-history mark ("–") when `NULL`. Today's percentage
stays exactly where it already is (the existing Delta chip) — this story doesn't
duplicate it. Scoped to Topplista only, same precedent as Story 5.4 — Fullständig
lista, Bevakningslista, and Aktiedetalj are unchanged.

## Boundaries & Constraints

**Always:** A period's percentage is `NULL`, never a misleading number, whenever the
exact-N-calendar-days-earlier row doesn't exist for that `(isin, source)` — mirrors
`pct_1d`'s existing gap-aware rule exactly, not `sma_N`'s row-counted rule. In Alla
mode (Story 5.4), the period percentages are Avanza-derived, same as badges/sparkline/
ranking already are — no special-casing needed since the row data itself is already
resolved to Avanza in that mode. The new row line must not reintroduce the mobile
clipping Story 5.1 fixed — placed as its own full-width wrapped line below the
existing row content, not squeezed into `.namecol`/`.statcol`.

**Never:** No new database table — this is a view (SQL) extension only, no
materialization/refresh logic (same as Story 3.1's decision). Do not touch
`FullListController.php`, `WatchlistController.php`, or `StockDetailController.php` —
scoped to `LeaderboardController` rows only. Do not edit the existing, already-applied
`20260911170000_create_owner_count_metrics_view.php` migration — add a new migration
that drops and recreates the view (Phinx has no ALTER VIEW helper; matches the
project's existing "enrich via a new migration" convention,
`20260911100000_enrich_ingest_run.php`).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|---|---|---|---|
| Enough gap-free history for all three windows | ≥365 consecutive daily rows, no gaps | `pct_7d`/`pct_90d`/`pct_365d` all populated, signed, colored by sign | N/A |
| Fewer rows than a window needs | e.g. 10 rows total | `pct_7d` populated, `pct_90d`/`pct_365d` `NULL` → row shows "–" for those two | N/A |
| A data gap crosses exactly the 7/90/365-day boundary | rows exist before and after a gap, but not exactly N days apart | That period's `pct_Nd` is `NULL` for the row straddling the gap (never a misleading cross-gap %) | N/A |
| Alla mode (Story 5.4) | `?source=alla` | Period percentages are Avanza's (ranking basis), never Nordnet's or a blend | N/A |
| Owner count unchanged over a period | `pct_Nd = 0` | Shown as a neutral "0,0 %", not treated as insufficient history | N/A |

</frozen-after-approval>

## Code Map

- `db/migrations/20260911170000_create_owner_count_metrics_view.php` — the view to
  extend; every CTE (`base`, `deltas`, `gapped`, `streaks`, `grouped`, `metrics`) must
  carry the new columns forward to the final `SELECT` (MariaDB CTEs require explicit
  column propagation at each stage). New migration adds three `LAG(number_of_owners,
  N) OVER w`/`LAG(as_of_date, N) OVER w` pairs (N = 7, 90, 365) in `base`, and three
  `pct_Nd` `CASE WHEN prev_date_N IS NOT NULL AND DATEDIFF(as_of_date, prev_date_N) = N
  THEN ... END` columns in `gapped`, mirroring `pct_1d`'s existing CASE exactly (same
  file, `gapped` CTE) — `down()` restores the original Story 3.1 view SQL verbatim
  (`DROP VIEW` + recreate), not a reference to the old migration class.
- `tests/Store/StoreTestCase.php:152-262` — a **hand-mirrored copy** of the view's DDL
  used by every DB-backed test (`StoreTestCase` subclasses never run real Phinx
  migrations; confirmed via its own comment: "Keep this in step with the migration;
  there is no automated check"). This mirror must be updated identically to the new
  migration or every DB-backed test silently runs against the *old* view — a known,
  pre-existing debt item (already logged in `deferred-work.md` for Story 1.6), not
  something this story fixes, but something this story's own tests would silently fail
  to catch if forgotten here.
- `src/Store/DerivedMetricsRepository.php:88-165` (`topByOwnerCount()`,
  `topByTrendQuality()`) — both use explicit `SELECT` column lists (not `SELECT *`);
  add `latest.pct_7d, latest.pct_90d, latest.pct_365d` to both. `forIsinAndSource()`/
  `forIsin()` (`:46,:63`) use `SELECT *` — no change needed, new columns flow through
  automatically (used by Aktiedetalj/`bin/show-metrics.php`, both out of this story's
  scope but unaffected either way).
- `src/Pipeline/Deriver.php` — no code change. It's a thin `SELECT`-delegating wrapper
  (its own docblock: "no SQL of its own"); the view does all the computation. Confirm
  by inspection only — this satisfies "the one story allowed to touch Deriver" via the
  view it exposes, not via new code in the class itself.
- `src/Web/LeaderboardController.php:168-` (`renderRow()`) — extract `pct_7d`/
  `pct_90d`/`pct_365d` from `$row` the same way `pct_1d` already is (`$row['pct_Nd']
  !== null ? (float) $row['pct_Nd'] : null`); these are already Avanza-derived in Alla
  mode with zero extra plumbing, because `$row` itself already comes from the
  Avanza-ranked query (Story 5.4's `$rankingSource`) in that mode.
- `src/Web/LeaderboardController.php` (new `public static` methods, same precedent as
  `deltaChipHtml()`): `periodPctItemHtml(string $label, ?float $pct): string` (one
  labeled percentage, `+2,1 %`/`-2,1 %`/`0,0 %` signed and colored by sign
  `positive`/`negative`/`neutral` — same convention as `deltaChipHtml()` — or a
  `period-pct--nohist` "–" mark with a `title="Otillräcklig historik"` when `$pct` is
  `null`); `periodPctsHtml(?float $pct7d, ?float $pct90d, ?float $pct365d): string`
  wraps three `periodPctItemHtml()` calls labeled "V"/"90d"/"År" in one `.period-pcts`
  container. Today's percentage is intentionally NOT repeated here — it's already
  shown via the existing `deltaChipHtml()` output in `.statcol`.
- `src/Web/LeaderboardController.php:190-215` (`renderRow()`'s `<div class="row">`
  markup) — append `{$periodPctsHtml}` as a new sibling **after** the closing
  `</a>` of `.row-body`, still inside `.row`. CSS: add `flex-wrap: wrap;` to `.row`
  (currently `display: flex` with no wrap — Code Map, `:596-600`) and give
  `.period-pcts { flex: 1 0 100%; }` so it always starts a fresh wrapped line below
  rank/star/row-body regardless of viewport width — the same "force a new line inside
  a flex row" technique, applied at the row level instead of Story 5.1's per-column
  level. Deliberately NOT indented to align under the row-body's content (a full-width
  footer line is simpler and lower-risk than matching the rank/star column width) — a
  presentation choice, not an open question.

## Tasks & Acceptance

**Execution:**
- [x] `db/migrations/20260914000000_extend_owner_count_metrics_view_multi_period_pct.php` -- new migration: `up()` drops and recreates `owner_count_metrics` with `pct_7d`/`pct_90d`/`pct_365d` added per the Code Map's CTE extension; `down()` restores the original Story 3.1 view SQL verbatim
- [x] `tests/Store/StoreTestCase.php` -- update the hand-mirrored view DDL identically, or every DB-backed test below silently runs against the old view
- [x] `src/Store/DerivedMetricsRepository.php` -- add `pct_7d`/`pct_90d`/`pct_365d` to `topByOwnerCount()`'s and `topByTrendQuality()`'s `SELECT` lists
- [x] `src/Web/LeaderboardController.php` -- add `periodPctItemHtml()`/`periodPctsHtml()`; extract the three new fields in `renderRow()`; append the new `.period-pcts` line to the row markup; `flex-wrap: wrap` on `.row` + new `.period-pcts`/`.period-pct-*` CSS
- [x] `tests/Store/DerivedMetricsRepositoryTest.php` -- extend the existing all-fields-present assertion loop with `pct_7d`/`pct_90d`/`pct_365d`; add gap-aware-NULL tests mirroring the existing `pct_1d` gap test, for at least one new period
- [x] `tests/Web/LeaderboardControllerTest.php` -- unit-test `periodPctItemHtml()`/`periodPctsHtml()` directly: positive/negative/zero/null cases, no database
- [x] `tests/FrontControllerIntegrationTest.php` -- `/` renders a row with all three periods populated (enough seeded history) and a row with some `NULL` (insufficient history, shows "–"); confirm Alla mode's period percentages are Avanza's, not Nordnet's

**Acceptance Criteria:**
- Given `owner_count_metrics`, when `Deriver`/the view computes metrics for an instrument/source, then `pct_7d`, `pct_90d`, `pct_365d` are computed alongside the existing `pct_1d`, `NULL` until the exact-N-days-back comparison row exists
- Given an instrument with fewer rows than a period's window requires, when metrics are computed, then that period's `pct_Nd` column is `NULL`, never a misleading number
- Given a Topplista row, when it renders, then it shows percentage movement for day (existing Delta chip, unchanged)/week/90 days/year, with a clear insufficient-history mark for periods without data
- Given this is the only Epic 5 story touching `src/Pipeline/Deriver`, when it is implemented, then the change follows Story 3.1's discipline (MariaDB window functions, SQL view, gap-aware null handling) — the rest of Epic 4/5's "never touch `src/Pipeline/`" rule does not apply to this story specifically

## Implementation Notes

`db/migrations/20260914000000_extend_owner_count_metrics_view_multi_period_pct.php`
drops and recreates `owner_count_metrics` exactly per the Code Map: `base` gains three
`LAG(number_of_owners, N)`/`LAG(as_of_date, N) OVER w` pairs (N = 7, 90, 365) alongside
the existing 1-back pair; `deltas` carries the six new `prev_owners_N`/`prev_date_N`
columns forward unchanged (needed by `gapped`'s CASE expressions, dropped afterwards --
same lifecycle as the existing `prev_owners`/`prev_date`); `gapped` computes
`pct_7d`/`pct_90d`/`pct_365d` with `CASE WHEN prev_date_N IS NOT NULL AND
DATEDIFF(as_of_date, prev_date_N) = N THEN ... END`, mirroring `pct_1d`'s CASE exactly
(same NULL-unless-exact-N-calendar-days-apart rule); `streaks`/`grouped`/`metrics` each
carry the three new columns straight through (MariaDB CTEs require explicit column
propagation at every stage); the final `SELECT` adds `pct_7d, pct_90d, pct_365d` right
after `pct_1d`. `down()` restores the original Story 3.1 `CREATE VIEW` SQL verbatim (not
a reference to that migration's class), confirmed both by diffing it against
`20260911170000_create_owner_count_metrics_view.php` and by round-tripping the real
migration against the `development` database: `phinx migrate` (columns land exactly as
`isin, source, as_of_date, number_of_owners, delta_1d, pct_1d, pct_7d, pct_90d,
pct_365d, sma_7, sma_30, sma_90, up_streak, spike_score`), `phinx rollback` (columns
revert to exactly the pre-migration eleven), `phinx migrate` again (back to the extended
fourteen) -- all clean, no errors.

`tests/Store/StoreTestCase.php`'s hand-mirrored view DDL was updated identically to the
migration's `up()` SQL (same CTE chain, same column list), so the DB-backed test suite
exercises the real extended view rather than silently running against the old one.

`src/Store/DerivedMetricsRepository.php` -- `topByOwnerCount()` and `topByTrendQuality()`
both gained `latest.pct_7d, latest.pct_90d, latest.pct_365d` in their explicit `SELECT`
lists, placed next to `latest.pct_1d`. `forIsinAndSource()`/`forIsin()` needed no change
(`SELECT *`), confirmed by inspection -- the new columns flow through automatically to
`Deriver::forInstrument()` (Aktiedetalj/`bin/show-metrics.php`, both out of scope but
unaffected either way) and `Deriver.php` itself needed no code change, confirmed by
inspection: it's a thin `SELECT`-delegating wrapper with no SQL of its own.

`src/Web/LeaderboardController.php` -- `periodPctItemHtml(string $label, ?float $pct)`
and `periodPctsHtml(?float $pct7d, ?float $pct90d, ?float $pct365d)` added as
`public static` pure functions (same precedent as `deltaChipHtml()`), both built from
`<span>` elements only (no `<div>`) so `FrontControllerIntegrationTest::rowHtmlFor()`'s
"slice from `data-isin` to the first `</div>`" helper still lands on the row's own
closing tag rather than an earlier one introduced by this story's markup.
`periodPctItemHtml()` mirrors `deltaChipHtml()`'s sign/color convention exactly
(`+2,1 %`/`-2,1 %`/`0,0 %`, `positive`/`negative`/`neutral`) and renders a
`period-pct--nohist` "–" mark with `title="Otillräcklig historik"` when `$pct` is
`null`. `renderRow()` extracts `pct_7d`/`pct_90d`/`pct_365d` from `$row` the same
null-coalescing pattern as `pct_1d`, and the resulting `.period-pcts` line is appended
as a sibling **after** `.row-body`'s closing `</a>`, still inside `.row` -- Alla mode
needs no special-casing since `$row` itself already came from the Avanza-ranked query
(`render()`'s `$rankingSource`), same reasoning already established for badges/
sparkline/ranking. CSS: `.row` gained `flex-wrap: wrap`; `.period-pcts` is
`flex: 1 0 100%` so it always starts a fresh wrapped line regardless of viewport width,
with its own `.period-pct`/`.period-pct-label`/`.period-pct-value` rules and
sign-colored `.period-pct--positive/--negative/--neutral/--nohist` variants, all reusing
the existing `--positive`/`--negative`/`--text-secondary`/`--text-muted` CSS custom
properties rather than introducing new ones.

Test coverage: `tests/Store/DerivedMetricsRepositoryTest.php` extends the first-row
all-null assertion loop with the three new fields and adds four new tests against the
real view (7-day window populates exactly at 8 gap-free rows; insufficient rows nulls
it; a gap crossing exactly the 7-day boundary nulls it despite enough physical rows
existing; a zero-change window renders as `0.0`, not `NULL`). `tests/Web/
LeaderboardControllerTest.php` adds six unit tests for `periodPctItemHtml()`/
`periodPctsHtml()` (positive/negative/zero/null, label/order, all-null). `tests/
FrontControllerIntegrationTest.php` adds three end-to-end tests: all three periods
populated (366 consecutive gap-free days), all three showing the insufficient-history
mark (a single stored day), and Alla mode's period percentages provably Avanza-derived
(Avanza +7,0% vs. a deliberately different Nordnet -14,0% over the same seven days --
only the Avanza figure may appear).

`composer test`: 484 tests, 2227 assertions, all green (13 new tests: 4 in
`DerivedMetricsRepositoryTest`, 6 in `LeaderboardControllerTest`, 3 in
`FrontControllerIntegrationTest`).

Nothing left incomplete. One thing worth flagging for a future story, not a defect here:
`pct_365d` will rarely populate in production for many months (NFR7, no historical
backfill) -- expected per the spec's own Design Notes, and this story's own tests prove
the column computes correctly once enough gap-free history exists (the 366-row
integration test), so this is not a testing gap, just a real-world data-maturity fact.

**Orchestrator verification:** independently re-verified — full diff read against
`baseline_commit` (including the untracked new migration file via `git add -N`), all 7
execution tasks and all 4 spec-level ACs confirmed directly against the diff content,
all 5 I/O & Edge-Case Matrix rows confirmed covered by a passing test (the gap-crossing
row is tested for `pct_7d` specifically, per the Tasks' own "at least one new period"
scope — the SQL pattern is mechanically identical across all three offsets). Hand-traced
the migration SQL against the Story 3.1 original: every new `LAG`/`pct_Nd` column is
correctly threaded through all six CTEs to the final `SELECT`, and the `gapped` CTE's
new `CASE WHEN prev_date_N IS NOT NULL AND DATEDIFF(...) = N` clauses are byte-faithful
extensions of `pct_1d`'s existing pattern. Confirmed `tests/Store/StoreTestCase.php`'s
hand-mirrored view DDL (the known pre-existing trap this Code Map flagged) was updated
identically to the new migration — without this, every DB-backed test in this diff
would have silently exercised the old view and still passed for the wrong reason.
Independently re-ran `composer test`: 484 tests, 2227 assertions, clean — matches the
subagent's reported numbers exactly. Independently ran `vendor/bin/phinx status -e
development`: all 9 migrations, including the new one, show `up` — confirms the
subagent's reported migrate/rollback/migrate round-trip left the local dev DB in a
clean, fully-migrated state. Confirmed via `git diff --stat` that
`FullListController.php`/`WatchlistController.php`/`StockDetailController.php`/
`src/Pipeline/Deriver.php` and the already-applied Story 3.1 migration are untouched,
matching Boundaries exactly.

## Review Triage Log

- **[low, patch]** (blind-hunter + edge-case-hunter, duplicate finding) `periodPctItemHtml()` derived its sign and `positive`/`negative`/`neutral` CSS class from the *unrounded* `$pct` float while the displayed text was rounded to one decimal — a tiny genuine change (e.g. `-0.00001`) rendered a self-contradictory "-0,0 %" styled red. Verified live. Fixed: sign/class/text now all derive from the same rounded value.
- **[medium, patch]** (verification-gap, pre-verified) `pct_90d`/`pct_365d` had no value-correctness test — only `pct_7d` was checked against a hand-computed expected ratio; the two longer periods were only ever asserted `NULL` (insufficient-history path). Verified real via a concrete demonstration: a copy-paste slip in the hand-duplicated `CASE WHEN` block (e.g. reusing `prev_owners_7` while leaving the `DATEDIFF(...) = 90` guard) would still produce a plausible-looking positive number with the full suite green. Fixed: added `testPct90dPopulatesWithTheCorrectValueWhenExactlyNinetyCalendarDaysOfHistoryExist()` and the 365-day equivalent, mirroring `pct_7d`'s existing pattern.
- **[low, patch]** `pct_365d`'s fixed-365-calendar-day offset (not a same-calendar-date-last-year comparison) was undocumented — across a leap day, `DATEDIFF(...) = 365` lands one day before the literal anniversary. Verified accurate; this is the intended, correct, and consistent behavior (same fixed-day-count treatment as every other period), not a bug — just unexplained. Fixed: added one clarifying paragraph to the migration's docblock.
- **[out of scope]** The "insufficient history" mark relies solely on a `title` attribute, unreliable for screen readers and unreachable on touch. Rejected: UX-DR18 (the project's own, pre-existing accessibility floor) explicitly states "ingen dedikerad skärmläsargenomgång... uttryckligen utanför scope" (no dedicated screen-reader review, explicitly out of scope) — the intent this project already committed to excludes this class of work, not just this story's spec.
- **[false]** The new migration's `down()` claims to restore the original Story 3.1 view "verbatim," but a literal diff shows its inline SQL comments were dropped. Refuted: MariaDB does not persist comments in a view definition regardless of how `down()` is written, so true byte-verbatim-ness was never achievable either way — "verbatim" is used in its ordinary, functional sense (the query the view answers is unchanged), which is accurate.
- **[false]** Alla mode's new Vecka/90d/År line has no "Avanza"-source label, unlike the owner-count line above it. Refuted: this is consistent with, not a deviation from, this row's own already-established pattern — badges/sparkline/Delta chip are already silently Avanza-derived in Alla mode with no label (per this story's own frozen Intent, citing the same precedent), because none of those elements ever display Nordnet's own figure anywhere on the row. The owner-count line is labeled specifically because it is the sole place Nordnet's real number is also shown side by side — a disambiguation need the period-percentages line doesn't share.
- **[defer]** The migration's `DROP VIEW` + `CREATE VIEW` is not atomic, and `bin/deploy.sh` rsyncs source before running migrations — so a request hitting the server in that narrow window after a deploy but before `phinx migrate` completes could see a real "Unknown column" SQL error for the new `pct_7d`/`pct_90d`/`pct_365d` references. Verified real, but a pre-existing architectural characteristic of `bin/deploy.sh` since Story 1.10 (rsync-then-migrate ordering) and of this view's own DROP+CREATE convention since Story 3.1's own `down()` — every prior schema-change story shares the identical theoretical window; this story doesn't introduce or widen it. A proper fix (reordering `deploy.sh`, or switching to `CREATE OR REPLACE VIEW`) is a cross-cutting deploy/ops concern, out of this UI-feature story's scope. Deferred to `deferred-work.md`.

## Spec Change Log

## Review Triage Log

## Design Notes

The exact-N-calendar-days-back rule (not `sma_N`'s row-counted rule) is a deliberate
choice, not a literal reading of the AC's "same null-until-enough-rows principle as
sma_7/sma_30/sma_90" — that phrase is read as "the general idea of nulling out until
there's enough data," not a mandate to copy `sma_N`'s specific row-window mechanism.
`sma_N` is a rolling average (row-count-based windows are the correct tool there);
`pct_Nd` is a point-in-time comparison against a specific calendar offset, where a
data gap crossing the window would otherwise silently produce a comparison against
the wrong number of actual days — exactly the failure mode `pct_1d`'s existing
gap-aware `DATEDIFF(...) = 1` check was built to prevent (Story 3.1, 2026-09-11). This
spec extends that same proven mechanism rather than introducing a second, looser rule
for the three new periods; it also happens to satisfy the AC's null-until-enough-data
requirement as a side effect (a partition with fewer than N rows has no row N
positions back at all, so `prev_date_N` is `NULL` and the whole expression is `NULL`).

One practical consequence worth flagging, not a defect: because the exact-match rule
requires a *fully gap-free* run of N consecutive calendar days, `pct_365d` will rarely
populate for a young dataset (NFR7: no historical backfill, series starts empty) —
expected and correct, the same "insufficient history" state the app already surfaces
elsewhere (Aktiedetalj's Range picker), not something to work around here.

## Verification

**Commands:**
- `composer test` -- expected: all PHPUnit tests pass, including the new migration's
  DB-backed coverage (view recreated correctly) and the new row-content assertions
  Actual: `OK (484 tests, 2227 assertions)` against the real `stockpicker_test` MariaDB
  database (StoreTestCase's hand-mirrored view DDL, updated in step with the migration).
- `vendor/bin/phinx migrate -e development` / `rollback -t 20260911170000` / `migrate`
  again -- expected: the real migration applies cleanly, `down()` restores the exact
  pre-migration view, and re-applying is clean. Actual: confirmed via `SHOW COLUMNS FROM
  owner_count_metrics` at each step -- 14 columns (…, `pct_1d`, `pct_7d`, `pct_90d`,
  `pct_365d`, …) after `migrate`, back to the original 11 (no `pct_7d`/`pct_90d`/
  `pct_365d`) after `rollback`, 14 again after re-`migrate`; `development` left in the
  fully-migrated (`up`) state, matching every other already-applied migration.

**Manual checks (if no CLI):**
- Log in, visit `/` and `/?source=alla`; confirm the new Vecka/90d/År line appears
  below each row, colored correctly by sign, and shows "–" where history is
  insufficient.
