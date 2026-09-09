---
title: 'Story 1.5: Nordnet-hämtningsadapter med schemakoll'
type: 'feature'
created: '2026-09-09'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: 'e26b110d3f4be3e5ff66443635bb033be351e7d4'
context:
  - '{project-root}/AGENTS.md'
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `NordnetAdapter::fetch()` still throws "implemented in Story 1.5". The pipeline needs a Nordnet owner-count datapoint per instrument, independent of Avanza, schema-checked so a silent field change never becomes a `null` in the series.

**Approach:** Implement `NordnetAdapter::fetch(Instrument)` — GET the public `stocklist` search, match the result on ISIN, validate, return a `NormalizedRow` (with `sourceTimestamp` from Nordnet's `statistics_timestamp`) or throw `SchemaMismatch` / `Transient` / `NotFound`. Reuse `HandlesTransientHttp` and dedupe the `stocklist` search/match with the existing `resolveId()`.

## Boundaries & Constraints

**Always:**
- `number_of_owners` is load-bearing: missing, non-int, or negative ⇒ `SchemaMismatch` (never a row with `number_of_owners = null`), `warning` logged (AD-7).
- `statistics_timestamp` is load-bearing (it dates the datapoint — Story 1.6 derives `as_of_date` from it): missing or non-numeric ⇒ `SchemaMismatch`. When present it becomes `NormalizedRow.sourceTimestamp` (a UTC `DateTimeImmutable` from the epoch-ms value).
- `last_price` and `market_cap` are nullable — absent or unparseable ⇒ `null`, do not fail the fetch.
- One retry on `Transient` (429 / 5xx / timeout / connection error), then throw `Transient` — the shared helper.
- Match is by ISIN: a `stocklist` result counts only when `instrument_info.isin` equals the requested ISIN. No ISIN match (or empty results) ⇒ `NotFound`.
- `fetch()` requires `Instrument.nordnetInstrumentId` to be non-null (the "was resolved" gate, mirroring Avanza); `null` ⇒ `NotFound` without a request. The id itself is not a request parameter — `stocklist` is a free-text search only.
- No `curl` / Guzzle / `nordnet.se` outside `src/Adapter/`. (AD-1)
- Guzzle client is constructor-injected; tests use `MockHandler` and make no real request.

**Verified Nordnet response (Investor B, 2026-09-09):**
- `GET https://www.nordnet.se/api/2/instrument_search/query/stocklist?free_text_search=<name>&limit=10` (`client-id: NEXT` header, no session)
- `results[].instrument_info.isin`
- `results[].statistical_info.number_of_owners` (int, e.g. `69611`)
- `results[].statistical_info.statistics_timestamp` (epoch ms, e.g. `1788955452687`)
- `results[].price_info.last.price` (float, e.g. `401.4`)
- `results[].company_info.market_cap` (int, e.g. `1238152058906`)

**Never:**
- No `owner_count_daily` write / `OwnerCountRepository` (Story 1.6), no queue (Story 1.7).
- No `as_of_date` computed here — `NormalizedRow` has no such field (Story 1.4 decision).
- No Avanza changes. No rate limiting / backoff beyond the single retry (Epic 2).
- Do not modify `docs/deploy.md` or `bin/deploy.sh` (Story 1.10).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Behavior | Error Handling |
|---|---|---|---|
| Happy | resolved instrument, ISIN present in a result | `NormalizedRow` `source='nordnet'`, int `number_of_owners`, `sourceTimestamp` (UTC from `statistics_timestamp`), `fetchedAt` (UTC now) | — |
| price absent | no `price_info.last.price` | row with `last_price = null` | — |
| market_cap absent | no `company_info.market_cap` | row with `market_cap = null` | — |
| owners missing / wrong type | `number_of_owners` absent, string, or negative | throws `SchemaMismatch` naming the field | `warning` logged |
| timestamp missing / non-numeric | `statistics_timestamp` absent or not a number | throws `SchemaMismatch` | `warning` logged |
| no ISIN match | results returned, none with the requested ISIN, or empty | throws `NotFound` | — |
| unresolved instrument | `Instrument.nordnetInstrumentId` is `null` | throws `NotFound` (no request made) | — |
| transient once then ok | 503 then 200 | `NormalizedRow` | one retry |
| transient twice | 503 / timeout both attempts | throws `Transient` | — |

</frozen-after-approval>

## Code Map

- `src/Adapter/NordnetAdapter.php` -- has `resolveId()` + `lookupInstrumentId()` (GET `stocklist`, iterate `results`, match `instrument_info.isin`, read `nnx_info.nnx_instrument_id`), `HandlesTransientHttp`, `BASE_URI` / `SEARCH_PATH` / `USER_AGENT` consts. Add `fetch()`; extract the search+match so `resolveId` and `fetch` share it.
- `src/Adapter/NormalizedRow.php` -- the return type; `SOURCE_NORDNET` const; `sourceTimestamp` (?DateTimeImmutable).
- `src/Adapter/HandlesTransientHttp.php` -- `requestJson()` + `withOneRetry()`, unchanged.
- `src/Adapter/AvanzaAdapter.php` -- reference for the `fetch()` shape (guard → `withOneRetry` → validate → build row → `warn()` on `AdapterError`).
- `src/Store/Instrument.php` -- `fetch()` reads `->nordnetInstrumentId` (gate) and `->isin` (+ `->name` for the search).
- `tests/Adapter/NordnetAdapterTest.php` + `AdapterTestCase.php` -- `instrument()` already takes `nordnetInstrumentId`; add a `fetch` group of `MockHandler` tests.

## Tasks & Acceptance

**Execution:**
- [x] `src/Adapter/NordnetAdapter.php` -- `fetch()`: null `nordnetInstrumentId` → `NotFound`; `withOneRetry` around a `stocklist` name search; `matchByIsin()` on `instrument_info.isin` (shared with `resolveId`); validates `statistical_info.number_of_owners` (non-negative int) and `statistical_info.statistics_timestamp` (int|float → UTC `DateTimeImmutable` via `@seconds`); `numeric()` helper for nullable `price_info.last.price` / `company_info.market_cap`; `warn()` on any `AdapterError`. Extracted `searchResults()` + `matchByIsin()`; `resolveId` refactored onto them.
- [x] `tests/Adapter/NordnetAdapterTest.php` -- 11 `fetch` tests covering every matrix row; asserts `sourceTimestamp` timestamp + UTC zone.

**Acceptance Criteria:**
- Given an instrument with a valid `nordnet_instrument_id`, when `fetch()` runs, then it returns a `NormalizedRow` with `source='nordnet'`, an int `number_of_owners`, `sourceTimestamp` set from `statistics_timestamp`, and a UTC `fetched_at`.
- Given a response missing `number_of_owners` (or `statistics_timestamp`) or with a wrong-typed value, when the adapter validates it, then it throws `SchemaMismatch` (never a null-owners row) and logs a `warning`.
- Given an HTTP error or no response, when the adapter receives it, then it retries once; if that also fails it throws `Transient`.
- Given `composer test` with no network, when it runs, then the Nordnet `fetch` tests pass against mocked HTTP and issue no real request.
- Given the tree is grepped outside `src/Adapter/`, then there is still no `curl` / Guzzle / `nordnet.se` reference.

## Implementation Notes

- **Live smoke (2026-09-09)** — `NordnetAdapter::fetch()` for Investor B → `numberOfOwners=69611`, `lastPrice=401.70`, `marketCap≈1.24e12`, `sourceTimestamp` a recent UTC datetime (~20 min old — Nordnet's cadence is intraday, unlike Avanza's once-a-day). `fetchedAt` UTC.
- **`resolveId` and `fetch` now share `searchResults()` + `matchByIsin()`** — one `stocklist` GET definition, one ISIN-match loop. `resolveId`'s previous "matched result without a scalar nnx_instrument_id" behaviour is preserved.
- **`statistics_timestamp` strict** per the frozen block — string / missing → `SchemaMismatch`. Nordnet returns a bare epoch-ms integer today.
- **`stocklist` is search-only** — confirmed no by-id variant; `fetch` never uses `nordnet_instrument_id` in the request, only as the resolved-gate.
- **Verified**: `composer test` green — 65 tests / 229 assertions (11 new). `composer validate --strict` clean. `php -l` clean. Boundary grep — no matches. Diff touches only `NordnetAdapter` + its test (NormalizedRow / SourceAdapter / Avanza untouched).

## Spec Change Log

## Review Triage Log

- 2026-09-09 — step-04 multi-agent review skipped at the user's choice (as with 1.2–1.4). Verification (`composer test` 65/229, `composer validate`, `php -l`, boundary grep, live smoke) all passed.

## Design Notes

- **`stocklist` is search-only** — no by-id endpoint, so `fetch()` searches by `instrument->name` and matches on ISIN, exactly like `resolveId()`. The cached `nordnet_instrument_id` is only a precondition gate (an unresolved instrument is skipped). Extract the search+match once and call it from both.
- **`statistics_timestamp` is strict** — Nordnet's owner-count cadence is uncertain (addendum), so a datapoint without its own timestamp cannot be dated correctly; treat a missing/invalid one as `SchemaMismatch` rather than silently dating it "today".
- **Epoch-ms → UTC** — `DateTimeImmutable` via `@<seconds>` or `setTimestamp(intdiv(ms, 1000))`, `DateTimeZone('UTC')`. Sub-second precision is not needed for `as_of_date`.

## Verification

**Commands:**
- `composer test` -- all Nordnet tests (resolveId + fetch) pass on mocked HTTP
- `grep -rn "GuzzleHttp\|curl_\|avanza\.se\|nordnet\.se" src/ bin/ | grep -v src/Adapter/` -- no matches
- `composer validate --strict`, `php -l` on changed files -- clean
- one-off live smoke: `NordnetAdapter::fetch()` for Investor B returns a plausible `NormalizedRow` (owners ~70k, price ~400, mcap ~1.2e12, `sourceTimestamp` a recent UTC datetime)
