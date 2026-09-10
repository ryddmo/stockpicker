# Deploying stockpicker to Loopia

Runbook for Story 1.10. Stockpicker runs unattended on **Loopia shared hosting**
(Privatpaket): PHP 8.3+ (Loopia's shell is 8.5), MariaDB 10.11, and Loopia's **URL-cron**
(HTTP GET) as the only scheduler. Deploys are **manual over SSH** via `bin/deploy.sh` —
there is no CI deploy.

Domain: **`ryddmo.se`**, app subdomain **`stockpicker.ryddmo.se`**.

**App root is `~/stockpicker.ryddmo.se/`.** When you add a subdomain in Kundzon,
Loopia creates `~/<subdomain>/public_html/` and serves it from there; it does **not**
let you re-point an existing subdomain at another directory ("Peka om" is not offered
for a self-created subdomain). So the code lives in the subdomain-named folder, not a
generic `~/stockpicker/`. `bin/deploy.sh` defaults `STOCKPICKER_REMOTE_DIR` accordingly.

```
local checkout ──rsync -az --delete over SSH──▶ ~/stockpicker.ryddmo.se/ on Loopia
                                                   │
                                                   ├─ composer install --no-dev   (on the server)
                                                   ├─ vendor/bin/phinx migrate     (manual, over SSH)
                                                   └─ public_html/  ◀── stockpicker.ryddmo.se docroot
                                               config.php lives in ~/stockpicker.ryddmo.se/,
                                               above the docroot, never in git
```

First production deploy completed **2026-09-10** — pipe proven end-to-end against the
live seed list (20 jobs enqueued, drained in one 71 s `/cron/work` slice, 36
`owner_count_daily` rows written, 0 failed). Measured values are in "Open items" below.

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
| Home layout | one folder per domain (e.g. `ryddmo.se/`), **no shared `public_html/`** — a subdomain gets `~/<subdomain>/public_html/` |
| MariaDB | database + user created in Kundzon (`ryddmo_se` on `mysql684.loopia.se`, MariaDB 10.11.19) |

**Confirmed on the first deploy 2026-09-10** (measured values in "Open items"):

- [x] Web PHP for `stockpicker.ryddmo.se` = **8.4 / Apache 2.4**, `memory_limit` **256M**,
      `max_execution_time` **180 s**. `memory_limit` meets the NFR8 target, so
      `batch_size` 25 and the 75 s `/cron/work` time-box are left as-is.
- [x] URL-cron **minimum interval is 5 min** ("Var femte minut" is offered), well under
      the 180 s per-call limit. `http://` → `https://` is a 301 at Loopia's nginx LB.
- [x] Subdomain docroot: `~/stockpicker.ryddmo.se/public_html/` (Loopia-created, not
      re-pointable — see the app-root note above).
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
2. **Subdomain** — add `stockpicker.ryddmo.se` under **"Domännamn → Subdomäner"** and
   set its configuration to **"Hemsida hos Loopia" → Unix → PHP 8.3+/Apache 2.4**. Loopia
   creates `~/stockpicker.ryddmo.se/public_html/` as the docroot (fixed — not
   re-pointable) and, once web config is enabled, flips the subdomain's DNS `A` record to
   the shared web IP automatically (it may take 10–30 min for the vhost to serve;
   until then you get Loopia's "Parked at Loopia" page).
3. **SSL** — Kundzon → **SSL** → add the **free Let's Encrypt** certificate for
   `stockpicker.ryddmo.se`. Issuance runs in a batch and can take 15–60 min after the
   subdomain is serving real content. Once the cert is live, tick **"Tvinga SSL"** in the
   subdomain settings (belt-and-braces — Loopia's LB already 301s `http`→`https`).
4. **Database** — a MariaDB database and a user with full rights on it already exist
   (`ryddmo_se` on `mysql684.loopia.se`, MariaDB 10.11). **Copy the username verbatim from
   Kundzon → Databaser** — it has an `@…` suffix and is not guessable. Have host / name /
   user / password ready for `config.php`.
5. **URL-cron** — Kundzon → **"Schemaläggning (cron)"** → create **two** jobs, both
   calling `stockpicker.ryddmo.se` over HTTPS with the shared token as the **only** query
   parameter (the endpoints return 400 on any extra param):

   | Path | Periodicity | Purpose |
   |---|---|---|
   | `https://stockpicker.ryddmo.se/cron/refill?token=…` | **Varje timme** (hourly) | `UniverseSync` (reconcile `instrument` against the live Avanza listing, timeboxed by `universe.resolve_timebox`) then `Enqueue` over the active universe |
   | `https://stockpicker.ryddmo.se/cron/work?token=…` | **Var femte minut** | `FetchRunner`, one 75 s time-boxed slice |

   A `/cron/refill` whose `UniverseSync` step fails — the Avanza listing is
   unreachable / changed shape, or the run would delist more than
   `universe.max_delist` names — returns HTTP 200 `{"status":"universe_sync_failed"}`,
   writes **nothing**, and does **not** run `Enqueue`. The next hourly call retries,
   so the loss is bounded to ≤ 1 h. A legitimate delisting larger than the cap
   needs a one-run operator bump of `universe.max_delist` (see settings below).

   **Both endpoints are gated by `settings.run_after`** (default `18:30` Europe/Stockholm)
   — Story 1.9 design. A `/cron/refill` *before* 18:30 returns `window_closed` and
   enqueues nothing, so it must **not** be scheduled at 00:00. Loopia's cron timezone is
   not exposed and DST shifts a fixed time, so refill runs **hourly**: the first call
   after 18:30 enqueues, later calls are idempotent (`created: 0`). Outside the window
   every call returns HTTP 200 `{"status":"window_closed"}` and does no work.

   `/cron/derive` is **not registered yet** — it stays a 404 until Story 3.2 ships the
   `Deriver` endpoint. Add a third URL-cron job (daily, after the queue drains) then.

   Leave **"E-postadress för utmatning"** empty. Confirm **"Aktiv körning"** is ticked.

### C. Loopia shell (over SSH)

1. The app directory `~/stockpicker.ryddmo.se/` and its `public_html/` are created by
   Loopia when you add the subdomain (step B.2). After the first deploy the tree is:

   ```
   ~/stockpicker.ryddmo.se/
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
   cp ~/stockpicker.ryddmo.se/config.php.dist ~/stockpicker.ryddmo.se/config.php
   nano ~/stockpicker.ryddmo.se/config.php   # real DB creds + a strong cron_token
   chmod 600 ~/stockpicker.ryddmo.se/config.php
   ```

   **The file must be `<?php return [ ... ];`** — a config with no `return` makes
   `require` yield `int(1)` and phinx fails with *"config.php … must return an array, got
   int"*. Verify before deploying:

   ```sh
   php -r 'var_dump(is_array(require "/home/…/stockpicker.ryddmo.se/config.php"));'  # want: bool(true)
   ```

   `config.php.dist`'s defaults are for local Docker development — every value must be
   replaced on the server. `db.user` is the exact `@…`-suffixed string from Kundzon →
   Databaser. Use a password with no `'` `\` `$` (they break the single-quoted PHP string
   or get mangled in `nano`). Keep a copy of the working file as `config.php.bak` (see
   Rollback).

3. Probe the **web** PHP limits for the subdomain. The Loopia shell cannot `curl` its own
   public hostname (hairpin NAT returns empty), so fetch the probe from **outside** the
   server or with `curl --resolve`:

   ```sh
   printf '<?php echo "mem=",ini_get("memory_limit")," time=",ini_get("max_execution_time")," ver=",PHP_VERSION,"\n";' \
     > ~/stockpicker.ryddmo.se/public_html/_probe.php
   # from your laptop:
   curl -s https://stockpicker.ryddmo.se/_probe.php
   rm ~/stockpicker.ryddmo.se/public_html/_probe.php
   ```

   Measured 2026-09-10: `mem=256M time=180 ver=8.4` — recorded in "Open items" below.

### D. First deploy only — data bootstrap

From Epic 2 Story 2.2 the nightly `/cron/refill` reconciles the whole `instrument`
table against the live Avanza listing (`UniverseSync`), but its HTTP work is
wall-clock-timeboxed (`universe.resolve_timebox`, default 45 s per pass) so a
cold table converges over roughly **two weeks** of hourly passes. To finish it in
one sitting, run the un-timeboxed bootstrap once over SSH after the first
`bin/deploy.sh` (so `vendor/` and the schema exist):

```sh
cd ~/stockpicker.ryddmo.se
php bin/universe-sync.php   # full reconcile, NO timebox — resolves every ISIN + Nordnet id in one pass
```

Expect **~45–50 min** for the full universe (~740 names, each costing one
`market-guide` call for its ISIN plus a Nordnet id lookup, strictly serial and
spaced by `settings.rate.avanza` / `settings.rate.nordnet`). It prints the churn
counts (`added / removed / changed / reactivated / ids resolved / ids failed /
deferred / active`) and writes one `run_type='universe_sync'` `ingest_run` row.
Idempotent and safe to re-run; check progress with `php bin/show-runs.php` and
`php bin/list-universe.php` (a read-only spot-check of the live listing).

`bin/seed-instruments.php` still loads the small `InstrumentSeeder::LIST` dev
universe for **local** development. On production those seed rows (and any row
whose `avanza_orderbook_id` is still NULL) are **not** delisted by the first real
`UniverseSync` — they are adopted once their resolved ISIN matches a listing
entry, and otherwise linger active until then.

`bin/resolve-ids.php` is **legacy / dev-only** now: `UniverseSync` owns Avanza-id
caching (the id comes straight from the listing) and re-attempts every missing
Nordnet id each run. For a name whose Nordnet ISIN search keeps failing
(Handelsbanken A, Nordea, Epiroc A are the known ones), re-running `resolve-ids`
just repeats a call `UniverseSync` already logged — hand-cache it instead:

```sh
mysql … -e "UPDATE instrument SET nordnet_instrument_id = '<nnx-uuid>' WHERE isin = '<isin>'"
```

---

## Routine deploy

From the repo root on your local machine:

```sh
bin/deploy.sh
```

What it does:

1. **Preflight** (before anything is written): echoes the commit being deployed and
   whether the working tree is clean, then probes SSH to `loopia-stockpicker` with
   `BatchMode=yes` (`ConnectTimeout=10`, `ServerAlive*` bounds a post-connect stall at
   ~15 s). If the host is unreachable it aborts here — no partial deploy.
2. `rsync -az --delete` the **source** to `loopia-stockpicker:~/stockpicker.ryddmo.se/`
   (override with `STOCKPICKER_REMOTE_DIR`). `--delete` makes the server tree mirror the
   checkout (removed files do not linger); the safety is the exclude list, which always
   covers:

   `.git/`, `.github/`, `_bmad-output/`, `_bmad/`, `docs/`, `tests/`, `config.php`,
   `.env`, `*.pub`, `stockpicker-loopia`, `.DS_Store`, and — unless
   `--with-local-vendor` — `vendor/`.

   So a routine deploy **never** deletes or overwrites `config.php` or `vendor/` on the
   server.
3. `ssh … "cd stockpicker.ryddmo.se && composer install --no-dev --optimize-autoloader"`
   (falls back to `php composer.phar …`) — builds `vendor/` against Loopia's PHP.
4. `ssh … "cd stockpicker.ryddmo.se && vendor/bin/phinx migrate -e production"` — applies
   pending migrations. Skipped with `--no-migrate`. **Take a `mysqldump` first** (see
   "Database migrations") unless the DB is empty — MariaDB DDL is not transactional, so a
   migration that fails partway leaves the schema half-applied.

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
  ./ loopia-stockpicker:stockpicker.ryddmo.se/
ssh loopia-stockpicker 'cd stockpicker.ryddmo.se && composer install --no-dev --optimize-autoloader'
ssh loopia-stockpicker 'cd stockpicker.ryddmo.se && vendor/bin/phinx migrate -e production'
```

---

## Database migrations

Phinx reads DB credentials from `config.php` through its `production` environment (defined
in `phinx.php` via `Stockpicker\Config::db()`, port `3306` — Loopia's MariaDB default on
`mysql684.loopia.se`). Migrations are **never** run from a cron endpoint or automatically
inside the deploy beyond the one explicit step above.

- **Back up first** (skip only when the DB is empty). On the Loopia shell:

  ```sh
  cd ~/stockpicker.ryddmo.se
  eval "$(php -r '$c=require "config.php"; printf("H=%s U=%s P=%s N=%s", $c["db"]["host"],$c["db"]["user"],$c["db"]["pass"],$c["db"]["name"]);')"
  mysqldump -h"$H" -u"$U" -p"$P" "$N" > ~/pre-migrate-$(date +%F).sql
  ```

- Apply: `ssh loopia-stockpicker 'cd stockpicker.ryddmo.se && vendor/bin/phinx migrate -e production'`
- Status: `ssh loopia-stockpicker 'cd stockpicker.ryddmo.se && vendor/bin/phinx status -e production'`

MariaDB DDL is **not transactional** — if a migration aborts midway (the 2026-09-10
first deploy hit *"must return an array, got int"* from a malformed `config.php` before
any DDL ran, but a later schema change could fail after a partial apply) the schema is
left half-migrated and must be reconciled by hand against the table above.

The five migrations in `db/migrations/`, in order:

| Migration | Creates |
|---|---|
| `20260908161500_create_instrument_and_settings` | `instrument` dimension table; `settings` key/value table seeded with `run_after=18:30`, `batch_size=25`, `rate.avanza=0.5`, `rate.nordnet=0.5`, `queue.stale_after=900` |
| `20260909140000_create_owner_count_daily` | `owner_count_daily` — the append-only time series, PK `(isin, source, as_of_date)`, FK to `instrument` |
| `20260909140100_widen_nordnet_instrument_id` | widens `instrument.nordnet_instrument_id` to `VARCHAR(64)` (36-char nnx UUID) |
| `20260909150000_create_work_queue` | `work_queue` — `pending → claimed → done \| failed`, unique `(isin, run_date)`, FK to `instrument` |
| `20260909160000_create_ingest_run` | `ingest_run` — one appended summary row per pipeline run; append-only, no FK |

`owner_count_daily` and `ingest_run` are append-only (NFR7) and are never rolled back.

**Optional `settings` keys (no migration seeds them — absent → the built-in default):**

| Key | Default | Effect |
|---|---|---|
| `universe.resolve_timebox` | `45` (seconds) | wall-clock budget for `UniverseSync`'s two HTTP passes (ISIN + Nordnet id) inside `/cron/refill`. Delistings and list/name changes are pure SQL and always apply in full. `bin/universe-sync.php` ignores this — it runs un-timeboxed. |
| `universe.max_delist` | `25` | `UniverseSync` aborts with zero writes (and `/cron/refill` returns `universe_sync_failed`) if a run would delist more than this many active instruments — a guardrail against a truncated listing mass-delisting the universe. Raise it for one run, via `UPDATE settings`, when a real index review delists more than 25 names, then set it back. |

A present-but-unusable value (non-numeric, zero, negative) is ignored with one
`warning` and the default is used — same rule as `batch_size` / `rate.*`.

---

## Verifying a deploy

Run these **from your laptop**, not the Loopia shell — the server cannot reach its own
public hostname (hairpin NAT returns empty). Add `-k` while the Let's Encrypt cert is
still issuing.

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
# after run_after, expect e.g.:
# {"status":"ok","run_date":"2026-09-10","claimed":20,"done":18,"failed":1,"reopened":1,
#  "rows_written":35,
#  "by_source":{"avanza":{"ok":19,"not_found":1,"schema_mismatch":0,"transient":0},
#               "nordnet":{"ok":18,"not_found":0,"schema_mismatch":0,"transient":1}}}
# `by_source` is the per-source slice tally (Story 2.3): a non-zero `schema_mismatch`
# means an endpoint changed shape; `transient` counts reopened-for-retry hits.
```

If you can't verify from outside, prove the pipe over loopback on the server instead —
`php -S 127.0.0.1:8877 -t public_html &` then `curl 127.0.0.1:8877/cron/…` — this is how
the 2026-09-10 first deploy was verified before the web vhost had propagated.

Then check `ingest_run` for a fresh row:

```sh
ssh loopia-stockpicker 'cd stockpicker.ryddmo.se && php bin/show-runs.php'
```

Note: a `universe_sync` `ingest_run` row is read differently from `fetch` /
`enqueue` rows — its `ok_count` is additions (incl. reactivations) and its
`fail_count` is **delistings**, not failures. The `changed` / `reactivated` /
`ids_resolved` / `ids_failed` / `deferred` breakdown is in the `universe sync
complete` log line (a queryable churn breakdown is Story 2.6).

---

## Rollback

There is no automated rollback. Options, simplest first:

1. **Re-deploy a known-good commit:**
   `git checkout <sha> && bin/deploy.sh --no-migrate && git checkout -` — `--no-migrate`
   so an older codebase never runs migrations against the current schema.
2. **Schema:** `vendor/bin/phinx rollback -e production -t <target>` — only for a
   reversible migration. `owner_count_daily` and `ingest_run` are append-only and are
   never rolled back. For an irreversible break, restore the `~/pre-migrate-*.sql` dump
   taken before `phinx migrate`.
3. `config.php` is not under version control — keep the last working copy on the server as
   `~/stockpicker.ryddmo.se/config.php.bak` (it is excluded from rsync, so it survives a
   deploy).

---

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| 500 on every route | `.htaccess` in `public_html/` missing or not routing to `index.php`; or `config.php` absent / unreadable |
| 404 on `/` too | subdomain docroot not pointed at `~/stockpicker.ryddmo.se/public_html/` |
| "Parked at Loopia" page on every route | subdomain has DNS but no **web configuration** (Kundzon → Subdomäner → "Hemsida hos Loopia"), or the vhost has not propagated yet (10–30 min after enabling) |
| phinx: *"config.php … must return an array, got int"* | `config.php` has no top-level `return` — `require` yields `int(1)`. Must be `<?php return [ ... ];` |
| `curl` from the Loopia shell to the public hostname returns empty | hairpin NAT — the server can't reach its own external IP. Test from your laptop, `curl --resolve`, or loopback `php -S` |
| cert warning / `ssl_verify` != 0 | Let's Encrypt not issued yet (batch runs 15–60 min after you enable it in Kundzon → SSL) |
| 403 on a cron call you expected to work | token mismatch with `config.php`, or URL-cron not sending the query string — check the job URL in Kundzon |
| 400 on a cron call | a query parameter other than `token` is present — the endpoints accept `token` and nothing else |
| `/cron/work` returns `window_closed` | `settings.run_after` is later than now (Europe/Stockholm) — expected outside the run window |
| `/cron/refill` created nothing all evening | scheduled before `run_after` (18:30) — it returns `window_closed` and enqueues nothing. Run it hourly, not at 00:00 |
| `/cron/refill` returns `{"status":"universe_sync_failed"}` | the Avanza listing was unreachable / changed shape, or the run would delist > `universe.max_delist` names. No rows changed, `Enqueue` skipped. Check the log `warning`/`error` line; the next hourly call retries. A genuine large delisting needs a one-run `universe.max_delist` bump |
| `deploy: cannot reach 'loopia-stockpicker'` | SSH alias/key wrong, or SSH not enabled in Kundzon — the preflight aborted before rsync |
| Migrations fail with access denied | wrong `db.user` (copy the `@…`-suffixed string verbatim from Kundzon), wrong password, or DB user lacks rights |
| `composer` not found over SSH | use `php composer.phar …`, or deploy with `--with-local-vendor` |
| Slice killed mid-run | web `max_execution_time` shorter than the 75 s time-box — lower `batch_size` and the time-box |
| Out-of-memory in a slice | web `memory_limit` < what a slice needs — lower `batch_size` |

---

## Open items — closed on the first deploy (2026-09-10)

- [x] Web PHP version = **8.4**  `memory_limit` = **256M**  `max_execution_time` = **180 s**
- [x] URL-cron max execution time ≥ 180 s  min interval = **5 min** ("Var femte minut")
- [x] Subdomain docroot = `~/stockpicker.ryddmo.se/public_html/` (Loopia-created, fixed)
- [x] MariaDB reachable from the app; `config.php` in place and `chmod 600`
- [x] Both URL-cron jobs registered — `refill` hourly, `work` every 5 min. **Firing to be
      confirmed on the first automated run after 18:30 on 2026-09-10.**
- [x] `http://` → `https://` is a 301 at Loopia's nginx LB; `Tvinga SSL` to be ticked once
      the Let's Encrypt cert issues
- [x] `batch_size` 25 / 75 s time-box left as-is — `memory_limit` 256M meets the NFR8 target
- [ ] Loopia URL-cron `User-Agent` / query-string passthrough — verify from the log after
      the first automated run (job URL shows verbatim `?token=…` in Kundzon, so no extra
      params are appended)
- [ ] Three large caps unresolved by `bin/resolve-ids.php` — tracked in `deferred-work.md`
      for Epic 2 Story 2.1
