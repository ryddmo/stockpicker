<!-- bmad:context -->
<!-- Verified 2026-09-11 against a211411. Managed by bmad-project-context; edits inside this block are replaced on refresh. Keep anything you want preserved outside the markers. -->

## stockpicker

Personal nightly data-collection engine: builds a per-day time series of Avanza and Nordnet
owner counts for Swedish Nasdaq Stockholm (LC/MC/SC) and First North stocks. PHP 8.3+
(Loopia's shell runs 8.5, web-PHP 8.4), MariaDB 10.11, Composer, no framework; Guzzle,
Monolog, Phinx. Runs entirely on Loopia shared hosting. v1 is the collection engine only —
no UI or analysis layer. The canonical contract is `_bmad-output/specs/spec-stockpicker/SPEC.md`
plus the architecture spine.

## Policy

- Never push to main; PRs only, one approval.
- Personal use is an architecture boundary, not a preference: no component exposes fetched
  data outward, call volume stays low, cron endpoints require a token. (AD-10)
- Never sum Avanza and Nordnet owner counts — they measure different populations and
  neither is the legal shareholder count. (NFR6)
- Secrets (DB credentials, cron token) live in `config.php` outside `public_html/`, never
  committed — keep `config.php` in `.gitignore`, ship `config.php.dist`. (AD-8)
- No historical backfill — the series starts at first run; sparse early history is the
  design, not a bug to fix. (NFR7)

## Where things are

- Canonical contract: `_bmad-output/specs/spec-stockpicker/SPEC.md` and
  `_bmad-output/planning-artifacts/architecture/architecture-stockpicker-2026-09-08/ARCHITECTURE-SPINE.md`
  — read the spine's invariants (AD-1…AD-11) before implementing a story.
- Epics and stories: `_bmad-output/planning-artifacts/epics.md`
- Source endpoint paths, field names, auth headers, incl. the verified Avanza
  universe-listing endpoint + response schema (Story 2.1):
  `_bmad-output/planning-artifacts/briefs/brief-stockpicker-2026-09-08/addendum.md`
- Layout: `public_html/` thin front controller (currently `/cron/refill`, `/cron/work`);
  `src/{Adapter,Pipeline,Store,Error}/`; `bin/` for SSH-run scripts; `db/migrations/`
  (Phinx); `config.php` outside webroot.
- Deploying to Loopia, and every tunable `settings.*` key with its default
  (`retry.*`, `universe.*`, `queue.stale_after`, `alarm.email`, …): `docs/deploy.md`
  (SSH/subdomain/DB setup, `bin/deploy.sh`, prerequisites).

## Running and verifying

Epic 1 is done and deployed (Loopia, ~20-ISIN seed list, first run 2026-09-10). Epic 2
(live Avanza-listing universe; fetch hardening — retry/backoff, stale-job recovery,
per-source run log + schema-mismatch alarm) is implemented and merged to `main`, pending
epic retro — **not yet deployed**: production still runs Epic 1's seed-list code until the
next deploy.
- Setup: `composer install`. Deps: `guzzlehttp/guzzle ^7.9 || ^8.0`, `monolog/monolog ^3.11`,
  `robmorgan/phinx ^0.16.12`; `phpunit/phpunit ^11` in `require-dev`.
- Tests: `composer test` (PHPUnit). DB-backed tests self-skip without the docker-compose
  MariaDB and phpunit does not fail on skips — a green run can hide skipped integration
  tests; `bin/deploy.sh` is guarded by `tests/DeployScriptTest.php` (no server needed).
- Inspect recent runs, per-source outcomes, and schema-mismatch alarms:
  `php bin/show-runs.php --alarms` (SSH).
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
- All DB access goes through PDO repositories in `src/Store/` — no SQL in pipeline or
  adapter code, no ORM. (consistency convention)
- One writer per table: `instrument` is written only by `UniverseSync`; each source's flow
  writes only its own `source` rows in `owner_count_daily`; `Deriver` reads facts, never
  writes them. (AD-3)
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

<!-- /bmad:context -->
