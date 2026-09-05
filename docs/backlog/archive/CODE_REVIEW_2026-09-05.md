# Code Review: CS-PLAN-062 WP3 — Atomic House Setup and Structure (final Standard B)

**Review Date**: 2026-09-05
**Reviewer**: Kilo (main agent, review skill Standard B)
**Mode**: Standard Batch Review (final — owning plan verified whole)
**Scope**: Full uncommitted WP3 package (29 tracked modified files + 2 untracked new test specs) + owning plan `docs/plans/active/cs-plan-062-wp3-atomic-house-setup-structure.md`
**Base Commit**: `861b3963c3cdbf7ab1a74180660ea67662da0cde` (branch `master`, nothing staged)

---

## Executive Summary

### Outcome
- PASS-WITH-FIXES — plan moved `active/ → done/`

### What Was Reviewed
- Whole WP3 package: transactional first-run gate (house-row `findForUpdate`
  lock → in-transaction floor-count recheck → `ConflictException` with the
  unchanged 409 `HOUSE_ALREADY_SET_UP` envelope; controller pre-check deleted —
  single gate owner), `houseName` folded into the setup command (validated
  pre-write, trimmed, persisted on the locked row, echoed), under-transaction
  membership revalidation, wizard result panel + per-recipient invite feedback
  (D4/D5, dead `exists` branch removed), the a11y modal pass (focus trap, Esc,
  no backdrop discard — D8), the `emptyhouse` E2E profile and serial-pool
  journey (WP3 exit gate), and both durable-doc updates
- Owning plan: full §5 file manifest (entries verified; three reviewed paths
  added to the manifest), all 14 §8 acceptance criteria, §4 test evidence,
  knowledge integration (API README, setup concept, locking note), D1–D8

### What Was Fixed Immediately
- `SetupWizard.apply()` now calls `setupStore.clearError()` on the recoverable
  raced-409 path, so the explicit "already set up — continue" panel renders
  alone instead of duplicating the message as a red `wizard-error` line
  (F2; Jest 409 test extended to pin the absence; affected gates rerun)
- §5 File Manifest synced with `lib/Db/TransactionExecutor.php`,
  `src/views/Dashboard.vue`, `tests/integration/Controller/TestControllerTest.php`

### Main Risks Remaining
- None introduced. Pre-existing test-determinism remainders stay under
  BUG-E2E-005 / CR-WF-05 (WP9); the `houseName` type-strictness nit is logged
  as CR-062-01 (WP4); the out-of-scope stylelint warning as TECH-LINT-007.

## Scope Fingerprint (capture before review)

- Branch `master` @ `861b3963`; index empty (staged diff: none).
- Unstaged diff (binary) SHA-256
  `F689746C88A34D495589215C780DD5AE3434A85B825A60D5B089FB664C3B870D`
  (115,639 bytes) — before the review-own F2 fix.
- Untracked: `tests/e2e/setup-wizard-journey.spec.ts`
  `7E1BB0BEF155D1164D39973A9C6A8418D899C341B70B33D192AA5B06B572863B`,
  `tests/integration/Setup/SetupApplyConcurrencyTest.php`
  `B7B62F574335DA7AA14A6F0E3601DE0CE3520AC0A33C8658947A2A8A10E8D025`.
- Every captured path maps to the plan manifest or a documented deviation;
  no unrelated user/agent changes present. The only post-capture drift is the
  review's own F2 fix (`src/components/Setup/SetupWizard.vue`,
  `tests/unit/components/SetupWizard.spec.js`).

## Findings

| # | Severity | Location | Issue | Status |
|---|----------|----------|-------|--------|
| F1 | minor (docs) | plan §5 | Manifest omitted `TransactionExecutor.php`, `TestControllerTest.php`, `Dashboard.vue` | fixed (manifest synced) |
| F2 | minor (behavior) | `src/components/Setup/SetupWizard.vue` apply() 409 branch | Store `error` left set → `wizard-error` line duplicated the recoverable already-set-up panel ("instead of a generic error", §3.5) | fixed inline (user-ratified) + Jest pin |
| F3 | nit (validation) | `HouseSetupService::validateHouseName` | Non-string `houseName` silently treated as absent (200, keeps current name) instead of 400 | open → logged CR-062-01 (WP4) |
| F4 | nit (lint, pre-existing) | `src/components/Common/NotAuthorizedState.vue:73` | stylelint `csstools/use-logical` warning (`margin-left`) outside reviewed scope | open → logged TECH-LINT-007 |

Architecture / authorization / security tracks: no findings —
`@NoAdminRequired` only, owner gate pre-transaction, membership revalidated
under the lock, prepared `COUNT` primitive, no SQL concatenation, no secrets
or emails logged (invite failures log count only, per-row reasons stay UI),
no `v-html`, all strings via `t()` with `l10n/` in sync.

## Gates (all rerun by this review; green after the F2 fix)

- Jest: **813 passed** (813→813, F2 test extended in place); ESLint clean;
  `test:noise` clean; `l10n:check-keys`, `docs:encoding`, `backlog:check`
  green; `git diff --check` clean; webpack production build clean.
- Docker unit (fresh env, `down -v` + `up -d`): **822 OK**;
  `php-cs-fixer --dry-run`: 0 of 270 fixable.
- Docker integration: **270 OK, 10 skipped** — exactly the pre-existing
  documented skips, no new skips (includes the new MySQL-only
  `SetupApplyConcurrencyTest` race + zero-write + controller matrix).
- Playwright `chromium`: **92 passed**, 7 documented skips.
- Playwright `chromium-serial-db`: **94 passed**, 4 documented skips;
  WP3 surfaces (`setup-wizard-journey` + `release-setup-wizard`) 7/7 green
  twice (also after the F2 bundle rebuild). The sole pool-run failure
  `room-management.spec.ts:179` is the documented pre-existing load flake —
  **10/10 in isolation**; `insights-flow:121` (the earlier deterministic
  date-sensitive failure) passed on this run.

## Questions / Decisions

- One batched structured request (2026-09-05): F2 disposition → user chose
  the inline safe fix (recommended); F3 disposition → user chose logging the
  backlog nit for WP4 (recommended). Answers applied/rerun before finalizing;
  D1–D8 verified as implemented as ratified at Standard A.

## Disposition

- `docs/plans/active/cs-plan-062-….md` → `docs/plans/done/` (final review
  passed, all acceptance criteria green).
- Backlog: CR-WF-03 setup/structure criterion + CR-WF-05 empty-house
  criterion already checked/annotated by the implementation; umbrellas stay
  open (halves owned by WP4/WP9 → no archive move); **new open items**:
  CR-062-01 (WP4), TECH-LINT-007 (unassigned); BUG-E2E-005 update extended
  with the full pre-existing flake inventory and the re-run observations;
  CR-SVC-44 `hasFloors` note resolved (annotated by implementation).
- `ACTIVE_TASKS.md`: CS-PLAN-062 rolled to Recently Completed (one line);
  oldest entry beyond the rolling cap (CS-PLAN-054, already recorded verbatim
  in [`2026-08.md`](2026-08.md)) dropped from the window.
- CS-PLAN-058 §9 WP3 row → Done (2026-09-05).
