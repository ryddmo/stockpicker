<!-- bmad:context -->
<!-- Verified 2026-09-14 against d24a343. Managed by bmad-project-context; edits inside this block are replaced on refresh. Keep anything you want preserved outside the markers. -->

## stockpicker

Personal nightly data-collection engine plus an authenticated web UI on top: builds a
per-day time series of Avanza and Nordnet owner counts for Swedish Nasdaq Stockholm
(LC/MC/SC) and First North stocks, and lets Stefan browse/rank/filter/watch instruments
by owner-count trend. PHP 8.3+ (Loopia's shell runs 8.5, web-PHP 8.4), MariaDB 10.11,
Composer, no framework; Guzzle, Monolog, Phinx. Runs entirely on Loopia shared hosting.
The canonical contract is `_bmad-output/specs/spec-stockpicker/SPEC.md` plus the
architecture spine.

## Policy

- Never push to main; PRs only, one approval.
- Personal use is an architecture boundary, not a preference: no component exposes
  data outward without authentication; the web UI (`src/Web/`) is single-user with no
  registration; cron endpoints require a token; call volume to external sources stays
  low. (AD-10)
- Never sum Avanza and Nordnet owner counts — they measure different populations and
  neither is the legal shareholder count; this holds even in the "Alla" source mode,
  which shows both side by side. (NFR6)
- Secrets — DB credentials, cron token, login username/bcrypt password hash, session
  HMAC key — live in `config.php` outside `public_html/`, never committed — keep
  `config.php` in `.gitignore`, ship `config.php.dist`. (AD-8, AD-13)
- No historical backfill — the series starts at first run; sparse early history is the
  design, not a bug to fix. (NFR7)

## Where things are

- Canonical contract: `_bmad-output/specs/spec-stockpicker/SPEC.md` and
  `_bmad-output/planning-artifacts/architecture/architecture-stockpicker-2026-09-08/ARCHITECTURE-SPINE.md`
  — read the spine's invariants (AD-1…AD-15) before implementing a story.
- Epics and stories: `_bmad-output/planning-artifacts/epics.md`
- Source endpoint paths, field names, auth headers, incl. the verified Avanza
  universe-listing endpoint + response schema (Story 2.1):
  `_bmad-output/planning-artifacts/briefs/brief-stockpicker-2026-09-08/addendum.md`
- Web UI design tokens, copy/interaction rules, and empty/error-state microcopy:
  `_bmad-output/planning-artifacts/ux-designs/ux-stockpicker-2026-09-12/{DESIGN.md,EXPERIENCE.md}`
- Layout: `public_html/` thin front controller — public: `/health`; token-gated:
  `/cron/refill`, `/cron/work`, `/cron/derive`; session-gated: `/login`, `/`
  (Topplista), `/list`, `/watchlist`, `/stock/{isin}`; plus `assets/watchlist.js`.
  `src/{Adapter,Pipeline,Store,Error,Web}/`; `bin/` for SSH-run scripts;
  `db/migrations/` (Phinx); `config.php` outside webroot.
- Deploying to Loopia, and every tunable `settings.*` key with its default
  (`retry.*`, `universe.*`, `queue.stale_after`, `alarm.email`, …): `docs/deploy.md`
  (SSH/subdomain/DB setup, `bin/deploy.sh`, prerequisites).
- Ad-hoc ideas/bugs noticed outside a story's scope: log them in `docs/backlog.md` as
  you think of them, rather than losing them.

## Running and verifying

All epics (1–5) are implemented, merged to `main`, and deployed to production (Loopia)
— live Avanza-listing universe, derived metrics, and the full web UI (login, Topplista,
Fullständig lista, Bevakningslista, Aktiedetalj, informationssida) are in daily use.
`sprint-status.yaml` lags behind merges (a story can show `review` after its PR is
merged and live) — trust `git log` and this section over it for current state.
- Setup: `composer install`. Deps: `guzzlehttp/guzzle ^7.9 || ^8.0`, `monolog/monolog ^3.11`,
  `robmorgan/phinx ^0.16.12`; `phpunit/phpunit ^11` in `require-dev`.
- Tests: `composer test` (PHPUnit). DB-backed tests self-skip without the docker-compose
  MariaDB and phpunit does not fail on skips — a green run can hide skipped integration
  tests; `bin/deploy.sh` is guarded by `tests/DeployScriptTest.php` (no server needed).
- Inspect recent runs, per-source outcomes, and schema-mismatch alarms:
  `php bin/show-runs.php --alarms` (SSH). Inspect one instrument's full series + derived
  metrics: `php bin/show-metrics.php --isin=...` (SSH).
- Migrations: `vendor/bin/phinx migrate -e production` — run manually over SSH; never in a
  cron endpoint or the deploy beyond its explicit step.
- Deploy: `bin/deploy.sh` — SSH-reachability + working-tree preflight, then rsync source
  over SSH (`-az --delete`, `config.php` / `vendor/` excluded), `composer install --no-dev`
  on the server, then the explicit `phinx migrate -e production` step (not FTP, not a local
  `vendor/` upload). Flags: `--with-local-vendor`, `--no-migrate`. Runbook: `docs/deploy.md`.
- Nightly work is triggered only by Loopia URL-cron (HTTP GET) — no CLI cron, one instance
  at a time. Web-PHP context: `memory_limit` 256M, `max_execution_time` 180s, URL-cron
  minimum interval 5 min (measured 2026-09-10). No step may assume a single invocation
  finishes it.

## Conventions that differ from defaults

- All external HTTP lives in `src/Adapter/` — owner-count sources behind the `SourceAdapter`
  port, the universe listing in its own adapter class (`AvanzaUniverseAdapter`,
  `listUniverse()`, not a `SourceAdapter`). No `curl`/Guzzle, source URL, or source-specific
  parsing anywhere else. (AD-1)
- All DB access goes through PDO repositories in `src/Store/` — no SQL in pipeline,
  adapter, or web code, no ORM. (AD-14)
- `src/Web/` and `src/Pipeline/` are parallel layers that never call each other — both
  call only `src/Store/`; all new ranking/filter/search/sort SQL lands as repository
  methods, never inline in a controller. An uncaught `src/Web/` exception is caught by
  the same shared front-controller handler as the cron routes — one generic error page,
  never a per-controller variant. (AD-14)
- One writer per table: `instrument` is written only by `UniverseSync`; each source's flow
  writes only its own `source` rows in `owner_count_daily`; `Deriver` reads facts, never
  writes them; `watchlist` is written only by `WatchlistController`/`WatchlistRepository`
  — the first write path in the system that isn't the nightly pipeline. (AD-3, AD-15)
- Fact storage is idempotent upsert keyed on `(isin, source, as_of_date)` — a re-run night
  yields the same end state, no existing row overwritten. (AD-4)
- Adapter `fetch()` returns a normalized row or a typed error
  (`SchemaMismatch | NotFound | Transient`) — the core acts only on those types, never on
  raw exceptions or status codes. A missing/changed field is `SchemaMismatch`, never a
  null value passed downstream. (AD-2, AD-7)
- `FetchRunner` claims queue jobs atomically and works a ~60–90s timebox; `work_queue`
  goes `pending → claimed → done | failed`, only `Enqueue` creates `pending`, only
  `FetchRunner` makes the other transitions. (AD-5)
- `FetchRunner` owns all `fetch()` retry (exponential backoff, timeboxed) — an adapter's
  `fetch()` must never wrap itself in `withOneRetry()`; only `resolveId()` and the
  universe-listing calls keep the one-shot retry. (Story 2.4)
- Stale `work_queue` recovery is run-date-aware: a `claimed` row stuck past
  `queue.stale_after` reopens if it is today's run, or is marked `failed` (no backfill) if
  from an earlier run — never left `claimed` forever. (Story 2.5)
- ISIN is the natural key everywhere; Avanza/Nordnet ids are cached attributes on
  `instrument`. Tables `snake_case`, singular.
- `as_of_date` is a calendar date in `Europe/Stockholm` (from the source timestamp when
  present, else run date); `fetched_at` is always a UTC timestamp. Never conflate them.
- Web UI renders server-side in PHP — no client framework, no build step. The one
  exception is `public_html/assets/watchlist.js` (a handwritten `fetch()` POST toggling
  the watchlist star) — introduce no other client-side JS. (AD-12, NFR10)
- Session auth is a stateless HMAC-signed cookie (30-day TTL), not PHP-native sessions —
  a missing or invalid/tampered-signature cookie is always silently redirected to
  `/login` (never distinguishable from a first visit); only a valid-signature-but-expired
  cookie gets a "session expired" message. (AD-13)
- UI copy — labels, errors, dates — is Swedish throughout; never introduce English UI
  text. (NFR12)

## Known pitfalls

- Leaderboard-style row CSS (`.row-body`/`.namecol`/`.trend`/`.statcol`/etc.) is
  hand-duplicated identically across `LeaderboardController.php`,
  `FullListController.php`, and `WatchlistController.php` (no shared CSS file) — a row
  layout change must be made in all three, verified against all three.
- `tests/Store/StoreTestCase.php::createSchema()` hand-mirrors every table's DDL instead
  of running the real Phinx migrations — a migration-only schema change (new column,
  index, type) can pass the store test suite silently while drifting from production
  schema; update the mirror whenever a migration changes a table.

<!-- /bmad:context -->
