# Code Review: CS-PLAN-072 WP1 — Standard B Interim (Occurrence Timeline Package)

**Review Date**: 2026-09-21 (fix round completed 2026-09-22)
**Reviewer**: Main agent (Kilo, `/review`, skill: review) with six parallel read-only track agents (security, performance, business logic, deploy safety, duplication, dead code)
**Tier**: Standard B (code, interim) — changed-file review of the uncommitted WP1 package
**Scope**: all uncommitted changes on `master` (99 tracked files + 19 untracked new files, +3078/−1284 at capture) implementing `docs/plans/active/cs-plan-072-wp1-occurrence-timeline.md`
**Base Commit**: `master` @ `937cf29`
**Owning plan**: CS-PLAN-072 / WP1 (active) — all findings routed plan-owned per project-rules §4 Finding Routing; no backlog items created

---

## Scope And Capture

- Branch `master`, HEAD `937cf29`, index empty at capture; the entire WP1
  delivery is uncommitted (unstaged + untracked).
- Pre-fix diff fingerprint was not captured before the in-round fixes; the
  post-fix fingerprint (SHA-1 `git hash-object` over `git diff HEAD`) is
  `c1980448b849b6ab1711023707808df55b915c94` (119 changed/untracked paths).
- No automatic review commit: a scoped evidence-only commit was not separable
  from the user's uncommitted implementation package, and the package owner
  did not request a commit.

## Outcome

`NEEDS-FOLLOW-UP` — 16 findings (3 critical, 12 warning, 1 suggestion).
Four fixed in-round (RF-072-WP1-02/03/04/05) with three new regression tests;
twelve remain open (RF-072-WP1-06..17) and block the final Standard B pass and
the `done/` move. Full finding text, fix descriptions and regression-test
names: plan §9 round "Interim Standard B review round (2026-09-21)".

### Fixed in-round (critical)

| ID | File | Defect | Regression test |
|---|---|---|---|
| RF-072-WP1-02 | `lib/Service/OccurrenceService.php` | Keep-anchor edits on fixed rules re-derived the predecessor's entire agreed history as completable virtual twins (double credit for one planned day; duplicate calendar rows) | `OccurrenceTimelineTest::testKeepAnchorEdit_DoesNotDuplicateAgreedOccurrences` |
| RF-072-WP1-03 | `lib/Service/OccurrenceService.php` | Catch-up coverage compared window instants (strict `<`); adjacent default flex=0 windows share the boundary instant, so current work never covered the previous day's miss (stale card stayed completable for extra coins) | `OccurrenceTimelineTest::testCurrentCompletion_CoversAdjacentMiss_ForDefaultFlexWindows` |
| RF-072-WP1-04 | `lib/Service/AccountErasureService.php`, `lib/Db/AgreementMapper.php` | Erasure left plain Nextcloud `userId` strings inside `agreements.old_state/new_state` member snapshots (re-identifiable data survived erasure) | `AccountErasureTest::testErasure_ScrubsUserIdsFromAgreementMemberSnapshots` |
| RF-072-WP1-05 | `lib/Service/OccurrenceService.php` | `$materialized +=` without initialization — PHP 8.2 undefined-variable warning on every fixed-rule edit preamble | covered by the suite (no warning tolerated) |

Fix surface: `OccurrenceService::fixedLowerBoundKey()` +
`ScheduleRevisionMapper::findPredecessor()` (enumeration bound applied in
`deriveAgreedEntries`, `reconcileUnderLock`, `reconcileThroughUnderLock`,
`resolveAgreedKeyUnderLock`); planned-date coverage comparison;
`AgreementMapper::anonymizeMemberSnapshots()` called from `eraseMembership`.

### Open (plan-owned, deferred to the next round by user decision)

RF-072-WP1-06 (undo successor revision scope) · 07 (client commandId reuse) ·
08 (unbounded reconciliation under house lock) · 09 (job write amplification) ·
10 (unbounded/duplicated selection derivation per request) · 11 (duplicated
conflicting child-capacity guards + N+1) · 12 (legacy recurrence engine in
planner capacity) · 13 (checklist 500 on malformed occurrenceId) · 14
(voluntary fallback covered/kind filters) · 15 (divergent occurrence DTO
builders) · 16 (dead-code bundle) · 17 (redundant prefix indexes).

## Gates (fix round)

- Docker PHPUnit unit: **976 tests / 2900 assertions — OK**.
- Docker PHPUnit integration: **398 tests / 1802 assertions — OK**
  (`--fail-on-skipped`, zero skips; baseline 395 + 3 new regression tests).
- `composer cs:check`: clean (0 of 327 files fixable).
- `php -l`: clean on every changed file.
- No frontend files touched in the fix round — Jest/lint/build/E2E unaffected;
  the full package gate set reruns at the final Standard B.

## Questions / Decisions

- Batched question (Kilo `question` tool): user selected **"Fix criticals
  first"** — fix the three critical findings with regression tests, defer the
  warnings/suggestion to a separate round. Recorded in the plan's Review
  Outcome.

## Plan Transition

None — interim review with open findings; the plan stays in
`docs/plans/active/`.
