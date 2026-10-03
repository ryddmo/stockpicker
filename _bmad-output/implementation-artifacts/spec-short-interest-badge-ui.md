---
title: 'Short-interest UI: "Blankad X %" badge + "Dölj blankade" toggle'
type: 'feature'
created: '2026-10-03'
status: 'done'
baseline_revision: '1d763c1eb527d4e06b7bbf6134e329b5f024b0ec'
review_loop_iteration: 0
followup_review_recommended: false
context:
  - '{project-root}/_bmad-output/implementation-artifacts/spec-short-interest-data.md'
warnings: ['oversized']
deferred:
  - summary: >-
      Short positions from an old snapshot are badged and filtered as current when the nightly FI fetch keeps failing; there is no age limit on MAX(snapshot_date).
    evidence: |-
      currentForIsins() and the new shortedCondition() both take MAX(snapshot_date) unconditionally (semantics set in spec-short-interest-data). Failed runs are visible in show-runs/alarms, but the UI never says the data is stale. Needs a staleness policy (e.g. ignore snapshots older than N days, or show the FI date in the badge title).
    location: >-
      src/Store/ShortPositionRepository.php currentForIsins / src/Store/DerivedMetricsRepository.php shortedCondition
    severity: medium
---

<intent-contract>

## Intent

**Problem:** The data half (spec-short-interest-data, merged in PR #30) stores FI's aggregated short positions per issuer each night, but nothing in the UI shows them. Stefan wants to see at a glance which listed companies professionals are heavily short (≥ 5 %), and to be able to hide them from the Topplista (UX memlog decisions, 2026-10-03).

**Approach:** Add a "Blankad X %" badge to the badge row on every row surface (Topplista in all three modes, Fullständig lista, Bevakningslista) and to Aktiedetalj with FI's date. Add a "Dölj blankade" toggle to the Topplista period row in all three modes, persisted in the `topplista_view` cookie as `shorts=exclude`. The toggle's ≥ 5 % filter is applied in SQL before the top 10 is cut.

## Boundaries & Constraints

**Always:**
- The threshold is one shared constant, `ShortPositionRepository::BADGE_THRESHOLD_PCT = 5.0`. It is compared against the raw stored `position_pct`, so a position ≥ 5.00 qualifies. The badge and the filter use the same constant.
- "Current" means the latest snapshot, matched by LEI only, with the same semantics as `currentForIsins()`. An older snapshot never counts. If an ISIN has no LEI or no row in the latest snapshot, it has no badge and is never hidden.
- Badge text is `Blankad 15,8 %`: one decimal, decimal comma, a normal space before `%`. On Aktiedetalj it is `Blankad 15,8 % (FI 2 okt)`, where the date is the row's `position_date` (FI's own date), formatted as day without zero-padding plus a lowercase Swedish month abbreviation: jan feb mar apr maj jun jul aug sep okt nov dec.
- The badge comes last in the badge row (after streak/flat, the Plusdagar chip and spike) and uses the quiet grey `badge--nohist` treatment with an extra `badge--short` class. It gets no new colour.
- The badge is the same in every Source mode (Avanza, Nordnet, Alla), because short interest is per issuer, not per owner source.
- The toggle is a plain link with no JS (AD-12), like "Dölj spikar". It reads "☐ Dölj blankade" when off and "☑ Dölj blankade" when on, and it is a live link in all three modes, including Flest ägare's muted period row. It sits right of "Dölj spikar" where that exists, otherwise right of the pill or the "Gäller inte Flest ägare" note.
- `shorts` is a view param in every mode. It joins `VIEW_PARAMS`, `normalizeView`, `viewParams`/`url` (emitted only when on, in any mode) and the cookie. A `shorts` param in the query makes the query authoritative, exactly like `spikes`/`market`. Every header link (source, ranking, period, market, spike toggle) carries it.
- All SQL lives in `src/Store/` (AD-14), and the Swedish copy follows NFR12.

**Never:**
- No badge or filter below 5 %, and no badge for an issuer missing from the latest snapshot.
- No hiding on Fullständig lista, Bevakningslista or Aktiedetalj. They show the badge only, and Fullständig lista gets no new filter.
- No new client JS, no new colour token, no change to the FI or GLEIF pipeline, and no migration.
- No name-based issuer matching.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Badge shown | latest snapshot has the row's LEI at 15.82 | `Blankad 15,8 %` in the badge row | — |
| Below threshold | 4.99 | no badge | — |
| Exactly 5 | 5.00 | `Blankad 5,0 %` | — |
| Stale only | issuer only in an older snapshot | no badge, not hidden | — |
| No snapshot yet | `short_position` empty | no badges anywhere; the toggle renders, but turning it on hides nothing | — |
| No LEI | `instrument.lei` NULL | no badge, never hidden | — |
| Share classes | A and B shares with the same LEI at 6 % | both badged, both hidden by the toggle | — |
| Toggle on, top N | the #1 Plusdagar row is ≥ 5 % shorted | the row is gone; the list still has 10 rows (#11 moves up), since the filter runs in SQL before LIMIT | — |
| Toggle hides all | every qualifier is shorted | the mode's existing empty-state copy | — |
| Garbage param | `?shorts=yes` or `shorts[]=x` | treated as off | never 500 |
| Cookie | `topplista_view=ranking=count&shorts=exclude`, bare `/` | Flest ägare with the toggle on | — |
| Aktiedetalj | position 15.82, position_date 2026-10-02 | `Blankad 15,8 % (FI 2 okt)` in the badge row | — |

