---
title: "Story 2.6: Full Run Logging and Schema-Mismatch Alarm"
type: "feature"
created: "2026-09-11"
status: "done"
route: "dispatch"
review_loop_iteration: 0
baseline_commit: "30428b03142122699ac91bf6dc654d523051a0e4"
context:
  - _bmad-output/implementation-artifacts/epic-2-context.md
  - _bmad-output/implementation-artifacts/spec-2-5-ateroppning-av-fastnade-jobb.md
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The pipeline already counts per-source outcomes and typed `SchemaMismatch` failures in memory, but `ingest_run` stores only aggregate completed-run counts. An operator cannot reliably tell which source failed, whether an endpoint changed shape, or inspect recent alarms in one place.

**Approach:** Extend the append-only run log with per-source outcome counts, schema-mismatch count, and an alarm/status signal. Link successfully written owner rows to the run that produced them, preserve run visibility across normal and failed paths, and enrich the existing SSH inspection command so recent outcomes and alarms are visible together.

**Decisions:** Schema-mismatch alarms use PHP `mail()` in addition to the persisted alarm flag. A run is inserted at start and finished as failed/alarmed on caught aborts. Operator inspection remains in the existing `bin/show-runs.php` command; no public status endpoint is added.

## Boundaries & Constraints

**Always:**

- Keep all run-log and owner-row SQL behind `RunRepository` and `OwnerCountRepository`; pipeline classes never issue SQL.
- Preserve append-only owner facts and idempotence on `(isin, source, as_of_date)`; the nullable run link is metadata only and has no backfill.
- Store UTC timestamps, Stockholm `run_date`, and the existing `by_source` bucket names (`avanza`, `nordnet`, `ok`, `not_found`, `schema_mismatch`, `transient`, `retried`, `rate_limited`).
- Every completed fetch/enqueue/universe-sync attempt has an inspectable run outcome; any run with one or more `SchemaMismatch` results has `alarm = true`.
- Existing cron response shapes remain compatible; new observability fields are additive.

**Never:**

- Do not alter retry, queue state transitions, source parsing, universe reconciliation, or owner-count values.
- Do not backfill historical `ingest_run` or `owner_count_daily` rows and do not expose fetched data outside the existing personal-use boundary.
- Do not add a framework, parallel fetching, or an unprotected public status surface.
- Do not silently discard a run row when a pipeline aborts after its run has started.

## I/O & Edge-Case Matrix

| Scenario                  | Input / State                                          | Expected Output / Behavior                                                                        | Error Handling                                                 |
| ------------------------- | ------------------------------------------------------ | ------------------------------------------------------------------------------------------------- | -------------------------------------------------------------- |
| Clean fetch slice         | all source calls succeed                               | run row has per-source `ok_count`, zero schema mismatches, alarm false; owner rows link to its id | none                                                           |
| Partial source failure    | NotFound/Transient/SchemaMismatch for one source       | run row preserves each source bucket; other source continues; schema mismatch increments alarm    | existing job continuation/retry rules remain                   |
| Universe schema failure   | listing or guard raises `SchemaMismatch` before writes | an inspectable alarmed universe-sync outcome exists and no partial instrument writes occur        | endpoint keeps its existing failure response                   |
| Unexpected pipeline error | throwable after run start                              | run outcome is inspectable as failed/alarmed according to the chosen policy                       | error is logged and original HTTP/CLI failure behavior remains |
| Status inspection         | recent runs include clean and alarmed rows             | one existing operator inspection surface shows status, alarm, and per-source/schema counts        | empty result remains valid                                     |

</frozen-after-approval>

## Code Map

- `src/Store/RunRepository.php` and `src/Store/IngestRun.php` -- current append-only completion record and row value object; add lifecycle/detail fields and recent/alarm queries without SQL in callers.
- `src/Store/OwnerCountRepository.php` -- sole `owner_count_daily` writer; accept nullable ingest-run id and preserve first-write-wins behavior.
- `src/Pipeline/FetchRunner.php` -- already owns canonical `bySource`; start/finish the run and thread its id into successful owner writes without changing fetch decisions.
- `src/Pipeline/Enqueue.php` and `src/Pipeline/UniverseSync.php` -- current run-log callers; preserve enqueue/churn semantics and make abort outcomes inspectable.
- `db/migrations/` and `tests/Store/StoreTestCase.php` -- add the run-log columns, nullable owner-row FK, and matching integration-test schema.
- `src/Store/RunTable.php` and `bin/show-runs.php` -- existing DB-free renderer and SSH inspection command; display alarm and detailed counts in one place.
- `public_html/index.php` -- existing token-guarded cron responses; retain current status codes and add only compatible observability fields if needed.
- `tests/Store/RunRepositoryTest.php`, `tests/Store/RunTableTest.php`, `tests/Pipeline/FetchRunnerTest.php`, `tests/Pipeline/UniverseSyncTest.php`, `tests/FrontControllerIntegrationTest.php` -- regression and edge-case coverage seams.

## Tasks & Acceptance

**Execution:**

