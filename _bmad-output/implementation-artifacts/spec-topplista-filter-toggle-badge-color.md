---
title: 'Topplista: real filter-toggle checkboxes + red Blankningsbadge'
type: 'refactor'
created: '2026-10-06'
status: 'done'
baseline_revision: '47e70c25760382abb6ed2de6499ea1619437563d'
review_loop_iteration: 0
followup_review_recommended: true
context:
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-stockpicker-2026-09-12/DESIGN.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-stockpicker-2026-09-12/EXPERIENCE.md'
warnings: [multiple-goals]
deferred:
  - summary: >-
      aria-pressed is used on <a> (link) elements instead of a button/role="button"
      element for the Topplista filter-toggle checkboxes.
    evidence: |-
      aria-pressed is not part of ARIA's supported states for the implicit "link"
      role. Confirmed real via blind-hunter review (2026-10-06), but the pattern
      predates this diff -- it already shipped in FullListController::filterTogglesHtml(),
      and this spec explicitly directed mirroring it for markup consistency. Fixing it
      properly would mean revisiting the existing, already-shipped FullListController
      instance too, not just this diff's two new call sites.
    location: >-
      src/Web/LeaderboardController.php (periodRowHtml) and
      src/Web/FullListController.php (filterTogglesHtml)
    severity: low
---

<intent-contract>

## Intent

**Problem:** Topplista's "Dölj spikar"/"Dölj blankade" render as plain `.spike-toggle` text links with literal "☐"/"☑" glyphs (not the real `.filter-toggle` checkbox chip Fullständig lista already uses), and "Dölj blankade"'s position shifts between ranking modes depending on what precedes it. Separately, the Blankningsbadge ("Blankad X %") shares `.badge--nohist`'s grey and is indistinguishable from the neutral chips around it.

**Approach:** Reuse the existing `.filter-toggle` component (`public_html/assets/app.css`, already used by Fullständig lista) for both Topplista toggles, laid out in a fixed two-row right-aligned slot so "Dölj blankade" is always row 2 regardless of mode. Give `.badge--short` its own red rule instead of sharing `.badge--nohist`.

## Boundaries & Constraints

**Always:** Keep every existing query param, href, and cookie behavior byte-identical (`shorts=exclude`, `spikes=exclude`, source/period/market survival) — only the *markup/class* of the two toggles and the badge's CSS change. Keep the muted "Gäller inte Flest ägare" note outside the two-row checkbox slot, between the period pill and the slot (per DESIGN.md's Periodväljare entry). Preserve `aria-pressed` semantics (model on `FullListController::filterTogglesHtml()`). Update every test assertion and the Info-page static symbol example that hardcodes the old markup — do not leave stale assertions passing by accident or break the suite.

**Never:** Do not touch query-string parsing/logic, the cookie-writing code, or any non-visual behavior. Do not introduce JS. Do not change Fullständig lista's own filter-toggle markup (already correct). Do not restyle any other header control.

</intent-contract>

## Code Map

- `src/Web/LeaderboardController.php` — `periodRowHtml()` (~line 1027-1083, builds `$extraHtml`): currently emits `<a class="spike-toggle[...]" href="..."><span aria-hidden="true">☐|☑</span> Dölj spikar</a>` then the same pattern for `short-toggle`/"Dölj blankade", wrapped in one `<span class="period-extras">...</span>`. `FullListController::filterTogglesHtml()` (~line 513-532) is the markup model to mirror: `<a class="filter-toggle[ filter-toggle--active]" href="..." aria-pressed="true|false">Label</a>`, no glyph span (CSS `::before` draws the box).
- `src/Web/LeaderboardController.php` — `shortBadgeHtml()` (~line 506-514) and `shortBadgeWithDateHtml()` (~line 523-535): both emit `class="badge badge--nohist badge--short"`. Drop `badge--nohist` from both, leaving `class="badge badge--short"`.
- `src/Web/InfoController.php` — line ~94, the static symbol-list example: same `badge badge--nohist badge--short` string, same fix.
- `public_html/assets/app.css`:
  - `.spike-toggle`/`.spike-toggle--active` (~line 232-238) — becomes dead once both toggles move to `.filter-toggle`; remove.
  - `.filter-toggle`/`.filter-toggle--active`/`::before` (~line 288-310) — reuse as-is, no changes needed.
  - `.period-extras` (~line 254-258) — currently a flex-wrap row of inline items; restructure to a `flex-direction: column` stack of exactly two fixed rows (new `.period-extras-row` class, each `min-height: 32px` to match `.filter-toggle`, right-aligned). Row 1 holds the spike toggle when present (Plusdagar) or is empty (Flest ägare, Stadig tillväxt); row 2 always holds the short toggle.
  - `.badge--nohist` comment (~line 359-363) mentions short badge reuse — update/remove once `.badge--short` gets its own rule: `.badge--short { background: var(--negative-tint); color: var(--negative); }`.