</intent-contract>

## Code Map

- `src/Store/ShortPositionRepository.php` -- add `public const BADGE_THRESHOLD_PCT = 5.0`. `currentForIsins()` already returns `isin => {pct, position_date}` and is reused as-is.
- `src/Store/DerivedMetricsRepository.php` -- `topByOwnerCount` (L90), `topByTrendQualityForPeriod` (L146) and `topByPlusDays` (L249) each gain a trailing `bool $excludeShorted = false`. Add a private static helper next to `marketCondition()` (L192) that returns `AND NOT EXISTS (SELECT 1 FROM short_position sp WHERE sp.lei = i.lei AND sp.snapshot_date = (SELECT MAX(snapshot_date) FROM short_position) AND sp.position_pct >= :short_threshold)`, plus a matching bind helper. All three queries already join `instrument i`. A NULL `i.lei` never matches, so the row is kept.
- `src/Web/LeaderboardController.php`:
  - The constructor (L106) gains `ShortPositionRepository $shorts`.
  - `render()` (L118) gains `string $shorts = ''`. It passes `$excludeShorted` to all three repo calls, then calls `currentForIsins()` once on the rendered rows and passes each row's pct to `renderRow()` (L537), which appends `shortBadgeHtml()` after `spikeBadgeHtml`.
  - Add `public const SHORTS_EXCLUDE = 'exclude'`, `VIEW_PARAMS` (L101) += `'shorts'`, and `normalizeView` (L207) gains `'shorts' => $str['shorts'] === self::SHORTS_EXCLUDE` (in all modes).
  - Thread `$excludeShorted` through `pageHtml` (L694), `sourceSwitcherHtml`, `rankingToggleHtml`, `periodRowHtml` (L893), `marketRowHtml` (L947), `url` (L981), `viewParams` (L996) and `serializeView` (L272). In `viewParams` the param is emitted as `shorts=exclude` after `spikes`.
  - Add public static helpers: `shortBadgeHtml(?float $pct): string` returns '' when `$pct` is null or below the threshold; `isShorted(?float $pct): bool`; and `shortBadgeWithDateHtml(?float $pct, ?string $positionDate): string` for Aktiedetalj.
  - In `periodRowHtml`, render the toggle in every mode. The existing `$extraHtml` branch structure must also emit it after the note or the spike toggle.
- `public_html/index.php` -- the `/` route (L91-111) injects `new ShortPositionRepository($pdo)` and passes `$view['shorts'] ? SHORTS_EXCLUDE : ''`. `/list` (L140), `/watchlist` (L170) and the stock route (L625) inject the repo into their controllers.
- `src/Web/FullListController.php` (`renderRow` L171, badges L178) and `src/Web/WatchlistController.php` (badges L115) -- gain the repo in their constructors. Call `currentForIsins(array_column($rows,'isin'))` once per render and append `LeaderboardController::shortBadgeHtml()` to `$badgesHtml`.
- `src/Web/StockDetailController.php` -- the constructor (L45) gains the repo. At the badges line (L79), append `shortBadgeWithDateHtml()` from `currentForIsins([$instrument->isin])`.
- `public_html/assets/app.css` -- `.spike-toggle` (L236-242) and the flex rule at L255 already cover a second toggle if it reuses the class. Give the new link `class="spike-toggle short-toggle"` (plus `spike-toggle--active` when on). Make sure the muted-row rules (L246-249) target only `.range-picker .tab`, so the toggle isn't greyed out. Add `.badge--short` only if it needs anything beyond `badge--nohist`.
- `src/Web/InfoController.php` -- the badge legend (L84-89) gets a third symbol row for "Blankad X %". The Topplista paragraph (L64) and the Plusdagar paragraph (L118) mention "Dölj blankade" and add it to the cookie's list of remembered choices.
- `tests/FrontControllerIntegrationTest.php` -- route-level tests; reuse the existing helpers (`seedPlusMonth` L1003, `seedOwnerCount` L2604). Set the LEI with an `UPDATE instrument` and insert `short_position` rows directly.
- `tests/Web/LeaderboardControllerTest.php` -- unit tests for the static helpers (`normalizeView`, `resolveView`, `serializeView`, badge helpers).
- `tests/Store/DerivedMetricsRepositoryTest.php` -- repo-level exclusion tests.
- `tests/Store/StoreTestCase.php` -- it already mirrors `short_position` and `instrument.lei` (L82-120), so no change is needed.
- `_bmad-output/planning-artifacts/ux-designs/ux-stockpicker-2026-09-12/{DESIGN.md,EXPERIENCE.md}` -- add the badge and the toggle (the memlog already holds the decisions).

