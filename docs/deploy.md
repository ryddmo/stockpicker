# Deploying stockpicker to Loopia

Runbook for Story 1.10. Stockpicker runs unattended on **Loopia shared hosting**
(Privatpaket): PHP 8.3+ (Loopia's shell is 8.5), MariaDB 10.11, and Loopia's **URL-cron**
(HTTP GET) as the only scheduler. Deploys are **manual over SSH** — there is no CI
deploy.

```
local build ──rsync over SSH──▶ ~/stockpicker/ on Loopia
                                   │
                                   ├─ composer install --no-dev   (on the server)
                                   ├─ vendor/bin/phinx migrate     (manual, over SSH)
                                   └─ public_html/  ◀── subdomain docroot
                               config.php lives here, above docroot, never in git
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

**Still to confirm on first deploy** (fill in below as you go):

- [ ] Web PHP version for the subdomain, and its `memory_limit` / `max_execution_time`
      — **URL-cron runs in the web context, not the CLI**, so the 1024M CLI limit does
      not apply. Target: `memory_limit` ≥ 256M (NFR8).
- [ ] URL-cron **maximum execution time** per call and **minimum interval** (need ≈5 min
      for `/cron/work`).
- [ ] Subdomain document root can be pointed at a subdirectory.
- [x] MariaDB database + user created in Kundzon (`ryddmo_se` on `mysql684.loopia.se`, MariaDB 10.11.19).

---

## One-time setup

### A. Local machine

1. Generate a deploy key (ED25519 — on Loopia's supported list):

   ```sh
   ssh-keygen -t ed25519 -C "stockpicker-loopia" -f ~/.ssh/stockpicker-loopia
   chmod 600 ~/.ssh/stockpicker-loopia
   ```

   The private key stays in `~/.ssh/`. **Never commit it, never put it in GitHub source.**
   (If you later add a CI deploy, its private key goes in GitHub Actions *secrets*, not a
   file.)

2. Add a host alias in `~/.ssh/config`:

   ```
   Host loopia-stockpicker
       HostName <loopia-ssh-host>       # e.g. sNN.loopia.se — from Kundzon
       User <loopia-ssh-user>
       IdentityFile ~/.ssh/stockpicker-loopia
       IdentitiesOnly yes
   ```

3. Test: `ssh loopia-stockpicker` should land you on the Loopia shell.

### B. Loopia Kundzon

1. **SSH/SFTP** — activate it, upload the contents of `~/.ssh/stockpicker-loopia.pub`.
2. **Subdomain** — create `stockpicker.<yourdomain>` (e.g. `stockpicker.ryddmo.se`) and
   set its **document root** to `~/stockpicker/public_html/`.
3. **PHP version** — set the subdomain to PHP **8.3 or newer**. Note the version and its
   `memory_limit` / `max_execution_time` (check via the probe in step C, or Kundzon's PHP
   settings).
4. **Database** — create a MariaDB database and a user with full rights on it (Loopia
   runs MariaDB 10.11). Record host, name, user, password for `config.php`.
5. **URL-cron** — create three scheduled jobs, all calling the subdomain over HTTPS with
   the shared token:

   | Path | Schedule | Purpose |
   |---|---|---|
   | `https://stockpicker.<domain>/cron/refill?token=…` | daily, 00:00 | `UniverseSync` + `Enqueue` |
   | `https://stockpicker.<domain>/cron/work?token=…` | every 5 min | `FetchRunner` time-boxed slice |
   | `https://stockpicker.<domain>/cron/derive?token=…` | daily, after the queue drains | `Deriver` |

   The token is compared with `hash_equals` against `config.php`. `run_after` in the
   `settings` table gates the actual work window, so the cron schedule itself never needs
   to change.

### C. Loopia shell (over SSH)

1. Create the app directory **above** the web root:

   ```sh
   mkdir -p ~/stockpicker
   ```

   After the first deploy the tree is:

   ```
   ~/stockpicker/
     public_html/     ← subdomain docroot (front controller only)
     src/
     db/migrations/
     bin/
     vendor/          ← built here by composer, never rsynced
     config.php       ← manual, see step 2
   ```

2. Create `config.php` by hand from the template (never rsynced, never in git):

   ```sh
   cp ~/stockpicker/config.php.dist ~/stockpicker/config.php
   nano ~/stockpicker/config.php     # fill in DB creds + cron_token
   chmod 600 ~/stockpicker/config.php
   ```

3. (Optional) Probe the **web** PHP limits, then remove the probe:

   ```sh
   printf '<?php echo "mem=",ini_get("memory_limit")," time=",ini_get("max_execution_time")," ver=",PHP_VERSION,"\n";' \
     > ~/stockpicker/public_html/_probe.php
   curl -s https://stockpicker.<domain>/_probe.php
   rm ~/stockpicker/public_html/_probe.php
   ```

---

## Routine deploy

From the repo root on your local machine:

```sh
bin/deploy.sh
```

What it does:

1. `rsync -az --delete` the **source** to `loopia-stockpicker:~/stockpicker/`, excluding
   `config.php`, `.git/`, `vendor/`, `_bmad-output/`, `_bmad/`, `docs/`, tests, and key
   files. `--delete` removes files on the server that no longer exist locally;
   `config.php` and `vendor/` are excluded and therefore protected.
2. `ssh … "cd stockpicker && composer install --no-dev --optimize-autoloader"` — builds
   `vendor/` against Loopia's PHP.
3. `ssh … "cd stockpicker && vendor/bin/phinx migrate -e production"` — applies pending
   migrations.

**Fallback** if server-side composer ever disappears: `bin/deploy.sh --with-local-vendor`
builds `vendor/` locally and syncs it instead.

Manual equivalent (if the script is unavailable):

```sh
rsync -az --delete \
  --exclude '.git/' --exclude 'vendor/' --exclude 'config.php' \
  --exclude '_bmad-output/' --exclude '_bmad/' --exclude 'docs/' --exclude 'tests/' \
  ./ loopia-stockpicker:stockpicker/
ssh loopia-stockpicker 'cd stockpicker && composer install --no-dev --optimize-autoloader'
ssh loopia-stockpicker 'cd stockpicker && vendor/bin/phinx migrate -e production'
```

---

## Database migrations

- Phinx reads DB credentials from `config.php` via its `production` environment (defined
  in `phinx.php`). Migrations are **never** run from a cron endpoint or automatically
  inside the deploy beyond the explicit step above.
- Check status: `ssh loopia-stockpicker 'cd stockpicker && vendor/bin/phinx status -e production'`
- The first migration seeds the canonical `settings` keys (`run_after`, `batch_size`,
  `rate.avanza`, `rate.nordnet`, `queue.stale_after`).

---

## Verifying a deploy

```sh
# health check
curl -sS https://stockpicker.<domain>/            # expect 200

# cron endpoints reject a bad token
curl -sS -o /dev/null -w '%{http_code}\n' https://stockpicker.<domain>/cron/work?token=wrong   # expect 403

# a real slice (before run_after → "window closed"; after → does work)
curl -sS "https://stockpicker.<domain>/cron/work?token=<real>"
```

Then check `ingest_run` for a fresh row (via a `bin/` script or a DB client).

---

## Rollback

There is no automated rollback. Options, simplest first:

1. **Re-deploy a known-good commit:** `git checkout <sha> && bin/deploy.sh && git checkout -`
2. **Schema:** `vendor/bin/phinx rollback -e production -t <target>` — only if the
   migration is reversible; the time series in `owner_count_daily` is append-only and is
   never rolled back.
3. `config.php` is not under version control — keep a copy of the last working one on the
   server (`config.php.bak`).

---

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| 500 on every route | `.htaccess` in `public_html/` missing or not routing to `index.php`; or `config.php` absent / unreadable |
| 403 on a cron call you expected to work | token mismatch with `config.php`, or URL-cron not sending the query string — check the job URL in Kundzon |
| `/cron/work` returns "window closed" | `settings.run_after` is later than now (Europe/Stockholm) — expected outside the run window |
| Migrations fail with access denied | DB user lacks rights, or `config.php` creds wrong |
| `composer` not found over SSH | use `php /usr/local/bin/composer.phar …`, or deploy with `--with-local-vendor` |
| Slice killed mid-run | web `max_execution_time` shorter than the time-box — lower `batch_size` and the time-box in `settings` |
| Out-of-memory in a slice | web `memory_limit` < what a slice needs — lower `batch_size` |

---

## Open items to confirm on first deploy

Copy this into the PR description for Story 1.10 and tick as verified:

- [ ] Web PHP version = ______  `memory_limit` = ______  `max_execution_time` = ______
- [ ] URL-cron max execution time = ______  min interval = ______
- [ ] Subdomain docroot successfully set to `~/stockpicker/public_html/`
- [ ] MariaDB database created; `config.php` in place and `chmod 600`
- [ ] All three URL-cron jobs registered and firing
- [ ] Loopia URL-cron sends a usable `User-Agent` and follows the query string
