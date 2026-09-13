---
title: 'Länk till aktien på Avanza'
type: 'feature'
created: '2026-09-13'
status: 'done'
route: 'oneshot'
review_loop_iteration: 0
context: []
baseline_commit: '01eca2d764ef0adbd3830d7253a969d5dc24d866'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Aktiedetalj (`/stock/{isin}`) has no way to jump to the instrument's own
page on Avanza — a stated post-launch wish (item 3 of the user's v1 feedback).

**Approach:** Add a visible link in the page header that opens
`https://www.avanza.se/aktier/om-aktien.html/{avanzaOrderbookId}` in a new tab, built
from `Instrument::$avanzaOrderbookId` (already cached by `UniverseSync`). Verified
against live Avanza pages during planning (multiple real, correctly-titled indexed
pages confirm this bare-orderbookId form resolves without a name slug, e.g.
`.../om-aktien.html/5269` → Volvo B, `.../om-aktien.html/1296604` → Volvo Car B) — no
open question needed. An instrument with a `null` `avanzaOrderbookId` (not yet
resolved, or Nordnet-only) silently omits the link rather than rendering a broken one.
`target="_blank" rel="noopener noreferrer"` so the authenticated session is never left
or exposed to reverse-tabnabbing. Extract the link markup as its own `public static`
pure function on `StockDetailController` (same precedent as
`LeaderboardController::rowBodyHtml()`/`streakBadgeHtml()`), so it's unit-testable
without a database, and cover the null-omission + present-link cases directly; add one
`FrontControllerIntegrationTest` assertion on `/stock/{isin}` confirming the link
appears in real rendered output for a seeded instrument with a cached orderbook id.

</frozen-after-approval>

## Implementation Notes

Added `StockDetailController::avanzaLinkHtml(?string $orderbookId): string` as a
`public static` pure function (same precedent as
`LeaderboardController::rowBodyHtml()`): returns `''` when `$orderbookId` is `null`
or empty, otherwise an `<a>` to `https://www.avanza.se/aktier/om-aktien.html/{id}`
(`rawurlencode()`'d, then `htmlspecialchars`-escaped) with
`target="_blank" rel="noopener noreferrer"`. Called from `pageHtml()` with
`$instrument->avanzaOrderbookId` and rendered inside `<header class="stock-header">`,
after the badges — `.stock-header`'s existing `flex-wrap: wrap` lets it drop to its
own line on narrow widths with no other layout change needed. Added `.avanza-link`
CSS (muted secondary-text link, matching `.tab`'s size/weight).

Tests: two direct unit tests on `avanzaLinkHtml()` (null-omission; URL/target/rel
present) in `StockDetailControllerTest.php`, plus one `FrontControllerIntegrationTest`
assertion on the existing `/stock/SE0000001001` Story 4.3 test — `seedMatchedUniverse()`
already caches `avanza_orderbook_id = '1001'` for Alpha AB, so no new fixture was
needed.

`composer test`: 438 tests, 2030 assertions, all green (up from 436/2025 baseline).

Review round found 5 findings; 4 patched, 1 deferred (orderbookId rotation staleness,
a pre-existing characteristic of `avanza_orderbook_id`, not introduced by this story).
Patches: added an integration test proving the link is actually absent from a real
`/stock/{isin}` render when no `avanza_orderbook_id` is cached (previously only the
pure function's null case was covered); added the missing empty-string unit test;
added `aria-label="Visa på Avanza, öppnas i en ny flik"` to the anchor (the app's
first `target="_blank"` link — nothing previously announced the new-tab behavior to
screen readers); added `white-space: nowrap` to `.avanza-link` so the short label
can't wrap mid-phrase on narrow `.stock-header` layouts, consistent with Story 5.1's
mobile-layout fix on the same page family. The first integration-test attempt
asserted the bare substring `'avanza-link'` was absent, which false-failed because
`css()`'s static stylesheet always emits the `.avanza-link { ... }` rule regardless of
whether the element itself renders — corrected to assert `'class="avanza-link"'`
(the actual element marker) instead. `composer test` after patches: 440 tests, 2034
assertions, all green.

## Review Triage Log

- **[medium, patch]** AC3 (silent omission for a missing `avanza_orderbook_id`) was only unit-tested against the pure function directly; no test rendered a real `/stock/{isin}` response for a null-orderbookId instrument and confirmed the link is actually absent from the page. Verified: `FrontControllerIntegrationTest::seedMatchedUniverse()` seeds a non-null id for every fixture row, so the wiring in `pageHtml()` was unverified end-to-end. Fixed: added `testStockDetailOmitsTheAvanzaLinkWhenNoOrderbookIdIsCached()`.
- **[low, patch]** `avanzaLinkHtml()`'s `$orderbookId === ''` branch had no test. Verified real (the condition exists in the code, just untested). Fixed: added `testAvanzaLinkHtmlOmitsTheLinkWhenOrderbookIdIsAnEmptyString()`.
- **[low, patch]** The app's first `target="_blank"` link gave no cue to screen-reader users that it opens a new tab — a bare "↗" glyph typically announces as "north east arrow," not "opens in a new tab." Verified by grep: no prior `target="_blank"`/`rel="noopener"` convention exists anywhere in `src/` to have inherited this from. Fixed: added `aria-label="Visa på Avanza, öppnas i en ny flik"` to the anchor.
- **[low, patch]** `.avanza-link` had no `white-space: nowrap`, and sits as a flex-shrinkable item in `.stock-header` alongside the star/h1/badges — on a narrow viewport with a long instrument name, the short label text could in principle wrap mid-phrase, directly analogous to the class of bug Story 5.1 fixed on the same page family. Fixed: added `white-space: nowrap` to `.avanza-link`.
- **[defer]** `avanza_orderbook_id` can rotate for a given ISIN (confirmed in `UniverseSyncTest`, an existing Epic 2 characteristic), so a previously bookmarked/shared Aktiedetalj page could link to a stale Avanza orderbook page after a rotation. Pre-existing risk inherent to the cached field, not introduced by this story — this story only consumes the field as-is. Deferred to `deferred-work.md`.

