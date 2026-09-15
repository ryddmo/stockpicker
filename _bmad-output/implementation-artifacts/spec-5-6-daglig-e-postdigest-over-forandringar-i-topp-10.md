---
title: 'Daglig e-postdigest över förändringar i topp 10'
type: 'feature'
created: '2026-09-15'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
context:
  - _bmad-output/implementation-artifacts/epic-5-context.md
baseline_commit: '274881908906f3aa3a6eb9c7eb4aefc9bac08566'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Stefan has to open Topplista himself to notice which stocks entered/left
the top-10 "Flest ägare"/"Stadig tillväxt" rankings, or moved rank — nothing surfaces
that automatically.

**Approach:** After `/cron/derive`'s existing work, a new isolated pipeline step diffs
today's Avanza-sourced top-10 (both rankings) against the previous trading day's, and
emails a plain-text Swedish summary to the configured recipient via PHP's local
`mail()`/`sendmail`, gated by the same `cron_gate()`/trading-day logic already in
place — a send failure never affects derive's own response.

**Amended 2026-09-15 (post-deploy, human-approved):** originally specified authenticated
SMTP to `mailcluster.loopia.se`. Live verification on the actual Loopia production server
after the first deploy showed the shared-hosting firewall blocks outbound connections to
that host on every port (587/465/25), for both IPv4 and IPv6 — a hard infrastructure
constraint, not a code defect. Switched to PHP's local `mail()`/`sendmail`, the same
already-proven mechanism `UniverseSync`/`FetchRunner`'s `alarm.email` already uses; no
SMTP host/port/auth, no `phpmailer/phpmailer` dependency.

## Boundaries & Constraints

**Always:** Reuse `cron_gate()`'s existing trading-day gate — the digest only runs when
derive itself runs. Ranking basis is Avanza only, matching Topplista's default/Alla-mode
basis. The From address + recipient live in `config.php` via a new `Config::digest()`
accessor (documented in `config.php.dist`), never hardcoded or committed — the same
discipline as every other secret here, not real environment variables (see Design
Notes). No password/credential is needed or read (local `mail()`, not authenticated
SMTP). Digest failures are caught, logged via `Logging::logger()`, and never affect
`/cron/derive`'s existing response or `ingest_run` row. Body text is Swedish, plain/
skeptical tone, matching this project's established microcopy convention; subject/body
are explicitly UTF-8/MIME-encoded since `mail()` does not do this on its own.

**Never:** No new database table or migration. No change to `Deriver.php` or the
`owner_count_metrics` view. No change to `/cron/refill`/`/cron/work` behavior. No email
when there's no previous trading day to diff against (first-ever run) — skip, don't
crash. No email when nothing changed.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|---|---|---|---|
| Normal trading day, real changes | today + prior trading day both have top-10 data, and differ | Email sent: IN/UT + rank moves with direction arrows, both rankings | N/A |
| No changes in either ranking | today's top-10 == prior trading day's, same order | No email sent | N/A |
| Weekend/holiday | `cron_gate()` already returns a skip response | Digest never computed or sent | N/A |
| No previous trading day has data yet | first-ever derive run | No email sent | No crash |
| More than 5 changes in one ranking | e.g. 8 entries/exits/moves combined | First 5 listed individually, rest as "+N till" | N/A |
| mail() send fails / digest config missing | `Config::digest()` throws, or `mail()` itself fails | `derive`'s normal 200 response and `ingest_run` row unaffected | Caught, logged via `Logging::logger()`, swallowed |

</frozen-after-approval>

## Code Map

- `public_html/index.php:325-346` (`/cron/derive` case) — insertion point: after
  `(new RunRepository($pdo))->record(...)`, before `send_json(200, [...])`. Call the new
  digest step here, wrapped in try/catch.
- `public_html/index.php:404-443` (`cron_gate()`) — reused as-is, no changes.
- `src/Config.php:13-183` — add `digest(): array{username: string, password: string,
  recipient: string}`, same pattern as `cronToken()`/`db()`, reading
  `$this->data['digest']`, throwing `RuntimeException` on missing/invalid.
- `config.php.dist` — document the new `'digest' => ['username' => '', 'password' => '',
  'recipient' => '']` key.
- `src/Logging.php:23-38` — `Logging::logger($config)` reused for failure logging, same
  idiom as `index.php`'s other caught cron errors.
