---
title: 'Bevakningslista-flik (Watchlist Tab)'
type: 'feature'
created: '2026-09-13'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
context: ['{project-root}/AGENTS.md']
baseline_commit: '997bae5cda16c87544dbaeee3c164ac578dde075'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Stefan's starred instruments are scattered across whichever page he happened to
star them from — there's no quick way to see "just what I'm already watching" without
re-filtering Fullständig lista every time, and none of the four authenticated pages built so
far show the persistent tab bar EXPERIENCE.md's information architecture calls for.

**Approach:** Add `/watchlist`, reached via a persistent two-tab bar (Topplista,
Bevakningslista) that this story adds to *all four* authenticated pages (Topplista,
Fullständig lista, Aktiedetalj, and the new Bevakningslista), replacing the ad-hoc
"← Topplista" back-link on Fullständig lista/Aktiedetalj (redundant once the tab bar's own
Topplista tab exists). `WatchlistController` reuses `DerivedMetricsRepository::searchAndFilter()`
with the `watchlist` filter already built in Story 4.4 — no new repository method.

## Boundaries & Constraints

**Always:** Reuse `require_session()`, `WatchlistRepository`, `LeaderboardController`'s
public badge/delta helpers, and `DerivedMetricsRepository::searchAndFilter($source,
['watchlist' => true], DerivedMetricsRepository::SORT_COUNT)` /
`recentSeriesForIsins()` exactly as Story 4.4 established. The tab bar shows only Topplista
and Bevakningslista (Fullständig lista stays reachable only via Topplista's own footer
action, never a tab) and appears on all four authenticated pages; `/login` stays entirely
outside it. Bevakningslista includes its own Source switcher (the `watchlist` table has no
source column — a starred isin's data is still per-source), defaulting to Avanza, same
convention as every other list page.

**Never:** No new repository method — `searchAndFilter()` already does this. Do not touch
`src/Pipeline/`, `src/Adapter/`, `/cron/*`, or the `watchlist`/`/watchlist/toggle` mechanics
themselves (Story 4.2). No pagination (same reasoning as Story 4.4 — a personal watchlist is
smaller than the full universe by construction). No client framework or build step (AD-12).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|---|---|---|---|
| Some starred instruments | `GET /watchlist`, valid session | 200, only starred isins for Avanza, as Leaderboard rows | N/A |
| No starred instruments | `GET /watchlist` | "Inga aktier bevakade än." with a link back to `/` | N/A |
| Star toggle from Bevakningslista | `POST /watchlist/toggle` (same endpoint as Story 4.2) | Unstars and the row should disappear on the next load — no reload happens automatically, `watchlist.js` updates the star in place | N/A |
| Row tap | tap anywhere but the star | Navigates to `/stock/{isin}` | N/A |
| Source switch | `GET /watchlist?source=nordnet` | Nordnet's data for the same starred isins, never merged with Avanza | N/A |
| Tab bar on any authenticated page | `GET /`, `/list`, `/stock/{isin}`, `/watchlist` | Topplista and Bevakningslista tabs both present, correct one marked active | N/A |
| No session | `GET /watchlist` | Login form, same as any other protected route | N/A |

</frozen-after-approval>

## Code Map

- `src/Web/LeaderboardController.php::pageHtml()` (~264-301) — insert the tab-bar snippet
  inside `<div class="page">`, before `<header class="page-header">`.
- `src/Web/FullListController.php::pageHtml()` (~268-309) — replace the `<p class="back-link">`
  block with the same tab-bar snippet.
- `src/Web/StockDetailController.php::pageHtml()` (~376-426) — same replacement as
  FullListController.
- `src/Store/DerivedMetricsRepository.php::searchAndFilter()` — already supports
  `['watchlist' => true]` (Story 4.4); no change needed here.
- `src/Store/WatchlistRepository.php` — `starredIsins()`/`toggle()`, unchanged, reused as-is.
- `public_html/index.php` — add `case '/watchlist':` right before the existing
  `case '/watchlist/toggle':`, mirroring `/list`'s exact shape (method guard →
  `require_session()` → `Database::connect()` → controller → `render_html()`).
- `src/Web/FullListController.php::emptyStateHtml()`/`emptyStateCopy()` (~142-157) — pattern
  to mirror for `WatchlistController`'s own empty state (copy + a link, no "rensa filter"
  needed since there's nothing to clear — just a link back to `/`).
- No shared layout file exists (confirmed, matches Stories 4.3/4.4's already-accepted CSS
  duplication debt) — the tab-bar HTML/CSS snippet is duplicated across all four
  controllers' `css()`/`pageHtml()` methods, same convention, not a new gap.
- `tests/FrontControllerIntegrationTest.php` — `seedMatchedUniverse()`, `seedOwnerCount()`,
  `validCookie()`, and the `POST /watchlist/toggle` seeding pattern (e.g. from Story 4.4's
  `testListWatchlistFilterOnlyShowsStarredIsins`) all reusable as-is.

## Tasks & Acceptance

