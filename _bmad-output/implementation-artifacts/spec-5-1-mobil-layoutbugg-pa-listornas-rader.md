---
title: 'Mobil layoutbugg på listornas rader'
type: 'bugfix'
created: '2026-09-13'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
context: []
baseline_commit: '54db85730abe2d4df8ff501d2c68fc642349437c'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** On mobile (~390px), rows on Topplista/Fullständig lista/Bevakningslista get
cut off — the owner-count number and Delta chip are pushed off-screen and invisible,
because `.trend`'s "insufficient history" text label refuses to shrink or wrap.

**Approach:** Root-caused via `DESIGN.md`'s own canonical mockup (`mockups/leaderboard-hero.html`),
which specifies a structure the shipped implementation never matched: name+badges nested in
one shrinkable column (badges wrap below the name — `EXPERIENCE.md`'s own "Streak-/Spike-badgar
radbryts under radnamnet"), and owner-count+delta-chip nested in one fixed-width, protected
column — not five flat, mostly-unshrinkable flex siblings as currently built. Restructure the
row markup/CSS in all three affected controllers to match the mockup's actual layout.

## Boundaries & Constraints

**Always:** Owner count and Delta chip must never be clipped or pushed off-screen at any
width ≥ 390px — they are the row's primary payload (UX-DR12: count+percent always shown
together). The instrument name still truncates with an ellipsis before anything else gives
way (unchanged from today). Badges wrap below the name on mobile, exactly as `EXPERIENCE.md`
specifies — not a new decision, restoring the already-approved spec. Fix all three
controllers identically (`LeaderboardController`, `FullListController`,
`WatchlistController` — confirmed byte-identical `.row`/`.trend`/`.stat`/`.delta-chip` CSS
in each); `StockDetailController` has no row-shaped layout and is unaffected. Laptop-width
(≥900px) rendering — the explicit grid-like column layout, 130×30 sparklines — must not
regress.

**Never:** No new JS, no client framework (AD-12) — this is a pure CSS/markup restructuring.
Do not touch `StockDetailController.php`, `src/Pipeline/`, or `src/Store/`. Do not change
what data is fetched or how rows are queried — only how the already-correct data is laid out.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|---|---|---|---|
| Row with a long "no history" label | `sma_7 IS NULL`, mobile width | Owner count + Delta chip stay visible; the label wraps/truncates within its own column, never pushes siblings off-screen | N/A |
| Row with badges + long name | Both `up_streak >= 1` and spiking, long instrument name | Name ellipsizes; badges wrap onto their own line below the name, not squeezed inline | N/A |
| Normal row, no badges, has sparkline | Typical row | Renders exactly as before — no regression | N/A |
| Same fix at laptop width (≥900px) | Any row | Existing wide-layout column treatment unchanged | N/A |

</frozen-after-approval>

## Code Map

- `src/Web/LeaderboardController.php:168-221` (`renderRow()`, `sparklineHtml()`) and its
  CSS `.row`/`.row-body`/`.name`/`.badges`/`.trend`/`.sparkline`/`.sparkline-label`/`.stat`/
  `.delta-chip` (lines 456-500) — the row markup to restructure, and the exact current CSS
  values to replace.
