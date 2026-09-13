# Epic 4 Context: Webb-UI — bläddra ägarantal-trender

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

On top of the time series and derived metrics built in Epics 1–3, this epic adds an
authenticated, mobile-first web UI so Stefan can browse, rank, filter, and watch stocks
based on owner-count trends himself, instead of reading the database directly. It turns
the collection engine into a daily-usable personal tool — a "morning coffee check" that
answers, in seconds, whether a movement is a real trend or just a spike.

## Stories

- Story 4.1: Inloggning (login — long-lived signed session)
- Story 4.2: Topplista med bevakningsstjärnans mekanik (leaderboard + watchlist star)
- Story 4.3: Aktiedetalj (stock detail — full history, both sources)
- Story 4.4: Fullständig lista (full list — search/filter/sort)
- Story 4.5: Bevakningslista-flik (watchlist tab)

## Requirements & Constraints

- Every route except `/login` requires a valid session; a missing/invalid session
  redirects silently to login (indistinguishable from a first-time visitor, no error).
  Login checks username/password with `password_verify()` against `config.php`; success
  sets an HMAC-signed cookie valid 30 days and lands on the leaderboard. No native PHP
  sessions, no multi-user, no registration/password reset. `/health` (the former public
  JSON check, previously at `/`) stays public, unauthenticated, unchanged response shape.
- Leaderboard: top 10 instruments for the active source, ranked by raw owner count
  ("Flest ägare", default) or trend quality ("Stadig tillväxt": high streak, uptrending
  `sma_30`, low spike score); each row shows delta, streak badge, spike badge when
  applicable, and a sparkline.
- Source switcher toggles Avanza/Nordnet across leaderboard, full list, and stock
  detail; the two sources' owner counts are never merged or summed anywhere.
- Full list: all ~740 tracked instruments for the selected source; full-text search,
  sort (name / owner count / % change), combinable filters (spike-flagged, steady
  growth, watchlisted only, market list).
- Watchlist: any row's star toggles watched status optimistically (no reload, no
  confirmation) and persists across sessions; a dedicated tab shows only watched
  instruments.
- Stock detail shows an instrument's full history and derived metrics for **both**
  sources at once (trend overlay: primary + secondary line), with a range picker
  (Day/Week/30d/90d/Year) whose middle three boundaries match backend `sma_7`/`sma_30`/
  `sma_90` windows exactly.
- UI language is Swedish throughout; tone is plain and skeptical, never hype/gamified.
- Mobile-first, responsive (~390px phone / ~900px laptop reference widths, tablet
  interpolates toward mobile rather than a third layout); dense-list interactive
  elements (star, tab bar, toggle segments) have a ~44px tap floor; muted text/badges
  target WCAG AA-ish contrast (~4.5:1). No formal a11y audit, screen-reader pass, or
  keyboard-nav requirement.
- No client framework or build step: views are server-rendered PHP, reloading on nearly
  every interaction — the one exception is a single hand-written vanilla JS file for the
  watchlist star's optimistic toggle.

## Technical Decisions

- **Layering:** a new `src/Web/` layer sits alongside `src/Pipeline/`; both call only
  `src/Store/`, never each other or SQL directly. New controllers: `AuthController`,
  `LeaderboardController`, `FullListController`, `WatchlistController`,
  `StockDetailController`. New repository: `WatchlistRepository` — the first write path
  in the system that isn't the nightly pipeline.
- **New table `watchlist`** (`isin` PK/FK `RESTRICT` on `instrument`, `starred_at`),
  owned and written only via `WatchlistRepository`; `RESTRICT` means `UniverseSync` can
  never hard-delete a watched instrument — delisting stays a status change, not a row
  deletion.
- **New routes** alongside `/cron/*`: `/login`, `/` (leaderboard, session-gated),
  `/list`, `/watchlist`, `/stock/{isin}`; `/` previously served the public JSON health
  check, which moves to `/health`.
- **Session mechanics:** no native sessions; a cookie with expiry (now + 30 days) and an
  HMAC signature keyed against a new `config.php` secret, same pattern as `cron_token`.
  Missing/invalid signature → silently show the login form, no error; valid signature
  but expired → login form with "Sessionen har gått ut. Logga in igen." `config.php`
  gains the login username, bcrypt password hash, and session HMAC key alongside
  existing DB credentials and `cron_token`.
- **Reload-based interaction, one exception:** nearly every interaction (range picker,
  source switcher, ranking mode, filters, sort, login) is a link or form POST that
  reloads the page. The watchlist star is the sole exception: it toggles via a
  `fetch()` POST from `public_html/assets/watchlist.js` (the only JS file in the
  system) to a toggle endpoint that returns `401` with a minimal text body (never login
  HTML) on an expired/invalid session, so the JS can distinguish a toggle failure from
  a dead session and fall back to a full page load to `/login`.
- **Query scoping:** `LeaderboardController`/`FullListController` scope to the source
  switcher's selection; `StockDetailController` always fetches **both** sources' full
  series regardless of switcher state — the switcher there only picks the primary line.