- `tests/Web/LeaderboardControllerTest.php` — `testShortBadgeHtmlFormatsOneDecimalWithDecimalCommaInTheQuietGreyBadge` (~line 589, rename off "QuietGrey") and the `badge badge--nohist badge--short` string at line 594: update to `badge badge--short`.
- `tests/FrontControllerIntegrationTest.php` — every exact-string assertion containing `spike-toggle`, `short-toggle`, `☐`, `☑`, or `badge--nohist badge--short` needs its replacement markup; known occurrences (not exhaustive — grep to confirm none missed): lines ~1042, 1156, 1197, 2570-2572, 2672, 2715-2716, 2727, 2736, 2742, 2749, 2763 (the muted-mode wrapper — note `.period-extras-row` wraps the empty slot where "Dölj spikar" would be, plus the note now sits *outside* `.period-extras`), 2770, 2775, 2816, 2825. Tests at ~940/1216/2815/2847 that assert absence (`assertStringNotContainsString('Dölj spikar', ...)` / `badge--short`) need no change.

## Tasks & Acceptance

**Execution:**
- `public_html/assets/app.css` -- replace `.spike-toggle` family with a `.period-extras` two-row layout (reusing `.filter-toggle` visuals) and add `.badge--short`'s own red rule -- makes the real checkbox and badge color decisions visible.
- `src/Web/LeaderboardController.php` -- rewrite `periodRowHtml()`'s toggle markup to `.filter-toggle`/`aria-pressed`, keep the muted note outside the new two-row slot, drop `badge--nohist` from both short-badge methods -- implements the decision with zero param/cookie/query changes.
- `src/Web/InfoController.php` -- update the static badge example string to match -- keeps the Info page's "what symbols mean" section truthful.
- `tests/Web/LeaderboardControllerTest.php`, `tests/FrontControllerIntegrationTest.php` -- update every hardcoded markup assertion listed in Code Map to the new strings, rename the now-inaccurate "QuietGrey" test -- keeps the regression suite honest instead of just green.

**Acceptance Criteria:**
- Given Plusdagar mode, when the period row renders, then both toggles are `.filter-toggle` links (checked state adds `filter-toggle--active` + `aria-pressed="true"`) and "Dölj blankade" is the second row of a fixed right-aligned stack.
- Given Flest ägare or Stadig tillväxt mode, when the period row renders, then row 1 of the stack is empty (no spike toggle) but still reserves height, and "Dölj blankade" sits in the exact same row 2 position as in Plusdagar.
- Given a row with `short_pct >= 5`, when rendered anywhere (Topplista any mode/source, Fullständig lista, Bevakningslista, Aktiedetalj), then the badge has class `badge badge--short` (no `badge--nohist`) and app.css paints it with `var(--negative-tint)`/`var(--negative)`.
- Given the full suite, when run, then `vendor/bin/phpunit` and `vendor/bin/phpstan analyse --memory-limit=512M` both pass clean.

## Spec Change Log

## Review Triage Log

