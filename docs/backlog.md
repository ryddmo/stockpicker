# Backlog

Ad-hoc ideas, bugs, and improvements noticed outside a story's scope — jot them here as
you think of them so they aren't lost. Not a replacement for `deferred-work.md` (which
holds items deferred from a specific story's review); this is for anything that comes up
independent of a review pass.

## 2026-09-14

- **Admin interface / multi-user login.** Stefan (admin) can add and edit users; each
  user logs in with their own username/password and has their own favorites (today's
  `watchlist` is single-user, tied to no account). Conflicts with NFR5 ("en användare,
  en installation") as currently written — would need that NFR revisited, not just a
  feature added.
- **Bring in stock-analysis expertise.** Stefan wants a discussion partner who knows
  stocks/investing to help interpret the collected figures (owner-count trends,
  streak/spike/sma) before deciding what to build next — not a specific feature yet,
  more a "how should I think about these numbers" conversation.
- **FIXED 2026-09-15 — Mobile layout bug on Topplista's "Alla" source mode.** On a
  phone screen the accumulated owner-count number (e.g. "Avanza 534 01…") is clipped
  by the right edge of the screen instead of wrapping/shrinking — reported with a
  screenshot showing Investor B / Volvo B / SAAB B rows all cut off mid-number. "Alla"
  mode is Story 5.4; the mobile row-layout fix was Story 5.1, but that predates "Alla"
  and evidently doesn't cover this wider combined-source number. `.statcol`/`.stat` was
  `width: 70px; white-space: nowrap` with no wrap/shrink safety net (unlike `.name`'s
  ellipsis or `.sparkline-label`'s existing wrap treatment) — sized for a plain number,
  not `combinedOwnerCountText()`'s much longer "Avanza X · Nordnet Y" string. Widened
  `.statcol` to 100px and let `.stat` wrap (`white-space: normal; overflow-wrap:
  break-word`), mirroring `.sparkline-label`'s pattern. The text still wraps mid-number
  on narrow phones (a `combinedOwnerCountText()` format change to fix that would break
  its frozen-spec return value and the tests pinning it) but nothing is clipped/hidden
  any more — verified in a real Chrome window at 390px width. Single-source mode
  (short numbers) is unaffected. Scoped to `LeaderboardController.php` only — "Alla"
  mode doesn't exist on FullListController/WatchlistController despite them sharing
  the same duplicated `.statcol`/`.stat` CSS block.

## 2026-09-15

- **INVESTIGATED 2026-09-15, not a bug, left as-is — "Stadig tillväxt" (steady
  growth) filter returns an empty list for Avanza, but works for Nordnet.** On
  Fullständig lista, switching source to Avanza with "Stadig tillväxt" selected shows
  "Inga resultat för dessa filter." — Nordnet shows real rows under the same filter.
  Reported with two screenshots (Avanza empty vs. Nordnet populated).

  Root cause: `owner_count_metrics`'s `up_streak` is computed from `raw_delta`, which
  compares each row to the immediately preceding *row* (`LAG(...) OVER (PARTITION BY
  isin, source ORDER BY as_of_date)`) with no check that the two rows are actually 1
  calendar day apart — unlike `delta_1d`/`pct_1d`, which correctly null out across a
  gap. This is a **deliberate, human-confirmed frozen-spec decision**
  (spec-3-1-deriver-berakna-harledda-matt.md, 2026-09-11, reconfirmed on review): "up_streak"
  stays row-based specifically so it "tolerates the occasional missing day" — locked in
  by a passing test (`DerivedMetricsRepositoryTest::testGapLargerThanOneDayNullsDeltaAndPctButNotRowBasedMetrics`)
  that asserts a streak survives a 2-day gap.

  On production 2026-09-14/15 this backfired: Avanza has zero gaps (fetched every day,
  including weekends) and its real owner counts happened to be flat Sat/Sun/Mon —
  since there's no gap to hop over, that flat stretch *correctly* resets every single
  active instrument's streak to 0 (741/741 had `up_streak = 0`). Nordnet has actual
  missing rows over the same weekend, so the gap-tolerant design hops right over them
  and keeps counting whatever streak existed before the gap — the source with *more
  complete* data is penalized relative to the one with real gaps in it.

  Presented the tradeoff to Stefan (make `up_streak` gap-aware like `delta_1d`, which
  would very likely also shrink Nordnet's currently-inflated results) vs. leaving it —
  **decision: leave the frozen design as-is**, this is a temporary side-effect of a
  real flat stretch in Avanza's data that resolves itself once the count moves again,
  not a defect to fix.
- **FIXED 2026-09-15 — Add a Y-axis to Aktiedetalj's trend-overlay chart.** Right now
  the chart shows direction only (up/down/flat) with no scale — no numbers anywhere on
  the page tell you the actual owner count, just the line's shape. A few rounded tick
  labels (e.g. "530k / 532k / 534k") in recessive gray, no heavy gridlines, per the
  dataviz skill's convention (hairline recessive axes, clean rounded tick numbers,
  direct labels before gridlines). Skip an X-axis — the Dag/Vecka/30d/90d/År range tabs
  already convey the timeframe, and date labels would clutter a chart this narrow on
  mobile.

  Built as plain HTML/CSS, not SVG `<text>`: the chart's `<svg>` uses
  `preserveAspectRatio="none"` (stretches x/y independently to fill the container —
  fine for a line's shape, but it would non-uniformly squash/stretch SVG text on every
  viewport but the one matching the viewBox's exact aspect ratio). `StockDetailController
  ::yAxisTicks()` computes up to 3 anchors (max/mid/min of the *primary* slice's actual
  values, NFR6 — the secondary source never gets numbers) positioned by `top: n%` in a
  column the same height as the chart; `tickLabel()` rounds each to ~3 significant
  figures for display only ("532481" -> "532k"), never the position. One case found
  only by actually looking at a rendered chart (Volvo B's real dev data): a "Dag"
  slice's mid and min values can round to the *same* display label while sitting at
  very different heights — "210k" stacked twice reads as a mistake, not a clean axis.
  `dedupeAdjacentLabels()` drops the mid tick when its rounded label collides with
  either extreme; the two extremes always stay.
- **FIXED 2026-09-15 — Spike vs. negative trend colors too close to tell apart.**
  Flagged while fixing the Aktiedetalj secondary-line contrast above: `--spike-text`
  (#B54708) and `--negative` (#F04438), duplicated identically across Leaderboard,
  FullList, Watchlist, and StockDetail, failed the dataviz palette validator's
  normal-vision floor (Delta E 12.1, below the 15 threshold) — sparkline mini-charts
  carry no secondary encoding (no dash/icon, unlike the badges/delta chips which stay
  readable via icon/sign regardless), so a spiking stock's sparkline and a declining
  one could be hard to tell apart scanning down a list. Replaced `--spike-text` with
  `#7C5800` (deep gold/olive) in all four controllers — validated against every other
  trend color, and badge text-on-background contrast against `--spike-bg` still
  passes.
- **FIXED 2026-09-15 — weekday-only gating (dfe705e) still let full-day exchange
  holidays through, storing flat/noisy owner-count rows on days Nasdaq Stockholm
  never opened.** Stefan noticed spikes/pct-1d being messed up and suspected
  activity landing on non-trading days. `run_weekdays` already skips
  Saturday/Sunday, but a weekday holiday (Midsommarafton, Juldagen, ...) still
  passed that gate — the same "flat day resets streak / adds noise to sma_30 and
  spike_score" mechanism as the weekend contamination described in the "Stadig
  tillväxt" Avanza-empty note above, just for holidays. Delta alone can't signal
  a non-trading day (a real trading day can post delta 0; a closed day can still
  post a nonzero delta from settlement lag) — added a hand-maintained
  `trading_holiday` table instead (`TradingHolidayRepository`, migration
  `20260915120000_create_trading_holiday.php`, seeded with 2026's known Nasdaq
  Stockholm full closures) and gated all three cron endpoints on it alongside
  the existing weekday check (`holiday_skipped` status, mirrors
  `weekend_skipped`). No backfill migration for already-stored holiday/weekend
  rows — `sma_7`/`sma_30`/`spike_score` self-clean as new rows push old ones out
  of their rolling windows (up to ~90 trading days for `sma_90`), `up_streak`
  heals immediately since it only looks back to the nearest non-up row. One seed
  date (2026-01-06, Trettondedag jul) is flagged in the migration as worth a
  final check against Nasdaq's own notice — sources disagreed on whether it's a
  full closure or a half-day.
