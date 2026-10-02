---
title: 'Plusdagar landing view + remembered Topplista view (cookie)'
type: 'feature'
created: '2026-10-02'
status: 'done'
baseline_revision: '765206eee6952777aa21030644e7836257f89fef'
review_loop_iteration: 0
followup_review_recommended: false
context:
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-stockpicker-2026-09-12/EXPERIENCE.md'
warnings: ['oversized']
deferred:
  - summary: >-
      AGENTS.md doesn't document the topplista_view cookie (only `/` via LeaderboardController::resolveView() reads or writes it; its value always goes through normalizeView()).
    evidence: |-
      AGENTS.md lists session auth as the only cookie and says UI defaults are per-request; a later story could add another remembered cookie or trust this one unnormalized.
    location: >-
      AGENTS.md (Conventions)
    severity: low
---

<intent-contract>

## Intent

**Problem:** Stefan opens Topplista every morning and makes the same click to Plusdagar. Leaving Topplista loses the view: the Topplista tab from Aktiedetalj, Fullständig lista or Bevakningslista resets period and market (deferred item, spec-topplista-market-filter).

**Approach:** Decided by Stefan in the 2026-10-02 party session.
- **Plusdagar becomes the landing view.** The default ranking changes from Flest ägare to Plusdagar.
- **One cookie remembers the last Topplista view:** source, ranking, period, market and spikes.
  - It is written only by Topplista.
  - Explicit URL params always win and update the cookie.
  - With no cookie, the defaults apply: Plusdagar · Månad · Alla · Avanza.
- **AD-14 exception:** this is a deliberate, documented exception to AD-14's "no stored preference".

## Boundaries & Constraints

**Always:**
- **Which requests use the cookie** (renegotiated 2026-10-02 after review: Stefan chose 1b; the junk-param rule is a UX decision delegated by Stefan):
  - **No view param:** a request to `/` whose query contains **none** of the view params (`source`, `ranking`, `period`, `market`, `spikes`) renders the view stored in the cookie (no cookie means the defaults). This covers an empty query and junk such as `fbclid`/`utm_*`. The cookie is **not** written.
  - **Only `source`:** if `source` is the **only** view param present, render the cookie's view with that source replacing the stored one, and write the merged view to the cookie. This is how the Topplista tab on other pages (`/?source=nordnet|alla`) returns to the remembered view.
  - **Any other view param:** if `ranking`, `period`, `market` or `spikes` is present, the request is authoritative. Missing params take their defaults (never cookie values), and the normalized view is written to the cookie.
