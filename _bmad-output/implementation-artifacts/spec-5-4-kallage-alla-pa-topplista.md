---
title: 'Källäge "Alla" på Topplista'
type: 'feature'
created: '2026-09-13'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
context: []
baseline_commit: '1bce30fb53a2bcfb40ecd7e79f5e6db03297d76b'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Stefan has to open Aktiedetalj for every instrument to compare Avanza's
and Nordnet's owner counts side by side — Topplista's Source switcher only ever shows
one source's data at a time.

**Approach:** Add a third Source-switcher mode, "Alla" (shown first, before Avanza and
Nordnet), scoped to Topplista only (`LeaderboardController`; Fullständig lista and
Bevakningslista are untouched by this story). In "Alla" mode: ranking (both "Flest
ägare" and "Stadig tillväxt") always uses Avanza's data — the same top-10 rows Avanza
mode would show — with Nordnet's latest owner count fetched for those same isins and
displayed alongside on each row as "Avanza {n} · Nordnet {m}", never summed (NFR6).
Badges, sparkline, and delta chip stay Avanza-derived in Alla mode too (they're tied to
the ranking basis, same principle already used for Stadig tillväxt's criteria). An isin
with no Nordnet data shows "Nordnet ingen data" instead of a misleading zero. "Alla"
does not become the new default landing source — Topplista's existing Avanza default
(Story 4.2) is unchanged; "Alla" is simply first in the switcher's left-to-right order,
reachable via `?source=alla`.

## Boundaries & Constraints

**Always:** Avanza's and Nordnet's owner counts are shown side by side, never summed
or merged into one figure (NFR6 — different populations, neither is the legal
shareholder count). Ranking in Alla mode always uses Avanza as the basis for both
ranking modes; Nordnet's figure is display-only, never a sort key. An isin missing
Nordnet data shows "ingen data" for that source, never `0` or a blank. Fullständig
lista and Bevakningslista behavior is unchanged by this story.

**Never:** No new database table. No changes to `src/Pipeline/` or the write side of
`src/Store/` — this is a read-only display addition. Do not modify
`FullListController.php` or `WatchlistController.php`. Do not change
`LeaderboardController::rowBodyHtml()`'s signature — it stays reusable by the other two
controllers unchanged; the combined "Avanza X · Nordnet Y" text is built by the caller
and passed in as the existing `$eOwners` string parameter.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|---|---|---|---|
| Alla mode, both sources have data | `?source=alla` | Row shows "Avanza {n} · Nordnet {m}", never a summed figure | N/A |
| Alla mode, isin missing from Nordnet | `?source=alla`, no `owner_count_daily` row for that isin/nordnet | Row shows "Avanza {n} · Nordnet ingen data" | N/A |
| Alla mode, Stadig tillväxt | `?source=alla&ranking=steady` | Same top-10 isins/order as Avanza mode's Stadig tillväxt (Avanza `up_streak`/`spike_score`) | N/A |
| Alla mode switcher order | `/` renders | Switcher shows Alla, Avanza, Nordnet in that order, Alla first | N/A |
| Unknown/garbage source value | `?source=xyz` | Falls back to Avanza (existing convention), never 500 | N/A |
| Default landing view | `/` with no query params | Avanza mode, unchanged from Story 4.2 — Alla is not the default | N/A |

</frozen-after-approval>

## Code Map

- `src/Web/LeaderboardController.php:48-70` (`render()`) — inline two-way source
  normalization (`$source === NORDNET ? NORDNET : AVANZA`) becomes a three-way
  `public static normalizeSource(string $source): string` (mirrors
  `StockDetailController::normalizeSource()`'s existing naming/shape) returning
  `self::SOURCE_ALL | NormalizedRow::SOURCE_AVANZA | NormalizedRow::SOURCE_NORDNET`.
  Add `public const SOURCE_ALL = 'alla';` near the existing `RANKING_*` constants —
  Web-layer-only display concept, deliberately not added to `NormalizedRow` (that
  enum represents real adapter data sources, not a display mode; keeps `src/Adapter/`
  and `src/Pipeline/` untouched, per Boundaries).
- `src/Web/LeaderboardController.php:168-192` (`renderRow()`) — the data-fetch source
  (`topByOwnerCount`/`topByTrendQuality`/`recentSeries`) must always be Avanza when
  the page is in Alla mode (ranking/badges/sparkline stay Avanza-derived); pass a
  `$rankingSource` resolved once in `render()` (`self::SOURCE_ALL ? AVANZA : $source`)
  into `renderRow()` instead of the raw page `$source`. Add one new parameter,
  `?int $nordnetOwners` (only meaningful when the page is in Alla mode — `render()`
  passes `null` for every row when it isn't), and build `$eOwners` via the new
  `combinedOwnerCountText()` (below) instead of the current plain `number_format()`
  call when in Alla mode.
- `src/Web/LeaderboardController.php` (new `public static` method, same precedent as
  `deltaChipHtml()`/`streakBadgeHtml()`): `combinedOwnerCountText(int $avanzaOwners,
  ?int $nordnetOwners): string` returning `"Avanza {n} · Nordnet {m}"` or `"Avanza {n}
  · Nordnet ingen data"` — the literal format from the epic's AC, same `·` separator
  convention as the Delta chip. Unescaped return (caller still runs it through the
  existing `self::e()` before handing to `rowBodyHtml()`, matching this class's
  established discipline even though nothing in the string needs escaping).
- `src/Web/LeaderboardController.php:378-421` (`sourceSwitcherHtml()`, `url()`) — add
  a third "Alla" tab/href, positioned first in the switcher markup. `url()` gains one
  new branch: `self::SOURCE_ALL` sets `?source=alla`, same omit-when-default pattern
  already used for Nordnet. `rankingToggleHtml()`/`fullListUrl()`/`infoUrl()` need NO
  changes — they already fall through to "no suffix" for any non-Nordnet value,
  which already covers `alla` correctly (Fullständig lista/Bevakningslista silently
  default to Avanza on an unrecognized/unsupported source value, their own existing,
  untouched convention — satisfies the "unchanged" Boundary with zero edits to those
  two controllers).
- `src/Store/DerivedMetricsRepository.php:314-345` (`recentSeriesForIsins()`) — the
  exact pattern to mirror for the new `latestOwnerCountForIsins(array $isins, string
  $source): array<string, int>`: one batch query over `owner_count_daily` with a
  `ROW_NUMBER() OVER (PARTITION BY isin ORDER BY as_of_date DESC)` window, `rn = 1`.
  Unlike `recentSeriesForIsins()`, do NOT pad missing isins with an empty entry — an
  isin absent from the returned array IS the "no Nordnet data" signal `renderRow()`
  checks with `$map[$isin] ?? null`.
- `public_html/index.php` — **no changes**: the `/` route already forwards the raw
  `$_GET['source']` string to `LeaderboardController::render()` untouched;
  `normalizeSource()` handles `'alla'` entirely inside the controller.
- `src/Web/FullListController.php`, `src/Web/WatchlistController.php` — **no
  changes** (Boundaries); confirm by inspection only, not by editing.

## Tasks & Acceptance

**Execution:**
- [x] `src/Store/DerivedMetricsRepository.php` -- add `latestOwnerCountForIsins(array $isins, string $source): array<string, int>` -- one batch query, missing isin = no data, mirrors `recentSeriesForIsins()`'s pattern
- [x] `src/Web/LeaderboardController.php` -- add `SOURCE_ALL` const, `normalizeSource()`, `combinedOwnerCountText()`; update `render()` to resolve a Avanza-only `$rankingSource`, batch-fetch Nordnet counts in Alla mode, and thread `?int $nordnetOwners` into `renderRow()`; update `renderRow()` to build `$eOwners` via `combinedOwnerCountText()` when in Alla mode; update `sourceSwitcherHtml()`/`url()` for the third "Alla" tab (first in order)
- [x] `tests/Store/DerivedMetricsRepositoryTest.php` (or the DB-backed equivalent) -- test `latestOwnerCountForIsins()`: present data, missing isin, empty input list
- [x] `tests/Web/LeaderboardControllerTest.php` -- unit-test `normalizeSource()` (all three values + garbage fallback) and `combinedOwnerCountText()` (both-present, Nordnet-missing) directly, no database
- [x] `tests/FrontControllerIntegrationTest.php` -- `/` in Alla mode: switcher shows Alla/Avanza/Nordnet in order with Alla first; a row with both sources shows the combined text; a row missing Nordnet data shows "ingen data"; Stadig tillväxt in Alla mode ranks by the same isins/order as Avanza's Stadig tillväxt; default `/` (no query) is still Avanza-only, unchanged from Story 4.2
- [x] `tests/FrontControllerIntegrationTest.php` -- confirm `/list` and `/watchlist` are unaffected by `?source=alla` (falls back to their existing Avanza-default behavior, no new code path)

**Acceptance Criteria:**
- Given Topplistans källväxlare renders, then it shows Alla, Avanza, Nordnet in that order, Alla first
- Given Alla mode is selected, when a row renders, then both sources' owner counts show separately on the row (e.g. "Avanza 1 234 · Nordnet 567"), never a summed figure
- Given Alla mode and Flest ägare, when Topplista renders, then rows rank by Avanza's owner count, with Nordnet's count shown alongside, never as the ranking basis
- Given Alla mode and Stadig tillväxt, when Topplista renders, then rows rank by the same Stadig tillväxt criteria as Avanza mode (Avanza's `up_streak`/`spike_score`), with Nordnet's count shown alongside
- Given an instrument missing data from one source in Alla mode, when it renders, then that source shows "ingen data", never a misleading zero
- Given Fullständig lista and Bevakningslista, when this story is implemented, then they remain unchanged — Alla mode is scoped to Topplista only

## Implementation Notes

`DerivedMetricsRepository::latestOwnerCountForIsins()` mirrors `recentSeriesForIsins()`'s
batch-over-isins shape (one query, `ROW_NUMBER() OVER (PARTITION BY isin ...)`, `rn = 1`)
but, per the Code Map, does not pre-pad the result — an isin absent from the returned
`array<string, int>` is exactly the "no Nordnet data" signal `render()` converts to
`null` via `$nordnetOwners[$isin] ?? null` before handing it to `renderRow()`.

`LeaderboardController::normalizeSource()` is a three-way `match` (mirrors
`StockDetailController::normalizeSource()`'s naming/shape, extended one case). `render()`
resolves `$rankingSource` once (`SOURCE_ALL ? AVANZA : $source`) and uses it for both
`topByTrendQuality()`/`topByOwnerCount()` and the batch Nordnet fetch (only run when the
page source is Alla and the ranking query returned rows).

One deliberate deviation from the Code Map's literal renderRow() wiring: rather than
resolving `$rankingSource` in `render()` and feeding it into `renderRow()`'s existing
`$source` parameter (which would leave `renderRow()` with no way to distinguish "not
Alla mode" from "Alla mode, isin missing Nordnet data" -- both would arrive as
`$nordnetOwners === null`), `renderRow()` still receives the page-level `$source`
(possibly `SOURCE_ALL`) unchanged, resolves its own `$dataSource` for `recentSeries()`
(`SOURCE_ALL ? AVANZA : $source`), and branches `$eOwners`'s construction on
`$source === SOURCE_ALL` directly. Net effect on behavior is identical to the Code Map's
description (ranking/badges/sparkline/series are always Avanza-derived in Alla mode);
only the internal plumbing differs, and it removes the ambiguity. `renderRow()` gained
exactly one new parameter, `?int $nordnetOwners`, as specified.

`sourceSwitcherHtml()`/`url()` got a third "Alla" branch, positioned first in both the
rendered tab order and the switcher markup. `tabBarHtml()`/`rankingToggleHtml()`/
`fullListUrl()`/`infoUrl()` were left untouched, per the Code Map: they already fall
through to "no suffix" for any non-Nordnet source, which covers `alla` correctly, and
`FullListController`/`WatchlistController` (confirmed untouched by `git diff --stat`)
already normalize any unrecognized source value -- `alla` included -- back to Avanza on
their own, so `/list?source=alla` and `/watchlist?source=alla` silently behave exactly
as `/list`/`/watchlist` always have.

`public_html/index.php` needed no changes, confirmed by inspection: `/`'s route handler
already forwards the raw `$_GET['source']` string to `LeaderboardController::render()`
untouched, and `normalizeSource()` handles `'alla'` entirely inside the controller.

`composer test`: 469 tests, 2137 assertions, all green (18 new tests: 7 in
`LeaderboardControllerTest`, 4 in `DerivedMetricsRepositoryTest`, 7 in
`FrontControllerIntegrationTest`).

**Orchestrator verification:** independently re-verified — full diff read against
`baseline_commit`, all 6 execution tasks and all 6 spec-level ACs confirmed directly
against the diff content, all 6 I/O & Edge-Case Matrix rows confirmed covered by a
passing test. Independently re-ran `composer test`: 469 tests, 2137 assertions, clean —
matches the subagent's reported numbers exactly. Confirmed via `git diff --stat` that
`FullListController.php`/`WatchlistController.php`/`public_html/index.php`/
`src/Pipeline/` are untouched, matching Boundaries. Evaluated the "deliberate deviation"
the subagent flagged (renderRow() resolving its own `$dataSource` internally rather than
receiving a pre-resolved `$rankingSource`) and confirmed it is functionally equivalent
to the Code Map's description and, if anything, clearer — no correction needed. Reset
`status` from `done` to `in-progress` (set by the subagent) and `sprint-status.yaml`'s
`5-4-...` entry stays at `in-progress` pending this review round — both are the
orchestrator's responsibility per this workflow, not the implementer's, same correction
applied on prior stories this session.

## Review Triage Log

- **[medium, patch]** `tabBarHtml()`'s persistent "Topplista" tab-bar link (always visible at the top of every page) was not updated for `SOURCE_ALL` — it only special-cased Nordnet, so clicking the always-present "Topplista" tab while already viewing Alla mode silently reset the view back to Avanza. Verified: the link's own target (`/`) is Topplista itself, which *does* support Alla, unlike `fullListUrl()`/`infoUrl()`'s targets. Fixed: `tabBarHtml()`'s suffix logic now handles `SOURCE_ALL` the same as Nordnet.
- **[low, patch]** `fullListUrl()`'s docblock claimed switching to Full list "doesn't silently reset back to Avanza," which is no longer strictly true for the new `alla` source (Full list has no Alla concept, so it correctly falls back to Avanza there). Verified: the behavior itself is correct and required by Boundaries; only the comment overclaimed. Fixed: added one clause noting the deliberate exception.
- **[low, patch]** No test distinguished a stored Nordnet row of exactly `0` owners from "no stored row at all" — both currently render correctly (`0` vs `"ingen data"`) via the strict `!== null` check, but nothing would catch a future refactor that loosened it to a falsy/`empty()` check. Fixed: added `testCombinedOwnerCountTextShowsALiteralZeroDistinctFromMissingData()`.
- **[low, patch]** No test exercised Alla mode's Stadig tillväxt with zero qualifying instruments (a reachable, ordinary state — same empty-state precedent already tested for Avanza mode). Fixed: added `testRootWithSourceAllaAndRankingSteadyShowsTheEmptyStateWhenNoInstrumentQualifies()`, also confirming the Nordnet batch fetch's `$rows !== []` guard doesn't error on an empty result.
- **[medium, patch]** (verification-gap, pre-verified) No test proved the sparkline is actually plotted from Avanza's real series in Alla mode rather than silently landing on the true empty state — demonstrated concretely: if `renderRow()`'s `$dataSource` resolution were ever reverted to the raw page `$source` (`'alla'`), `recentSeries()`'s exact `source` match would return `[]` for every row (no stored row has `source = 'alla'`), and every Alla-mode sparkline would silently render "ingen trend än" instead of a real trend, with no existing test catching it. Fixed: extended the existing Stadig-tillväxt-in-Alla-mode integration test with an assertion that Beta AB's sparkline renders a real (if muted, given its 4-day fixture) polyline, not the true `sparkline--empty` state.
- **[false]** The "Alla" Source-switcher label collides with `FullListController`'s pre-existing, unrelated "Alla" market-filter option. Refuted: the two live on different pages, never visible together, and both use the same common Swedish word ("all/everything") consistently within their own, unambiguous context (all sources vs. all markets) — no more confusing than reusing "All" for distinct filters across different pages in any app. The dedicated test helper the reviewer cited as evidence was needed only to avoid a *test-assertion* collision on `/list`'s page body, not a real end-user confusion risk.

## Spec Change Log

## Review Triage Log

## Design Notes

The combined owner-count text is one line, matching the AC's own literal example —
this can widen `.statcol` beyond its `70px` sizing hint on narrow screens, exactly the
scenario Story 5.1 already designed for: `.namecol`'s `min-width: 0` absorbs the
pressure (ellipsizing the name further) rather than clipping the count, so no new CSS
is needed and no new clipping risk is introduced. Default landing source is a
deliberate decision, not an open question: Story 4.2's AC still stands ("källan
Avanza... default") and nothing in this story's AC says otherwise — "Alla... visas
först" describes switcher tab order, not the initial selection.

## Verification

**Commands:**
- `composer test` -- expected: all PHPUnit tests pass, including the new repository
  method tests and the new `/` Alla-mode integration tests

**Manual checks (if no CLI):**
- Log in, visit `/?source=alla` and `/?source=alla&ranking=steady`; confirm the
  switcher order, the combined owner-count text, and that an instrument with only
  Avanza data shows "ingen data" for Nordnet.
