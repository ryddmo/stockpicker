---
title: "Story 1.10: Loopia deploy tooling and runbook"
type: "feature"
created: "2026-09-09"
status: "done"
route: "dispatch"
review_loop_iteration: 0
baseline_commit: "3eb6283def145ae6fadde66eeedef0cb835b0811"
context:
  - "{project-root}/AGENTS.md"
  - "{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md"
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The cron endpoints are done, but the Loopia deploy path is still the Story 1.1
draft. `bin/deploy.sh` and `docs/deploy.md` were written before any app code and never
reconciled with the real repo (thin front controller, five migrations, `phinx.php`
`production` env). No preflight safety, no test guarding the rsync exclude list, no
reviewed repeatable way to push a version and migrate.

**Approach:** Harden `bin/deploy.sh` (rsync-over-SSH with a preflight and an explicit
migrate step), finish `docs/deploy.md` as the operator runbook, align `config.php.dist` and
the `phinx.php` `production` env with that flow, and add a guard test for the script
contract.

**Decisions (2026-09-09):**

- **Scope split.** This spec ships the deploy *tooling + runbook* as one reviewable PR. The
  first production deploy — probing the real web-PHP `memory_limit` / `max_execution_time`
  and the URL-cron limits, filling those blanks in the runbook, registering the Kundzon
  cron jobs, and any `settings` tuning against the measured limit — is split to
  `deferred-work.md` and will be walked through in-session immediately after this lands.
  So the runbook is finished *structurally*, with the first-deploy measurements left as
  labelled blanks in its checklist.
- **`/cron/derive`.** The runbook registers `/cron/refill` + `/cron/work` only and flags
  `/cron/derive` as "add when Story 3.2 ships". No application-code change in this story.
- **Runbook domain.** Fill the `<domain>` placeholders in `docs/deploy.md` with the real
  values: `ryddmo.se` and the `stockpicker.ryddmo.se` subdomain.

## Boundaries & Constraints

**Always:**

- rsync uses `-az --delete` and excludes at least `config.php`, `.git/`, `vendor/` (unless
  `--with-local-vendor`), `_bmad-output/`, `_bmad/`, `tests/`, `docs/`. A routine deploy
  never touches `config.php` or `vendor/` on the server.
- Dependencies build on the server via `composer install --no-dev --optimize-autoloader`; a
  local `vendor/` synced with `--with-local-vendor` is the documented fallback.
- Migrations run only as an explicit `vendor/bin/phinx migrate -e production` step over SSH.
- `bin/deploy.sh` keeps `set -euo pipefail`, stays bash-3.2 compatible, keeps its env-var
  config, and runs a preflight (SSH reachable, working-tree state reported, commit echoed)
  before any `--delete` sync.
- `docs/deploy.md` is the single source of truth for Loopia setup and lists the first-deploy
  items to confirm as labelled blanks: SSH enabled, subdomain docroot to
  `~/stockpicker/public_html/`, web-PHP version + its `memory_limit` / `max_execution_time`,
  URL-cron execution-time limit and minimum interval.

**Never:**

- Do not perform the first production deploy or mutate the Loopia server/database in this
  story (it is the split-off deferred item).
- Do not change app code, migrations, `settings` defaults, or the front-controller routes
  (`/cron/derive` stays 404 until Story 3.2).
- Do not add CI/CD or an automated deploy trigger.
- Do not commit `config.php`, keys, or real credentials (AD-8), or weaken `--delete` safety
  by dropping excludes.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Behavior | Error Handling |
|---|---|---|---|
| Routine deploy | `bin/deploy.sh`, SSH reachable | preflight passes; rsync `--delete`; server `composer install`; `phinx migrate -e production` | non-zero exit stops before next step |
| Local-vendor fallback | `--with-local-vendor` | `vendor/` built locally and synced; no server composer step | non-zero exit if local build fails |
| Skip migrations | `--no-migrate` | deploy without the phinx step | — |
| Unknown flag | `--bogus` | usage error, exit 2, nothing runs | exit 2 |
| SSH unreachable | preflight `ssh` probe fails | abort before rsync; no partial deploy | non-zero exit, clear message |

</frozen-after-approval>

## Code Map

