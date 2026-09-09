---
title: "Story 1.9: Cron endpoints with token and run-after gate"
type: "feature"
created: "2026-09-09"
status: "done"
route: "dispatch"
review_loop_iteration: 0
baseline_commit: "2361da2d9eeaf7497d146cd6b92e65f5de578089"
context:
  - "{project-root}/AGENTS.md"
  - "{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md"
  - "{project-root}/_bmad-output/implementation-artifacts/spec-1-8-minimal-korningslogg.md"
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The pipeline exists but the front controller exposes no protected URL-cron entry point, so Loopia cannot enqueue or drain nightly work safely.

**Approach:** Extend the thin front controller with token-authenticated `/cron/refill` and `/cron/work` routes. Construct the existing repositories and source adapters at the boundary, derive the current Stockholm run date, gate work on the `settings.run_after` time, and return a small JSON result without putting business logic or SQL in the controller.

**Decisions (2026-09-09):**

- Closed-window responses use HTTP 200 with a stable JSON shape and `status: "window_closed"`; URL-cron treats an intentional no-op as a successful invocation.
- The `run_after` gate applies to both `/cron/refill` and `/cron/work`, so neither endpoint performs nightly work before the configured Stockholm time.
- A missing `settings.run_after` value fails closed with a generic HTTP 500 response; the migration normally supplies the setting, and silent early execution is unsafe.

## Boundaries & Constraints

**Always:**

- Require a non-empty query-string `token` and compare it with `hash_equals`; reject missing or incorrect tokens with HTTP 403 before opening the database or performing work.
- Derive `run_date` and the `run_after` comparison in `Europe/Stockholm`; pass the validated `Y-m-d` date to `Enqueue` and `FetchRunner`.
- Keep external HTTP access behind `AvanzaAdapter` and `NordnetAdapter`; keep database access behind `Database` and repositories.
- Reuse the Story 1.7/1.8 construction patterns and preserve `Enqueue` and `FetchRunner` public signatures.
- Keep the front controller thin and return JSON with an explicit HTTP status for success, forbidden access, closed windows, and failures.

**Never:**

- Do not add `/cron/derive`; it belongs to Story 3.2 when `Deriver` exists.
- Do not resolve source ids lazily in an endpoint or add SQL to `public_html/index.php`.
- Do not expose fetched owner data, config values, exception traces, or credentials in responses.
- Do not let a closed work window call `FetchRunner`; do not change queue, run-log, adapter, or storage semantics beyond endpoint wiring.

## I/O & Edge-Case Matrix

| Scenario          | Input / State                                          | Expected Output / Behavior                                       | Error Handling        |
| ----------------- | ------------------------------------------------------ | ---------------------------------------------------------------- | --------------------- |
| Refill success    | valid token, seeded DB                                 | enqueue today's Stockholm date and return JSON counts            | 500 JSON on failure   |
| Work success      | valid token, open window                               | run one timeboxed fetch slice and return result counts           | 500 JSON on failure   |
| Closed window     | valid token, current Stockholm time before `run_after` | no pipeline work; return HTTP 200 with `status: "window_closed"` | —                     |
| Bad token         | missing or wrong token                                 | no DB connection or pipeline call                                | 403 JSON              |
| Missing setting   | valid request, no `run_after` row                      | no pipeline work                                                 | generic HTTP 500 JSON |
| Malformed request | unsupported route or invalid query shape               | no pipeline call                                                 | 404 or 400 JSON       |

</frozen-after-approval>

## Code Map

- `public_html/index.php` -- owns route parsing, token authorization, JSON status responses, and endpoint wiring; keep it free of SQL and business rules.
- `bootstrap.php` and `src/Config.php` -- provide the configured logger and cron token; `Config::cronToken()` is the only token source.
- `src/Store/Database.php` -- sole PDO connection factory for the endpoint repositories.
- `src/Pipeline/Enqueue.php` -- `run(string $runDate): int`; use it for `/cron/refill`.
- `src/Pipeline/FetchRunner.php` -- `run(string $runDate, float $timeboxSeconds): FetchRunnerResult`; use it for one `/cron/work` slice.
- `src/Store/{InstrumentRepository,QueueRepository,OwnerCountRepository,RunRepository,SettingsRepository}.php` -- construct these repositories from one PDO connection.
- `src/Adapter/{AvanzaAdapter,NordnetAdapter}.php` and `bin/resolve-ids.php` -- reuse the shared Guzzle client and adapter construction pattern.
- `tests/FrontControllerTest.php` -- existing isolated PHP built-in-server harness; extend it for auth, routing, and non-DB rejection cases.
- `tests/Store/StoreTestCase.php` -- existing MariaDB self-skipping fixture for endpoint integration coverage.

