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

---

## H6 Full Interim Review - NEEDS-FOLLOW-UP (2026-10-04)

**Requested tier:** Standard B; the structured user decision approved Full
interim escalation because H6 backup/restore touches data integrity. No
`--no-commit` flag was supplied. Explicit owner: CS-PLAN-081 in `active/`.
This entry supplements the earlier repair-round PASS; it does not replace its
historical evidence or claim whole-plan completion.

### Capture And Exact Manifest

- Branch `master`, HEAD `56697d273b0324d2b7d1a172d57569129438fc42`.
- Index empty; index-entry SHA-256 `39b326ce622cbf4356b331d32211c34fe3d3070e8081992c4b2afe9412930698`.
- Initial staged binary diff SHA-256 `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`.
- Initial unstaged binary diff SHA-256 `e2dbb05c9caff2ebae2b4828790d2268cbd67916d1191285a3c869c89aca9282`.
- Initial complete capture SHA-256 `1f7f986493ea080f79bc30f86c954b14b2d31acd3f054b4ea99f3260131be48d`.
- Repaired pre-finalization unstaged binary diff SHA-256 `e0df9ab7d717b72d29a77e466a4f6cd79892e6cf45d9f75b9c2b55810d3cf8a8`.
- Repaired complete capture SHA-256 `52d5a619789e45f733e5a3626d2d5d25ca7e01a49289c4e873deb120c2540063`.

Capture algorithm: SHA-256 of concatenated staged binary diff, unstaged binary
diff and JSON-serialized sorted untracked `{path,blob}` records (Git cryptographic
content identities). Raw diff captures include the excluded `.gitkeep` deletions
for drift detection; those paths are never reviewed/staged. Evidence-finalization
edits follow the repaired snapshot. Per-untracked identities:

| Path | Initial Git blob | Reviewed Git blob | Reviewed content SHA-256 |
| --- | --- | --- | --- |
| `scripts/run-recovery-rehearsal.ps1` | `355da7c551a593ddb402ee18ddfc3f0af049bf91` | `152051a9ec133b09e56e2313a0a140de080f7268` | `89ec38d3336ef0df8390175f5f48ff2815cec49dfb5dc51bfe14ff0ff5efd925` |
| `tests/recovery/fixture.php` | `b2e3fed91c47055d31abcf757bc7d6afed9c58e3` | unchanged | `5246278609fd3cc661fd072537a87cb720f1664e92fadc8672c1d86603068b12` |

Exact captured reviewed paths (14), plus the explicitly authorized repair path:

```text
AGENTS.md
docs/backlog/ACTIVE_TASKS.md
docs/engineering/deployment.md
docs/engineering/testing.md
docs/plans/active/cs-plan-081-pre-wp9-hardening-and-retained-data-readiness.md
docs/release/release-strategy.md
docs/standards/ai/rules/project-rules.md
docs/standards/ai/skills/architecture/SKILL.md
docs/standards/ai/skills/php-backend/SKILL.md
docs/testing/release-test-plan.md
lib/Service/RewardService.php
tests/unit/Service/CatalogAuditTest.php
scripts/run-recovery-rehearsal.ps1
tests/recovery/fixture.php
tests/integration/Controller/TestModeApiTest.php (authorized repair addition)
```

Review-generated finalization path: this record. Unrelated
`docs/plans/{active,new,onhold}/.gitkeep` deletions remain unstaged. Private
diagnostic scripts/config/JSONL, recovery artifacts and browser traces are not
public review/commit paths.

### Four Tracks And Findings

All four read-only deliverables received and validated. Architecture/ownership,
security/auth/data-flow and performance/quality return `[]`. Correctness returns
one minor readiness race; the authorized repaired source follow-up returns `[]`.
Main validates source and adds the reproduced diagnosis finding below.

| ID | Severity | Evidence / status |
| --- | --- | --- |
| RF-081-H6-06 | minor | Socket-only `mysqladmin ping` can accept the temporary MariaDB initialization server. Authorized bounded authenticated TCP/application `SELECT 1` repair; argument/retry/exhaustion self-tests and real isolated recovery pass. Resolved in the working tree; runner deliberately not committed with evidence-only finalization. |
| RF-081-H6-07 | minor | `TestModeApiTest.php:178` absolute zero-house assertion fails at randomized seed 8105. A nonempty test-owned fixture reproduces the old failure; authorized exact ordered before/after snapshot proof passes focused and both full orders. Resolved; no production behavior change. |
| RF-081-H6-05 | major | Earlier due-date-holder assertion plus four missing-fixture errors remain unexplained. 2,272 clean sampled boundaries across cached/randomized runs do not establish the cause or observe transient in-test/helper state. Open, plan-owned; no rerun-only disposition. |
| H7/H-T11 | required incomplete work | Operator address/key/receipt/decryption/publication evidence unavailable. Still required, not rescoped; prevents whole-plan completion. |