- [x] `db/migrations/` + `tests/Store/StoreTestCase.php` -- add the schema for per-source counts, schema-mismatch count, alarm/status, and nullable `owner_count_daily.ingest_run_id`; preserve existing keys and FKs.
- [x] `src/Store/RunRepository.php` + `src/Store/IngestRun.php` -- implement the selected run lifecycle, detail persistence, and recent/alarmed queries behind typed rows.
- [x] `src/Store/OwnerCountRepository.php` + `src/Pipeline/FetchRunner.php` -- associate successful owner rows with the active run id without changing append-only semantics or fetch tallies.
- [x] `src/Pipeline/Enqueue.php` + `src/Pipeline/UniverseSync.php` + `public_html/index.php` -- record normal and selected failure outcomes, preserve current endpoint behavior, and surface alarm state additively.
- [x] `src/Store/RunTable.php` + `bin/show-runs.php` -- show recent run status, alarm, source counts, and schema mismatches in one operator view.
- [x] `tests/Store/RunRepositoryTest.php`, `tests/Store/RunTableTest.php`, `tests/Pipeline/FetchRunnerTest.php`, `tests/Pipeline/UniverseSyncTest.php`, `tests/FrontControllerIntegrationTest.php` -- cover every matrix row and compatibility behavior.
- [x] `docs/deploy.md` -- document the inspection command, alarm meaning, and any selected delivery configuration.

**Acceptance Criteria:**

- Given a completed run, when it is persisted, then `ingest_run` contains per-source outcomes and the schema-mismatch count, and successful owner rows link to that run id.
- Given at least one `SchemaMismatch`, when the run ends or aborts, then its persisted alarm signal is true and the operator can see it in the selected inspection surface.
- Given a normal source failure or retry, when the pipeline completes, then existing continuation, queue, retry, and idempotence behavior is unchanged.
- Given a status inspection with recent clean and alarmed runs, when the operator runs the selected command or endpoint, then both outcomes and their alarm/details appear together.
- Given `composer test`, then the full suite is green and no historical rows are backfilled or rewritten.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Verdict     | Finding                                                                                    | Evidence and route                                                                                                                                                                                               |
| ----------- | ------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| false       | `alarm.email` is absent from `config.php.dist`.                                            | The approved design stores the recipient in the existing database `settings` table, not application config; deployment documentation now lists the optional setting and its absent/invalid behavior.             |
| maybe-false | An unexpected outer fetch exception loses partial counters.                                | The normal per-job unexpected path already catches and records the failed job; only an exception outside that loop can reach the wrapper, and no reproducible user-visible partial-counter case was established. |
| patch       | Enqueue operational failures were marked as schema alarms.                                 | Fixed by finishing those rows with `status=failed` and `alarm=false`; schema mismatch counts still force alarms in `RunRepository`.                                                                              |
| patch       | Callers could override a nonzero schema mismatch count with `alarm=false`.                 | `RunRepository::finish()` now forces `alarm=true` whenever the persisted source details contain a schema mismatch.                                                                                               |
| false       | Process termination can leave a `running` row.                                             | The approved decision covers caught aborts; a process kill cannot execute PHP cleanup, and adding stale-run recovery was not part of the approved story behavior.                                                |
| false       | The renderer lacks a dedicated schema-mismatch column.                                     | The rendered `by_source` detail includes each source's `schema_mismatch` count, so the operator can inspect the requested count in the one approved SSH surface.                                                 |
| false       | `--alarms` combined with `--date` silently ignores the date.                               | This is an unrequested option combination; the documented alarm mode intentionally selects recent alarms globally, while date filtering remains the normal inspection mode.                                      |
| patch       | Existing production migrations were not asserted for the new columns and FK.               | `MigrationTest` now checks status, alarm, mismatch count, JSON detail storage, owner run-link type, and the reference to `ingest_run`.                                                                           |
| patch       | The `--alarms` subprocess path lacked end-to-end coverage.                                 | `ShowRunsScriptTest` now inserts clean and alarmed rows and asserts the alarm mode includes only the alarmed row; it skips when the development DB is still on the pre-2.6 schema.                               |
| patch       | PHP mail delivery lacked verification.                                                     | Both pipeline classes now accept an injected mail callable while defaulting to PHP `mail()`, and fetch/universe schema-alarm tests assert the configured recipient is invoked.                                   |
| patch       | Nullable `finished_at` was coerced to an empty string.                                     | `IngestRun::fromRow()` now preserves `null` for running rows.                                                                                                                                                    |
| false       | Schema alarm lookup failures were reported as successful fetch failures.                   | The cited guard does not exist in the implementation; settings lookup is outside the alarm delivery callback and the callback is only invoked after a completed alarm decision.                                  |
| patch       | UniverseSync per-instrument schema mismatches were swallowed without alarm telemetry.      | Resolution catches now increment the source-specific mismatch bucket, and successful universe runs persist those buckets; the wrapper also persists an alarmed mismatch outcome for aborts.                      |
| false       | Persisting `{}` for old `by_source` values violates the no-backfill rule.                  | The migration normalizes new metadata columns on pre-existing run rows without rewriting owner facts or historical run outcomes; this is required to make the new non-null column valid.                         |
| patch       | Migration rollback could fail on unfinished rows when restoring `finished_at` to NOT NULL. | The down migration now fills unfinished timestamps from `started_at` before restoring the old constraint.                                                                                                        |
| false       | Malformed internal `by_source` JSON could crash inspection.                                | The value is written only by `RunRepository` as validated JSON from typed counters; malformed database content is outside the approved input contract.                                                           |

## Verification

**Commands:**

- `composer test` -- expected: full suite passes with migration, lifecycle, alarm, linkage, and inspection coverage.
- `composer validate --strict` -- expected: Composer configuration remains valid.
