# Code Review: CS-PLAN-081 Repair Round Clearance - Standard B Interim

**Review Date**: 2026-10-04
**Reviewer**: Kilo (main agent, no subagents - Standard B tier)
**Mode**: Standard B (code review, interim; explicit active-plan scope)
**Scope**: The complete uncommitted CS-PLAN-081 candidate - the prior 67-path implementation/review manifest, the six approved engine-support additions and the authorized RF-081-B13..B16 repair round. 65 modified implementation/durable-doc paths plus 11 untracked files; evidence files (owning plan, `ACTIVE_TASKS.md`, prior review record) reviewed with the package. Unrelated pre-existing `docs/plans/{active,new,onhold}/.gitkeep` deletions preserved unstaged and excluded from review and every allowed write/commit set.
**Owner**: `docs/plans/active/cs-plan-081-pre-wp9-hardening-and-retained-data-readiness.md`
**Base Commit**: `605cd8349baa458ea907794005ad15b6c11a56f5` (`master`; index empty at capture; full raw unstaged diff SHA-256 `c74ae8e408c65587bd0112b9b4108f6afd9b915282487763f3143b70ecdeabb9`)
**Outcome**: `PASS` - interim; RF-081-B13..B16 cleared, no new findings; plan stays `active/` because H6 recovery/docs and H7 operator evidence remain required and unrescoped.

---

## Executive Summary