- `src/Web/FullListController.php` (CSS lines 582-627) and `src/Web/WatchlistController.php`
  (CSS lines 350-395) — byte-identical row/CSS, apply the same fix in both; no shared CSS
  file exists (Stories 4.3–4.5's already-accepted duplication debt).
- Canonical reference: `_bmad-output/planning-artifacts/ux-designs/ux-stockpicker-2026-09-12/mockups/leaderboard-hero.html`
  lines 103-136 — the actual approved structure: `.namecol` (`min-width:0; flex:1 1 auto;`
  wrapping `.name` + `.badges` with `margin-top:3px` on badges, so they stack), `.spark`
  (`flex-shrink:0`), `.statcol` (`text-align:right; flex-shrink:0; width:70px;` wrapping
  `.owners` + `.delta-chip` with `margin-top:3px`, so they stack). The mockup has no
  "insufficient history" label — that text was added later and needs a width-bounded
  treatment inside the sparkline area (wrap or truncate) not present in the original design.
- Root cause confirmed: `.trend` is `flex:0 0 auto` (never shrinks) and `.sparkline-label`
  is `white-space:nowrap` with no width bound, so at 390px the label's max-content width
  forces `.row-body` past its bounds with no `overflow:hidden` anywhere to clip it, pushing
  `.stat`/`.delta-chip` off-screen.
- No automated visual/layout test exists or is feasible (PHPUnit only, no
  Panther/Selenium/browser runtime) — verification is CSS-value assertions plus manual
  browser checks.

## Tasks & Acceptance

**Execution:**
- [x] `src/Web/LeaderboardController.php` -- restructure `renderRow()`'s markup into namecol(name+badges)/spark/statcol(owners+delta-chip) per the mockup; update `css()` accordingly -- fixes the actual root cause, matches the already-approved mockup
- [x] `src/Web/FullListController.php` -- apply the identical markup/CSS restructuring
- [x] `src/Web/WatchlistController.php` -- apply the identical markup/CSS restructuring
- [x] `tests/Web/LeaderboardControllerTest.php` / `FullListControllerTest.php` / `WatchlistControllerTest.php` -- add an assertion that the rendered row HTML contains the new wrapper classes (`namecol`/`statcol` or equivalent), so a future refactor can't silently drop the fix
- [x] `tests/FrontControllerIntegrationTest.php` -- no new test required (existing rendering tests continue to pass); note in Implementation Notes if any existing assertion needed adjusting for the new markup

**Acceptance Criteria:**
- Given a row with `sma_7 IS NULL` (long "insufficient history" label) at 390px width, when rendered, then the owner count and Delta chip are present in the HTML in a column that CSS never shrinks below its content or clips
- Given a row with both Streak and Spike badges and a long name, when rendered at 390px, then the badges are DOM-nested under the name in a wrapping container, not inline flex siblings of `.trend`/`.stat`
- Given the same fix, when the page renders at ≥900px, then the existing wide-layout CSS block (`@media (min-width: 900px)`) still applies unchanged

## Implementation Notes

Extracted the row-body markup into a single new `LeaderboardController::rowBodyHtml()`
public static pure function (name/badges pre-rendered, owners pre-formatted, sparkline/
delta-chip pre-rendered HTML fragments in, `namecol`/`trend`/`statcol` markup out), rather
than restructuring the heredoc identically three times. `FullListController` and
`WatchlistController` call this shared function from their own `renderRow()` instead of
duplicating the markup — the same reuse pattern those two controllers already use for
`streakBadgeHtml()`/`spikeBadgeHtml()`/`deltaChipHtml()`. This made the new per-controller
test-file assertions possible without a database (`renderRow()` is a private instance method
needing a live `DerivedMetricsRepository`/`WatchlistRepository`, both `final` classes PHPUnit
cannot double) and keeps the three controllers' `.row-body` output guaranteed identical by
construction instead of by convention.

CSS restructuring applied identically in all three controllers' `css()`:
- `.namecol` (new): `display:flex; flex-direction:column; min-width:0; flex:1 1 auto;` —
  the sole shrinkable/wrapping column. `.name` dropped its own `flex`/`min-width` (no longer
  a flex sibling) and gained `display:block` so ellipsis still works inside the column.
  `.badges` gained `margin-top:3px` for the stacked spacing under the name.
- `.trend`: changed from a horizontal `flex:0 0 auto` row (svg + label side-by-side, no width
  bound — the actual root cause) to a `width:52px` (130px at ≥900px) vertical flex column, so
  `.sparkline-label` (now `white-space:normal; overflow-wrap:break-word; max-width:52px/130px`)
  wraps within its own column instead of forcing `.row-body` wider.
- `.statcol` (new): `text-align:right; flex-shrink:0; width:70px;` wrapping `.stat` (now
  `display:block`) and `.delta-chip` (now `display:inline-block; margin-top:3px`). The
  `width:70px` is a sizing hint, not a hard clip: flex's automatic-minimum-size behavior
  (no `overflow:hidden`/`min-width:0` set anywhere on this column) means a wider owner count
  than 70px simply grows the column instead of clipping — `.namecol`'s `min-width:0` is what
  absorbs the pressure, ellipsizing the name first, exactly as the Boundaries require.
- `@media (min-width: 900px)`: added `.trend { width: 130px; }` and
  `.sparkline-label { max-width: 130px; }` alongside the pre-existing sparkline enlargement,
  otherwise unchanged.

Verified the CSS block is still byte-identical across `FullListController`/`WatchlistController`
(diffed after extraction) and that `LeaderboardController`'s block differs from the other two
only in the two places it already did before this change (`.full-list-link` vs `.empty-state a`,
and `.controls { flex-direction: row; }` vs `...; flex-wrap: wrap;` at ≥900px).

No `FrontControllerIntegrationTest.php` assertions needed adjusting — `composer test` (436
tests, 2018 assertions, including that file's full DB-backed suite) passes unchanged.

No manual browser check was performed (no running local server/seeded DB in this session);
verification relied on `composer test` plus reasoning through the flexbox sizing/min-content
behavior described above. This is the one item worth a human eyeballing at 390px/900px before
calling this fully done, per the spec's own manual-check suggestion.

**Orchestrator verification:** independently re-verified — full diff read against
`baseline_commit`, all 5 execution tasks and all 3 spec-level ACs confirmed against the
diff, `composer test` re-run independently (436 tests / 2018 assertions, clean). Traced the
CSS by hand to confirm it's sound, not just plausible: `.statcol` is a flex item of
`.row-body` (blockified per the CSS Display spec regardless of its own `<span>`/`display:inline`
default), so `.stat` (block) + `.delta-chip` (inline-block, forced to a new line after a
preceding block box) stack correctly inside it in normal flow; its `width:70px` is only a
flex-basis hint — flex items get an implicit `min-width:auto` floor from their content
unless overridden, so a wider owner-count number (e.g. "999 999") grows the column rather
than clipping. `.namecol`'s explicit `display:flex; flex-direction:column` with default
`align-items:stretch` gives `.name` a definite width from its parent, which is what makes
its pre-existing `overflow:hidden`/`text-overflow:ellipsis` continue to work. No headless
browser exists in this stack (confirmed, matching the spec's own Code Map note) so this
reasoning-based check is the practical ceiling short of a manual eyeball — flagging the same
open item the implementation report already flagged, not resolving it further.

## Spec Change Log

## Review Triage Log

- **[medium, patch]** Production render path untested for the fix's own structural change. `FullListController::renderRow()`/`WatchlistController::renderRow()`/`LeaderboardController::renderRow()` are the only call sites that produce the HTML actually shipped on `/`, `/list`, `/watchlist`, but every new test (`LeaderboardControllerTest`, `FullListControllerTest`, `WatchlistControllerTest`) calls `LeaderboardController::rowBodyHtml()` directly, and `FrontControllerIntegrationTest` (which does exercise `renderRow()` end-to-end) asserts only stock names/owner counts/sparkline classes, never `namecol`/`statcol`. Verified by demonstration (verification-gap layer): reverting any one `renderRow()` to the old flat markup would pass every existing test. Fix: add `assertStringContainsString('class="namecol"'...)`/`'class="statcol"'` to the existing `/`, `/list`, `/watchlist` row assertions in `FrontControllerIntegrationTest.php`. — patched.
- **[low, patch]** `rowBodyHtml()`'s PHPDoc cites the mockup (`leaderboard-hero.html` lines 103-136) as the source for `.namecol`/`.trend`/`.statcol`, but that mockup section defines only `.namecol`/`.statcol`/`.spark{flex-shrink:0}` — no `.trend` fixed-width column or `.sparkline-label` wrap rule exists there; those are original engineering for the "insufficient history" label, not traceable to the cited lines. Verified by reading the mockup directly. Fix: reword the comment to cite the mockup only for `.namecol`/`.statcol`, and describe `.trend`'s sizing as this story's own addition. — patched.
- **[low, patch]** `LeaderboardControllerTest::testRowBodyHtmlOmitsNothingWhenBadgesAndDeltaChipAreEmpty()` passes `$badgesHtml = ''`, but `streakBadgeHtml()` unconditionally returns at least the `badge--nohist "flat"` span (confirmed by reading its implementation) — `$badgesHtml` can never actually be empty at a real call site, so the realistic single-flat-badge case is untested while an unreachable one is. Fix: build the badges fixture from `streakBadgeHtml(null)`'s real output instead of a literal `''`. — patched.
- **[low, defer]** `.row-body` CSS (`.namecol`/`.trend`/`.statcol`/etc.) is hand-edited identically into `LeaderboardController.php`/`FullListController.php`/`WatchlistController.php` a third time — this story extends the existing triplication (already-accepted debt per this spec's own Code Map, carried from Stories 4.3-4.5) rather than introducing it, but the surface for the next layout tweak keeps tripling. Deferred to `deferred-work.md`.
- **[false]** `rowBodyHtml()` performs no escaping of `$eName`/`$eOwners` itself. Refuted: both are pre-escaped via `self::e()` at the sole call site (`renderRow()`) before being passed in — the exact same "caller pre-escapes, callee documents the convention" pattern already used by every other static helper in this class (`$eIsin`, `$starLabel`, etc.), not something newly introduced or loosened by this diff.
- **[false]** `.badges:empty { margin-top: 3px }` always adds a gap even when a row has no badge. Refuted: `streakBadgeHtml()` never returns an empty string (always at least the "flat" badge span), so `.badges` is never actually empty in production — the trigger condition does not occur.
- **[false]** `.statcol`'s `width:70px` has no wrap/ellipsis ceiling unlike `.name`/`.sparkline-label`, so it could in theory grow the row past the viewport once `.namecol` fully collapses. Refuted by the reviewing layer's own hand-trace: no `overflow:hidden`/`min-width:0` is set on `.statcol`, so it's a flex sizing hint, not a clip bound, and `.namecol`'s `min-width:0` is exactly what's designed to absorb the pressure first (matching AC1's "never shrinks below its content or clips" requirement) — this is the documented design, not a defect.
- **[false]** AC3's "the existing wide-layout CSS block still applies unchanged" is contradicted by the diff adding `.trend{width:130px}`/`.sparkline-label{max-width:130px}` inside that `@media` block. Refuted: "still applies unchanged" reads naturally as "continues to apply, no regression" (matching the Boundaries' actual requirement — "must not regress"), not "byte-identical"; the added rules extend the same size-tier-override pattern the block already used for the sparkline enlargement, consistent with, not contrary to, its established behavior.
- **[out of scope]** No test verifies literal pixel-width arithmetic (that the row actually fits in 390px). Rejected: the spec's own Code Map states plainly that "No automated visual/layout test exists or is feasible (PHPUnit only, no Panther/Selenium/browser runtime)" — the frozen intent itself excludes this from what's buildable here; the spec's own Verification section names the manual browser check as the intended closer for this gap.
- **[out of scope]** No spec/sprint-tracking artifact was updated to record the work as implemented. Rejected: not a code defect — marking the spec/sprint status is this workflow's own step 5, performed by the orchestrator after review, not part of the reviewed diff.

## Design Notes

This is a restoration, not a new design: `EXPERIENCE.md`'s Responsivitet section already
specifies "Streak-/Spike-badgar radbryts under radnamnet" for mobile — the shipped
implementation just never matched it. Treat the mockup's `.namecol`/`.statcol` structure as
the ground truth for the restructuring; adapt property names to this codebase's existing
class names (`.name`, `.badges`, `.stat`, `.delta-chip`) rather than renaming everything to
match the mockup verbatim. The "insufficient history" sparkline label (not present in the
mockup) should get a `max-width` matching the sparkline's own width token
(`{components.sparkline.width-mobile}` = 52px, `width-wide` = 130px) and wrap onto a second
line within its own column rather than forcing the row wider — do not truncate it with
ellipsis, since "Nd spårade" is short enough to matter in full and the column has vertical
room once badges/delta-chip already stack.

## Verification

**Commands:**
- `composer test` -- expected: all PHPUnit tests pass, including the new markup-assertion
  cases in the three controller test files

**Manual checks (if no CLI):**
- Run `php -S localhost:8080 -t public_html` against a seeded local database; view Topplista
  in a browser at 390px width (devtools responsive mode) with an instrument that has both
  badges and an "insufficient history" sparkline label, and confirm owner count + Delta
  chip are fully visible with no horizontal scroll. Repeat at ≥900px and confirm the
  existing wide layout is unchanged. Repeat on `/list` and `/watchlist`.
