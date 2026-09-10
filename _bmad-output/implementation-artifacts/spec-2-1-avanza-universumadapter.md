---
title: 'Story 2.1: Avanza-universumadapter (listning)'
type: 'feature'
created: '2026-09-10'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: '9e36b6a972f97b9ec07e1eed8bce7cff97d80f20'
context:
  - _bmad-output/implementation-artifacts/epic-2-context.md
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Epic 2 needs the real Swedish universe (every share on Nasdaq Stockholm
Large/Mid/Small Cap and First North) so `UniverseSync` (Story 2.2) has an authoritative
list to reconcile `instrument` against, replacing the hardcoded ~20-ISIN seed list. The
Börsdata adapter that Story 2.1 originally shipped was removed — Börsdata's API needs a
paid Pro subscription.

**Approach:** Add `AvanzaUniverseAdapter` in `src/Adapter/` — a plain class (not a
`SourceAdapter`) whose one job is `listUniverse(): list<UniverseEntry>`. It calls Avanza's
public stock-screener endpoint once per target list (four calls), normalizes each row to
`{avanzaOrderbookId, name, list}`, and returns the deduped, sorted list. No persistence —
Story 2.2 consumes it. ISIN is **not** in the screener response; Story 2.2 resolves it per
instrument via `market-guide/stock/{orderbookId}`.

## Boundaries & Constraints

**Decisions (resolved 2026-09-10, verified against the live API):**
- **Endpoint:** `POST https://www.avanza.se/_api/market-stock-filter/stocks`, JSON body
  `{"filter":{"sectors":[],"marketPlaces":["<one value>"]},"limit":5000,"offset":0,"sortBy":{"field":"numberOfOwners","order":"desc"}}`.
  No auth; a normal `User-Agent` header (reuse `AvanzaAdapter::USER_AGENT`). One call per
  target list.
- **Four target lists** (one `marketPlaces` value each) → label:
  `se.xsto.large cap stockholm` → `LC`, `se.xsto.mid cap stockholm` → `MC`,
  `se.xsto.small cap stockholm` → `SC`, `se.fnse` → `First North`.
  `se.xsto.xterna listan` (1 orderbook), Spotlight, NGM, foreign lists are **not** queried.
- **Response shape** (verified 2026-09-10): `{ stocks: [ { orderbookId (string),
  companyId, type: "STOCK", name, shortName, currency, countryCode: "SE",
  marketPlaceCode: "XSTO", numberOfOwners, marketCap, … } ], pagination, sortBy,
  totalNumberOfOrderbooks, filterOptions }`. Per-list counts on 2026-09-10: LC 163, MC 141,
  SC 107, First North ~330 (~741 total). **No `isin` field. No cap-tier field** — the label
  comes from which query returned the row.
- **`UniverseEntry` loses `isin`, gains `avanzaOrderbookId`:** `{string avanzaOrderbookId,
  string name, string list}`. Keep the `LIST_LC|LIST_MC|LIST_SC|LIST_FIRST_NORTH` consts.
  The VO is currently unused (Börsdata code removed), so this is a safe change.

**Always:**
- All Avanza HTTP, the base URL, the request body, and every JSON-shape assumption live
  inside `AvanzaUniverseAdapter` (AD-1); the return type is a `list<UniverseEntry>`, never
  a raw array.
- Any consumed field missing / renamed / wrong-typed, a response that is not
  `{stocks: [...]}`, or **any one of the four target lists coming back empty** raises
  `SchemaMismatch` and returns **no** list — never a partial one (AD-7). Logged at
  `warning`. (An empty list means a whole cap tier silently vanished — Story 2.2 would
  mass-delist it.)
- Transient failures (connect error, timeout, 429, 5xx) map to `Transient` with one retry
  via the `HandlesTransientHttp` trait.
- A single row with a blank/whitespace `name`, a non-scalar `orderbookId`, or
  `type` != `"STOCK"` is dropped with a `warning` (name/orderbookId non-STOCK is dropped
  silently) — one junk row must not sink the sync.