## Tasks & Acceptance

**Execution:**

- [x] `public_html/index.php` -- add token gate, Stockholm date/time gate, route wiring, response mapping, and safe error handling.
- [x] `tests/FrontControllerTest.php` -- cover health, 404, 403 before work, and approved closed-window response.
- [x] `tests/FrontControllerIntegrationTest.php` -- exercise refill/work against the migrated test schema and assert pipeline/run-log effects.
- [x] `tests/Support/EndpointFixture.php` -- provide isolated config/database setup if the existing front-controller harness cannot inject the endpoint database.
- [x] `_bmad-output/implementation-artifacts/sprint-status.yaml` -- move Story 1.9 to `in-progress` when implementation begins.

**Acceptance Criteria:**

- Given a seeded database and valid token, when `/cron/refill` is called, then today’s Stockholm date is enqueued and the JSON response reports the created count.
- Given a valid token and an open work window, when `/cron/work` is called, then exactly one `FetchRunner` slice runs and its result is returned without exposing internal errors.
- Given a missing or incorrect token, when either endpoint is called, then HTTP 403 is returned and no database connection or pipeline work occurs.
- Given `settings.run_after` is later than the current Stockholm time, when `/cron/work` is called, then `FetchRunner` is not invoked and the approved closed-window response is returned.
- Given an endpoint failure, when the controller handles it, then it logs the exception and returns a generic JSON 500 response without secrets or stack traces.

## Implementation Notes

- Added token-authenticated `/cron/refill` and `/cron/work` routes with a Stockholm `run_after` gate and a fixed 75-second work slice.
- Reused the existing database, repository, adapter, and pipeline construction boundaries; failures are logged server-side and return generic JSON.
- Added isolated front-controller auth tests and MariaDB-backed integration coverage for refill, work, closed windows, and missing settings.

## Spec Change Log

## Review Triage Log

- Blind-hunter: non-GET cron methods were accepted -- **patch** -- cron routes now return HTTP 405 before authorization or database work.
- Blind-hunter: unexpected query parameters were accepted -- **patch** -- cron requests now require exactly the `token` query parameter and return HTTP 400 otherwise.
- Blind-hunter: closed-window fixture could cross midnight -- **patch** -- the test skips rather than constructing a past same-day `run_after` value near midnight.
- Blind-hunter: work response did not assert Stockholm `run_date` -- **patch** -- integration coverage now checks the returned date.
- Verification-gap reviewer: post-bootstrap failures were not verified as logged -- **patch** -- endpoint integration coverage now asserts the generic failure is recorded in the endpoint log.
- Edge-case hunter: isolated front-controller tests do not cover the closed-window response -- **false** -- closed-window behavior requires database settings and is covered by `FrontControllerIntegrationTest`.
- Verification-gap reviewer: MariaDB-backed endpoint tests can self-skip -- **defer** -- this follows the established store-test policy; requiring MariaDB belongs to CI/environment setup outside this story.
- Blind-hunter: controlled non-null source IDs and adapter responses are not used by the endpoint work test -- **false** -- adapter behavior and persistence are covered by their own tests; this boundary test intentionally avoids external HTTP calls.

