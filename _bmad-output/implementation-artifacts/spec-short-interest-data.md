---
title: 'Short-interest data: FI blankningsregister snapshot + instrument LEI'
type: 'feature'
created: '2026-10-03'
status: 'done'
baseline_revision: '3eefdb4808946b94659c85783e88fc1e01cf2824'
review_loop_iteration: 0
followup_review_recommended: true
context: []
warnings: []
deferred:
  - summary: >-
      AGENTS.md doesn't list the short_position writer (ShortPositionSync), the instrument.lei writer (UniverseSync via GLEIF), the FI/GLEIF adapters, or the topplista_view cookie.
    evidence: |-
      AGENTS.md's "One writer per table" and "Layout" sections predate this change, so future agents could add a second writer or an adapter outside the conventions.
    location: >-
      AGENTS.md
    severity: low
---

<intent-contract>

## Intent

**Problem:** Stefan wants to flag Topplista companies that are heavily shorted by professionals (decided 2026-10-03). The app has no short-interest data.

**Approach:** This spec is the **data half only**. The badge and the "Dölj blankade" toggle follow as a separate spec, logged in deferred-work.md.
- **Short positions:** fetch Finansinspektionen's aggregated short-position file nightly and store it as a dated snapshot. It is an ODS with issuer name, **LEI**, position % and position date per row, and **no ISIN**.
- **LEI per instrument:** resolve each instrument's LEI via GLEIF by ISIN, so later UI work can join instruments to short positions.
- **Read method:** expose a repository read giving each active ISIN's current short position, for that UI work to call.

## Boundaries & Constraints

**Always:**
- **Adapters (AD-1):** all FI and GLEIF HTTP and parsing live in `src/Adapter/` behind small ports. They return normalized rows or the typed errors `SchemaMismatch | NotFound | Transient`. A missing or changed column, or an unparseable number or date, is a `SchemaMismatch`, never a null.
- **Writers (AD-3):** `instrument.lei` is written only by `UniverseSync`, as a timeboxed, throttled LEI pass after the Nordnet id pass, sharing its deadline. `short_position` is written only by `ShortPositionSync`.
- **Idempotency (AD-4):** storage is an upsert keyed on `(snapshot_date, lei)`, where `snapshot_date` is the Stockholm run date. A re-run night ends in the same state.
- **Current position:** the rows of the **latest snapshot** only. An issuer absent from the latest file has no current position, even if an older snapshot has one.
- **Failures never break the night:** the FI step runs once per night as an isolated step at the tail of `/cron/derive`, inside the existing once-per-day guard and in its own try/catch.
  - It records an `ingest_run` row (run type `shorts`) and alarms on `SchemaMismatch`, as other steps do.
  - Its failure never changes the route's response or blocks the digest.
  - A failed night leaves the previous snapshot current.
- **Call volume:** low. One FI download per night (one-shot retry allowed); GLEIF only for active instruments missing a LEI, throttled by `rate.gleif` (default 2 req/s).
- **Missing PHP extension:** a missing `ZipArchive` or XML extension is reported as a `SchemaMismatch`-class alarm, never a fatal error or a 500.

**Never:**
- No ISIN/issuer **name** matching: LEI only.
- No backfill (NFR7).
- No UI change in this spec.
- No new Composer dependency. Use `ext-zip` and `ext-xmlreader`/SimpleXML, which are present on Loopia CLI PHP 8.5.9.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Normal night | FI ODS with rows "Elekta AB (publ) / 54930044O54BK617EP80 / 16,05 / 2026-10-02" | One `short_position` row per LEI for today's `snapshot_date` (16.05, position_date 2026-10-02); `ingest_run` `shorts` ok | — |
| Re-run same night | Same file again | Same end state, no duplicates | — |
| Dropped out | LEI in yesterday's snapshot, absent today | Current-position read returns nothing for it | — |
| Share classes | Two ISINs (A/B) with the same LEI | Both get that issuer's current position from the read | — |
| LEI resolved | Active instrument, `lei` NULL, GLEIF returns `data[0].id` | `instrument.lei` set (write-once) | — |
| No LEI at GLEIF | Empty `data` | `lei` stays NULL; retried next run | NotFound tallied, not alarmed |
| Timebox spent | Deadline passed during the LEI pass | Remaining instruments deferred to the next run | — |
| Changed FI format | Header lacks "LEI" or "Position i procent" | Nothing written; run alarmed | `SchemaMismatch` |
| FI down | 5xx / timeout twice | Nothing written; previous snapshot stays current; run failed | `Transient` |
| Decimal comma / bad number | "1,61" → 1.61; "abc" | 1.61 stored; "abc" → whole run SchemaMismatch, nothing written | `SchemaMismatch` |
| Derive unaffected | FI step throws | `/cron/derive` response and digest unchanged | Caught and logged |