- Same `orderbookId` seen in two target-list responses → keep the first, log a `warning`.
- Result is sorted by `avanzaOrderbookId` (numeric-string ascending) and deduped.

**Never:**
- No writes to any table, no migration, no `settings` rows.
- No `market-guide/stock/{id}` call, no ISIN resolution, no Nordnet lookup — all Story 2.2.
- No `fetch()` / owner-count behaviour; `AvanzaUniverseAdapter` does not implement
  `SourceAdapter`.
- No Avanza SDK; use the shared Guzzle client passed to the constructor.
- Do not touch `AvanzaAdapter` (owner counts) or `NordnetAdapter`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Happy path | 4 target-list POSTs well-formed | `list<UniverseEntry>`, one per STOCK row, `list` ∈ `{LC,MC,SC,First North}`, sorted by `avanzaOrderbookId`, deduped | N/A |
| Non-STOCK row | a `stocks[]` entry with `type` != `"STOCK"` | excluded | silent |
| Junk row | entry with blank/whitespace `name` or non-scalar `orderbookId` | row dropped, rest returned | `warning` with `orderbookId`/`name` |
| Missing field | response with no `stocks` key; or a consumed row field absent | raises, returns nothing | `SchemaMismatch` + `warning` |
| Empty target list | one of the 4 queries returns `{stocks: []}` | raises, returns nothing | `SchemaMismatch` + `warning` naming the list |
| Rate limited | HTTP 429 then success on retry | retried once, normal result | `Transient` only if the retry also fails |
| Other 4xx | e.g. HTTP 400 (request contract changed) | raises | `SchemaMismatch`, message names the status |
| Duplicate orderbookId | same `orderbookId` in two target-list responses | one entry, first wins | `warning` |
| Pagination overflow | a target-list response's `stocks` length equals the 5000 `limit` | raises (universe 30× expected → contract change) | `SchemaMismatch` + `warning` |

</frozen-after-approval>

## Code Map

- `src/Adapter/AvanzaAdapter.php` — structural template (do **not** modify): `final`,
  `use HandlesTransientHttp`, ctor `(ClientInterface, LoggerInterface)`, `requestJson()`
  per call, explicit `is_*` checks → `SchemaMismatch`, `warn()` helper. Copy its
  `BASE_URI` (`https://www.avanza.se`) and `USER_AGENT` string into the new class — no
  shared base class.
- `src/Adapter/HandlesTransientHttp.php` — reuse `requestJson($http, 'POST', $uri, ['json' => [...], 'headers' => ['User-Agent' => ...]])`
  and `withOneRetry()`. 429/5xx/connect → `Transient`; other non-2xx / non-JSON → `SchemaMismatch`.
- `src/Adapter/UniverseEntry.php` — **change**: first ctor param `string $isin` →
  `string $avanzaOrderbookId`; update its comment. Keep `final readonly` + the four
  `LIST_*` consts (docstring already names `AvanzaUniverseAdapter`).
