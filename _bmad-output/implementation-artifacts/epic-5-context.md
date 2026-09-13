# Epic 5 Context: Post-launch-förbättringar av webb-UI

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

After Epic 4 went live (2026-09-13), Stefan hit five rough edges in real daily use of
the web UI: a mobile layout bug that clips leaderboard-style rows, no way to jump from
a stock to its own Avanza page, no explanation of what the site's pages/badges/ranking
mean, a wish to see both sources' owner counts side by side instead of switching
back and forth, and a wish for percentage movement over more than just today's delta.
This epic closes all five gaps as five ordered stories in `src/Web/` (one of them also
touches `src/Pipeline/Deriver` for new computed columns). Note: unlike Epic 1–4, these
requirements (FR20–FR25) come directly from post-launch user feedback captured in
`epics.md` itself — they have not yet been folded into `SPEC.md`, `ARCHITECTURE-SPINE.md`,
`DESIGN.md`, or `EXPERIENCE.md`. Those documents remain the source for established
conventions the stories below must still follow, but contain no Epic-5-specific content.

## Stories

- Story 5.1: Mobil layoutbugg på listornas rader
- Story 5.2: Länk till aktien på Avanza
- Story 5.3: Informationssida
- Story 5.4: Källäge "Alla" på Topplista
- Story 5.5: Procentuell utveckling över flera perioder

## Requirements & Constraints

- The shared row component used by Leaderboard, Full list, and Watchlist tab must
  render fully at the ~390px mobile reference width with no clipping and no forced
  horizontal scroll — owner count, delta chip, and sparkline label must all stay
  visible. The fix is verified on all three pages that share the component, not just
  the leaderboard.
- Stock detail needs a visible link to the instrument's own page on Avanza, built from
  its cached `avanza_orderbook_id`; opens in a new tab so the authenticated session is
  never left. An instrument without a cached `orderbookId` silently omits the link
  rather than showing a broken one. The actual Avanza public stock-page URL pattern is
  **unverified** — only API endpoints are documented in `addendum.md`, never a page
  URL — so it must be confirmed against a real page before the link is built, with the
  same verification discipline used for the Story 2.1 universe endpoint.
- A new information page, reachable via a visible link from the Leaderboard (not only
  by direct URL), explains in plain (non-technical) language what each page
  (Leaderboard, Full list, Watchlist, Stock detail) shows, what each symbol means
  (streak 🔥, spike ⚡, watchlist ☆/★, delta chip), and specifically what qualifies an
  instrument for "Stadig tillväxt" ranking and how it's ordered.
- The source switcher gains a third mode, "Alla" ("All"), shown first — before
  Avanza and Nordnet — on the Leaderboard only; Full list and Watchlist are explicitly
  unchanged by this story. In "Alla" mode each row shows both sources' owner counts
  separately (e.g. "Avanza 1 234 · Nordnet 567") — **never summed**, preserving the
  standing rule that Avanza's and Nordnet's owner counts must never be merged into one
  figure (different populations, neither is the legal shareholder count). Ranking in
  "Alla" mode always uses Avanza as the ranking basis (both for "Flest ägare" raw count
  and "Stadig tillväxt" streak/spike criteria) — Nordnet's figure is display-only, never
  a sort key. An instrument missing data from one source shows that source as "no data"
  rather than a misleading zero.
- Leaderboard rows show percentage movement across multiple horizons — day, week, 90
  days, year — not just today's delta. This requires new derived columns `pct_7d`,
  `pct_90d`, `pct_365d` (alongside the existing `pct_1d`) computed by `Deriver`, using
  the same "null until enough history exists" principle already used for
  `sma_7`/`sma_30`/`sma_90` — a period without enough rows renders a clear
  "insufficient history" mark, never a misleading number.
- All five stories stay within the existing product conventions: Swedish UI language
  throughout, plain/skeptical tone (never hype or gamified copy), ~44px tap targets,
  ~4.5:1 contrast target for muted text/badges, mobile-first responsive layout.

