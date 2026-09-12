# Code Review: CS-PLAN-066 WP7 — Canonical Client State and Family-Friendly Core UX (final Standard B)

**Review Date**: 2026-09-12  
**Reviewer**: Kilo agent (Standard B final code review)  
**Mode**: Standard Batch Review (final Standard B over the whole owning plan)  
**Scope**: Complete WP7 implementation package — 59 modified + 4 new files, captured as the uncommitted working tree on `master`; final-manifest, acceptance-criteria, test-evidence, durable-doc and implementation-history depth per the review skill §3  
**Base Commit**: `bd085bcdfcaed3efae1a40ccc6f92c09e9c34405` (Standard A activation)  
**Owning Plan**: [`docs/plans/done/cs-plan-066-wp7-canonical-client-state-family-ux.md`](../../plans/done/cs-plan-066-wp7-canonical-client-state-family-ux.md) (moved `active/ → done/` by this review)

---

## Executive Summary

### Outcome
- PASS-WITH-FIXES

### What Was Reviewed
- Backend: `TaskController::update` D6 request-binding fix (BUG-API-001) with closed-payload route-internals exclusion; enriched due/overdue execution rows (`RoomTaskService::getDueTodayTaskRows`/`getOverdueTaskRows`, batched `findByIds` enrichment, no access broadening, no N+1); canonical unique deterministically-ordered user-ID assignee arrays across read/create/set/bulk (`RoomTaskController`, `TaskAssigneeMapper` ORDER BY); explicit nullable `rewards.newTitle` in `CompletionService`.
- Stores: scoped request generations (per-room map, per-resource seqs), refcounted shared loading, reset/identity invalidation, mutation read-invalidation, shared in-flight current-user promise with identity-checked release, stable-id member upserts, D2 dedicated count projections, D3 coordinated derived refresh with stale flags + read-only retry, D10 reconcile-before-events, D5 week-identity celebration suppression, titles invalidate-before-unlock-event, minigame aggregate try/finally.
- Views/components: D4 one-shot child landing (`landingRouteForRole` + App.vue watcher), TodayView selector rework (instructions, execute-only expandable checklist, refresh-retry banner), TaskManager/RoomDetail error+retry states and visible mutation toasts, child checkbox hiding, wizard 44px targets, SnakeGame mount guard.
- Tests vs the plan's §4.1-§4.3 matrix: PHP controller/service pins (incl. real-HTTP-shaped D6 dispatch and `ownerUserId` route-param≠field attack), Jest race/projection/event/component matrix, new `family-core-ux` + `workflow-state-reconciliation` E2E specs, D4-affected spec updates, WP6 date-bomb fix.
- l10n: 99-key core slice (en/de/POT) + `coreWorkflowLocalization.spec.js` source-coverage guard; durable docs (api/README payload matrix, architecture client-state contract, UC-08); backlog routing and archive evidence.

### What Was Fixed Immediately
1. `src/views/TodayView.vue`: accidentally dropped `@license MIT` header line restored.
2. `tests/unit/Controller/TaskControllerTest.php`: 62 mixed CRLF line endings normalized to LF — unblocked `composer cs:check` (was 1 of 284 files, red).
3. Plan §5 manifest realigned with the actual reviewed surface (10 additional files added, `router.spec.js` path corrected, optional entries narrowed).
4. Manual-a11y finding (user pass 2026-09-12, verbatim: "Checklist" 0/0 clickable and flashing away, "Der Button sollte inaktiv sein"): the My Day checklist toggle is now hidden after the one-shot empty discovery (+ regression test; Jest 895 → 896). A permanently disabled state was rejected because checklist presence is unknown before the fetch and the frozen payloads carry no presence flag.

### Main Risks Remaining
1. None plan-owned. Observed (resolved, no defect identified): `room-management.spec.ts` showed two different passing-on-retry flakes across full `chromium-serial-db` runs under the extended serial pool; isolated re-runs and a full-pool re-run (108 passed / 0 flaky) were clean — load-sensitive timing only.
2. WP9-owned (explicit remainders, not gaps hidden by this plan): global l10n extraction/checker strengthening + ~340 dead artifact entries (CR-053-13 remainder); skip cleanup and `gui-scratch-flow.spec.ts` replacement.

---

## Package Results

