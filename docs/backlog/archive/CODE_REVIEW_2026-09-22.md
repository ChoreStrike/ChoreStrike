# Code Review: CS-PLAN-072 WP1 — Standard B Final (Occurrence Timeline Package)

**Review Date**: 2026-09-22
**Reviewer**: Main agent (Kilo, `/review`, skills: review, plan-management, git-workflow)
**Tier**: Standard B (code, final) — full-package review against the owning plan's manifest, acceptance criteria, required-test evidence, durable-doc integration and implementation history
**Scope**: all uncommitted changes on `master` (102 tracked modified files + 20 untracked new files) implementing `docs/plans/active/cs-plan-072-wp1-occurrence-timeline.md`
**Base Commit**: `master` @ `937cf29`
**Owning plan**: CS-PLAN-072 / WP1 (active) — findings routed plan-owned per project-rules §4 Finding Routing; no backlog items created

---

## Scope And Capture

- Branch `master`, HEAD `937cf29`, index empty at capture; the entire WP1
  delivery remains uncommitted (unstaged + untracked).
- Scope fingerprint (SHA256) at capture: unstaged binary diff
  `09148DB9F8D5B1CCDFD7DDE26394FC5DB02C5D1250CD52690D3679340C386C4E`; staged
  diff EMPTY; untracked manifest (20 sorted paths with per-file content
  hashes) `2245615D4231209A414C5DD08ADF6DC8DDFD532B8E800E252041126B5124B98C`.
- The three pre-existing unrelated `.gitkeep` deletions
  (`docs/plans/{active,new,onhold}/`) stayed excluded from the review package,
  consistent with prior commits.

## Outcome

`NEEDS-FOLLOW-UP` — all twelve resolving-round fixes (RF-072-WP1-06..17)
verified in code with their regression tests; one new finding raised:

### RF-072-WP1-18 — OccurrenceService file size (open, plan-owned)

- Severity: minor (guideline, not a defect)
- File: `lib/Service/OccurrenceService.php` (1934 lines)
- Issue: far beyond the ~300-lines/file guideline; selection derivation,
  reconciliation/materialization, completion/undo seam, capacity counting and
  DTO building share one class that still grew through the resolving round.
- Context: the interim six-track review passed on it; existing services
  already exceed the guideline (`RoomTaskService` 921).
- Routing: user selected **plan-owned finding** (batched question) — recorded
  in the plan's §9 "Final Standard B review round"; blocks the `done/` move;
  fix direction is a behavior-neutral split in a next round of this plan.

### Verification of RF-072-WP1-06..17 (all confirmed fixed)

| ID | Verified evidence |
|---|---|
| 06 | `releaseCompletionUnderLock` matches `sourceCompletionId` + open + uncovered across ALL revisions; `testUndoAfterParticipantAmendment_ReleasesCrossRevisionSuccessor` |
| 07 | `pendingCommands` cache keyed `<roomTaskId>:<occurrenceId>:<expectedVersion>`; keep on uncertain (no status/5xx), clear on definitive (success/4xx), `reset()` discards; 5 spec cases in `roomTasks.spec.js` |
| 08 | `JOB_MATERIALIZE_BUDGET` 250 per pass; frontier advances only through reconciled entries; `dayAfter(frontier)` lower bound; edit preamble enforces `MAX_GENERATE_OCCURRENCES` with localized 409 (EN/DE/POT present); `testReconciliationBudget_ConvergesOverPasses` |
| 09 | `findFirstOpenScheduledByRoomTask` single indexed query + no-op-skip UPDATE; `WorkflowQueryBudgetTest` pin updated; `testNextDueProjection_TracksEarliestOpenOccurrence` |
| 10 | `SELECTION_LOOKBACK_DAYS` 31; batched `findOpenByRoomTasks`/`findByRoomTasksPlannedBetween`; one threaded selection map in the row endpoints |
| 11 | ONE shared `openOccurrenceDayLoadByWallet` counter feeds the amendment gate and `RoomTaskService::assertChildCapacityForDate`; `testDateAmendment_ChildCapacity_CountsOpenOccurrencesOnTargetDay` |
| 12 | `baselineCapacity` from `agreedOccurrencesForHouseTasks` + `projectionsForTask`; legacy engine deleted repo-wide (only a comment reference remains) |
| 13 | `validateOccurrenceKey` via `Occurrence::parsePublicKey` on both checklist endpoints; 3 test cases in `ChecklistControllerTest` |
| 14 | Voluntary fallback requires completion+coverage null; `findLatestMissedByRoomTask` filters `kind = scheduled`; `testVoluntaryOccurrence_IsNoCatchUpMiss_AndNeverCovered` |
| 15 | `selectionBlock` delegates to `OccurrenceService::occurrenceBlock` (+`savedZoneDiffers` only) |
| 16 | Dead-code bundle deleted; `findByOccurrenceIds` wired by the RF-11 counter; erasure/reset survivors intentional |
| 17 | `idx_srev_task`, `idx_agr_occ` (Version1031) and `chk_prog_occ_idx` (Version1005) absent |

Also verified: `TimelineJob` registered in `appinfo/info.xml` (5-minute
interval, injected clock, no awards/credits/notifications);
`family-rhythm.spec.ts` pinned in `required-scenarios.json`; durable docs
integrated (ADR-013 new; ADR-009/011/012 amended; task-model concept, API
README, architecture/development/testing, release-test-plan, roadmap);
parent RF-070-02/04 annotated with the delivered WP1 portion only; all
acceptance criteria checked except the final-review criterion.

## Gates (rerun in this review)

- Docker PHPUnit unit: **963 tests / 2890 assertions — OK** (`--fail-on-skipped`, zero skips, `family` profile).
- Docker PHPUnit integration: **403 tests / 1832 assertions — OK** (`--fail-on-skipped`, zero skips).
- Jest: **1069/1069 — OK** (100 suites).
- `npm run lint`: clean. `npm run build`: compiled successfully. `npm run test:noise`: clean.
- `composer cs:check`: 0 of 327 fixable.
- `npm run l10n:check-keys`: 957 keys, POT/EN/DE exact coverage.
- `npm run backlog:check`: 25 files link-checked, green. `npm run docs:encoding`: 742 files, green. `git diff --check`: clean.
- **E2E gate: PASSED** (`run-Fwt2M5`) — 47 required specs, all three pools sequential, zero skipped/failed/flaky. Rerun by user decision (see below).

## Questions / Decisions

Batched question (Kilo `question` tool, 2026-09-22):

1. **E2E gate evidence** — user selected "Rerun E2E gate now": the final
   review produced its own authoritative E2E evidence rather than accepting
   the resolving round's same-day PASSED report.
2. **File-size guideline** (`OccurrenceService` 1934 lines) — user selected
   "Plan-owned finding": recorded as RF-072-WP1-18 in the plan's §9; blocks
   the `done/` move; not an observation and not a backlog rescope.

## Fixes

None applied in this review round (no auto-fixable findings; RF-072-WP1-18 is
an architectural refactor deferred to a next round of the plan by user
decision).

## Plan Transition

None — `NEEDS-FOLLOW-UP` with an open plan-owned finding; the plan stays in
`docs/plans/active/`. Evidence-only scoped commit (this record, the interim
record `CODE_REVIEW_2026-09-21.md`, the plan's Review Outcome/§9 updates,
`ACTIVE_TASKS.md`); the implementation package stays uncommitted pending
RF-072-WP1-18's resolution and a passing final review.
