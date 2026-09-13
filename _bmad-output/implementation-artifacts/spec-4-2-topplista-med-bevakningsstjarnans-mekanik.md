---
title: 'Topplista med bevakningsstjärnans mekanik (Leaderboard + Watchlist Star)'
type: 'feature'
created: '2026-09-13'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
context: ['{project-root}/AGENTS.md']
baseline_commit: '10448719fee1bf043093c7ccb86e0529411d7ea8'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Since Story 4.1, `/` is only a session-gated placeholder — Stefan still can't
see any collected data through the browser. He needs the real morning-coffee leaderboard:
top 10 instruments by owner count or trend quality, per source, with the visual signals
(streak/spike/sparkline/delta) that let him judge a real trend from a spike in seconds —
and a way to mark instruments worth following without leaving the page.

**Approach:** Replace the `/` placeholder with a real `LeaderboardController` backed by new
top-N queries on `DerivedMetricsRepository`, rendering the Leaderboard row markup per
`DESIGN.md` tokens with a Source switcher and Ranking-mode toggle. Introduce the `watchlist`
table + `WatchlistRepository` (first non-pipeline writer, AD-15) and a `POST
/watchlist/toggle` endpoint backed by a small hand-written `watchlist.js` — the one
JS-exception in the system (AD-12) — for the star's optimistic toggle.

## Boundaries & Constraints

**Always:** Reuse `require_session()`/`AuthController` from Story 4.1 as-is — no auth/session
changes. Source switcher defaults Avanza, Ranking-mode defaults "Flest ägare" — per-request
fallbacks only, never stored (AD-14). `/watchlist/toggle` validates the session cookie the
same way `require_session()` does; on invalid/expired session it returns `401` with a
minimal text body, never login HTML, so `watchlist.js` can tell a toggle failure from a dead
session (AD-12). All new SQL lands as new repository methods, never inline in `src/Web/`
(AD-14). Badge conditions follow Story 3.1's fixed view columns exactly: `up_streak >= 1`
for the Streak badge, `sma_7 IS NULL` for the muted/no-history Sparkline treatment. The
Spike badge triggers on `spike_score >= 2` (upward only — never flags a drop, decided
2026-09-13). "Stadig tillväxt" ranks by `up_streak` DESC alone, excluding any row with
`spike_score >= 2` from the ranking entirely (decided 2026-09-13) — no separate `sma_30`
slope calculation.

**Never:** Do not touch `src/Pipeline/`, `src/Adapter/`, or `/cron/*`. Do not build
Fullständig lista, Bevakningslista, or Aktiedetalj (Stories 4.3–4.5) — a row's link to
`/stock/{isin}` may 404 for now. No client framework or build step — `watchlist.js` is one
hand-written vanilla-JS file (AD-12). Do not persist source/ranking-mode choice server-side.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|---|---|---|---|
| Default view | `GET /`, valid session | 200, top 10 by owner count, Avanza, "Flest ägare" | N/A |
| Ranking = steady growth | `GET /?ranking=steady` | 200, ranked by `up_streak` DESC, rows with `spike_score >= 2` excluded | N/A |
| Source = Nordnet | `GET /?source=nordnet` | 200, top 10 for Nordnet, never merged with Avanza | N/A |
| `up_streak >= 1` | row render | Streak badge "🔥 {n}d" | N/A |
| `up_streak` 0 or `NULL` | row render | No-history badge's "flat" variant | N/A |
| `spike_score >= 2` | row render | Spike badge shown, independent of Streak badge | N/A |
| `sma_7 IS NULL` (<7 rows) | row render | Sparkline muted/dashed, "{n}d spårade · ingen trend än" | N/A |
| Steady-growth mode, zero qualifiers | `GET /?ranking=steady` | "Inga aktier med stadig tillväxt just nu." | N/A |
| Star toggle, valid session | `POST /watchlist/toggle` `{isin}` | 200, new starred state persists across reload | N/A |
| Star toggle, expired/invalid session | `POST /watchlist/toggle` | 401, minimal text body (no login HTML) | `watchlist.js` does a full navigate to `/login` |
| Star toggle, unknown isin | `POST /watchlist/toggle` `{isin: bogus}` | 404 JSON | N/A |

</frozen-after-approval>

## Code Map

- `public_html/index.php:62-73` — Story 4.1's `/` placeholder (`require_session()` then
  `render_html(200, render_placeholder_page())`). Replace the body with
  `LeaderboardController` construction + call, keep the `require_session()` guard and
  GET-only/405 check as-is.
