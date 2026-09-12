# Spine Pair Review — stockpicker

## Overall verdict

The spine pair is mechanically sound at the frontmatter/cross-reference level — every color has a hex value, every `{path.to.token}` reference in both files resolves, section order matches the canonical DESIGN.md structure, and all EXPERIENCE.md required-default sections are present. The gaps are concentrated where the design goes deepest: Stock Detail's both-sources overlay and range picker have behavioral text but no visual spec, only one Key Flow exists to cover a surface set that includes Login, Full List, and a source switcher the memlog calls "the one mistake this app cannot make silently," and component naming drifts slightly across the two files. A downstream consumer building the Leaderboard can build directly off this pair; one building Stock Detail or Full List will hit real invention points.

## 1. Flow coverage — thin

Checked every stated user need in `.memlog.md` against EXPERIENCE.md's Key Flows (only one exists: "Morning coffee check," lines 99–110).

### Findings
- **high** No flow walks a fresh login (username/password entry → success). The only login-adjacent content is a one-line failure branch ("if the session has expired... re-authenticates") appended to Flow 1 (EXPERIENCE.md:110). The memlog decision "Auth: simple login, username + password" (`.memlog.md:8`) and the Login IA row (EXPERIENCE.md:20) have no protagonist walkthrough with steps/climax. *Fix:* add a short Flow 0 or fold a numbered login sequence into Flow 1's opening instead of skipping straight to "session persists."
- **high** No flow exercises the Full List surface at all — no search, sort, or the four confirmed filter toggles (spike-flagged / steady-growers / watchlisted-only / market list, `.memlog.md:34`) are ever walked step-by-step, despite Full List being an explicit IA destination (EXPERIENCE.md:22). *Fix:* add a flow where Stefan drills into Full List to search/filter beyond the Top 10.
- **medium** The source switcher (Avanza/Nordnet) is never toggled in the one existing flow — Flow 1 only exercises the ranking-mode toggle. The memlog frames getting the source wrong as the one thing "this app cannot make silently" (DESIGN.md:208), yet no flow demonstrates a source switch and re-rank. *Fix:* add a beat to Flow 1 (or a second flow) where Stefan switches source tabs and confirms the list re-ranks without merging.
- **low** Watchlist-tab visit (EXPERIENCE.md:108) is a single trailing sentence tacked onto Flow 1 rather than its own numbered sequence with a climax — thin relative to how central "own nav tab, not a filter" is as a memlog decision (`.memlog.md:31`). *Fix:* acceptable as a coda if intentional, but a two-line flow (open tab → see starred list) would meet the bar the reference examples set (2 flows each, each surface-complete).

## 2. Token completeness — strong

Extracted all 16 color tokens, 6 typography roles, 4 rounded scale values, 9 spacing values, and 10 component-token blocks from DESIGN.md's frontmatter, plus every `{colors.*}`, `{typography.*}`, `{rounded.*}`, `{spacing.*}`, `{components.*}` reference in the prose (DESIGN.md) and the two references in EXPERIENCE.md (`{colors.text-muted}` at EXPERIENCE.md:60, `{typography.label}` at EXPERIENCE.md:82). Every reference resolves to a defined token; every color has a hex value.

