---
title: 'Story 1.4: Avanza-hämtningsadapter med schemakoll'
type: 'feature'
created: '2026-09-09'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: '33c61a614da937b38568f14c4eb956dcf9f3810a'
context:
  - '{project-root}/AGENTS.md'
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `SourceAdapter::fetch()` is declared but unimplemented and there is no normalized-row type. The pipeline (Stories 1.6/1.7) needs one Avanza owner-count datapoint per instrument, schema-checked so a silent field rename never becomes a `null` in the time series.

**Approach:** Define `NormalizedRow` (the adapter output contract, shared with Story 1.5) and implement `AvanzaAdapter::fetch(Instrument)` — GET `market-guide/stock/{avanza_orderbook_id}`, validate the response, return a `NormalizedRow` or throw `SchemaMismatch` / `Transient` / `NotFound`. Reuse the `HandlesTransientHttp` retry/classify plumbing from Story 1.3.

## Boundaries & Constraints

**Always:**
- `number_of_owners` is the load-bearing field: missing, non-int, or negative ⇒ `SchemaMismatch` (never a row with `number_of_owners = null`), logged `warning` (AD-7).
- `last_price` and `market_cap` are nullable — absent or unparseable ⇒ leave them `null`, do not fail the fetch.
- One retry on `Transient` (429 / 5xx / timeout / connection error), then throw `Transient` — same helper as Story 1.3.
- A `404` for the cached `avanza_orderbook_id` ⇒ `NotFound` (the id is stale), not `SchemaMismatch`.
- The returned row's `isin` is the requested instrument's ISIN; if `market-guide` reports a different `isin`, that is `SchemaMismatch`.
- No `curl` / Guzzle / `avanza.se` outside `src/Adapter/`. (AD-1)
- Guzzle client is constructor-injected; tests use `MockHandler` and make no real request.

**Verified Avanza response (orderbookId 5247, 2026-09-09):**
- `GET https://www.avanza.se/_api/market-guide/stock/{id}` (`User-Agent` header, no auth)
- `isin` (top level), `keyIndicators.numberOfOwners` (int, e.g. `533660`),
  `keyIndicators.marketCapital` = `{ "value": <float>, "currency": "SEK" }`,
  `quote.last` (float), `quote.timeOfLast` (epoch ms),
  `historicalClosingPrices.oneDay` (float — previous close).
- `last_price` source: `quote.last` when present, else `historicalClosingPrices.oneDay`, else `null`.
- `market_cap` source: `keyIndicators.marketCapital.value`, else `null`.

**Never:**
- No `owner_count_daily` write, no `OwnerCountRepository` (Story 1.6), no queue (Story 1.7).
- No Nordnet `fetch()` (Story 1.5) — only its signature is updated to the new return type.
- No rate limiting / backoff beyond the single retry (Epic 2).
- Do not modify `docs/deploy.md` or `bin/deploy.sh` (Story 1.10).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Behavior | Error Handling |
|---|---|---|---|
| Happy | valid `avanza_orderbook_id`, full response | `NormalizedRow` with `source='avanza'`, int `number_of_owners`, float `last_price` + `market_cap`, `fetched_at` (UTC now) | — |
| `quote` absent | no `quote`, has `historicalClosingPrices.oneDay` | row with `last_price` = the previous close | — |
| price fully absent | no `quote`, no `oneDay` | row with `last_price = null` | — |
| `marketCapital` absent | no `keyIndicators.marketCapital` | row with `market_cap = null` | — |
| owners missing / wrong type | `numberOfOwners` absent, string, or negative | throws `SchemaMismatch` naming the field | `warning` logged |
| ISIN mismatch | response `isin` ≠ requested | throws `SchemaMismatch` | `warning` logged |
| stale id | HTTP 404 | throws `NotFound` | — |
| transient once then ok | 503 then 200 | `NormalizedRow` | one retry |
| transient twice | 503 / timeout both attempts | throws `Transient` | — |
| unresolved instrument | `Instrument.avanzaOrderbookId` is `null` | throws `NotFound` (nothing to fetch) | — |

