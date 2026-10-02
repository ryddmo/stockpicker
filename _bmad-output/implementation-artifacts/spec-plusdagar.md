---
title: 'Plusdagar — third Topplista ranking mode'
type: 'feature'
created: '2026-10-02'
status: 'done'
baseline_commit: 'a1056835673cf9c8c7ff9f122cc76d083214b0db'
route: 'dispatch'
review_loop_iteration: 0
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** "Stadig tillväxt" ranks only by `up_streak`, the number of consecutive days with a strict increase. A single flat or down day resets the streak to 0, and the instrument drops out of the ranking entirely. A company with a strong month and one bad day yesterday is invisible. Stefan wants to see it, and to see that it had a bad day ("then there is probably something going on").

**Approach:** Add a third Topplista ranking mode, **Plusdagar**, next to "Flest ägare" and "Stadig tillväxt". The mode has a period selector: **Vecka / Månad / 3 mån / År**, with **Månad** as the default. Decisions were made with Stefan on 2026-10-02 and are backed by a read-only prototype query against production data up to 2026-10-01.

- **Plus day:** a data day where `number_of_owners` is **unchanged or higher** than the previous stored row (flat days count).
- **Primary sort:** the **number** of plus days in the period, as a raw count rather than a share. This lets new listings (e.g. Dormy, listed 2026-09-24) appear in Vecka right away and climb in Månad as they collect days, with no special rule.
- **Secondary sort:** **new owners** in the period, in absolute numbers. Large caps winning ties is intended: Stefan considers them the safer companies.
- **Qualifier:** net new owners in the period **> 0**. Otherwise the instrument is not ranked. This stops flat-all-month companies (Bonäsudden +0) and net-negative ones (Binero −1) from ranking.
- **Display:** each row shows **"16/18"** (plus days / data days in the period) and the new owners. The denominator must always be visible.
- **Spikes:** **included by default**, with an opt-in toggle to exclude them. Note: `spike_score` needs 30 rows, so it is NULL for every instrument until about 2026-10-20. Until then the toggle changes nothing, which is correct and expected.
- No separate "also in Stadig tillväxt" marker: the existing 🔥 streak badge already says this.

## Boundaries & Constraints

**Always:**
- **Periods** are calendar windows, the same N as the period chips (spec-calendar-period-metrics): Vecka 7, Månad 30, 3 mån 90, År 365 days.
- **Window:** rows with `as_of_date` in `(D − N, D]`, where D = the source's latest `as_of_date`.
- **Data days:** a row in the window is a data day if it has a previous stored row within ≤ 5 calendar days (the same tolerance as `delta_1d`). If that delta is ≥ 0, the day is a plus day.
- **New owners:** owners on the instrument's latest row minus owners on the latest row on or before `D − N`. If no such row exists (a new listing), use the first row in the window instead.
- **Instruments:** only active instruments (`instrument.last_seen IS NULL`) are ranked.
- **Alla mode:** ranks on Avanza data, with Nordnet display-only (spec-5-4). Never sum the sources (NFR6).
- **SQL:** cast owner counts to SIGNED before subtracting. `owner_count_daily.number_of_owners` is unsigned, and the subtraction overflows (the prototype hit `SQLSTATE 22003`).
- **Placement:** all new SQL goes in a `DerivedMetricsRepository` method (AD-14).
- **Copy:** all copy is in Swedish (NFR12).
- **Links:** the period and the spike toggle are plain links (query params) with no JS (AD-12). Default values are omitted from the URL, following the `url()` convention.

**Never:**
- No migration and no new table or view: this is a read-time query over `owner_count_daily` plus the latest `owner_count_metrics` row for spikes.
- No backfill (NFR7).
- No change to "Flest ägare", "Stadig tillväxt", Fullständig lista, Bevakningslista or the e-mail digest.
- No share-based ranking.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected |
|---|---|---|
| One bad day in Månad | 15 plus days of 16, net +1 035 | Ranks at 15, shown as "15/16 · +1 035" |
| Flat all month | 16/16, net 0 | Not ranked |
| Net negative, many flat days | 15/16, net −1 | Not ranked |
| Tie on plus days | Two instruments at 5/5 | Higher new owners first; then isin ASC |
| New listing in Månad | First row 6 days ago, 5/5, from 0 → 4 602 | Ranked at count 5 (far down), shown "5/5" |
| Different data-day counts | 16/18 vs 16/16 | Equal primary key (16); tie-break on new owners |
| Gap > 5 days | Previous row 8 days back | That row is not a data day (not in numerator or denominator) |
| 3 mån / År with short history | Source history spans < N days | Empty state: "För lite historik för {period} ännu." |
| Spike toggle off (default) | Instrument spiking | Included |
| Spike toggle on | Latest `spike_score ≥ SPIKE_THRESHOLD` | Excluded; NULL score is never excluded |
| No qualifiers | Every instrument net ≤ 0 | Empty state: "Inga aktier med fler ägare under perioden." |
| Garbage params | `?ranking=plus&period=xyz&spikes=7` | Falls back to Månad, spikes included; never 500 |

