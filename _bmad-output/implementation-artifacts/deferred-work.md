- source_spec: `_bmad-output/implementation-artifacts/spec-2-6-full-run-log-and-schema-mismatch-alarm.md`
  summary: Preserve partial per-source counters if an unexpected exception escapes the fetch slice after some jobs have run.
  evidence: The outer failure handler currently records a failed run with zeroed counters; the normal per-job unexpected-error path is covered, but a reproducible exception after partial slice telemetry was not established during review.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-projektskelett-och-gemensam-grund.md`
  summary: Verify `.htaccess` front-controller routing under real Apache (not just `php -S`).
  evidence: Both routing tests run through the PHP built-in server, which ignores `.htaccess`; a rewrite regression would ship with the suite green. Fix belongs in Story 1.10 (`docs/deploy.md`): a post-deploy curl check for `/` (200 JSON) and `/nope` (404 JSON) against the live subdomain.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-projektskelett-och-gemensam-grund.md`
  summary: Add log rotation / a sane default log level for the nightly job.
  evidence: `Logging::logger()` attaches one `StreamHandler` at `Level::Debug` to a single unbounded file; on shared hosting this grows without limit. Revisit in Story 1.8 (minimal körningslogg) / Story 2.6 (full körningslogg) — e.g. `RotatingFileHandler` and a configurable level.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-projektskelett-och-gemensam-grund.md`
  summary: Reject the placeholder `cron_token` value (`change-me`) so a misconfigured deploy fails closed.
  evidence: `Config::cronToken()` rejects only an empty string. No `/cron/*` endpoints exist yet; Story 1.9 (cron endpoints + token check) should reject the known default or warn loudly.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-projektskelett-och-gemensam-grund.md`
  summary: RESOLVED 2026-09-14 — Decide whether to pin `config.platform.php` in `composer.json` for the 8.3 floor.
  evidence: Without `config.platform.php`, a `composer update` on an 8.5 dev machine can lock dependencies requiring >8.3 and silently break the minimum-supported environment. All currently-locked deps are 8.1/8.2-compatible, so no impact today. The frozen spec comments that `require.php` is "not pinned", so this is a deliberate dependency-policy decision.

  This stopped being theoretical the moment CI (added same day) ran on real
  PHP 8.3: `composer require --dev phpstan/phpstan` on this local 8.5 machine
  had locked `symfony/config` v8.1.5 (a `robmorgan/phinx` transitive dep),
  which requires PHP >=8.4.1 -- `composer install` failed outright in CI on
  8.3. Added `config.platform.php: 8.3.0` and re-ran `composer update`, which
  downgraded `symfony/config`/`console`/`filesystem`/`string` to their 7.4.x
  releases (8.3-compatible) and dropped the now-unneeded
  `symfony/polyfill-php85`. `composer test` and `composer run analyse` still
  pass locally against the downgraded lock.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-projektskelett-och-gemensam-grund.md`
  summary: RESOLVED 2026-09-14 — Add static analysis (PHPStan) and CI to enforce the baseline type-safety bar.
  evidence: The scaffold is written to a high type-safety standard (array-shape annotations, `declare(strict_types=1)`, `@throws`) but nothing enforces it and nothing runs the smoke suite automatically. Reasonable as its own tooling story.

  Added `phpstan.neon` (level 8, over `src/`, `public_html/`, `bin/`) and
  `.github/workflows/ci.yml` (MariaDB service container matching
  `docker-compose.yml`, `composer test` + `composer run analyse` on every
  push/PR). Fixed every finding rather than baselining — no
  `phpstan-baseline.neon` exists. Most were trivial (`array_values()` around a
  `fetchAll()`/`array_map()` result to satisfy `list<>`; an explicit
  `$stmt === false` throw after `PDO::query()`, since `Database::connect()`
  always sets `ATTR_ERRMODE_EXCEPTION` but PHPStan can't see that invariant
  across the call boundary; `$argv` given an explicit `??= []` in the three
  `bin/*.php` scripts, since PHPStan can't assume the CLI SAPI populates it).

  One finding was a real, live bug: `guzzlehttp/guzzle` 8.x (currently locked,
  `composer.json` allows `^7.9 || ^8.0`) moved `getResponse()` off the base
  `RequestException` onto a new `ResponseException` subtype — `RequestException`
  itself no longer has it. `HandlesTransientHttp::requestJson()` (shared by
  `AvanzaAdapter`, `NordnetAdapter`, `AvanzaUniverseAdapter`) and the two
  `market-guide/stock/…` 404-detection call sites in `AvanzaAdapter`/
  `AvanzaUniverseAdapter` all called `$e->getResponse()` on a bare
  `RequestException`. Any transport-level Guzzle failure that isn't a
  `ConnectException` — e.g. `CurlFactory`'s generic mid-transfer-reset throws —
  is exactly a bare `RequestException` with no response, so this was a live
  fatal-`Error` crash waiting to happen on production (which runs 8.2.0 per
  `composer.lock`), not merely a caught-and-misclassified error. Fixed by
  checking `instanceof ResponseException` before calling `getResponse()`,
  treating a responseless `RequestException` the same as `ConnectException`
  (`Transient`) rather than falling through to `SchemaMismatch`. Covered by a
  new test in each of `AvanzaAdapterTest`, `NordnetAdapterTest`,
  `AvanzaUniverseAdapterTest` (`testThrows(Transient|Resolve…)OnABareRequestExceptionWithNoResponse`).

  This CI job also closes the separate Story 1.11 item below (`EndToEndSmokeTest`
  runs in no unattended path) — it now gets a real MariaDB on every push/PR.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-3-sourceadapter-port-feltyper-och-id-uppslag.md`
  summary: RESOLVED 2026-09-15 (found already shipped, stale, never marked) — Widen `instrument.nordnet_instrument_id` (or change which Nordnet id is cached) before anything persists it.
  evidence: Live smoke in Story 1.3 showed `nnx_info.nnx_instrument_id` is a 36-char UUID (`19fa390b-040f-45a9-8fa2-e7fd34e319ab`); Story 1.2's column is `VARCHAR(32)`. Story 1.3 does not persist, so nothing is broken yet. The story that first caches Nordnet ids (Epic 2 `UniverseSync` or an interim resolver) must add a migration to widen the column to `VARCHAR(64)` / `CHAR(36)`, or cache `instrument_info.instrument_id` (integer) instead — which would mean renegotiating Story 1.3's frozen "return `nnx_instrument_id`" decision.

  Confirmed done: `db/migrations/20260909140100_widen_nordnet_instrument_id.php` widens the
  column to `VARCHAR(64)`, and `MigrationTest` pins `varchar(64)` for it. Stale, just never
  marked; no code change made here.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-6-append-only-tidsserielagring.md`
  summary: Make the store integration tests run the real Phinx migrations instead of hand-written mirror DDL (or assert the two schemas match).
  evidence: `tests/Store/StoreTestCase::createSchema()` rebuilds instrument/settings/owner_count_daily from DDL that only "mirrors" the migrations and is kept in step by hand — Story 1.6 had to edit two places for one column change, and the mirror already omits constraint names, column comments, and FK ON UPDATE. A migration-only change (index, type) passes the store suite silently. `MigrationTest` now pins owner_count_daily's PK + uniqueness against the real migration; the general fix is broader.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-6-append-only-tidsserielagring.md`
  summary: Fix each night's `as_of_date` at Enqueue time so a fetch retry straddling local midnight doesn't split one observation across two calendar days.
  evidence: `OwnerCountRepository::asOfDate()` derives the day from `(sourceTimestamp ?? fetchedAt)` in Europe/Stockholm (a frozen Story 1.4/1.6 decision). For Avanza (no sourceTimestamp) a job fetched at 23:59 and re-fetched at 00:05 after a Transient/timebox gets two rows with the same owner count on consecutive dates. Home: Story 1.7 (`work_queue.run_date` / `Enqueue`) — pass the run's calendar date to `upsert()` rather than deriving per-fetch.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-7-work-queue-och-tidsboxad-fetchrunner.md`
  summary: RESOLVED 2026-09-14 — Add a retention / pruning step for `work_queue` — old `done`/`failed` rows accumulate one-per-instrument-per-run-date forever.
  evidence: `bin/prune.php` (`QueueRepository::countPrunable()`/`prune()`) deletes `done`/`failed` rows older than `--work-queue-days` (default 30); `pending`/`claimed` rows are never touched regardless of age. Dry-run by default, `--apply` to actually delete. Manual SSH op, not wired into any cron endpoint.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-7-work-queue-och-tidsboxad-fetchrunner.md`
  summary: RESOLVED 2026-09-14 — `FetchRunner` reads `batch_size` from `settings` with no upper bound — a very large value makes one `claimBatch` load that many `QueueJob` rows and voids the NFR8 256 MB margin.
  evidence: `FetchRunner::intSetting('batch_size')` guards `> 0` but not a ceiling. The default 25 is safe; a `min($value, MAX)` clamp is the right fix once Story 1.10's Loopia probe measures the real web-PHP `memory_limit`. Loopia's measured `memory_limit` is 256M (docs/deploy.md). Added `FetchRunner::MAX_BATCH_SIZE = 1000` (well above the ~740-instrument active universe, so it never limits normal operation) with a `warning` log when a raw setting exceeds it, clamped before `claimBatch()`. Covered by `testBatchSizeSettingIsClampedToTheHardCeiling`.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-7-work-queue-och-tidsboxad-fetchrunner.md`
  summary: Validate `run_date` format where Story 1.9 derives it — the pipeline passes it straight to SQL.
  evidence: `Enqueue::run()` / `QueueRepository` bind `$runDate` unchecked. A malformed string (`''`, `'2026-9-9'`, trailing space) makes MySQL coerce the key and `FetchRunner` silently claim nothing, indistinguishable from an empty queue. Not reachable from current callers (tests pass `Y-m-d`; Story 1.9 will mint it via `->format('Y-m-d')`), so the guard belongs at the derivation site in Story 1.9.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-7-work-queue-och-tidsboxad-fetchrunner.md`
  summary: RESOLVED (Story 2.4) — A source stuck on `Transient` has no retry cap — the job ping-pongs `pending ↔ claimed` every slice until it eventually succeeds.
  evidence: `FetchRunner` reopens the job on any `Transient` with no attempt counter. Explicitly Epic 2 by the FR coverage map (FR9: exponential backoff, rate-limit-aware, retry caps — Story 2.4). `upsert` idempotency makes the re-store of an already-healthy source benign.

  Confirmed shipped: `FetchRunner::$maxAttempts` (from `retry.max_attempts`, default 3)
  bounds attempts with exponential backoff (`retry.backoff_base`/`retry.backoff_max`) —
  see `src/Pipeline/FetchRunner.php`. This item was already stale, just never marked;
  no code change made here.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-8-minimal-korningslogg.md`
  summary: RESOLVED 2026-09-15 (found already shipped, stale, never marked) — Add the per-datum `owner_count_daily.ingest_run_id` link (spine ER diagram `ingest_run ||--o{ owner_count_daily`).
  evidence: Story 1.8 keeps the körningslogg minimal — one appended summary row per run, `RunRepository` append-only, no change to `OwnerCountRepository::upsert()`. Story 1.6's spec flagged "Story 1.8 adds the run link" but the epic's 1.8 ACs only ask for the summary row. Home: Story 2.6 (full körningslogg) — add a nullable `ingest_run_id BIGINT UNSIGNED` column + FK, no backfill (NFR7: series starts empty); `RunRepository` gains `start()`/`finish()` and `FetchRunner` threads the id into each `upsert()`.

  Confirmed done: `db/migrations/20260911100000_enrich_ingest_run.php` adds the nullable
  `ingest_run_id` FK (`ON DELETE SET NULL`) to `owner_count_daily`, and
  `OwnerCountRepository::upsert()` accepts and writes it, threaded in by `FetchRunner`.
  Stale, just never marked; no code change made here.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-8-minimal-korningslogg.md`
  summary: RESOLVED 2026-09-14 — Add retention / pruning for `ingest_run` rows (one per slice, forever).
  evidence: Same `bin/prune.php` (`RunRepository::countPrunable()`/`prune()`) deletes rows older than `--ingest-run-days` (default 180). Deleting a run nulls out `owner_count_daily.ingest_run_id` for any row that pointed at it (ON DELETE SET NULL) — the owner-count history itself is never touched, only that old run's provenance link.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-10-loopia-deploy-och-runbook.md`
  summary: RESOLVED (Story 1.10, 2026-09-10) — Run the first production deploy to Loopia and record the results — probe web-PHP `memory_limit` / `max_execution_time` and the URL-cron execution-time limit + minimum interval, fill those blanks in `docs/deploy.md`, register the `/cron/refill` + `/cron/work` URL-cron jobs in Kundzon, and clamp/tune `settings` (`batch_size`, the 75 s `/cron/work` timebox) against the measured web-PHP limit.

  Confirmed done: `docs/deploy.md`'s "Open items — closed on the first deploy
  (2026-09-10)" section has every measured value filled in (PHP 8.4, 256M/180s,
  5 min URL-cron interval, all three jobs registered). Stale, just never marked;
  no code change made here.
  evidence: Split from Story 1.10 at planning (2026-09-09). The spec ships the deploy tooling + runbook as one reviewable PR; the live deploy needs Loopia credentials + Kundzon access and produces real numbers that can only be measured against the server. Independent, small follow-up PR (runbook blanks + a possible `batch_size` ceiling — see the Story 1.7 `batch_size` item above). To be walked through in-session with the operator immediately after the Story 1.10 tooling lands. Story 1.11 (end-to-end smoke test) also depends on this being done.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-10-loopia-deploy-och-runbook.md`
  summary: RESOLVED 2026-09-15 (found already shipped, stale, never marked) — Add a TLS / Let's Encrypt enablement step to the `docs/deploy.md` Kundzon one-time setup, and a "`mysqldump` before `phinx migrate`" note to the Rollback section + first-deploy checklist.
  evidence: Story 1.10 review (blind-hunter, iteration 1). Every cron and verify URL in the runbook is `https://stockpicker.ryddmo.se` but section B never enables the certificate. Separately, MariaDB DDL is non-transactional so a migration that fails partway half-applies, and Rollback only covers reversible migrations. Both are runbook completeness gaps that land naturally with the split-off first production deploy.

  Confirmed done: `docs/deploy.md` step B.3 covers Kundzon SSL/Let's Encrypt enablement, and
  the deploy step + "Database migrations" section both call out taking a `mysqldump` before
  `phinx migrate -e production`. Stale, just never marked; no code change made here.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-10-loopia-deploy-och-runbook.md`
  summary: Bound the `bin/deploy.sh` SSH preflight probe against a post-connect stall (banner/auth hang), and guard `git rev-parse --short HEAD` against an unborn HEAD under `set -e`.
  evidence: Story 1.10 review (edge-case-hunter, iteration 1). `ssh -o ConnectTimeout=10` bounds only the TCP connect, so a server that accepts the connection then stalls in the SSH banner or auth hangs the deploy with no upper bound; `timeout`/`gtimeout` is not standard on macOS so the fix needs design. The `commit="$(git rev-parse --short HEAD)"` assignment aborts the script silently under `set -e` if the repo has zero commits — unreachable in a real deploy but a cheap `|| echo '(unknown)'` guard closes it.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-11-end-to-end-roktest-mot-seed-listan.md`
  summary: RESOLVED 2026-09-14 — The Epic 1 end-to-end guard (`EndToEndSmokeTest`) runs in no unattended path — it self-skips without a manually-started docker-compose MariaDB and the repo has no CI.
  evidence: Story 1.11 review (verification-gap, iteration 1). `StoreTestCase` calls `markTestSkipped()` when `127.0.0.1:3306` is unreachable and `phunit` does not fail on skips; the frozen spec bars adding CI, and every existing `StoreTestCase` test shares this behaviour. Real but pre-existing — the fix is a CI job (or a documented pre-acceptance step) that runs `docker compose up -d && composer test`. Until then the epic must not be accepted without one manual `docker compose up -d && composer test` run confirming `EndToEndSmokeTest` executed and passed.

  The "frozen spec bars adding CI" was Story 1.11's own scope boundary ("this
  is a test-only story", don't scope-creep into building CI infra as part of
  it) — not a standing project-wide ban. `.github/workflows/ci.yml` (added
  alongside the PHPStan item above) runs a real MariaDB service container on
  every push/PR, so `EndToEndSmokeTest` — and every other `StoreTestCase` test
  — now actually executes unattended instead of self-skipping.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-10-loopia-deploy-och-runbook.md`
  summary: RESOLVED 2026-09-14 — The interim `bin/resolve-ids.php` / `SourceIdResolver` ISIN search fails to resolve three correct-ISIN large caps on the scraped endpoints — Svenska Handelsbanken A (SE0007100599) and Nordea Bank Abp (FI4000297767) on Avanza, and Epiroc A (SE0011166933) on both Avanza and Nordnet.
  evidence: First Loopia production deploy 2026-09-09 — `resolve-ids` reported 36 resolved / 4 failed, all `NotFound` (not `SchemaMismatch`). ISINs verified current and correct. Avanza returns "no STOCK hit"; Nordnet "no result with isin". The scraped search-by-ISIN strategy is too brittle for reliable universe coverage. Partial fix from Epic 2 Story 2.1 v2 (`AvanzaUniverseAdapter`): the Avanza `orderbookId` comes straight out of the listing, so the Avanza side of this stops depending on ISIN search. The Nordnet id is still resolved by search in Story 2.2 — if Nordnet's ISIN search keeps missing names, hand-cache those `nordnet_instrument_id`s via a direct `instrument` UPDATE, or match on ticker/name.

  RE-INVESTIGATED 2026-09-14, production DB checked directly: Handelsbanken A and
  Nordea are already fully resolved (`UniverseSync`'s nightly retry healed both — no
  action needed). Epiroc A is a different, more specific problem: `SE0011166933`
  (the seed list's ISIN for it) is simply **wrong** — Avanza's live
  `market-guide/stock/861430` reports `SE0015658109` for the real "Epiroc A".
  `UniverseSync` correctly resolved the true ISIN on 2026-09-11 and inserted a
  *second*, fully-populated row (`SE0015658109`, both source ids cached, collecting
  data nightly since); the original seed row (`SE0011166933`, both ids still `NULL`)
  is a permanent ghost duplicate — still `last_seen IS NULL` (active), enqueued and
  fetched every night, always `not_found` on both sources (harmless, no alarm, but a
  second data-less "Epiroc A" would appear in Fullständig lista). There was no
  Nordnet id to hand-cache here; the fix applied on production 2026-09-14 was
  `UPDATE instrument SET last_seen = '2026-09-14' WHERE isin = 'SE0011166933' AND
  last_seen IS NULL` (the same effect a real delisting has, via
  `InstrumentRepository::markInactive()`'s exact guard) — verified the row now has
  `last_seen` set and will no longer be enqueued. The real "Epiroc A"
  (`SE0015658109`) is unaffected and keeps collecting data as it has since
  2026-09-11.
- source_spec: `_bmad-output/implementation-artifacts/spec-2-1-borsdata-adapter-for-universumlistan.md`
  summary: MOOT 2026-09-10 — Börsdata universe source dropped (paid Pro subscription required, no free tier). The live `bin/show-universe.php` run, the `EQUITY_TYPE_IDS` / market-name pinning, the listing-status filter question, and the `docs/deploy.md` `borsdata.api_key` item all fall away.
  evidence: Course correction 2026-09-10 (see `_bmad-output/planning-artifacts/sprint-change-proposal-2026-09-10.md`). Story 2.1 v2 (`AvanzaUniverseAdapter`) replaces the Börsdata adapter and carries its own live-verification step against the real Avanza listing response — including a delisted/non-tradable spot-check.

## Deferred from: code review of story-2.1 (Avanza universe adapter, 2026-09-10)

- source_spec: `_bmad-output/implementation-artifacts/spec-2-1-avanza-universumadapter.md`
  summary: No test asserts the four outgoing `market-stock-filter/stocks` POSTs — path, body, and the `marketPlaces` value per label are unverified in the suite.
  evidence: Every `AvanzaUniverseAdapterTest` case queues responses into `MockHandler`, which ignores the request. A wrong path → 404 → `SchemaMismatch`; a wrong `marketPlaces` value → empty list → `SchemaMismatch` — so a request-side regression fails loudly, not silently, and this matches the repo convention (`AvanzaAdapterTest` / `NordnetAdapterTest` assert only on responses). Add a Guzzle history-middleware assertion if request-shape regressions become a concern.
- source_spec: `_bmad-output/implementation-artifacts/spec-2-1-avanza-universumadapter.md`
  summary: No test proves a mid-sequence target-list failure (LC + MC succeed, then SC throws) discards the already-built entries rather than returning a two-thirds universe.
  evidence: The behaviour is already correct — `$out` is a local in `listUniverse()` and `queryList()`'s throw is uncaught, so a failure anywhere yields nothing. Only the explicit test is missing; add one that queues [LC ok, MC ok, SC 503×2] and asserts `Transient` with no partial return.
- source_spec: `_bmad-output/implementation-artifacts/spec-2-1-avanza-universumadapter.md`
  summary: `bin/list-universe.php` is not documented in `docs/deploy.md` (its sibling `bin/resolve-ids.php` is).
  evidence: Story 2.1 review. Add a one-line entry to the deploy runbook's SSH-scripts section; pairs naturally with the Story 2.2 `docs/deploy.md` updates already deferred.

## Deferred from: review pass 1 of story-2.2 (UniverseSync v2, 2026-09-10)

- source_spec: `_bmad-output/implementation-artifacts/spec-2-2-universesync-daglig-avstamning-2.md`
  summary: `ingest_run` semantics for `run_type='universe_sync'` rows overload `ok_count`/`fail_count` (a healthy index-review delisting of 30 names shows `fail_count=30`), and `changed` / `reactivated` / `ids_resolved` / `ids_failed` / `deferred` live only in a Monolog `info` line — convergence progress and churn history are not queryable. `universe.resolve_timebox`'s default (45) also lives only in `public_html/index.php`'s helper, not alongside `UniverseSync::DEFAULTS`.
  evidence: Story 2.2 frozen "Never" defers all run-log enrichment (per-source counts, columns, alarm flag) to Story 2.6; the epic AC asks for added/removed/changed "in `ingest_run` (or a dedicated row)". Story 2.6 should give `universe_sync` runs a queryable churn breakdown (dedicated columns or a companion row) and reconsider the ok/fail overload; while there, consolidate the timebox default.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-2-universesync-daglig-avstamning-2.md`
  summary: `EndpointFixture` has no canned Nordnet source, so `UniverseSync`'s step-5 Nordnet id-resolution pass — and a full new-instrument `/cron/refill` end-to-end (resolveIsin → insert → cacheAvanzaId → Nordnet lookup) — cannot be integration-tested without a live nordnet.se call.
  evidence: Story 2.2 review pass 2. The Avanza universe seam (`STOCKPICKER_AVANZA_UNIVERSE_BASE_URI` + a canned `php -S` router) was added; Nordnet was left out of scope. A future test-hardening pass should give `NordnetAdapter` the same base-URI override and add a second canned router so the new-instrument path is covered end to end.
- source_spec: `_bmad-output/implementation-artifacts/spec-2-2-universesync-daglig-avstamning-2.md`
  summary: `instrument.avanza_orderbook_id` has no `UNIQUE` index, yet the entire `UniverseSync` reconciliation pivots on matching by that column and `setAvanzaId()` is an unconditional writer — two rows could end up sharing one orderbook id, and `$storedByObId` would then keep only one.
  evidence: Story 2.2 review pass 2; the frozen "Never: no migration" blocked the safeguard in this story. Add a unique index in a hardening story (the column is nullable, so multiple NULLs remain allowed).

## Deferred from: review pass 1 of story-3.1 (Deriver — derived metrics view, 2026-09-11)

- source_spec: `_bmad-output/implementation-artifacts/spec-3-1-deriver-berakna-harledda-matt.md`
  summary: `owner_count_metrics` stacks six CTEs with `PARTITION BY isin, source` window functions computed before any caller `WHERE isin = ?` filter — MariaDB generally cannot push a predicate through a window partition, so a single-instrument read (Story 3.3's SSH script) recomputes every window over the entire table, not just the requested instrument.
  evidence: Structural read of the view's CTE chain; no `EXPLAIN`/timing check exists yet because today's data volume (1 day, 742 instruments) makes any measurement meaningless. The architecture spine explicitly anticipated deferring this ("materialize later if EXPLAIN ever tells us to"). Revisit once Story 3.3 has a real caller and enough historical data (weeks/months) to measure against, per the epic's "stay cheap enough for Loopia shared hosting" constraint.
- source_spec: `_bmad-output/implementation-artifacts/spec-3-2-cron-derive-endpoint.md`
  summary: No uniqueness/idempotency guard on `ingest_run` rows — two concurrent or retried cron calls for the same `run_date` each write their own row for `derive` (and equally for `enqueue`/`universe_sync`).
  evidence: `db/migrations/20260909160000_create_ingest_run.php` only indexes `run_date` (not unique); `Enqueue.php`/`UniverseSync.php` have no duplicate-prevention either. Pre-existing systemic gap across every cron route, not introduced by Story 3.2, which only mirrors the established unguarded pattern.
- source_spec: `_bmad-output/implementation-artifacts/spec-3-2-cron-derive-endpoint.md`
  summary: The auth/param/`run_after`-gate block in `public_html/index.php` is now duplicated a third time across `/cron/refill`+`/cron/work` and `/cron/derive` instead of factored into a shared helper.
  evidence: The duplication pattern predates this story (already shared between `/cron/refill` and `/cron/work`); Story 3.2 followed the established style per its own Code Map instruction to keep `/cron/derive` as its own block. A cross-route refactor is out of this story's scope.
- source_spec: `_bmad-output/implementation-artifacts/spec-3-3-uttag-av-en-akties-serie-och-matt.md`
  summary: CLI scripts (`bin/show-runs.php`, `bin/show-metrics.php`) silently let a repeated `--isin=`/`--limit=`/`--date=` flag overwrite the earlier value instead of erroring, unlike an actually-unrecognized flag.
  evidence: Verified in both scripts' argv loops — last-wins on repeat, no duplicate-detection guard. Pre-existing convention (`bin/show-runs.php` shipped first, Story 1.8), not introduced by Story 3.3, which mirrored it per spec instruction.
- source_spec: `_bmad-output/implementation-artifacts/spec-3-3-uttag-av-en-akties-serie-och-matt.md`
  summary: DB-gated script-integration tests (`ShowRunsScriptTest`, `ShowMetricsScriptTest`) do row setup (`INSERT`/`upsert()`) before entering their `try` block, so a setup failure (e.g. duplicate PK from a prior orphaned run) skips the `finally` cleanup and can permanently wedge the fixture ISIN/run id.
  evidence: Confirmed in `tests/Store/ShowRunsScriptTest.php::testAlarmsFlagShowsAlarmedRunsOnly` (pre-existing, Story 1.8) and both new `ShowMetricsScriptTest.php` DB-gated tests. If real, moves setup calls inside `try`, ahead of `finally`.

## Deferred from: review pass 1 of story-4.2 (Topplista med bevakningsstjärnans mekanik, 2026-09-13)

- source_spec: `_bmad-output/implementation-artifacts/spec-4-2-topplista-med-bevakningsstjarnans-mekanik.md`
  summary: The hand-mirrored `watchlist` table DDL in `tests/Store/StoreTestCase.php` has no automated check against the real Phinx migration, so the two can silently drift.
  evidence: Pre-existing gap already acknowledged by the file's own comment ("Keep this in step with the migration; there is no automated check") for every table before this story; Story 4.2 only extends the same already-accepted convention to `watchlist`, per its Code Map's explicit instruction to match it. A cross-cutting fix (e.g. a test that diffs the migration's schema against `createSchema()` for every table) is out of this story's scope.

## Deferred from: review pass 1 of story-5.1 (Mobil layoutbugg på listornas rader, 2026-09-13)

- source_spec: `_bmad-output/implementation-artifacts/spec-5-1-mobil-layoutbugg-pa-listornas-rader.md`
  summary: The `.row-body` CSS (`.namecol`/`.trend`/`.statcol`/`.name`/`.badges`/`.stat`/`.delta-chip`) is hand-edited identically into `LeaderboardController.php`, `FullListController.php`, and `WatchlistController.php` a third time, with no shared CSS file.
  evidence: Already-accepted duplication debt carried from Stories 4.3-4.5 (per this story's own Code Map, which explicitly notes "no shared CSS file exists"); Story 5.1 extends the existing triplication rather than introducing it, per its own instruction to fix all three controllers identically. The surface for the next layout tweak keeps tripling — worth a shared-CSS or shared-partial mechanism as its own chore, especially before Stories 5.4/5.5 add more per-row content.

## Deferred from: review pass 1 of story-5.2 (Länk till aktien på Avanza, 2026-09-13)

- source_spec: `_bmad-output/implementation-artifacts/spec-5-2-lank-till-aktien-pa-avanza.md`
  summary: `avanzaLinkHtml()` builds the Avanza stock-page URL straight from the currently cached `avanza_orderbook_id`, with no acknowledgment that this id can rotate for the same ISIN.
  evidence: `UniverseSyncTest` confirms orderbookId rotation is an already-handled, expected phenomenon for a stable ISIN (Epic 2). A previously bookmarked or shared Aktiedetalj page could, after a rotation, link to a stale/wrong Avanza page — self-healing on any fresh page load (the link is rebuilt from the current DB value each render), so only stale external bookmarks are affected. Pre-existing characteristic of the cached field; this story just consumes it as-is.

## Deferred from: review pass 1 of story-5.3 (Informationssida, 2026-09-13)

- source_spec: `_bmad-output/implementation-artifacts/spec-5-3-informationssida.md`
  summary: No route in `public_html/index.php` — `/info` included — has a test proving its 405 (method-not-allowed) guard actually fires for a non-GET request.
  evidence: Confirmed by grep across `tests/FrontControllerIntegrationTest.php` for "405" — zero matches on any route, old or new. `EndpointFixture::postJson()` already exists, so the test is mechanically cheap once someone takes this on, but fixing it only for `/info` would be inconsistent with every other route sharing the same untested pattern; the real fix is cross-cutting 405 coverage across the whole front controller.

## Deferred from: review pass 1 of story-5.5 (Procentuell utveckling över flera perioder, 2026-09-14)

- source_spec: `_bmad-output/implementation-artifacts/spec-5-5-procentuell-utveckling-over-flera-perioder.md`
  summary: A schema-changing migration's `DROP VIEW`/`CREATE VIEW` (or any DDL) is not atomic, and `bin/deploy.sh` rsyncs source code to the server before running `vendor/bin/phinx migrate -e production` — so a request landing in that window after a deploy but before migration could hit a real "Unknown column"/"table doesn't exist" SQL error for any newly-referenced schema object.
  evidence: Confirmed in `bin/deploy.sh`: `rsync` (line 106) runs before the `phinx migrate` step (lines 116-119). This is a pre-existing characteristic since Story 1.10 (deploy tooling) and Story 3.1 (the view's own DROP+CREATE convention, used again identically by Story 5.5's migration) — every prior story pairing a schema change with code that references it (Story 4.2's `watchlist` table, Story 5.5's `pct_7d`/`pct_90d`/`pct_365d`) shares the same theoretical window. Real but narrow (a manual deploy's migrate step typically completes in well under a second after rsync finishes) and requires touching deploy.sh's step ordering (or switching to `CREATE OR REPLACE VIEW` for the view-specific half of it) — a cross-cutting ops concern, not a single story's fix.
