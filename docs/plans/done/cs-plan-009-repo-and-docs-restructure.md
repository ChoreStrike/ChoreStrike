# CS-PLAN-009: Repo-root & documentation structure cleanup

> **Status:** Done (implemented 2026-07-23)
> **Effort:** M
> **Priority:** P3
> **Depends on:** none
> **Related issue:** —

## 1. Problem Summary

The repository root and `docs/` are cluttered and inconsistent:

- **Root has ~25 files**, mixing genuine standards, tooling configs, a redundant PHPUnit template (`phpunit.xml.dist`), and a duplicate.
- **`docs/` has 14 CAPITALS markdown files** plus **6 more in `docs/concepts/`**. The agreed convention is: *only standard files (`README`, `LICENSE`, `CHANGELOG`, `AGENTS`, `CONTRIBUTING`, `CODE_OF_CONDUCT`, `SECURITY`) stay in CAPITALS*; everything else uses kebab-case.
- **`MANUAL_TEST_CHECKLIST.md` is duplicated**: root (4.8 KB) and `docs/` (6.3 KB), not identical — the root copy is stale.
- **`docs/backlog/` mixes live and historical content**: 3× `CODE_REVIEW_*.md`, `ALPHA_TEST_RESULTS_2026-03-22.md`, and 13× `phase-*.md`, several already shipped.
- **Stray build/test artifacts** live locally at the root (`coverage/`, `playwright-report/`, `test-results/`, `test-results*.json`, `.phpunit*.cache`, and a mysterious `line/` folder) — all already gitignored, but polluting the working tree.
- The CAPITALS doc files are **referenced 100+ times** across the repo (incl. historical `plans/done/`), so renames must be coordinated with a full link sweep.

## 2. Strategy

Execute as a sequence of **small, independently reviewable commits** grouped by risk:

1. Local-only cleanup of artifacts (no commit).
2. Resolve the root duplicate.
3. Archive historical backlog snapshots.
4. Slim down root tooling (remove redundant `phpunit.xml.dist`; keep `docker-compose.test.yml` and PHPUnit configs in root — they are PHP/Docker convention and deeply wired into CI).
5. Rename the 20 non-standard CAPITALS docs to kebab-case and **sweep all live references** (skip historical `plans/done/`).
6. Harden `.gitignore` + `playwright.config.ts`.
7. Verify via build gate + link check.

CAPITALS kept at root: `README.md`, `LICENSE`, `CHANGELOG.md`, `AGENTS.md`, `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`. In `docs/` only `README.md` stays CAPITALS. `THIRD_PARTY.md` → `NOTICES.md` (optional decision).

Historical `docs/plans/done/*.md` are **frozen snapshots** — their references to old names are intentionally left as-is (rewriting history would be worse than a few stale internal links in archived plans).

## 3. Backend / Frontend Changes

None. This plan is **docs + repository structure only**. No `lib/`, `src/`, migrations, or runtime behavior is touched. `appinfo/`, `l10n/` unaffected.

## 4. Tests

- **No new code tests** (no behavior change).
- **Link-integrity check** after Phase 5: a grep/read sweep confirming every live markdown link to a renamed file resolves to the new path. Document the kebab-case doc convention in `docs/standards/guidelines/documentation.md`; a dedicated lint script in `scripts/` is out of scope unless requested.
- **Build gate green** as regression proof: `npm test` + `npm run lint` + Docker backend tests (PHPUnit).

## 5. File Manifest

