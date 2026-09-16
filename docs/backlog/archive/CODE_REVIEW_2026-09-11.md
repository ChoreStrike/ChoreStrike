# Code Review: CS-PLAN-065 WP6 — Actionable Scheduling (final Standard B)

**Review Date**: 2026-09-11  
**Reviewer**: Kilo agent (Standard B final code review)  
**Mode**: Standard Batch Review (final Standard B over the whole owning plan)  
**Scope**: Complete WP6 implementation package — 45 modified + 6 new files matching the plan's §5 manifest boundary, captured as the uncommitted working tree on `master`  
**Base Commit**: `768a75758fcbb9310b331e2fdda8074cd6a27434` (Standard A activation)  
**Owning plan**: `docs/plans/active/cs-plan-065-wp6-actionable-scheduling.md` (at review time; now in [`done/`](../../plans/done/cs-plan-065-wp6-actionable-scheduling.md))

---

## Executive Summary

### Outcome
- NEEDS-FOLLOW-UP

### What Was Reviewed
- Backend aggregate: `SchedulePlanService` preview/apply (fingerprint, lock order `house → room_task → task → house_user`, stale rejection, rollback), `SchedulingController` contract, mapper primitives (`TaskMapper::lockForUpdate`, `CompletedTaskMapper` D11 queries, `RoomTaskMapper` tie-break), `RoomTaskService` daily eligibility + due-date seam hardening.
- Frontend: `SchedulePlanner` rewrite (state machine, a11y), `scheduling`/`roomTasks` stores (sequencing, reconciliation, D7 removal), views (owner gating, `schedule-applied` refetch), availability limit UI (D5).
- Tests vs the plan's §4.1 matrix; durable docs (ADR-012, api/README, concepts, engineering); the complete §4.2 quality gate.
- Concurrency: two-process `proc_open` races ran on the Docker MariaDB service.

### What Was Fixed Immediately
1. RF-065-03 (user-authorized): Apply input-edit gating — selection/start-date watchers now `markPlanStale()` per §3.1 rule 7, with 2 Jest regression specs.
2. Stylelint error: deprecated `clip` in `SchedulePlanner.vue` `.sr-only` → global `cs-visually-hidden` utility (+ `padding-inline-start` logical property).
3. `composer cs:check` ×2: unused `AppLogger` import (`SchedulePlanAggregateTest.php`), operator alignment (`SchedulePlanService.php`).
4. ADR-012 typo ("actional" → "actionable"); PHPDoc array-shape corrections.

### Main Risks Remaining
1. RF-065-01 (open): the E2E exit journey does not create tasks through the UI, verifies via member `mom` instead of a child session, and never exercises the owner-set child limit or role-cannot-apply/keyboard-mobile journeys.
2. RF-065-02 (open): component tests missing for the D5 limit UI, planner owner-gating, CalendarView `schedule-applied` refetch, and keyboard/mobile controls.

---

## Package Results