</intent-contract>

## Code Map

- **Partial work already on this branch, from an interrupted first attempt (unverified):** treat it as a draft to complete and correct, not as finished.
  - Untracked:
    - `db/migrations/20261003120000_create_short_position.php`
    - `src/Adapter/{FiShortPositionAdapter,GleifAdapter,LeiResolver,ShortPosition,ShortPositionSource}.php`
    - `src/Pipeline/ShortPositionSync.php`
    - `src/Store/ShortPositionRepository.php`
  - Modified: `src/Adapter/HandlesTransientHttp.php`, `src/Pipeline/UniverseSync.php`, `src/Store/{Instrument,InstrumentRepository}.php`, `tests/MigrationTest.php`, `tests/Store/StoreTestCase.php`.
  - Nothing is wired into `public_html/index.php` yet, and no new tests exist.
- **Adapter conventions:**
  - Port/adapter shape: `UniverseLister.php:15`, `AvanzaUniverseAdapter.php:33,67,72,162-172` (constructor `(ClientInterface, LoggerInterface, ?string $baseUri)`, an env base-URI test seam, a `USER_AGENT` const, 404→NotFound).
  - `HandlesTransientHttp.php:32` (`requestJson`, JSON only) and `:78` (`withOneRetry`).
  - The FI binary download needs the same status→error mapping as `requestJson`.
  - FI URL constant: `https://www.fi.se/BlankningsRegister/GetBlankningsregisterAggregat`.
  - Locate the header row by the exact labels "Namn på emittent", "LEI", "Position i procent", "Positionsdatum senaste position". Data rows follow it; skip empty rows.
  - GLEIF: `GET https://api.gleif.org/api/v1/lei-records?filter[isin]=<ISIN>` → `data[0].id`.
- **UniverseSync:** pass 5 (:298-328) is the template (`allActive()`, `throttle()` :390, deadline :179, `$bySource` tallies, `numericSetting` :408 with DEFAULTS :46). Inject the GLEIF adapter at `public_html/index.php:276-321`, and add `cacheLei` as a write-once method like `cacheNordnetId` (:185).
- **Nightly step:** in `/cron/derive` (`index.php:351-445`), next to `TopTenDigest` (:396-426), inside the `$alreadyDerivedToday` guard (:373-380). Use `RunRepository::start/finish` (:47/:70); `run_type` is VARCHAR(16), and an alarm mails via the `UniverseSync::sendAlarm()` pattern (:374).
- **Schema:**
  - `instrument.lei VARCHAR(20) NULL`;
  - `short_position` (`snapshot_date DATE`, `lei VARCHAR(20)`, `issuer_name VARCHAR(255)`, `position_pct DECIMAL(6,2)`, `position_date DATE`, `fetched_at DATETIME` UTC), PK `(snapshot_date, lei)`, with an index on `lei`.
  - Mirror both in `StoreTestCase::createSchema()`/`dropSchema()` and `MigrationTest`.
- **Read for later UI:** `ShortPositionRepository::currentForIsins(array $isins): array<isin, {pct, position_date}>`, joining `instrument.lei` to the latest `snapshot_date`.
- **Docs:**
  - `docs/deploy.md` settings table (:350-361): add `rate.gleif`.
  - `ARCHITECTURE-SPINE.md`: the FI/GLEIF adapters and the `short_position` single writer.
  - `bin/show-runs.php` already lists any run type, so no change is needed.

## Tasks & Acceptance

**Execution:**
- `src/Adapter/` -- complete and test the FI ODS adapter and the GLEIF adapter. Build an ODS fixture in-test with `ZipArchive`; mock HTTP with Guzzle MockHandler.
- Migration + `StoreTestCase` + `MigrationTest` + `Instrument`/`InstrumentRepository::cacheLei` -- schema.
- `ShortPositionRepository` + `ShortPositionSync` + `/cron/derive` wiring -- the nightly snapshot and run log.
- `UniverseSync` + refill wiring -- the LEI pass.
- Tests -- one per matrix row; existing tests stay green.
- Docs -- deploy.md, spine.

**Acceptance Criteria:**
- Given the real FI file format, when the adapter parses it, then every data row yields `{issuerName, lei, positionPct, positionDate}` with the decimal comma handled.
- Given a stored snapshot and resolved LEIs, when `currentForIsins(['SE0009554454'])` is called, then it returns that issuer's latest-snapshot percentage.