## Technical Decisions

- Four of the five stories are UI-only work in `src/Web/`; Story 5.5 is the **only**
  story in this epic allowed to touch `src/Pipeline/Deriver` — every other "don't touch
  `src/Pipeline/`" boundary from Epic 4 still applies to Stories 5.1–5.4.
- Story 5.5's `Deriver` change must follow the same discipline already established for
  derived metrics (Story 3.1): MariaDB window functions, computed via the existing
  `owner_count_metrics` SQL view (no materialization, no refresh logic), gap-aware null
  handling per instrument/source.
- Standing layering rules from Epic 4 still apply: `src/Web/` and `src/Pipeline/` each
  call only `src/Store/`, never each other; no SQL outside `src/Store/` (any new
  query/filter logic lands as repository methods, never inline in a controller); no
  client framework or build step — views are server-rendered PHP, and
  `public_html/assets/watchlist.js` remains the system's only JavaScript file (the
  watchlist star is still the sole exception to full-page-reload interaction). None of
  Epic 5's five stories introduce new JS or a new write path.
- The information page (Story 5.3) is a new authenticated, read-only route rendered
  the same server-side way as existing pages — it needs no new repository or table,
  just descriptive content plus a link to it from the Leaderboard.
- The Avanza stock-page link (Story 5.2) is presentation-only: it consumes the already
  cached `instrument.avanza_orderbook_id` (populated by `UniverseSync`/id-resolution in
  Epics 1–2) — no new adapter call or stored field is implied beyond building and
  verifying the URL pattern itself.

## UX & Interaction Patterns

- The leaderboard/full-list/watchlist row is a shared component (established in Epic
  4): rank, watchlist star, name + badge row, sparkline, owner count + delta chip.
  Story 5.1's fix must preserve this shared component across all three consuming pages
  rather than special-casing one page's layout.
- Delta chip convention carries over: always show count and percent together, never
  one alone. Story 5.5 extends this pattern to multiple time horizons on the same row
  rather than replacing it — each period gets a clear insufficient-history treatment
  when its window exceeds available data, matching how the range picker already
  handles insufficient history on Stock detail.
- Source switcher is a pill-style toggle with the active tab inverted to a solid dark
  background (the highest-contrast control in the UI, so misreading the active source
  is never silent); Story 5.4 extends it from two segments to three ("Alla", Avanza,
  Nordnet) without changing this visual language, and "Alla" must remain visually and
  behaviorally distinguishable from picking a single source (never implying a merged
  total).
- Tone/microcopy discipline (plain, skeptical, never celebratory or gamified,
  emoji limited to the fixed 🔥/⚡ badge glyphs) applies to all new copy introduced by
  this epic, including the information page's explanatory text and any new
  insufficient-data/no-data microcopy for "Alla" mode and multi-period percentages.

## Cross-Story Dependencies

- Story 5.1 touches the same shared row component consumed by Leaderboard, Full list,
  and Watchlist (all built in Epic 4); it must be verified against all three, not
  developed against the Leaderboard alone.
- Story 5.4 ("Alla" mode) and Story 5.5 (multi-period %) both render on Leaderboard
  rows and are independent of each other, but both build on the row layout that Story
  5.1 is fixing — sequencing 5.1 before 5.4/5.5 avoids fixing the same clipping issue
  twice as row content grows (more percentages, dual-source counts).
- Story 5.2 depends on `avanza_orderbook_id` already being cached per instrument
  (established by `UniverseSync` in Epic 2) and needs no epic-internal dependency.
- Story 5.3 (information page) should reference the finished behavior of Story 5.4
  ("Alla" mode) and the existing "Stadig tillväxt" ranking criteria accurately, so it
  is naturally sequenced after (or written with knowledge of) 5.4's final ranking rule
  for "Alla" mode.