## Tasks & Acceptance

**Execution:**
- `src/Store/ShortPositionRepository.php` -- add the threshold constant.
- `src/Store/DerivedMetricsRepository.php` -- add the shorted-exclusion condition and its bind to the three top-N methods.
- `src/Web/LeaderboardController.php` -- add the badge helpers, the `shorts` view param, the toggle in the period row in all modes, and render wiring.
- `src/Web/FullListController.php`, `src/Web/WatchlistController.php`, `src/Web/StockDetailController.php` -- inject the repo and append the badge.
- `public_html/index.php` -- wire the repo into four routes and pass `shorts` from the resolved view.
- `public_html/assets/app.css` -- styling for the toggle and badge (minimal).
- `src/Web/InfoController.php` -- add the legend row and the toggle copy.
- `tests/Web/LeaderboardControllerTest.php`, `tests/Store/DerivedMetricsRepositoryTest.php`, `tests/FrontControllerIntegrationTest.php` -- cover every row of the I/O matrix plus the ACs below.
- `DESIGN.md` and `EXPERIENCE.md` -- record the badge (component plus trigger) and the toggle.

**Acceptance Criteria:**
- Given a ≥ 5 % shorted instrument in the top 10, when `/` renders in each of Plusdagar, Stadig tillväxt and Flest ägare, then its row shows `Blankad X,X %`. The same instrument also shows the badge on `/list` and `/watchlist` (when starred) and on `/stock/{isin}` with `(FI d mmm)`.
- Given `/?ranking=plus&shorts=exclude`, when rendered, then the shorted instrument is absent, the toggle shows ☑, and every header link (source, ranking, period, market, Dölj spikar) contains `shorts=exclude`. The toggle's own link drops it.
- Given that response, then it sets the `topplista_view` cookie containing `shorts=exclude`. A following bare `/` request with that cookie renders with the toggle on.
- Given `/?ranking=count`, when rendered, then the period row is still muted, but "Dölj blankade" is a clickable `<a>` whose href has `ranking=count&shorts=exclude`.
- Given the existing test suite, when `composer test` runs, then everything passes, including the existing cookie and URL assertions, updated only where the new param legitimately appears.

## Spec Change Log

## Review Triage Log

### 2026-10-03 — Review pass
- verdicts: 22 findings — high 0, medium 3, low 17, false 2, maybe-false 0
- findings:
  - `[medium]` `[patch]` (edge) Period row at ~390px: two `flex: 1 1 0` neighbours right of the pill get ~50px each, "blankade"/"spikar" overflow — wrapped the neighbours in `.period-extras` (flex-wrap, nowrap children)
  - `[low]` `[reject]` (edge) Snapshot committed between top-N SQL and badge lookup → filter/badge disagree — millisecond window during the nightly cron; fix needs shared snapshot plumbing
  - `[medium]` `[defer]` (edge) Stale FI data never flagged: MAX(snapshot_date) has no age limit — pre-existing currentForIsins() semantics from spec-short-interest-data; needs a staleness policy decision
  - `[medium]` `[patch]` (blind) Same narrow-screen squeeze as the edge finding — same fix
  - `[low]` `[patch]` (blind) Period-row CSS header comment mentions only "Dölj spikar" — comment updated
  - `[low]` `[patch]` (blind) Dangling `.badge--short` CSS comment reads as describing `.badge--plusdays` — moved into the `.badge--nohist` comment
  - `[low]` `[reject]` (blind) Toggle on/off state invisible to screen readers — same pattern as existing "Dölj spikar"; single sighted user; fix adds ARIA design
  - `[low]` `[reject]` (blind) Defer-to-existing empty state doesn't name the active filter — the spec's matrix chose the existing copy; all qualifiers being ≥ 5 % shorted is improbable (0–2 per top 10)
  - `[low]` `[patch]` (blind) bindShorted binds a 2-decimal-formatted threshold while PHP compares the raw float — now binds the constant unformatted
  - `[low]` `[patch]` (blind) New docblock lines at column 0 — re-indented
  - `[low]` `[patch]` (blind) Style drift (brace placement, optional vs required `$shortPct`, use-order) — aligned
  - `[low]` `[patch]` (blind) serializeView reads `$view['shorts'] ?? false` despite required `shorts: bool` — `??` dropped
  - `[low]` `[reject]` (blind) currentForIsins() still called when the filter is on — one cheap indexed query; skipping adds a branch
  - `[low]` `[reject]` (blind) Test gaps (Nordnet Topplista badge, rendered share classes, steady toggle position, empty state in other modes, null date) — badge path is source-independent and shared; helpers and repo cases are covered; verification-gap layer found no gaps
  - `[low]` `[patch]` (blind) EXPERIENCE.md Periodväljare and Topplista row-2 passages omit the new toggle — updated; mockup not updated (spines win on conflict)
  - `[false]` `[reject]` (blind) Spec staleness (line anchors, warnings, status) — fix edits this build's spec
  - `[false]` `[reject]` (intent) Aktiedetalj date uses position_date, not snapshot_date — the intent's own example "(FI 2 okt)" came from the 2026-10-03 fetch, so the 2 okt is FI's position date; snapshot_date would read 3 okt
  - `[low]` `[reject]` (intent) "Current" rule written twice (currentForIsins + shortedCondition) — both in src/Store sharing one threshold constant; consolidating needs a new repository API
  - `[low]` `[reject]` (intent) Nordnet Topplista badge untested — covered by the shared source-independent path (see test-gap row)
  - `[low]` `[reject]` (intent) DB-backed route tests unconfirmed due to collisions — verification-gap layer ran them green on an isolated DB, 0 skipped
  - `[low]` `[reject]` (verification-gap, other) Shared `stockpicker_test` DB collides under parallel runs — test-environment artifact of parallel review agents; `STOCKPICKER_TEST_DB_NAME` already exists
  - `[low]` `[reject]` (verification-gap) No verification gaps — informational

