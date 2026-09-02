# CS-PLAN-059: WP0 — Backlog Structure and Executable Baseline

> **Status:** Plan (Standard A review passed 2026-09-02; activation pending)
> **Effort:** M
> **Priority:** P1
> **Depends on:** [CS-PLAN-058](../active/cs-plan-058-core-workflow-bottom-up-masterplan.md) (active; this plan executes its work package WP0 and may not start before a passing Standard A review of this plan)
> **Related backlog items:** TECH-DOCS-003 (owned), CR-053-21 (metadata portion owned), CR-WF-01..08 + RELEASE-002/003 (restructured and annotated, not implemented here)

## 1. Problem Summary

CS-PLAN-058 §2.2 requires the backlog to become an executable, dependency-oriented
program inventory before WP1 starts. The current `docs/backlog/backlog.md` (821
lines) does not satisfy that:

- **~46 resolved/closed stubs** (BUG-\*, TECH-\*, CR-016-\*, CR-SVC-\*, UX-\*,
  FEAT-UX-021/030/031, CR-053-05/06/08/10/14/15, CR-027-\*) still sit inline next
  to roughly 45 genuinely open items, although the monthly archive is supposed to
  be their only home (TECH-DOCS-003, open since 2026-09-02).
- **Duplicate ID:** `BUG-E2E-003` exists twice — a resolved stub (title-selection
  flake, CS-PLAN-048) and an open item (insights-flow Sunday flake).
- **Inconsistent metadata:** CR-SVC-40/41/44 and the open CR-053/CR-055 items
  have no `**Status**` line at all; no open entry carries the standardized
  program metadata required by CS-PLAN-058 §2.2 (Layer, Journey stage, Owning,
  Depends on, Blocks, Folded-in).
- **Sections follow historical review origin**, not dependencies or program
  ownership, so the critical path CR-WF-01..08 → RELEASE-002 is not reviewable
  from the structure.
- **Broken tracked links:** stale references to removed
  `docs/plans/done/cs-plan-025/029/036/037-*.md` files and to CS-PLAN-058's old
  `new/` path (introduced by its activation move) — links are not portable.
- **No machine check** exists for duplicate IDs, resolved-in-backlog stubs,
  missing metadata, or broken links; the encoding/l10n gates cover other classes.
- **Program baseline facts are scattered or absent:** current route count
   (71 after CS-PLAN-055), the migration chain (22 files, Version1000→1028 with
  1017–1020 already folded), the PHPUnit unit/integration skip inventory, and the
  Playwright project split (`chromium`, `chromium-serial-db`,
  `chromium-scheduling-owner`) are not recorded in one dated, verifiable form.

Without WP0, WP1 cannot start from a trustworthy inventory and later packages
cannot prove their backlog ownership.

## 2. Strategy

Archive-first, then restructure, then lock with a checker — mirroring the
 ratified decision D1 of CS-PLAN-058:

1. **Inventory (no writes):** enumerate every `backlog.md` entry as open or
   resolved; confirm each resolved stub's unchanged history exists in
   `archive/2026-08.md` (add verbatim where missing); record exact before-counts
   of open/resolved/duplicate/broken-link findings.
