# Epic 2 Context: Live universum + överleva en månad oövervakad

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

Replace the hardcoded ~20-ISIN seed list with a real, self-maintaining instrument
universe, and harden the nightly pipeline for thirty nights of unattended operation.
The universe is fetched from Avanza's public stock listing (the same class of
unofficial, unauthenticated endpoint already used for owner counts — Börsdata was
dropped on 2026-09-10 because its API requires a paid Pro subscription). A nightly
`UniverseSync` reconciles the stored `instrument` table against that listing —
additions, delistings, list changes — and logs the churn. On top of that comes the
resilience the first weeks of real running demand: a broken instrument or a down
source never loses a whole night, transient failures retry with exponential backoff,
stuck `claimed` jobs reopen, the run log gains per-source success/failure and
schema-deviation counts, and a schema change raises a visible alarm rather than a
single log line. Story order is deliberate: live universe first, then hardening
stories shaped by what the first weeks actually throw. The feedback loop between
running and hardening lives inside this epic.

## Stories

- Story 2.1: Avanza-universumadapter (listning)
- Story 2.2: UniverseSync — daglig avstämning
- Story 2.3: Delfeltolerans och fellogg per instrument
- Story 2.4: Retry med exponentiell backoff
- Story 2.5: Återöppning av fastnade jobb
- Story 2.6: Full körningslogg och schemaavvikelse-larm

## Requirements & Constraints

- The universe is stored, never hardcoded. It covers Nasdaq Stockholm Large/Mid/Small
  Cap and First North, each mapped to a `LC | MC | SC | First North` label; non-target
  lists (Spotlight, NGM, foreign listings) and non-shares (ETFs, indices, certificates)
  are filtered out.
- Universe reconciliation runs as step one of the nightly job (`/cron/refill` runs
  `UniverseSync` then `Enqueue` for the active instruments; the seed list is gone): a
  new instrument (identified by its Avanza `orderbookId`, cached straight from the
  listing) has its ISIN resolved via `GET /_api/market-guide/stock/{orderbookId}`, is
  added with `first_seen` set, and its Nordnet id looked up; an instrument absent from
  the Avanza response gets `last_seen` set and is marked inactive (never deleted —
  history is kept); a list change updates `instrument.list`. Every reconciliation logs
  additions / removals / changes to `ingest_run` (or a dedicated row).
- A source response with a changed shape returns a typed `SchemaMismatch`; the universe
  adapter saves no partial list on a deviation.
- Partial-failure tolerance (must not regress from Epic 1): `NotFound` / `SchemaMismatch`
  on an instrument is logged per instrument, the job goes to `failed`, the runner
  continues, and a source failing every call in a slice must not block the other
  source's data. External calls stay strictly serial, throttled per source.
- Transient errors retry with exponential backoff up to a cap within the timebox; a
  `429` widens the backoff window and lowers the call rate against that source for the
  rest of the slice (`settings.rate.<source>`). Still-failing jobs are left `pending`
  for the next cron pass.
- A `claimed` row older than `settings.queue.stale_after` reopens to `pending` before
  new jobs are claimed; a normal slice leaves no `claimed` rows behind.
- After every run, `ingest_run` shows `ok_count` / `fail_count` per source and the
  `SchemaMismatch` count. At least one `SchemaMismatch` in a run raises a visible signal
  (alarm flag and/or email/notification), not just a log line; recent runs and their
  alarms are inspectable in one place.
- Success target: after a month of unattended running, an unbroken daily series for
  essentially the whole universe (at most a fraction of a percent missing); endpoint
  changes surface as an alarm, never as silent null data. Platform limits are unchanged
  from Epic 1 (Loopia shared hosting, PHP 8.3+ / MariaDB 10.11, URL-cron only in
  web-PHP context, no step assumes it finishes in one invocation, append-only idempotent
  storage keyed `(isin, source, as_of_date)`, secrets in `config.php` outside webroot,
  drift params in `settings`, cron endpoints token-guarded with `hash_equals`, personal
  use only).

## Technical Decisions

- **The universe adapter is a plain class, not a `SourceAdapter`.** `AvanzaUniverseAdapter`
  lives in `src/Adapter/` and exposes `listUniverse(): list<UniverseEntry>`. The owner
  adapters (`AvanzaAdapter`, `NordnetAdapter`) implement `SourceAdapter` with `fetch()`;
  the universe adapter does not — it has its own single-method shape. All Avanza HTTP,
  URL construction, and response parsing for the listing stay inside this class (AD-1);
  no `curl`/Guzzle or endpoint path outside `src/Adapter/`.