```
# Phase 2 — duplicate resolution
deleted:  MANUAL_TEST_CHECKLIST.md                       # root copy (content merged into docs/ version first)
modified: docs/MANUAL_TEST_CHECKLIST.md                  # merged content

# Phase 3 — backlog archival (tracked files: git rm; local copy -> archive/)
archived: docs/backlog/CODE_REVIEW_2026-03-07.md   -> archive/2026-03/code-reviews/
archived: docs/backlog/CODE_REVIEW_2026-03-11.md   -> archive/2026-03/code-reviews/
archived: docs/backlog/CODE_REVIEW_2026-03-14.md   -> archive/2026-03/code-reviews/
archived: docs/backlog/ALPHA_TEST_RESULTS_2026-03-22.md -> archive/2026-03/
# shipped phase plans -> docs/plans/done/  (per-item, only those confirmed shipped)

# Phase 4 — root tooling cleanup (minimal, low-risk)
deleted:  phpunit.xml.dist                      # redundant: phpunit.xml is tracked & active (composer.json refs it)
# NOTE: docker-compose.test.yml STAYS in root (CI + scripts reference it; internal relative paths
#       use context: . and ./docker/... — moving would break .forgejo/workflows/release-gate.yml:141,
#       scripts/run-docker-tests.sh, and the compose file itself).
# NOTE: phpunit.xml, phpunit.unit.xml, phpunit.integration.xml STAY in root (PHP convention;
#       referenced by composer.json, .forgejo/workflows/release-gate.yml:122,141, test.yml:37).
# PRE-EXISTING BUG (out of scope): scripts/run-tests.sh cd's to scripts/ then refs
#       docker-compose.test.yml (at root) — already broken before this plan.

# Phase 5 — CAPITALS -> kebab-case (docs/)
moved:    docs/ARCHITECTURE.md        -> docs/engineering/architecture.md
moved:    docs/DEVELOPMENT.md         -> docs/engineering/development.md
moved:    docs/TESTING.md             -> docs/engineering/testing.md
moved:    docs/DEPLOYMENT.md          -> docs/engineering/deployment.md
moved:    docs/REVIEW_PLAYBOOK.md     -> docs/engineering/review-playbook.md
moved:    docs/API.md                 -> docs/api/README.md
moved:    docs/ROADMAP.md             -> docs/release/roadmap.md
moved:    docs/RELEASE_STRATEGY.md    -> docs/release/release-strategy.md
moved:    docs/REALTIME_UPDATES.md    -> docs/operations/realtime-updates.md
moved:    docs/I18N_WORKFLOW.md       -> docs/standards/i18n-workflow.md
moved:    docs/MANUAL_TEST_CHECKLIST.md -> docs/testing/manual-test-checklist.md
moved:    docs/ALPHA_TEST_GUIDE.md    -> docs/testing/alpha-test-guide.md
moved:    docs/FAMILY_TEST_SETUP.md   -> docs/testing/family-test-setup.md
renamed:  docs/testing/CRITICAL_TESTS.md -> docs/testing/critical-tests.md  (in-place rename)
# Phase 5 — CAPITALS -> kebab-case (docs/concepts/)
moved:    docs/concepts/PRODUCT_VISION.md          -> docs/concepts/product-vision.md
moved:    docs/concepts/DESIGN.md                  -> docs/concepts/design.md
moved:    docs/concepts/GAMIFICATION_AND_REWARDS.md -> docs/concepts/gamification-and-rewards.md
moved:    docs/concepts/ENERGY_AND_BALANCING.md    -> docs/concepts/energy-and-balancing.md
moved:    docs/concepts/TASK_MODEL_AND_SYNC.md     -> docs/concepts/task-model-and-sync.md
moved:    docs/concepts/I18N_AND_CONTENT_POLICY.md -> docs/concepts/i18n-and-content-policy.md
# Phase 5 — live reference sweep (NOT plans/done/*)
modified: AGENTS.md
modified: README.md
modified: docs/README.md
modified: docs/engineering/review-playbook.md   (was REVIEW_PLAYBOOK.md)
modified: docs/engineering/architecture.md       (internal tree + cross-links)
modified: docs/engineering/development.md
modified: docs/engineering/deployment.md
modified: docs/operations/realtime-updates.md
modified: docs/standards/i18n-workflow.md
modified: docs/standards/guidelines/i18n.md
modified: docs/standards/ai/skills/*           (architecture, testing, review, i18n, documentation, docker-dev SKILL.md)
modified: docs/standards/ai/rules/project-rules.md
modified: docs/backlog/README.md
modified: docs/backlog/ACTIVE_TASKS.md
modified: docs/backlog/phase-*.md              (live phases only)
modified: CHANGELOG.md                         (add entry for this restructuring)

# Phase 5 — optional
moved:    THIRD_PARTY.md   -> NOTICES.md   (decision)

# Phase 6 — tooling hardening
modified: .gitignore                          (ensure playwright-report/, /blob-report/, /junit.xml present)
modified: playwright.config.ts                (explicit outputDir: 'test-results/')
```

