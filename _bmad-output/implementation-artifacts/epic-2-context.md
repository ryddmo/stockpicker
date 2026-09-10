# Epic 2 Context: Live universum + överleva en månad oövervakad

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

Replace the hardcoded ~20-ISIN seed list with the real Börsdata universe sync and harden
the pipeline for thirty nights of unattended operation. A `BorsdataAdapter` fetches every
Swedish instrument on Nasdaq Stockholm Large/Mid/Small Cap and First North; `UniverseSync`
reconciles the stored `instrument` table against it every night (additions, delistings,
list changes) and logs the churn. On top of that comes the resilience the first weeks of
real running demand: a broken instrument or a down source never loses a whole night,
transient failures retry with exponential backoff, stuck `claimed` jobs reopen, the run
log gains per-source success/failure counts and schema-deviation counts, and a schema
change raises a visible alarm rather than a single log line. Story order is deliberate:
live universe first, then hardening stories shaped by what the first weeks actually throw.
The feedback loop between running and hardening lives inside this epic.

## Stories

- Story 2.1: Börsdata-adapter för universumlistan
- Story 2.2: UniverseSync — daglig avstämning
- Story 2.3: Delfeltolerans och fellogg per instrument
- Story 2.4: Retry med exponentiell backoff
- Story 2.5: Återöppning av fastnade jobb
- Story 2.6: Full körningslogg och schemaavvikelse-larm

## Requirements & Constraints

- The universe is stored, never hardcoded. It covers Nasdaq Stockholm LC/MC/SC and First
  North Growth Market (Stockholm), each mapped to a `LC | MC | SC | First North` label.
- Universe reconciliation runs as step one of the nightly job: new ISIN added with
  `first_seen` set and Avanza/Nordnet id lookup triggered; ISIN absent from the Börsdata
  response gets `last_seen` set and is marked inactive (never deleted — history is kept);
  list changes update `instrument.list`. Every reconciliation logs additions / removals /
  changes.
- Börsdata is used only to define the universe, never for owner counts. It is a free-tier
  API with a key (in `config.php` or `settings`) and a call cap — a universe sync is a
  handful of calls per night. Free-tier coverage of the full universe is unverified and
  must be checked against the real API response.
- A Börsdata response with a changed shape returns `SchemaMismatch` and no partial list is
  saved.
- Partial-failure tolerance (must not regress): `NotFound` / `SchemaMismatch` on an
  instrument is logged per instrument, the job goes to `failed`, the runner continues, and
  a source failing every call in a slice must not block the other source's data. External
  calls stay serial.
- Transient errors retry with exponential backoff up to a cap within the timebox; a `429`
  widens the backoff window and lowers the call rate against that source for the rest of
  the slice (`settings.rate.<source>`). Still-failing jobs are left `pending` for the next
  cron pass.
- A `claimed` row older than `settings.queue.stale_after` reopens to `pending` before new
  jobs are claimed. A normal slice leaves no `claimed` rows behind.
- After every run, `ingest_run` shows `ok_count` / `fail_count` per source and the
  `SchemaMismatch` count. At least one `SchemaMismatch` raises a visible signal (alarm
  flag and/or email/notification), not just a log line; recent runs and their alarms are
  inspectable in one place.
- Success target: after a month, an unbroken daily series for essentially the whole
  universe with at most a fraction of a percent of missing datapoints; endpoint changes
  surface as an alarm, never as silent null data.

## Technical Decisions

- **Börsdata joins the existing adapter model:** `BorsdataAdapter` implements
  `SourceAdapter` in `src/Adapter/`, exposing a universe-listing call (`listUniverse()` or
  similar) alongside the `fetch()` contract. No HTTP, source URL, or source-specific
  parsing outside `src/Adapter/`. Börsdata endpoint paths and the label→list mapping
  (`marketId` / `branschId`, `markets` / `branches`) must be verified against the API wiki
  (github.com/Borsdata-Sweden/API/wiki).
- **Single writer per table holds:** `instrument` is written only by `UniverseSync` (via
  `BorsdataAdapter`), including the one-time lookup and caching of Avanza `orderbook_id`
  and Nordnet `nnx_instrument_id`. `FetchRunner` never resolves a missing id lazily — an
  instrument without an id is skipped and logged until the next `UniverseSync`.
  `owner_count_daily` stays source-partitioned; `Deriver` (Epic 3) only reads facts.