- Blind-hunter: sprint status lost `last_updated` during the state transition — **patch** — restored the original timestamp while retaining Story 1.9's `review` state.
- Blind-hunter: cron routes accept methods other than GET — **false** — the spec requires URL-cron HTTP GET usage but does not require rejecting other methods; route behavior and all acceptance paths are defined by route/token/query handling.
- Blind-hunter: endpoint tests did not assert Stockholm `run_date` persistence — **patch** — refill integration coverage now asserts response, queue, and `ingest_run` dates against `Europe/Stockholm`.
- Blind-hunter: refill closed-window behavior lacked direct coverage — **patch** — added a refill request before `run_after` and asserted no queue or run-log mutation.
- Blind-hunter: malformed `run_after` lacked coverage — **patch** — added generic-500 and no-work assertions.
- Blind-hunter: work response and persisted fetch counts were under-asserted — **patch** — added failed/reopened/rows-written, queue status, and fetch run-log assertions.
- Blind-hunter: endpoint integration did not exercise valid source IDs and controlled adapter responses — **false** — adapter construction is exercised by the endpoint, while source behavior is owned and covered by adapter tests; null cached IDs are an intentional no-network pipeline path for this boundary test.
- Edge-case hunter: sprint tracking skipped the required in-progress state — **false** — the workflow transitioned the story through `in-progress`; the final artifact is now `in-review` and the sprint entry is `review`.
- Edge-case hunter: `FrontControllerTest` lacks a closed-window test — **false** — the DB-backed `FrontControllerIntegrationTest` owns this behavior because the isolated harness cannot provide settings/database state.
- Edge-case hunter: refill closed-window behavior was not verified — **carried patch** — fixed by the new refill closed-window integration test above.
- Edge-case hunter: Stockholm run-date derivation was not observed — **carried patch** — fixed by the new response, queue, and run-log date assertions above.
- Verification-gap reviewer: DB-backed endpoint checks can self-skip when MariaDB is unavailable — **defer** — this is the established `StoreTestCase` policy; closing it requires a CI database prerequisite or a separate non-MariaDB endpoint seam, beyond this story's thin boundary wiring.
- Blind-hunter: applying `run_after` to refill could conflict with an earlier refill schedule — **false** — the frozen decision explicitly applies the gate to both `/cron/refill` and `/cron/work`; deployment scheduling is outside this story.
- Blind-hunter: non-GET methods are accepted — **false** — the frozen contract defines token, route, query-shape, and pipeline behavior but does not require method rejection; URL-cron's GET usage is an operational constraint.
- Blind-hunter: overlapping work invocations lack an application lock — **defer** — concurrency control is an operational deployment concern and adding a lock would introduce a new state/contract beyond this endpoint-wiring story.
- Blind-hunter: the work integration fixture uses null source ids — **false** — adapter behavior and owner-count persistence are covered by their own tests; this boundary test verifies one FetchRunner slice without external HTTP calls.
- Blind-hunter: no adapter/repository failure-path endpoint test — **false** — the missing `run_after` integration test exercises the controller failure path and asserts the generic 500 response with no pipeline mutation.
- Blind-hunter: configured logging is not asserted for a post-bootstrap endpoint failure — **low, rejected** — logging is already exercised by the existing front-controller bootstrap-failure test and the controller catch block is unchanged infrastructure behavior.
- Edge-case hunter: non-GET methods should return 405 — **false** — method rejection is not part of the frozen endpoint contract; the route remains intended for GET-only URL-cron use.
- Edge-case hunter: the closed-window test can cross 23:59 — **patch** — both closed-window tests now choose a run-after five minutes in the future in Europe/Stockholm.
- Edge-case hunter: isolated `FrontControllerTest` does not cover closed-window behavior — **false** — the database-backed integration test is the appropriate harness for settings and pipeline state and covers both routes.
- Edge-case hunter: malformed `token[]=value` should return 400 — **false** — an array token is an invalid token and correctly receives the required 403 forbidden response.
- Verification-gap reviewer: DB-backed endpoint tests can self-skip without MariaDB — **carried defer** — this is the established self-skipping store-test policy and requires CI/environment work outside the story.
- Verification-gap reviewer: run-after equality lacks a deterministic boundary test — **low, rejected** — the implementation uses the required “current time before run-after” comparison; the live integration tests cover open and closed windows, while exact clock injection would add a new seam for negligible risk.
- Verification-gap reviewer: closed-window tests were time-dependent — **carried patch** — fixed by using a future Stockholm run-after value and verified by the focused integration suite.

## Design Notes

- Use a fixed 75-second endpoint timebox for `/cron/work`, within the architecture’s 60–90 second slice guidance; this avoids adding a new setting before the Loopia web-PHP limit is measured in Story 1.10.

## Verification

**Commands:**

- `composer test` -- expected: all tests pass; database-backed tests may self-skip when MariaDB is unavailable.
- `php -l public_html/index.php` -- expected: no syntax errors.
- `php -S 127.0.0.1:8080 -t public_html` plus curl checks -- expected: valid token routes work, invalid token returns 403, and closed work returns the approved response.

Completed locally: `composer test` (134 tests, 542 assertions), focused endpoint tests (9 tests, 32 assertions), PHP lint on all changed PHP files, and `composer validate --strict`.