</frozen-after-approval>

## Code Map

- `src/Store/DerivedMetricsRepository.php` -- new `topByPlusDays(string $source, int $days, bool $excludeSpikes, int $limit)`. Window functions over `owner_count_daily` (LAG per isin, ≤5-day gap check), joined to the latest `owner_count_metrics` row so `renderRow()`'s existing columns (sparkline basis, badges, delta chip, period pcts) stay available. Return `plus_days`, `data_days` and `new_owners` in addition. See Design Notes for the prototype query.
- `tests/Store/StoreTestCase.php` -- no schema change; add fixtures only.
- `src/Web/LeaderboardController.php` --
  - `RANKING_PLUS` constant; `render()` normalizes ranking, `period` (`vecka|manad|3man|ar`, default `manad`) and `spikes` (`exclude` or absent).
  - `rankingToggleHtml()` gets a third tab, "Plusdagar".
  - A new period selector and spike toggle, shown only in Plusdagar mode.
  - `url()` carries `period` and `spikes` (omitted when they are the default).
  - `emptyStateCopy()` gets the new states.
  - `renderRow()` shows "{plus}/{data} · +{new}" in Plusdagar mode, as a chip in `.badges` after the 🔥/flat badge. It reuses the quiet `.badge--nohist` grey (decided with UX on 2026-10-02). The delta chip keeps today's 1-day change.
  - Period UI: the active ranking tab reads "Plusdagar · {Period}". A line of plain links "Vecka · Månad · 3 mån · År" sits directly under the toggle, only in Plusdagar mode, with the active period in brand text. The same line holds the spike toggle, a checkbox-styled link "☐ Dölj spikar" / "☑ Dölj spikar". Each link has a ≥44px tap target. UX contract: `ux-stockpicker-2026-09-12/{DESIGN,EXPERIENCE}.md`.
- `public_html/index.php:93-97` -- pass `period` and `spikes` through, string-guarded like `ranking`.
- `public_html/assets/app.css` -- style for the plus-days chip and the period/spike controls, reusing `.tab`/`.ranking-toggle` patterns.
- `src/Web/InfoController.php` -- an "Plusdagar" section: definition, sort order, qualifier, the spike toggle, and the "16/18" denominator.
- Tests: `tests/Store/DerivedMetricsRepositoryTest.php` (every matrix row), `tests/Web/LeaderboardControllerTest.php`, `tests/FrontControllerIntegrationTest.php`.

## Tasks & Acceptance

**Execution:**
- [x] `DerivedMetricsRepository::topByPlusDays()` + store tests covering the matrix (Mon–Fri fixtures, including a gap > 5 days and a new listing).
- [x] `LeaderboardController` third mode, period selector, spike toggle, row chip, empty states.
- [x] `public_html/index.php` param pass-through.
- [x] CSS for the new controls and chip (390px and desktop).
- [x] `InfoController` copy.
- [x] Web + integration tests.

**Acceptance Criteria:**
- Given `/?ranking=plus`, when Topplista renders, then the Månad period is active and the rows are ordered by plus days, then new owners, with each row showing "x/y · +n".
- Given an instrument with one down day in a month of gains, when Plusdagar Månad renders, then it is listed (unlike Stadig tillväxt).
- Given net new owners ≤ 0, then the instrument never appears.
- Given `?spikes=exclude`, then instruments whose latest `spike_score ≥ SPIKE_THRESHOLD` are absent; without it they are present.
- Given `?source=alla`, then the ranking is Avanza-based and the Nordnet count is shown alongside, never summed.
- Given 3 mån before the history spans 90 days, then the "för lite historik" empty state shows.

## Design Notes

Prototype used for the 2026-10-02 decisions (read-only, run against production). It is simpler than the spec: it has no ≤5-day gap check, no baseline-before-window for new owners (it sums deltas), and no net > 0 qualifier. Use it as a starting shape, not as the final query.

