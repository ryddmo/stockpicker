- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-projektskelett-och-gemensam-grund.md`
  summary: Verify `.htaccess` front-controller routing under real Apache (not just `php -S`).
  evidence: Both routing tests run through the PHP built-in server, which ignores `.htaccess`; a rewrite regression would ship with the suite green. Fix belongs in Story 1.10 (`docs/deploy.md`): a post-deploy curl check for `/` (200 JSON) and `/nope` (404 JSON) against the live subdomain.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-projektskelett-och-gemensam-grund.md`
  summary: Add log rotation / a sane default log level for the nightly job.
  evidence: `Logging::logger()` attaches one `StreamHandler` at `Level::Debug` to a single unbounded file; on shared hosting this grows without limit. Revisit in Story 1.8 (minimal körningslogg) / Story 2.6 (full körningslogg) — e.g. `RotatingFileHandler` and a configurable level.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-projektskelett-och-gemensam-grund.md`
  summary: Reject the placeholder `cron_token` value (`change-me`) so a misconfigured deploy fails closed.
  evidence: `Config::cronToken()` rejects only an empty string. No `/cron/*` endpoints exist yet; Story 1.9 (cron endpoints + token check) should reject the known default or warn loudly.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-projektskelett-och-gemensam-grund.md`
  summary: Decide whether to pin `config.platform.php` in `composer.json` for the 8.3 floor.
  evidence: Without `config.platform.php`, a `composer update` on an 8.5 dev machine can lock dependencies requiring >8.3 and silently break the minimum-supported environment. All currently-locked deps are 8.1/8.2-compatible, so no impact today. The frozen spec comments that `require.php` is "not pinned", so this is a deliberate dependency-policy decision.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-projektskelett-och-gemensam-grund.md`
  summary: Add static analysis (PHPStan) and CI to enforce the baseline type-safety bar.
  evidence: The scaffold is written to a high type-safety standard (array-shape annotations, `declare(strict_types=1)`, `@throws`) but nothing enforces it and nothing runs the smoke suite automatically. Reasonable as its own tooling story.
