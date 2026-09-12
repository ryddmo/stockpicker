---
title: 'Inloggning (Login)'
type: 'feature'
created: '2026-09-12'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
context: ['{project-root}/AGENTS.md']
baseline_commit: 'd84c23df761e3498b381d8d1e4ac959476313cc2'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Stefan has no way to reach the collected owner-count data through a browser —
everything so far is cron jobs and SSH scripts. Before any authenticated screen (Topplista
in Story 4.2, etc.) can exist, the app needs a login gate and a durable session so Stefan
isn't re-authenticating from his phone every morning.

**Approach:** Add a `src/Web/` layer with an `AuthController` that checks a single hardcoded
username/password against `config.php` and issues an HMAC-signed, 30-day cookie (same
secret-handling convention as `cron_token`). Extend the front controller with `/login`
(GET+POST) and a session-gated `/` placeholder (real Topplista content lands in Story 4.2);
move the existing public healthcheck from `/` to `/health`.

## Boundaries & Constraints

**Always:** No native PHP session — auth state lives entirely in a signed cookie (AD-13).
New secrets (`login_username`, `login_password_hash`, `session_key`) follow the existing
`Config`/`config.php.dist` pattern. `require_session()` must be reusable as-is by Stories
4.2–4.5 — this story is the only place it's written. `/health` keeps today's exact JSON
shape, unauthenticated. Exact copy and status rules are in the I/O Matrix below.

