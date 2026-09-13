---
title: "Sprint Change Proposal — Stockpicker"
date: 2026-09-12
status: approved
trigger: bmad-sprint-planning readiness gate — SPEC.md Non-goals contradict the already-designed Epic 4 (web UI)
scope: Minor (canonical-contract + brief documentation reconciliation; no epic/story rework, no code)
mode: batch
---

# Sprint Change Proposal — Stockpicker

## Section 1 — Issue Summary

**Problem.** `SPEC.md` — the project's self-declared "kanoniskt kontrakt" — still lists
*"Allt presentations- och analyslager: webbgränssnitt, grafer, dashboards, notiser."* as a
v1 Non-goal, and frames presentation as deferred: *"v1 är enbart insamlingsmotorn ...
Presentation och analys kommer senare."* `brief.md` carries the same exclusion
("All presentation: webbgränssnitt, grafer, dashboards, notiser.") plus a related one
("autentisering" listed as outside v1).

That "later" has already arrived in every other artifact:
- `ARCHITECTURE-SPINE.md` was updated 2026-09-12 with AD-12 through AD-15 and capabilities
  K13–K18 for a full authenticated web UI; its scope line now reads "...plus autentiserad
  webb-UI ... ovanpå samma lagrade data."
- `DESIGN.md` / `EXPERIENCE.md` (the UX design contract) were authored, `status: final`,
  with the memo "Inga kvarstående" open points.
- `epics.md` now has **Epic 4: Webb-UI — bläddra ägarantal-trender** (5 stories, FR14–FR19),
  drafted and validated this session.

**Issue type:** documentation-sync gap following a strategic pivot. The pivot itself (adding
the presentation layer) was never in question — it's already fully designed and story-broken
downstream. Only the canonical PRD-equivalent (`SPEC.md`) and its source brief never caught
up.

**Discovery.** During `bmad-sprint-planning`'s readiness gate, run immediately after Epic 4
was added to `epics.md`. The gate's own conflict check ("a spec and an epic disagreeing")
flagged it before any tracking was generated.

**Evidence.**
- `SPEC.md` Non-goals: *"Allt presentations- och analyslager: webbgränssnitt, grafer,
  dashboards, notiser."*
- `SPEC.md` Why: *"v1 är enbart insamlingsmotorn ... Presentation och analys kommer
  senare."*
- `brief.md` Scope "Ligger utanför v1": *"All presentation: webbgränssnitt, grafer,
  dashboards, notiser."* and *"Fleranvändarstöd, autentisering, molndrift, hög
  tillgänglighet."*
- `ARCHITECTURE-SPINE.md` frontmatter `updated: '2026-09-12'`, scope line, AD-12–AD-15,
  Capability → Architecture Map K13–K18.
- `epics.md` Epic 4 section, FR14–FR19, FR Coverage Map.

## Section 2 — Impact Analysis

**Epic impact.** None. Epic 4 (and Epics 1–3) are unaffected — every decision the stories
rely on is already recorded in `ARCHITECTURE-SPINE.md` and the UX contract. No epic is
modified, added, or resequenced by this correction; it only brings the canonical contract
into agreement with work already approved this session.

**Story impact.** None. No story text or acceptance criteria change.

**Artifact conflicts.**

| Artifact | Impact |
|---|---|
| `SPEC.md` | Dated note; Why section reworded; new **CAP-8 — Autentiserad webb-yta**; one Constraints bullet added; Non-goals bullet replaced; frontmatter `companions` gains the UX pair |
| `brief.md` | Dated note; frontmatter `updated`; "Vem det är för" reworded; Scope section gets a new "Ingår i v1 (webb-UI)" line and two "Ligger utanför v1" bullets rewritten |
| `ARCHITECTURE-SPINE.md` | None — already reconciled 2026-09-12, source of truth for this correction |
| `DESIGN.md` / `EXPERIENCE.md` | None — already final |
| `epics.md` | None — already reconciled this session |
| `addendum.md` | None — scoped to data-source technical detail, doesn't claim UI is out of scope |
| `sprint-status.yaml` | Not hand-edited here — deferred to the next `bmad-sprint-planning` run, which derives epic/story keys deterministically via `sprint_plan.py` |

**Technical impact.** None. No code changes; this is a planning-document correction only.

## Section 3 — Recommended Approach

**Direct Adjustment.** Edit `SPEC.md` and `brief.md` in place to match what
`ARCHITECTURE-SPINE.md`, the UX contract, and `epics.md` already establish.

- **Why not rollback:** there's nothing to roll back — no story or code was built against
  the stale Non-goal.
- **Why not MVP review:** the MVP isn't shrinking or being renegotiated; it already grew
  (deliberately, via the UX/Architecture work) and the canonical doc just needs to catch up.

**Effort:** Low — text edits to two documents, mirroring the precedent set by
`sprint-change-proposal-2026-09-10.md` (the Börsdata→Avanza correction) for how a dated note
plus targeted section edits keep the contract set coherent without a rewrite.

**Risk:** Low. No downstream artifact depends on the stale wording; the correction only
removes a contradiction, it doesn't introduce a new decision.

**Scope classification:** Minor — canonical-contract wording reconciled, no epic/story
rework, no code.

## Section 4 — Detailed Change Proposals

### 4.1 `SPEC.md`

- **Frontmatter `companions`:** add `../../planning-artifacts/ux-designs/ux-stockpicker-2026-09-12/DESIGN.md`
  and `.../EXPERIENCE.md` — the UX contract Epic 4 is built on now belongs in the
  preservation-validated set alongside the architecture spine and addendum.