The requested Standard B review covered the remaining CS-PLAN-081 candidate with
the authorized RF-081-B13..B16 repair round. All four open plan-owned findings
were re-verified against source and regressions and are resolved. No new
findings arose; nothing was routed to the backlog. Every required gate was rerun
green on the current tree (except the full browser gate, whose recorded
`run-pqNIwe` evidence applies to this exact candidate; the final gates rerun at
the plan's final review). This is an interim passing review: the reviewed
package is committed and the plan remains `active/` pending H6/H7 and the final
review.

## Candidate Identity And Drift Check

- Branch `master`, HEAD `605cd8349baa458ea907794005ad15b6c11a56f5`, staged set
  empty at capture (staged diff = SHA-256 of empty).
- Full raw unstaged diff SHA-256:
  `c74ae8e408c65587bd0112b9b4108f6afd9b915282487763f3143b70ecdeabb9`.
- Per-untracked drift check against the recorded frozen checkpoint: 9 of the 10
  recorded content hashes match byte-for-byte; `tests/unit/Service/ChecklistAuditTest.php`
  differs exactly by the authorized B13/B15 regressions (current SHA-256
  `fb3710968489704e68ca37b813010b7ff16c4095575f33a8a04b33e012d3b7bc`);
  `tests/integration/Service/OwnerCommandAuthorizationTest.php` is the authorized
  B16 addition (SHA-256
  `bdba888bee2788944f17de99fdc14b6f532ea65ddd93cacfec6430f71011039d`).
  No unrelated drift.

## Findings Verification (RF-081-B13..B16)

| Finding | Severity | Verified resolution |
| --- | --- | --- |
| RF-081-B13 | major | `lib/Service/ChecklistService.php:291` fresh missing-item branch uses `$this->l10n` scoped 404; `ChecklistAuditTest` pins item-disappeared/denial/rollback/visibility-revoked modes with rollback and no false-success audit (17 provider cases). |
| RF-081-B14 | minor | `lib/Service/SchedulingService.php:258-278` compares locked before/after preferences; audit outcome is truthful `no-change` for empty/identical updates; numeric post-commit context pinned by `testUpdateMemberAvailability_AuditIsNumericTruthfulAndPostCommit`. |
| RF-081-B15 | minor | `lib/Service/ChecklistService.php:293-296` deduplicates fresh task IDs before the locked access re-check; `testReorder_ManyItems_ChecksFreshTaskAccessOncePerDistinctTask` pins one access check per distinct task across four items. |
| RF-081-B16 | block | `lib/Service/CommandService.php:117-121,163-165` invokes the under-lock `authorizeUnderLock` guard on execution AND replay before any receipt answer. Every owner-capable command consumer supplies it: `AgreementService::runOccurrenceCommand` (amend/catchUp/restart, fresh identity + scoped 404 + owner role), `PauseService::actorGuard` (all five commands, member/owner/shorten-authority variants with pause house-scoping), `SchedulePlanService::authorizeApplyUnderLock`, `HouseService::resetHouse`, `RewardService::ownerGuard` (catalog), `RewardRequestService::lifecycleGuard`. Documented self-service member boundary (preferences, completion, own request creation, manual delta with pre/post checks) unchanged. `OwnerCommandAuthorizationTest` proves all 36 action x loss x replay combinations behind a real two-connection house-lock barrier (external process commits demotion/departure while the command waits for the lock) with domain/receipt snapshot equality and no false-success audit. Technical security evidence remains private under the approved disclosure boundary. |

No new findings; no backlog routing. The stale "H1's engine-compatibility probe
is red" sentence in the plan's repair-cluster history describes the
pre-resolution state and is superseded by the RF-081-B02 continuation outcome
and this review's fresh engine evidence (`npm ls --all --omit=optional` exit 0).

## Gates (rerun 2026-10-04 on the reviewed tree)

| Gate | Result |
| --- | --- |
| `npm test` | PASS, 109 suites / 1228 tests |
| `npm run test:noise` | PASS, full run, free of error-level log noise |
| `npm run lint` | PASS, exit 0 (approved deprecation-warning baseline only) |
| `npm run stylelint` | PASS, exit 0 |
| `npm run build` | PASS, webpack 5.105.2 production build |
| `npm run l10n:check-keys` | PASS, 1270 source/catalog keys, exact POT/EN/DE coverage |
| `node scripts/run-e2e-gate.js --self-test` | PASS |
| Docker PHPUnit unit `--fail-on-skipped` | PASS, 1313 tests / 4234 assertions, zero skips |
| Docker PHPUnit integration `--fail-on-skipped` | PASS, 568 tests / 3203 assertions, zero skips (incl. the 36-case barrier suite) |
| Docker `composer cs:check` | PASS, 0 of 404 files require fixes |
| `npm ls --all --omit=optional` | PASS, exit 0, engine/peer graph healthy |
| `npm run backlog:check` | PASS, 34 scoped files link-checked |
| `npm run docs:encoding` | PASS, 879 tracked files |
| `git diff --check` | PASS |
| `npm run test:e2e:gate` (full browser) | Not rerun in this review: recorded `run-pqNIwe` green evidence applies to this exact candidate (drift check above); final gates rerun at the plan's final review |

## Decisions

- No batched user questions were required: the repairs were explicitly
  pre-authorized by the 2026-10-04 "Repair now" decision, the review found no
  new findings, no behavior change beyond the approved scope occurred, and no
  security disclosure decision arose.
- No `--no-commit` override was supplied; the full finalization pipeline ran.

## Outcome-Specific Finalization

- `PASS` (interim). RF-081-B13..B16 marked resolved in the owning plan's
  `## Review Findings`; acceptance criteria for H1-H5 regressions and the
  owner-mutation logging inventory checked with review citations.
- Plan header status updated; `## Review Outcome` appended. The plan stays in
  `active/`: H6 recovery/docs and H7 operator evidence remain required and
  unrescoped, and the final Standard B/Full review is still pending.
- One scoped conventional commit of the reviewed package (implementation plus
  review evidence, this record, tracking updates); the three unrelated
  `.gitkeep` deletions remain unstaged. No branch change, amend, push, PR,
  deployment, retained-baseline adoption or productive-use declaration.

## Plan Lifecycle

- Interim passing review: commit the reviewed package, keep the plan `active/`.
- No standalone backlog item is closed or archived by this review: H6/H7 and
  the final gates keep TECH-JEST-001/TECH-LINT-006/TECH-TEST-003/CR-SEC-05/
  SEC-AUDIT-001/BUG-SEC-001/FEAT-UX-032/CR-053-11 owners in progress under the
  plan until its final review.
- `ACTIVE_TASKS.md` updated to the post-review state.