```sql
WITH d AS (
  SELECT o.isin, o.as_of_date,
         CAST(o.number_of_owners AS SIGNED)
           - CAST(LAG(o.number_of_owners) OVER (PARTITION BY o.isin ORDER BY o.as_of_date) AS SIGNED) AS delta
  FROM owner_count_daily o WHERE o.source = 'avanza')
SELECT d.isin, i.name, SUM(d.delta >= 0) AS plus_days, COUNT(d.delta) AS data_days, SUM(d.delta) AS new_owners
FROM d JOIN instrument i ON i.isin = d.isin AND i.last_seen IS NULL
WHERE d.as_of_date > DATE_SUB(:max_date, INTERVAL 30 DAY) AND d.as_of_date <= :max_date
GROUP BY d.isin, i.name
ORDER BY plus_days DESC, new_owners DESC
```

Prototype findings that shaped the decisions:
- Vecka: 74 instruments tie at 5/5, so the tie-break on new owners decides. Large caps lead, which is accepted.
- Månad: 4 of 12 instruments at 15/16 had net ≤ 0, which led to the qualifier.
- Data days differ (Volvo B 18 vs typical 16). With count-based ranking that is a small, accepted advantage.

## Verification

**Commands:**
- `docker compose up -d && composer test` -- expected: green, with **no skipped** Store/integration tests.

**Manual checks:**
- Topplista → Plusdagar for each period, at 390px and desktop width.
- Compare Månad against the 2026-10-02 prototype top 15 (Volvo B, Karnell, Munters Group, Morrow Bank, FM Mattsson B … with Bonäsudden and Binero now excluded by the net > 0 rule).

## Spec Change Log

## Review Triage Log

Pass 1 (2026-10-02):

| # | Source | Finding | Verdict | Evidence / route |
|---|---|---|---|---|
| 1 | verification-gap | Source-switcher links keeping `spikes=exclude` are untested | medium | Pre-verified: dropping `$excludeSpikes` from `sourceSwitcherHtml()` passes every test → patch (test) |
| 2 | edge | Instrument whose only window row follows a >5-day gap renders "0/0 · +N" | low | Real, but it needs a lone window row after a gap *and* fewer than 10 instruments with ≥1 plus day before it reaches the top 10; the fix adds a qualifier the spec doesn't state → reject |
| 3 | edge+blind | `new_owners` baseline has no staleness tolerance (period pcts use N+5) | low | Real for an instrument that's absent for weeks and then returns. Code follows the frozen definition ("latest row on or before D − N"); the fix edits the spec → reject |
| 4 | edge | Source with zero rows shows "Inga aktier med fler ägare" | low | Needs a source with no stored data at all; never true in production → reject |
| 5 | edge | Månad/Vecka rank a partial window when history < N days | false | Intended: the frozen matrix gates only 3 mån/År, and Månad must work on today's ~23-day history (prototype comparison) |
| 6 | blind | EXPERIENCE.md Streak-badge note contradicts itself and drops the "no two-sided indicator" rule | low | Confirmed at the edited sentence; direct correction → patch (docs) |
| 7 | blind | New `<700px` CSS block separates the 700–899px comment from its rule | low | Confirmed in the app.css hunk; direct move → patch |
| 8 | blind | Spikes hiding every qualifier shows the "no gainers" copy | low | Needs every qualifying instrument to be spiking; spike_score is NULL for everything until ~2026-10-20. The fix adds a branch → reject |
| 9 | blind | "För lite historik för år ännu." reads badly | low | Confirmed: phrase 'år' gives "för år". Direct constant change to 'ett år' → patch |
| 10 | blind | Spike toggle state not exposed to screen readers | low | Real, but the app is single-user and the sole user doesn't use a screen reader; the fix adds markup and ARIA semantics → reject |
| 11 | blind | Alla-mode chip doesn't say it is Avanza-only | false | Same convention as every Alla-mode badge, sparkline and delta (spec-5-4: Avanza-derived, Nordnet display-only); nothing is summed |
| 12 | blind | Test comment "Today's (bad) day stays visible" contradicts its positive-chip assertion | low | Confirmed at FrontControllerIntegrationTest.php:872; direct comment fix → patch |
| 13 | blind | Info-page sample chip duplicates the markup without the nowrap parts | low | Confirmed at InfoController.php:115; direct reuse of `plusDaysChipHtml()` → patch |
| 14 | blind | Spec's manual check says top 15, UI shows 10 | low | The fix edits the spec → reject |
| 15 | blind | EXPERIENCE.md Topplista IA row still says "efter ägarantal" and omits the chip | low | Confirmed; direct correction → patch (docs) |
| 16 | blind | Ungated Månad with history < 30 days has no test | low | Deliberate behaviour and today's production state; a one-test addition → patch (test). Other listed gaps (stale `rn_latest`, Nordnet data, store-level garbage params) are negligible → reject |