- **Dated note after the H1** (same pattern as `ARCHITECTURE-SPINE.md`'s 2026-09-10 note):
  ```
  > _2026-09-12: presentationslagret (webb-UI) flyttat in i scope — tidigare ett Non-goal.
  > Se ARCHITECTURE-SPINE.md AD-12–AD-15/K13–K18, DESIGN.md/EXPERIENCE.md, CAP-8 nedan, och
  > sprint-change-proposal-2026-09-12.md._
  ```
- **Why section**, last sentence:
  - OLD: *"v1 är enbart **insamlingsmotorn** — den som bygger upp tidsserien. Presentation
    och analys kommer senare och behöver att datainsamlingen redan gått ett tag."*
  - NEW: *"v1 är insamlingsmotorn och, sedan 2026-09-12, en personlig webbyta ovanpå den
    (CAP-8): insamlingen byggdes och driftsattes först (Epic 1–3) eftersom presentationen
    förutsätter att det redan finns historik att visa. Analys utöver de härledda måtten
    (K10) kommer fortfarande senare."*
- **New capability**, appended after CAP-7:
  ```
  - **CAP-8 — Autentiserad webb-yta** _(tillagd 2026-09-12)_
    - **intent:** En inloggad, mobilanpassad webbyta (Topplista, Fullständig lista,
      Bevakningslista, Aktiedetalj) låter Stefan bläddra, ranka, filtrera och bevaka aktier
      utifrån de insamlade ägarantalstrenderna och härledda måtten, utan att fråga
      databasen direkt.
    - **success:** Stefan kan logga in med en långlivad session, se topp 10 per källa och
      rankningsläge, gräva vidare i fullständiga listan med sök/filter/sort, bevaka en
      aktie med ett tryck, och se en akties fulla historik och härledda mått för båda
      källorna — på mobil och laptop.
  ```
- **Constraints**, new bullet:
  ```
  - **Webb-UI:t är serverrenderad PHP utan klientramverk eller byggsteg** (enda undantaget:
    en handskriven JS-fil för bevakningsstjärnans optimistiska växling) — se AD-12.
  ```
- **Non-goals**, replace the presentation-layer bullet:
  - OLD: *"Allt presentations- och analyslager: webbgränssnitt, grafer, dashboards,
    notiser."*
  - NEW: *"Push-notiser eller e-postaviseringar (webb-UI:t är session-baserat, ingen
    bakgrundsnotifiering)."*

### 4.2 `brief.md`

- **Frontmatter `updated`:** 2026-09-10 → 2026-09-12.
- **Dated note after the H1:**
  ```
  > _2026-09-12: presentationslagret (webb-UI) flyttat in i scope — tidigare listat som
  > liggande utanför v1. Se SPEC.md CAP-8, ARCHITECTURE-SPINE.md AD-12–AD-15, och
  > sprint-change-proposal-2026-09-12.md._
  ```
- **"Vem det är för"**:
  - OLD: *"...ingen inloggning, ingen fleranvändarhantering, ingen SLA."*
  - NEW: *"...ingen fleranvändarhantering, ingen SLA — webb-UI:t (tillagt 2026-09-12) har en
    enda hårdkodad inloggning, ingen registrering eller multi-tenant."*
- **Scope**, new line after "Ingår i v1 (datainsamling)":
  ```
  **Ingår i v1 (webb-UI, tillagt 2026-09-12):** autentiserad, mobilanpassad webbyta för
  Topplista, Fullständig lista, Bevakningslista och Aktiedetalj ovanpå den insamlade
  tidsserien och de härledda måtten. Se SPEC.md CAP-8 och ARCHITECTURE-SPINE.md K13–K18.
  ```
- **"Ligger utanför v1"**, two bullets rewritten:
  - OLD: *"All presentation: webbgränssnitt, grafer, dashboards, notiser."*
  - NEW: *"Push-notiser eller e-postaviseringar (webb-UI:t, tillagt 2026-09-12, är
    session-baserat utan bakgrundsnotifiering)."*
  - OLD: *"Fleranvändarstöd, autentisering, molndrift, hög tillgänglighet."*
  - NEW: *"Fleranvändarstöd, molndrift, hög tillgänglighet."* (drop "autentisering" — a
    single hardcoded-credential login now exists for the web UI; multi-tenant auth remains
    out of scope)

### 4.3 Implementation artifacts

- `sprint-status.yaml` — **not edited here.** The next `bmad-sprint-planning` run will add
  `epic-4` and its five story keys via `sprint_plan.py generate`, which derives keys
  deterministically from `epics.md`; hand-editing here would just be overwritten or risk
  drifting from the script's key format.

## Section 5 — Implementation Handoff

**Scope: Minor.** Two documents, text-only, no code or story impact.

| Recipient | Responsibility |
|---|---|
| This session (done in this proposal, on approval) | Apply the `SPEC.md` and `brief.md` edits above. |
| `bmad-sprint-planning` (re-run next) | Re-run the readiness gate (should now PASS) and generate `sprint-status.yaml` entries for `epic-4` and its five stories. |

**Carried as open items (not blockers):** none. This correction has no dependency on
anything still undecided — both source artifacts (`ARCHITECTURE-SPINE.md`,
`DESIGN.md`/`EXPERIENCE.md`) are already `status: final`.

## Success Criteria

- `SPEC.md` no longer lists the web UI as a Non-goal, and carries a capability (CAP-8) that
  traces to `epics.md` FR14–FR19.
- `brief.md`'s Scope section reflects the same reconciliation.
- A re-run of the `bmad-sprint-planning` readiness gate reports PASS with no artifact
  conflicts.