### 2026-10-06 — Review pass
- verdicts: 10 findings — high 0, medium 2, low 8, false 0, maybe-false 0
- findings:
  - `[medium]` `[patch]` DESIGN.md/EXPERIENCE.md still describe the checkbox migration and badge color as "Beslutat 2026-10-06, ej kodat" (blind-hunter) — evidence: the decision is now implemented by this diff; the stale "not yet coded" status will mislead a future reader of the spine. Fix applied: update the status wording in both docs to reflect implementation.
  - `[low]` `[reject]` Spec's own `## Verification` section claims `vendor/bin/phpstan analyse` returns `[OK] No errors` (blind-hunter) — evidence: confirmed 6 pre-existing errors in `ShortPositionSync.php`/`UniverseSync.php`/`ShortPositionRepository.php`, identical on baseline `47e70c2` via `git stash`, unrelated to this diff. Rejected: fix is to edit this build's spec, which is excluded from patching per the review rules.
  - `[low]` `[patch]` `LeaderboardController::periodRowHtml()`'s heredoc always emits the `{$noteHtml}` placeholder line, leaving a harmless blank line in the rendered HTML when `$noteHtml` is empty (non-muted modes) (blind-hunter) — evidence: confirmed by reading the heredoc; invisible to users (HTML whitespace between block elements) but a trivial direct fix. Fix applied.
  - `[low]` `[patch]` `tests/FrontControllerIntegrationTest.php`'s `assertLessThan(..., 'right of "Dölj spikar"')` message is stale — the layout is now a vertical two-row stack, not horizontal (blind-hunter) — evidence: confirmed against the new `.period-extras-row` CSS; assertion logic is still correct, only the failure-message wording is wrong. Fix applied: reworded to describe markup order instead of visual direction.
  - `[low]` `[defer]` `aria-pressed` is used on `<a>` (link) elements rather than a `button`/`role="button"` element, in both the new Topplista toggles and the pre-existing `FullListController::filterTogglesHtml()` pattern they deliberately mirror (blind-hunter) — evidence: confirmed `aria-pressed` is not part of ARIA's supported states for the implicit "link" role; however this pattern predates this diff (already shipped in `FullListController`) and the spec explicitly directed mirroring it for consistency. Deferred: pre-existing issue, extends beyond this story's two new call sites to an already-shipped surface.
  - `[low]` `[reject]` Moving `.period-note` out of `.period-extras` means the note and the two-row toggle slot can now wrap onto separate lines independently on a narrow viewport, previously glued together (blind-hunter) — evidence: confirmed as a real structural change via the CSS diff, but it matches DESIGN.md's own Periodväljare entry (written the same day) describing the note as sitting "till höger om segmenten, innan filter-checkbox-facket" — a separate element, not nested inside the toggle slot. No concrete broken rendering was demonstrated, and `.period-row`'s children already wrapped independently before this diff. Rejected: matches documented intent; fix would mean reverting the explicit structural decision.
  - `[low]` `[reject]` Spec's own Code Map cites baseline line numbers (e.g. "~line 1027-1083") for code this diff rewrites, which will drift once merged (blind-hunter) — evidence: true but inherent to "~line" being explicitly approximate per the spec template's own convention, on an artifact about to be marked `done`. Rejected: fix is to edit this build's spec.
  - `[low]` `[reject]` Same claim as above: spec's `## Verification` section's phpstan expectation is false (edge-case-hunter) — evidence: independently reproduced 6 pre-existing errors unrelated to this diff, confirmed via `git stash` against baseline. Rejected: fix is to edit this build's spec (duplicate of the blind-hunter finding above; grouped).
  - `[medium]` `[patch]` No test asserts the full `.period-extras` structure for `ranking=steady` (Stadig tillväxt) the way `testRootDoljBlankadeIsALiveLinkInFlestAgaresMutedPeriodRowAndRememberedFromTheCookie` does for `ranking=count` (verification-gap, pre-verified) — evidence: a hypothetical future change that special-cased Flest ägare's empty row-1 placeholder and dropped it for Stadig tillväxt would reintroduce the exact position-shift regression this spec exists to prevent, undetected by the current suite. Fix applied: added an exact-structure assertion for `ranking=steady` mirroring the existing `ranking=count` one.
  - `[medium]` `[patch]` Same root cause as the first finding above: DESIGN.md/EXPERIENCE.md's framing (via this spec's own `context:` list and the sibling blocked-run artifact) treats implementing the recorded UX decision as the point of this work, but the diff never touches those docs (intent-alignment) — evidence: `EXPERIENCE.md` lines 60/62 and `DESIGN.md` line 282 still read "ej kodat"/"nuvarande implementation" after the decision shipped. Grouped with the first finding; same fix.

## Design Notes

Row 1's "empty but height-reserved" slot: render it as `<span class="period-extras-row" aria-hidden="true"></span>` (no content) rather than omitting the element — omitting it would collapse row 2 upward in modes without a spike toggle, reintroducing the exact position-shift bug this spec fixes.

## Verification

**Commands:**
- `php -l src/Web/LeaderboardController.php src/Web/InfoController.php` -- expected: no syntax errors
- `vendor/bin/phpstan analyse --memory-limit=512M` -- expected: `[OK] No errors`
- `vendor/bin/phpunit` -- expected: all tests pass (740 baseline + any renamed/updated ones), 0 failures

## Auto Run Result