**Never:** No registration, password reset, or multi-user table — one hardcoded credential
pair, verified with `password_verify()`. No client-side JS (server-rendered, full-page
POST-and-reload, AD-12 — the watchlist star's JS exception belongs to Story 4.2). No real
Topplista content — `/` after login is a placeholder, replaced in Story 4.2. Do not touch
`src/Pipeline/`, `src/Adapter/`, or `/cron/*`. Do not hand-edit `AGENTS.md`'s managed
`bmad:context` block (refreshed separately via `bmad-project-context`).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|---|---|---|---|
| No cookie | `GET /` | Login form (200), no message | N/A |
| Correct login | `POST /login` valid user+pass | `Set-Cookie` (HMAC, exp now+30d), 302 → `/` | N/A |
| Wrong password | `POST /login` bad pass | Login form (200), "Fel användarnamn eller lösenord." inline | N/A |
| Expired cookie | `GET /`, valid signature, `exp` past | Login form + "Sessionen har gått ut. Logga in igen." | N/A |
| Tampered cookie | `GET /`, bad signature | Login form, no message (same as no cookie) | N/A |
| Already logged in | `GET /login`, valid unexpired cookie | 302 → `/`, skip the form | N/A |
| Valid session | `GET /`, valid unexpired cookie | 200, placeholder authenticated page | N/A |
| `/health` | `GET /health`, no cookie | 200 JSON, today's exact shape, unauthenticated | N/A |
| Wrong method | `PUT /login` or `POST /` | 405, same convention as cron routes | N/A |

</frozen-after-approval>

## Code Map

- `public_html/index.php:44-224` — shared `try { switch ($path) {...} } catch (\Throwable)`. Add `/login` (GET/POST), gate `/` behind a session check with a placeholder response, add `/health` with today's `/` body (lines 52-58) moved verbatim.
- `public_html/index.php:226-233` — `authorize_cron()` (`hash_equals` + `send_json(403,...); exit;`) is the pattern to mirror for `require_session(Config): bool`, redirecting to `/login` instead of sending JSON.
- `src/Config.php:98-107` — `cronToken()` accessor (`$this->data['cron_token'] ?? null`, throw `RuntimeException` if missing/empty) is the pattern for `sessionKey()`, `loginUsername()`, `loginPasswordHash()`.
- `config.php.dist` (root, near `cron_token`) — add the three new keys with a comment: `php -r "echo password_hash('your-password', PASSWORD_BCRYPT), PHP_EOL;"`.
- New `src/Web/AuthController.php` (`Stockpicker\Web`) — credential check, cookie issue/verify (see Design Notes), login-form + inline-error rendering.
- `tests/FrontControllerTest.php` — full-stack HTTP precedent (`php -S` against an isolated temp copy + real HTTP requests). Update the `/` healthcheck assertion to `/health`; add login-flow cases.
- New `tests/Web/AuthControllerTest.php` (`Stockpicker\Tests\Web`) — unit-level cookie sign/verify/expiry.
- No migration — no `users` table; credentials live in `config.php` (none exists in `db/migrations/`).
- `README.md` — one-line edit: drop "no UI" framing, matching `SPEC.md` CAP-8 (already on `main`).

## Tasks & Acceptance

**Execution:**
- [x] `src/Config.php` -- add `sessionKey()`, `loginUsername()`, `loginPasswordHash()` accessors -- mirrors `cronToken()`, keeps secret access consistent
- [x] `config.php.dist` -- add the three new keys with a comment on generating the bcrypt hash -- keeps the dist template authoritative for deploy setup
- [x] `src/Web/AuthController.php` -- credential check, cookie issue/verify, login form + inline error rendering -- the only place session logic lives, reused by every later Story 4.x route
- [x] `public_html/index.php` -- add `/login` (GET+POST), `/health` (moved current `/` body), gate `/` behind `require_session()` with a placeholder authenticated response -- wires the controller into the existing switch-based router
- [x] `tests/Web/AuthControllerTest.php` -- unit tests for cookie sign/verify/expiry edge cases from the I/O Matrix
- [x] `tests/FrontControllerTest.php` -- update `/` healthcheck test to `/health`; add end-to-end login-flow cases from the I/O Matrix
- [x] `README.md` -- drop the stale "no UI" line

**Acceptance Criteria:**
- Given no valid session cookie, when any route other than `/login` or `/health` is requested, then the response is the login form, not an error
- Given correct credentials submitted to `/login`, when the request completes, then a signed cookie valid for 30 days is set and the response redirects to `/`
- Given the session cookie's signature is valid but its expiry has passed, when an authenticated route is requested, then the login form renders with "Sessionen har gått ut. Logga in igen."
- Given `/health` is requested with no cookie at all, when the response is inspected, then it is unchanged from today's `/` healthcheck (status 200, same JSON shape)

## Implementation Notes

Implemented as specified: `src/Web/AuthController.php` + a new `SessionStatus` enum
(Valid/Missing/Invalid/Expired) backing `sessionStatus()`; `require_session()` in
`public_html/index.php` composes on it and returns `bool` (never `exit`s) so Stories 4.2+
can reuse it directly. Cookie format matches Design Notes exactly:
`base64(payload).hmac_sha256(payload, sessionKey)`, payload `{"exp": ...}`.

One decidable gap not spelled out in the I/O Matrix: `GET /login` with an *expired*
(vs. missing/tampered) cookie. The matrix only specifies the expired-message behavior for
`GET /` (a protected route) and the skip-the-form redirect for `GET /login` with a *valid*
cookie — it says nothing about `GET /login` + expired. Resolved by treating expired the
same as missing/tampered at `/login` (plain form, no message), reserving "Sessionen har
gått ut. Logga in igen." for `require_session()` on protected routes only, since that's the
only place the matrix specifies it. Consistent with the matrix as written; flagging in case
the intent was for `/login` itself to also surface the expired message.

Verified independently (not just from the implementation report): full diff read against
`baseline_commit`, all 7 execution tasks and all 4 spec-level ACs confirmed against the
diff, all 9 I/O Matrix rows traced to a specific passing test, `composer test` re-run
(287 tests / 1413 assertions, clean) plus a targeted re-run of the 24 auth/login/health
tests by name — all pass.

## Spec Change Log

## Review Triage Log

- **New Config accessors' throw-on-empty is untested** — `medium`, routes `patch`. `sessionKey()`/`loginUsername()`/`loginPasswordHash()` mirror `cronToken()`'s throw-on-missing/empty pattern, but unlike `cronToken()` (covered by `testConfigCronTokenThrowsOnEmptyString` in `tests/SmokeTest.php`), no test exercises their `RuntimeException` branch — every test that builds a `Config` supplies all three non-empty. A silent regression here (e.g. `?? ''` instead of the throw) would let an empty HMAC key or credential slip through undetected. Fix is a direct addition of three mirrored tests — no new public surface, no unproven state. (Corroborated independently by blind-hunter and verification-gap.)
- **Session cookie `Secure` flag doesn't account for Loopia's TLS-terminating LB** — `medium`, routes `patch`. `docs/deploy.md` confirms "`http://` → `https://` is a 301 at Loopia's nginx LB" — a proxy sits in front of the PHP/Apache backend, so `$_SERVER['HTTPS']` may be unset even when the browser connection is genuinely HTTPS, causing the session cookie to ship without `Secure` on production. Fix (check `$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'` too) is trivial and the LB's existence is now demonstrated, not assumed. (Corroborated by blind-hunter and edge-case-hunter.)
- **`docs/deploy.md`'s deploy-verification curl still expects the old `/` JSON healthcheck** — `medium`, routes `patch`. Verified directly: lines 359–361 curl `/` and assert the JSON body `{"status":"ok",...}`, but `/` now serves the login form (still 200, wrong body). An operator following this runbook after deploying this story gets a false-looking failure. Fix: point the JSON assertion at `/health`. (Corroborated by edge-case-hunter and verification-gap.)
- **`docs/deploy.md`'s config.php setup step omits the three new secrets** — `medium`, routes `patch`. Verified directly: the setup list (~line 161) only names `db`, `cron_token`, `log_path`; `login_username`/`login_password_hash`/`session_key` are absent, even though `config.php.dist` documents them. An operator following only this doc to provision a server would miss them and hit `RuntimeException` on first authenticated request. (verification-gap.)
- **README's Layout/local-dev sections don't mention the new secrets** — `low`, routes `patch`. Verified directly: the `Layout` config.php line still says only "DB creds, cron token", and the `Local development` walkthrough's `cp config.php.dist config.php` step never says to replace the `change-me` login placeholders — a reader following it literally can start the server and hit `/health`, but cannot actually log in at `/` (the shipped `login_password_hash` placeholder isn't a valid bcrypt hash). (blind-hunter.)
- **No test for `GET /login` with an expired (but validly signed) cookie** — `low`, routes `patch`. Verified: `tests/FrontControllerTest.php` covers expired-at-`/` and valid-at-`/login`, but not expired-at-`/login`. The spec's own Implementation Notes already record the intended behavior (same as missing/tampered — plain form, no message); nothing currently locks that in against regression. (blind-hunter.)
- **No logout route** — rejected, out of scope. `EXPERIENCE.md`'s information architecture and all four flows never include a logout action; this is a deliberate single-user, long-lived-session design (30-day cookie, no affordance to end it early), not an oversight this story introduced. (blind-hunter.)
- **No throttling/lockout/audit logging on failed login** — rejected, out of scope. `EXPERIENCE.md`'s Tillståndsmönster is explicit: "inget utelåsningsmeddelande (en användare, inget mål för säkerhetshärdning)" — no lockout, single user, not a security-hardening target. This spec's own Boundaries ("no lockout/attempt-counting") already carries that exact upstream decision forward. (blind-hunter.)
- **Login always redirects to `/`, never to an originally-requested URL** — rejected; fix would require editing this build's frozen AC ("the response redirects to `/`" is explicit and frozen in `<frozen-after-approval>`). Legitimate forward-looking note for Story 4.2+ once other protected routes exist — flagged to the human separately, not filed here. (blind-hunter.)
- **AC wording ("any route other than `/login` or `/health`") could be read as implying `/cron/*` and unknown routes should be session-gated too** — `false`. Verified: the same spec's Boundaries section explicitly forbids touching `/cron/*`, and the Code Map never lists cron/unknown routes as session-gated; the diff confirms their token/404 behavior is unchanged. No actual bad outcome occurs — the AC's literal wording is loose, but nothing in the spec or the shipped code creates real ambiguity once read together. (edge-case-hunter.)

## Design Notes

Cookie `stockpicker_session`: `base64(payload).signature`, `payload` = JSON `{"exp":
<unix-timestamp>}` (nothing else needed — one user), `signature =
hash_hmac('sha256', payload, Config::sessionKey())`. Verify by recomputing the signature
with `hash_equals()` before trusting `exp` — no session storage, matching AD-13.
`require_session()` returns a bool rather than calling `exit` itself, so Story 4.2+ can
compose it into their own dispatch instead of copy-pasting cron's `send_json`+`exit`.

## Verification

**Commands:**
- `composer test` -- expected: all PHPUnit tests pass, including new `AuthControllerTest`
  and the updated `FrontControllerTest` cases, no skips beyond the existing DB-guarded ones

**Manual checks (if no CLI):**
- Run `php -S localhost:8080 -t public_html` against a local `config.php` with a test
  username/bcrypt hash; curl through: no cookie → login form; correct POST → `Set-Cookie` +
  redirect; wrong password → inline error; `/health` → unchanged JSON with no cookie at all.
