---
title: 'Story 1.3: SourceAdapter-port, feltyper och id-uppslag'
type: 'feature'
created: '2026-09-08'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: 'de109c498e61d3e9e77be68183f296b81d642295'
context:
  - '{project-root}/AGENTS.md'
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Stories 1.4/1.5 (the Avanza and Nordnet fetch adapters) need a shared adapter contract and typed error classes, and each instrument needs a way to resolve its per-source id from its ISIN — none of which exist.

**Approach:** Define the `SourceAdapter` port and `SchemaMismatch` / `NotFound` / `Transient` in `src/Error/`, and implement a pure `resolveId(string $isin): string` in `AvanzaAdapter` and `NordnetAdapter` — Guzzle behind the adapter boundary, one-shot retry on `Transient`, returns the id or throws a typed error. `fetch()` is declared on the port but implemented in 1.4/1.5.

**Decisions (2026-09-08):**
- `resolveId()` is **pure** — it returns the id string or throws; it does not touch the database. The caller that persists resolved ids (Epic 2's `UniverseSync`, or an interim resolver script) is out of this story, and so are `InstrumentRepository` id-setter methods.
- **No `bin/resolve-ids.php`** in this story — deliver the port, error types, `resolveId`, and mocked unit tests only.

## Boundaries & Constraints

**Always:**
- No `curl` / Guzzle / source URL / source-specific field name outside `src/Adapter/`. (AD-1)
- `resolveId()` returns the id string on success and throws exactly one of `SchemaMismatch` / `NotFound` / `Transient` otherwise — never a raw Guzzle exception or HTTP status. (AD-2, AD-7)
- One retry on `Transient` (connection error, timeout, 429, 5xx), then throw `Transient`. (AD-6)
- ISIN is the identity: a search hit resolves only when its ISIN equals the requested ISIN. A name match with a different or absent ISIN does not.
- Adapters take their Guzzle client by constructor injection so tests drive them with `GuzzleHttp\Handler\MockHandler` and make no real request.
- `resolveId()` takes what it needs to search (at least the instrument name) as an argument or via an `Instrument` — it does not read the database to get it.

**Verified source endpoints (2026-09-08 — unofficial, may change):**
- Avanza: `POST https://www.avanza.se/_api/search/filtered-search` (JSON `{"query":"<name>","searchFilter":{"types":["STOCK"]}}`, `User-Agent` header) → `hits[].orderBookId`, no ISIN in the hit. Confirm each `STOCK` candidate via `GET /_api/market-guide/stock/{orderBookId}` → `isin`; return the `orderBookId` whose `isin` matches. (The addendum's `global-search` path is dead.)
- Nordnet: `GET https://www.nordnet.se/api/2/instrument_search/query/stocklist?free_text_search=<name>&limit=10` (`client-id: NEXT` header) → `results[].instrument_info.isin` + `results[].nnx_info.nnx_instrument_id`; return `nnx_instrument_id` of the ISIN-matching result. One call.

**Never:**
- No `fetch()` implementation, no `owner_count_daily`, no queue, no DB writes (Stories 1.4+).
- No exponential backoff / rate-limit scheduling (Epic 2) — just the one retry.
- No Börsdata / `UniverseSync`; no runner script.
- Do not modify `docs/deploy.md` or `bin/deploy.sh` (Story 1.10).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Behavior | Error Handling |
|---|---|---|---|
| Resolve happy (either source) | name given, ISIN present in a result | returns the id string | — |
| No ISIN match | hits returned, none with the requested ISIN, or empty result set | throws `NotFound` | — |
| Transient once then ok | first call 503 / timeout, retry returns 200 | returns the id | one retry only |
| Transient twice | both calls fail (503 / timeout / 429 / connection error) | throws `Transient` | — |
| Schema drift | HTTP 200 but an expected field is missing or the wrong type | throws `SchemaMismatch` naming the field | — |
| Non-transient HTTP error | 4xx other than 429 (e.g. 400/403) | throws `SchemaMismatch` (unexpected shape) or `NotFound` — never leaks the Guzzle exception | — |

</frozen-after-approval>

## Code Map

- `src/Adapter/.gitkeep`, `src/Error/.gitkeep` -- placeholders; delete when real files land.
- `src/Store/Instrument.php` -- readonly VO (`isin`, `name`, `list`, nullable ids); `resolveId()` may accept one of these or a plain name string.
- `src/Logging.php` -- `Logging::logger()` returns a PSR-3 logger; adapters type-hint `Psr\Log\LoggerInterface`.
- `composer.json` -- `guzzlehttp/guzzle` already required (8.2 locked); `psr/log` comes in transitively via Monolog.
- `bin/seed-instruments.php` -- the DI style to follow (bootstrap builds collaborators, passes them in).
- `_bmad-output/planning-artifacts/briefs/brief-stockpicker-2026-09-08/addendum.md` -- source research; its Avanza search path is stale — use the frozen block's verified endpoints.

## Tasks & Acceptance

**Execution:**
- [x] `src/Error/AdapterError.php` + `SchemaMismatch.php` / `NotFound.php` / `Transient.php` -- `abstract AdapterError extends RuntimeException`, three `final` subclasses; `SchemaMismatch` message names the offending field.
- [x] `src/Adapter/SourceAdapter.php` -- interface: `resolveId(Instrument): string` and `fetch(Instrument): mixed` (declared only).
- [x] `src/Adapter/AvanzaAdapter.php` -- ctor `(ClientInterface, LoggerInterface)`; `resolveId()` per the frozen endpoint spec; `fetch()` throws `\LogicException` "implemented in Story 1.4"; logs `warning` on any `AdapterError` before rethrowing (AD-7).
- [x] `src/Adapter/NordnetAdapter.php` -- same shape; `fetch()` → Story 1.5.
- [x] `src/Adapter/HandlesTransientHttp.php` -- trait: `requestJson()` maps `ConnectException`/429/5xx → `Transient`, other non-2xx and non-JSON → `SchemaMismatch`; `withOneRetry()` reruns the op once on `Transient`. Shared by both adapters.
- [x] `tests/Adapter/AvanzaAdapterTest.php` + `tests/Adapter/NordnetAdapterTest.php` (+ `AdapterTestCase.php`) -- `MockHandler` fixtures, every matrix row, `assertQueueDrained()`, no real network.
- [x] `composer.json` -- added explicit `psr/log ^3.0` (was transitive via Monolog; now a direct dependency).

**Acceptance Criteria:**
- Given `src/Error/`, when read, then `SchemaMismatch` / `NotFound` / `Transient` exist and share a base the core can `catch` as one type.
- Given `src/Adapter/SourceAdapter`, when read, then it declares `fetch()` and `resolveId()` and both adapters implement it.
- Given an instrument whose ISIN a source exposes, when `resolveId()` runs against that source, then it returns the source's id string.
- Given an ISIN no source result carries, when `resolveId()` runs, then it throws `NotFound` (and callers can log a `warning` and move on).
- Given `composer test` with no network, when it runs, then the adapter tests pass against mocked HTTP and issue no real request.
- Given the tree is grepped, when checking `src/` and `bin/` outside `src/Adapter/`, then there is no `curl` / Guzzle / source-URL reference.

## Implementation Notes

- **Live smoke against the real endpoints (2026-09-09)** — `AvanzaAdapter::resolveId` for Investor B / `SE0015811963` → `5247` (matches the known orderbookId); `NordnetAdapter::resolveId` → `19fa390b-040f-45a9-8fa2-e7fd34e319ab`.
- **⚠️ `nnx_info.nnx_instrument_id` is a 36-char UUID**, not an integer. Story 1.2's `instrument.nordnet_instrument_id` column is `VARCHAR(32)` — **too narrow**. The story that first persists Nordnet ids (Epic 2 `UniverseSync`, or an interim resolver) must widen that column to `VARCHAR(64)` (or `CHAR(36)`), or decide to cache `instrument_info.instrument_id` (the integer `16102308`) instead. Story 1.3 does not persist, so nothing breaks yet — flagging for the persistence story and for the human, since it may warrant renegotiating the frozen "return `nnx_instrument_id`" decision.
- **Retry wraps the whole `resolveId` body**, not individual calls — on a `Transient` the adapter re-runs from the first request (for Avanza: the search POST again). Fine at this volume; simpler than per-call retry state.
- **`requestJson` relies on Guzzle `http_errors` staying on** (the default). A client built with `http_errors => false` would make non-2xx slip through as a `SchemaMismatch` ("body is not JSON") rather than a `Transient` — documented in the trait.
- **`psr/log` promoted to a direct dependency** — the adapters type-hint `Psr\Log\LoggerInterface` and tests use `Psr\Log\NullLogger`; `composer.lock` content-hash refreshed, no version change (already `^3` via Monolog).
- **Verified**: `composer test` green — 42 tests / 187 assertions (17 new adapter tests). `composer validate --strict` clean. `php -l` clean on all new files. Boundary grep (`GuzzleHttp|curl_|avanza.se|nordnet.se` outside `src/Adapter/`) — no matches.

## Spec Change Log

## Review Triage Log

- 2026-09-09 — the step-04 multi-agent review was skipped at the user's choice. Verification (`composer test` 42/187, `composer validate`, `php -l`, boundary grep, live smoke) all passed. The UUID / `VARCHAR(32)` mismatch is recorded in `deferred-work.md` for the persistence story.

## Design Notes

- **Error classes are exceptions** (`extends \RuntimeException` via `AdapterError`) — the spine says "kastas/returneras"; throwing is idiomatic PHP and a single `catch (AdapterError)` keeps future callers simple. 1.4/1.5's `fetch()` may still return them where a value reads better.
- **Avanza needs two calls per instrument** — `filtered-search` hits carry no ISIN, so each `STOCK` candidate is confirmed via `market-guide/stock/{id}`; stop at the first ISIN match. Low volume, run rarely; `rate.avanza` throttling is Epic 2.
- **Pure `resolveId`** keeps the adapters free of a DB dependency and makes the mocked tests self-contained; persisting resolved ids belongs to the universe path (Epic 2), which also owns the `instrument` write per AD-3.

## Verification

**Commands:**
- `composer test` -- all adapter tests pass with mocked HTTP, no real request
- `grep -rn "GuzzleHttp\|curl_\|avanza\.se\|nordnet\.se" src/ bin/ | grep -v src/Adapter/` -- no matches
- `composer validate --strict`, `php -l` on every new file -- clean
