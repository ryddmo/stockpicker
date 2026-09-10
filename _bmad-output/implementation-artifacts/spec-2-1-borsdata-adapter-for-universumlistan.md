---
title: 'Story 2.1: Börsdata-adapter för universumlistan'
type: 'feature'
created: '2026-09-10'
status: 'done'
route: 'dispatch'
baseline_commit: 'a06523dbcd0478b8b307dc18f5afd05995f70566'
review_loop_iteration: 0
context:
  - _bmad-output/implementation-artifacts/epic-2-context.md
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The pipeline only knows the ~20 hardcoded seed ISINs. Epic 2 needs the
real Swedish universe — every company on Nasdaq Stockholm Large/Mid/Small Cap and
First North — so `UniverseSync` (Story 2.2) has an authoritative list to reconcile
`instrument` against, replacing the brittle scraped ISIN resolver as symbology source.

**Approach:** Add a `BorsdataAdapter` in `src/Adapter/` that calls the Börsdata REST
API (`/v1/markets` + `/v1/instruments`), filters to the four target Swedish markets,
and returns a validated in-memory list of `{isin, name, list}` with the list label
normalized to `LC | MC | SC | First North`. No persistence here — Story 2.2 consumes it.

## Boundaries & Constraints

**Decisions (resolved 2026-09-10):**
- **Port shape:** `BorsdataAdapter` is a plain concrete class — no `SourceAdapter` and
  no new interface. Its only public method is `listUniverse(): list<UniverseEntry>`.
  AD-1 is satisfied by keeping all HTTP inside the class. Story 2.2 depends on the
  concrete class directly (a `final` class is still fakeable via a subclass in tests if
  needed).
- **API key / verification:** a real Börsdata key is available now. Ship `MockHandler`
  unit tests **and** run `bin/show-universe.php` against the live API once during this
  story; capture the test fixtures from real responses and record the real market names,
  the equity/pref instrument-type ids, and per-list counts in Implementation Notes. If
  the implementing agent cannot reach the network or has no key in `config.php`, it must
  stop and ask the operator to run the live step and paste the output rather than guess.
- **Universe membership:** include common shares **and** preference shares on the four
  target markets. Exclude indices, ETFs, certificates, warrants, and funds. The
  instrument-type filter is by Börsdata `instrument` type id, resolved from the live
  response (see Implementation Notes).

**Always:**
- All Börsdata HTTP, URL, auth, and JSON parsing stays in the adapter (AD-1); the
  return type is a normalized value object, never a raw array.
- Any missing/renamed/wrong-typed field, an uncorrelatable market/instrument response,
  or an empty target-market universe raises `SchemaMismatch` and returns **no** list —
  never a partial one (AD-7). Logged at `warning`.
- Transient failures (connection error, timeout, 429, 5xx) map to `Transient` with one
  retry, via the existing `HandlesTransientHttp` trait.
- The API key is read from `config.php` (outside webroot, AD-8) via a new
  `Config::borsdataApiKey()`; `config.php.dist` documents it.
- A single entry with a blank/malformed ISIN or empty name is dropped with a `warning`,
  not failed — one junk row must not sink the sync.

**Never:**
- No writes to any table, no migration, no `settings` rows. (Nordnet-id column widening
  stays in Story 2.2.)
- No `fetch()` / owner-count behavior — Börsdata is universe/symbology only.
- No Börsdata SDK; use the shared Guzzle client.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Happy path | Valid key; `/v1/markets` + `/v1/instruments` well-formed | `list<UniverseEntry>`, one per target-market instrument, `list` in `{LC,MC,SC,First North}`, sorted by ISIN, deduped | N/A |
| Non-target market | Instrument on Spotlight / NGM / foreign market | Excluded silently | N/A |
| Missing field | Instrument without `isin`; `/v1/markets` entry without `name` | Raises, returns nothing | `SchemaMismatch` + `warning` |
| Junk single row | One target-market instrument with `isin` `""`/`null` | Row dropped, rest returned | `warning` with `insId`/name |
| Auth failure | Key rejected by Börsdata (401/403) | Raises | `SchemaMismatch` (non-transient HTTP); message names status. (A missing/empty key never reaches the adapter — `Config::borsdataApiKey()` throws first.) |
| Rate limited | HTTP 429 then success | Retried once, normal result | `Transient` only if retry also fails |
| Empty universe | `/v1/instruments` → `{instruments: []}` or zero on target markets | Raises | `SchemaMismatch` + `warning` |