- `public_html/index.php:302-320` — `require_session(Config): bool` (Story 4.1) — reuse
  unchanged; the same pattern (construct `AuthController`, check `$_COOKIE[...COOKIE_NAME]`)
  is what `/watchlist/toggle` should use for its own 401 check, but returning `401` +
  minimal text instead of rendering the login page (AD-12 explicitly forbids login HTML on
  this endpoint).
- `public_html/index.php:345-350` — `render_html(int, string): void` — reuse for the
  leaderboard page; `send_json()` (existing, used by cron routes) — reuse for
  `/watchlist/toggle`'s JSON responses.
- PDO wiring: no `$services['pdo']` — every route calls `Database::connect($services['config'])`
  inline, then constructs repositories from that `$pdo` (see e.g. `index.php:134`). Follow
  the same pattern for `DerivedMetricsRepository` and the new `WatchlistRepository`.
- `src/Store/DerivedMetricsRepository.php` — currently only single-isin lookups
  (`forIsinAndSource`, `forIsin`). Add `topByOwnerCount(string $source, int $limit): array`,
  `topByTrendQuality(string $source, int $limit): array` (latest `as_of_date` row per isin,
  joined against `instrument` for name/list, ordered per the resolved Open Question), and
  `recentSeries(string $isin, string $source, int $days): array` for the sparkline (last N
  `number_of_owners` values, oldest first).
- `owner_count_metrics` view columns (fixed by Story 3.1, `db/migrations/20260911170000_create_owner_count_metrics_view.php`):
  `isin`, `source`, `as_of_date`, `number_of_owners`, `delta_1d`, `pct_1d`, `sma_7`,
  `sma_30`, `sma_90`, `up_streak`, `spike_score` — all metric columns nullable until enough
  rows exist (`sma_7` needs 7, `sma_30`/`spike_score` need 30).
- `instrument` table (`db/migrations/20260908161500_create_instrument_and_settings.php`):
  `isin` PK, `name`, `list`, `avanza_orderbook_id`, `nordnet_instrument_id`, `first_seen`,
  `last_seen` (NULL = active). No `last_price`/`market_cap` here — not needed for this story.
- No `watchlist` table yet — this story's own migration. Match the existing FK-with-RESTRICT
  idiom in `db/migrations/20260909140000_create_owner_count_daily.php:16-28`; Phinx
  `up()`/`down()` style (no `change()` used anywhere in this repo).