**Execution:**
- [x] `src/Web/WatchlistController.php` -- new, mirrors `FullListController`'s shape but calls `searchAndFilter($source, ['watchlist' => true], SORT_COUNT)` directly, no search/sort/filter controls, just a Source switcher, rows, and the empty state
- [x] `tests/Web/WatchlistControllerTest.php` -- empty-state copy/HTML in isolation from HTTP
- [x] `src/Web/LeaderboardController.php` -- add the shared tab-bar snippet
- [x] `src/Web/FullListController.php` -- add the tab bar, remove the now-redundant back-link
- [x] `src/Web/StockDetailController.php` -- add the tab bar, remove the now-redundant back-link
- [x] `public_html/index.php` -- wire `/watchlist`
- [x] `tests/FrontControllerIntegrationTest.php` -- end-to-end: some starred instruments render, empty state, source switch, tab bar present with the correct active tab on all four pages, no-session case

**Acceptance Criteria:**
- Given one or more starred instruments, when `/watchlist` is requested, then only those instruments render as Leaderboard rows for the selected source
- Given no starred instruments, when `/watchlist` is requested, then "Inga aktier bevakade än." renders with a link back to `/`
- Given any of `/`, `/list`, `/stock/{isin}`, or `/watchlist`, when the page renders, then the same two-tab bar (Topplista, Bevakningslista) appears with the correct tab marked active
- Given `/watchlist?source=nordnet`, when the page renders, then Nordnet's data for the same starred isins renders, never merged with Avanza's

## Implementation Notes

Implemented as specified: `WatchlistController` calls `DerivedMetricsRepository::searchAndFilter()`
with the `watchlist` filter Story 4.4 already built — no new repository method. The
persistent tab bar (`tabBarHtml(string $active)`) is duplicated byte-for-byte across all
four page controllers, replacing the ad-hoc back-link on Fullständig lista/Aktiedetalj, per
the frozen Design Notes' explicit call that this is a continuation of the already-accepted
CSS-duplication debt, not a new gap. `WatchlistController::sparklineHtml()` is likewise its
own copy, matching Story 4.4's precedent for `FullListController`.

Verified independently (not just from the implementation report): full diff read against
`baseline_commit`, all 7 execution tasks and all 4 spec-level ACs confirmed against the
diff, 6 of 7 I/O Matrix rows traced to a specific passing test (row-tap navigation to
`/stock/{isin}` is implicit via the same already-tested `href` pattern reused verbatim from
`LeaderboardController`/`FullListController`, not re-tested here), `composer test` re-run
independently (431 tests / 1998 assertions, clean).