## Spec Change Log

- 2026-10-03: split from `spec-short-interest-badge` at Stefan's request ("data first"). The UI half (badge + Dölj blankade) is logged in deferred-work.md.

## Review Triage Log

### 2026-10-03 — Review pass
- verdicts: 34 findings — high 0, medium 4, low 28, false 2, maybe-false 0
- findings:
  - `[medium]` `[patch]` (edge) Malformed content.xml mid-file ends the read loop quietly and returns a partial list stored as a full snapshot — → check libxml errors after the loop and throw SchemaMismatch; test added
  - `[medium]` `[patch]` (edge, intent R2c) Same-day re-run upserts without removing issuers that dropped out — → upsertSnapshot replaces the day's snapshot (DELETE snapshot_date rows, then insert) in one transaction; test added
  - `[low]` `[patch]` (intent R4b) The parser needs ext-dom (XMLReader::expand), which the missing-extension guard doesn't cover — → add a DOMDocument check to the guard
  - `[medium]` `[patch]` (verification-gap) Percentage-typed cell (×100), date-typed cell and duplicate-LEI branches untested — pre-verified → tests added
  - `[medium]` `[patch]` (verification-gap, blind) /cron/refill wiring of GleifAdapter unasserted — pre-verified → refill integration test asserts the by_source `gleif` tally
  - `[low]` `[patch]` (blind) deploy.md prerequisites don't say web-PHP needs zip/xmlreader/dom; `shorts` run type undocumented — → docs updated
  - `[low]` `[defer]` (blind) AGENTS.md doesn't list the short_position/instrument.lei writers or the new adapters — agent-context file → deferred
  - `[low]` `[reject]` (blind, edge) A failed FI download is never retried that night (derive guard) — the intent explicitly places the step inside the existing once-per-day guard; changing it edits the contract
  - `[low]` `[reject]` (blind) A truncated-but-valid file could wipe most positions — a generated government file; truncation would break the zip; the fix adds a new guard
  - `[low]` `[reject]` (blind) No alarm on weeks of Transient failures / stale snapshot — enhancement; the UI spec can show the position date
  - `[low]` `[reject]` (blind, edge) GLEIF multiple records → data[0]; write-once LEI never re-checked — not demonstrated (the real lookup for 85/86 ISINs returned one record); the fix adds selection logic
  - `[low]` `[reject]` (blind) Permanent GLEIF misses re-queried hourly could starve later ISINs — ≈1–3 % permanent misses vs ~90 calls/run; the fill still completes
  - `[low]` `[reject]` (blind) `<text:s/>` spaces not expanded — the real FI file parsed cleanly (335 issuers, verified by the implementer)
  - `[low]` `[reject]` (edge) A footer row with only a name would fail the whole file — the real file has none; the fix adds a heuristic
  - `[low]` `[reject]` (edge) Float-serial or time-suffixed date cells — robustness only; any change surfaces as a SchemaMismatch alarm
  - `[low]` `[reject]` (edge) content.xml size cap (zip bomb) — trusted government source
  - `[low]` `[reject]` (edge) A repeating GLEIF SchemaMismatch alarms every hourly refill — not demonstrated; GLEIF's schema is stable
  - `[low]` `[reject]` (edge) A hung GLEIF call can overrun the timebox by ~50 s — same pattern as the existing Nordnet pass; within 180 s
  - `[low]` `[reject]` (edge, intent) The LEI pass also looks up instruments delisted later in the same run — a few wasted calls
  - `[low]` `[reject]` (edge) finish() throwing inside the Throwable catch masks the original error — DB-down edge case; the route logs either way
  - `[false]` `[reject]` (edge claim, intent R3) currentForIsins doesn't filter on active — intent R3b: the caller passes the ISINs it renders
  - `[low]` `[reject]` (edge claim) The AC says "every data row yields" but duplicate LEIs are deduped — the duplicate is logged; the AC wording is approximate
  - `[low]` `[reject]` (edge) Concurrent derive hits could run the step twice — pre-existing guard race; now harmless since the snapshot is replaced
  - `[low]` `[reject]` (edge) table:number-rows-repeated on a data row — deduped by LEI anyway
  - `[low]` `[reject]` (edge) A duplicate LEI keeps the first row, not the latest date — logged; not seen in the real file
  - `[low]` `[reject]` (blind) short_position grows ~335 rows/night — ≈120k rows/year; history may be useful; no pruning needed now
  - `[low]` `[reject]` (blind) The deferred counter mixes the ISIN/Nordnet/LEI passes — log attribution only
  - `[low]` `[reject]` (blind) No bin script to run the FI step by hand — enhancement
  - `[low]` `[reject]` (blind) The deferred-work UI entry lacks staleness and share-class notes — deferred-work is append-only; the UI spec will plan them
  - `[low]` `[reject]` (blind, verification-gap other) GLEIF Transient tally and retry-then-success tests missing — the adapter retry is covered on the FI side; low value
  - `[low]` `[reject]` (intent) The MigrationTest rollback doesn't assert instrument.lei is dropped — minor test gap
  - `[low]` `[reject]` (intent) The normal-night success path is tested with fakes, not through the route — the sync and adapter are covered separately; the FI-down route path is end-to-end
  - `[false]` `[reject]` (intent R1) "Nightly" means trading-day nights — R1b is the stated guard; cron_gate defines nights
  - `[low]` `[reject]` (verification-gap other) The refill test spends seconds on dead-host GLEIF retries — test speed only