- **`/cron/refill` changes behaviour:** it now runs `UniverseSync` followed by `Enqueue`
  for the active instruments; the seed list is no longer used.
- **Nordnet id width:** the live `nnx_info.nnx_instrument_id` is a 36-char UUID, but the
  Epic 1 `instrument.nordnet_instrument_id` column is `VARCHAR(32)`. The story that first
  persists a Nordnet id (Story 2.2) must add a migration widening it (`CHAR(36)` /
  `VARCHAR(64)`) or switch to caching the integer `instrument_info.instrument_id` instead
  — the latter reopens Story 1.3's "return `nnx_instrument_id`" decision.
- **Börsdata as symbology source fixes known gaps:** the interim scraped
  search-by-ISIN resolver (`bin/resolve-ids.php` / `SourceIdResolver`) failed to resolve
  several correct-ISIN large caps (e.g. Handelsbanken A, Nordea, Epiroc A). Börsdata as
  the universe/symbology source in Story 2.1–2.2 is the proper fix.
- **Retry cap (Story 2.4):** the Epic 1 `FetchRunner` reopens a job on any `Transient`
  with no attempt counter, so a source stuck on `Transient` ping-pongs `pending ↔ claimed`
  every slice. Story 2.4 adds the attempt counter, backoff, and per-source rate reduction.
- **Run-log enrichment (Story 2.6):** Epic 1's `ingest_run` is one summary row per slice
  via an append-only `RunRepository`. Story 2.6 adds per-source counts, the
  `SchemaMismatch` count, an alarm flag, and — per the spine ER diagram — a nullable
  `owner_count_daily.ingest_run_id` link (migration + FK, no backfill: the series starts
  empty). `RunRepository` gains `start()` / `finish()` and `FetchRunner` threads the id
  into each `upsert()`.
- **Alarm delivery mechanism is an open decision** ("email/notis och/eller larm-flagga")
  — resolve when Story 2.6 is drafted. Loopia shared hosting: PHP mail vs a webhook vs a
  flag polled on a status endpoint.
- **Logging:** revisit the Epic 1 single unbounded `StreamHandler` at `Debug` — add
  rotation (`RotatingFileHandler`) and a configurable level as part of Story 2.6.
- **Retention:** `work_queue` and `ingest_run` accumulate rows forever (one per
  instrument per run-date; one per slice). Within NFR8 tolerance today but unbounded — a
  pruning step belongs with Story 2.6 or a dedicated chore.
- **Platform constraints unchanged from Epic 1:** Loopia shared hosting, PHP 8.3+ / MariaDB
  10.11, URL-cron only (web-PHP context, one instance at a time, no step assumes it
  finishes in one invocation), append-only idempotent storage keyed `(isin, source,
  as_of_date)`, secrets in `config.php` outside webroot, drift params in `settings`, cron
  endpoints token-guarded with `hash_equals`, personal use only.

## Cross-Story Dependencies

- 2.1 (Börsdata adapter) blocks 2.2 (`UniverseSync` consumes its list).
- 2.2 depends on Epic 1 Story 1.3 (id resolution port) and Story 1.7 (`Enqueue`), and
  must carry the Nordnet-id column-width migration before any Nordnet id is persisted.
- 2.3, 2.4, 2.5, 2.6 all build on Epic 1 Story 1.7 (`work_queue` + `FetchRunner`) and
  Story 1.8 (`ingest_run` / `RunRepository`); they are largely independent of 2.1/2.2 and
  are ordered by what real running surfaces first.
- 2.4 supersedes the Epic 1 one-shot adapter retry with the full backoff / rate-limit
  logic (FR9).
- 2.6 depends on 2.3 and 2.4 landing the per-source and schema-deviation data it reports,
  and provides the observability Epic 3's `/cron/derive` run assumes.
- First production deploy follow-ups from Epic 1 (Loopia web-PHP `memory_limit` /
  `max_execution_time` probe, URL-cron limits, `batch_size` ceiling) should be settled
  before relying on unattended month-long operation.
