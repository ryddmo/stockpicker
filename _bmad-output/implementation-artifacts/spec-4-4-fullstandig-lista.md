---
title: 'Fullständig lista (Full List)'
type: 'feature'
created: '2026-09-13'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
context: ['{project-root}/AGENTS.md']
baseline_commit: '2c839c2031cd771d0db3b33b6e42d10277492f44'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Topplista only ever shows the top 10 per source/ranking-mode — Stefan can't dig
past that during a weekend research session, search for a specific company, or combine
filters (steady growth, spike-flagged, watchlisted, market list) the way EXPERIENCE.md's
"Flöde 2" describes.

**Approach:** Add `/list`, reached via a new footer link on Topplista, showing every active
instrument for the selected source as the same Leaderboard row component (rank position
dropped, everything else — star, badges, sparkline, delta — unchanged), with combinable
search/sort/filters resolved in one new `DerivedMetricsRepository` query. No pagination —
render the full filtered/sorted result in one page load, but batch the sparkline data for
every visible row into a single query rather than repeating Story 4.2's per-row pattern at
~740-row scale.

## Boundaries & Constraints

**Always:** Reuse `require_session()`, `WatchlistRepository`, and
`LeaderboardController`'s public badge helpers (`hasStreak`, `isSpiking`,
`streakBadgeHtml`, `spikeBadgeHtml`, `deltaChipHtml`) as-is. Search/sort/filters/source are
all per-request query params, never stored (AD-14), and combine with AND semantics (each
active filter narrows the result further). Sort defaults to owner count descending,
`isin ASC` tie-breaker (same convention as Story 4.2's ranking queries); search matches
instrument name only, case-insensitive substring. "Steady growth" filter reuses the exact
Topplista qualifying rule (`up_streak >= 1` AND not spiking); "spike-flagged" reuses
`DerivedMetricsRepository::SPIKE_THRESHOLD` exactly. Market-list filter values are the
literal stored strings (`LC`/`MC`/`SC`/`First North`), single-select. All new SQL —
search/filter/sort combination and the batched sparkline query — lands as new
`DerivedMetricsRepository` methods, never inline in `src/Web/` (AD-14).

**Never:** No pagination controls (no LIMIT/OFFSET, no page links) — render everything that
matches in one response. Do not call `recentSeries()` once per row (Story 4.2's pattern) —
that N+1 shape is only acceptable at Topplista's fixed 10-row scale, not at full-list scale;
batch it. Do not touch `src/Pipeline/`, `src/Adapter/`, `/cron/*`, or Story 4.3's
`StockDetailController`. Do not build Bevakningslista (Story 4.5). No client framework or
build step (AD-12) — search/sort/filter are plain links/a GET form, full page reload.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|---|---|---|---|
| Default view | `GET /list` | 200, all active instruments for Avanza, owner count desc | N/A |
| Search | `GET /list?q=volvo` | Rows whose name contains "volvo" (case-insensitive) | N/A |
| Sort by %-change | `GET /list?sort=pct` | Rows ordered by `pct_1d` desc, `isin` tie-break | N/A |
| Steady-growth filter | `GET /list?growth=1` | Only rows with `up_streak >= 1` and not spiking | N/A |
| Spike filter | `GET /list?spike=1` | Only rows with `spike_score >= SPIKE_THRESHOLD` | N/A |
| Watchlist-only filter | `GET /list?watchlist=1` | Only starred isins | N/A |
| Market filter | `GET /list?market=LC` | Only `instrument.list = 'LC'` rows | N/A |
| Combined filters | `GET /list?q=volvo&sort=pct&growth=1` | All three narrow the same result set together | N/A |
| Zero matches | Any combination yielding no rows | "Inga resultat för dessa filter." + a "rensa filter" link back to `/list` | N/A |
| Source switch | `GET /list?source=nordnet` | Nordnet's own instrument set/ranking, never merged with Avanza; other params persist | N/A |

</frozen-after-approval>

## Code Map

- `src/Web/LeaderboardController.php:290-292` (`pageHtml`'s `<main class="rows">` block) —
  add a footer link ("Visa fullständig lista" → `/list?source={current}`) after `</main>`,
  before the closing `</div>`.
- `src/Store/DerivedMetricsRepository.php` — no existing method fits; add
  `searchAndFilter(string $source, array $filters, string $sort): array` (or similar),
  building on the same `ROW_NUMBER() OVER (PARTITION BY isin ORDER BY as_of_date DESC)`
  "latest row" pattern as `topByOwnerCount()`/`topByTrendQuality()` (lines 78-107, 125-157),
  but with a full `WHERE` built from optional pieces (name `LIKE`, `up_streak >= 1 AND
  spike_score < SPIKE_THRESHOLD`, `spike_score >= SPIKE_THRESHOLD`, `isin IN (SELECT isin
  FROM watchlist)`, `instrument.list = ?`) and a `sort` param switching the `ORDER BY`
  column, always with `, isin ASC`. No `LIMIT`.
- `src/Store/DerivedMetricsRepository.php` — add `recentSeriesForIsins(array $isins, string
  $source, int $days): array` (keyed by isin) — one query for every visible row's sparkline
  instead of Story 4.2's per-row `recentSeries()` call, using the same last-N-per-isin shape
  as `recentSeries()` but with `isin IN (...)` and a window function to cap rows-per-isin.
- `instrument.list` stored values (confirmed): `'LC'`, `'MC'`, `'SC'`, `'First North'` — not
  spelled-out names.
- `src/Store/WatchlistRepository.php:23-31` (`starredIsins()`) — for the watchlist-only
  filter, use a SQL subquery/JOIN against `watchlist` in the new repository method rather
  than fetching all rows and filtering in PHP (this repo's SQL-in-Store-only convention);
  `starredIsins()` itself is still reused as-is for star-state rendering per row.
- `public_html/index.php` — add `case '/list':` mirroring `/`'s shape
  (`require_session()` → `Database::connect()` → controller → `render_html()`).
- `src/Web/LeaderboardController.php:93-163` — badge/delta helpers, reused as-is (same as
  Story 4.3).
- `tests/Store/DerivedMetricsRepositoryTest.php` — pattern to mirror (`StoreTestCase`,
  `NormalizedRow` + `OwnerCountRepository::upsert()` seeding).
- `tests/FrontControllerIntegrationTest.php` — `seedMatchedUniverse()`, `seedOwnerCount()`,
  `validCookie()` all reusable as-is.

## Tasks & Acceptance

**Execution:**
- [x] `src/Store/DerivedMetricsRepository.php` -- add `searchAndFilter()` and `recentSeriesForIsins()` per Code Map
- [x] `tests/Store/DerivedMetricsRepositoryTest.php` -- cover each filter alone, combined filters, each sort column, the tie-breaker, zero-match case, and the batched sparkline method
- [x] `src/Web/LeaderboardController.php` -- add the "Visa fullständig lista" footer link
- [x] `src/Web/FullListController.php` -- render the page: search box, sort control, filter controls, Source switcher, rows (reusing badge helpers), zero-results state with "rensa filter"
- [x] `tests/Web/FullListControllerTest.php` -- zero-results/"rensa filter" link and any pure selection logic in isolation from HTTP
- [x] `public_html/index.php` -- wire `/list`
- [x] `tests/FrontControllerIntegrationTest.php` -- end-to-end: default view, search, each filter alone, combined filters, each sort, zero-match state, source switch preserving other params

**Acceptance Criteria:**
- Given a valid session, when `/list` is requested with no params, then every active instrument for Avanza renders, ordered by owner count descending
- Given `?q=volvo`, when `/list` is requested, then only name-matching rows render, regardless of any other active filter
- Given `?growth=1&spike=1` (contradictory in practice — spiking rows are excluded from steady growth), when `/list` is requested, then the result is the intersection (rows satisfying both), which may be empty
- Given a filter/search combination with zero matches, when `/list` renders, then "Inga resultat för dessa filter." appears with a link back to `/list` with no params
- Given `?source=nordnet&q=volvo`, when `/list` is requested, then Nordnet's own matching instruments render and the search term persists in the rendered search box/links

## Implementation Notes

Implemented as specified. `DerivedMetricsRepository::searchAndFilter()` builds on the same
`ROW_NUMBER() OVER (PARTITION BY isin ORDER BY as_of_date DESC)` "latest row" CTE as
`topByOwnerCount()`/`topByTrendQuality()`, with an array of optional `WHERE` fragments
joined by `AND` — name search (`LOWER(name) LIKE LOWER(...)`, with `%`/`_`/`\` escaped so a
literal search term can never be misread as a wildcard), the steady-growth rule reusing
`up_streak >= 1 AND (spike_score IS NULL OR spike_score < SPIKE_THRESHOLD)` verbatim, the
spike rule (`spike_score >= SPIKE_THRESHOLD`), a `watchlist` subquery, and an exact
`instrument.list` match — always `ORDER BY ..., isin ASC`, no `LIMIT`.
`recentSeriesForIsins()` uses one query with `isin IN (...)` plus a per-isin
`ROW_NUMBER() OVER (PARTITION BY isin ...)` capped at `$days`, keyed by isin
(including isins with zero rows, same no-padding contract as `recentSeries()`), replacing
Story 4.2's per-row pattern at `/list`'s full scale.

`FullListController` mirrors `LeaderboardController`/`StockDetailController`'s established
shape: query params normalized by small `public static` pure functions (never trusting raw
`$_GET` past that point, so a garbage query string can't 500), badge/delta helpers
(`hasStreak`, `isSpiking`, `isSparklineMuted`, `streakBadgeHtml`, `spikeBadgeHtml`,
`deltaChipHtml`, `sparklineNoHistoryLabel`) reused as-is from `LeaderboardController`. The
sparkline SVG renderer itself is *not* in that reuse list (it's `private` on
`LeaderboardController`, deliberately out of scope to expose) — `FullListController` carries
its own byte-for-byte-equivalent copy rather than changing `LeaderboardController`'s public
surface for this story. The zero-results body (copy + "rensa filter" link) is its own pure
`emptyStateHtml()` static, matching the Task list's "isolated from HTTP" instruction for that
case specifically. Search is a plain GET `<form>`; sort/filter/market/source are plain links
built by one `url($active, $overrides)` helper that carries every other active param forward
and omits a param when it's the default — same convention as
`LeaderboardController::url()`/`StockDetailController::url()`. No pagination, no client JS
beyond the pre-existing `watchlist.js` star-toggle script tag (AD-12).

The footer link's target carries the current source forward (`/list?source=nordnet` when
viewing Nordnet, plain `/list` for the Avanza default) via a new private
`LeaderboardController::fullListUrl()`, added alongside the existing `<main class="rows">`
block per the Code Map's line reference.

Verified with `composer test`: 415 tests / 1943 assertions, all green, zero skips (checked
explicitly — `AGENTS.md` warns a green run can hide skipped DB-backed tests; grepped for
`skip`/`risky` in verbose output and found none, confirming the new DB-backed
`DerivedMetricsRepositoryTest`/`FrontControllerIntegrationTest` cases ran for real against
the docker-compose MariaDB). Every I/O & Edge-Case Matrix row and every Acceptance Criterion
has a corresponding passing test (unit-level in
`DerivedMetricsRepositoryTest`/`FullListControllerTest`, end-to-end in
`FrontControllerIntegrationTest`). Added one test beyond the spec's explicit list —
`FrontControllerTest::testListWithWrongMethodReturns405` — matching the existing DB-free
405-test precedent for every other GET-only route (`/list`'s method check runs before
`require_session()`/DB access, same as `/`).

**Judgment calls worth flagging:**
- The "rensa filter" link is a bare `/list` with no params at all, including dropping
  `source` back to the Avanza default — read literally from the Acceptance Criteria ("a link
  back to `/list` with no params"), not just clearing filters while keeping source/sort.
- `market` is matched with a plain `i.list = :market` comparison; case-sensitivity is *not*
  guaranteed at the SQL layer (`instrument.list`'s default utf8mb4 collation is
  case-insensitive, confirmed by
  `testSearchAndFilterMarketFilterCaseSensitivityAtTheSqlLayerIsDocumented`, which calls
  `searchAndFilter()` directly with `'first north'` and gets the `'First North'` row back) —
  "the literal stored strings" (Boundaries & Constraints) is enforced one layer up, by
  `FullListController::normalizeMarket()`'s exact-value whitelist, which is the only caller.
  An unrecognized/garbage `market` value normalizes to "no filter" rather than a 500 or an
  empty result.
- LIKE-wildcard characters (`%`, `_`, `\`) in a search term are escaped so they match
  literally — not specified either way in the spec, but the alternative (an accidental
  wildcard from a company name or user input) seemed like a worse default.

**Orchestrator note:** the implementation subagent also set this file's `status` to `done`
and `sprint-status.yaml`'s entry to `review` on its own — that's the orchestrator's call, not
the implementer's, so those were reset to the correct in-progress workflow stage and
re-applied properly after independent verification and the review round below, per the
usual process. The self-report above was still useful context but was not taken on trust:
independently re-verified — full diff read against `baseline_commit`, all 7 execution tasks
and all 5 spec-level ACs confirmed against the diff, all 10 I/O Matrix rows traced to a
specific passing test, `composer test` re-run independently (415 tests / 1943 assertions,
clean).

**Review patch round:** 6 patch findings applied — the `url()` carry-forward contract and a
toggle's own "turn off" path are now tested end-to-end; `escapeLike()`'s literal-match
behavior is now tested; the AC's `growth=1&spike=1` intersection scenario now has HTTP-level
coverage, not just a repository-level test; the reflected search term is now regression-
tested for safe escaping; the empty state's "rensa filter" link is now confirmed to drop
`source` too when reached from Nordnet. One finding's fix corrected a wrong claim rather than
just adding a test: the market filter's "exact, case-sensitive" documentation was actually
false at the SQL layer — MariaDB's default collation is case-insensitive, so `searchAndFilter()`
alone would match `'first north'` against a stored `'First North'` row; case-sensitivity in
practice is enforced one layer up, by `FullListController::normalizeMarket()`'s whitelist,
not by the query. The frozen Boundaries text itself never claimed SQL-layer case-sensitivity
(only "literal stored strings"), so nothing there needed correcting — the repository
docblock and this file's earlier Implementation Notes wording were updated to state the true
behavior accurately. 4 findings rejected as out of scope or low-value: ARIA-toggle
semantics and the duplicated sparkline renderer (both pre-accepted exclusions, matching
Stories 4.1–4.3's precedent), exhaustive filter-pairing coverage (no cross-filter logic
exists to regress), and deriving `MARKETS` from the database (the upstream `UniverseSync`
mapping already constrains the domain). Independently re-verified after patching:
`composer test` (421 tests, 1960 assertions, clean); all 6 new test methods confirmed
directly in the patched source.

## Spec Change Log

## Review Triage Log

- **`FullListController::url()`'s "carry forward every other active param" contract is untested, and so is a toggle's own "turn off" path** — `medium`, routes `patch`. Verified: reading `url()` and its callers confirms the mechanism is correct by construction (every rendering helper receives the same full `$active` state and merges its own override on top, same pattern already used by `LeaderboardController`/`StockDetailController`), but no test with multiple active params checks that another control's rendered `<a href>` still contains all of them, and none checks that an active filter's own toggle link correctly omits it (flips off). A regression here would silently drop a user's search/filters exactly when they refine further — the scenario the feature exists to prevent — and ship undetected, since every current test hand-builds its own query string rather than following a link the page rendered. (Corroborated independently by blind-hunter [toggle-off path] and verification-gap [carry-forward, pre-verified].)
- **`escapeLike()`'s literal-match behavior for `%`/`_` in a search term is untested** — `low`, routes `patch`. Verified: no test seeds a name containing a literal `%` or `_` and confirms the search still matches only that row. Verification-gap filed this as `defer` (narrow edge case, low-severity failure mode) but the fix is a single, trivial, bounded test — that satisfies `patch`'s own criteria more precisely than deferring a cheap close. (Corroborated by blind-hunter and verification-gap.)
- **`aria-pressed` on the filter-toggle `<a>` elements is toggle-button semantics on a navigation link, and inconsistent with the page's other three controls (class-only active state)** — rejected, out of scope. Same explicit exclusion as Stories 4.1–4.3: `EXPERIENCE.md`'s accessibility floor states "ingen dedikerad skärmläsargenomgång... (uttryckligen utanför scope)." (blind-hunter.)
- **The Acceptance Criteria's `?growth=1&spike=1` intersection scenario is only tested at the repository level, not end-to-end via `/list`** — `low`, routes `patch`. Verified: `testSearchAndFilterGrowthAndSpikeTogetherIsAlwaysEmpty` covers the repository method directly, but the AC text frames this as an HTTP-level scenario and nothing exercises `GET /list?growth=1&spike=1` through the full stack. (blind-hunter.)
- **Market-filter "exact, case-sensitive" matching relies entirely on `FullListController`'s whitelist, not on any DB-level guarantee** — `low`, routes `patch`. Verified: `instrument.list` is a plain `VARCHAR(20)` with no `CHECK`/`ENUM` constraint and no explicit collation, so `searchAndFilter()`'s `i.list = :market` comparison isn't provably case-sensitive at the SQL layer — case-sensitivity currently holds only because the one caller (`FullListController::normalizeMarket()`) whitelists exact values first. No test calls the repository method directly with a differently-cased value to document/lock in the actual behavior. (blind-hunter, corroborated by edge-case-hunter's collation observation.)
- **Filter-combination coverage doesn't exercise every pairing (e.g. `growth`+`market`, `spike`+`watchlist`)** — rejected. `searchAndFilter()` has no cross-filter special-casing — each filter independently appends its own `WHERE` fragment to a plain `AND`-joined list, and every fragment is already unit-tested alone. Testing every combinatorial pairing would exercise PHP's own well-established `implode()`/prepared-statement binding, not story-specific logic — more test volume than the risk justifies. (blind-hunter.)
- **The reflected search term (`q` echoed into the search box's `value` attribute) has no regression test confirming it's escaped** — `medium`, routes `patch`. Verified: `self::e($active['q'])` (`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`) is used correctly — this is not a live vulnerability — but it's the first place in the app that reflects raw query input into an HTML attribute, and nothing pins that behavior against a future refactor. (blind-hunter.)
- **`FullListController::sparklineHtml()` is a byte-for-byte duplicate of `LeaderboardController`'s private method, with nothing keeping them in sync** — rejected, out of scope. Same category as Story 4.3's CSS-duplication finding: the spec's own Boundaries excluded this method from the reuse list specifically because it's `private` on `LeaderboardController`, and no shared-component file exists yet (already-accepted architectural debt, not a new unacknowledged defect — the diff's own comment explains why). (blind-hunter.)
- **The empty-state "rensa filter" link is only tested with the default (Avanza) source active** — `low`, routes `patch`. Verified: the Implementation Notes document a deliberate judgment call (the link drops `source` too, even when viewing Nordnet), but no test reaches the empty state with `?source=nordnet` active to confirm the link is still a bare `/list`, not `/list?source=nordnet`. (blind-hunter.)
- **`FullListController::MARKETS` is a hardcoded list, not derived from `instrument.list`'s actual distinct values** — rejected. `instrument.list` is written only by `UniverseSync` (AD-3), sourced from Avanza's listing categorization already mapped and verified against real API data in Story 2.1 — a value outside `{LC,MC,SC,First North}` would first require a bug in that upstream, already-verified mapping. The suggested fix (a live `SELECT DISTINCT` on every page render) adds real complexity for a scenario already prevented upstream. (edge-case-hunter.)

## Design Notes

No pagination: EXPERIENCE.md's Flöde 2 never mentions page controls, and at ~740 rows with
no BLOB columns, one query plus one batched sparkline query is well within NFR8's 256MB
budget — the only real cost risk was Story 4.2's per-row `recentSeries()` pattern repeated
at 74x the scale, which this story avoids by batching instead.

Filter/search query params (all optional, combine with AND): `q` (name substring), `sort`
(`count` default | `pct`), `growth` (1|absent), `spike` (1|absent), `watchlist` (1|absent),
`market` (`LC`|`MC`|`SC`|`First North`|absent). `source` matches Story 4.2's existing
convention. Every control's link/form target carries forward all currently-active params
except the one it's changing (same pattern as `LeaderboardController::url()`).

## Verification

**Commands:**
- `composer test` -- expected: all PHPUnit tests pass, including new
  `DerivedMetricsRepositoryTest`/`FullListControllerTest`/`FrontControllerIntegrationTest`
  cases, no skips beyond the existing DB-guarded ones

**Manual checks (if no CLI):**
- Run `php -S localhost:8080 -t public_html` against a seeded local database; log in, click
  "Visa fullständig lista" from Topplista, try each filter alone and combined, search for a
  known name, switch sort and source, and confirm a zero-match combination shows the
  "rensa filter" state.