### Package 0: Scope and Diff Mapping
- Inputs reviewed: `git status` (45 modified, 6 untracked), full diffs for `appinfo/`, `lib/`, `src/`, `tests/`, `docs/`, `l10n/`, `playwright.config.ts`; fingerprint captured (staged empty-blob, unstaged binary diff `58846467…`, per-file untracked blob hashes — recorded in the plan's Review Outcome).
- Findings: scope unambiguous; working tree exactly matches the plan manifest boundary (untouched manifest files are upper-bound entries legitimately not needed).
- Outcome: clean.

### Package 1: Automated Static Quality Sweep
- Checks run: eslint, stylelint, `composer cs:check`, `docs:encoding`, `l10n:check-keys`, `git diff --check`.
- Findings: stylelint `property-no-deprecated` error + logical-property warning (both `SchedulePlanner.vue`); 2 cs:check violations; ADR-012 typo. The implementation record's gate list had omitted stylelint and `cs:check` — both were red on first run.
- Immediate fixes: all applied (see above); gates rerun green.

### Package 2: Wiring, Contracts, and Orphan Scan
- Inputs reviewed: route replacement (`scheduling#preview` on the suggestions URL, `scheduling#apply` added, route pin 70), D7 removal end-to-end (store, call-site, payload, mapper query, tests, docs), `filtered-daily` embedded assignees, request-order protection, `chorestrike:schedule-applied` wiring.
- Findings: no orphans; legacy taskCount contract rejected everywhere (unit + E2E + apiContract guard).
- Outcome: clean.

### Package 3: Focused Test Validation
- Tests run: complete gate — Jest 832; PHPUnit unit 908 / integration 315 (10 documented pre-existing GameLogic/RoomTask scenario skips; both two-process scheduling races ran on MariaDB); Playwright chromium (91 passed, 1 flaky-on-retry, 7 pool-excluded), chromium-serial-db (98, 5 skipped), chromium-scheduling-owner (35); build; test:noise.
- Results: green after the fix cluster (affected gates rerun).
- Gaps: RF-065-01 (E2E matrix), RF-065-02 (component matrix), RF-065-04 (race enumeration — resolved by user decision: lock-class coverage accepted).

### Package 4: Documentation and Backlog Sync
- Docs updated: ADR-012 typo; plan §9 findings + Review Outcome; ACTIVE_TASKS; `backlog.md` CR-WF-07 plan line; this record.
- Backlog updates: CR-WF-07/CR-055-01 remain open (plan stays `active/`; they archive only with the passing final review).

### Package 5: Final Triage and Handoff
- Final recommendation: NEEDS-FOLLOW-UP — one more round of this plan delivering RF-065-01/02, then the final Standard B rerun.
- Deferred items: RF-065-01, RF-065-02 (plan-owned, user-ratified "fix in next round"); accepted nits: duplicated default energy budget (WP8), controller numeric-id leniency (service-level strictness pinned by unit tests).
- Questions answered (batched, all as recommended): E2E gaps → next round; component gaps → next round; Apply gating → fix now; concurrency → accept + document.
- Plan transition: none — plan stays in `active/` (open findings block `done/` per rule 10).
- Scoped commit: one conventional commit containing the verified implementation package, review fixes, plan evidence, this record, and tracking updates (no implementation file is affected by an unresolved finding; the findings demand additional test files, to be delivered by the next round).

---

# Code Review: CS-PLAN-065 WP6 — Actionable Scheduling (final Standard B re-review, round 2)

**Review Date**: 2026-09-11 (same day, after the round-2 delivery)
**Reviewer**: Kilo agent (final Standard B re-review over the whole owning plan)
**Mode**: Standard Batch Review (final Standard B: round-2 changed-file set + whole-plan completion surface)
**Scope**: Round-2 package — 11 modified files (uncommitted working tree): `SchedulingController.php` (RF-065-05), `scheduling-apply-flow.spec.ts` + `auth-contexts.ts` (RF-065-01), 4 component specs (RF-065-02), controller unit tests, backlog/plan docs — plus §5 manifest, §8 acceptance criteria, §4.1 test matrix, durable docs, implementation history
**Base Commit**: `9cf3875dacf7cc781d73e7c9e51acea311dbf4ad` (round-1 NEEDS-FOLLOW-UP commit)
**Base fingerprint**: staged empty; unstaged diff `7b4642c286e4c8f5bc73d6df32836f4cd2a92f27`; no untracked files
**Owning plan**: [`docs/plans/done/cs-plan-065-wp6-actionable-scheduling.md`](../../plans/done/cs-plan-065-wp6-actionable-scheduling.md)

## Outcome

- **PASS**

## Verification

1. Round-2 diff review: RF-065-01 exit journey (UI task creation pinned from the authoritative aggregate response, owner-UI availability + limit 2→3 with the pinned PUT body — the detector that exposed RF-065-05, exact 3-row child Due-Today set proving the limit, calendar occurrence exactly once via SPA navigation without reload, role-cannot-apply 403 matrix for member + child, keyboard journey with focus-ring pin, mobile 390×844 touch journey with 44px pins); RF-065-02 component matrix (D5 limit, owner gating, `schedule-applied` refetch/unsubscribe, native-control + aria-live contracts); RF-065-05 controller boundary fix (closed payload from `getParams()` minus route internals, closed-shape validation retained, no stale two-arg callers, route-internals + `maxDailyTasks: null` regressions pinned). Out-of-scope sibling `TaskController::update` correctly routed to backlog BUG-API-001.
2. All plan §9 findings resolved: RF-065-01/02 fixed in round 2, RF-065-03 fixed in round 1, RF-065-04 user-resolved (lock-class coverage), RF-065-05 fixed in round 2.
3. Full §4.5 masterplan gate rerun green: eslint 0 errors (1 pre-existing documented warning), stylelint 0 errors (same single pre-existing warning), Jest 849, `test:noise`, webpack build, `l10n:check-keys`, `docs:encoding`, `backlog:check`, Docker PHPUnit unit 909 OK / integration 315 (+10 documented pre-existing skips), `composer cs:check` clean, Playwright chromium 92 (+7 pool-excluded skips), chromium-serial-db 98 passed / 5 skipped / **0 flaky on a clean re-run** (first run: 3 transient retry-flakes under load; static list 103 unchanged vs. round 1), chromium-scheduling-owner 38 passed, `git diff --check` clean.
4. Final-plan extras verified: manifest boundary matches the delivered set; every §8 acceptance criterion evidenced; durable docs (ADR-012, api/README, concepts, engineering) complete; knowledge integration sufficient for the `done/` move.

## Findings / Fixes / Decisions

- Findings: none. Safe fixes: none required. Decisions: none needed (all ratified in prior rounds).

## Finalization

- Plan moved `active/ → done/`; CR-WF-07 + CR-055-01 archived to [`2026-09.md`](2026-09.md); masterplan WP6 section + tracking row updated; ACTIVE_TASKS entry completed; §8 acceptance boxes ticked with evidence; Review Outcome appended to the plan; one scoped conventional review commit.
