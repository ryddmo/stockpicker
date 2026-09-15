# Epic 5 Context: Post-launch-förbättringar av webb-UI

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

After Epic 4 went live (2026-09-13), Stefan hit six rough edges in real use: a mobile
layout bug clipping leaderboard-style rows, no link from a stock to its own Avanza
page, no explanation of what the site's pages/badges/ranking mean, a wish to see both
sources' owner counts side by side, a wish for percentage movement over more than
today's delta, and a wish for a daily email summary of top-10 changes. This epic
closes all six as six ordered stories, mostly in `src/Web/` (one also touches
`src/Pipeline/Deriver`; the email digest is a new, isolated step run after
`/cron/derive`). Unlike Epic 1–4, these requirements (FR20–FR25) come directly from
post-launch feedback in `epics.md` itself — they haven't been folded into `SPEC.md`,
`ARCHITECTURE-SPINE.md`, `DESIGN.md`, or `EXPERIENCE.md`, which remain the source for
established conventions but hold no Epic-5-specific content.

## Stories

- Story 5.1: Mobil layoutbugg på listornas rader
- Story 5.2: Länk till aktien på Avanza
- Story 5.3: Informationssida
- Story 5.4: Källäge "Alla" på Topplista
- Story 5.5: Procentuell utveckling över flera perioder
- Story 5.6: Daglig e-postdigest över förändringar i topp 10

## Requirements & Constraints

- Shared row component (Leaderboard/Full list/Watchlist) must render fully at ~390px
  with no clipping and no forced horizontal scroll — verified on all three pages.
- Stock detail gets a visible new-tab link to Avanza's own page, built from cached
  `avanza_orderbook_id`; silently omitted when no id is cached. The actual public
  stock-page URL pattern is **unverified** (only API endpoints exist in `addendum.md`)
  and must be confirmed against a real page first, same discipline as Story 2.1.
- A new information page (linked from the Leaderboard, not just reachable by URL)
  explains, in plain language, what each page shows, what each symbol means (🔥, ⚡,
  ☆/★, delta chip), and specifically what qualifies/orders "Stadig tillväxt".
- Source switcher gains a third mode "Alla", shown first (before Avanza/Nordnet), on
  the Leaderboard only (Full list/Watchlist unchanged). Each row shows both sources'
  counts separately, **never summed** (owner counts must never be merged — different
  populations). Ranking in "Alla" always uses Avanza as the basis (raw count or
  streak/spike); Nordnet's figure is display-only. Missing data from one source shows
  "no data", never a misleading zero.
- Leaderboard rows show % movement across day/week/90d/year, needing new `Deriver`
  columns `pct_7d`, `pct_90d`, `pct_365d` (alongside existing `pct_1d`), same
  "null until enough history" principle as `sma_7/30/90`.
- Daily email digest to `stockpicker@ryddmo.se`, sent once `/cron/derive` finishes,
  summarizing top-10 changes ("Flest ägare" and "Stadig tillväxt") vs. the previous
  **trading day**. Entries/exits listed as IN/UT; retained-but-moved entries show
  direction + rank (e.g. "Investor B ↑ #7→#4"). More than 5 changes in a list: first 5
  individually, rest as "+N till". No email/diff on a day the existing trading-day gate
  would skip (weekend/holiday). First-ever run (no prior trading day) skips sending
  rather than crashing.
- All UI stories keep existing conventions: Swedish throughout, plain/skeptical tone,
  ~44px tap targets, ~4.5:1 contrast, mobile-first layout. Digest copy should match the
  same tone even though it isn't a web page.

## Technical Decisions

- Stories 5.1–5.4 are UI-only in `src/Web/`. Story 5.5 is the only story changing
  `src/Pipeline/Deriver` (must follow Story 3.1's discipline: window functions, the
  existing `owner_count_metrics` view, no materialization, gap-aware nulls). Story 5.6
  also lands in the pipeline side as a **new, separate class** invoked after
  `/cron/derive`'s existing work — not a `Deriver` change — so it's a second, distinct
  exception to the "don't touch `src/Pipeline/`" boundary.