The source diagnosis pins remaining harness seams at
`SchedulePlanConcurrencyTest.php:179-217,295-306`: signal before commit,
no separate commit-result barrier, helper termination in `finally`, independently
calculated parent/helper due days. `IntegrationTestCase.php:56-73` closes the
known provisioning transaction only for newly created users; the shared DBAL
counter is not independent native-PDO evidence. These are diagnostic seams, not
proof of a production defect or authority to commit unknown inherited work.
The original H6-05 failure is not reproduced by this round and remains open.

### Gates And Failure History

Minimum-engine frontend commands run via the isolated Node 20.19.0/npm 10.8.2
wrapper. No global configuration or engine contract changes.

| Gate | Actual review result |
| --- | --- |
| Jest / noise | 109 suites, 1228 tests; noise gate passes, warning baseline not suppressed |
| Lint / stylelint / production build | PASS |
| Localization / browser runner self-test | PASS, 1270 exact keys; selection/skip/flaky/failure rejection self-test |
| Docker unit | PASS, 1314 tests / 4246 assertions, zero skips |
| Initial cache-independent integration | PASS, 568 / 3183, zero skips |
| Instrumented cached diagnostic | PASS, 568 / 3204; 1,136 clean before/after transaction records |
| Instrumented random seed 8105 | Initial FAIL, 568 / 3183, one invalid-profile baseline assertion; 1,136 clean transaction records; failure retained, not accepted as green |
| Focused nonempty regression | Old assertion FAIL deterministically (1 test / 3 assertions); repaired entire TestModeApi suite PASS (8 / 61) |
| Final random seed 8105 integration | PASS, 568 / 3182, `--do-not-cache-result --fail-on-skipped`, zero skips |
| Final default integration | PASS, 568 / 3182, `--order-by=default --do-not-cache-result --fail-on-skipped`, zero skips |
| PHP coding standards | PASS, 0 of 405 files require fixes |
| Full browser | `run-oi7XAv` PASS: 49 required specs, three sequential pools, zero required skips/failures/flaky cases/retries |
| Corrected recovery/self-test | `cs081-recovery-0f68dce7b4de` PASS, 30 snapshots, full DB/file equality, divergence rejection, 26 migration IDs, complete owned teardown; 32.77-second interval / 105.84-second total |
| Documentation/backlog/whitespace | PASS; rerun after final evidence edits |

Build-complete H-T10 was rechecked separately from the first build-concurrent
capture; only completed-build/corrected-run evidence is used for clearance.
Recovery, integration and browser mutation jobs never overlap. Private probes
read existing connection state without SQL/connect/transaction normalization;
they are optional diagnostics, not a replacement mandatory regression.

### Decisions And Finalization

Structured decisions, recommendation first, all answers recorded:

1. Full interim escalation approved for the captured H6 data-integrity scope.
2. H6-06 readiness repair approved; H6-05 diagnosis explicitly requested in-round.
3. H6-07 domain-neutral test repair approved after the randomized failure.
4. Keep H6-05 open with the collected evidence; no extra harness/production repair,
   blind transaction commit, suppression, required-work rescoping or adoption.

**Outcome: NEEDS-FOLLOW-UP.** Current required gates are green and questions
answered, but H6-05 remains unresolved; H7 remains required incomplete work.
Plan stays `active/`; no completed owners archived and no new backlog item.

Allowed commit set: evidence-only findings/outcome hunks in the owning plan,
this review record, `ACTIVE_TASKS.md` and the verified domain-neutral
`TestModeApiTest.php` correction. The owning plan is selectively staged so its
pre-existing H6 policy/implementation/manifest/acceptance updates do not enter
this evidence-only commit. All other captured H6 implementation/policy files,
including recovery runner/fixture and optional-description implementation, remain
uncommitted. No branch change, amend, push, PR, deployment or baseline adoption.