- `src/Adapter/SourceAdapter.php` / `NormalizedRow.php` — the port (don't implement it) and
  the VO style to mirror.
- `src/Store/Instrument.php` — `list` values `LC | MC | SC | First North` line up 1:1 with
  `UniverseEntry` labels; `name` doubles as the Nordnet search string.
- `tests/Adapter/AdapterTestCase.php` — extend: `client()`, `queue()`, `json()`,
  `assertQueueDrained()`. `NordnetAdapterTest.php` = test shape (`NullLogger`, one method
  per scenario).
- `bin/resolve-ids.php` — thin-wrapper pattern for `bin/list-universe.php` (`require bootstrap.php`,
  build `Client` + adapter, print, `catch \Throwable` → log + `exit(1)`).
- `addendum.md` §"Källa: Avanza — universum (listning)" — replace the "unverified" text
  with the verified endpoint / body / shape / counts from the Decisions block.
- Superseded `spec-2-1-borsdata-adapter-for-universumlistan.md` Review Findings — two
  learnings carried here: all four target lists must be non-empty or raise; tight scalar
  check on the id (numeric strings must not become int array keys — hence `uksort` with a
  cast, `$out` keyed by string).

## Tasks & Acceptance

**Execution:**
- [x] `src/Adapter/UniverseEntry.php` -- rename first field `isin` → `avanzaOrderbookId`, update comment -- the normalized contract now carries the Avanza id, not ISIN.
- [x] `src/Adapter/AvanzaUniverseAdapter.php` -- new `final` class, ctor `(ClientInterface, LoggerInterface)`; `listUniverse()` POSTs the screener endpoint once per target list, validates every consumed field, drops junk rows with a `warning`, raises `SchemaMismatch` on a broken shape or any empty target list, dedupes + sorts by `avanzaOrderbookId`, returns `list<UniverseEntry>` -- the story.
- [x] `bin/list-universe.php` -- thin wrapper: bootstrap, build `Client` + adapter, run `listUniverse()`, print per-list counts + a 10-row sample -- operator spot-check over SSH.
- [x] `tests/Adapter/AvanzaUniverseAdapterTest.php` -- one case per I/O & Edge-Case Matrix row against `MockHandler` fixtures shaped like the verified response -- edge-case coverage.
- [x] `_bmad-output/planning-artifacts/briefs/brief-stockpicker-2026-09-08/addendum.md` -- replace the "unverified" Avanza universe section with the verified endpoint / request body / response shape / per-list counts -- the addendum is the source-of-record for source endpoints.

**Acceptance Criteria:**
- Given the four target-list POSTs return well-formed screener responses, when `listUniverse()` runs, then it returns one `UniverseEntry` per STOCK row with `list` ∈ `{LC,MC,SC,First North}`, no duplicate `avanzaOrderbookId`, sorted ascending.
- Given the adapter code under review, then no Guzzle/HTTP/Avanza-URL/JSON-shape reference exists outside `src/Adapter/`, and no partial list is ever returned when validation fails.
- Given `composer test`, then `AvanzaUniverseAdapterTest` passes and no existing test regresses.
- Given any DB table, when the story is done, then its schema and rows are unchanged.
- Given `php bin/list-universe.php` against the live API, then it prints plausible counts (LC/MC/SC in the low hundreds, First North a few hundred) with all four labels present.

## Implementation Notes

- `AvanzaUniverseAdapter` copies `BASE_URI` + `USER_AGENT` from `AvanzaAdapter` verbatim
  (no shared base class, per Code Map). `STOCKS_PATH` const `/_api/market-stock-filter/stocks`.
- `queryList($marketPlace, $label)` wraps one POST in `withOneRetry`; raises `SchemaMismatch`
  (via `schemaMismatch()` which logs `warning` first) for: `stocks` missing/not-a-list,
  `stocks` empty, or `count >= 5000`.
- Row loop: non-`STOCK` skipped silently; non-scalar `orderbookId` and blank/absent `name`
  dropped via `warn()` (logs `list`, `orderbookId`, `name`); cross-list duplicate keeps the
  first and warns. `$out` keyed by string id, `uksort` with `(int)` cast, `array_values()`.
- Live run 2026-09-10 (`php bin/list-universe.php`): 741 entries — LC 163 / MC 141 / SC 107 /
  First North 330. Matches the spec's stated counts.
- Test double `RecordingLogger extends Psr\Log\AbstractLogger` lives in the test file
  (captures `warning` calls). Full suite 164 tests green.
- No static analysis in the project (no phpstan/psalm/cs-fixer) — verification is
  `php -l` + phpunit.

**Review pass 1 patches (2026-09-10, applied inline — the implementation subagent could
not be re-engaged):**
- `queryList()` — added a truncation guard: `count($stocks)` vs `body.totalNumberOfOrderbooks`
  (int or digit-string); a short page raises `SchemaMismatch` naming the list. `sortBy`
  changed `numberOfOwners desc` → `name asc` (both `sortBy` and `limit` are **required** —
  the endpoint 400s without either; verified live).
- `listUniverse()` — per-list guard: if a target list's non-empty response contributes 0
  usable rows, raise `SchemaMismatch` naming the list (a whole cap tier must never vanish).
- `preview()` helper — a `warning` about a non-scalar value now records `get_debug_type()`
  of it instead of a bare `null`.
- Tests — happy path now feeds ids out of order (5364 before 5247; a First North id
  between the LC ids) so the sort is exercised; `assertQueueDrained()` added to the five
  run-to-completion cases; new `testATargetListWithRowsButNoUsableStockRows...` and
  `testTruncatedTargetListResponseRaisesSchemaMismatch`. 16 adapter tests, full suite 166.

## Spec Change Log

- **2026-09-10 (implementation, matrix-audit).** The I/O & Edge-Case Matrix's "Missing
  field" row ("response with no `stocks` key; **or a consumed row field absent** → raises,
  returns nothing") contradicts the "Junk row" row and the Boundaries "Always" rule ("one
  junk row must not sink the sync"). **Resolution (operator, 2026-09-10):** a single row
  with a consumed field absent, blank, whitespace, non-scalar, or `type` != `"STOCK"` is
  dropped with a `warning` and the sync continues. Only a *structural* break raises
  `SchemaMismatch` with no list: no `stocks` key / `stocks` not a list, an empty target
  list, the 5000-row ceiling, or an unexpected 4xx. `AvanzaUniverseAdapter` and
  `AvanzaUniverseAdapterTest::testAConsumedRowFieldBeingAbsentIsHandled` implement this.
  KEEP: the per-list empty-list guard (a whole cap tier vanishing must raise).
- **2026-09-10 (correction).** The entry above lumped `type` != `"STOCK"` into the
  "dropped with a `warning`" list. The I/O Matrix "Non-STOCK row" is **excluded silently**
  — that is what `AvanzaUniverseAdapter` does and it is correct. Only blank/whitespace/
  absent `name` and non-scalar `orderbookId` get a `warning`.

## Review Triage Log

Review pass 1 (2026-09-10) — blind-hunter, edge-case-hunter, verification-gap.

| # | Finding | Verdict | Route | Evidence |
|---|---------|---------|-------|----------|
| 1 | `queryList()` only guards `stocks === []`; a target list truncated below its true size (server page-size < requested 5000) returns silently short — `totalNumberOfOrderbooks` is in the response but ignored | medium | patch | Frozen Boundaries demand "never a partial list / a cap tier silently vanishing must raise". Live run honours `limit:5000` today, but the endpoint is unofficial and page-size drift is a known failure class. `totalNumberOfOrderbooks` (documented response field) makes the check trivial. |
| 2 | A target list that returns non-empty `stocks[]` whose rows all fail the type/name/id filters contributes 0 entries with no raise — same "cap tier vanishes → Story 2.2 mass-delists it" harm the empty-list guard exists to stop | medium | patch | `listUniverse()` loop at `AvanzaUniverseAdapter.php:71-104` has no per-list "added at least one" check. |
| 3 | Request sends `sortBy: numberOfOwners desc`; the adapter re-sorts by id anyway, so the request sort only matters if the response is truncated — and then it drops precisely the long-tail small caps | low | patch (folded into #1) | `sortBy` and `limit` are both **required** (verified: the endpoint 400s without either), so it cannot be dropped — changed to `sortBy: name asc` (deterministic, no tail bias). #1's truncation guard makes the order irrelevant to correctness anyway. |
| 4 | `warn()` records `orderbookId => null` for the non-scalar-orderbookId drop (`is_scalar(...) ? ... : null`) — the debugging signal for the exact value that triggered the warning is discarded | low | patch | `AvanzaUniverseAdapter.php:176-183`. Trivial: log `get_debug_type()` of the raw value. |
| 5 | The sort-ascending contract is asserted only against fixtures whose insertion order already equals ascending order; deleting the `uksort` line keeps every order-asserting test green | low | patch | verification-gap, pre-verified. `AvanzaUniverseAdapterTest.php:80,113,130,147,181` all feed ids already in LC→MC→SC→FN ascending arrangement. |
| 6 | `assertQueueDrained()` is called in 2 of 8 tests; a regression that skips a list or issues an extra call passes several tests unnoticed | low | patch | `AvanzaUniverseAdapterTest.php` — `testNonStockRows`, `testBlankName`, `testNonScalarOrderbookId`, `testAConsumedRowFieldAbsent`, `testDuplicateOrderbookId` run to completion with 4 queued but never assert the queue drained. |
| 7 | No test asserts the four outgoing POSTs (path, body, `marketPlaces` value per label) — the riskiest unverified surface in the story | low | defer | A wrong path → 404 → `SchemaMismatch`; a wrong `marketPlaces` value → empty list → `SchemaMismatch`. Failure is loud, not silent. Matches the repo convention (`AvanzaAdapterTest`/`NordnetAdapterTest` assert only on responses). |
| 8 | No test proves a mid-sequence list failure (LC+MC succeed, SC throws) discards the already-built entries | low | defer | The behaviour is already correct — `$out` is a local and `queryList()`'s throw is uncaught, so `listUniverse()` returns nothing. Only the test is missing. |
| 9 | `bin/list-universe.php` is not in `docs/deploy.md` (its sibling `bin/resolve-ids.php` is) | low | defer | Doc gap; pairs with the Story 2.2 `docs/deploy.md` updates already deferred. |
| 10 | No real captured `market-stock-filter/stocks` payload is committed as a fixture — the `stock()` factory is hand-rolled | false | reject | Spec Code Map asks for fixtures "shaped like the verified response", which is met (every field name/type from the live 2026-09-10 capture). A committed raw payload adds reproducibility but is not what the spec requires; drift is rare and the fix adds a file + wiring. |
| 11 | `shortName` (ticker) is discarded though the epic may need ticker matching for the 3 known Nordnet-miss large caps | n/a | reject | Fix edits the frozen `UniverseEntry` contract (`{avanzaOrderbookId, name, list}`). Story 2.2 adds `shortName` with a 1-line adapter change when it needs it. Noted for 2.2 planning. |
| 12 | No defensive `marketPlaceCode` / `countryCode` check per row — the adapter trusts Avanza's `marketPlaces` filter entirely | low | reject | Speculative (filter semantics changing). A wrong-tier row would be a wrong `list` label, which Story 2.2's nightly reconcile corrects next run. Fix adds branches for an undemonstrated state. |
| 13 | "Swedish universe" scope drift — `countryCode` is in the response but unused, so foreign-domiciled shares on Stockholm LC/MC/SC are included | false | reject | Correct behaviour: "Swedish" means *listed on Nasdaq Stockholm*, not Swedish-domiciled. The seed list itself carries Nordea (FI4000297767). Filtering on `countryCode === 'SE'` would wrongly drop it. |
| 14 | A `SchemaMismatch` thrown inside `HandlesTransientHttp::requestJson()` (unexpected 4xx, non-JSON body) is not logged at `warning`, unlike every `schemaMismatch()` path in the adapter | low | reject | The I/O Matrix "Other 4xx" row says only "`SchemaMismatch`, message names the status" — no "+ warning", unlike the "Missing field" / "Empty target list" rows which do. The exception propagates loudly to `/cron/refill`'s `error`-level catch; Story 2.6 counts from exceptions, not log lines. |
| 15 | Empty-string / non-numeric-string `orderbookId` passes `is_string`, becomes a `UniverseEntry`, and makes the `(int)`-cast `uksort` unstable | low | reject | Avanza returns numeric strings (`"5247"`). Non-numeric ids are not demonstrated reachable; if one occurred, Story 2.2's `market-guide/stock/{id}` 404s loudly and skips the instrument. Fix adds a guard for an everyday-unlikely case. |
| 16 | `bin/list-universe.php` constructs `GuzzleHttp\Client` outside `src/Adapter/`, against the "no Guzzle/HTTP outside `src/Adapter/`" AC | false | reject | Composition-root client construction follows `bin/resolve-ids.php` and `public_html/index.php`. The AC and the spec's own Verification target source-specific HTTP *logic* (URLs, parsing, shape) in `src/Pipeline` / `src/Store`; the bin script has none. |
| 17 | `bin/list-universe.php` doesn't exit non-zero / warn when a label is missing, though AC5 is "all four labels present" | low | reject | AC5 is a *manual* check the operator runs and eyeballs; the script prints per-label counts. Adding a gate to a throwaway spot-check script is more than a direct correction. |
| 18 | `epic-2-context.md` still says `UniverseEntry` is `{isin, name, list}` and that the listing "yields all four list segments with ISIN" — contradicts the shipped `{avanzaOrderbookId, name, list}` | low | defer (fixed inline) | Stale compiled artifact predating the ISIN-not-in-listing discovery. Auto-regenerates on the next Story 2.2 build (`epics.md` is now newer). The 3 wrong lines were corrected inline this pass. |
| 19 | Absent `type` key → row skipped silently with no `warning` | false | reject | The I/O Matrix "Non-STOCK row" is "excluded silently"; a row with no `type` is not a STOCK and is correctly dropped silently. (The Spec Change Log wording that implied otherwise was corrected this pass.) |

**Patches:** #1+#2+#3 (per-list completeness guards), #4 (warn value), #5 (sort test), #6 (queue-drain asserts). No loopback (no intent_gap / bad_spec). `review_loop_iteration` unchanged.

## Design Notes

Mirror `AvanzaAdapter` validation: pull each field with `??`, check the type, `throw new
SchemaMismatch('avanza market-stock-filter/stocks (<list>): "<field>" …')`. Build the whole
list in a local and return it in one statement — a throw half-way then leaves the caller
with nothing.

```php
private const LISTS = [
    'se.xsto.large cap stockholm' => UniverseEntry::LIST_LC,
    'se.xsto.mid cap stockholm'   => UniverseEntry::LIST_MC,
    'se.xsto.small cap stockholm' => UniverseEntry::LIST_SC,
    'se.fnse'                     => UniverseEntry::LIST_FIRST_NORTH,
];

// listUniverse():
$out = [];
foreach (self::LISTS as $marketPlace => $label) {
    $rows = $this->queryList($marketPlace);          // requestJson POST; ['stocks'] must be a non-empty array
    foreach ($rows as $raw) {
        if (!is_array($raw) || ($raw['type'] ?? null) !== 'STOCK') continue;
        $id = $raw['orderbookId'] ?? null;
        if (!is_string($id) && !is_int($id)) { $this->warn('bad orderbookId', $raw); continue; }
        $id = (string) $id;
        $name = $raw['name'] ?? null;
        if (!is_string($name) || trim($name) === '') { $this->warn('empty name', $raw); continue; }
        if (isset($out[$id])) { $this->warn('duplicate orderbookId across lists', ...); continue; }
        $out[$id] = new UniverseEntry($id, trim($name), $label);
    }
}
if ($out === []) throw new SchemaMismatch('avanza screener: target universe came back empty');
uksort($out, static fn (string $a, string $b): int => (int) $a <=> (int) $b);
return array_values($out);
```

`queryList()` wraps one POST in `withOneRetry`; an empty `stocks` array for a target list
throws `SchemaMismatch` (not "return nothing") so the all-four-lists guard is per-list.
The 5000 `limit` is a safety ceiling — if `count($rows) >= 5000`, raise.

## Verification

**Commands:**
- `composer test -- --filter AvanzaUniverseAdapterTest` -- expected: all new cases green.
- `composer test` -- expected: full suite green, no regressions.
- `php -l src/Adapter/AvanzaUniverseAdapter.php` -- expected: no syntax errors.

**Manual checks:**
- `php bin/list-universe.php` against the live API — confirm ~741 entries, all four labels,
  LC/MC/SC low hundreds and First North a few hundred.
- Grep `src/Pipeline` and `src/Store` for `guzzle` / `avanza.se` / `market-stock-filter` —
  expected: no hits.