### Package 0: Scope and Diff Mapping
- Inputs reviewed: `git status` (59 modified, 4 untracked), full diffs for `lib/`, `src/`, `tests/`, `docs/`, `l10n/`, `playwright.config.ts`; scope fingerprint captured at review start — empty index (staged diff SHA-256 `E3B0C44298FC1C149AFBF4C8996FB92427AE41E4649B934CA495991B7852B855`), unstaged binary diff SHA-256 `766AF3CDBEF768A54D593125551028DF4610FA7C2E1B0EC75F422EB5EED55DF8` (430,857 bytes), untracked SHA-256s: `tests/e2e/family-core-ux.spec.ts` `47AC05B6EDBFD031F45E56C1C808549D95ACDA3E1A9DEC3FE0EA956865F0B976`, `tests/e2e/workflow-state-reconciliation.spec.ts` `51C4886105CCF9C442060ECC8FD700E8B56E84FAD3CB285C78C4BBC4ECD7AA0F`, `tests/unit/router.spec.js` `B24B1C2F184645B97D1D091E7E9F6739E232D9C85C071FBC602116FD55069784`, `tests/unit/services/coreWorkflowLocalization.spec.js` `D35A28A6770B8B84B6F0045E8D8B20D48A35425579E46839628666AF2346D66B`.
- Findings: scope unambiguous (single active child plan + parent masterplan tracking); 3 manifest-drift/format minors (fixes 1-3 above).
- Outcome: clean after fixes.

### Package 1: Automated Static Quality Sweep
- Checks run: eslint, stylelint, `composer cs:check` (Docker), `docs:encoding`, `l10n:check-keys`, `backlog:check`, `git diff --check`.
- Findings: `cs:check` red on first run (CRLF, fix 2). Everything else green except the documented pre-existing stylelint warning (`NotAuthorizedState.vue`, `csstools/use-logical`) — unchanged from the WP6 baseline.
- Immediate fixes: applied; gates rerun green.

### Package 2: Wiring, Contracts, and Orphan Scan
- Inputs reviewed: D1 shape end-to-end (controller arrays ↔ store consumption ↔ TodayView rows — no relation-object consumer left, no dual-format shim); D6 real-HTTP boundary (unit mock mirrors dispatcher merge; integration adapted; E2E `waitForResponse` on `PUT /tasks/{id}`); `getAssigneeUserIdsByRoomTasks` reuse (CS-PLAN-036 idioms); D3 refresh owner wiring (TodayView banner → `refreshDerivedProjections`); D4 one-shot semantics (router helper pure + App watcher/mounted); D5 event sources (only completion passes `celebrateOnTransition`); reset paths bump every generation; `landingRouteForRole` query-qualified root handling.
- Findings: no orphans, no compatibility shims, no duplicated refresh owners; enrichment adds no new exposure (viewer-visible sets unchanged).
- Outcome: clean.

### Package 3: Focused Test Validation
- Tests run (complete §4.4 gate, rerun after review fixes): Jest 896/896 (83 suites); PHPUnit unit 916 OK / 2715 assertions; integration 315 OK / 10 pre-existing conditional skips (WP6 baseline unchanged — no new skips); `composer cs:check` 0; `test:noise`; webpack build; Playwright `chromium` 92 passed / 7 pool-excluded, `chromium-serial-db --workers=1` 108 passed / 5 pre-existing conditional skips / 0 flaky on the full re-run, `chromium-scheduling-owner --workers=1` 38 passed. The WP6 `SchedulePlanServiceTest` Saturday date-bomb (§9 entry 2) was exercised green on this Saturday run.
- Results: green. Flake observation recorded above and in plan §9 (resolved).
- Gaps: none for WP7. CR-053-13 global remainder explicitly WP9-owned.

### Package 4: Documentation and Backlog Sync
- Docs updated: UC-08 checklist row (toggle discovery note); plan §5/§8/§9 + final Review Outcome + status; masterplan WP7 row; `backlog.md` plan links repointed to `done/`; archive `2026-09.md` review-pending notes finalized; `ACTIVE_TASKS.md` (In Progress cleared, review completion line added); this record.
- Backlog updates: CR-WF-04/CR-WF-08/CR-053-20/BUG-API-001 archived 2026-09 with per-criterion evidence (delivered by the implementation round; finalized by this review); CR-053-13 slice delivered with exact WP9 remainder; CR-WF-05/CR-WF-06 dependency metadata updated.

### Package 5: Manual Acceptance Evidence
- User-performed child-persona copy/screen-reader pass (2026-09-12, authorized via the batched review question, option "perform now"): My Day landing, row instructions, checklist expand/toggle, Done + queued Undo, error/retry state. Verbatim report: "Funktioniert, was auffällt: 'Checklist' hat 0/0, lässt sich aber anklicken (und verschwindet gleich wieder). Der Button sollte inaktiv sein." — recorded in plan §8 criterion 11; the finding was fixed in round (fix 4) and its regression test added.

---

## Verdict

`PASS-WITH-FIXES` — all findings fixed or resolved in round, no open plan-owned findings, full quality gate green, manual acceptance evidence recorded. Plan moved to `done/`; masterplan WP7 complete; WP8 eligible (not started).