- Standing Epic 4 layering rules still apply: `src/Web/` and `src/Pipeline/` each call
  only `src/Store/`, never each other; no SQL outside `src/Store/`; no client framework
  or build step; `public_html/assets/watchlist.js` remains the only JS file. Stories
  5.1–5.5 introduce no new JS or write path.
- Story 5.3 needs no new repository/table — descriptive content plus a link in.
- Story 5.2 is presentation-only, consuming the already-cached
  `instrument.avanza_orderbook_id` — no new adapter call.
- Story 5.6 hooks into `public_html/index.php`'s existing `/cron/derive` case, after
  its current metrics-logging work, and reuses `cron_gate(Config $config)` — the same
  shared gate already used by `/cron/refill`, `/cron/work`, `/cron/derive`, which
  checks `run_after`, `run_weekdays`, then `TradingHolidayRepository::isHoliday()` in
  order. The digest class's own failures (e.g. failed SMTP send) must be caught and
  logged via `Logging::logger()` (Monolog to file, same as other cron-path errors)
  without affecting the rest of `/cron/derive`.
- SMTP credentials must never be hardcoded/committed. Implemented: they live in the
  untracked `config.php` array under a new `digest` section (`username`, `password`,
  `recipient`), read via a new `Config::digest()` typed accessor — the same discipline
  (never hardcoded, never committed, exposed via a typed accessor) as every other
  secret here (`cronToken()`, `sessionKey()`, etc.), not environment variables/`getenv()`.
- No SMTP/mail library is currently a dependency (`composer.json` has only
  `guzzlehttp/guzzle`, `monolog/monolog`, `psr/log`, `robmorgan/phinx`) — adding one, or
  sending raw SMTP, is an implementation-time decision the epic doesn't make.
- Per `docs/deploy.md`, all secrets are set by hand on the Loopia server (never
  rsynced/committed); `bin/deploy.sh`'s rsync already excludes `config.php` and `.env`
  by name, and the new `digest` section fits that same discipline — added by hand to
  production `config.php`, same as every other secret there.

## UX & Interaction Patterns

- Shared row component (rank, star, name + badges, sparkline, owner count + delta
  chip) must stay shared across all three consuming pages, not special-cased per page.
- Delta chip always shows count and percent together; Story 5.5 extends this to
  multiple horizons on the same row, with insufficient-history treatment matching the
  Stock detail range picker's existing pattern.
- Source switcher is a pill toggle, active tab inverted to solid dark background;
  Story 5.4 extends two segments to three ("Alla" first) without changing that visual
  language, and "Alla" must stay visually/behaviorally distinct from a single-source
  selection (never implying a merged total).
- Tone/microcopy discipline (plain, skeptical, never celebratory/gamified, emoji
  limited to 🔥/⚡) applies to all new copy in this epic, including the information
  page, new no-data/insufficient-history microcopy, and the email digest's text.

## Cross-Story Dependencies

- Story 5.1 touches the row component shared by Leaderboard, Full list, and Watchlist;
  must be verified against all three.
- Stories 5.4 and 5.5 both grow Leaderboard row content and are independent of each
  other, but both build on the layout Story 5.1 fixes — sequence 5.1 first.
- Story 5.2 depends only on `avanza_orderbook_id` already being cached (Epic 2).
- Story 5.3 should reflect Story 5.4's finished "Alla"-mode ranking rule, so it's
  naturally sequenced after (or written with knowledge of) 5.4.
- Story 5.6 is independent of 5.1–5.5 (reads `owner_count_metrics` directly, no UI
  dependency), but depends on `/cron/derive`'s existing metrics computation finishing
  first and on the already-in-place `cron_gate()` trading-day logic.
