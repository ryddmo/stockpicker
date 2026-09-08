<!-- bmad:context -->
<!-- Verified 2026-09-08 against 1869dfb. Managed by bmad-project-context; edits inside this block are replaced on refresh. Keep anything you want preserved outside the markers. -->

## stockpicker

Personal nightly data-collection engine: builds a per-day time series of Avanza and Nordnet
owner counts for Swedish Nasdaq Stockholm (LC/MC/SC) and First North stocks. PHP 8.3,
MariaDB 10.6, Composer, no framework; Guzzle, Monolog, Phinx. Runs entirely on Loopia
shared hosting. v1 is the collection engine only — no UI or analysis layer. The canonical
contract is `_bmad-output/specs/spec-stockpicker/SPEC.md` plus the architecture spine.

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
- Source endpoint paths, field names, and auth headers:
  `_bmad-output/planning-artifacts/briefs/brief-stockpicker-2026-09-08/addendum.md`
- Planned layout: `public_html/` thin front controller (`/cron/refill`, `/cron/work`,
  `/cron/derive`); `src/{Adapter,Pipeline,Store,Error}/`; `bin/` for SSH-run scripts;
  `db/migrations/` (Phinx); `config.php` outside webroot.

## Running and verifying

TODO — no code yet. Decided stack for when it lands:
- Setup: `composer install`. Deps: `guzzlehttp/guzzle ^7.9`, `monolog/monolog ^3.11`,
  `robmorgan/phinx ^0.16.12`.
- Tests: PHPUnit — add `phpunit/phpunit` to `require-dev`, run `vendor/bin/phpunit`.
- Migrations: `vendor/bin/phinx migrate` — run manually over SSH on Loopia, never wired
  into a cron endpoint or deploy step.
- Deploy: SSH + Composer; `vendor/` is built and uploaded.
- Nightly work is triggered only by Loopia URL-cron (HTTP GET) — no CLI cron, one instance
  at a time, execution-time limit unknown. No step may assume a single invocation finishes it.

## Conventions that differ from defaults

- All external source access goes through a `SourceAdapter` implementation in
  `src/Adapter/`. No `curl`/Guzzle, no source URL, no source-specific parsing anywhere
  else. (AD-1)
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
- ISIN is the natural key everywhere; Avanza/Nordnet ids are cached attributes on
  `instrument`. Tables `snake_case`, singular.
- `as_of_date` is a calendar date in `Europe/Stockholm` (from the source timestamp when
  present, else run date); `fetched_at` is always a UTC timestamp. Never conflate them.

<!-- /bmad:context -->