2. **De-stub and de-duplicate:** delete resolved stubs after archive
   confirmation; renumber the open insights-flow item `BUG-E2E-003` →
   `BUG-E2E-004` (the resolved stub's history keeps the old ID in the archive);
   fold `CR-055-02` into `CR-053-13` (its own text defers to the CR-053-13
   regeneration pass).
3. **Restructure** open entries into the five sections fixed by CS-PLAN-058
   §2.2 and add the standardized metadata block to every open entry.
4. **Lock in** the invariants with `scripts/check-backlog.js`
   (`npm run backlog:check`), following the established `check-docs-encoding.js`
   pattern including `--self-test`.
5. **Record the baseline** (route count, migration chain, skip inventory, E2E
   project split, core journey specs) date-stamped in the existing engineering
   docs — no new doc file.

The mapping table in §3 makes placement deterministic; CS-PLAN-058's per-WP
"Backlog ownership" lines are authoritative wherever this table and the
masterplan could disagree.

## 3. Backend / Frontend Changes

No runtime code changes (`lib/`, `src/` untouched). All changes are
documentation, backlog structure, and one Node checker script.

### 3.1 New `backlog.md` section layout and entry mapping

The historical review sections are replaced by the CS-PLAN-058 §2.2 sections.
Placement of the current open items:

| New section | Entries moved in | Notes |
|---|---|---|
| Core Workflow Program | CR-WF-01..08; CR-053-07/09 (WP1), CR-053-19 persistence parts (WP1), CR-055-03/04 (WP1), CR-SVC-15/40/41/44 (WP1 CR-SVC-40 foundation portion, WP5 completion constraints, WP8 structural remainders), CR-053-16/17/18 core remainders + FEAT-UX-015 owner-transfer semantics (WP2), CR-053-20 undo/cache portions (WP5), UX-015 (WP5), CR-055-01 (WP6), CR-053-20 remaining core parts (WP7) | CR-WF umbrellas stay the acceptance-criteria owners; older findings are annotated `Owned by: CS-PLAN-058 WPx` (or `Folded-in`) instead of duplicating criteria; CR-WF-03 alone carries `Owning: WP3+WP4` |
| Core Hardening | CR-053-11, CR-053-13 (with CR-055-02 folded in), BUG-E2E-004 (renumbered), CR-053-22 | Each states which WP it blocks (CR-053-13 → WP7 i18n acceptance; BUG-E2E-004 + CR-053-22 → WP9 deterministic coverage; CR-053-11 is referenced by no WP and stays `unassigned` with `Blocks: —` per §3.2) |
| Deferred Add-ons | FEAT-UX-018/019/023..029, UX-017 | Explicitly outside the core critical path (CS-PLAN-058 §1.3) |
| Infrastructure and Documentation | TECH-DOCS-003 (this plan), CR-053-21 remainder (README rewrite, docs truth; testing.md slice owned by WP9), TECH-LINT-006, BUG-SEC-001, SEC-AUDIT-001 | Independently schedulable; SEC-AUDIT-001/BUG-SEC-001 annotate `Blocks: RELEASE-002` |
| Release | RELEASE-002, RELEASE-003 | RELEASE-002 lists the exact blocking gates: CS-PLAN-058 WP1..WP9 exit gates + final Full review PASS |

### 3.2 Standardized metadata block (every open entry)

```markdown
### [P1] CR-WF-01 — Enforce approved workflow authorization boundaries
**Status**: Backlog · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: authorization
**Owning**: CS-PLAN-058 WP2 · **Depends on**: — · **Blocks**: CR-WF-03..08 · **Folded-in**: —
**Source**: Full Review of the core task workflow (2026-09-02)
```

All fields are required; `—` is the explicit empty value. `Owning` is a work
package (`CS-PLAN-058 WPx`), a plan ID, or `unassigned` (Core Hardening /
Deferred Add-ons entries that block nothing may stay `unassigned` only with a
`Blocks: —` line). Existing free-form status prose (e.g. CR-053-11's partial
closures) is kept as body text below the metadata block.

### 3.3 Checker — `scripts/check-backlog.js`

Node script following `check-docs-encoding.js` conventions (git-tracked file
enumeration, `--self-test` mode, exit 1 with file:line findings). Checks:

1. **Open items only:** no `Status: Resolved|Closed` entry remains in
   `backlog.md`.
2. **No duplicate IDs:** every `### [P*] ID — Title` ID appears at most once in
   `backlog.md`.
3. **Required metadata:** every open entry has Status, Effort, Layer, Journey
   stage, Owning, Depends on, Blocks, Folded-in, Source (the two metadata lines
   from §3.2).
4. **Resolvable dependency path:** every `Depends on` / `Blocks` / `Folded-in`
   ID reference and every `Owning: CS-PLAN-058 WPx` resolves to an existing open
   ID or a WP0..WP9 token — this makes the CR-WF → RELEASE-002 path
   machine-checkable (CS-PLAN-058 WP0 exit gate).
5. **Tracked links resolve:** every relative Markdown link in
   `docs/backlog/*.md` and `docs/plans/**/*.md` points to an existing file
   (links resolve relative to the containing file and must stay inside the
   repo root; external URLs and `#anchor` fragments are not validated).

`package.json` gains `"backlog:check": "node scripts/check-backlog.js"`. The
command is added to the CS-PLAN-058 §4.5 quality gate (masterplan update in this
package, ratified by this plan's reviews).

### 3.4 Stale link repairs (beyond the checker's detection)

- `backlog.md` CR-WF header and `ACTIVE_TASKS.md`: CS-PLAN-058 path
  `../plans/new/…` → `../plans/active/…` (already applied at plan-058 activation;
  re-verified here).
- Six link occurrences (four distinct files) of
  `../plans/done/cs-plan-025/029/036/037-*.md` in the `lib/Service` review
  section's preamble and resolution blockquotes → replaced by their existing
  `archive/2026-08.md` completion records. (Verified 2026-09-02: these are the
  only broken tracked links in the checker's scope.)

### 3.5 Baseline recording (date-stamped 2026-09)

- `docs/api/README.md`: verify the documented route count equals the live
  `appinfo/routes.php` count (71 expected); correct only if drifted.
- `docs/engineering/development.md`: migration-chain inventory (file list,
  Version1000→1028, note that 1017–1020 are already folded per pre-release
  policy).
- `docs/engineering/testing.md`: PHPUnit unit/integration skip inventory
  (counts + reason classes, incl. the skipped live-authorization scenarios and
  CR-053-22 approved deferrals), Playwright project split with each project's
  testMatch/testIgnore semantics, and the current core-journey spec list
  including `gui-scratch-flow.spec.ts` (to be replaced by WP9).

## 4. Tests

- **Script self-test (the regression test for this tool, per repo convention
  for `scripts/check-*`):** `node scripts/check-backlog.js --self-test` proves
  with fixture buffers that each check rejects its violation class (resolved
  stub, duplicate ID, missing metadata field, dangling dependency reference,
  broken link) and accepts a clean fixture.
- **Gate run:** `npm run backlog:check` exits 0 against the restructured
  backlog/plans tree.
- **No runtime tests are added or changed** — `lib/` and `src/` are untouched;
  `npm test`, `npm run lint`, and `npm run docs:encoding` must stay green to
  prove no accidental breakage. Docker PHPUnit/Playwright are not applicable to
  a docs-only diff (CS-PLAN-058 §2.3 "full applicable quality gate"); the
  Standard B reviewer may re-run the full gate at discretion.

## 5. File Manifest

```
new:      scripts/check-backlog.js
new:      docs/plans/new/cs-plan-059-wp0-backlog-baseline.md (this plan)
modified: docs/backlog/backlog.md (de-stub, renumber BUG-E2E-003→004, fold CR-055-02,
          five-section restructure, metadata blocks, RELEASE-002 blocking list,
          stale link repairs)
modified: docs/backlog/ACTIVE_TASKS.md (WP0 coordination entry, 058 path fix,
          rolling-window housekeeping)
modified: docs/backlog/README.md (document the five sections + metadata format
          as the new item-format convention)
modified: docs/backlog/archive/2026-08.md (add any resolved histories found
          missing during inventory; otherwise untouched)
new:      docs/backlog/archive/2026-09.md (WP0 completion entries: TECH-DOCS-003,
          CR-053-21 metadata slice, CR-055-02 fold)
modified: docs/engineering/development.md (migration-chain baseline)
modified: docs/engineering/testing.md (skip inventory, E2E project split, core
          journey specs)
modified: docs/api/README.md (route-count verification; only corrected if drifted)
modified: package.json (backlog:check script)
modified: docs/plans/active/cs-plan-058-core-workflow-bottom-up-masterplan.md
          (§9 progress table WP0, §4.5 gate + backlog:check)
```

## 6. Implementation Order

1. Inventory pass (read-only): exact open/resolved counts, duplicate-ID list,
   broken-link list, archive-presence confirmation per resolved stub.
2. Archive-first cleanup: complete missing archive histories, delete resolved
   stubs, renumber BUG-E2E-003 → BUG-E2E-004, fold CR-055-02 into CR-053-13.
3. Restructure into the five sections with metadata blocks and `Owned by`
   annotations per §3.1/§3.2; update `docs/backlog/README.md`.
4. Implement `scripts/check-backlog.js` (+ self-test) and `backlog:check`;
   iterate until green on the restructured tree; repair stale links per §3.4.
5. Record the dated baseline in `docs/api/README.md`,
   `docs/engineering/development.md`, `docs/engineering/testing.md`.
6. Update CS-PLAN-058 §9 (WP0 → Done pending review) and §4.5; update
   `ACTIVE_TASKS.md`; archive TECH-DOCS-003 and the CR-053-21 metadata slice.
7. Run the applicable gate (§4) and request the Standard B review.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Restructure silently drops or duplicates an open item | Medium | Before/after ID inventory diff as a reviewed artifact; checker checks 1–2; exact counts recorded in step 1 |
| Placement ambiguity (Core Hardening vs Deferred Add-ons) | Medium | §3.1 mapping table ratified in the Standard A review; CS-PLAN-058 ownership lines are authoritative on conflict |
| Checker false positives block later packages' gates | Medium | Narrow scope (docs/backlog + docs/plans, no anchor validation), `—` empty values allowed, self-test fixtures for accept and reject paths |
| Baseline numbers drift while later WPs land | High | Baseline is date-stamped point-in-time; later packages update it as part of their own doc integration |
| Metadata format churn breaks the checker | Low | Format frozen by this plan's review; changes need a plan update (same rule as the CS-PLAN-058 manifest) |
| Backlog edits collide with concurrent work | Low | Program governance allows exactly one In-Progress package; WP0 is first |

## 8. Acceptance Criteria

- [ ] `backlog.md` contains only genuinely open items, structured into the five
      CS-PLAN-058 §2.2 sections; every resolved stub's history is confirmed in
      the monthly archive before deletion.
- [ ] No duplicate open IDs; the insights-flow item is renumbered BUG-E2E-004.
- [ ] Every open entry carries the standardized metadata block (all nine
      fields, `—` for empty); CR-SVC-40/41/44 gain explicit Status lines.
- [ ] CR-WF-01..08 are the sole core umbrellas with `Owned by`/`Folded-in`
      annotations instead of duplicated acceptance criteria; CR-WF-03 documents
      its WP3+WP4 split; RELEASE-002 lists its exact blocking work packages and
      gates.
- [ ] `npm run backlog:check` is green, its `--self-test` passes, and the
      command is part of the CS-PLAN-058 §4.5 quality gate.
- [ ] All relative Markdown links in `docs/backlog/*.md` and
      `docs/plans/**/*.md` resolve; the stale `done/`-plan and 058-`new/`
      references are repaired.
- [ ] Dated baseline recorded: route count (api/README), migration chain
      (development.md), unit/integration skip inventory, E2E project split, and
      core journey specs (testing.md).
- [ ] TECH-DOCS-003 archived as completed; CR-053-21 metadata portion closed
      with its remainder kept open under Infrastructure and Documentation.
- [ ] CS-PLAN-058 §9 progress table and `ACTIVE_TASKS.md` updated in the same
      package.
- [ ] Applicable gate green (`npm run lint`, `npm test`, `npm run
      docs:encoding`, `npm run backlog:check`, `git diff --check`) with no new
      or unapproved test skips.
- [ ] All implementation decisions and review questions resolved and recorded.
- [ ] Durable outcomes integrated into permanent docs (backlog README format
      convention, engineering baselines) — plans are temporary.
- [ ] Final Standard B review is `PASS` or `PASS-WITH-FIXES`.

## 9. Plan Review Decision Register

| ID | Proposed decision | Rationale |
|----|-------------------|-----------|
| D1 | Renumber the open insights-flow item to BUG-E2E-004 instead of reusing BUG-E2E-003 | IDs must be unique across open and archived history; the archived resolution keeps the old ID |
| D2 | Fold CR-055-02 into CR-053-13 and archive the ID | CR-055-02's own text defers its fix to the CR-053-13 regeneration pass — a pointer without a live remainder |
| D3 | New dedicated `scripts/check-backlog.js` instead of extending `check-docs-encoding.js` | Encoding and structural backlog checks are disjoint concerns; the script follows the established self-test pattern |
| D4 | Link check scoped to `docs/backlog/*.md` + `docs/plans/**/*.md`, no anchor validation | Full-tree link checking risks false positives on examples/anchors; the broken classes observed live in these two directories |
| D5 | Baseline recorded in existing docs (api/README, development.md, testing.md), no new baseline file | KISS — the facts belong next to their existing topics; avoids a doc that would duplicate and drift |
| D6 | `backlog:check` added to the CS-PLAN-058 §4.5 gate as part of this package | The WP0 exit gate requires the dependency path to stay machine-checkable in all later packages |

## 10. Review Outcome

**Standard A plan review — 2026-09-02: GO-WITH-CHANGES (all changes applied
inline; no open findings).**

- Reviewed against the review skill §3 shared checklist plus plan-only checks.
  Factual claims verified against the live tree: backlog.md at 821 lines with
  46 resolved/closed stubs and 45 open items; duplicate BUG-E2E-003; missing
  Status lines on CR-SVC-40/41/44 and the open CR-053/CR-055 entries; 22-file
  migration chain Version1000→1028; 71 live routes matching docs/api/README.md
  (no drift); Playwright projects `chromium`/`chromium-serial-db`/
  `chromium-scheduling-owner`; `check-docs-encoding.js --self-test` pattern;
  archive/2026-08.md histories present for spot-checked resolved stubs; and the
  six broken `plans/done/` link occurrences as the only broken tracked links in
  the checker's scope.
- Decisions D1–D6 ratified without revision. D1 additionally verified: the only
  non-backlog BUG-E2E-003 reference (`tests/e2e/title-selection.spec.ts:93`)
  belongs to the resolved stub, which keeps the old ID in the archive.
- Inline fixes: §3.1 mapping completed with CR-SVC-15/40/41/44 (ownership per
  CS-PLAN-058 WP1/WP5/WP8) and CR-053-11's `unassigned`/`Blocks: —` handling;
  migration count corrected 23 → 22; §3.4 corrected to six link occurrences in
  the `lib/Service` section; §5 manifest `archive/2026-09.md` reclassified
  modified → new; §1 Status-line-gap wording and §3.3 check-5 scoping wording
  clarified.
- No open findings. Recommended next state: `active/`; the move remains a
  manual user action per project-rules §7.
