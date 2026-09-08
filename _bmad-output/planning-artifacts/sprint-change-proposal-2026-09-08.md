---
title: "Sprint Change Proposal — Stockpicker"
date: 2026-09-08
status: approved
trigger: pre-sprint readiness gate (bmad-sprint-planning)
scope: Moderate (backlog reorganization — new story, renumber, added ACs; artifact reconciliation)
mode: batch
---

# Sprint Change Proposal — Stockpicker

## Section 1 — Issue Summary

The `bmad-sprint-planning` readiness gate flagged that the project's infrastructure
decision matured **after** the upstream planning documents were frozen:

- The **architecture spine** (`ARCHITECTURE-SPINE.md`) commits the project to **Loopia
  shared hosting, PHP 8.3, MariaDB 10.6, and URL-cron only** (no CLI cron, unattended
  operation). `SPEC.md` Constraints §1 agrees.
- The **brief** (`brief.md` §"Icke-funktionellt") and its **addendum** (`addendum.md`
  §"Datamodell", §"Konfiguration") — the addendum being a formal companion of `SPEC.md`,
  i.e. inside the preservation-validated contract — still described **SQLite on a laptop
  with a simple scheduler**.

Two further gaps surfaced:

- **NFR8** (nightly run must fit within 256 MB PHP memory) was listed but traced to no
  story or acceptance criterion.
- **No story covered the initial deploy to Loopia.** Story 1.10 (end-to-end smoke test)
  assumed *"ett driftsatt system"* with nothing describing how the code gets there.

Discovered before any implementation started, so no code is affected.

## Section 2 — Impact Analysis

| Artifact | Impact |
|---|---|
| `SPEC.md` | **None** — already correct (Loopia/PHP/MariaDB in Constraints). |
| `ARCHITECTURE-SPINE.md` | Minor — Stack row and structural seed already imply SSH deploy; a `## Deployment` subsection was added to make the rsync-over-SSH + manual-migration + manual-`config.php` flow explicit. |
| `AGENTS.md` (context block) | **None** — written from the spine, already says Loopia / MariaDB / URL-cron / "Deploy: SSH + Composer". |
| `brief.md` | 1 paragraph rewritten. |
| `addendum.md` | 2 sections rewritten (Datamodell close, Konfiguration). |
| `epics.md` | 1 new story (1.10), old 1.10 → 1.11, 1 new AC on Story 1.7, Deploy line and Epic 1 coverage list updated. |
| Epic sequencing | Unchanged. Epic 1 gains a deploy story before the smoke test; Epics 2 and 3 untouched. |
| MVP scope | Unchanged. |
| Code / infra | None yet (greenfield). |

## Section 3 — Recommended Approach

**Option 1 — Direct Adjustment.** Edit the two stale documents to match the canonical
contract, add the missing NFR8 acceptance criterion, and add one deploy story within the
existing Epic 1 structure.

- Effort: **Low** — documentation only, no code, no rework.
- Risk: **Low** — the changes align divergent docs with a decision already made and
  already reflected in the spec, the spine, and the epics.
- Rollback (Option 2): not applicable — nothing built.
- MVP review (Option 3): not needed — scope and goals unchanged.

## Section 4 — Detailed Change Proposals

### 4.1 `brief.md` — §"Krav: datahämtning" → "Icke-funktionellt." paragraph

Replaced *"Ska kunna driftas på en laptop eller en liten server … SQLite räcker
sannolikt; Postgres om …"* with a statement that the system runs unattended on Loopia
(PHP 8.3, MariaDB 10.6, URL-cron only), the ~730k rows/year figure now carries the 256 MB
memory budget, and a dated reconciliation note records that the infra choice was fixed in
the architecture spine after the brief was written.

### 4.2 `addendum.md` — §"Datamodell (skiss)" closing line

Replaced *"SQLite räcker gott; Postgres om analyslagret senare motiverar det"* with
*"Databas: MariaDB 10.6 på Loopia, bundet av plattformen (se arkitektur-spinen). Ett
annat databasval för ett framtida analyslager är ett separat beslut."*, plus the 256 MB
budget.