**Review round:** ran in two passes and combined into one triage after a mid-review
interruption — my own `blind-hunter` pass, plus the user's independently-run `/code-review`
(4 parallel angles: removed-behavior, cross-file tracer, reuse/simplification/efficiency,
and their own cleanup pass). The two passes cross-validated each other on 3 of 4 real
findings (the racy/redundant `starredIsins()` query, the duplicate `.tab-bar` CSS, and the
ARIA `role="tablist"` gap), giving real confidence in those verdicts; my own pass separately
caught the source-dropping tab-bar links (the highest-severity finding this round — 100%
reproducible, not a race) and the missing 405 test, neither of which the `/code-review` run
surfaced. 4 patch findings applied: `tabBarHtml()` now threads `$source` through and
preserves it across tab switches (`LeaderboardController`, `FullListController`,
`StockDetailController`, `WatchlistController`); `WatchlistController` no longer depends on
`WatchlistRepository` at all — every row is unconditionally starred by construction, so the
second query and its race were removed entirely, not just fixed; the duplicate `.tab-bar`
CSS rule is gone; `/watchlist` now has a dedicated DB-free 405 test matching every other
guarded route's precedent. 3 findings rejected as out of scope, all matching established
Epic 4 precedent: `watchlist.js` not removing an unstarred row from `/watchlist`'s DOM (this
story's own frozen Boundaries explicitly forbid touching the toggle mechanics), the ARIA gap
(`EXPERIENCE.md`'s explicit accessibility exclusion), and `normalizeSource()`'s duplication
(the same already-accepted no-shared-file debt as `tabBarHtml()`/`sparklineHtml()`). 1
finding verified false: `sprint-status.yaml` not appearing in the code diff is expected — it's
maintained by the orchestrator's own process, same as every prior story. Independently
re-verified after patching: `composer test` (432 tests, 2000 assertions, clean); all four
fixes confirmed directly in the patched source (`tabBarHtml($active, $source)` signatures,
`WatchlistController`'s single-argument constructor, the deduplicated CSS, and the new test).

## Spec Change Log

## Review Triage Log

- **The tab bar's `href="/"`/`href="/watchlist"` links drop the current `?source=` selection, silently bouncing a Nordnet-viewing user back to Avanza on every tab switch** — `high`, routes `patch`. Verified directly: `tabBarHtml()` hardcodes both hrefs with no source parameter, unlike every sibling navigation link in the same files (`LeaderboardController::fullListUrl($source)`, each controller's own `url($source, ...)` for its source switcher) — all of which carefully preserve the selected source. This is 100% reproducible (not a race), and is the one navigation element in this story that breaks an otherwise-consistent, already-established pattern used 4+ times elsewhere in the same codebase. Fix: thread `$source` through `tabBarHtml($active, $source)` in all four controllers and append `?source=nordnet` when applicable, same shape as the existing `url()` helpers. (My own blind-hunter pass; not raised by the parallel `/code-review` run.)
- **`WatchlistController::render()`'s second `starredIsins()` query is both dead weight and a non-atomic-read race** — `medium`, routes `patch`. Verified: `searchAndFilter($source, ['watchlist' => true], ...)` already restricts every returned row to isins currently in `watchlist` via an atomic single-statement subquery — so the star is unconditionally true by construction, and the extra `starredIsins()` call only matters if a concurrent `/watchlist/toggle` unstars a row between the two queries, at which point that row renders with an empty star on the one page whose entire premise is "starred only." Fix: drop the `WatchlistRepository` dependency and `starredIsins()` call entirely, render every row's star as filled — simpler than the current code, not just safer. (Corroborated independently by my own blind-hunter pass and the user's parallel `/code-review` run — cross-validated by two independent review processes.)
- **`WatchlistController::css()` declares `.tab-bar`'s layout properties twice** — `low`, routes `patch`. Verified: `.tab-bar` gets its own standalone rule and is then re-included in the `.source-switcher, .tab-bar` combined selector, redeclaring the same properties — the three sibling controllers each pair `.source-switcher` with a different, page-specific control class instead. Fix: drop `.tab-bar` from the combined selector, matching the sibling pattern. (Corroborated independently by both review passes.)
- **`/watchlist`'s 405 (wrong-method) branch has no dedicated test** — `low`, routes `patch`. Verified: every other guarded route in this codebase (`/`, `/login`, `/watchlist/toggle`, `/list`, `/stock/{isin}`) has its own DB-free 405 test in `tests/FrontControllerTest.php`; `/watchlist` is the one exception. Fix: mirror `testListWithWrongMethodReturns405`. (My own blind-hunter pass.)
- **`watchlist.js`'s toggle only flips the star's CSS class in place — an unstarred row lingers, visibly unstarred, on `/watchlist` until the next reload** — rejected, out of scope. This story's own frozen Boundaries explicitly exclude touching "the watchlist/toggle mechanics themselves (Story 4.2)," and the only real fix (removing the row from the DOM on unstar) requires editing `watchlist.js`'s shared toggle logic, which the frozen intent forbids. (Corroborated independently by both review passes; both also independently noted the underlying behavior is pre-existing from Story 4.2, newly exposed rather than newly introduced.)
- **The new tab bar uses `role="tablist"` with plain `<a>` children — no `role="tab"`/`aria-selected`/`aria-current`** — rejected, out of scope. Same explicit exclusion as every prior Epic 4 story: `EXPERIENCE.md`'s accessibility floor states "ingen dedikerad skärmläsargenomgång... (uttryckligen utanför scope)." The `/code-review` pass noted this is a more clear-cut case than the pattern's prior same-page uses (source switcher, sort toggle) since it's now real cross-page navigation — a fair distinction, but the underlying product-level exclusion is the same regardless of which control it sits on. (Corroborated by both review passes.)
- **`WatchlistController::normalizeSource()` is a third byte-identical copy of logic already `public static` on `FullListController`/`StockDetailController`** — rejected, out of scope. Same category as `tabBarHtml()`/`sparklineHtml()`'s already-accepted duplication: no shared base-controller or helper file exists yet, and extracting one is explicitly out of scope per the same debt this story's own Design Notes already carries forward from Stories 4.3/4.4. (`/code-review` run.)
- **`sprint-status.yaml` isn't updated in this diff** — `false`. This is handled separately by the orchestrator's own process (frontmatter/tracking sync happens outside the code diff, same as every prior story in this epic), not omitted — verified by checking the actual workflow, not just the diff. (My own blind-hunter pass.)

## Design Notes

The existing "← Topplista" back-link on Fullständig lista/Aktiedetalj is removed, not kept
alongside the new tab bar — the tab bar's own Topplista tab serves the identical purpose, and
having both would be visually redundant. `DESIGN.md` doesn't specify a tab-bar component
directly (it predates this exact combination); style it with the same `.tab`/`.tab--active`
pill convention already used for the Source switcher/Ranking-mode toggle throughout, placed
at the very top of `.page`, above the wordmark/h1.

## Verification

**Commands:**
- `composer test` -- expected: all PHPUnit tests pass, including new
  `WatchlistControllerTest` and `FrontControllerIntegrationTest` cases, no skips beyond the
  existing DB-guarded ones

**Manual checks (if no CLI):**
- Run `php -S localhost:8080 -t public_html` against a seeded local database; log in, star a
  couple of instruments from Topplista, open the Bevakningslista tab and confirm only those
  render, unstar one and confirm the tab bar appears consistently on every page.