</frozen-after-approval>

## Code Map

- `src/Adapter/NordnetAdapter.php` — structural template: `use HandlesTransientHttp`,
  ctor `(ClientInterface, LoggerInterface)`, `requestJson()` per call, explicit per-field
  `is_*` checks → `SchemaMismatch`, `warn()` helper.
- `src/Adapter/HandlesTransientHttp.php` — reuse `requestJson()` (Guzzle→typed error) and
  `withOneRetry()`. 429/5xx/connect → `Transient`; other non-2xx → `SchemaMismatch`.
- `src/Adapter/NormalizedRow.php` — VO pattern to mirror for `src/Adapter/UniverseEntry.php`
  (`final readonly`, promoted ctor, label consts).
- `src/Adapter/SourceAdapter.php` — the existing port; do **not** change it and do **not**
  implement it (`BorsdataAdapter` is a plain class).
- `src/Store/Instrument.php` — `list` values are `LC | MC | SC | First North`; ISIN is the
  natural key; `name` doubles as the Avanza/Nordnet search string. `UniverseEntry` fields
  must line up so Story 2.2 maps straight across.
- `src/Config.php` — add `borsdataApiKey(): string` next to `cronToken()`, same
  missing/empty → `RuntimeException` style; reads `$this->data['borsdata']['api_key']`.
- `tests/Adapter/AdapterTestCase.php` — `MockHandler` queue helpers (`json()`, `queue()`,
  `assertQueueDrained()`); base for the new test. `NordnetAdapterTest.php` = test shape.
- `bin/resolve-ids.php` / `bin/seed-instruments.php` — thin-wrapper pattern for
  `bin/show-universe.php` (build client + adapter from `bootstrap.php`, print counts).
- `_bmad-output/implementation-artifacts/deferred-work.md` — already notes this adapter
  replacing the brittle scraped resolver; no new entry needed for this story.

## Tasks & Acceptance

**Execution:**
- [x] `src/Adapter/UniverseEntry.php` -- new `final readonly` VO `{string isin, string name, string list}` + `LIST_LC|LIST_MC|LIST_SC|LIST_FIRST_NORTH` consts -- normalized contract, mirrors `NormalizedRow`.
- [x] `src/Adapter/BorsdataAdapter.php` -- plain `final` class, ctor `(ClientInterface, LoggerInterface, string $apiKey)`; `listUniverse()` calls `/v1/markets` then `/v1/instruments` (`authKey` query param), builds `marketId → label` for the four target Swedish markets, filters by market + equity/pref instrument type, validates every consumed field, returns ISIN-sorted deduped `list<UniverseEntry>` -- the story.
- [x] `src/Config.php` -- add `borsdataApiKey(): string` -- key access per AD-8.
- [x] `config.php.dist` -- add a `borsdata` section (`api_key => 'change-me'`) with a comment -- operator setup.
- [x] `tests/Adapter/BorsdataAdapterTest.php` -- one case per I/O & Edge-Case Matrix row against `MockHandler` fixtures (shapes per the documented API; live capture still pending) -- edge-case coverage.
- [x] `tests/Store/BorsdataConfigTest.php` -- assert `Config::borsdataApiKey()` reads a set key and rejects missing/empty/non-string -- config contract.
- [~] `bin/show-universe.php` -- thin wrapper written (build client + adapter from `bootstrap.php`, print per-list counts + a sample). **Live run deferred to a tracked, Story-2.2-blocking follow-up in `deferred-work.md` (operator decision 2026-09-10): review proceeds with fixture-only tests; the live run pins the two guessed constants and refreshes fixtures before Story 2.2 consumes the list.**

**Acceptance Criteria:**
- Given a valid key and well-formed responses, when `listUniverse()` runs, then it returns one `UniverseEntry` per target-market instrument, `list` ∈ `{LC,MC,SC,First North}`, no duplicate ISINs.
- Given the adapter code under review, then no Guzzle/HTTP/Börsdata-URL/JSON-shape reference exists outside `src/Adapter/`, and no partially-built list is ever returned when validation fails.
- Given `composer test`, then `BorsdataAdapterTest` passes and no existing test regresses.
- Given any DB table, when the story is done, then its schema and rows are unchanged.

## Implementation Notes

