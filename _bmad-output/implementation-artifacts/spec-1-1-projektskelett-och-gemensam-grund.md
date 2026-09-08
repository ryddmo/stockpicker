---
title: 'Story 1.1: Projektskelett och gemensam grund'
type: 'feature'
created: '2026-09-08'
status: 'ready-for-dev'
route: 'dispatch'
review_loop_iteration: 0
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
- [ ] `composer.json` -- PSR-4 `Stockpicker\`→`src/`, `Stockpicker\Tests\`→`tests/`; require `php >=8.3`, `guzzlehttp/guzzle ^7.9 || ^8.0`, `monolog/monolog ^3.11`, `robmorgan/phinx ^0.16.12`; require-dev `phpunit/phpunit ^11`; `test` script → `phpunit`
- [ ] `.gitignore` -- `/vendor/`, `/config.php`, `/var/`, `.phpunit.result.cache`, `/stockpicker-loopia*`, `.DS_Store`, `.idea/`, `.vscode/`
- [ ] `config.php.dist` -- returns an array: `db` (`host`, `name`, `user`, `pass`, `charset` = `utf8mb4`), `cron_token`, `log_path` (default `var/log/stockpicker.log`)
- [ ] `src/Config.php` -- `Stockpicker\Config`: `load(?string $root = null)` reads `config.php` from the project root, throws `RuntimeException` naming the path if absent; typed getters `db()`, `cronToken()`, `logPath()`
- [ ] `src/Logging.php` -- `Stockpicker\Logging::logger(Config $c): Monolog\Logger` -- one `StreamHandler` to `logPath()`, creating the directory if needed; channel `stockpicker`
- [ ] `bootstrap.php` -- project-root bootstrap: `require vendor/autoload.php`, `date_default_timezone_set('UTC')`, load `Config`, build logger; return `['config' => ..., 'logger' => ...]`
- [ ] `public_html/index.php` -- `require __DIR__.'/../bootstrap.php'`; switch on the request path: `/` → healthcheck JSON; otherwise 404 JSON; wrap in try/catch → log `error`, 500 JSON
- [ ] `public_html/.htaccess` -- `RewriteEngine On`; pass through existing files; route everything else to `index.php`
- [ ] `phinx.php` -- reads `Stockpicker\Config`; `production` environment from `db` creds (adapter `mysql`); `development` environment targets the `docker-compose.yml` MariaDB (`127.0.0.1:3306`); `migration_paths` → `db/migrations`
- [ ] `docker-compose.yml` -- one `mariadb:10.11` service, port `3306:3306`, literal local-only env (`MYSQL_DATABASE=stockpicker`, a dev user/password), named volume for data
- [ ] `phpunit.xml.dist` -- bootstrap `vendor/autoload.php`; testsuite over `tests/`
- [ ] `tests/SmokeTest.php` -- autoload resolves `Stockpicker\Config`; `Config::load()` against a fixture directory returns expected values; a missing config throws; `Logging::logger()` writes a line to a temp path
- [ ] `src/Adapter/.gitkeep`, `src/Pipeline/.gitkeep`, `src/Store/.gitkeep`, `src/Error/.gitkeep`, `db/migrations/.gitkeep` -- keep the required empty directories under version control
- [ ] `README.md` -- replace the stub: what the project is, `composer install`, `docker compose up -d`, copy `config.php.dist` → `config.php` (dev values point at the compose DB), `composer test`, pointer to `docs/deploy.md`

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

## Spec Change Log

## Review Triage Log
