---
title: 'Period choice for Stadig tillväxt + period row'
type: 'feature'
created: '2026-10-02'
status: 'done'
baseline_commit: '1c7c50a3c0df163b728dbdd3012bcdd86a22a982'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-stockpicker-2026-09-12/EXPERIENCE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Topplista's "Stadig tillväxt" has no period choice. It always sorts by the length of the ongoing streak, whereas Plusdagar can be viewed per Vecka / Månad / 3 mån / År. The Plusdagar period line also floats loosely under the ranking toggle as dotted text links.

**Approach:** The decisions below were made with Stefan in the 2026-10-02 UX update and are recorded in the UX spines.
- **Stadig tillväxt qualifier:** unchanged (ongoing `up_streak ≥ 1`, not spiking).
- **Stadig tillväxt sort:** by the chosen period's % growth (`pct_7d` / `pct_30d` / `pct_90d` / `pct_365d`) instead of streak length. The default period is **Månad**, and the pure streak-length sort disappears from Topplista.
- **Shared period:** Stadig tillväxt and Plusdagar share one period. Switching between them keeps `?period`. Flest ägare has no period.
- **Period row:** the period moves to its **own full-width row** under all header controls, left-aligned with the source switcher. It is a compact segmented pill **Vecka | Månad | 3 mån | År** that looks like Aktiedetalj's Range picker. In Plusdagar, "☐ Dölj spikar" sits to its right. Stadig tillväxt has no spike toggle, because it always excludes spikes.
- **Tab labels:** ranking tabs are plain names again ("Flest ägare | Stadig tillväxt | Plusdagar"). The period no longer appears in a tab label.
- **Short history:** Stadig tillväxt with too little history for the chosen period shows "För lite historik för {period} ännu.", the same copy as Plusdagar. Accepted consequence: the default Stadig tillväxt · Månad view is empty until about 2026-10-09.

## Boundaries & Constraints

**Always:**
- Steady ranking SQL is a `DerivedMetricsRepository` method (AD-14). The period → column mapping is a whitelist, never interpolated from input.
- Alla mode ranks on Avanza (spec-5-4), and sources are never summed (NFR6).
- Plain links, no JS (AD-12). Default values are omitted from URLs.
- Copy is in Swedish (NFR12).
- The garbage-param fallback is unchanged: never a 500.
- Plusdagar behaviour is otherwise unchanged. Its 3 mån/År history gate, its ungated Vecka/Månad, its chip and its spike toggle all stay as they are.

**Never:**
- Don't change the e-mail digest (`topByTrendQualityAsOf`) or Fullständig lista's Stadig tillväxt filter. Both keep the streak rule.
- No migration and no view change.
- No new client-side JS.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected |
|---|---|---|
| Default steady | `/?ranking=steady` | Period Månad; qualifying rows ordered by `pct_30d` DESC |
| Steady Vecka | `?ranking=steady&period=vecka` | Ordered by `pct_7d` DESC; tie → `up_streak` DESC, then isin |
| Qualifier unchanged | Row with streak 0, or spiking | Never listed, whatever its % |
| Instrument lacks the period % | Qualifies, `pct_N` NULL (e.g. new listing) | Listed after all rows with a %, ordered by streak |
| Short source history | Source history spans < N days | "För lite historik för {period} ännu." |
| Zero qualifiers, enough history | No streaking rows | "Inga aktier med stadig tillväxt just nu." |
| Switch steady → plus | On `?ranking=steady&period=vecka`, click Plusdagar | `?ranking=plus&period=vecka` |
| Switch to Flest ägare | From either period mode | Period dropped from URL; no period row shown |
| Spike toggle scope | Steady mode | No "Dölj spikar"; `?spikes=exclude` is ignored and not carried |
| Garbage | `?ranking=steady&period=xyz` | Månad |

</frozen-after-approval>

## Code Map

- `src/Store/DerivedMetricsRepository.php:136` `topByTrendQuality($source, $limit)`:
  - Add a period-aware variant, e.g. `topByTrendQualityForPeriod($source, $days, $limit)`. It keeps the exact WHERE clause (latest row, `last_seen IS NULL`, `up_streak >= 1`, spike < threshold) and orders by `latest.pct_N IS NULL, latest.pct_N DESC, latest.up_streak DESC, latest.isin`.
  - Leave `topByTrendQuality` itself in place unless nothing calls it any more. `topByTrendQualityAsOf` (digest) and `searchAndFilter` (Fullständig lista) must not change.
- `historySpansDays()` (same file) -- reuse it for the steady short-history gate on **all four** periods. Plusdagar keeps `PERIODS_REQUIRING_FULL_HISTORY` (3 mån/År only).
- `src/Web/LeaderboardController.php`:
  - In `render()`, normalize `period` for steady as well as plus. Steady uses `PERIODS[$period]['days']`, the new repository method and the short-history gate. Pass `$insufficientHistory` to `emptyStateCopy()` for steady too (same copy and the same `phrase`).
  - `rankingToggleHtml()` -- plain labels. Steady and plus hrefs both carry `$period`; only plus carries spikes.
  - `url()` -- emit `period` for steady too (omitted when Månad); `spikes` stays plus-only.
  - `sourceSwitcherHtml()` -- carries period for both period modes.
  - Replace `plusOptionsHtml()` with a period-row builder: `<div class="range-picker" role="tablist" aria-label="Period">` holding `.tab` / `.tab--active` links, reusing the StockDetailController:744 markup pattern, plus the spike link in plus mode only.
  - `pageHtml()` -- render the row as a new full-width row after `.controls`, inside the header, only in steady/plus. Drop the `.ranking-group` wrapper if it has no other use.