## Design Notes

- **Why snapshots:** FI's aggregate file lists only issuers that currently have a reported position, so "current" must mean "in the latest snapshot", not "latest row per LEI". Otherwise a de-shorted company keeps its old figure forever.
- **First fill takes several runs:** about 745 LEIs at 2 req/s, each run capped by `universe.resolve_timebox`. That is expected.

## Verification

**Commands:**
- `docker compose up -d && composer test` -- expected: green, 0 skipped.

**Manual checks (if no CLI):**
- After deploy: `vendor/bin/phinx migrate -e production`. After the next night, `php bin/show-runs.php` shows a `shorts` run, and a one-off `currentForIsins(['SE0009554454'])` returns ~15.8.

## Auto Run Result

Status: done

**Summary:**
- **Nightly FI snapshot:** once per derive night, `ShortPositionSync` downloads FI's aggregated short-position ODS through `FiShortPositionAdapter` and stores it as a dated snapshot in `short_position`. The day's snapshot is replaced, never merged into. Each run writes an `ingest_run` row of type `shorts`: `completed`, `failed` (Transient), or `alarmed` with a mail (SchemaMismatch).
- **LEI per instrument:** `UniverseSync` fills `instrument.lei` (write-once) through `GleifAdapter` in a throttled pass sharing the existing timebox.
- **Read method:** `ShortPositionRepository::currentForIsins()` returns each ISIN's latest-snapshot position, for the coming badge UI.
- **No UI change.**

**Files:**
- `src/Adapter/{FiShortPositionAdapter,GleifAdapter,ShortPositionSource,LeiResolver,ShortPosition}.php` and `HandlesTransientHttp.php` (`requestBody`).
- `src/Pipeline/{ShortPositionSync,UniverseSync}.php`.
- `src/Store/{ShortPositionRepository,Instrument,InstrumentRepository}.php`.
- `db/migrations/20261003120000_create_short_position.php`.
- `public_html/index.php`: refill and derive wiring.
- `bin/universe-sync.php`: GLEIF wiring.
- Tests: adapter, repository, sync, UniverseSync, integration, `StoreTestCase`/`MigrationTest` mirror; `tests/Support/EndpointFixture.php` gets dead FI/GLEIF hosts.
- Docs: `docs/deploy.md`, `ARCHITECTURE-SPINE.md`.

**Review (pass 1, 34 findings):**
- **Patched (6):**
  - 4 medium: the malformed-XML partial-snapshot guard; same-day snapshot replacement; tests for the percentage/date/duplicate parser branches; the refill GLEIF-wiring assertion.
  - 2 low: the ext-dom guard; deploy.md prerequisites and the `shorts` run type.
- **Deferred (1):** an AGENTS.md update.
- **Rejected:** 25 low and 2 false, each with its reason in the triage log.

**Follow-up review recommended: true.** Two medium patches changed behaviour after review: snapshot replacement in `upsertSnapshot`, and the libxml check that ends the read loop. They are verified only with synthetic ODS fixtures, and the success path through `/cron/derive` is not exercised end to end.

**Verification:**
- `composer test` → 720 tests OK, 0 skipped (MariaDB up).
- The implementer ran the adapters once against the live FI file and GLEIF: 335 issuers; SBB SE0009554454 → 15.83 %.

**Residual risks:**
- A Transient FI failure isn't retried until the next night.
- There is no staleness alarm.
- A wrong GLEIF LEI would stay cached for good (write-once).
- Web-PHP extensions are confirmed only on the Loopia CLI; the web check is listed in deploy.md.
- `phinx migrate -e production` must be run after deploy.