### Findings
- **medium** No explicit contrast ratio or WCAG number is stated for any load-bearing muted-on-surface combination (e.g. `text-muted` #98A2B3 on `bg-surface`/`nohist-bg` — badge text used across every row). EXPERIENCE.md's Accessibility Floor says only "verify the muted grays... but no formal audit needed" (EXPERIENCE.md:81) without naming a target to verify against. *Fix:* state a target ratio (even a soft "aim for 4.5:1") so "verify" has something to check against.
- **low** The wide-viewport ambient shadow (`0 12px 40px rgba(16,19,31,0.08)`, DESIGN.md:190) and the ranking-mode active-tab shadow are stated only as literal values in Elevation & Depth prose, not captured as frontmatter tokens, inconsistent with the rest of the system's token-first discipline. *Fix:* add a `shadow` block to frontmatter (`shadow.page`, `shadow.tab-active`) and reference it from prose.

## 3. Component coverage — thin

Extracted every component name from DESIGN.md.Components (lines 201–211), DESIGN.md frontmatter `components:` block, EXPERIENCE.md.Component Patterns (lines 42–54), and mentions elsewhere (IA table, Key Flow, Interaction Primitives).

### Findings
- **critical** The Stock Detail "both-sources trend overlay" (IA, EXPERIENCE.md:24; also Flow 1, EXPERIENCE.md:106) has no visual spec anywhere in DESIGN.md. `DESIGN.md.Components.sparkline` only defines single-line stroke logic (positive/negative/spike/no-history); nothing says how two overlaid source lines stay "visually distinct, never merged" per the explicit memlog decision (`.memlog.md:25`). *Fix:* add a component (or extend sparkline) specifying the two-source disambiguation — second stroke color, dash pattern, or legend.
- **high** The range picker (segmented control, day/week/month/year) has a full behavioral row in EXPERIENCE.md (line 54) and drives a Key Flow climax (EXPERIENCE.md:106), but has no row in DESIGN.md.Components — no shape, color, size, or active-state spec at all. *Fix:* add a `range-picker` component entry (likely reusing the existing pill/toggle family — `{rounded.full}`, `{colors.control-bg}`).
- **medium** `Delta chip` and `Sparkline` are both fully specified in DESIGN.md.Components (lines 207, 211) with real visual rules, but neither has a corresponding row in EXPERIENCE.md.Component Patterns — no behavioral statement (even a minimal "non-interactive, part of row, not its own tap target"). *Fix:* add one-line behavioral rows for both.

## 4. State coverage — adequate

Walked each IA surface (Login, Leaderboard, Full List, Watchlist, Stock Detail) against EXPERIENCE.md's State Patterns table (lines 56–66) for empty / cold-load / focus / error / offline / permission-denied applicability.

### Findings
- **medium** Full List has no "no results" state for its filter/search combination. It has four confirmed filter dimensions plus search (`.memlog.md:34`) but the only empty-list state defined is "Zero qualifying Steady growers" (EXPERIENCE.md:63), which is specific to the Leaderboard ranking mode, not to Full List's filter UI. A user filtering Full List to zero rows has no defined treatment. *Fix:* add a Full List "no matches" state row.
- **medium** No generic data-fetch/network error state exists for Leaderboard, Full List, or Stock Detail — only Auth failure and Session expired are covered (EXPERIENCE.md:65–66). Both reference examples include this class of state (Drift's save-failure toast, Quill's sync-error surface). *Fix:* add a "couldn't load latest data" state, even a minimal one given the low-stakes personal-tool posture.
- **low** No cold-load/checking-session state is defined for Login itself (distinct from the post-login Leaderboard skeleton). Minor given session persistence makes this rare.

## 5. Visual reference coverage — as expected, pending

`mockups/` and `wireframes/` do not exist yet — expected at this stage, before mock promotion; noted as a pending step, not a defect. `.working/` holds all 3 rendered direction mockups (`direction-clean-terminal.html`, `direction-calm-editorial.html`, `direction-modern-fintech.html`). Only `direction-modern-fintech.html` is referenced by name in EXPERIENCE.md (Responsive & Platform, line 88) — the other two are orphaned relative to the spine pair, but this is expected: `.memlog.md:29` explicitly records them as rejected directions, not omissions. `imports/` exists and is empty, consistent with `sources: []` (fresh design, nothing to import). No unspecific or broken references found.

## 6. Bloat & overspecification — strong

No source restatement (no `sources:` to restate). No pixel values duplicate what a token already covers, apart from the two shadow literals noted in §2 (an under-specification issue, not bloat). DESIGN.md's per-color prose matches the spec's expected "why it exists, where it's used, what it's not used for" format rather than padding. EXPERIENCE.md's prose (Foundation, Voice and Tone) stays functional rather than editorial, consistent with the instruction that DESIGN.md may carry editorial voice and EXPERIENCE.md should not. No decorative narrative found that doesn't tie back to a memlog decision.

## 7. Inheritance discipline — thin

No `sources:` frontmatter to resolve (fresh design, as expected). Checked instead: (a) component-name identity across DESIGN.md and EXPERIENCE.md, (b) that every EXPERIENCE.md `{path.to.token}` resolves to a DESIGN.md token by name.

### Findings
- **high** Component names drift between the two files for the same concept: "Spike badge" (DESIGN.md:205, `components.spike-badge`) vs. "Spike flag badge" (EXPERIENCE.md:50); "Watchlist star" (DESIGN.md:210) vs. "Watchlist star toggle" (EXPERIENCE.md:51); "Source tab" (DESIGN.md:208, `components.source-tab`) vs. "Source switcher" (EXPERIENCE.md:52). Concepts map 1:1 for a human reader, but a downstream consumer resolving by exact name would miss the link. *Fix:* align names literally (pick one label per component, use it in both files).
- (token resolution itself is clean — both EXPERIENCE.md references resolve correctly; see §2.)

## 8. Shape fit — strong

DESIGN.md sections appear in canonical order (Brand & Style → Colors → Typography → Layout & Spacing → Elevation & Depth → Shapes → Components → Do's and Don'ts) with none omitted. EXPERIENCE.md has all required defaults (Foundation, IA, Voice and Tone, Component Patterns, State Patterns, Interaction Primitives, Accessibility Floor, Key Flows) plus the required-when-applicable Responsive & Platform section, correctly present given the confirmed multi-surface (mobile + laptop) scope. The invented "Open Items" section (EXPERIENCE.md:112–114) earns its place — it directly closes the loop on the memlog's 5 resolved assumptions.

### Findings
- **low** "Inspiration & Anti-patterns" — present in both reference examples — is dropped entirely, despite usable material existing: 3 rejected mockup directions with stated rejection reasons (`.memlog.md:29`) and an explicit anti-hype brand stance (DESIGN.md:144, "none of fintech's persuasion"). The drop is defensible for a one-off personal tool but isn't acknowledged as a deliberate omission anywhere. *Fix:* either add a short section capturing the 3-direction rejection logic, or note the omission as intentional.

## Mechanical notes

- No broken cross-references found; every `{path.to.token}` in both files resolves to a defined frontmatter token.
- Frontmatter is complete in both files (`name`, `status`, `sources: []`, `updated` present; DESIGN.md adds `description`).
- No Mermaid diagrams present in either file.
- Naming inconsistencies to reconcile before downstream consumption: `Spike badge`/`Spike flag badge`, `Watchlist star`/`Watchlist star toggle`, `Source tab`/`Source switcher` (see §7).
- `Delta chip` and `Sparkline` exist as DESIGN.md components with no EXPERIENCE.md Component Patterns counterpart; `Range picker` and the Stock Detail both-sources overlay exist as EXPERIENCE.md behavior with no DESIGN.md visual counterpart (see §3).
