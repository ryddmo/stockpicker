# Version/Reality-Check Review — Stockpicker Architecture Spine

Reviewed: `ARCHITECTURE-SPINE.md` (updated 2026-09-12), against Packagist/php.net/MariaDB
release data fetched live on 2026-09-12, and against
`sprint-change-proposal-2026-09-10.md`.

## Overall verdict
The Stack section holds up under a fresh check (no drift since the 2026-09-08 run) and
the 2026-09-12 web-UI addition genuinely introduces no hidden dependency — but the spine
is missing an explicit Deferred entry for the still-unverified Avanza universe endpoint,
which the sprint-change-proposal that introduced it already flags as an open risk.

## Findings

- **medium** The spine treats `AvanzaUniverseAdapter`/`listUniverse()` (AD-3, K1,
  Structural Seed) as a settled design, but `sprint-change-proposal-2026-09-10.md`
  (lines 74, 121, 144, 177, 215, 226) explicitly records Avanza's listing endpoint path,
  filter syntax, and list-segment coverage as **unverified**, gated for verification as
  an AC of Story 2.1. The spine's own "Deferred" section — which does carry the
  analogous open Loopia cron-timeout risk — never mentions this. A reader of the spine
  alone would not know the universe source's core contract is still unconfirmed against
  a real API response (2026-09-08 header callout only says *why* the source changed, not
  that its shape is unverified). *Fix:* Add a Deferred bullet mirroring the
  "Loopias verkliga exekveringstidsgräns" one, e.g. "Avanza-listningens endpoint-väg,
  filtersyntax och segmenttäckning (LC/MC/SC/First North) är overifierade mot ett
  verkligt API-svar; verifieras som AC i Story 2.1."

- **low** AD-13 specifies HMAC-signing the login cookie but never states the signature
  is verified with a constant-time comparison, whereas the Consistency Conventions table
  explicitly names `hash_equals` for the cron-token comparison using the identical
  pattern ("samma mönster som `cron_token`/`Config::cronToken()`"). This isn't a missing
  dependency — `hash_equals` is PHP core and already used elsewhere in this same system —
  but the asymmetry in how explicitly it's called out could lead an implementer to use
  `===`/`==` on the cookie signature, reintroducing the timing side-channel the cron path
  already guards against. *Fix:* State in AD-13 (or add a Consistency Conventions row)
  that the cookie signature is verified via `hash_equals`, same as the cron token.

## Verified, no issues found

- **Dependency-completeness claim for this update**: confirmed correct. The cookie
  signing scheme needs only `hash_hmac()` (PHP core, `ext-hash`, always compiled in),
  bcrypt via `password_hash()`/`password_verify()` is PHP core (password hashing API,
  no extension flag to enable), the cron-style `hash_equals` comparison is PHP core, and
  `assets/watchlist.js` is confirmed hand-written vanilla JS using only `fetch()` — no
  bundler, framework, or polyfill implied anywhere in AD-12/AD-13/AD-15 or the sequence
  diagrams. No unstated Composer or npm dependency is implied by anything new in this
  2026-09-12 revision.
- **Stack table re-checked live against Packagist/php.net/MariaDB (2026-09-12)**:
  `guzzlehttp/guzzle` latest is 8.2.0 (fits `^7.9||^8.0`); `monolog/monolog` latest is
  3.12.0 with no 4.x line yet (fits `^3.11`); `robmorgan/phinx` latest is exactly
  `0.16.12`, released 2026-07-03, and is CakePHP-community-maintained as stated; PHP 8.3
  is in security-only support through 2027-12-31 (not EOL); MariaDB 10.11 LTS is
  supported through 2028-02-16; Composer remains at the 2.x line (no 3.0 exists). No
  version in the Stack table is stale.
