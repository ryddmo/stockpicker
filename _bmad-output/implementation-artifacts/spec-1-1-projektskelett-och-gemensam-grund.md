---
title: 'Story 1.1: Projektskelett och gemensam grund'
type: 'feature'
created: '2026-09-08'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: 'd87d5982812798dc49abbbfe7edff6af10338a32'
context:
  - '{project-root}/AGENTS.md'
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The repository has planning artifacts, a deploy runbook, and `bin/deploy.sh`, but no PHP project — no `composer.json`, no autoloading, no config loading, no logging, no front controller. Every Epic 1 story needs this foundation.

**Approach:** Scaffold a framework-less Composer project: PSR-4 `Stockpicker\` autoloading over `src/`, the architecture's directory layout, a `config.php` loader with secrets outside the web root, a Monolog logger writing outside `public_html/`, a thin front controller answering a healthcheck, Phinx wired (not yet run), and PHPUnit with a smoke test.

## Boundaries & Constraints

**Always:**
- `config.php` lives at the project root (above `public_html/`), is git-ignored, and is loaded through `Stockpicker\Config`; ship `config.php.dist` carrying `db` (host/name/user/pass/charset) and `cron_token` keys.
- Logs are written outside `public_html/` (default `var/log/stockpicker.log`) via Monolog.
- `composer.json` sets `require.php` to `>=8.3` (not pinned); Loopia's shell runs 8.5.
- Front controller is a thin hand-rolled path switch in `public_html/index.php`; `.htaccess` routes all non-file requests to it.
- Directory layout matches the architecture spine: `public_html/`, `src/{Adapter,Pipeline,Store,Error}/`, `bin/`, `db/migrations/`.
- Local development runs against a MariaDB 10.11 container defined in `docker-compose.yml`; Phinx's `development` environment targets it. (Decided 2026-09-08.)

**Never:**
- No framework; Guzzle, Monolog, Phinx only, plus PHPUnit as a dev dependency.
- No database schema, migrations, adapters, pipeline, or repository logic — those are Stories 1.2+.
- No SQLite; the project targets MariaDB only.
- Do not modify `bin/deploy.sh` or `docs/deploy.md` (Story 1.10 owns them).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|---|---|---|---|
| Healthcheck | `GET /` | 200, JSON `{"status":"ok","app":"stockpicker","time":"<ISO-8601 UTC>"}` | N/A |
| Unknown route | `GET /nope` | 404, JSON `{"error":"not found"}` | N/A |
| Config present | `config.php` exists, valid array | `Config::load()` returns accessors; `db()`, `cronToken()`, `logPath()` work | N/A |
| Config missing | no `config.php` | `Config::load()` throws `RuntimeException` naming the expected path | front controller catches, logs `error`, returns 500 JSON |
| Log write | `Logging::logger($c)->info('x')` | line appended to the configured log file | log directory auto-created if absent |

</frozen-after-approval>

## Code Map

- greenfield — no existing PHP source.
- `AGENTS.md` -- policy and conventions the skeleton must honor: secrets in `config.php` outside webroot, PR-only, `src/` layout, `snake_case` tables (later).
- `_bmad-output/planning-artifacts/architecture/architecture-stockpicker-2026-09-08/ARCHITECTURE-SPINE.md` -- Structural Seed (directory tree), Stack table (dependency versions), AD-8 (secrets in file / drift params in `settings` table).
- `_bmad-output/implementation-artifacts/epic-1-context.md` -- distilled epic constraints.
- `bin/deploy.sh`, `docs/deploy.md` -- already exist; the skeleton must deploy cleanly through them (rsync excludes `config.php`, `vendor/`; server runs `composer install`).

## Tasks & Acceptance

**Execution:**
- [x] `composer.json` -- PSR-4 `Stockpicker\`→`src/`, `Stockpicker\Tests\`→`tests/`; require `php >=8.3`, `guzzlehttp/guzzle ^7.9 || ^8.0`, `monolog/monolog ^3.11`, `robmorgan/phinx ^0.16.12`; require-dev `phpunit/phpunit ^11`; `test` script → `phpunit`
- [x] `.gitignore` -- `/vendor/`, `/config.php`, `/var/`, `.phpunit.result.cache`, `/stockpicker-loopia*`, `.DS_Store`, `.idea/`, `.vscode/`
- [x] `config.php.dist` -- returns an array: `db` (`host`, `name`, `user`, `pass`, `charset` = `utf8mb4`), `cron_token`, `log_path` (default `var/log/stockpicker.log`)
- [x] `src/Config.php` -- `Stockpicker\Config`: `load(?string $root = null)` reads `config.php` from the project root, throws `RuntimeException` naming the path if absent; typed getters `db()`, `cronToken()`, `logPath()`
- [x] `src/Logging.php` -- `Stockpicker\Logging::logger(Config $c): Monolog\Logger` -- one `StreamHandler` to `logPath()`, creating the directory if needed; channel `stockpicker`
- [x] `bootstrap.php` -- project-root bootstrap: `require vendor/autoload.php`, `date_default_timezone_set('UTC')`, load `Config`, build logger; return `['config' => ..., 'logger' => ...]`
- [x] `public_html/index.php` -- `require __DIR__.'/../bootstrap.php'`; switch on the request path: `/` → healthcheck JSON; otherwise 404 JSON; wrap in try/catch → log `error`, 500 JSON
- [x] `public_html/.htaccess` -- `RewriteEngine On`; pass through existing files; route everything else to `index.php`
- [x] `phinx.php` -- reads `Stockpicker\Config`; `production` environment from `db` creds (adapter `mysql`); `development` environment targets the `docker-compose.yml` MariaDB (`127.0.0.1:3306`); `migration_paths` → `db/migrations`
- [x] `docker-compose.yml` -- one `mariadb:10.11` service, port `3306:3306`, literal local-only env (`MYSQL_DATABASE=stockpicker`, a dev user/password), named volume for data
- [x] `phpunit.xml.dist` -- bootstrap `vendor/autoload.php`; testsuite over `tests/`
- [x] `tests/SmokeTest.php` -- autoload resolves `Stockpicker\Config`; `Config::load()` against a fixture directory returns expected values; a missing config throws; `Logging::logger()` writes a line to a temp path
- [x] `src/Adapter/.gitkeep`, `src/Pipeline/.gitkeep`, `src/Store/.gitkeep`, `src/Error/.gitkeep`, `db/migrations/.gitkeep` -- keep the required empty directories under version control
- [x] `README.md` -- replace the stub: what the project is, `composer install`, `docker compose up -d`, copy `config.php.dist` → `config.php` (dev values point at the compose DB), `composer test`, pointer to `docs/deploy.md`

**Acceptance Criteria:**
- Given a clean checkout, when `composer install` runs, then it completes with no errors and every required directory exists.
- Given the project is installed, when `config.php` is absent, then `config.php.dist` sits at the root with `db` + `cron_token` keys and `config.php` is git-ignored.
- Given the app is served, when `GET /` is requested, then the response is 200 with a JSON healthcheck body.
- Given code calls the logger, when a record is written, then it lands in a file under `var/log/` (outside `public_html/`).
- Given `composer test`, when it runs, then the PHPUnit smoke suite passes.

## Design Notes

- **Config as a returned array wrapped by `Config`** — keeps `config.php` a dumb data file (AD-8: secrets in file, drift params in the `settings` table later) while giving code a typed surface.
- **Timezone**: bootstrap sets `UTC` globally; `fetched_at` is UTC. `Europe/Stockholm` conversion for `as_of_date` is explicit and belongs to Story 1.6.
- **Phinx wired but not run**: `phinx.php` exists and `vendor/bin/phinx` resolves; the first migration is Story 1.2. `docker compose up -d` provides the local MariaDB the `development` environment connects to.
- Healthcheck is deliberately shallow (no DB ping) per "enkel healthcheck".

## Verification

**Commands:**
- `composer validate --strict` -- expected: valid manifest
- `composer install` -- expected: exit 0, `vendor/` created
- `composer test` -- expected: all tests pass
- `php -S 127.0.0.1:8080 -t public_html` + `curl -s -o /dev/null -w '%{http_code}' 127.0.0.1:8080/` -- expected: `200`; `curl .../nope` -- expected: `404`
- `docker compose up -d` then `vendor/bin/phinx status -e development` -- expected: connects to the dev DB, reports no migrations
- `vendor/bin/phinx list` -- expected: command resolves without error

## Implementation Notes

- Scaffolded per the task list. `Config` is a private-constructor value object with `load()` / `fromArray()`; `db()` / `cronToken()` validate their sections lazily and throw `RuntimeException` on malformed config.
- Front controller has two guard layers: a bootstrap try/catch (missing/invalid `config.php` → best-effort `error` log to the default `var/log/` path, then `error_log()` fallback, 500 JSON) and a request try/catch (`error` via the configured logger, 500 JSON).
- `phinx.php` tolerates an absent `config.php` so `phinx status -e development` works on a fresh checkout; the `development` env is hard-wired to the compose DB.
- Added `tests/FrontControllerTest.php` beyond the task list: runs `public_html/index.php` under the PHP built-in server (one process per test) to cover the three HTTP matrix rows — healthcheck 200, unknown route 404, and missing-config 500 + error-log. Each test picks a free port and synthesises `config.php` from `config.php.dist` when absent.
- Verified: `composer validate --strict` clean, `composer test` green (7 tests, 29 assertions), `php -l` on all PHP files, `vendor/bin/phinx list` resolves. Docker/Phinx `development` connectivity was verified during implementation and the container torn down.
- `.gitignore` also carries `/.phpunit.cache/` (PHPUnit 11's `cacheDirectory`).

**Review iteration 1 patches applied** (see Review Triage Log): guarded the logger call in `index.php`'s request catch; `phinx.php` now defines `production` only on successful `Config::load()` and fails loudly otherwise; `FrontControllerTest` rebuilt against an isolated temp project root (no longer touches the real `config.php` / `var/log/`); added malformed-config + ERROR-level test coverage; `docker-compose.yml` binds `127.0.0.1` and gained a healthcheck; `.htaccess` wrapped in `<IfModule mod_rewrite.c>` with `Options -MultiViews -Indexes`; `Config::DEFAULT_LOG_PATH` constant shared with the bootstrap-failure fallback. Post-patch: `composer validate --strict` clean, `composer test` green (11 tests, 25 assertions), `docker compose config` valid, lint clean.

## Spec Change Log

## Review Triage Log

### Iteration 1 (2026-09-08)

Layers: blind-hunter (15), edge-case-hunter (7), verification-gap (1 gap + 4 other).

**Routed to patch:**

| # | Finding | Verdict | Evidence |
|---|---|---|---|
| P1 | `public_html/index.php` request `catch` calls `$logger->error()` unguarded — a log-write failure (disk full on the long-running shared host, unwritable path) throws out of the catch and no 500 JSON is sent | medium | Confirmed at the catch block: `$logger->error(...)` precedes `send_json(500,...)` with nothing between them; Monolog `StreamHandler` throws `UnexpectedValueException` on an unwritable stream. Disk-full is a plausible steady state given the unbounded log (see D2). |
| P2 | `phinx.php` pre-seeds `production` with hard-coded local creds (`127.0.0.1`/`stockpicker`) and only overwrites on `Config::load()` success → `phinx -e production` with a missing/broken `config.php` silently targets a fabricated local DB instead of failing; STDERR notice also fires on the supported `phinx status -e development` fresh-checkout path | medium | Confirmed in `phinx.php`: `$production` array literal + `try/catch` that only replaces it on success. `docs/deploy.md` documents `phinx status -e production` as a real operation on the Loopia host (`db.host` = `mysql684.loopia.se`), so a config drift there masks itself. Also raised by edge-case-hunter (EC3) and blind-hunter (BH14). |
| P3 | `tests/FrontControllerTest.php` mutates the real working tree — `copy()` `config.php.dist`→`config.php`, `@unlink` real `var/log/stockpicker.log`, `rename()` real `config.php`→`config.php.testbak`; a crash mid-test leaves the developer with no `config.php` and a deleted log; cannot run in parallel | medium | Confirmed in the new test file. `SmokeTest` already demonstrates the correct pattern (isolated fixture root). Raised by all three layers (BH4, EC7, VG-other). |
| P4 | Malformed-config throw paths advertised in Implementation Notes (`Config::db()`/`cronToken()` on missing `db` section, non-string cred, empty `cron_token`) and the `charset` default are untested; missing-config front-controller test asserts only the substring `bootstrap failed`, not the log level | low | Confirmed: `SmokeTest` exercises only the happy path + missing file; `FrontControllerTest::testMissingConfigReturns500AndLogsError` would still pass if the level were downgraded to `warning`. Cheap to close while the test files are already open. (BH12, VG-other) |
| P5 | `docker-compose.yml` publishes `"3306:3306"` on all interfaces despite the "local-only" comment, and has no healthcheck though the spec Verification runs `docker compose up -d` immediately followed by `phinx status -e development` | low | Confirmed in the file. Weak-cred MariaDB exposed to the LAN; documented cold-start-then-connect flow can race. Fix is a one-line bind change + a standard healthcheck stanza. (BH9) |
| P6 | `public_html/.htaccess` is not wrapped in `<IfModule mod_rewrite.c>` (a host without mod_rewrite 500s the whole site on `RewriteEngine On`) and does not disable `MultiViews` (content negotiation can pre-empt the front-controller rewrite) | low | Confirmed in the file. `MultiViews` pre-empting a front controller is a known production gotcha; the guards are defensive and change no happy-path behavior. This is the "common foundation" story. (BH10) |
| P7 | Default log path `var/log/stockpicker.log` is hard-coded in three places (`Config::logPath()` default, `config.php.dist`, `index.php` `log_bootstrap_failure()`); the bootstrap-failure fallback silently diverges if the default changes | low | Confirmed. Introduce `Config::DEFAULT_LOG_PATH` used by `logPath()` and `log_bootstrap_failure()`. (BH8, log-path half) |

**Routed to defer** (appended to `deferred-work.md`):

| Finding | Verdict | Evidence |
|---|---|---|
| VG1 — `.htaccess` production routing is exercised only via `php -S`'s built-in fallback, never real Apache; a rewrite regression ships with the suite green | medium (unverified — Apache not available in the PHPUnit env) | verification-gap traced both routing tests to `proc_open([PHP_BINARY,'-S',...])` with no router script and confirmed `php -S` ignores `.htaccess`. Filed disposition: defer. Home: Story 1.10 (`docs/deploy.md`) — add a post-deploy curl check for `/` (200 JSON) and `/nope` (404 JSON). |
| BH5 — no log rotation; single `StreamHandler` at `Level::Debug` appends to one unbounded file forever on shared hosting | low now, grows over time | Confirmed in `Logging.php`. Personal, low-volume v1; logging is explicitly revisited in Story 1.8 (minimal körningslogg) and Story 2.6 (full körningslogg). |
| BH7 — `Config::cronToken()` rejects only an empty string, not the shipped placeholder `'change-me'` | low now (no `/cron/*` endpoints yet) | Confirmed. Home: Story 1.9 (cron endpoints + token check) should reject the known default. |
| BH13 — `composer.json` sets no `config.platform.php`; a future `composer update` on an 8.5 machine could lock deps needing >8.3 | low (all currently-locked deps are 8.1/8.2-compatible) | Confirmed. Frozen boundary comments on `require.php` being "not pinned", so this touches dependency-resolution policy — worth a deliberate decision rather than a drive-by patch. |
| BH15 — no static analysis (PHPStan) or CI to enforce the type-safety bar the code is written to | n/a (process gap) | Confirmed. Reasonable as its own tooling story. |

**Rejected:**

| Finding | Verdict | Refutation |
|---|---|---|
| EC1 (dup of P1) | — | Merged into P1. |
| EC2 — `Logging::logger()` doesn't check the log dir is writable; first write throws from an unexpected call site | low | Monolog throws `UnexpectedValueException` naming the path on first write — fail-loud behaviour for a misconfigured environment. Fix adds a guard for a situation not shown reachable in this story. |
| EC4 — non-GET methods to `/` are answered identically to GET; no 405 | low | The I/O matrix specifies only `GET /`. A healthcheck answering any method 200 is harmless and conventional; fix adds a branch for no demonstrated harm. |
| EC5 — empty-string `db.host/name/user/pass` pass `Config::db()` validation | low | No consumer calls `db()` in this story except `phinx.php`, which swallows all `\Throwable`. Credential hardening belongs with the Story 1.2+ Store repositories that first connect. |
| BH3 — DB `port`/`unix_socket` not expressible in the config schema; Loopia may use a non-standard port | false | `db.host` already carries the remote Loopia host (`mysql684.loopia.se` per `docs/deploy.md`); Loopia MariaDB is reached on the standard 3306 over TCP, no socket. The frozen boundary fixes the `db` keys as host/name/user/pass/charset. |
| BH6 — README references `bin/`, `docs/deploy.md`, `src/Error/` classes that "don't exist" | false | `bin/deploy.sh` and `docs/deploy.md` exist in the repo (pre-dating this story). `src/Error/` classes are listed under a clearly-labelled "planned layout" overview. |
| BH11 — spec frontmatter `in-review` vs `sprint-status.yaml` `in-progress` | false | Expected mid-workflow state; both are managed by the Build workflow and converge at completion. Fix would edit this build's tracking artifacts. |
| VG-other — `date_default_timezone_set('UTC')` not pinned by a test | low | No consumer observes the default in this story (Story 1.6 owns tz conversion); `gmdate()` in the healthcheck is UTC regardless. Not a gap today. |

