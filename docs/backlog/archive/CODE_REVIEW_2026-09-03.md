# Code Review: CS-PLAN-060 WP1 — Schema, Entity, and Mapper Invariants (final Standard B)

**Review Date**: 2026-09-03
**Reviewer**: Kilo (main agent, review skill Standard B)
**Mode**: Standard Batch Review (final — owning plan verified whole)
**Scope**: Full uncommitted WP1 package + owning plan `docs/plans/active/cs-plan-060-wp1-schema-entity-mapper-invariants.md`
**Base Commit**: `49be3fed5dba86ed8b3b030c6397ebc366837f67` (branch `master`, nothing staged)

---

## Executive Summary

### Outcome
- PASS-WITH-FIXES — plan moved `active/ → done/`

### What Was Reviewed
- 77 tracked modified/deleted files + 9 untracked files (plan, `TransactionExecutor`, 7 new integration tests); migrations fold, bootstrap race safety, canonical visibility/assignee/recurrence, `TransactionExecutor` routing, house-scoped `findVisibleByHouse`, mapper minors, mechanical frontend contract-key removals, all test updates, backlog/docs dispositions
- Owning plan: full file manifest, all 12 acceptance criteria, required test evidence, durable-doc integration, decisions D1–D11

### What Was Fixed Immediately
1. Stale E2E consumers of removed payload keys → canonical `assignees` contract (`overdue-task-claiming`, `time-travel-overdue`, `multi-user-interactions`, `release-task-lifecycle`, `error-handling`, `database-fixtures.ts`); `TestDataService` family seed now enriches room tasks with the canonical `assignees` key (test tooling)
2. Stale durable docs contradicting the implemented contract: `docs/api/README.md` (`isPrivate` compat note, legacy `assigned_to_user_id` merge note, example keys) and `docs/engineering/architecture.md` schema inventory (folded columns removed, canonical assignee store noted)
3. `lib/Db/Task.php` formatting glitch (`*/` collapsed onto the method declaration line)
4. Stale Jest fixture keys in `TodayView.spec.js` and `roomTasks.spec.js`

### Main Risks Remaining
1. `docs/api/README.md` room-tasks example still shows pre-WP1 snake_case key style (pre-existing drift) — logged into CR-053-21
2. Rare InnoDB gap-lock/deadlock windows on first-insert races are theoretically possible beyond the proven two-connection scenarios (consistent with the established ADR-007 pattern; no defect demonstrated)

---

## Scope Fingerprint (capture before review)

- Staged diff: empty (`e69de29bb2d1d6434b8b29ae775ad8c2e48c5391`)
- Unstaged diff SHA-1: `874d90dd0f0a959072e1acb74983b6811dd45bc8`
- Untracked (hash → path):
  - `247cf16dbc14333f4fbd7493366948130293cece` docs/plans/active/cs-plan-060-wp1-schema-entity-mapper-invariants.md
  - `c2d645d63f949754829477598fe813777fc11728` lib/Db/TransactionExecutor.php
  - `3300dbdca2887178e48f73ef4ee600e56c038b00` tests/integration/Db/AssigneeRepresentationTest.php
  - `9347d47fea919b60909bd2ef76c1717146c49644` tests/integration/Db/BootstrapConcurrencyTest.php
  - `be0773781a645e604b8811483428f2631dbdb25e` tests/integration/Db/ChecklistToggleConcurrencyTest.php
  - `309417dc79b47fbd7186d2e278cc2f3d7ae9474d` tests/integration/Db/MapperMinorsRegressionTest.php
  - `a7544a075c8bf747216a992b7bddd8cd7ac80b7c` tests/integration/Db/RecurrenceOnceDeactivationTest.php
  - `22f0518892f2f085424f64c0001a3769bf11d789` tests/integration/Db/TaskMapperHouseScopeTest.php
  - `8af39fe45304cc8f240c1b1aa842235f01556238` tests/integration/Db/WeekWindowBoundaryTest.php

Post-review drift check: untracked hashes unchanged; unstaged-diff change is exactly the review's fix cluster; index still untouched before staging.

---

## Findings

| # | Severity | Location | Issue | Status |
|---|----------|----------|-------|--------|
| 1 | major | tests/e2e/overdue-task-claiming.spec.ts; time-travel-overdue.spec.ts; multi-user-interactions.spec.ts (+3 more) | Stale filters/Assertions on removed payload keys (`assignedToUserId`, `isPrivate`): claim/private gating tests silently skipped in `chromium`; two specs would fail in `chromium-serial-db` (not part of the implementation gate run) — violates the plan's own contract-grep acceptance check across `tests/` | Fixed |
| 2 | major | docs/api/README.md; docs/engineering/architecture.md | Durable docs still documented the removed `isPrivate` compat behavior and legacy `assigned_to_user_id` column/merge | Fixed |
| 3 | minor | lib/Db/Task.php | `*/` and `getPriorityLabel()` declaration collapsed onto one line | Fixed |
| 4 | minor | tests/unit/components/TodayView.spec.js; tests/unit/store/roomTasks.spec.js | Stale `assignedToUserId` fixture keys against the §4 assignees-only Jest contract | Fixed |
| 5 | nit | docs/api/README.md | Room-tasks example uses pre-WP1 snake_case key style (pre-existing drift, out of review scope) | Logged → CR-053-21 |

Security/auth checks: `@NoAdminRequired` surface unchanged; house-membership validation intact on every migrated writer (`replaceSingleAssignee` keeps the ownership/member checks; `findVisibleByHouse` narrows exposure); no secrets/PII in logs; no raw SQL beyond the established `*PREFIX*` locking-read pattern with bound parameters.

---

## Gates (rerun after fixes; all green)

| Gate | Result |
|------|--------|
| Jest (`npm test`) | 780/780 passed |
| ESLint (`npm run lint`) + stylelint | clean |
| PHPUnit unit (Docker) | 781 OK |
| PHPUnit integration (Docker, clean-volume install) | 229 OK, 21 pre-existing skips (matches baseline; no new skips) |
| php-cs-fixer (`cs:check`) | 0 of 265 files |
| Scoped `php -l` (changed files) | clean |
| `l10n:check-keys` / `backlog:check` / `docs:encoding` | green |
| Playwright `chromium` + `chromium-serial-db` | 181 passed, 0 failed (previously skipping claim-gating tests now actively run) |

Environment note: the first Playwright run failed in `auth.setup.ts` because the review brought the stack up without `TEST_DB_PROFILE=family` (minimal profile provisions no `teen` user). Clean re-create per docker-dev fixed it; not a code defect.

---

## Questions / Decisions

None — no behavior-changing, architectural, migration, or security authorization was required; all findings fell into the review skill's auto-fix classes (objectively stale docs/tests, formatting, mechanical contract-key migration). Plan decisions D1–D11 stand as ratified.

## Disposition

- Plan `cs-plan-060` → `done/` (Status: Done; acceptance criteria all ticked; Standard B Review Outcome appended)
- CS-PLAN-058 §9 WP1 row → Done with this review recorded
- Backlog: CR-053-21 extended with the api-README snake_case remainder; no other open follow-ups
- `ACTIVE_TASKS.md` updated (completed entry, In Progress cleared)
