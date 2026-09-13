---
title: 'Aktiedetalj (Stock Detail)'
type: 'feature'
created: '2026-09-13'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
context: ['{project-root}/AGENTS.md']
baseline_commit: 'b8f0b0846b2cc55c9fd1d03f917e54ba33194171'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Since Story 4.2, every leaderboard row already links to `/stock/{isin}`, but the
route doesn't exist — Stefan can spot a candidate on the Topplista but can't actually
verify whether its movement is a real trend or a spike, which is the entire point of the
product.

**Approach:** Add `/stock/{isin}` (a new path-parameter route, the first in this codebase)
rendering a `StockDetailController` that fetches both sources' full metrics series
unconditionally (AD-14), draws a Trend overlay (primary line = active Source-switcher
selection, secondary = the other source, always dashed/fixed-color regardless of its own
direction), a five-segment Range picker (Dag/Vecka/30d/90d/År) that slices the
already-fetched series client-side (no per-range query), and reuses Story 4.2's
`WatchlistRepository`/badge helpers for the header's star and streak/spike context.

## Boundaries & Constraints

**Always:** Fetch both sources' full series via `DerivedMetricsRepository::forIsin($isin)`
on every request, never per-range (AD-14) — the Range picker only changes which slice of the
already-fetched arrays is rendered. Primary line = the source named by `?source=` (default
Avanza, same param/default as Story 4.2's switcher); secondary line is always the fixed,
non-contextual color + dashed, regardless of its own trend direction. Reuse
`require_session()`, `WatchlistRepository`, and `LeaderboardController`'s public badge
helpers (`hasStreak`, `isSpiking`, `streakBadgeHtml`, `spikeBadgeHtml`) as-is — no
duplicate badge logic. Unknown isin → 404. `Vecka`/`30d`/`90d`/`År` insufficiency gates key
off the *primary* source's row count for the selected range (`< 7`/`< 30`/`< 90`/`< 90`
respectively) — the secondary line renders with whatever it has, un-gated, since sources are
never required to have matching coverage (NFR6).

The `Dag` range shows the day-over-day view: the last 2 rows, gated at `< 2` (decided
2026-09-13) — mirrors `delta_1d` exactly, the tightest zoom the picker widens outward from.

**Never:** No new SQL for the per-range slicing — `forIsin()` already returns the full
ordered series; slicing/counting happens in PHP. Do not touch `src/Pipeline/`,
`src/Adapter/`, `/cron/*`, or Story 4.2's leaderboard/watchlist-toggle code paths beyond
reusing their public helpers. Do not build Fullständig lista or Bevakningslista (Stories
4.4–4.5). No client framework or build step (AD-12) — the Range picker and Source switcher
are plain links like Story 4.2's, reloading the page; only the watchlist star reuses Story
4.2's existing `watchlist.js`/`/watchlist/toggle`, unchanged.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|---|---|---|---|
| Valid session, known isin, default range | `GET /stock/{isin}` | 200, instrument name as title, Trend overlay (both sources), Range picker on "Dag" | N/A |
| Unknown isin | `GET /stock/{bogus}` | 404 | N/A |
| No session | `GET /stock/{isin}` | Login form (200), same as any other protected route | N/A |
| `?range=vecka`, primary has ≥7 rows | `GET /stock/{isin}?range=vecka` | Chart redraws to the 7-day window | N/A |
| `?range=30d`, primary has <30 rows | `GET /stock/{isin}?range=30d` | "Inte tillräckligt med historik för det här intervallet ännu — kolla in igen om {n} dagar" instead of a partial chart | N/A |
| `?range=ar`, primary has ≥90 rows | `GET /stock/{isin}?range=ar` | Full available series rendered (uncapped, same ≥90-row gate as 90d) | N/A |
| `?source=nordnet` | `GET /stock/{isin}?source=nordnet` | Nordnet is primary (contextual color), Avanza secondary (fixed, dashed) | N/A |
| Watchlist star on header | `POST /watchlist/toggle` (same endpoint as Story 4.2) | Toggles exactly as on the leaderboard | N/A |

</frozen-after-approval>

## Code Map

- `public_html/index.php:56-318` — `switch ($path)` over literal cases only; no existing
  path-parameter route. Add `if (str_starts_with($path, '/stock/')) { ... }` immediately
  before the `switch`, slicing `substr($path, 7)` as the isin, dispatching to
  `StockDetailController`, falling through to the existing `switch`/404 only when the
  prefix doesn't match.