**Summary:** Topplista's "Dölj spikar"/"Dölj blankade" now render as real `.filter-toggle` checkbox chips (reusing Fullständig lista's existing component) instead of plain-text `.spike-toggle` links with literal ☐/☑ glyphs, laid out in a fixed two-row right-aligned slot so "Dölj blankade" never shifts position between ranking modes. The Blankningsbadge ("Blankad X %") now has its own red `.badge--short` rule instead of sharing `.badge--nohist`'s grey. `DESIGN.md`/`EXPERIENCE.md` were resynced to say the decision is implemented, not pending.

**Files changed:**
- `public_html/assets/app.css` -- removed `.spike-toggle`; restructured `.period-extras` into a two-row `.period-extras-row` stack; added `.badge--short`'s own red rule.
- `src/Web/LeaderboardController.php` -- `periodRowHtml()` rewritten to `.filter-toggle`/`aria-pressed` markup with the fixed two-row slot and a no-blank-line note placement; `shortBadgeHtml()`/`shortBadgeWithDateHtml()` drop `badge--nohist`.
- `src/Web/InfoController.php` -- static symbol-list badge example updated to match.
- `tests/Web/LeaderboardControllerTest.php`, `tests/FrontControllerIntegrationTest.php` -- all hardcoded old-markup assertions updated; one test renamed off "QuietGrey"; added a `ranking=steady` structural assertion mirroring the existing `ranking=count` one; reworded a stale assertion message.
- `_bmad-output/planning-artifacts/ux-designs/ux-stockpicker-2026-09-12/DESIGN.md`, `EXPERIENCE.md` -- flipped "beslutat/ej kodat" status wording to implemented across the Spike-toggle, Filter-toggle, Blankningsbadge, Blankningsväxlare, Spikväxlare and Filter-checkbox entries.

**Review findings breakdown (4 layers, 10 findings total):**
- Patched (4): docs stale "ej kodat" wording (medium, 2 findings grouped — blind-hunter + intent-alignment); missing `ranking=steady` structural test coverage (medium — verification-gap, pre-verified); stray blank heredoc line when the muted note is absent (low — blind-hunter); stale "right of" assertion message for the now-vertical layout (low — blind-hunter).
- Deferred (1): `aria-pressed` on `<a>` elements rather than a button/`role="button"` element (low) -- pre-existing pattern in `FullListController::filterTogglesHtml()` that this diff's spec explicitly directed mirroring; fixing it properly means revisiting that already-shipped surface too, not just this diff's two new call sites.
- Rejected (3, all "fix is to edit this build's spec" or matched documented intent): this spec's own Verification section wrongly claims a clean `phpstan` run (6 pre-existing, unrelated errors actually exist, confirmed identical on baseline via `git stash` -- blind-hunter + edge-case-hunter, same claim, grouped); the spec's Code Map line-number references will drift post-merge (blind-hunter); moving the muted note outside `.period-extras` lets it wrap independently on narrow viewports (blind-hunter) -- rejected because it matches DESIGN.md's own Periodväljare entry (written the same day) describing the note as a separate element, and no concrete broken rendering was demonstrated.

**Follow-up review recommendation:** `true` -- two `medium` entries were patched on this first pass (the docs resync and the new steady-mode test). Specific unverified risk: the `DESIGN.md`/`EXPERIENCE.md` wording fixes were applied as scattered targeted edits across many entries in two files by the implementation subagent; grep confirms no stale "ej kodat"/"nuvarande implementation" phrases remain, but a full prose-coherence read of both files (not just a grep check) has not been done, so a tense mismatch or awkward sentence elsewhere in the edited rows cannot be fully ruled out.

**Verification performed:** `php -l` on both touched PHP files (clean); `vendor/bin/phpstan analyse --memory-limit=512M` (6 errors, all pre-existing and unrelated -- confirmed identical on baseline `47e70c2` via `git stash`, none in files this spec touches); `vendor/bin/phpunit` (740/740 tests, 10373 assertions, 0 failures) -- all three run independently by the coordinator, not just trusted from the implementation subagent's self-report. Diff manually read and judged against the spec's Code Map and Acceptance Criteria.

**Residual risks:** the prose-coherence risk named above (follow-up review recommendation); the deferred `aria-pressed`-on-link accessibility pattern, now present on two more elements; no automated test exercises actual CSS flex-wrap rendering at narrow viewports (this codebase has no visual/layout testing infrastructure at all, not a regression introduced by this change).