- `src/Store/DerivedMetricsRepository.php:88-169` — add `topByOwnerCountAsOf(string
  $source, string $asOfDate, int $limit)` / `topByTrendQualityAsOf(...)`, mirroring the
  existing two methods' column list, `JOIN`, and filters, but querying `m.as_of_date =
  :date` directly instead of the `ROW_NUMBER` "latest" CTE (one row per isin/source/date
  already exists).
- `src/Store/TradingHolidayRepository.php:23-29` — `isHoliday(string $date)` reused to
  walk backward from today to find the previous trading day.
- `src/Pipeline/UniverseSync.php:55-82` — injectable `?callable $sendMail` precedent to
  mirror for testability.
- `composer.json` — add `"phpmailer/phpmailer": "^7.1"`.
- New: `src/Pipeline/TopTenDigest.php` — the digest class (see Design Notes).
- `tests/FrontControllerIntegrationTest.php:215-269` — existing `/cron/derive` tests; add
  digest cases here.
- `tests/Store/DerivedMetricsRepositoryTest.php:395` — pattern to mirror for the new
  `AsOf` method tests.

## Tasks & Acceptance

**Execution:**
- [x] `src/Config.php` -- add `digest()` accessor reading `$this->data['digest']`, validating non-empty strings, throwing like `cronToken()` -- keeps SMTP secrets out of git
- [x] `config.php.dist` -- document the new `digest` key
- [x] `composer.json` -- add `phpmailer/phpmailer": "^7.1"` to `require`
- [x] `src/Store/DerivedMetricsRepository.php` -- add `topByOwnerCountAsOf()`/`topByTrendQualityAsOf()`, mirroring the existing methods' shape but pinned to an exact `as_of_date`
- [x] `src/Pipeline/TopTenDigest.php` -- new class: resolves the previous trading day (walk back skipping weekends + `TradingHolidayRepository::isHoliday()`, capped at 10 days), fetches today's/prior day's top-10 for both rankings via the new `AsOf` methods, diffs them (IN/UT/rank-move with arrow, capped at 5 + "+N till"), builds the Swedish plain-text body, sends via an injectable `?callable $mailSender` (default: PHPMailer configured from `Config::digest()`, host/port as class constants `mailcluster.loopia.se`/587 STARTTLS); skips silently when there's no prior-day data or nothing changed
- [x] `public_html/index.php` -- in `/cron/derive`, after `RunRepository::record(...)`, call `TopTenDigest` wrapped in try/catch logging via `Logging::logger($config)`, never rethrowing (implemented by reusing the request's own `$logger`, already that same logger instance from bootstrap)
- [x] `tests/Store/DerivedMetricsRepositoryTest.php` -- test the two new `AsOf` methods: correct row for a pinned date, empty when no data exists for that date, same exclusion/ordering rules as the existing "latest" methods
- [x] `tests/Pipeline/TopTenDigestTest.php` -- new: unit-test the diff/formatting logic directly (IN/UT/move/arrow, 5-item cap, no-changes = no send, no-prior-day = no send) via a spy `$mailSender`, no real SMTP
- [x] `tests/FrontControllerIntegrationTest.php` -- extend `/cron/derive` tests: seed two trading days with a real diff, confirm digest ran via an injectable spy without breaking derive's existing response/`ingest_run` row; confirm a forced digest failure still leaves derive's own response intact (see Implementation Notes for how the spy crosses the subprocess boundary, and how the SMTP-failure case is exercised)

**Acceptance Criteria:**
- Given two trading days of `owner_count_metrics` data with a genuine top-10 change, when `/cron/derive` runs and its gate passes, then an email is sent to the configured recipient summarizing both rankings' IN/UT and rank moves with direction arrows
- Given no previous trading day has any data yet, when `/cron/derive` runs, then no email is sent and derive's normal response is unaffected
- Given `cron_gate()` would already skip (weekend/holiday/window-closed), when `/cron/derive` is hit, then no digest is computed or sent
- Given the mail send throws (or `Config::digest()` is missing/invalid), when `/cron/derive` runs, then the failure is logged via `Logging::logger()` and derive's own response/`ingest_run` row are unaffected

## Implementation Notes

Implemented as scoped: `Config::digest()`, `config.php.dist`'s new `digest`
key, `phpmailer/phpmailer: ^7.1` in `composer.json`/`composer.lock`,
`DerivedMetricsRepository::topByOwnerCountAsOf()`/`topByTrendQualityAsOf()`,
`src/Pipeline/TopTenDigest.php`, and the `/cron/derive` wiring in
`public_html/index.php` (after `RunRepository::record(...)`, before
`send_json(200, ...)`, wrapped in try/catch logging via the request's own
`$logger` — already `Logging::logger($config)` from bootstrap, so a second
instance isn't created).

One deliberate divergence from the literal Code Map, needed for
`tests/FrontControllerIntegrationTest.php`'s new cases: `EndpointFixture`
spawns `/cron/derive` in a separate `php -S` subprocess, so a PHP closure
(a real spy `$mailSender`) cannot be injected into `TopTenDigest` from the
test process — config.php is plain data (`var_export`), not code. To still
exercise the real send crossing that subprocess boundary (not just
`TopTenDigest`'s own unit tests), `index.php` reads an optional
`STOCKPICKER_DIGEST_SPY_FILE` env var — unset in production, so the real
`PHPMailer` sender is always used there — and when set, swaps in a sender
that appends each `(to, subject, message)` as one JSON line to that file
instead of touching SMTP. This mirrors the already-established
`STOCKPICKER_AVANZA_UNIVERSE_BASE_URI` test-seam idiom in the same file, and
does not weaken any boundary: SMTP host/port stay hardcoded class constants,
credentials still only ever come from `Config::digest()`, and the spy path
is exercised only by `EndpointFixture::enableDigestSpy()`, called from one
test. `EndpointFixture::start()` also now writes a `digest` config section,
but only when the spy is enabled — the default fixture still has none,
which doubles as the "digest config missing" failure-path test (see below).

The "SMTP send fails" edge-case row/acceptance criterion is verified at the
integration level via a config-missing failure (the default `EndpointFixture`
config has no `digest` section, so `Config::digest()` throws) rather than a
live/faked SMTP failure — both throw from inside the exact same try/catch in
`index.php`, so the caught-logged-swallowed behavior is identical either way,
without a test ever needing real network I/O or a fake TLS-speaking SMTP
server. `TopTenDigest`'s own PHPMailer-throws case is covered structurally
(the `defaultMailSender()` closure wraps `PHPMailer::send()` in a try/catch
that rethrows as a plain `RuntimeException`, caught the same way by the
caller) but is not separately unit-tested against a real SMTP server — doing
so would require either live credentials or a hand-rolled STARTTLS-speaking
mock server, judged not worth the added complexity/flakiness for a personal
project's cron path that already fails safe.

`tests/Pipeline/TopTenDigestTest.php` covers the diff/format/cap/skip logic
directly (IN/UT/move+arrow, 5-item cap + "+N till", no-change skip,
no-prior-day skip, weekend/holiday lookback, Avanza-only basis) via an
injected spy `$mailSender`, no real SMTP, per the task's own instruction.

**Post-deploy correction (2026-09-15):** the two paragraphs above describe the
original PHPMailer/SMTP implementation, which is now superseded — kept for
the historical record of why the spy-file test seam exists in `index.php`,
not as a description of current behavior. After the first production deploy,
live verification (SSH into the Loopia server, raw `curl telnet://` probes
against `mailcluster.loopia.se` on ports 587/465/25, then an actual PHPMailer
send attempt) showed every outbound connection attempt failed with
`Permission denied` at the OS/firewall level — Loopia's shared-hosting
environment blocks outbound SMTP entirely, regardless of credentials. Human
approved switching to PHP's local `mail()`/`sendmail` (already proven by
`UniverseSync`/`FetchRunner`'s `alarm.email`). Changes: `TopTenDigest::
defaultMailSender()` now builds a `From:`/`Content-Type: charset=UTF-8`
header block and calls `@mail()` directly (no PHPMailer, no SMTP host/port
constants); `Config::digest()` narrowed to `array{username, recipient}` — no
password field, since local `mail()` needs no auth (an existing `password`
key in a previously-configured `config.php` is simply ignored, not an
error); `phpmailer/phpmailer` removed from `composer.json`/`composer.lock`;
`config.php.dist`, `docs/deploy.md`, and this spec's own frozen Intent/
Boundaries/I-O-matrix/AC updated to match (frozen-block edit is
human-approved per this project's own rule for renegotiating intent).
`tests/SmokeTest.php`'s three `Config::digest()` validation tests updated to
stop asserting on the now-unchecked `password` key. Re-verified after the
change: `composer test` (555 tests, 2963 assertions, all green), `composer
run analyse` (PHPStan level 8, clean), and a real production SSH check
confirming `Config::digest()` loads correctly with the server's actual
`config.php`. Redeployed via `bin/deploy.sh`; production `/` and `/health`
both verified 200 after redeploy. Not yet verified: an actual `mail()` send
succeeding end-to-end in production (no genuine top-10 change has occurred
since the redeploy to trigger a real send) — the next night a real diff
exists will be the first live proof.

## Spec Change Log

## Review Triage Log

- **[low, patch]** (edge-case-hunter) `TopTenDigest::run()` discards the injectable `$mailSender`'s bool return value entirely (line ~99) — the callable's own documented signature (`callable(string, string, string): bool`) implies a meaningful return, but nothing checks it. Verified: the default PHPMailer sender always either throws or returns `true` (safe today), but a future/custom sender following the documented "return false on failure" convention would have its failure silently swallowed. Fix: `if (!($this->mailSender)(...)) { throw new \RuntimeException(...); }`.
- **[low, patch]** (blind-hunter + verification-gap, duplicate finding) `TopTenDigest::defaultMailSender()`'s try/catch wraps only `$mail->send()`; the preceding `$mail->setFrom()`/`$mail->addAddress()` calls can themselves throw `PHPMailer\Exception` and would propagate unwrapped (as a raw `PHPMailerException`, not the `RuntimeException` the method's own doc comment implies). Verified against the code: confirmed. No live impact today — `index.php`'s outer `catch (\Throwable $e)` around the whole call catches either type identically — but it's a real discrepancy between the code and its own documentation. Fix: widen the try block to cover both calls.
- **[low, patch]** (edge-case-hunter) The `STOCKPICKER_DIGEST_SPY_FILE` test-seam closure in `public_html/index.php` unconditionally returns `true` regardless of whether `file_put_contents()` succeeded, so a write failure (disk full, unwritable path) in test infrastructure would be masked as a successful send. Test-only code, no production impact. Fix: `return file_put_contents(...) !== false;`.
- **[low, patch]** (verification-gap, pre-verified) `Config::digest()`'s new per-key validation loop (username/password/recipient non-empty-string checks) has no dedicated test, unlike every other `Config` accessor (`db()`, `cronToken()`, `sessionKey()`, `loginUsername()` all have a `testConfigXThrowsWhenYIsNonString`-shaped case in `tests/SmokeTest.php`). A regression in the loop (wrong key checked, loosened condition) would ship undetected — no test in `TopTenDigestTest`/`FrontControllerIntegrationTest` supplies a partially-invalid `digest` array. Fix: add the same shaped tests to `tests/SmokeTest.php` for `digest()`.
- **[medium, patch]** (blind-hunter) No task anywhere (Code Map, Tasks, Verification) instructs adding the new `digest` config section to production `config.php` on the Loopia server — per `docs/deploy.md`'s own established discipline (every secret is set by hand on the server, documented there). Verified: `docs/deploy.md` has no mention of `digest`. Without it, the feature ships fully tested but throws "missing digest section" (caught, logged, swallowed) forever in production until someone notices and adds it by hand with no doc pointing them to do so. Fix: add a short note to `docs/deploy.md`'s secrets section.
- **[low, patch]** (blind-hunter) `_bmad-output/implementation-artifacts/epic-5-context.md`'s "Technical Decisions" section still says SMTP credentials "must come from environment variables" and that "the env-var read itself is new and should be added as another typed accessor" — verified stale: this directly contradicts the actual implementation and the spec's own Design Notes (`Config::digest()` reads `config.php`, not `getenv()`). Fix: correct that paragraph to match the implemented approach.
- **[low, patch]** (blind-hunter) No test exercises a tie (`number_of_owners`/`up_streak` equal on the same date) for the new `topByOwnerCountAsOf()`/`topByTrendQualityAsOf()` methods, so their `ORDER BY ..., m.isin ASC` tie-break is unverified for the pinned-date path. Verified: confirmed absent from the diff's test additions. Fix: add one tie test per method, mirroring the existing methods' coverage.
- **[low, rejected]** (blind-hunter + edge-case-hunter, duplicate finding) No idempotency guard prevents a duplicate `/cron/derive` invocation on the same trading day from re-sending the same day's digest. Real, but the natural fix (a persisted "already sent today" marker) would need new state, conflicting with this spec's own frozen "no new database table" boundary — and Loopia's registered URL-cron fires once per configured interval per `docs/deploy.md`, making a same-day double-fire uncommon. Worst case is a duplicate email, not data corruption. Rejected: unlikely in everyday use, and a proper fix requires more than a direct correction.
- **[low, rejected]** (blind-hunter) `topByOwnerCountAsOf()`/`topByTrendQualityAsOf()` duplicate most of `topByOwnerCount()`/`topByTrendQuality()`'s SQL (column list, JOIN, filters) with nothing factored out. Real, but consistent with this codebase's existing tolerance for duplicated query shapes over premature abstraction (the two pre-existing methods already duplicate each other identically). Rejected: fix would mean refactoring proven, working queries into a shared builder — more than a direct correction, for a cosmetic concern.
- **[low, rejected]** (blind-hunter) `Config::digest()` validates username/password/recipient as non-empty strings only, not as email-address-shaped. A typo'd value fails late (a caught, logged PHPMailer exception) rather than at config-load time. Rejected: unlikely in everyday use (one-time manual config), and address-format validation is more than a direct correction for this low a risk.
- **[low, rejected]** (blind-hunter) `TopTenDigest::run()` reads `Config::digest()` twice on the send path (once for `recipient`, again inside `defaultMailSender()` for credentials). Verified true but no named harm beyond redundancy — a cheap array read, not a correctness or performance issue. Rejected: no real defect to fix.
- **[low, rejected]** (blind-hunter) `resolvePreviousTradingDay()` reimplements its own Sat/Sun check independently of `cron_gate()`'s weekday gate (both do share the same `TradingHolidayRepository` for holidays). Real, but already a deliberate, documented choice in this spec's own Design Notes (the shared helper isn't reachable from `src/Pipeline/`), and the weekend definition itself never changes. Rejected: low risk of divergence, fix would mean a larger extraction more than this finding warrants.
- **[low, rejected]** (blind-hunter) `diffRanking()`'s cap-then-render order (IN/moves before UT) means exits are the first to be dropped into "+N till" whenever combined changes exceed 5. Real behavior, but the spec's AC only requires "first 5 individually, rest as +N till" without specifying priority among change types — the code satisfies the letter of the AC. Rejected: an unlikely scenario (>5 combined top-10 changes in one day) with no stated priority to violate, and re-ordering by "importance" is a design question, not a defect.
- **[low, rejected]** (blind-hunter) The plain-text digest body has no line-wrapping for long lines. Real but genuinely cosmetic — the confirmed-working webmail (and any modern mail client) soft-wraps plain text for reading. Rejected: no concrete harm named, fix is speculative polish.

## Design Notes

Ranking basis is Avanza-only, matching Topplista's default/Alla-mode basis
(`LeaderboardController::render()`) — not user-configurable, since the digest is meant
to mirror what Stefan already sees by default.

The From address + recipient live in `config.php` (`Config::digest()`), not real
environment variables — every existing secret in this codebase (`cron_token`, login
credentials, DB password) reads from the untracked `config.php` array via a typed
accessor, never `getenv()`.

**Mail transport, superseded 2026-09-15:** originally PHPMailer over authenticated SMTP
to `mailcluster.loopia.se:587`, chosen for the same reason Guzzle was chosen over raw
cURL (a well-tested library for a fiddly protocol). Live verification on the production
server showed Loopia's shared-hosting firewall blocks outbound SMTP entirely (every
port, confirmed via raw TCP probes and an actual failed PHPMailer send) — an
infrastructure constraint no library choice could work around. Now: PHP's local
`mail()`/`sendmail`, the same mechanism `UniverseSync`/`FetchRunner`'s `alarm.email`
already proves works from this environment. No host/port/auth, no dependency; the
subject/body are explicitly MIME/UTF-8-encoded by hand since `mail()` doesn't do that on
its own (Swedish `ä`/`ö`/`å` throughout the digest text).

`TopTenDigest` takes an injectable `?callable $mailSender` (default wraps `mail()`),
mirroring `UniverseSync`'s/`FetchRunner`'s existing `?callable $sendMail` precedent —
keeps the diff/formatting logic testable without touching the real mail transport.

Previous-trading-day resolution walks backward from `$runDate` day by day (capped at 10
days as a sanity bound) skipping Sat/Sun and `TradingHolidayRepository::isHoliday()`
dates — self-contained in `TopTenDigest` rather than reusing `index.php`'s private
weekday helper, which isn't reachable from `src/Pipeline/`.

## Verification

**Commands:**
- `composer test` -- expected: all tests pass, including new `TopTenDigestTest` and the extended `DerivedMetricsRepositoryTest`/`FrontControllerIntegrationTest` cases
- `composer run analyse` -- expected: PHPStan level 8 clean, no new findings

**Manual checks (if no CLI):**
- Hit `/cron/derive?token=...` against a dev DB seeded with two trading days of differing top-10 data and confirm an email arrives in the `stockpicker@ryddmo.se` webmail inbox with the expected IN/UT/arrow content