- `public_html/assets/app.css` -- `.range-picker` (around line 178) already gives the pill look; add only the period-row layout (full width, left-aligned, spike link to the right, wraps at 390px). Remove the now-unused `.period-links`/`.period-link`/`.period-sep` and `.ranking-group` rules, plus the `.controls > .source-switcher { align-self }` tweak if it becomes unneeded. Tap targets match the other segmented controls.
- `src/Web/InfoController.php` -- update the Stadig tillväxt paragraph (sort by growth over the chosen period, default Månad; spikes always excluded) and mention the shared period row.
  - `tests/Web/InfoControllerTest.php:45` asserts the exact old sentence "sorteras med längst svit först". Update the test together with the copy.
- Tests:
  - `tests/Store/DerivedMetricsRepositoryTest.php` (`topByTrendQuality` section ~line 638): ordering per period, NULL % last, the unchanged qualifier.
  - `tests/Web/LeaderboardControllerTest.php`, `tests/FrontControllerIntegrationTest.php`: existing steady/plus assertions on tab labels ("Plusdagar · Månad") and period-link markup must be updated to the new markup.

## Tasks & Acceptance

**Execution:**
- [x] `src/Store/DerivedMetricsRepository.php` -- add the period-aware steady query -- the steady sort becomes period growth.
- [x] `src/Web/LeaderboardController.php` -- shared period for steady and plus, plain tab labels, the period row, the steady short-history gate.
- [x] `public_html/assets/app.css` -- period-row layout; delete the dead Plusdagar-line rules.
- [x] `src/Web/InfoController.php` + `tests/Web/InfoControllerTest.php` -- Stadig tillväxt copy.
- [x] Tests -- one test per matrix row (store for ordering/NULL/qualifier, integration for URLs/row visibility/empty states); update the existing assertions.

**Acceptance Criteria:**
- Given `/?ranking=steady`, when rendered with ≥ 30 days of history, then the Månad segment is active in a period row below the header controls and the rows are ordered by `pct_30d`.
- Given any Topplista mode, then the ranking tabs read exactly "Flest ägare", "Stadig tillväxt", "Plusdagar".
- Given a 390px viewport, when steady or plus renders, then the source switcher, ranking toggle and period row fit without horizontal scroll.
- Given the digest and Fullständig lista, then their Stadig tillväxt output is unchanged (existing tests stay green unmodified).

## Implementation Notes

## Spec Change Log

## Review Triage Log

## Verification

**Commands:**
- `docker compose up -d && composer test` -- expected: green, with **no skipped** Store/integration tests.

**Manual checks:**
- Topplista steady and plus at 390px and desktop: the period row sits on its own line, left-aligned, with no stray dots.

Pass 1 (2026-10-02):

| # | Source | Finding | Verdict | Evidence / route |
|---|---|---|---|---|
| 1 | blind+edge+verification-gap | Info page's second Stadig tillväxt paragraph still says "sorteras med längst uppgångssvit högst upp"; the negative assertion misses it | medium | Confirmed at InfoController.php:111; pre-verified gap → patch (copy + assertion) |
| 2 | verification-gap | 90→`pct_90d` / 365→`pct_365d` mapping never exercised | medium | Pre-verified: swapping the mapping keeps the suite green → patch (store test) |
| 3 | blind+edge | `topByTrendQuality()` has no production caller, but the Code Map says to remove it in that case | low | grep of src/bin/public_html finds only tests; a second copy of the qualifier can drift → patch (delete + move its tests) |
| 4 | blind | "Own row" test finds the toggle's `</div>`, not the end of `.controls` | low | Confirmed by reading the assertion; direct test fix → patch |
| 5 | verification-gap | Stale test name `…OrdersByUpStreak…` and InfoControllerTest docblock naming `topByTrendQuality()` | low | Direct rename → patch |
| 6 | blind | Digest/Fullständig lista keep streak order under the same label; not logged in backlog | low | AGENTS.md asks for backlog entries; direct addition → patch |
| 7 | blind+edge | Short-history gate is the source's span, while EXPERIENCE.md says "when the period % is missing" | low | Differs only after a >5-day gap before the period start. Direct fix: align the EXPERIENCE.md wording to the span rule → patch (docs, by orchestrator) |
| 8 | blind | DESIGN.md Range picker still says "bara Aktiedetalj" | low | Confirmed; direct doc fix → patch (orchestrator) |
| 9 | blind+edge | Qualifying rows with negative period % can be listed | low | Intent keeps the qualifier unchanged; only visible when fewer than 10 rows have a positive %, and the fix changes frozen intent → reject |
| 10 | blind+edge | Spike toggle 38px / segments 32px, below the previous 44px | false | 38px is the `.range-picker` pill's outer height (32 + 2×3 padding), so the toggle aligns with it; segments match the other segmented controls, as the spec says |
| 11 | edge | "Plusdagar unchanged" claim vs. the restyled spike toggle | false | Intent moves Dölj spikar into the period row; the restyle is the intended change |
| 12 | blind | `role="tablist"` on a row of links | low | Same pattern as the existing source switcher and range picker; the fix is app-wide → reject |
| 13 | blind | Row doesn't highlight which period % drives the sort | low | Enhancement not in the intent; raised to the user as a follow-up idea → reject |
| 14 | blind | No Alla + steady + period test | false | `testRootWithSourceAllaAndRankingSteady…` tests run the default period Månad through the new path |
| 15 | blind | `.period-row` negative margin is coupled to `.controls` spacing | low | Developer-only, no breakage today; the fix is a layout restructure → reject |
| 16 | blind | Plain-tab test depends on heredoc whitespace | low | Test brittleness, not a defect → reject |
| 17 | blind | Spec has no recorded test run | low | The fix edits the spec → reject |