## 6. Implementation Order

> One commit per step (except step 1). Each step is independently revertable.

1. **Local artifact cleanup (no commit).** Delete `coverage/`, `playwright-report/`, `test-results/`, `test-results.json`, `test-results-summary.json`, `.phpunit.result.cache`, `.phpunit.integration.result.cache`, and `line/`. All gitignored — nothing leaves the repo.
2. **Duplicate resolution.** Diff root vs `docs/MANUAL_TEST_CHECKLIST.md`; merge any unique root content into the docs copy; `git rm` the root file. → commit `docs: remove duplicate root MANUAL_TEST_CHECKLIST.md`.
3. **Backlog archival.** Move historical `CODE_REVIEW_*.md` + `ALPHA_TEST_RESULTS_*.md` to `archive/2026-03/` (copy local + `git rm` tracked). Move shipped `phase-*.md` to `docs/plans/done/`. → commit `docs: archive historical backlog snapshots`.
4. **Root tooling cleanup.** Delete redundant `phpunit.xml.dist` (the active `phpunit.xml` is tracked and referenced by `composer.json`). Keep `docker-compose.test.yml` and the three PHPUnit configs in root (PHP/Docker convention; deeply wired into CI workflows and scripts). → commit `chore: remove redundant phpunit.xml.dist`.
5. **CAPITALS → kebab-case.** Split across three commits for reviewability:
   - 5a: rename the 20 files (`git mv`) + create new subfolders. → commit `docs: rename non-standard CAPITALS docs to kebab-case`.
   - 5b: update live references in `AGENTS.md`, `README.md`, `docs/README.md`, skill files, guidelines, `project-rules.md`. → commit `docs: update bootstrap/skill references after rename`.
   - 5c: update references in `docs/backlog/*`, `REVIEW_PLAYBOOK`, `ARCHITECTURE` tree, and CHANGELOG entry. → commit `docs: finish link sweep and changelog`.
6. **Tooling hardening.** Extend `.gitignore`, set Playwright `outputDir`. → commit `chore: harden gitignore and playwright outputDir`.
7. **Verify.** Run full build gate + grep link-integrity sweep; fix any remaining broken links.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| 100+ stale links to old CAPITALS names after rename | High | Phases 5b/5c are dedicated link sweeps; Phase 7 runs a grep-based integrity check before close. |
| Renaming a doc breaks a relative link inside a historical `plans/done/` file | Medium | **Explicit decision: do not edit `plans/done/`** — they are frozen historical snapshots. Document this so reviewers accept residual old-name refs there. |
| `git mv` history-split on large docs | Low | Use `git mv` (preserves rename detection); one logical rename per commit. |
| CI workflows (`.forgejo/workflows/`) reference moved/renamed files | Medium | CI only references PHPUnit configs + `docker-compose.test.yml` — all stay in root. Doc renames don't affect CI. Verify in Phase 7. |
| `I18N_WORKFLOW.md` move affects `scripts/check-l10n-keys.js` docs or npm script | Low | Script references are by code path, not doc path; double-check in Phase 5c. |
| Loss of unique content from the duplicate checklist | Medium | Phase 2 diffs both files and merges before deleting; review the diff. |
| Scope creep (renaming internal headers/anchors) | Medium | Plan only renames **files**, not in-document headings or anchors, to keep diffs minimal. |
| `scripts/run-tests.sh` already broken (pre-existing) | Low | Out of scope; log as backlog item if not already tracked. |

