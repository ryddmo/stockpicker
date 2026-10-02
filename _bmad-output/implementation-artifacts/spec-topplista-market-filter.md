---
title: 'Topplista market filter + identical three-row header'
type: 'feature'
created: '2026-10-02'
status: 'done'
baseline_commit: '2404be29e05008b87276ccbc1b83ddda0d204420'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-stockpicker-2026-09-12/EXPERIENCE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Topplista can't be narrowed to a market list (Large/Mid/Small Cap, First North). Fullständig lista can. The header also looks different per mode: the period row is missing in Flest ägare.

**Approach:** Decisions were made with Stefan on 2026-10-02 and are recorded in the UX spines.
- **Header layout, identical in all three modes:**
  - Row 1: source switcher + ranking toggle (unchanged).
  - Row 2: the Periodväljare. "Dölj spikar" sits to its right in Plusdagar only.
  - Row 3: a new **market filter "Alla | LC | MC | SC | First North"**.
- **Market filter:**
  - Same values as Fullständig lista's `?market=`. Default **Alla**.
  - The top 10 is computed **within** the chosen market.
  - Rows 2 and 3 use the same segmented control (`.range-picker` look).
- **Flest ägare:**
  - Row 2 is shown greyed out and is not clickable, with the muted note **"Gäller inte Flest ägare"**.
  - The remembered period stays marked (muted).
  - Flest ägare still ranks by today's total owners.
- **Remembering while navigating only:**
  - `period` and `market` are carried in every mode, source, period and market link. Previously Flest ägare dropped the period; now it carries it.
  - "Visa fullständig lista" carries `market`.
  - A fresh visit starts at Månad + Alla. Nothing is stored (AD-14): no cookie, no `settings`.

## Boundaries & Constraints

**Always:**
- **Market values and SQL:** only `LC`, `MC`, `SC`, `First North` are accepted, exactly as `FullListController::MARKETS`. Anything else means Alla. The filter is `i.list = :market` with a bound parameter.
- **Placement:** market filtering lives in the `src/Store/` repository methods (AD-14), never as post-filtering in the controller.
- **Defaults in URLs:** default values are omitted (`period=manad`, Alla).
- **Spike toggle:** `spikes` is still carried in Plusdagar only.
- **Alla source mode:** still ranks on Avanza, Nordnet stays display-only, and the sources are never summed (NFR6).
- **Mode behaviour:** the existing gates, empty states, chips and sorting per mode are unchanged apart from the market narrowing.
- **No JS, Swedish copy.**

**Never:**
- Don't change the e-mail digest (`top*AsOf`), Fullständig lista's own filters, Bevakningslista or Aktiedetalj.
- No migration.
- Don't add a market param to `historySpansDays()`. History gating stays per source.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected |
|---|---|---|
| Market narrows top 10 | `?market=SC`, > 10 SC qualifiers | 10 rows, all `list = SC`, the best SC by the mode's order |
| Each mode | count / steady / plus with `market=MC` | Only MC rows, each in its mode's order |
| Default | No `market` | Alla segment active; same rows as before this change |
| Garbage | `?market=XX` / array | Alla, never 500 |
| Empty within market | Market has no qualifiers | The mode's existing empty-state copy |
| Mode switch | On `?ranking=plus&period=vecka&market=LC`, click Flest ägare | `?period=vecka&market=LC` (ranking default omitted), and Flest ägare ranks LC by total owners |
| Back to period mode | From that page, click Stadig tillväxt | `?ranking=steady&period=vecka&market=LC` |
| Flest ägare period row | `/` | Row 2 present, segments not links, Månad marked muted, note "Gäller inte Flest ägare" |
| Source switch | Any mode with market | `market` kept (`period` too) |
| Full list link | `market=First North` | "Visa fullständig lista" → `/list?market=First+North` (plus the existing source rule) |

</frozen-after-approval>

## Code Map

- `src/Store/DerivedMetricsRepository.php`:
  - Add an optional trailing `?string $market = null` to `topByOwnerCount` (:88), `topByTrendQualityForPeriod` (:140) and `topByPlusDays` (:222).
  - When non-null, add `AND i.list = :market` next to the existing `i.last_seen IS NULL`. `searchAndFilter` (:406–433) shows the same condition already.
  - The `*AsOf` methods and `historySpansDays` must not change.
- `src/Web/LeaderboardController.php`:
  - `render()` (:89) gains a `string $market = ''` parameter. Normalize it with the same whitelist; reusing `FullListController::normalizeMarket()`/`MARKETS` is fine, and moving them into a shared place is fine too.
  - Pass `$market` to the three repository calls.
  - `url()` (:777): carry `period` for **all** modes, which changes the current `hasPeriod` rule for links, and carry `market` for all modes. Keep `spikes` plus-only.
  - `sourceSwitcherHtml` / `rankingToggleHtml` / `periodRowHtml` take and carry `$market`.
  - `periodRowHtml()` (:742) always renders. In count mode the segments are `<span>`s instead of links, inside a muted modifier class, followed by `<span class="period-note">Gäller inte Flest ägare</span>`.
  - Add a `marketRowHtml()` built like `periodRowHtml()`: `.range-picker` segments, with the label "Alla" first.
  - `pageHtml()` renders row 2 and row 3 for every mode.
  - `fullListUrl()` (:815) carries `market`.