**Decision (2026-09-09) — `NormalizedRow` shape:** the adapter does **not** compute `as_of_date`. `NormalizedRow` carries `sourceTimestamp` (`?DateTimeImmutable`, UTC — `null` for Avanza, Nordnet's `statistics_timestamp` in Story 1.5) and `fetchedAt` (`DateTimeImmutable`, UTC now). Story 1.6's `OwnerCountRepository` performs the single `Europe/Stockholm` conversion to `as_of_date` (from `sourceTimestamp` when present, else the run date), per Story 1.1's Design Notes.

</frozen-after-approval>

## Code Map

- `src/Adapter/SourceAdapter.php` -- `fetch(Instrument): mixed` today → narrow the return type to `NormalizedRow`.
- `src/Adapter/NordnetAdapter.php` -- `fetch()` throws `\LogicException`; update its signature to match, keep it throwing (Story 1.5).
- `src/Adapter/AvanzaAdapter.php` -- has `resolveId()` + `HandlesTransientHttp` + `BASE_URI` / `STOCK_PATH` / `USER_AGENT` constants; add `fetch()` reusing `requestJson()` + `withOneRetry()`.
- `src/Adapter/HandlesTransientHttp.php` -- `requestJson()` maps 429/5xx/connect → `Transient`, other non-2xx → `SchemaMismatch`; `fetch()` needs to catch that `SchemaMismatch` for a 404 and rethrow `NotFound`.
- `src/Store/Instrument.php` -- readonly VO; `fetch()` reads `->avanzaOrderbookId` and `->isin`.
- `tests/Adapter/AdapterTestCase.php` -- `client()`, `queue()`, `json()`, `assertQueueDrained()`, `instrument()` helpers.
- `tests/Adapter/AvanzaAdapterTest.php` -- add a `fetch` group beside the `resolveId` tests.

## Tasks & Acceptance

**Execution:**
- [x] `src/Adapter/NormalizedRow.php` -- `final readonly class`, constructor-promoted: `isin`, `source` (+ `SOURCE_AVANZA`/`SOURCE_NORDNET` consts), `numberOfOwners` int, `lastPrice`/`marketCap` ?float, `sourceTimestamp` ?DateTimeImmutable, `fetchedAt` DateTimeImmutable. No `as_of_date`.
- [x] `src/Adapter/SourceAdapter.php` -- `fetch(Instrument): NormalizedRow` + `@throws`.
- [x] `src/Adapter/NordnetAdapter.php` -- `fetch()` signature updated to `: NormalizedRow`, still throws "implemented in Story 1.5".
- [x] `src/Adapter/AvanzaAdapter.php` -- `fetch()`: null `avanzaOrderbookId` → `NotFound`; `withOneRetry` around GET `market-guide/stock/{id}`; shared `marketGuide()` helper maps a 404 → `NotFound`; validates `isin` (present + matches) and `keyIndicators.numberOfOwners` (non-negative int); `lastPrice()` / `marketCap()` fallback chains; `warn()` helper logs any `AdapterError` before rethrow. `resolveId` refactored onto the same `marketGuide()` helper.
- [x] `tests/Adapter/AvanzaAdapterTest.php` (+ `AdapterTestCase::instrument()` gained id params) -- 12 `fetch` tests covering every matrix row.

**Acceptance Criteria:**
- Given an instrument with a valid `avanza_orderbook_id`, when `fetch()` runs, then it returns a `NormalizedRow` with `source='avanza'`, an int `number_of_owners`, and `fetched_at` set to a UTC timestamp.
- Given a response missing `numberOfOwners` or with a non-int value, when the adapter validates it, then it throws `SchemaMismatch` (never a row with `number_of_owners = null`) and logs a `warning`.
- Given an HTTP 429 or 503, when the adapter receives it, then it retries once after the helper's pause; if that also fails it throws `Transient`.
- Given `composer test` with no network, when it runs, then the Avanza `fetch` tests pass against mocked HTTP and issue no real request.
- Given the tree is grepped outside `src/Adapter/`, then there is still no `curl` / Guzzle / `avanza.se` reference.

## Implementation Notes

- **Live smoke (2026-09-09)** — `AvanzaAdapter::fetch()` for Investor B / orderbookId 5247 → `numberOfOwners=533660`, `lastPrice=401.70`, `marketCap≈1.23e12`, `sourceTimestamp=null`, `fetchedAt` UTC. Matches the schema checks.
- **404 handling shared with `resolveId`** — `resolveId`'s per-candidate market-guide call now also runs through `marketGuide()`, so a candidate whose id 404s raises `NotFound` instead of `SchemaMismatch`. Story 1.3's frozen matrix explicitly permits either for a non-transient 4xx, and no 1.3 test exercised that sub-path, so this is not a regression.
- **`numberOfOwners` must be a JSON int** — `is_int()` is strict, so `"533660"` (string) or `533660.0` (float) → `SchemaMismatch`. Avanza returns a bare integer today.
- **`NormalizedRow` is immutable and has no `as_of_date`** per the frozen decision — Story 1.6's `OwnerCountRepository` converts `sourceTimestamp`/run-date → `as_of_date` in Europe/Stockholm.
- **Verified**: `composer test` green — 54 tests / 207 assertions (12 new). `composer validate --strict` clean. `php -l` clean. Boundary grep (`GuzzleHttp|curl_|avanza.se|nordnet.se` outside `src/Adapter/`) — no matches.

## Spec Change Log

## Review Triage Log

- 2026-09-09 — step-04 multi-agent review skipped at the user's choice (as with Stories 1.2/1.3). Verification (`composer test` 54/207, `composer validate`, `php -l`, boundary grep, live smoke) all passed.

## Design Notes

- **`NormalizedRow` lives in `src/Adapter/`** — it is the adapter→pipeline contract; both fetch adapters produce it and `OwnerCountRepository` (1.6) consumes it.
- **404 → `NotFound`, not `Transient`** — a cached `orderbook_id` that 404s is stale (delisting, id churn — the addendum flags Avanza id churn); `FetchRunner` should skip it and log, not retry forever.
- **`last_price` fallback chain** — `quote.last` is the live/last-trade price; after ~18:00 CET it equals the close, but `quote` can be absent for halted/illiquid names, so fall back to `historicalClosingPrices.oneDay`, then `null`.

## Verification

**Commands:**
- `composer test` -- all Avanza tests (resolveId + fetch) pass on mocked HTTP
- `grep -rn "GuzzleHttp\|curl_\|avanza\.se\|nordnet\.se" src/ bin/ | grep -v src/Adapter/` -- no matches
- `composer validate --strict`, `php -l` on new/changed files -- clean
- one-off live smoke: `AvanzaAdapter::fetch()` for Investor B (orderbookId 5247) returns a plausible `NormalizedRow` (owners ~500k, price ~400, mcap ~1.2e12)