## 8. Acceptance Criteria

- [x] Root contains no duplicate `MANUAL_TEST_CHECKLIST.md`.
- [x] No local build/test artifacts remain at repo root (`coverage/`, `test-results*`, `.phpunit*.cache`, `line/`).
- [x] Historical backlog snapshots (`CODE_REVIEW_*`, `ALPHA_TEST_RESULTS_*`) archived; shipped phases in `docs/plans/done/`.
- [x] Redundant `phpunit.xml.dist` removed; `phpunit.xml`, `phpunit.unit.xml`, `phpunit.integration.xml` remain in root (CI-compatible).
- [x] `docker-compose.test.yml` remains in root (CI + scripts reference it).
- [x] Only the agreed standard set is in CAPITALS at root and in `docs/` (`README.md` only in `docs/`; `README.md`/`LICENSE`/`CHANGELOG.md`/`AGENTS.md`/`CONTRIBUTING.md`/`CODE_OF_CONDUCT.md`/`SECURITY.md` at root). `THIRD_PARTY.md` stays CAPITALS (optional rename, not acted); `GAP_ANALYSIS_REWARD_SYSTEM.md` is backlog content (out of scope).
- [x] All 20 non-standard CAPITALS docs renamed to kebab-case and relocated into thematic subfolders.
- [x] All **live** references (AGENTS.md, README.md, docs/README.md, skills, guidelines, project-rules, live backlog) point to new paths. Full-tree scan of 79 `.md` files confirmed **0 broken relative links**.
- [x] `docs/plans/done/` left untouched (frozen). Shipped phase files (`phase-5/6/11`) added to `done/`; existing `cs-plan-*.md` not edited.
- [x] CI workflows (`.forgejo/workflows/`) unaffected (no path changes to their referenced files).
- [x] Build gate green: ESLint 0 errors (69 pre-existing JSDoc warnings), Jest 525/525. Backend PHPUnit not run (zero app-code changes; CI references only untouched configs).
- [x] Link-integrity scan finds no broken relative links (79 `.md` files scanned, 4 regressions from directory moves caught and fixed in review pass).
- [x] `CHANGELOG.md` has an entry for the restructuring.
- [x] `docs/backlog/ACTIVE_TASKS.md` updated with this plan's start/finish + review pass notes.

## 9. Implementation Notes (post-implementation)

- **Phases 1–7 implemented** as a single uncommitted working tree, then verified with build gate + full-tree link-integrity scan (79 `.md` files, 0 broken links after fixes).
- **Phase 2 judgment:** root `MANUAL_TEST_CHECKLIST.md` (stale "Test Family" scenario, referenced removed Monsters + obsolete script) — no unique content worth merging; kept docs/ copy as canonical (also renamed to `testing/manual-test-checklist.md` in Phase 5).
- **Phase 3 judgment:** confirmed shipped/obsolete from phase-file status headers — `phase-5` (Done), `phase-6` (Complete), `phase-11` (Shipped by CS-PLAN-005) → `docs/plans/done/`; `phase-10` (Obsolete) → `archive/2026-07/phases/`. Remaining phase files (Planned/In Progress) left in `docs/backlog/`. Historical `ACTIVE_TASKS.md` "Recently Completed" task records and prior `CHANGELOG` entries intentionally retain old names as frozen history (no broken navigational links).
- **Phase 5 note:** in-place case-only renames (concepts/ + testing/CRITICAL_TESTS.md) done via two-step `git mv` for the case-insensitive filesystem.
- **Review pass:** full-tree relative-link scan found 4 regressions in `deployment.md` and `roadmap.md` (relative paths to non-renamed files broke after directory move). All 4 fixed. Re-scan: 0 broken links.