## Design Notes

- Badge colour: the memlog doesn't decide one. Quiet grey follows DESIGN.md's rules: red and green are reserved for deltas, amber for spikes, and brand for streaks. A high short interest is context to weigh, not a verdict, like the Plusdagar chip, where "the numbers carry the signal, not the colour".
- The FI date on Aktiedetalj is `position_date` (when FI's aggregate was last reported), not `snapshot_date` (our fetch date). That is what "FI 2 okt" means.
- `currentForIsins()` is called once per page render, never per row.

## Verification

**Commands:**
- `docker compose up -d && composer test` -- expected: all tests pass, with 0 skipped DB tests.

## Auto Run Result

- **Summary:** "Blankad X %" badge (≥ 5 %, latest FI snapshot by LEI) on Topplista (all modes, all sources), Fullständig lista, Bevakningslista and Aktiedetalj ("Blankad 15,8 % (FI 2 okt)", FI's position_date). "Dölj blankade" toggle in the period row in all three Topplista modes, `shorts=exclude` in URLs and the `topplista_view` cookie, filtered in SQL before LIMIT.
- **Files:**
  - `src/Store/ShortPositionRepository.php` — `BADGE_THRESHOLD_PCT`.
  - `src/Store/DerivedMetricsRepository.php` — `$excludeShorted` on the three top-N queries (NOT EXISTS on the latest snapshot).
  - `src/Web/LeaderboardController.php` — badge helpers, `shorts` view param, toggle, `.period-extras` wrapper.
  - `src/Web/{FullList,Watchlist,StockDetail}Controller.php` — badge (one lookup per render).
  - `public_html/index.php` — repo wiring, `shorts` from the resolved view.
  - `public_html/assets/app.css` — `.period-extras` layout and comments.
  - `src/Web/InfoController.php` — legend row and toggle copy.
  - `DESIGN.md` / `EXPERIENCE.md` — badge and toggle.
  - Tests in `tests/Web/LeaderboardControllerTest.php`, `tests/Store/DerivedMetricsRepositoryTest.php`, `tests/FrontControllerIntegrationTest.php`.
- **Review:**
  - 9 patched: medium 2 (narrow-screen layout, counted once per reviewer); low 7 (CSS comments ×2, threshold bind, docblock indentation, style drift, `serializeView` `??`, EXPERIENCE.md).
  - 1 deferred: stale-snapshot age limit (medium).
  - 12 rejected; reasons are in the triage log.
- **Follow-up review recommended:** false. The two medium patches are one root cause (the layout), so one medium entry was patched and none were high.
- **Verification:** `STOCKPICKER_TEST_DB_NAME=… composer test` → OK (737 tests, 10195 assertions, 0 skipped).
- **Residual risks:**
  - The 390px period-row layout is not checked in a browser.
  - Badges depend on LEI coverage (719/743 resolved) and on the first nightly `shorts` run succeeding under web-PHP.