- `tests/Store/StoreTestCase.php:82-269` — hand-mirrors every migration's schema in
  `createSchema()`/`dropSchema()` (line 141: "Keep this in step with the migration; there is
  no automated check.") — add the new `watchlist` table here too.
- HTML convention: no templating engine anywhere — `AuthController::renderLoginPage()`
  (`src/Web/AuthController.php`) is the only precedent, raw heredoc + inline
  `htmlspecialchars(...)`. `LeaderboardController` should build the ~10-row table the same
  way: a `foreach` producing per-row markup, escaping every dynamic field.
- `settings` table has no leaderboard/ranking keys — confirms source/ranking-mode really are
  per-request only, not settings-backed.

## Tasks & Acceptance

**Execution:**
- [x] `db/migrations/<ts>_create_watchlist.php` -- `isin` PK/FK `RESTRICT` against `instrument`, `starred_at` -- AD-15, first non-pipeline write path
- [x] `tests/Store/StoreTestCase.php` -- add the `watchlist` table to `createSchema()`/`dropSchema()` -- keeps the hand-mirrored test schema in step with the migration
- [x] `src/Store/WatchlistRepository.php` -- `starredIsins(): array<string>`, `toggle(string $isin): bool` (returns new starred state) -- only writer to `watchlist`
- [x] `tests/Store/WatchlistRepositoryTest.php` -- toggle on/off, starredIsins reflects state, toggling an unknown isin
- [x] `src/Store/DerivedMetricsRepository.php` -- add `topByOwnerCount()`, `topByTrendQuality()`, `recentSeries()` per Code Map
- [x] `tests/Store/DerivedMetricsRepositoryTest.php` -- cover both ranking modes against fixtures, the resolved spike threshold, and the zero-qualifiers steady-growth case
- [x] `src/Web/LeaderboardController.php` -- page render: badge selection, sparkline data, Source switcher + Ranking-mode toggle, empty-state copy
- [x] `tests/Web/LeaderboardControllerTest.php` -- badge/no-history selection logic in isolation from HTTP
- [x] `public_html/assets/watchlist.js` -- `fetch()` POST to `/watchlist/toggle`, update the star in the DOM on 200, full navigate to `/login` on 401
- [x] `public_html/index.php` -- wire `LeaderboardController` into `/`; add `POST /watchlist/toggle`
- [x] `tests/FrontControllerTest.php` / `tests/FrontControllerIntegrationTest.php` -- end-to-end: default view, `?source=`/`?ranking=` query handling, toggle success, toggle with expired session (401), toggle unknown isin (404) -- DB-dependent cases landed in the Integration variant (see Implementation Notes)

**Acceptance Criteria:**
- Given a valid session and no query params, when `/` is requested, then the top 10 instruments by owner count for Avanza render as Leaderboard rows with rank, star, name, badges, sparkline, owner count, and delta
- Given `?ranking=steady`, when `/` is requested, then rows are ordered per the resolved steady-growth formula and any row meeting the spike threshold never appears above a non-spiking row with a shorter streak
- Given `?source=nordnet`, when `/` is requested, then the same 10-row shape renders for Nordnet's data, never combined with Avanza's counts
- Given a valid session and an isin on the current page, when `POST /watchlist/toggle` is called, then the isin's starred state flips and persists across a subsequent `GET /`
- Given an expired or invalid session cookie, when `POST /watchlist/toggle` is called, then the response is `401` with a minimal text body, not the login page's HTML

## Implementation Notes

Implemented as specified, including both frozen decisions (spike threshold `>= 2` upward
only; steady-growth ranked by `up_streak` DESC with spiking rows excluded entirely).
`topByOwnerCount()`/`topByTrendQuality()` use a `ROW_NUMBER() OVER (PARTITION BY isin ORDER
BY as_of_date DESC)` filter for "latest row per isin," joined against `instrument` with
`last_seen IS NULL`, exactly per the Code Map/Design Notes. Badge/empty-state/delta-chip
logic is exposed as small `public static` pure functions on `LeaderboardController`
specifically so they're unit-testable without HTTP or a database.

One deviation from the Code Map, for a good reason: the DB-dependent end-to-end cases
(`/` rendering real data, watchlist toggle + persistence, unknown-isin 404) went into
`tests/FrontControllerIntegrationTest.php` — the repo's existing self-skipping DB+HTTP
convention — rather than into `tests/FrontControllerTest.php`, which is otherwise entirely
DB-independent. The old `testValidSessionShowsAuthenticatedPlaceholderNotLoginForm` (Story
4.1's placeholder-page test) was removed from `FrontControllerTest.php` with a comment
explaining the move; its "valid session renders real content, not a login form" assertion
is now covered by `testRootDefaultViewShowsTop10ByOwnerCountForAvanza` in the Integration
file instead. `FrontControllerTest.php` keeps only the DB-independent 401/405 cases for
`/watchlist/toggle`.

Verified independently (not just from the implementation report): full diff read against
`baseline_commit`, all 11 execution tasks and all 5 spec-level ACs confirmed against the
diff, all 11 I/O Matrix rows traced to a specific passing test, `composer test` re-run
(337 tests / 1607 assertions, clean) and `vendor/bin/phinx migrate -e development` re-run
(applies cleanly) independently.

**Review patch round:** 6 patch findings applied — `WatchlistRepository::toggle()` now
uses a transaction + `SELECT ... FOR UPDATE` against the double-toggle race; `watchlist.js`
disables the star button for the duration of its own request; the spike threshold is now a
single `DerivedMetricsRepository::SPIKE_THRESHOLD` constant bound into the SQL (no more
unsynced literal); both ranking queries gained an `isin ASC` tie-breaker; a delisted-
instrument toggle is now tested; and the Source-switcher/Ranking-toggle hrefs plus the
Sparkline's spike/positive stroke-class selection are now asserted at the integration
level. 1 finding deferred (`deferred-work.md` — hand-mirrored `watchlist` DDL has no
automated drift check, a pre-existing gap this story just extends). 2 findings rejected as
out of scope (CSRF — already mitigated by Story 4.1's `SameSite=Lax` cookie, verified
false; sparkline screen-reader equivalent and `watchlist.js`'s own test coverage — both
explicitly excluded by `EXPERIENCE.md`/AD-12). 1 finding rejected as low-impact with a
non-trivial fix (`recentSeries()` N+1, bounded to 10 queries/page on a personal-scale app).
Independently re-verified after patching: `composer test` (340 tests, 1619 assertions,
clean); `beginTransaction`/`FOR UPDATE`/`commit`/`rollBack`, the shared `SPIKE_THRESHOLD`
constant, both `ORDER BY ... isin ASC` clauses, and the three new test methods all
confirmed directly in the patched source.

## Spec Change Log

## Review Triage Log

- **`WatchlistRepository::toggle()` races on concurrent calls for the same isin, and the star button has no in-flight/disabled guard against rapid re-clicks** — `medium`, routes `patch`. Verified: `toggle()` does a `SELECT` then branches to `INSERT`/`DELETE` with no transaction or row lock; two near-simultaneous calls (double-click, two tabs) can both see "not starred" and both `INSERT`, the second throwing an uncaught `PDOException` (falls through to the shared 500 page, AD-14). `watchlist.js`'s `handleClick` has no debounce/disable, so it's the practical trigger for the race. Fix: wrap `toggle()` in a transaction with `SELECT ... FOR UPDATE`, and disable the star button in `watchlist.js` until its `fetch()` resolves. (Corroborated independently by blind-hunter [race + no in-flight guard] and edge-case-hunter [race].)
- **No CSRF protection on `POST /watchlist/toggle`** — `false`. Verified: the session cookie is set with `'samesite' => 'Lax'` in Story 4.1's `/login` handler (unchanged by this story) and carries that attribute for its lifetime. SameSite=Lax cookies are not sent on cross-site POST requests or any cross-site `fetch()`/XHR — only on top-level GET navigation — so the cross-origin forged-POST scenario the finding describes would arrive with no session cookie at all and get `401`, not succeed. The claimed bad outcome does not occur given the existing cookie configuration. (blind-hunter.)
- **`recentSeries()` is called once per rendered row (N+1-shaped, 10 extra queries per page load)** — rejected. Real but low-impact: `TOP_N` is a fixed 10 (not proportional to the ~740-instrument universe), each query is a small indexed lookup, and this is a personal single-user tool with low request volume (NFR4/NFR8) — unlikely to be met as a real problem in everyday use. The fix (batch all 10 isins into one query) is more than a direct correction — it requires restructuring `recentSeries()`'s signature and call sites. (Corroborated by blind-hunter and verification-gap's "Other findings.")
- **Spike threshold (`>= 2`) is duplicated as an unsynced literal** — `medium`, routes `patch`. Verified: `LeaderboardController::SPIKE_THRESHOLD = 2.0` and the raw `2` inside `DerivedMetricsRepository::topByTrendQuality()`'s SQL express the same rule with no shared source of truth — changing one without the other silently breaks the "a spiking instrument never outranks steady growers" guarantee both the code and this spec promise. Fix: derive the SQL literal from the same constant (e.g. bind it as a parameter from a shared source). (blind-hunter.)
- **Ranking queries have no deterministic tie-breaker** — `low`, routes `patch`. Verified: neither `topByOwnerCount()` (`ORDER BY number_of_owners DESC`) nor `topByTrendQuality()` (`ORDER BY up_streak DESC`) has a secondary sort key: instruments tied on the primary metric — plausible for `up_streak`, an integer — can reorder unpredictably between identical requests, which is visible flicker on a leaderboard Stefan checks daily. Fix: append `, isin ASC` to both `ORDER BY` clauses. (Corroborated by blind-hunter and edge-case-hunter, independently, for both queries.)
- **No request-body size guard on `/watchlist/toggle`** — rejected, out of scope. The session check runs before the body is ever read, so an unauthenticated request can't reach this path; `post_max_size`/`memory_limit` are already architecture-level constraints (NFR8, `docs/deploy.md`'s measured web-PHP limits). Adding an explicit application-level size guard is exactly the kind of hardening this personal, single-user tool's stated posture already excludes (same reasoning as Story 4.1's rejected throttling/lockout finding). (blind-hunter.)
- **No test covers toggling a delisted instrument** — `low`, routes `patch`. Verified: `WatchlistRepository::toggle()` only checks the FK (isin exists in `instrument`), never `last_seen` — a delisted instrument can still be starred/unstarred, which is the *intended* behavior (the migration's own docblock reasons that `instrument` rows are never deleted, so a legitimate delist must never be blocked by a watchlist FK). Nothing currently locks this intentional behavior in against a future accidental regression. Fix: add one test starring a delisted isin and asserting it succeeds. (blind-hunter.)
- **Hand-mirrored `watchlist` DDL in `tests/Store/StoreTestCase.php` has no automated drift check against the real migration** — defer. Verified, but pre-existing: this exact gap ("Keep this in step with the migration; there is no automated check") already applied to every prior table before this story; Story 4.2 only extends the same already-accepted convention to one more table, per its own Code Map's explicit instruction to match it. Fixing the whole pattern (all tables, not just this one) is out of scope for this story. (blind-hunter.)
- **Sparkline has no non-visual equivalent for its trend signal (spike/positive/negative color), only the muted/no-history case gets a text label** — rejected, out of scope. `EXPERIENCE.md`'s accessibility floor is explicit: "ingen dedikerad skärmläsargenomgång... (uttryckligen utanför scope)" — no dedicated screen-reader pass, stated as a deliberate exclusion, not an oversight. (blind-hunter.)
- **`watchlist.js` (the click handler, fetch call, and 401/DOM-update branching) has zero automated test coverage** — rejected, out of scope. Verified: no JS test tooling exists anywhere in the repo (confirmed by search), and AD-12 explicitly commits this project to "no build step, no framework" for the one hand-written JS file — standing up a JS test harness would itself violate that architecture decision for a single 71-line file. Risk (a silent client/server contract drift) is real and acknowledged, but not fixable within this story's constraints. (verification-gap; disposition filed as "defer" by the reviewing layer, re-routed here to reject-as-out-of-scope since the only real "fix" — JS tooling — is precisely what AD-12 excludes.)
- **Source switcher / Ranking-mode toggle's rendered `<a href>` targets are never asserted against `LeaderboardController::url()`'s actual output** — `medium`, routes `patch`. Verified (pre-verified by the filing layer per its evidence rules): every Story 4.2 integration test hand-types its own query string (`/?source=nordnet`, `/?ranking=steady`) rather than reading the links the controller itself renders — a regression in `url()`'s param names or the Avanza/Nordnet mapping would leave every test green while breaking the only way a user switches source/ranking in the browser. Fix: extend an existing integration test to assert the rendered Nordnet/Steady tab hrefs equal the expected query strings. (verification-gap.)
- **Sparkline stroke-class selection (`--spike`/`--positive`/`--negative`/`--nohistory`) is asserted nowhere at the integration level** — `medium`, routes `patch`. Verified (pre-verified per verification-gap's evidence rules): `LeaderboardControllerTest` only unit-tests the standalone `isSparklineMuted()` predicate, never the combined branch inside `sparklineHtml()` that actually picks the CSS class; no integration test inspects the emitted class either. A regression here (e.g. muted taking priority over a genuine spike) would leave every test green while silently showing the wrong trend color for every affected row — the page's stated "spikes are highlighted, not hidden" promise. Fix: extend the existing steady-growth/spike integration test to also assert the expected `sparkline-line--*` class string. (verification-gap.)

## Design Notes

Sparkline window: use the last 30 `number_of_owners` values (fewer if less history exists)
— matches `sma_30`'s own window, giving a visually meaningful trend without an extra
product decision.

`AD-14` (no per-controller custom error page) vs. `EXPERIENCE.md`'s "Kunde inte hämta
senaste data" copy: resolved as *not* a bespoke error page — `LeaderboardController` does
not wrap its `DerivedMetricsRepository` calls in a try/catch of its own. A genuine Store
failure (the local MariaDB is down) is a real engineering failure and correctly falls
through to the one shared generic error page per AD-14's explicit "never a per-controller
variant" rule. The "data fetch failed" empty state is not required by this story's own AC
list and isn't reachable in normal operation (the web layer only ever reads the already-
populated local DB, never a live external call).

`topByOwnerCount`/`topByTrendQuality` should each return the *latest* `as_of_date` row per
`(isin, source)` — a `ROW_NUMBER() OVER (PARTITION BY isin ORDER BY as_of_date DESC) = 1`
filter (or equivalent), joined against `instrument` for `name`/`list`, limited to `list`
(active instruments only — `last_seen IS NULL`).

## Verification

**Commands:**
- `composer test` -- expected: all PHPUnit tests pass, including new
  `WatchlistRepositoryTest`, `DerivedMetricsRepositoryTest` additions, and
  `LeaderboardControllerTest`, no skips beyond the existing DB-guarded ones
- `vendor/bin/phinx migrate -e development` -- expected: the new `watchlist` migration
  applies cleanly against the local Docker MariaDB

**Manual checks (if no CLI):**
- Run `php -S localhost:8080 -t public_html` against a local `config.php` with a seeded
  database; log in, confirm `/` shows a ranked list, toggle a star and reload to confirm it
  persists, switch source/ranking via query params and confirm the list reorders correctly.
