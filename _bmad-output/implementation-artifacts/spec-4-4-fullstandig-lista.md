---
title: 'Fullständig lista (Full List)'
type: 'feature'
created: '2026-09-13'
status: 'ready-for-dev'
route: 'dispatch'
review_loop_iteration: 0
context: ['{project-root}/AGENTS.md']
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
- [ ] `src/Store/DerivedMetricsRepository.php` -- add `searchAndFilter()` and `recentSeriesForIsins()` per Code Map
- [ ] `tests/Store/DerivedMetricsRepositoryTest.php` -- cover each filter alone, combined filters, each sort column, the tie-breaker, zero-match case, and the batched sparkline method
- [ ] `src/Web/LeaderboardController.php` -- add the "Visa fullständig lista" footer link
- [ ] `src/Web/FullListController.php` -- render the page: search box, sort control, filter controls, Source switcher, rows (reusing badge helpers), zero-results state with "rensa filter"
- [ ] `tests/Web/FullListControllerTest.php` -- zero-results/"rensa filter" link and any pure selection logic in isolation from HTTP
- [ ] `public_html/index.php` -- wire `/list`
- [ ] `tests/FrontControllerIntegrationTest.php` -- end-to-end: default view, search, each filter alone, combined filters, each sort, zero-match state, source switch preserving other params

**Acceptance Criteria:**
- Given a valid session, when `/list` is requested with no params, then every active instrument for Avanza renders, ordered by owner count descending
- Given `?q=volvo`, when `/list` is requested, then only name-matching rows render, regardless of any other active filter
- Given `?growth=1&spike=1` (contradictory in practice — spiking rows are excluded from steady growth), when `/list` is requested, then the result is the intersection (rows satisfying both), which may be empty
- Given a filter/search combination with zero matches, when `/list` renders, then "Inga resultat för dessa filter." appears with a link back to `/list` with no params
- Given `?source=nordnet&q=volvo`, when `/list` is requested, then Nordnet's own matching instruments render and the search term persists in the rendered search box/links

## Implementation Notes

## Spec Change Log

## Review Triage Log

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