- **Endpoint verified (Story 2.1, 2026-09-10).** `POST /_api/market-stock-filter/stocks`
  (Avanza's Aktiescreener), one call per target list with a `marketPlaces` filter value.
  Response `{ stocks: [ { orderbookId, type: "STOCK", name, … } ], totalNumberOfOrderbooks, … }`
  — it carries **no `isin`** and **no cap-tier field**; the label comes from which query
  returned the row. Per-list counts 2026-09-10: LC 163, MC 141, SC 107, First North ~330.
  Full request/response shape in `addendum.md`. `GET /_api/market-guide/stock/{orderbookId}`
  carries `isin`, `name`, `marketList` — that is the per-instrument ISIN source Story 2.2
  calls.
- **`UniverseEntry` value object.** `{avanzaOrderbookId, name, list}` plus `LIST_*` consts
  — the source-agnostic VO from the superseded Börsdata Story 2.1, with its first field
  renamed from `isin` (the screener has none). Story 2.2 resolves ISIN separately.
- **Id resolution split.** Avanza's listing carries `orderbookId` directly (no Avanza id
  lookup). Story 2.2, per new instrument: one `market-guide/stock/{orderbookId}` call for
  the ISIN, then a Nordnet `nnx_instrument_id` search (Story 1.3's `resolveId` path), both
  cached on `instrument` — never looked up lazily in the fetch step.
- **No schema change for Nordnet ids.** `instrument.nordnet_instrument_id` is already
  `VARCHAR(64)` (migration `20260909140100`). The live value is a 36-char UUID; no
  widening migration is needed in this epic. If Nordnet's ISIN search keeps missing a
  few large caps (Handelsbanken A, Nordea, Epiroc A are known misses), hand-cache those
  ids with a direct `instrument` update or match on ticker/name.
- **Single writer per table holds (AD-3):** `instrument` is written only by
  `UniverseSync`. `FetchRunner` never resolves a missing id lazily — an instrument
  without an id is skipped and logged until the next `UniverseSync`. `owner_count_daily`
  stays source-partitioned; `Deriver` (Epic 3) only reads facts.
- **Retry cap (Story 2.4):** Epic 1's `FetchRunner` reopens a job on any `Transient`
  with no attempt counter, so a stuck source ping-pongs `pending ↔ claimed` every slice.
  Story 2.4 adds the attempt counter, backoff, and per-source rate reduction,
  superseding the Epic 1 one-shot adapter retry (FR9).
- **Run-log enrichment (Story 2.6):** Epic 1's `ingest_run` is one append-only summary
  row per slice. Story 2.6 adds per-source counts, the `SchemaMismatch` count, an alarm
  flag, and (per the spine ER diagram) a nullable `owner_count_daily.ingest_run_id` link
  (migration + FK, no backfill); `RunRepository` gains `start()` / `finish()` and
  `FetchRunner` threads the id into each `upsert()`.
- **Open decisions, resolve when their story is drafted:** alarm delivery mechanism (PHP
  mail vs webhook vs status-endpoint flag on Loopia shared hosting); log rotation and a
  configurable level for the single unbounded Monolog `StreamHandler`; a retention /
  pruning step for the unbounded `work_queue` and `ingest_run` tables. All fold into
  Story 2.6 or a dedicated chore.

## Cross-Story Dependencies

- 2.1 (Avanza universe adapter) blocks 2.2 (`UniverseSync` consumes its list). 2.1's
  live-verification step must land before 2.2 relies on the listing shape.
- 2.2 depends on Epic 1 Story 1.3 (id resolution port) and Story 1.7 (`Enqueue` /
  `work_queue`).
- 2.3, 2.4, 2.5, 2.6 all build on Epic 1 Story 1.7 (`work_queue` + `FetchRunner`) and
  Story 1.8 (`ingest_run` / `RunRepository`); they are largely independent of 2.1/2.2
  and are ordered by what real running surfaces first.
- 2.6 depends on 2.3 and 2.4 landing the per-source and schema-deviation data it
  reports, and provides the observability Epic 3's `/cron/derive` run assumes.
- First production deploy follow-ups from Epic 1 (Loopia web-PHP `memory_limit` /
  `max_execution_time` probe, URL-cron time limit and minimum interval, `batch_size`
  ceiling) should be settled before relying on unattended month-long operation.