- `public_html/index.php:71` (`/` case) — `require_session($services['config'])` call
  pattern to reuse verbatim for the new route.
- `public_html/index.php:121` (`/watchlist/toggle`) — `InstrumentRepository::get($isin) ===
  null` → 404 is the exact precedent for this story's unknown-isin check.
- `src/Store/DerivedMetricsRepository.php:53-66` — `forIsin(string $isin): array` returns
  both sources' full ordered series keyed by source; already exactly what this story needs,
  no new repository method required. `SPIKE_THRESHOLD` (line 24) reusable as-is.
- `src/Store/InstrumentRepository.php:54-61` — `get(string $isin): ?Instrument`; `Instrument`
  (`src/Store/Instrument.php:12-23`) has public `name`/`list` for the page title.
- `src/Store/WatchlistRepository.php` — `starredIsins()`/`toggle()`, reused as-is; for one
  isin, a single `in_array($isin, $this->watchlist->starredIsins())` check is enough (no
  `array_flip` needed, unlike Story 4.2's 10-row leaderboard).
- `src/Web/LeaderboardController.php:93-163` — `hasStreak()`, `isSpiking()`,
  `streakBadgeHtml()`, `spikeBadgeHtml()` are `public static` pure functions, directly
  callable from the new `StockDetailController` — reuse, don't reimplement.
- `src/Web/LeaderboardController.php:212-262` (`sparklineHtml`) — private, not reusable, but
  its x/y polyline-scaling technique is the model for the new dual-line Trend overlay SVG.
- `src/Web/LeaderboardController.php:354-458` (`css()`) — no shared CSS file exists yet;
  this story's new page duplicates the relevant design tokens/class names (`--brand`,
  `--positive`, `--negative`, badge/star classes) rather than extracting a shared file —
  out of scope to refactor now.
- `tests/FrontControllerIntegrationTest.php` — `seedOwnerCount()`, `seedMatchedUniverse()`,
  `validCookie()` all directly reusable for a new `/stock/{isin}` end-to-end test.
- No new migration, no new repository — this story is purely `src/Web/` + routing.

## Tasks & Acceptance

**Execution:**
- [x] `public_html/index.php` -- add the `/stock/{isin}` path-parameter dispatch before the existing `switch`, reusing `require_session()` and the 404-on-unknown-isin pattern
- [x] `src/Web/StockDetailController.php` -- render the page: header (name, watchlist star, streak/spike badges via `LeaderboardController`'s helpers), Trend overlay (dual-line SVG), Range picker, Source switcher, insufficient-history states
- [x] `tests/Web/StockDetailControllerTest.php` -- range-gate/slice logic and empty-state selection in isolation from HTTP
- [x] `tests/FrontControllerIntegrationTest.php` -- end-to-end: default view, unknown isin (404), no session (login form), each range's gate (sufficient and insufficient), source switch changes which line is primary

**Acceptance Criteria:**
- Given a known isin and a valid session, when `/stock/{isin}` is requested with no query params, then the page renders the instrument's name, both sources' Trend overlay lines, and the Range picker on "Dag"
- Given `?range=30d` and fewer than 30 rows for the primary source, when the page renders, then the insufficient-history message appears instead of a partial or misleading chart
- Given `?source=nordnet`, when the page renders, then Nordnet's line uses the contextual (trend-based) color and Avanza's uses the fixed secondary dashed style, never the reverse
- Given an isin with no matching `instrument` row, when `/stock/{that-isin}` is requested, then the response is 404

## Implementation Notes

Implemented as specified: `/stock/{isin}` dispatches before the existing `switch` via
`str_starts_with($path, '/stock/')`; both sources' full series come from the pre-existing
`DerivedMetricsRepository::forIsin()`, no new SQL. Range gate/slice/color-key logic is
exposed as `public static` pure functions on `StockDetailController`, mirroring
`LeaderboardController`'s pattern. "Dag" and the Ar/90d shared floor are implemented exactly
per the frozen decisions.

**Judgment call worth flagging:** the Trend overlay scales each source's line independently
— both in value (own min/max, per NFR6) and in *x-position* (each line's points are spaced
across the full chart width by its own index/count, not aligned to matching calendar dates).
When both sources have the same row count (the common case — both begin collecting from the
same day an instrument enters the universe) this is visually identical to date-alignment.
But if the two sources' histories differ in length (e.g. one had a gap Story 2.x's
schema-mismatch handling logged), the two lines' horizontal positions would not correspond
to the same dates, which could read as a trend comparison when it isn't strictly one. The
spec's Design Notes only settled the *gating* question (secondary renders ungated with
whatever it has); it didn't specify x-axis alignment, so this was decided by the
implementation, not asked as an Open Question. Flagging for review rather than treating it
as settled.

