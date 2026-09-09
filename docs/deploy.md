# Deploying stockpicker to Loopia

Runbook for Story 1.10. Stockpicker runs unattended on **Loopia shared hosting**
(Privatpaket): PHP 8.3+ (Loopia's shell is 8.5), MariaDB 10.11, and Loopia's **URL-cron**
(HTTP GET) as the only scheduler. Deploys are **manual over SSH** via `bin/deploy.sh` —
there is no CI deploy.

Domain: **`ryddmo.se`**, app subdomain **`stockpicker.ryddmo.se`**.

```
local checkout ──rsync -az --delete over SSH──▶ ~/stockpicker/ on Loopia
                                                   │
                                                   ├─ composer install --no-dev   (on the server)
                                                   ├─ vendor/bin/phinx migrate     (manual, over SSH)
                                                   └─ public_html/  ◀── stockpicker.ryddmo.se docroot
                                               config.php lives in ~/stockpicker/, above the
                                               docroot, never in git
```

---

## Prerequisites

**Verified on the Loopia shell 2026-09-08:**

| Tool | Status |
|---|---|
| SSH / SFTP | works (ED25519 key) |
| `rsync` | 3.4.4, `/usr/local/bin/rsync` |
| `composer` + `composer.phar` | present, `/usr/local/bin/` |
| `php` | 8.5.9 CLI, `/usr/local/bin/php`, `memory_limit` 1024M |
| PHP extensions | `pdo_mysql`, `curl`, `mbstring`, `json` loaded |
| Home layout | one folder per domain (e.g. `ryddmo.se/`), **no shared `public_html/`** |
| MariaDB | database + user created in Kundzon (`ryddmo_se` on `mysql684.loopia.se`, MariaDB 10.11.19) |

**Still to confirm on the first deploy** (these are the split-off deferred item for
Story 1.10 — fill the blanks in "Open items" at the bottom as you measure them):

- [ ] Web PHP version for `stockpicker.ryddmo.se`, and its `memory_limit` /
      `max_execution_time` — **URL-cron runs in the web context, not the CLI**, so the
      1024M CLI limit does not apply. Target: `memory_limit` >= 256M (NFR8). If the real
      limit is lower, drop `batch_size` (and the `/cron/work` time-box) in the `settings`
      table to fit.
- [ ] URL-cron **maximum execution time** per call and **minimum interval** (we want
      `/cron/work` roughly every 5 min; its slice is time-boxed to 75 s in
      `public_html/index.php`).
- [ ] `stockpicker.ryddmo.se` document root can be pointed at `~/stockpicker/public_html/`.
- [x] MariaDB database + user created in Kundzon (`ryddmo_se` on `mysql684.loopia.se`).

---

## One-time setup

### A. Local machine

1. Generate a deploy key (ED25519 — on Loopia's supported list):

   ```sh
   ssh-keygen -t ed25519 -C "stockpicker-loopia" -f ~/.ssh/stockpicker-loopia
   chmod 600 ~/.ssh/stockpicker-loopia
   ```

   The private key stays in `~/.ssh/`. **Never commit it, never put it in GitHub source.**

2. Add a host alias in `~/.ssh/config` (the alias `bin/deploy.sh` expects by default):

   ```
   Host loopia-stockpicker
       HostName <loopia-ssh-host>       # e.g. sNN.loopia.se — from Kundzon
       User <loopia-ssh-user>
       IdentityFile ~/.ssh/stockpicker-loopia
       IdentitiesOnly yes
   ```

   To use a different alias or remote directory, set `STOCKPICKER_SSH_HOST` /
   `STOCKPICKER_REMOTE_DIR` in the environment when calling the script.

3. Test: `ssh loopia-stockpicker` should land you on the Loopia shell.

### B. Loopia Kundzon

1. **SSH/SFTP** — activate it, upload the contents of `~/.ssh/stockpicker-loopia.pub`.
2. **Subdomain** — create `stockpicker.ryddmo.se` and set its **document root** to
   `~/stockpicker/public_html/`.
3. **PHP version** — set the subdomain to PHP **8.3 or newer**. Note the version and its
   `memory_limit` / `max_execution_time` (measure with the probe in step C, or read
   Kundzon's PHP settings).
4. **Database** — a MariaDB database and a user with full rights on it already exist
   (`ryddmo_se` on `mysql684.loopia.se`, MariaDB 10.11). Have host / name / user /
   password ready for `config.php`.
5. **URL-cron** — create **two** scheduled jobs, both calling `stockpicker.ryddmo.se`
   over HTTPS with the shared token as the only query parameter:

   | Path | Schedule | Purpose |
   |---|---|---|
   | `https://stockpicker.ryddmo.se/cron/refill?token=…` | daily, 00:00 | `UniverseSync` (seed list in Epic 1) + `Enqueue` |
   | `https://stockpicker.ryddmo.se/cron/work?token=…` | every 5 min | `FetchRunner`, one 75 s time-boxed slice |

   `/cron/derive` is **not registered yet** — it stays a 404 until Story 3.2 ships the
   `Deriver` endpoint. Add a third URL-cron job (daily, after the queue drains) then.

   The token is compared with `hash_equals` against `config.php`. `run_after` in the
   `settings` table (default `18:30` Europe/Stockholm) gates the actual work window, so
   the cron schedule itself never needs to change — outside the window the endpoints
   return `{"status":"window_closed"}` and do nothing.

### C. Loopia shell (over SSH)

1. Create the app directory **above** the web root:

   ```sh
   mkdir -p ~/stockpicker
   ```

   After the first deploy the tree is:

   ```
   ~/stockpicker/
     public_html/     ← stockpicker.ryddmo.se docroot (front controller + .htaccess only)
     src/
     db/migrations/   ← five migrations, see "Database migrations" below
     bin/
     phinx.php
     bootstrap.php
     composer.json  /  composer.lock
     vendor/          ← built here by composer, never rsynced
     config.php       ← created by hand, see step 2
   ```

2. Create `config.php` by hand from the committed template (never rsynced, never in git —
   AD-8). The template documents every key; `Stockpicker\Config` is the contract it must
   satisfy (`db`, `cron_token`, optional `log_path`):

   ```sh
   cp ~/stockpicker/config.php.dist ~/stockpicker/config.php
   nano ~/stockpicker/config.php     # real DB creds (mysql684.loopia.se / ryddmo_se / …) + a strong cron_token
   chmod 600 ~/stockpicker/config.php
   ```

   `config.php.dist`'s defaults are for local Docker development — every value must be
   replaced on the server. Keep a copy of the working file as `config.php.bak` (see
   Rollback).

3. (Optional) Probe the **web** PHP limits for the subdomain, then remove the probe:

   ```sh
   printf '<?php echo "mem=",ini_get("memory_limit")," time=",ini_get("max_execution_time")," ver=",PHP_VERSION,"\n";' \
     > ~/stockpicker/public_html/_probe.php
   curl -s https://stockpicker.ryddmo.se/_probe.php
   rm ~/stockpicker/public_html/_probe.php
   ```

   Record the numbers in "Open items" below.

---

## Routine deploy

From the repo root on your local machine:

```sh
bin/deploy.sh
```

What it does:

1. **Preflight** (before anything is written): echoes the commit being deployed and
   whether the working tree is clean, then probes SSH to `loopia-stockpicker` with
   `BatchMode=yes`. If the host is unreachable it aborts here — no partial deploy.
2. `rsync -az --delete` the **source** to `loopia-stockpicker:~/stockpicker/`. `--delete`
   makes the server tree mirror the checkout (removed files do not linger); the safety is
   the exclude list, which always covers:

   `.git/`, `.github/`, `_bmad-output/`, `_bmad/`, `docs/`, `tests/`, `config.php`,
   `.env`, `*.pub`, `stockpicker-loopia`, `.DS_Store`, and — unless
   `--with-local-vendor` — `vendor/`.

   So a routine deploy **never** deletes or overwrites `config.php` or `vendor/` on the
   server.
3. `ssh … "cd stockpicker && composer install --no-dev --optimize-autoloader"` (falls
   back to `php composer.phar …`) — builds `vendor/` against Loopia's PHP.
4. `ssh … "cd stockpicker && vendor/bin/phinx migrate -e production"` — applies pending
   migrations. Skipped with `--no-migrate`.

Flags:

- `--with-local-vendor` — build `vendor/` locally and rsync it instead of building on the
  server. The documented fallback for if server-side composer ever disappears.
- `--no-migrate` — deploy the code without running the phinx step.

Manual equivalent (if the script is unavailable) — keep the exclude list in sync with
`bin/deploy.sh`:

```sh
rsync -az --delete \
  --exclude '.git/' --exclude '.github/' --exclude 'vendor/' --exclude 'config.php' \
  --exclude '.env' --exclude '*.pub' --exclude 'stockpicker-loopia' --exclude '.DS_Store' \
  --exclude '_bmad-output/' --exclude '_bmad/' --exclude 'docs/' --exclude 'tests/' \
  ./ loopia-stockpicker:stockpicker/
ssh loopia-stockpicker 'cd stockpicker && composer install --no-dev --optimize-autoloader'
ssh loopia-stockpicker 'cd stockpicker && vendor/bin/phinx migrate -e production'
```

---

## Database migrations

Phinx reads DB credentials from `config.php` through its `production` environment (defined
in `phinx.php` via `Stockpicker\Config::db()`, port `3306` — Loopia's MariaDB default on
`mysql684.loopia.se`). Migrations are **never** run from a cron endpoint or automatically
inside the deploy beyond the one explicit step above.

- Apply: `ssh loopia-stockpicker 'cd stockpicker && vendor/bin/phinx migrate -e production'`
- Status: `ssh loopia-stockpicker 'cd stockpicker && vendor/bin/phinx status -e production'`

The five migrations in `db/migrations/`, in order:

| Migration | Creates |
|---|---|
| `20260908161500_create_instrument_and_settings` | `instrument` dimension table; `settings` key/value table seeded with `run_after=18:30`, `batch_size=25`, `rate.avanza=0.5`, `rate.nordnet=0.5`, `queue.stale_after=900` |
| `20260909140000_create_owner_count_daily` | `owner_count_daily` — the append-only time series, PK `(isin, source, as_of_date)`, FK to `instrument` |
| `20260909140100_widen_nordnet_instrument_id` | widens `instrument.nordnet_instrument_id` to `VARCHAR(64)` (36-char nnx UUID) |
| `20260909150000_create_work_queue` | `work_queue` — `pending → claimed → done \| failed`, unique `(isin, run_date)`, FK to `instrument` |
| `20260909160000_create_ingest_run` | `ingest_run` — one appended summary row per pipeline run; append-only, no FK |

`owner_count_daily` and `ingest_run` are append-only (NFR7) and are never rolled back.

---

## Verifying a deploy

```sh
# health check — front controller + .htaccess routing under real Apache
curl -sS -o /dev/null -w '%{http_code}\n' https://stockpicker.ryddmo.se/            # expect 200
curl -sS https://stockpicker.ryddmo.se/                                             # expect {"status":"ok","app":"stockpicker",...}
curl -sS -o /dev/null -w '%{http_code}\n' https://stockpicker.ryddmo.se/nope        # expect 404 (rewrite reaches index.php)

# cron endpoints reject a bad or missing token
curl -sS -o /dev/null -w '%{http_code}\n' https://stockpicker.ryddmo.se/cron/work?token=wrong   # expect 403
curl -sS -o /dev/null -w '%{http_code}\n' https://stockpicker.ryddmo.se/cron/work               # expect 403

# a real slice (before run_after → "window_closed"; after → does work)
curl -sS "https://stockpicker.ryddmo.se/cron/work?token=<real>"
```

Then check `ingest_run` for a fresh row:

```sh
ssh loopia-stockpicker 'cd stockpicker && php bin/show-runs.php'
```

---

## Rollback

There is no automated rollback. Options, simplest first:

1. **Re-deploy a known-good commit:**
   `git checkout <sha> && bin/deploy.sh --no-migrate && git checkout -` — `--no-migrate`
   so an older codebase never runs migrations against the current schema.
2. **Schema:** `vendor/bin/phinx rollback -e production -t <target>` — only for a
   reversible migration. `owner_count_daily` and `ingest_run` are append-only and are
   never rolled back.
3. `config.php` is not under version control — keep the last working copy on the server as
   `~/stockpicker/config.php.bak` (it is excluded from rsync, so it survives a deploy).

---

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| 500 on every route | `.htaccess` in `public_html/` missing or not routing to `index.php`; or `config.php` absent / unreadable |
| 404 on `/` too | subdomain docroot not pointed at `~/stockpicker/public_html/` |
| 403 on a cron call you expected to work | token mismatch with `config.php`, or URL-cron not sending the query string — check the job URL in Kundzon |
| 400 on a cron call | a query parameter other than `token` is present — the endpoints accept `token` and nothing else |
| `/cron/work` returns `window_closed` | `settings.run_after` is later than now (Europe/Stockholm) — expected outside the run window |
| `deploy: cannot reach 'loopia-stockpicker'` | SSH alias/key wrong, or SSH not enabled in Kundzon — the preflight aborted before rsync |
| Migrations fail with access denied | DB user lacks rights, or `config.php` creds wrong |
| `composer` not found over SSH | use `php composer.phar …`, or deploy with `--with-local-vendor` |
| Slice killed mid-run | web `max_execution_time` shorter than the 75 s time-box — lower `batch_size` and the time-box |
| Out-of-memory in a slice | web `memory_limit` < what a slice needs — lower `batch_size` |

---

## Open items to confirm on the first deploy

Copy this into the PR description for the first-deploy follow-up and tick as verified:

- [ ] Web PHP version = ______  `memory_limit` = ______  `max_execution_time` = ______
- [ ] URL-cron max execution time = ______  min interval = ______
- [ ] Subdomain docroot successfully set to `~/stockpicker/public_html/`
- [ ] MariaDB database reachable from the app; `config.php` in place and `chmod 600`
- [ ] Both URL-cron jobs (`/cron/refill`, `/cron/work`) registered and firing
- [ ] Loopia URL-cron sends a usable `User-Agent` and follows the query string
- [ ] `batch_size` / `/cron/work` time-box tuned against the measured web-PHP `memory_limit`