### 4.3 `addendum.md` — §"Konfiguration (v1)"

Replaced the flat bullet list ("cron-uttryck", "databassökväg / anslutningssträng") with
the AD-8 split: secrets (MariaDB credentials, cron-token, Börsdata API key) in
`config.php` outside `public_html/`; drift parameters (`run_after`, `rate.avanza` /
`rate.nordnet`, backoff, `batch_size`, `queue.stale_after`, universe list toggles) in the
`settings` table. Notes that URL-cron controls *when* endpoints are called and `run_after`
gates the work window without touching the cron schedule.

### 4.4 `epics.md` — Story 1.7 (Work queue och tidsboxad FetchRunner)

Added acceptance criterion:

> **Given** en `FetchRunner`-slice som bearbetar upp till `batch_size` jobb (NFR8)
> **When** slicen körs
> **Then** håller sig processens minnesanvändning under Loopias 256 MB PHP-gräns
> **And** `batch_size` startvärde i `settings` är satt så att en slice ryms med marginal

### 4.5 `epics.md` — new Story 1.10, renumber old 1.10 → 1.11

New **Story 1.10: Loopia-driftsättning och deploy-runbook** — `bin/deploy.sh` does
`rsync -az --delete` over SSH (excluding `config.php`, `.git/`, `_bmad-output/`, dev
deps); web root points at `public_html/` with `src/` and `config.php` above it;
`vendor/bin/phinx migrate -e production` run manually over SSH; `config.php` copied by
hand once; the three URL-cron jobs registered in Loopia's panel; a `docs/deploy.md`
runbook records the steps and Loopia prerequisites (SSH activated, PHP 8.3 CLI, docroot to
a subdirectory, rsync on the shell).

Old **Story 1.10 (End-to-end-röktest)** renumbered to **Story 1.11** — its existing
"Given ett driftsatt system" precondition is now satisfied by Story 1.10.

Epic 1 description and coverage lists updated: Deploy line rewritten (rsync over SSH, not
FTP; local `vendor/` build; manual migration; URL-cron registration; runbook in
`docs/deploy.md`), and Epic 1 now lists **NFRs covered: NFR1, NFR2, NFR4, NFR8, NFR9**.

### 4.6 `ARCHITECTURE-SPINE.md` — new `## Deployment` subsection

Makes explicit: rsync over SSH (not FTP); local `composer install --no-dev`; `vendor/`
synced; `config.php` manual and never in git (AD-8); migrations manual over SSH, never
from a cron endpoint; URL-cron jobs registered in Loopia's panel; and the Loopia
verification list for first deployment.

## Section 5 — Implementation Handoff

**Scope: Moderate** — backlog reorganization, no strategic replan.

| Recipient | Responsibility |
|---|---|
| Sprint planning (`bmad-sprint-planning`) | Regenerate `sprint-status.yaml` from the updated `epics.md` — picks up Story 1.10 (deploy) and 1.11 (smoke test). |
| Developer (`bmad-build`) | Implement Epic 1 in order; Story 1.10 produces `bin/deploy.sh` + `docs/deploy.md`; Story 1.7 verifies the NFR8 memory bound. |

**Carried as open items (not in this proposal):**

- Story 2.6 alarm delivery mechanism ("email/notis och/eller larm-flagga") — resolve when
  that story is drafted.
- Loopia verification questions (SSH, PHP CLI, rsync, docroot, URL-cron limits) — answer
  before the first Story 1.10 deploy; a Loopia support question about the URL-cron
  execution-time limit is already an `epics.md` open item.

## Success Criteria

- No document inside the canonical contract set (`SPEC.md` + companions) states SQLite or
  laptop operation.
- `epics.md` traces NFR8 to a verifiable AC and contains a deploy story ordered before the
  smoke test.
- `sprint-status.yaml` regenerates cleanly with 11 stories in Epic 1.