- `public_html/index.php:93-101` -- read `$_GET['market']` string-guarded, as for `period`, and pass it to `render()`.
- `public_html/assets/app.css` -- the `.period-row` rules; add the market row (same layout, its own line) and the muted/disabled period state. Remove dead rules if any.
- Tests:
  - `tests/Store/DerivedMetricsRepositoryTest.php`: market narrowing per method, with the null market unchanged.
  - `tests/FrontControllerIntegrationTest.php`: every matrix row. Existing assertions that expect Flest ägare links without `period`, or no period row in count mode, must be updated to the new contract.
  - `tests/Web/LeaderboardControllerTest.php` as needed.

## Tasks & Acceptance

**Execution:**
- [x] `src/Store/DerivedMetricsRepository.php` -- optional market narrowing on the three Topplista queries + store tests.
- [x] `src/Web/LeaderboardController.php` + `public_html/index.php` -- market param, the three-row header, the greyed period row in count mode, links carrying period+market.
- [x] `public_html/assets/app.css` -- market row, greyed period state.
- [x] Integration/unit tests -- one per matrix row; update the existing ones.

**Acceptance Criteria:**
- Given any of the three modes, when Topplista renders, then the header shows the same three rows in the same order and position.
- Given a 390px viewport, then all three rows fit without horizontal scroll (segments may wrap within their row).
- Given the digest, Fullständig lista and Bevakningslista, then their output is unchanged (existing tests stay green unmodified).

## Implementation Notes

- `FullListController::MARKETS` became `public` and is reused, along with `normalizeMarket()`, by `LeaderboardController`. No logic was duplicated.
- The repository uses the private helpers `marketCondition()`/`bindMarket()`. `topByOwnerCount`'s SQL went from nowdoc to heredoc so it can interpolate the (constant) condition. The value is always bound.
- `hasPeriod()` was removed because it had no callers left.
- `.period-note` is `flex: 1 1 0; min-width: 0`. Without that, at 390px the note wraps onto its own line, which pushes row 3 lower in Flest ägare than in the other modes. With it, the note breaks inside its own box next to the pill. This was checked in headless Chrome at 390px (iframe) and 1100px.
- `tests/Web/LeaderboardControllerTest.php` needed no change. The header helpers are private and are covered via `FrontControllerIntegrationTest`.

## Spec Change Log

## Review Triage Log

## Verification

**Commands:**
- `docker compose up -d && composer test` -- expected: green, with **no skipped** Store/integration tests.

**Manual checks:**
- Topplista in all three modes at 390px and desktop: identical header, greyed period in Flest ägare, market narrowing visible.

Pass 1 (2026-10-02):

| # | Source | Finding | Verdict | Evidence / route |
|---|---|---|---|---|
| 1 | verification-gap+blind | Period-row links (period modes) never asserted to carry `market`; market-row links in steady/plus never asserted to keep `ranking`/`spikes` | medium | Pre-verified: dropping `$market` in `periodRowHtml()` or the mode/spike args in `marketRowHtml()` keeps the suite green → patch (test) |
| 2 | blind | "Each mode in its own order" tests use fixtures where every mode gives the same order | medium | Confirmed: same expected list for all three modes; the matrix row is not proven → patch (test fixture) |
| 3 | blind | Info page still says the period row is only in Stadig tillväxt/Plusdagar and never mentions the Topplista market filter | medium | User-facing contradiction on the informationssida → patch |
| 4 | edge | At 390px in Plusdagar, "Dölj spikar" may wrap below the pill, pushing the market row lower than in other modes | low | Pill (~265px) + gap + toggle (~110px) exceeds 358px; the AC asks for the same position. Direct CSS (shrink in place, like `.period-note`) → patch, verify at 390px |
| 5 | blind | Stale docblocks (render(), class docblock "both" params) | low | Direct → patch |
| 6 | blind | Alla source + market combination untested | low | One assertion → folded into #1's test patch |
| 7 | blind | DESIGN.md says the muted active segment has "no shadow", but the CSS draws an inset outline | low | Direct doc correction → patch (orchestrator) |
| 8 | blind | Topplista tab link from Fullständig lista/Aktiedetalj resets period+market | low | Pre-existing: the tab bar never carried ranking/period. Outside the links the intent lists → defer |
| 9 | blind | `spikes` dropped on a round trip through Flest ägare | false | The intent states "spikes is still carried in Plusdagar only"; the behaviour matches |
| 10 | blind | Empty state doesn't mention the market | low | Rare (Flest ägare is never empty); new copy would be needed → reject |
| 11 | blind | Market filter rendered differently on Fullständig lista vs Topplista; segment loop duplicated | low | Developer-only, no named breakage → reject |
| 12 | blind | Inert period row ARIA (tablist of spans, aria-current on span) | low | Same light treatment as before; app-wide pattern → reject |
| 13 | blind | Leaderboard depends on `FullListController::MARKETS` | false | The intent requires the same values as Fullständig lista; a shared source is the point |
| 14 | verification-gap | New tests self-skip without MariaDB | false | Full run: 652 tests, 0 skipped, with docker MariaDB up |