- **Defaults are per-request fallbacks, not stored state** (source=Avanza, ranking
  mode=Most Owners, range=Day, full-list sort=owner count descending), hardcoded in the
  relevant controller — no preference storage exists or is needed for one user.
- **Shared error handling:** an uncaught exception anywhere in `src/Web/` is caught by
  the same `catch (\Throwable)` block already wrapping the cron routes, logged via
  Monolog, rendering one common generic error page.
- **Deferred to implementation:** exact search/filter/sort SQL for the full list —
  lands as new `src/Store/` repository methods, never inline in `src/Web/`; exact
  `watchlist` schema/index detail beyond `isin`/`starred_at`.

## UX & Interaction Patterns

- Reuse the product's existing shared design tokens (colors, typography, spacing,
  radii, shadows) — no named UI framework, hand-built components. The brand accent is
  reserved for the wordmark, active ranking-mode tab, and streak badge only;
  positive/negative colors are strictly for deltas and trend direction; the spike badge
  uses a dedicated amber (never red/green) since a spike is a flag to verify, not a
  verdict, and can coexist on a row with a positive delta chip and a streak badge.
- **Leaderboard row** (shared by leaderboard, full list, watchlist tab): rank, watchlist
  star, name + badge row, sparkline, owner count + delta chip, left to right; name
  truncates with an ellipsis first. Tapping the row (except the star) navigates to stock
  detail; the star is an independent ~44px target that toggles watch status in place —
  no navigation, no confirmation.
- **Streak badge** "🔥 {n}d" shows only when `up_streak` ≥ 1, else the no-history badge's
  "flat" variant (there's no symmetric down-streak, so don't design a two-sided
  indicator). **Spike badge** "⚡ spike" is independent and can appear alongside the
  streak badge — never merged into one badge. **Sparkline** color is contextual
  (positive/negative/spike, muted+dashed when history is insufficient), larger on
  laptop than mobile.
- **Trend overlay** (stock detail only): primary line = active source with the
  sparkline's contextual color logic; secondary line = the other source, always a fixed
  color and dashed regardless of its own direction; a legend pairs line style to source
  name.
- **Source switcher:** two-way Avanza/Nordnet pill toggle; active tab inverts to a solid
  dark background — deliberately the highest-contrast control in the UI, since
  misreading the source is the one mistake this app must never make silently. Switching
  re-fetches/re-ranks without merging sources; ranking mode/filters persist across it.
- **Ranking-mode toggle** (leaderboard header) and **range picker** (stock detail,
  Day/Week/30d/90d/Year matching `sma_7`/`sma_30`/`sma_90`) share a distinct active-state
  pill family (white tab + brand text + shadow) so neither is confused with the source
  switcher's inversion. Changing range redraws the loaded instrument's chart or falls
  back to the insufficient-history state — never a partial or misleading line.
- **Delta chip:** always count and percent together ("+412 · 0,9 %"), never one alone;
  non-interactive, part of the row.
- Exact Swedish microcopy (plain, never hype) exists for: insufficient sparkline history
  (<7 rows), insufficient history for a chosen range (<30/<90 rows, with a "check back
  in {n} days" callback), empty watchlist, zero steady-growth qualifiers, no search/
  filter results (with a clear-filters action), login failure (no lockout message),
  expired session, and fetch failure (with a retry action) — see `EXPERIENCE.md`
  Tillståndsmönster for the exact strings.
- **Navigation model:** a persistent tab bar with only Leaderboard and Watchlist as
  top-level tabs; Full list is reached only via the leaderboard's footer action, never a
  tab; Stock detail is always a drill-in from a row tap, never a tab; Login sits
  entirely outside the tab bar.
- **Responsive:** single-column cards on mobile, reflowing to explicit grid columns
  (rank/star/name/owners/trend/delta/flag) at laptop width; source switcher and
  ranking-mode toggle stack as two rows under the header on mobile, sharing a header row
  with the page title on laptop. Pull-to-refresh on mobile; a visible manual refresh
  plus "last updated" timestamp on laptop (data updates once/day via cron, not live).
- No drag, swipe-to-delete, or long-press anywhere — every interaction is a single tap.

## Cross-Story Dependencies

- Story 4.1 (login/session) is a hard prerequisite for every other story — all other
  routes are session-gated.
- Story 4.2 introduces the `watchlist` table, `WatchlistRepository`,
  `WatchlistController`, and the `/watchlist/toggle` endpoint plus `watchlist.js`;
  Stories 4.3, 4.4, and 4.5 all reuse this same endpoint/script rather than building a
  second write path.
- Story 4.2's leaderboard row component (badges, sparkline, delta chip) is reused as-is
  by Story 4.4 (full list rows) and Story 4.5 (watchlist tab rows).
- Story 4.3 (stock detail) is the shared drill-in target for row taps from Stories 4.2,
  4.4, and 4.5 — same `/stock/{isin}` route regardless of origin.
- Story 4.4 depends on Story 4.2 for its entry point (the leaderboard's "Visa
  fullständig lista" footer action).