Börsdata API facts (public wiki / Swagger — confirm against the live run):
- Base `https://apiservice.borsdata.se/v1`; auth `?authKey=<key>` on every call.
- Rate limit 100 calls / 10 s; 429 carries `Retry-After`. A universe sync is ~2 calls.
- `GET /v1/instruments` → `{ instruments: [ { insId, name, isin, ticker, instrument (type id),
  marketId, branchId, sectorId, countryId, listingDate, ... } ] }`.
- `GET /v1/markets` → `{ markets: [ { id, name, countryId, ... } ] }`. Swedish `countryId`
  expected `1`; target market names expected "Large Cap"/"Mid Cap"/"Small Cap"/"First North"
  — exact strings MUST be confirmed against the live `/v1/markets` response.
- Swagger: https://apidoc.borsdata.se/swagger/index.html

Record here from the live `bin/show-universe.php` run: the real `/v1/markets` `id`+`name`
for the four target markets, the Börsdata `instrument` type ids for common shares and
preference shares (and which ids were excluded), and the per-list instrument counts.

**I/O matrix rows 3 vs 4 — interpretation (operator decision 2026-09-10):** row 3's
"instrument without `isin` → raises" is read as *structural* breakage only — a field
absent from the response shape (`/v1/markets` entry without `name`, no `instruments` key)
or zero correlatable target-market instruments. A *single* instrument row with a
null/blank/malformed ISIN or empty name is dropped with a `warning` (row 4 + the
"one junk row must not sink the sync" Boundary). The implementation ships this behavior;
no code change.

**LIVE RUN STILL OUTSTANDING (2026-09-10).** No `borsdata.api_key` was present in
`config.php` on the implementing machine, so per the "API key / verification" decision the
agent stopped rather than guessing. Operator action required:
1. Add the real key to `config.php` under `'borsdata' => ['api_key' => '…']` (see
   `config.php.dist`).
2. Run `php bin/show-universe.php` and paste the output here.
3. Confirm / correct these assumptions the code currently ships with, then refresh the
   `tests/Adapter/BorsdataAdapterTest.php` fixtures from the real responses:
   - **Target market identification:** `countryId === 1` AND `name` (case-insensitive,
     trimmed) is exactly `Large Cap` → `LC`, `Mid Cap` → `MC`, `Small Cap` → `SC`, or
     *starts with* `First North` → `First North`. `BorsdataAdapter::labelForMarketName()` /
     `SWEDEN_COUNTRY_ID`.
   - **Instrument-type filter:** `instrument` type id `0` (Stocks / common shares) and `1`
     (Pref / preference shares) are kept; everything else excluded.
     `BorsdataAdapter::EQUITY_TYPE_IDS`. Check whether Börsdata also uses type `3`
     ("Stocks2") for any Swedish LC/MC/SC/First North listing — if so, add it.
   - Per-list counts (expected hundreds on LC/MC/SC and hundreds on First North).
   If the real `/v1/markets` names differ (e.g. `First North Stockholm`, or a suffix on the
   Cap names), only `labelForMarketName()` needs to change.

`bin/show-universe.php` is self-documenting for this: `BorsdataAdapter` logs an `info`
line per correlated target market (`{id, name, label}`) and a final `borsdata: universe
built` `info` line (`{markets: <marketId→label>, kept_instrument_types, types_on_target_markets,
counts, total}`). `targetMarketLabels()` now raises `SchemaMismatch` unless **all four**
labels (LC, MC, SC, First North) correlate — a partial map would silently drop a whole cap
tier. ISIN validation is `^[A-Z]{2}[A-Z0-9]{9}[0-9]$` (rejects all-digit values that PHP
would coerce to int array keys).

## Design Notes

Mirror `NordnetAdapter` validation: pull each field with `??`, check type, `throw new
SchemaMismatch('borsdata /v1/instruments: "<field>" …')`. Build the whole list in a local
and return it in one statement — a throw half-way then leaves the caller with nothing.

```php
$labels = $this->targetMarketLabels();            // ['<marketId>' => 'LC', ...] from /v1/markets
$out = [];
foreach ($this->instruments() as $raw) {
    if (!isset($labels[(string) ($raw['marketId'] ?? '')])) continue;   // non-target market
    if (!$this->isEquityOrPrefType($raw['instrument'] ?? null)) continue;
    $isin = $raw['isin'] ?? null;
    if (!is_string($isin) || !preg_match('/^[A-Z0-9]{12}$/', $isin)) {
        $this->logger->warning('borsdata: dropping instrument with bad isin', [...]);
        continue;
    }
    $out[$isin] = new UniverseEntry($isin, (string) $raw['name'], $labels[(string) $raw['marketId']]);
}
if ($out === []) throw new SchemaMismatch('borsdata: target-market universe came back empty');
ksort($out);
return array_values($out);
```

