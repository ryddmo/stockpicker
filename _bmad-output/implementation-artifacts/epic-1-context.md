# Epic 1 Context: Tunn end-to-end-skiva — nattlig insamling bevisad på en seed-lista

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

Prove the whole nightly collection pipeline end-to-end against a hardcoded ~20-ISIN seed
list, with every architectural layer present but thin. After the epic there is real
owner-count data on disk — pulled nightly from Avanza and Nordnet, stored append-only as a
per-day time series, running unattended on Loopia against the seed list, every run leaving
an inspectable trace. The real Börsdata universe sync, full hardening, and derived metrics
come in Epics 2 and 3.

## Stories

- Story 1.1: Projektskelett och gemensam grund
- Story 1.2: Instrumenttabell, settings och seed-lista
- Story 1.3: SourceAdapter-port, feltyper och id-uppslag
- Story 1.4: Avanza-hämtningsadapter med schemakoll
- Story 1.5: Nordnet-hämtningsadapter med schemakoll
- Story 1.6: Append-only tidsserielagring
- Story 1.7: Work queue och tidsboxad FetchRunner
- Story 1.8: Minimal körningslogg
- Story 1.9: Cron-endpoints med token och kör-inte-före-tid
- Story 1.10: Loopia-driftsättning och deploy-runbook
- Story 1.11: End-to-end-röktest mot seed-listan

## Requirements & Constraints

- Runs entirely on Loopia shared hosting: PHP 8.3+ (shell is 8.5), MariaDB 10.11, no other
  runtime or database.
- Nightly work is triggered only by Loopia URL-cron (HTTP GET). No pipeline step may assume
  it finishes in one invocation; it must tolerate an execution-time limit and one cron
  instance at a time. URL-cron runs in the **web PHP** context — a slice must stay under
  that `memory_limit` (target 256 MB); `batch_size` is tuned so it fits.
- External calls are serial, throttled per source, with exponential backoff on 429. No
  parallel bulk calls.
- Personal use only: no component exposes fetched data outward, call volume stays low, cron
  endpoints require a secret token (compared with `hash_equals`).
- Storage is append-only and idempotent per `(isin, source, as_of_date)` — a re-run night
  yields the same end state, no existing row overwritten. No historical backfill.
- Every fact row carries `as_of_date` (calendar date in `Europe/Stockholm`, from the
  source timestamp when present — Nordnet's — else the run date) and `fetched_at` (UTC).
- Avanza and Nordnet owner counts measure different populations and are never summed.
- Secrets live in `config.php` outside `public_html/`, never committed; operational
  parameters live in a `settings` table and are read every run.
- The universe in this epic is a hardcoded list of ~20 ISIN. A changed or missing field in
  a source response throws `SchemaMismatch` rather than writing `null`; the run log
  (`ingest_run`) exists from the story `FetchRunner` is born in, not last; adapters do one
  retry on `Transient` so night one survives contact.

## Technical Decisions

- **Paradigm:** pipes-and-filters (`UniverseSync → Enqueue → FetchRunner → Normalizer →
  Deriver`) with ports-and-adapters for sources.
- **Adapter boundary:** all external source access goes through a `SourceAdapter`
  implementation in `src/Adapter/`. No `curl`/Guzzle, no source URL, no source-specific
  parsing anywhere else. `fetch()` returns a normalized row
  `{isin, source, as_of_date, number_of_owners, last_price, market_cap, fetched_at}` or a
  typed error `SchemaMismatch | NotFound | Transient`. The core acts only on those types.
- **Store boundary:** all DB access goes through PDO repositories in `src/Store/`
  (`InstrumentRepository`, `OwnerCountRepository`, `QueueRepository`, `RunRepository`,
  `SettingsRepository`). No SQL in pipeline or adapter code; no ORM. Fact storage is
  idempotent upsert keyed on `(isin, source, as_of_date)`.
- **Single writer per table:** `instrument` is written only by the universe path (the seed
  step in this epic); `owner_count_daily` is source-partitioned — each adapter flow writes
  only its own `source` rows.
- **Queue:** `work_queue` state machine `pending → claimed → done | failed`. Only `Enqueue`
  creates `pending`; only `FetchRunner` makes the other transitions. `FetchRunner` claims
  jobs atomically (`UPDATE ... WHERE status='pending' ... LIMIT n`), works a ~60–90 s
  timebox, exits clean. A `claimed` row older than `settings.queue.stale_after` reopens to
  `pending`. `/cron/refill` fills the queue; `/cron/work` drains it over repeated calls.
- **Tables:** `instrument`, `owner_count_daily`, `work_queue`, `ingest_run`, `settings` —
  `snake_case`, singular. ISIN is the natural key everywhere; Avanza `orderbook_id` /
  Nordnet `nnx_instrument_id` are cached nullable attributes on `instrument`, resolved once
  per instrument (never lazily in the fetch step).
- **Migrations:** Phinx. The first migration sets the canonical `settings` keys
  (`run_after`, `batch_size`, `rate.avanza`, `rate.nordnet`, `queue.stale_after`).
- **Layout:** `public_html/` thin front controller (`/cron/refill`, `/cron/work`,
  `/cron/derive`); `src/{Adapter,Pipeline,Store,Error}/`; `bin/` for SSH-run scripts;
  `db/migrations/`; `config.php` above `public_html/`; `vendor/`.
- **Deps:** `guzzlehttp/guzzle ^7.9 || ^8.0`, `monolog/monolog ^3.11`,
  `robmorgan/phinx ^0.16.12`; PHPUnit in `require-dev`. `composer.json` sets
  `require.php` to `>=8.3`.
- **Logging:** Monolog to a file outside `public_html/`; `warning` for a schema deviation,
  `error` for an unexpected exception.
- **Deploy:** `bin/deploy.sh` (already drafted) — rsync source over SSH to `~/stockpicker/`,
  `composer install --no-dev` on the server, `vendor/bin/phinx migrate -e production` run
  manually; subdomain docroot points at `~/stockpicker/public_html/`. Full runbook in
  `docs/deploy.md`.
- **Source endpoints** (unofficial, may change without notice): Avanza
  `GET /_api/market-guide/stock/{orderbookId}` → `keyIndicators.numberOfOwners`, only a
  `User-Agent` header. Nordnet `GET /api/2/instrument_search/query/stocklist` → header
  `client-id: NEXT`, `results[].statistical_info.number_of_owners` +
  `statistics_timestamp`, `results[].nnx_info.nnx_instrument_id`. Both expose ISIN. Avanza
  updates ~18–19 CET on weekdays only; Nordnet cadence is ~daily and uncertain — hence the
  configurable `run_after`.

## Cross-Story Dependencies

- 1.1 (skeleton) blocks every other story.
- 1.3 (port + error types + id resolution) blocks 1.4 and 1.5; 1.4 and 1.5 are independent
  of each other.
- 1.7 (`work_queue` + `FetchRunner`) depends on 1.2, 1.3, and 1.6, and is where the
  `ingest_run` logging of 1.8 is born — build them together.
- 1.9 (cron endpoints) depends on 1.7.
- 1.10 (deploy) needs a deployable skeleton; `bin/deploy.sh` and `docs/deploy.md` already
  exist as drafts to finish.
- 1.11 (end-to-end smoke test) depends on everything, including a deployed system from 1.10.