- `bin/deploy.sh` -- Story 1.1 draft to harden. Keep flags (`--with-local-vendor`,
  `--no-migrate`), env config (`STOCKPICKER_SSH_HOST`, `STOCKPICKER_REMOTE_DIR`), exclude
  approach, unknown-flag exit 2; add the preflight + echo the deployed commit.
- `docs/deploy.md` -- Story 1.1 draft runbook (prerequisites, one-time setup, routine
  deploy, migrations, verifying, rollback, troubleshooting, open items). Reconcile paths /
  routes / migration count with the repo; drop `/cron/derive` to a "later" note; fill
  `ryddmo.se`; keep the first-deploy blanks.
- `config.php.dist` -- committed template; add a comment pointing at `docs/deploy.md`.
  `src/Config.php` `db()` / `cronToken()` is the contract it must match. Do not rename.
- `phinx.php` -- `production` from `Config::db()`, port hardcoded 3306. Verify vs Loopia
  (`mysql684.loopia.se:3306`); annotate only unless a real gap.
- `db/migrations/` -- five migrations; the runbook's migration description must match.
- `tests/Store/ShowRunsScriptTest.php`, `tests/MigrationTest.php` -- pattern for driving a
  script as a subprocess and self-skipping without a DB; model `DeployScriptTest` on these.
- `public_html/index.php` -- `/cron/derive` is 404 by Story 1.9's frozen decision; not
  touched. `AGENTS.md` -- stale "no application code yet" / deploy lines.

## Tasks & Acceptance

**Execution:**

- [x] `bin/deploy.sh` -- add SSH-reachability + working-tree preflight, echo the deployed
      commit; keep `-az --delete` with the required excludes and existing flags.
- [x] `docs/deploy.md` -- reconcile with the repo: exclude list matches the script, five
      migrations described, cron-job table = refill + work only with a `/cron/derive`
      "add in Story 3.2" note, `ryddmo.se` / `stockpicker.ryddmo.se` filled in, first-deploy
      checklist kept with labelled blanks.
- [x] `config.php.dist` -- add a comment pointing at `docs/deploy.md` for the Loopia
      values; keep local-dev defaults.
- [x] `phinx.php` -- verify/annotate the `production` env against Loopia; no behavior
      change unless a gap is found.
- [x] `tests/DeployScriptTest.php` -- `bash -n bin/deploy.sh` passes; `--bogus` exits 2 and
      runs no rsync/ssh; assert the rsync excludes contain each required entry; `shellcheck`
      run self-skips when the binary is absent.
- [x] `AGENTS.md` -- update the stale deploy-status lines.
- [x] `_bmad-output/implementation-artifacts/sprint-status.yaml` -- move Story 1.10 to
      `in-progress` when work starts.

**Acceptance Criteria:**

- Given a clean checkout, when `composer test` runs, then `DeployScriptTest` passes and
  asserts every required rsync exclude is present in `bin/deploy.sh`.
- Given `bin/deploy.sh --bogus`, when it runs, then it prints a usage error, exits 2, and
  performs no rsync or SSH.
- Given the finished `docs/deploy.md`, when an operator follows it, then it covers one-time
  Kundzon setup, routine deploy, manual migration, deploy verification, rollback, and the
  first-deploy Loopia prerequisites (as labelled blanks), referencing no file or route
  absent from the repo, and registering only `/cron/refill` + `/cron/work`.
- Given `bin/deploy.sh`, when read, then the only migration step is
  `vendor/bin/phinx migrate -e production` over SSH, with no implicit or cron-triggered path.
- Given a routine deploy, when it runs, then `config.php` and `vendor/` on the server are
  never deleted or overwritten.

## Implementation Notes