Verified independently (not just from the implementation report): full diff read against
`baseline_commit`, all 4 execution tasks and all 4 spec-level ACs confirmed against the
diff, all 8 I/O Matrix rows traced to a specific passing test, `composer test` re-run
(368 tests / 1689 assertions, clean) independently.

**Review patch round:** 5 patch findings applied — the Trend overlay's two lines now
position by calendar date within a shared domain (`sharedDateDomain()` + `daysBetween()`)
instead of by array index, fixing a real misalignment when sources have different coverage
(`scaledPoints()` made `public static` and unit-tested); the legend now says "(ingen data
ännu)" instead of promising a swatch for a line that wasn't drawn; `90d`, a brand-new
instrument's default `Dag` view, and a non-GET request to `/stock/{isin}` all gained
end-to-end coverage. One placement issue caught during my own verification (not the
subagent's): the new 405 test initially landed in the DB-gated
`FrontControllerIntegrationTest.php` with an unnecessary `seedMatchedUniverse()` call, even
though the method check runs before any session/DB access — moved to the DB-free
`FrontControllerTest.php` to match every other 405 test's precedent and ensure it always
runs regardless of docker-compose availability. 2 findings rejected as out of scope (CSS
duplication and accessibility — both already explicitly excluded, by this spec's own Code
Map and `EXPERIENCE.md`'s UX-DR18 respectively). 2 findings verified false (isin
URL-encoding asymmetry — unreachable given real ISINs are always plain alphanumeric;
viewing/starring a delisted instrument — confirmed intentional, matching Story 4.2's own
established precedent for AD-15/NFR7). Independently re-verified after patching: full
`composer test` (373 tests, 1703 assertions, clean); `sharedDateDomain()`/`scaledPoints()`/
`daysBetween()` and the legend's no-data branch read directly in the patched source.

## Spec Change Log

## Review Triage Log

- **Trend overlay's two lines are positioned by array index, not calendar date — a real, demonstrated misalignment when sources have different coverage** — `high`, routes `patch`. Verified: `scaledPoints()` maps row `$i` to `x = $i/($count-1)*$width` for each series independently, ignoring each row's own `as_of_date`. This system's own architecture documents a concrete reason coverage can differ between sources for the same isin (Nordnet's instrument id is resolved separately from Avanza's, AD-3 — a real, not hypothetical, source of a lagged start date). When that happens, the two "same-width" lines represent different calendar spans stretched to the same pixel width — undermining the entire feature's purpose (comparing shapes across sources to judge whether a trend is real). The Design Notes settled the *count*-gating question but never addressed x-axis alignment; the fix is bounded to `scaledPoints()`/`trendOverlayHtml()` (compute a shared date-domain from the union of both slices, position each point by date fraction rather than index) and should also make the method `public static` and unit-tested, matching every other pure rule on this controller. (Corroborated independently by blind-hunter [alignment defect] and verification-gap [same method flagged as the one pure-logic exception left untested].)
- **Legend still names the secondary source when its line isn't drawn at all** — `low`, routes `patch`. Verified: `scaledPoints()` returns `''` (no polyline) when a slice has fewer than 2 rows, but `legendHtml()` unconditionally renders both legend entries regardless — a source with 0–1 rows gets a swatch and label promising a line that isn't there. Fix: omit (or annotate) the secondary legend entry when its slice has fewer than 2 points. (edge-case-hunter.)
- **No range has both its sufficient and insufficient sides covered end-to-end, and `90d` has no integration test at all** — `low`, routes `patch`. Verified: `grep` confirms no `range=90d` integration test exists (only unit-level `gateThreshold`/`windowSize` cover it); across all five ranges, only `30d` gets its insufficient-history case exercised end-to-end. The underlying logic is unit-tested for every range, so this is redundant-but-cheap integration coverage, not a live gap — worth closing given it mirrors the codebase's own established pattern of pairing unit and end-to-end coverage. Fix: add one `range=90d` sufficient-history integration test. (Corroborated by blind-hunter and edge-case-hunter.)
- **No end-to-end test for a brand-new instrument (<2 primary rows) on the default `Dag` view** — `low`, routes `patch`. Verified: the pure gate logic is unit-tested (`testIsInsufficientHistoryIsTrueBelowTheGate`), and the *wiring* is proven end-to-end for `30d`'s insufficiency case — but not for `Dag`'s own (much more common) "just-onboarded instrument" trigger. Fix: add one such test. (blind-hunter.)
- **Isin taken from the URL path is never `rawurldecode()`'d, asymmetric with `url()`'s `rawurlencode()`** — `false`. Verified: a real ISIN (ISO 6166: 2-letter country code + 9 alphanumeric + 1 check digit) contains no characters `rawurlencode()` would ever change, and every isin reaching this route already passed through Story 1.3's `SourceAdapter`/id-resolution pipeline, which only ever produces well-formed ISINs. The described round-trip failure cannot occur given this system's actual data domain. (blind-hunter.)
- **`InstrumentRepository::get()` doesn't filter `last_seen IS NULL`, so a delisted instrument is fully viewable/starrable at `/stock/{isin}`** — `false`. Verified as intentional, not an oversight: Story 4.2's own review explicitly added a test confirming a delisted instrument *can* still be starred (AD-15/NFR7 — delisting is a status change, never a deletion, and watchlist entries must survive it), and the leaderboard excludes delisted instruments only from active *ranking*, never from being viewable. Keeping the detail page reachable for a delisted, previously-starred instrument is the correct, already-established behavior. (blind-hunter.)
- **`StockDetailController::css()` duplicates ~90 lines of `LeaderboardController::css()` verbatim, including an unused `--border` custom property** — rejected, out of scope. The spec's own Code Map already settled this: "no shared CSS file exists yet; this story's new page duplicates the relevant design tokens/class names... out of scope to refactor now." Real, but a pre-accepted exclusion, not a defect introduced without a decision. (blind-hunter.)
- **`/stock/{isin}` has no dedicated 405 test for a non-GET request** — `low`, routes `patch`. Verified (pre-verified by the filing layer): every other method-guarded route in this codebase (`/`, `/login`, `/watchlist/toggle`, `/cron/derive`) has its own dedicated 405 test; `/stock/{isin}` is the one exception. Fix: mirror `testWatchlistToggleWithWrongMethodReturns405`. (verification-gap.)
- **`.range-picker`/`.source-switcher` lack `role="tab"`/`aria-selected`, and the Trend `<svg>` has no textual equivalent of its data** — rejected, out of scope. Same explicit exclusion as Stories 4.1/4.2: `EXPERIENCE.md`'s accessibility floor states "ingen dedikerad skärmläsargenomgång... (uttryckligen utanför scope)." (blind-hunter.)

## Design Notes

`?range=` values map to row-count gates and display windows: `dag` → last 2 rows, gate
`< 2`; `vecka` → last 7 rows, gate `< 7`; `30d` → last 30 rows, gate `< 30`; `90d` → last 90
rows, gate `< 90`; `ar` → all available rows, same gate `< 90` as `90d` (a sub-90-row "year"
view would look exactly as thin as a sub-90-row "90d" view, so they share one floor). All
slicing is `array_slice($series, -$n)` on the already-fetched full array — never a second
query.

Per-source coverage can differ (Avanza and Nordnet aren't guaranteed the same row count for
the same isin, NFR6) — the insufficiency gate is evaluated only against the *primary*
(Source-switcher-selected) source's count; the secondary line simply renders however many
points it has, even if fewer than the primary, rather than gating the whole chart on the
weaker of the two.

## Verification

**Commands:**
- `composer test` -- expected: all PHPUnit tests pass, including new
  `StockDetailControllerTest` and the new `FrontControllerIntegrationTest` cases, no skips
  beyond the existing DB-guarded ones

**Manual checks (if no CLI):**
- Run `php -S localhost:8080 -t public_html` against a seeded local database; log in, open
  a leaderboard row's link, confirm the detail page loads with both sources' lines, switch
  ranges and source, and confirm an isin with under 30 stored days shows the insufficiency
  message on `?range=30d`.