## Verification

**Commands:**
- `composer test -- --filter BorsdataAdapterTest` -- expected: all new cases green.
- `composer test` -- expected: full suite green, no regressions.

**Manual checks:**
- Run `php bin/show-universe.php` against the live API; confirm plausible counts
  (hundreds on LC/MC/SC, hundreds on First North) and all four labels present; record real
  market names + type ids in Implementation Notes.
- Grep `src/Pipeline` and `src/Store` for `guzzle`/`borsdata`/`apiservice` — expected: no hits.

## Review Triage Log

Review pass 1 (2026-09-10) — blind-hunter, edge-case-hunter, verification-gap.

| # | Finding | Verdict | Route | Evidence |
|---|---------|---------|-------|----------|
| 1 | `targetMarketLabels()` raises only when zero markets correlate; a live `/v1/markets` name with a suffix (spec Notes call this plausible) drops a whole cap tier and returns a short universe silently | medium | patch | `BorsdataAdapter.php:177` only guards `$labels === []`. If caps come back as "Large Cap Stockholm" etc., `labelForMarketName()` returns null for them, `$labels` keeps only what matched, no throw. Story 2.2 would then mass-delist a real list. |
| 2 | ISIN regex `/^[A-Z0-9]{12}$/` accepts an all-digit 12-char value → PHP casts `$out[$isin]` key to int → `ksort` mixes int/string keys, output not lexically sorted | low | patch | `BorsdataAdapter.php:98,118,128`. Real ISINs always begin with 2 letters; tightening to `^[A-Z]{2}[A-Z0-9]{9}[0-9]$` removes the cast and makes validation match the format the code claims to check. |
| 3 | A non-array element in `instruments[]` is dropped silently by `array_filter(...,'is_array')`, while a non-array `/v1/markets` entry raises and other per-row drops warn | low | patch | `BorsdataAdapter.php:217` vs `:153`, `:99`, `:110`. Inconsistent; a per-row `warning` on skip matches the rest and the "single junk row" Boundary. |
| 4 | `bin/show-universe.php` prints per-list counts + a sample but not the `/v1/markets` id→name→label map or the instrument-type ids seen on target markets — 2 of the 3 things the spec's Implementation Notes tell the operator to record from this exact run | medium | patch | `bin/show-universe.php` output vs spec Implementation Notes ("record the real /v1/markets id+name … the instrument type ids present … per-list counts"). The designated verification aid can't complete the verification. |
| 5 | `EQUITY_TYPE_IDS = [0, 1]` may miss type 3 ("Stocks2") or another equity id on a target market; such instruments are silently skipped, no log | maybe-false (medium if true) | defer | `BorsdataAdapter.php:93`. Impact depends entirely on live data; settled by the mandatory live run. Already tracked in `deferred-work.md` (confirm `EQUITY_TYPE_IDS` against the real `/v1/instruments`, correct if different). |
| 6 | `BorsdataAdapterTest` fixtures are hand-authored from documented shapes, not captured from real responses — a mismatch between the shipped constants and the live API passes the suite green | medium | defer | verification-gap pre-verified. Already tracked in `deferred-work.md` with exact remediation (re-capture fixtures from real payloads, keep them as committed captures). Operator chose (2026-09-10) to proceed to review with this deferred and Story-2.2-blocking. |
| 7 | Frozen "a real Börsdata key is available now" contradicts the deferred live run | medium | reject | Fix would edit the `<frozen-after-approval>` block — the human's call, not a build blocker. The mismatch is already reconciled by the operator's 2026-09-10 decision + the `deferred-work.md` entry. |
| 8 | Cross-list duplicate ISIN (same ISIN on two target markets) → `list` label resolved by response order, no rule | low | reject | `/v1/instruments` returns one row per instrument with a single `marketId`; the same ISIN on two markets is a Börsdata data anomaly not shown reachable, and Story 2.2's nightly reconcile corrects a wrong label next run. Fix adds a collision branch. |
| 9 | Strict `countryId !== 1` / `in_array($type, [0,1], true)` break if the API serializes those as strings | low | reject | `json_decode` yields int for JSON integers; Börsdata's Swagger types both as integers; a string form violates their published schema and would fail loudly on the mandatory live run. Listed in the deferred verification checklist. |
| 10 | No test asserts the `authKey` query param is sent on either call | low | reject | A dropped key → HTTP 401 → `SchemaMismatch` (loud, immediate), not silent bad data; `NordnetAdapterTest`/`AvanzaAdapterTest` likewise assert only on responses (repo convention). Fix adds request-history infra beyond a direct correction. |
| 11 | 429 `Retry-After` is not honored — `withOneRetry` retries immediately | low | reject | Full backoff is explicitly Story 2.4; this story does one retry consistent with `HandlesTransientHttp` used by the other adapters; ≤3 calls worst case against a 100/10 s ceiling. |
| 12 | `withOneRetry` wraps both HTTP calls; a transient on `/v1/instruments` re-fetches `/v1/markets` | low | reject | `/v1/markets` re-fetch is idempotent; one extra call on a rare retry; identical shape to `NordnetAdapter::fetch`. |
| 13 | `withOneRetry` might retry the empty-universe `SchemaMismatch` | false | reject | `HandlesTransientHttp::withOneRetry` catches only `Transient`; `SchemaMismatch` propagates on first raise. |
| 14 | `UniverseEntry` drops `insId` / `ticker` that Story 2.2 could match on | n/a | reject (out of scope) | Story 2.1's AC names exactly `isin`, `name`, list label; `instrument` has no column for the others; adding them is a Story 2.2+ schema decision. |
| 15 | Large `/v1/instruments` decode vs unresolved Loopia `memory_limit` probe | low | reject | The Swedish instrument list is a few thousand small objects (single-MB decode); the Loopia memory probe is already a tracked Epic 1 follow-up. |
| 16 | "Börsdata fixes the Handelsbanken/Nordea/Epiroc symbology gaps" is unsubstantiated by this diff | n/a | reject (out of scope) | Id resolution is Story 2.2 (still searches Avanza/Nordnet by name); this story only supplies isin/name/list. Noted for 2.2 planning. |
| 17 | Untested structural-error branches in `targetMarketLabels()` (`id` not scalar, entry not object) | low | reject | Defensive `SchemaMismatch` throws on malformed structural data; the missing-`name` case covers the matrix row; per-branch fixtures are a negligible-risk test nicety. |
| 18 | `catch (AdapterError)` lets a bare `\Error` / non-mapped Guzzle exception propagate unlogged | low | reject | Identical to `AvanzaAdapter`/`NordnetAdapter`; `requestJson` maps the realistic Guzzle failures; a bare `\Error` should propagate loudly. Not caused by this story. |
| 19 | `bin/show-universe.php` constructs a Guzzle `Client` outside `src/Adapter/` vs the "no HTTP outside src/Adapter/" AC | false | reject | The AC targets source-specific HTTP logic (URLs, parsing, JSON shape); client construction at a composition root follows the existing `bin/resolve-ids.php` / `public_html/index.php` precedent; no Börsdata URL or parsing in the script. |
| 20 | `marketId` / `instrument` wrong-typed value silently skipped rather than validated ("validates every consumed field") | low | reject | A wrong-typed `marketId` correctly fails to match any target label = "not a target market"; structural breakage still surfaces via the empty-result raise, per the recorded rows-3/4 interpretation. |

**Patches applied (2026-09-10), verified green (`phpunit` full suite 170 tests, `grep` clean):**
- #1 — `targetMarketLabels()` raises `SchemaMismatch` naming the missing label(s) unless all four of `LC/MC/SC/First North` correlate (`TARGET_LABELS` const + `array_diff`). New test `testRaisesSchemaMismatchWhenOnlyThreeOfTheFourCapTiersMatch`.
- #2 — ISIN regex tightened to `^[A-Z]{2}[A-Z0-9]{9}[0-9]$`; all-digit fixture row added.
- #3 — `instruments()` warns per skipped non-array element. New test `testDropsNonObjectInstrumentEntriesWithAWarningAndKeepsTheRest`.
- #4 — `BorsdataAdapter` emits `info` logs (per correlated market + a `universe built` summary with the marketId→label map, instrument types seen on target markets, and per-list counts) so a live `bin/show-universe.php` run records everything the Implementation Notes require. No adapter public-surface change.