- **Header links:** every Topplista header link (source, ranking, period, market, spikes) is non-bare, so `url()` always emits `ranking`. Other defaults are still omitted.
- **Cookie values are untrusted:** they go through the same normalizers/whitelists as the URL params. A malformed or unknown value means that param's default, never a 500.
- **`spikes`:** kept only when ranking is Plusdagar, the same rule as the URLs.
- **Cookie attributes:**
  - name `topplista_view`, value = the URL-style query string of the normalized non-default view;
  - `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS (same detection as the session cookie at `public_html/index.php:244`), path `/`, 365-day expiry.
- **Auth unchanged:** unauthenticated requests still redirect to `/login` before anything reads or writes this cookie.
- **Copy:** Swedish.

**Never:**
- No `settings` table and no DB storage.
- Never write the cookie from any route other than `/`.
- No new JS.
- No change to the e-mail digest (it has no Topplista links), Fullständig lista, Bevakningslista, Aktiedetalj or the Info page's tab bar. Their existing Topplista tab links (`/` or `/?source=nordnet`) land on the remembered view through the rules above.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Fresh visit | `/`, no cookie | Plusdagar · Månad · Alla · Avanza; no Set-Cookie | — |
| Explicit view | `/?ranking=count&market=LC` | Flest ägare, LC; Set-Cookie `topplista_view` holding `ranking=count&market=LC` | — |
| Remembered | `/` + cookie `ranking=steady&period=vecka&market=SC&source=nordnet` | Stadig tillväxt · Vecka · SC · Nordnet rendered | — |
| URL wins, rest default | `/?ranking=steady` + cookie with `market=SC` | Stadig tillväxt · Månad · **Alla**; cookie overwritten | — |
| Back to defaults | In an LC view, click "Alla" | href is `/?ranking=plus` (or with the current non-default params); Alla rendered; cookie updated | — |
| Flest ägare link | Any page | Flest ägare tab href carries `ranking=count` | — |
| Garbage cookie | `topplista_view=%%%&ranking=xx&market=ZZ` | Defaults; 200 | Ignored values fall back |
| Spikes outside Plusdagar | Cookie `ranking=count&spikes=exclude` | Flest ägare; spikes dropped | — |
| Other routes | `/list`, `/stock/{isin}`, `/watchlist`, `/info` | Never send Set-Cookie `topplista_view` | — |
| Junk query | `/?fbclid=abc` + cookie `ranking=steady&market=SC` | Stadig tillväxt · SC rendered; no Set-Cookie | — |
| Source-only (tab from Nordnet page) | `/?source=nordnet` + cookie `ranking=steady&period=vecka&market=LC` | Nordnet · Stadig tillväxt · Vecka · LC; cookie updated with `source=nordnet` | — |
| Source-only, no cookie | `/?source=alla` | Alla · Plusdagar · Månad · Alla market; cookie written | — |
| Topplista's own tab in Alla | Tab href `/?source=alla` after an Alla · Stadig tillväxt · SC view | Same view restored | — |
| Return from Aktiedetalj | Topplista tab (`/`) after viewing `/?ranking=plus&period=vecka&market=LC` | Plusdagar · Vecka · LC | — |

</intent-contract>

## Code Map

- `src/Web/LeaderboardController.php`:
  - `normalizeRanking()` (~:160): change the `default` arm to `RANKING_PLUS`.
  - `url()`: always emit `ranking`. Keep omitting the other defaults.
  - Add pure static helpers, unit-testable without HTTP:
    - one that normalizes a raw `array` of params (source/ranking/period/spikes/market) into the canonical view;
    - one that serializes a view to the cookie value (`http_build_query` of the non-default params plus `ranking`);
    - one that parses a cookie string (`parse_str`) back through the normalizer.
  - `render()` keeps its signature. The front controller passes the resolved raw values.
  - Update the class docblock (per-request + cookie) and the `RANKING_COUNT` landing comments.
- `public_html/index.php:85-103` (the `/` route): wiring only. A controller helper (e.g. `resolveView(array $get, mixed $cookie): array{view, write: bool}`) applies the three-way rule from Boundaries. index.php calls it, calls `render()`, and on `write` calls `setcookie(...)`, mirroring the session cookie block at :244-252. Keep the logic in the controller (no business logic in `public_html/`).
- **Starting point:** `spec-plusdagar-landing-cookie.attempt-1.patch` (same folder) is a reviewed, green first attempt built on the old "any query string" rule. Apply it with `git apply`, then change only the authority rule, the related tests and docs/copy. Fix these known issues from its review:
  - the Info copy lost "eller" and says the page always opens in Plusdagar;
  - EXPERIENCE.md journey step 2 and the IA row still name Flest ägare as the landing view;
  - the AD-14 edit has an unwrapped ~150-char line.
- `src/Web/InfoController.php`: the Topplista paragraph says Plusdagar is the landing view and that Topplista remembers the last view (cookie) until another choice is made.
  - Update any `tests/Web/InfoControllerTest.php` assertions it touches.
- `tests/Support/EndpointFixture.php:192-240`:
  - `request()` drops response headers. Add a way to read them, e.g. a `getWithHeaders()` that returns `$http_response_header`, without changing `get()`'s return shape.
  - The `$cookie` arg is a raw `Cookie:` header, so multiple cookies are `a=b; c=d`.
- Tests:
  - `tests/FrontControllerIntegrationTest.php`: about 12 `get('/'` call sites assume Flest ägare at `/`. Change them to `/?ranking=count` where they test Flest ägare, or update the expectations. Plus one test per matrix row.
  - `tests/Web/LeaderboardControllerTest.php`: `normalizeRanking` default, cookie serialize/parse round-trip, garbage parse.
- Docs:
  - `_bmad-output/planning-artifacts/architecture/architecture-stockpicker-2026-09-08/ARCHITECTURE-SPINE.md:240-243` (AD-14 Rule): amend the "rankningsläge=Most Owners … ingen preferenslagring" sentence. The Topplista default is now Plusdagar, and one exception exists: the `topplista_view` cookie (personal UI preference, written only by Topplista, never server-side storage).
  - `_bmad-output/planning-artifacts/ux-designs/ux-stockpicker-2026-09-12/EXPERIENCE.md`: the Ranking-mode toggle row "Standard: Flest ägare" becomes Plusdagar. The Marknadsfilter row's "Kommer ihåg valet bara under navigering … (inget sparat tillstånd, AD-14)" becomes the cookie behaviour. Swedish.
  - `AGENTS.md` mentions neither; leave it.

## Tasks & Acceptance

**Execution:**
- `src/Web/LeaderboardController.php` -- default Plusdagar, `ranking` always in links, view normalize/serialize/parse helpers -- core behaviour.
- `public_html/index.php` -- choose the param source by query-string presence; write the cookie on authoritative requests -- wiring only.
- `tests/Support/EndpointFixture.php` -- expose response headers -- needed to assert Set-Cookie.
- `tests/FrontControllerIntegrationTest.php`, `tests/Web/LeaderboardControllerTest.php` -- one test per matrix row; migrate the tests that assumed Flest ägare at `/`.
- `src/Web/InfoController.php` (+ test) -- copy.
- `ARCHITECTURE-SPINE.md` (AD-14), `EXPERIENCE.md` -- documented exception and new defaults.

**Acceptance Criteria:**
- Given a logged-in browser with no `topplista_view` cookie, when `/` is opened, then the Plusdagar tab is active with Månad, Alla and Avanza.
- Given any Topplista header link, then its href contains `ranking=`.
- Given the whole suite, then `composer test` is green with no skipped Store/integration tests, and digest, Fullständig lista, Bevakningslista and Aktiedetalj tests pass unmodified, apart from the `/`-default migrations.

## Spec Change Log

- 2026-10-02, after review pass 1 (intent gap): the authority rule was renegotiated with Stefan. Previously "any query string is authoritative", which made the `/?source=nordnet|alla` tab links reset the view. Now: no view param → cookie; only `source` → cookie + that source; otherwise authoritative. The Never clause no longer assumes the tab links are plain `/`. Added matrix rows: junk query, source-only (with and without cookie), Topplista's own Alla tab. KEEP from attempt 1: the `normalizeView`/`serializeView`/`parseViewCookie` helpers, `ranking` always in header links, `getWithHeaders()` in the fixture, the migrated `/?ranking=count` tests, the docs/copy updates (with the noted fixes).

## Review Triage Log

### 2026-10-02 — Review pass
- verdicts: 20 findings — high 0, medium 2, low 15, false 3, maybe-false 0
- attempted change saved: `spec-plusdagar-landing-cookie.attempt-1.patch` (code reverted; spec kept)
- findings:
  - `[medium]` `[intent_gap]` (blind, edge ×2, verification-gap) Topplista tab links on Fullständig lista/Bevakningslista/Aktiedetalj/Info are `/?source=nordnet`, and on Topplista itself `/?source=alla|nordnet`. Under "any query string is authoritative" they reset ranking/period/market and overwrite the cookie, so the Problem stays unsolved for Nordnet/Alla. The fix needs either a change to those controllers (forbidden by the Never clause, which assumed "plain `/`" links) or a different authority rule (contradicts Always). Several readings with different behaviour → intent gap.
  - `[medium]` `[intent_gap]` (verification-gap) No round-trip test from a non-Avanza view through the real tab href — same root cause; moot until the gap is answered.
  - `[low]` `[intent_gap]` (blind, edge, intent-alignment R2) A query string with no view params (`/?fbclid=…`) resets the view. The intent literally says "any query string", so changing it is an intent decision; asked alongside the main gap.
  - `[low]` `[reject]` (blind, intent-alignment) A bare `/` never refreshes the 365-day expiry or repairs a garbage cookie — the intent writes only on query requests; changing that edits the contract.
  - `[low]` `[reject]` (blind, intent-alignment, verification-gap) The `Secure` branch for HTTPS is untested; the fixture can't send arbitrary headers, and the session cookie shares the gap — would be a defer, but moot under the intent gap.
  - `[low]` `[reject]` (blind) The cookie-options block is duplicated from `/login` — developer-only; moot.
  - `[low]` `[reject]` (blind) EXPERIENCE.md journey step and IA row still name Flest ägare as landing; the spine sequence diagram omits the cookie — direct doc fix, moot under the gap (re-derive).
  - `[low]` `[reject]` (blind) Info copy lost the "eller" and claims the page always opens in Plusdagar — direct copy fix, moot under the gap.
  - `[low]` `[reject]` (blind) The AD-14 edit has one unwrapped ~150-char line — cosmetic, moot.
  - `[low]` `[reject]` (blind) The header-link test scans only `.page-header`, not the tab bar — part of the main gap.
  - `[false]` `[reject]` (intent-alignment) The Set-Cookie wire value is URL-encoded, not literal `ranking=count&market=LC` — PHP encodes and `$_COOKIE` decodes; the logical value matches the matrix.
  - `[low]` `[reject]` (intent-alignment) The unauthenticated test asserts the final login page rather than the redirect itself — the ordering in code is correct; test style only.
  - `[low]` `[reject]` (intent-alignment) "Return from Aktiedetalj" test builds the Cookie header by hand instead of a cookie jar — acceptable simulation at the HTTP surface.
  - `[false]` `[reject]` (intent-alignment) "Visa fullständig lista" lacks `ranking` — it points to `/list`, not Topplista; correct.
  - `[false]` `[reject]` (intent-alignment) Topplista's own Avanza tab link is bare `/` — correct under the rule, since the cookie was just written.
  - `[low]` `[intent_gap]` (edge claim) EXPERIENCE/Info copy say "remembers until another choice" while the Alla/Nordnet tab resets it — same root cause as the first row.
  - `[low]` `[intent_gap]` (blind) The Topplista tab-bar behaviour depends on the source (Avanza keeps, Nordnet/Alla reset) — same root cause.
  - `[low]` `[intent_gap]` (verification-gap other) Functional-defect restatement of the first row — same root cause.
  - `[low]` `[reject]` (blind) Missing round trips from `/list`, `/watchlist`, `/info` — covered by the gap's re-derivation.
  - `[low]` `[reject]` (intent-alignment) Watchlist empty-state "Till Topplista" link not asserted — it is a bare `/`; fine under either reading.

### 2026-10-02 — Review pass 2
- verdicts: 22 findings — high 0, medium 0, low 17, false 5, maybe-false 0 (3 carried)
- findings:
  - `[low]` `[reject]` (edge, blind, verification-gap other) An Avanza page's Topplista tab is a bare `/`, so it lands on the remembered source (e.g. Nordnet), not the page's Avanza. The intent's Never clause explicitly keeps those tab links unchanged and says `/` lands on the remembered view, so the fix edits the contract. Reported to Stefan as a residual risk.
  - `[low]` `[reject]` (edge) A garbage/empty `source` in the source-only branch resets the source to Avanza and writes it — matches "a malformed value means that param's default".
  - `[false]` `[reject]` (edge) `HTTPS=off` would set Secure over HTTP — same detection as the session cookie, which works in production on Loopia.
  - `[false]` `[reject]` (edge) Output sent before `setcookie` — `render()` returns a string and nothing is emitted until `render_html()` after `setcookie`.
  - `[low]` `[reject]` carried (blind) Expiry never slides on bare `/` — carried from pass 1 (intent writes only on view-param requests).
  - `[low]` `[patch]` (blind) `epics.md:661` still says Flest ägare is the default — fixed to Plusdagar.
  - `[low]` `[patch]` (blind) UX `.memlog.md` has an earlier 'no cookie' decision with no supersede marker — appended a change entry superseding it.
  - `[low]` `[reject]` carried (blind) Cookie-option block duplicated from `/login` — carried from pass 1.
  - `[low]` `[reject]` carried (blind, verification-gap, intent-alignment) The HTTPS `Secure` branch is untested — carried from pass 1 (shared pre-existing gap with the session cookie).
  - `[low]` `[patch]` (blind) `EndpointFixture` adds a `$http_response_header` use, which PHP 8.5 deprecates — switched the fixture to `http_get_last_response_headers()`.
  - `[low]` `[reject]` (blind) Old bookmarks without `ranking` now mean Plusdagar — inherent in the intended default change.
  - `[low]` `[defer]` (blind) AGENTS.md doesn't mention the `topplista_view` cookie — agent-context file → deferred.
  - `[low]` `[reject]` (blind) The attempt-1 patch file is in the change set — kept on purpose as the audit artifact referenced by pass 1's triage log.
  - `[low]` `[reject]` (blind) No `Cache-Control` on `/` — dynamic PHP responses carry no validators and aren't heuristically cached; unlikely in practice.
  - `[low]` `[reject]` (blind) Untested: empty `?source=`, spikes surviving a source-only merge, login→remembered, expired session — the merge keeps every cookie field (unit-tested); login lands on a bare `/` (covered by the remembered-view test). Negligible.
  - `[low]` `[reject]` (intent-alignment R2a) Empty-valued params count as present — a defensible choice within "present"; no user-visible harm.
  - `[low]` `[reject]` (intent-alignment) The Approach line "URL params always win" vs the Boundaries source-only merge — the fix edits the spec; Boundaries controls.
  - `[false]` `[reject]` (intent-alignment) About 30 tests moved to `/?ranking=count` — that is the intended migration, not a gap.
  - `[false]` `[reject]` (intent-alignment, verification-gap note) Flaky DB errors on a first combined run — the shared test DB collided with concurrent reviewer runs; isolated reruns and the orchestrator's full run are green.
  - `[low]` `[reject]` (intent-alignment R2b) Garbage source falls back to Avanza — same as the second row.
  - `[low]` `[reject]` (intent-alignment R2c) Tab bar outside the always-emit-`ranking` rule — matches the matrix row "Topplista's own tab in Alla".
  - `[false]` `[reject]` (verification-gap) The cookie integration tests self-skip without MariaDB — CI provisions MariaDB, and the local full run had 0 skipped.

## Design Notes

Why there's a three-way rule instead of merging the cookie per param:
- Header links omit default values. If the cookie were merged per param, clicking "Alla" would re-apply a remembered market. So any query with a real view param besides `source` is the whole truth.
- A `source`-only query is what the other pages' Topplista tab sends, so it means "go back to my view, in this source".
- Queries without view params (tracking junk) never change the view.

## Verification

**Commands:**
- `docker compose up -d && composer test` -- expected: green, 0 skipped.

**Manual checks (if no CLI):**
- Log in, pick Stadig tillväxt · Vecka · LC, open a stock, click the Topplista tab: the same view returns. Close the browser, reopen `/`: still that view.


## Auto Run Result

Status: done

**Summary:**
- Topplista lands on **Plusdagar** · Månad · Alla · Avanza.
- The last Topplista view is remembered in the `topplista_view` cookie (HttpOnly, SameSite=Lax, Secure on HTTPS, 365 days), written only by `/`.
- Which request decides the view (`LeaderboardController::resolveView()`):
  - A query with no view param (empty, or junk like `fbclid`) renders the remembered view without writing.
  - A `source`-only query renders the remembered view in that source and writes it. This is how the other pages' `/?source=nordnet|alla` tab links return to the view.
  - Any other view param is authoritative: missing params take their defaults, and the view is written.
- Header links always carry `ranking`.
- AD-14 has a documented exception.

**Files:**
- `src/Web/LeaderboardController.php`: default Plusdagar, `resolveView`/`normalizeView`/`serializeView`/`parseViewCookie`, `ranking` always in links.
- `public_html/index.php`: `/` route wiring and `setcookie`.
- `src/Web/InfoController.php` (+ test): copy covering the landing view and the remembered view.
- `tests/Support/EndpointFixture.php`: `getWithHeaders()`, `http_get_last_response_headers()`.
- `tests/FrontControllerIntegrationTest.php`, `tests/Web/LeaderboardControllerTest.php`: one test per matrix row; Flest ägare tests moved to `/?ranking=count`.
- `ARCHITECTURE-SPINE.md` (AD-14), `EXPERIENCE.md`, `epics.md`, UX `.memlog.md`: docs.
- `spec-plusdagar-landing-cookie.attempt-1.patch`: audit artifact from pass 1.

**Review:**
- **Pass 1:** intent gap on the `/?source=` tab links. Resolved with Stefan (1b + the UX call on junk params), the contract renegotiated, and the code re-derived from the saved attempt.
- **Pass 2:** 3 low patches applied (epics.md default, memlog supersede note, PHP 8.5 header API), 1 deferred (AGENTS.md cookie note), and the rest rejected with reasons in the triage log.
- **Follow-up review recommended:** false. Pass 2 patched 0 high and 0 medium.

**Verification:** `composer test` gave 678 tests OK, 0 skipped, with MariaDB up.

**Residual risks:**
- An Avanza page's Topplista tab is a bare `/`, so it returns to the remembered source, not to Avanza. The intent keeps those links unchanged.
- The HTTPS `Secure` branch is untested (shared with the session cookie).
- Expiry doesn't slide on bare `/` visits.
- The manual browser check hasn't been done.
