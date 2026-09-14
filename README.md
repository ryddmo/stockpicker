# stockpicker

Personal nightly data-collection engine. It builds a per-day time series of
Avanza and Nordnet owner counts for Swedish Nasdaq Stockholm (LC/MC/SC) and
First North stocks. PHP 8.3+ (Loopia's shell runs 8.5), MariaDB 10.11,
Composer, no framework — Guzzle, Monolog, Phinx. Runs entirely on Loopia
shared hosting. v1 is the collection engine plus a personal, authenticated
web UI on top; deeper analysis beyond the derived metrics lands later.

Canonical contract: `_bmad-output/specs/spec-stockpicker/SPEC.md` and the
architecture spine under `_bmad-output/planning-artifacts/architecture/`.

## Layout

```
public_html/     thin front controller (index.php) + .htaccess — subdomain docroot
src/
  Adapter/       SourceAdapter + AvanzaAdapter, NordnetAdapter, AvanzaUniverseAdapter
  Pipeline/      UniverseSync, Enqueue, FetchRunner, Normalizer, Deriver
  Store/         PDO repositories — the only path to the database
  Error/         SchemaMismatch, NotFound, Transient
bin/             one-off scripts run over SSH
db/migrations/   Phinx migrations (first one is Story 1.2)
config.php       secrets (DB creds, cron token, login credentials, session key) — above the web root, never committed
```

## Local development

Requires PHP 8.3+, Composer 2.x, and Docker.

```sh
composer install
docker compose up -d                 # MariaDB 10.11 on 127.0.0.1:3306
cp config.php.dist config.php        # dev defaults already point at the compose DB
composer test                        # PHPUnit smoke suite
composer run analyse                 # PHPStan, level 8
```

CI (`.github/workflows/ci.yml`) runs both of these on every push/PR against a real
MariaDB service container.

`config.php.dist`'s `login_password_hash` placeholder is not a valid bcrypt hash, so set a
real one before logging in locally:

```sh
php -r "echo password_hash('your-password', PASSWORD_BCRYPT), PHP_EOL;"
# paste the result into config.php's login_password_hash, and pick your own login_username
```

Serve the app locally:

```sh
php -S 127.0.0.1:8080 -t public_html
curl -s 127.0.0.1:8080/health        # {"status":"ok","app":"stockpicker","time":"..."}
```

Then open `http://127.0.0.1:8080/` in a browser and log in with `login_username` and the
password you hashed above to reach the placeholder authenticated page.

Phinx is wired but has no migrations yet:

```sh
vendor/bin/phinx status -e development
```

## Deploying

Manual, over SSH, to Loopia. See `docs/deploy.md` for the full runbook
(`bin/deploy.sh`).