- **2026-09-09** — Implemented the deploy tooling + runbook (tooling PR only; the first
  live deploy stays the `deferred-work.md` follow-up).
  - `bin/deploy.sh`: added a preflight (echoes short commit + branch, reports
    clean/DIRTY working tree, then an `ssh -o BatchMode=yes -o ConnectTimeout=10`
    reachability probe that aborts with exit 1 before any `rsync --delete`). Added a
    `usage()` helper; unknown flag now prints usage and exits 2 (`-h/--help` exits 0).
    Kept `set -euo pipefail`, env config, `-az --delete`, and the conditional `vendor/`
    exclude. Exclude list now: `.git/ .github/ _bmad-output/ _bmad/ docs/ tests/
    config.php .env *.pub stockpicker-loopia .DS_Store` (+ `vendor/` unless
    `--with-local-vendor`).
    Two `# shellcheck disable=SC2029` directives on the intentional client-side
    `${REMOTE_DIR}` expansion so plain `shellcheck bin/deploy.sh` is clean. Verified
    bash-3.2 syntax with macOS `/bin/bash`.
  - `docs/deploy.md`: filled `ryddmo.se` / `stockpicker.ryddmo.se` throughout; cron table
    reduced to `/cron/refill` + `/cron/work` with a "`/cron/derive` stays 404 until Story
    3.2" note; exclude list + manual-rsync fallback aligned to the script; five migrations
    described in a table; preflight documented; added the `.htaccess`/front-controller
    curl checks (200 `/`, 404 `/nope`) — closes the Story 1.1 deferred routing-verification
    item; first-deploy blanks kept in "Still to confirm" + "Open items".
  - `config.php.dist`: docblock note pointing at `docs/deploy.md` step C.2; defaults
    unchanged.
  - `phinx.php`: docblock annotation only — port 3306 is Loopia's MariaDB default
    (`mysql684.loopia.se`), `Config::db()` carries no port key; no behavior change.
  - **Review fixes (2026-09-09):** routine-deploy test now asserts the *recorded* rsync
    argv carries each safety-critical `--exclude` (`config.php`, `vendor/`, `.git/`,
    `.env`, `*.pub`, `stockpicker-loopia`, `.DS_Store`), not just `--delete`; added an
    executing `--help` case (exit 0, usage, empty call log); `.DS_Store` added to the
    exclude array and the runbook's manual-rsync block (macOS Finder metadata); runbook
    Rollback step 1 now uses `bin/deploy.sh --no-migrate` so re-deploying an older commit
    never migrates.
  - `tests/DeployScriptTest.php`: 13 tests (subprocess pattern from `MigrationTest`).
    Static contract checks: `bash -n`, `set -euo pipefail` present, every required
    exclude present, `vendor/` exclude is conditional, single explicit phinx-over-SSH
    migrate step + no `phinx rollback`, ssh probe precedes rsync in source, `shellcheck`
    clean or self-skips. Every I/O & Edge-Case Matrix row is also driven end-to-end with
    `rsync`/`ssh`/`git`/`composer`/`php` replaced by stubs that log argv to
    `$STOCKPICKER_STUB_LOG` (the `ssh` stub exits non-zero for the unreachable case):
    * Unknown flag → exit 2, empty call log. `--help` → exit 0, usage, empty call log.
    * Routine deploy → recorded order is ssh-probe < rsync (`--delete`, and its argv
      carries every safety-critical `--exclude`) < server-side `composer install` over
      ssh < exactly one `phinx migrate -e production` over ssh; exit 0.
    * `--no-migrate` → rsync runs, zero `phinx migrate` calls; exit 0.
    * `--with-local-vendor` → local `composer install` (not over ssh), rsync argv has no
      `vendor/`, no server-side composer over ssh; exit 0.
    * SSH unreachable → probe attempted, no `rsync` in the call log, non-zero exit.
  - `AGENTS.md`: refreshed the stale "no application code yet" / deploy lines.
  - `composer test` green (151 tests, 0 skipped with shellcheck installed);
    `shellcheck bin/deploy.sh` exit 0; `bash -n bin/deploy.sh` clean.

## Spec Change Log

## Review Triage Log

### Iteration 1 (2026-09-09)

**patch**

- `tests/DeployScriptTest.php` — `medium` — the routine-deploy test asserts only `--delete`
  in the recorded rsync argv, never the `--exclude` tokens; dropping `"${excludes[@]}"`
  from the `rsync` line at `bin/deploy.sh:97` leaves the whole suite green while a real
  deploy would `--delete` `config.php` / `vendor/` off the server. Verified: line 161 checks
  `assertStringContainsString('--delete', $log[$rsync])` and nothing else; the source-text
  test at line 58 does not execute the script. This story's core safety contract is
  unguarded. Fix: assert each safety-critical exclude appears in the executed rsync argv.
