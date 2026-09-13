---
title: 'Informationssida'
type: 'feature'
created: '2026-09-13'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
context: []
baseline_commit: 'fbfc28c6f772c5e231fed44e02b438dd7c271670'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Stefan has to remember what each badge/symbol means and what qualifies an
instrument for "Stadig tillväxt" ranking — there's no in-app explanation, only what's
in his head or in the planning docs.

**Approach:** Add a new authenticated `/info` page (reachable via a visible footer link
from Topplista, not just direct URL) that explains, in plain non-technical Swedish: what
each page shows (Topplista, Fullständig lista, Bevakningslista, Aktiedetalj), what each
symbol means (🔥 Streak, ⚡ Spike, ☆/★ Watchlist star, Delta chip), and exactly what
qualifies an instrument for "Stadig tillväxt" and how it's ordered — copied faithfully
from `DerivedMetricsRepository::topByTrendQuality()`'s real rule (`up_streak >= 1` AND
not spiking i.e. `spike_score < 2` or null, ranked by longest streak first), not a
guess. Pure static content, no database query — a new `InfoController::render(): string`
with zero constructor dependencies (no repository, no PDO), wired as a new
authenticated `GET /info` route in `public_html/index.php` alongside the existing
routes. Reuses the persistent tab-bar pattern (Topplista/Bevakningslista, "topplista"
marked active, same as Aktiedetalj/Fullständig lista precedent) and the shared design
tokens/CSS conventions from the other `src/Web/` controllers — this is a content page,
not a new visual language.

## Boundaries & Constraints

**Always:** Content must be accurate to the real code behavior (the Stadig
tillväxt rule especially) — verify against `DerivedMetricsRepository` rather than
restating the epic's prose from memory. Swedish throughout, plain/skeptical tone,
never hype or gamified copy (UX-DR19). The page requires a valid session like every
other page except `/login`. The footer link on Topplista must be visible, not hidden
behind another page.

**Never:** No new database table, repository, or query — content is static PHP
markup only. No client framework, no new JS. Do not touch `src/Pipeline/`,
`src/Store/`, or any Epic 1-4 controller's data logic — `LeaderboardController` gets
only the one new footer-link line, nothing else in it changes.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|---|---|---|---|
| No session | `GET /info` without a valid cookie | Redirected to the login form, same as any other authenticated route | N/A |
| Valid session | `GET /info` | 200, page explains all 4 pages, all 4 symbols, and the exact Stadig tillväxt rule | N/A |
| Topplista footer | Topplista renders | A visible link to `/info` is present, distinct from the existing "Visa fullständig lista" link | N/A |

</frozen-after-approval>

## Code Map

- `src/Store/DerivedMetricsRepository.php:118-131` (`topByTrendQuality()` docblock) —
  the ground truth for the Stadig tillväxt explanation: qualify requires
  `up_streak >= 1` AND not spiking (`spike_score < SPIKE_THRESHOLD` or null,
  `SPIKE_THRESHOLD = 2.0`), ordered by `up_streak DESC`. Write the info page's
  explanation from this, not from `epics.md`'s prose.
- `src/Web/WatchlistController.php:249-263` (`tabBarHtml()`) — the exact persistent
  tab-bar markup/CSS to replicate byte-for-byte (established duplication convention,
  Stories 4.3-4.5). `/info` has no incoming `$source` query param to preserve, so its
  copy can hardcode plain `/` and `/watchlist` hrefs (no suffix logic needed) — a
  small, deliberate simplification versus the other three copies.
- `src/Web/StockDetailController.php:32-45,376-425` — the closest existing precedent
  for a page reached only via another page's action, not a tab itself (Aktiedetalj
  marks 'topplista' active); `/info` follows the same pattern.
- `src/Web/LeaderboardController.php:339` (`.full-list-link` paragraph in `pageHtml()`)
  and its CSS at `:554-555` — insert one new footer link paragraph near this one, new
  CSS class (do not reuse `.full-list-link` verbatim; give it its own class so the two
  links are independently stylable).
- `public_html/index.php:59-65` (routing switch) — add a `case '/info':` entry;
  no `route_stock_detail()`-style helper function is needed since there's no path
  parameter, so wire it as a plain `case` like `/list`/`/watchlist`, minus the PDO
  connection (this controller needs none).
- No existing test file for a fully static, zero-dependency `src/Web/` controller —
  `InfoControllerTest.php` is new; DB-free (`InfoController::render()` needs no PDO),
  so it belongs in a plain `TestCase`, not `StoreTestCase`.

## Tasks & Acceptance

**Execution:**
- [x] `src/Web/InfoController.php` -- new file: `public static function render(): string` returning the full `/info` page (tab bar, page descriptions, symbol legend, Stadig tillväxt rule) plus its own `css()` -- pure content, no dependencies
- [x] `src/Web/LeaderboardController.php` -- add one new footer link (own CSS class) pointing to `/info`, near the existing `.full-list-link` paragraph -- makes the page reachable per the AC ("en synlig länk", not only direct URL)
- [x] `public_html/index.php` -- add `case '/info':` (GET, `require_session()`, then `render_html(200, InfoController::render())`, no PDO/`Database::connect()` needed) -- wires the new route
- [x] `tests/Web/InfoControllerTest.php` -- new file: assert the rendered HTML contains descriptions of all 4 pages, all 4 symbol explanations, and the literal Stadig tillväxt qualifying rule text
- [x] `tests/Web/LeaderboardControllerTest.php` or `tests/FrontControllerIntegrationTest.php` -- assert the new footer link is present on `/`
- [x] `tests/FrontControllerIntegrationTest.php` -- add `/info` route tests: no session → login form; valid session → 200 with expected content