- `docs/deploy.md` (Rollback, step 1) — `low` — `git checkout <sha> && bin/deploy.sh` runs
  `phinx migrate -e production` by default, so the documented rollback touches the schema
  with an older codebase. Verified against `bin/deploy.sh:107` (`run_migrate=1` default).
  Fix: `bin/deploy.sh --no-migrate` in that step.
- `tests/DeployScriptTest.php` — `low` — the `-h|--help) usage; exit 0` branch added at
  `bin/deploy.sh:43` has no test (only `--bogus`/exit 2 is covered). Verified: no
  `--help` case in the file. Fix: add a case asserting exit 0 and an empty call log.
- `bin/deploy.sh` + `docs/deploy.md` (manual-rsync block) — `low` — the script is
  explicitly macOS-scoped ("bash-3.2 compatible (macOS /bin/bash)") but the exclude list
  omits `.DS_Store`, so Finder metadata is rsynced to the server docroot (minor filename
  disclosure). Verified against the `excludes=(…)` array at `bin/deploy.sh:77-88`. Fix:
  add `--exclude '.DS_Store'` in both places.

**defer**

- Runbook has no TLS / Let's Encrypt enablement step in the Kundzon one-time setup, though
  every cron and verify URL is `https://`. Real gap; tied to the split-off first deploy.
- No "`mysqldump` before `phinx migrate`" note (MariaDB DDL is non-transactional, a failed
  migration half-applies). Real; runbook Rollback + first-deploy checklist gap.
- SSH preflight probe: `ConnectTimeout=10` bounds only the TCP connect — a post-connect
  banner/auth stall can hang the deploy unbounded. Real robustness gap, no demonstrated
  failure; `timeout`/`gtimeout` is not standard on macOS so the fix needs thought.
- `commit="$(git rev-parse --short HEAD)"` in the preflight aborts under `set -e` with an
  unborn HEAD (repo with zero commits). Unreachable in a real deploy; cheap guard exists.
- "Verifying a deploy" could add `curl .../config.php` → expect 404 as a defence-in-depth
  check. `config.php` is structurally above the docroot so nothing routes to it; low value.

**rejected**

- `.htaccess` "undocumented / never created" — false: `public_html/.htaccess` is tracked,
  is not in the exclude list (so it is rsynced), and the runbook tree diagram names it.
- AGENTS.md dependency-line edits "out of scope / possibly wrong" — false: `composer.json`
  is exactly `guzzlehttp/guzzle ^7.9 || ^8.0` and `phpunit/phpunit ^11`; the doc now matches
  reality, and "stale lines" is the task.
- `--with-local-vendor` strips local dev deps — pre-existing (Story 1.1 draft), low, an
  advanced-operator fallback path.
- 400-on-extra-query-param vs unconfirmed Loopia behavior — Story 1.9 endpoint behavior,
  not changed here; "URL-cron follows the query string" is already an Open-items checkbox.
- `/cron/refill` 00:00 vs `run_after` 18:30 — works as designed; `run_after` gating the
  work window is the documented mechanism, no named harm.
- Exclude list "three sources of truth" — the frozen boundary says "excludes **at least**";
  extra script excludes are not a contradiction. Test-coverage half folded into patch #1.
- `sprint-status.yaml` still `in-progress` — expected mid-workflow; advanced at presentation.
- `_probe.php` info leak — optional, operator-run, `rm` in the same snippet, non-sensitive
  ini values.

## Design Notes

- The epic ACs are largely operational (real SSH, Kundzon, probing web-PHP limits) and are
  the split-off deferred item. The testable core here is the script contract (arg handling,
  exclude list, migrate command) and the runbook's consistency with the repo.
- Keep `--delete` — the server tree mirrors the repo so removed files do not linger; safety
  is the exclude list, not dropping `--delete`.

## Verification

**Commands:**

- `composer test` -- expected: all tests pass; DB-backed tests may self-skip without MariaDB.
- `bash -n bin/deploy.sh` -- expected: no syntax errors.
- `php -l phinx.php` -- expected: no syntax errors.
- `shellcheck bin/deploy.sh` -- expected: clean when installed (test self-skips otherwise).

**Manual checks:**

- Read `docs/deploy.md` end to end: every path, route, and the migration count match the
  repo; the cron-job table lists only refill + work; `ryddmo.se` is filled in.