**Acceptance Criteria:**
- Given a valid session, when I navigate to `/info`, then Topplista/Fullständig lista/Bevakningslista/Aktiedetalj are each described in plain text
- Given the info page, when I read it, then each symbol (🔥 Streak, ⚡ Spike, ☆/★ Watchlist star, Delta chip) is explained
- Given the Stadig tillväxt ranking mode, when I read the info page, then the qualifying rule and ordering are explained in plain (non-technical) language, matching `topByTrendQuality()`'s real behavior exactly
- Given the info page, when it renders, then it is reachable from Topplista via a visible link, not only a direct URL
- Given no session, when `/info` is requested, then the login form is shown, same as any other authenticated route

## Implementation Notes

New `InfoController::render(): string` — static, zero-dependency (no PDO/repository),
matching the Code Map's design. Tab bar hardcodes `/` and `/watchlist` (no `$source`
param to preserve, unlike the other three controllers' copies). Stadig tillväxt section
states the literal sentence from Design Notes, cross-checked against
`DerivedMetricsRepository::topByTrendQuality()`'s real predicate (`up_streak >= 1` AND
`spike_score < SPIKE_THRESHOLD` or null, `ORDER BY up_streak DESC`) — not restated from
`epics.md`'s prose. `LeaderboardController` gained one `.info-link` footer paragraph
(own CSS class, distinct from `.full-list-link`) and nothing else. `public_html/index.php`
gained one `case '/info':` — GET-only, `require_session()`, no `Database::connect()` call
since the controller needs no DB.

**Orchestrator verification:** independently re-verified — full diff read against
`baseline_commit` (including untracked new files via `git add -N`), all 6 execution
tasks and all 5 spec-level ACs confirmed directly against the diff content (not the
subagent's self-report). All 3 I/O & Edge-Case Matrix rows are covered by a passing
test. Independently re-ran `composer test`: 448 tests, 2068 assertions, clean — matches
the subagent's reported numbers exactly. Checked off all Tasks (subagent had left them
unchecked) and wrote this Implementation Notes section (subagent left it empty despite
the dispatch instruction to append notes as it worked — a process gap, not a code
defect; noted here rather than silently fixed, since the workflow's dispatch prompt is
fixed and cannot be amended per-story).

## Review Triage Log

- **[low, patch]** Aktiedetalj's description omitted Story 5.2's newly-shipped "Visa på Avanza ↗" link — the most recently-added part of that page went unmentioned. Verified by reading `StockDetailController.php:483`. Fixed: added one clause to the Aktiedetalj section.
- **[medium, patch]** `InfoController::tabBarHtml()` hardcoded plain `/` and `/watchlist` hrefs, silently dropping `?source=nordnet` — a Nordnet-source user who opens `/info` from the footer link and then taps either tab is bounced back to the Avanza-sourced view with no indication their selection was lost. Verified: real, reachable path for the app's actual (sole) user, who actively uses the source switcher. Fixed: threaded `$source` through `LeaderboardController`'s new `infoUrl()`, the `/info` route in `public_html/index.php`, and `InfoController::render()`/`tabBarHtml()`, mirroring the exact pattern already used by every other controller's tab bar.
- **[low, patch]** `testRootHasAVisibleFooterLinkToInfoDistinctFromTheFullListLink` asserted `class="info-link"` and `href="/info"` as two separate substring checks, so it would still pass even if they ended up on unrelated elements. Fixed: combined into one substring (`class="info-link"><a href="/info"`) that ties them to the same element.
- **[defer]** No test sends a non-GET request to confirm `/info`'s 405 guard actually fires. Verified real but not unique to this diff — no route in `public_html/index.php`, old or new, has 405 coverage (confirmed by grep). Fixing only `/info` would be inconsistent with the existing, untouched convention across every other route; a proper fix is cross-cutting and out of this story's scope. Deferred to `deferred-work.md`.
- **[out of scope]** `/info` is reachable only from Topplista, not from Bevakningslista/Fullständig lista/Aktiedetalj. Rejected: the frozen Intent and AC explicitly and narrowly scope reachability to "en synlig länk [från] Topplistan," and the Boundaries restrict the change to "LeaderboardController gets only the one new footer-link line" — the intent itself settles this, not just the spec's scope section.
- **[out of scope]** The Symbols/rules sections explain "Stadig tillväxt" but not "Flest ägare" (the other ranking mode) with equivalent rigor. Rejected: no AC names "Flest ägare" for explanation, and the Design Notes explicitly state "No need to explain ... anything not explicitly named in the AC" — the intent deliberately excludes it.
- **[false]** The diff doesn't update `sprint-status.yaml`/commit the new spec file. Refuted: this review round runs at step-04, before step-05's commit — matches the exact same sequencing already used by Stories 5.1 and 5.2, not a gap introduced by this diff.

## Spec Change Log

## Review Triage Log

## Design Notes

Keep the Stadig tillväxt explanation precise and testable: state it as "kvalificerar
om aktien har minst 1 dags obruten uppgångssvit och inte just nu spikar; sorteras med
längst svit först" (or equivalent plain phrasing) so the unit test can assert on a
literal substring tied to the real rule, not a vague paraphrase that could silently
drift from the code. Page sections in this order: short intro, one subsection per page
(Topplista/Fullständig lista/Bevakningslista/Aktiedetalj), symbol legend, Stadig
tillväxt explanation. No need to explain the Source switcher, Delta chip's exact
percentage math, or anything not explicitly named in the AC.

## Verification

**Commands:**
- `composer test` -- expected: all PHPUnit tests pass, including the new
  `InfoControllerTest` and the new `/info` integration tests

**Manual checks (if no CLI):**
- Log in and visit `/info` in a browser; confirm the footer link on Topplista is
  visible and the page reads clearly at 390px and 900px.
