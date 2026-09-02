# Code Review: Review Workflow Automation and Standards Audit

**Review Date**: 2026-09-02
**Reviewer**: Kilo (four parallel read-only reviewer tracks, main-agent verification)
**Tier**: Full
**Owning Plan**: N/A (direct user request)
**Base Commit**: `fb26f1899e3b46e954982ceba49eedb9e9a6ac4d` on `master`
**Reviewed Scope Fingerprint**: `4595291c223ceffeb3419a487c968b4e253fd317ee384e64337239b25f611105`

The fingerprint covers the staged and unstaged binary diffs plus sorted untracked
paths and the SHA-256 content hash of each untracked file, excluding this
self-referential review record. The index was empty and the working tree was clean
before this review package started.

## Outcome

**NEEDS-FOLLOW-UP**: the requested review/lifecycle automation and every standards
finding except STD-009 are resolved and fully gated. The pre-existing historical
backlog cleanup is intentionally deferred as **TECH-DOCS-003**; it does not affect
the new review workflow, but keeps this Full-review outcome from being `PASS`.
There is no owning plan to transition.

## Scope Manifest

```text
.gitignore
AGENTS.md
.kilo/command/plan-review.md
.kilo/command/review-chorestrike.md
.kilo/command/review-chorestrike-full.md
docs/backlog/ACTIVE_TASKS.md
docs/backlog/README.md
docs/backlog/backlog.md
docs/backlog/archive/README.md
docs/backlog/archive/CODE_REVIEW_2026-08-11.md (moved verbatim from docs/backlog/)
docs/backlog/archive/CODE_REVIEW_2026-08-27.md (moved verbatim from docs/backlog/)
docs/concepts/ui-use-cases.md
docs/engineering/architecture.md
docs/engineering/review-playbook.md
docs/licenses/lucide-LICENSE.txt
docs/plans/README.md
docs/plans/template.md
docs/plans/active/.gitkeep
docs/plans/new/.gitkeep
docs/plans/onhold/.gitkeep
docs/standards/ai/rules/project-rules.md
docs/standards/ai/skills/architecture/SKILL.md
docs/standards/ai/skills/docker-dev/SKILL.md
docs/standards/ai/skills/gamification/SKILL.md
docs/standards/ai/skills/git-workflow/SKILL.md
docs/standards/ai/skills/php-backend/SKILL.md
docs/standards/ai/skills/plan-management/SKILL.md
docs/standards/ai/skills/review/SKILL.md
docs/standards/ai/skills/testing/SKILL.md
docs/standards/guidelines/agent-spawning.md
docs/standards/guidelines/coding-conventions.md
docs/standards/guidelines/external-asset-licensing.md
docs/standards/guidelines/llm-do-dont-checklist.md
docs/standards/guidelines/security.md
docs/standards/guidelines/testing.md
```

## Decisions

| Decision | Recorded Answer | Resulting Rule |
|----------|-----------------|----------------|
| Automatic commit branch | Stay on `master`; create/switch branches only when explicitly requested | Review commits target the current branch and never create/switch branches |
| Interim code reviews | Commit the reviewed package and keep the plan active | Only a plan-wide final pass can move `active/ -> done/` |
| Reviews with open findings | Commit review evidence/backlog/safe fixes | Affected implementation and plan moves stay out; sensitive vulnerability details are never publicly auto-committed |
| Completed-plan retention | Later local archival remains allowed | `done/ -> archive/` requires an explicit user decision and tracked links must be repaired first |

## Findings and Disposition

| ID | Severity | Finding | Disposition |
|----|----------|---------|-------------|
| STD-001 | block | Canonical rules, plan skill, Git skill, and all commands prohibited the requested automatic moves/commits | Fixed: review-driven lifecycle and one scoped current-branch commit are canonical |
| STD-002 | block | Standard B mixed interim and final reviews, allowing premature `done/` transitions | Fixed: package outcome and whole-plan completion eligibility are separate |
| STD-003 | block | Questions could be listed and forgotten without recommendations or a stop/resume gate | Fixed: one structured batch, 2-3 options, recommendation first, answers recorded before finalization |
| STD-004 | block | Auto-fix rules allowed logic/validation changes and could publish unremediated security details | Fixed: behavior-changing/security work requires approval; sensitive details stay private and block public auto-commit |
| STD-005 | major | Automatic commits lacked exact plan ownership, staged-set, concurrent-change, empty-commit, and source-fingerprint guards | Fixed in review and Git skills; explicit read-only/no-commit overrides automation |
| STD-006 | major | Review outcomes, review-record paths, severity names, and removed backlog files conflicted across standards | Fixed and historical review records moved verbatim to `docs/backlog/archive/` |
| STD-007 | major | Architecture, migration, authorization, testing, gamification, logging, security-reporting, and Lucide-license guidance was stale or unsafe | Fixed against the current tree/package lock; Lucide ISC evidence added |
| STD-008 | major | `ACTIVE_TASKS.md` contained 578 lines of duplicated history and a stale Planned section | Fixed: live task plus ten one-line recent entries; history remains in tracked archives |
| STD-009 | major | `backlog.md` still contains resolved entries and links to locally archived plans | Deferred as `TECH-DOCS-003` with acceptance criteria; no workflow dependency |

## Validation

| Gate | Result |
|------|--------|
| Four reviewer tracks + focused follow-up | Complete; all workflow/safety/canonical/docs findings fixed except STD-009 |
| Kilo command frontmatter structural check | 3/3 valid |
| `npm run lint:fix` + `npm run lint` | Pass |
| `npm run stylelint` | Pass, 0 warnings |
| `npm test` | 80 suites, 778 tests passed |
| `npm run test:noise` | Pass, no error-level log noise |
| `npm run build` | Production webpack build passed |
| `npm run l10n:check-keys` | Pass |
| `npm run docs:encoding` | 631 tracked files passed |
| `node scripts/check-docs-encoding.js --self-test` | 7/7 cases passed |
| PHPUnit unit (Docker) | 781 tests, 2138 assertions, 0 skipped |
| PHPUnit integration (Docker) | 211 tests, 611 assertions, 21 pre-existing skips |
| `composer cs:check` (Docker) | 0/258 fixable files |
| `git diff --check` | Pass |

Docker required one canonical disposable reset (`down -v` then clean `up -d`):
the first recreated container met the documented used-volume/"Login already used"
entrypoint defect. The clean environment became healthy and both backend suites
then passed.

## Commit

This record is part of the single automatic review commit on the current
`master` branch. The final user report carries the resulting commit SHA.

---

# Code Review: Core Task Workflow

**Review Date**: 2026-09-02
**Reviewer**: Kilo (two explore preparation passes, four parallel read-only reviewer tracks, main-agent verification)
**Tier**: Full, Deep Slice
**Owning Plan**: N/A (direct user request)
**Base Commit**: `ac0a282c75aec0bc3919e82c2889ef4e63448a05` on `master`

## Outcome

**BLOCKED**: the public build and test gates are green and three safe fix
clusters were completed, but unremediated security/authorization findings remain.
Their public descriptions are intentionally sanitized with the user's approval;
technical evidence remains outside the public repository. Project rules prohibit
a review commit or plan transition for this outcome.

## Scope

- First authenticated app start, instance-house bootstrap, and current-user initialization.
- Setup wizard, house structure, member onboarding, and task catalog materialization.
- Custom/template task creation, visibility, single/multi/bulk assignment, and checklists.
- Today/TaskManager/RoomDetail execution, completion, immediate rewards, and undo.
- Matching route/controller/service/mapper/store/component contracts, unit/integration/E2E coverage, and durable docs.
- Scheduling algorithms, minigames, and background jobs were excluded except for direct completion-path touchpoints.

## Decisions

| Decision | Recorded Answer | Consequence |
|----------|-----------------|-------------|
| Membership after first owner | Invite only | Removal must remain durable; ordinary access may not auto-enroll a user |
| Private-task principals | Creator plus explicit assignees | Reads, assignment, checklists, and lifecycle actions share one policy |
| Undo contract | Full transactional undo | The former state-only accepted limitation is revoked |
| Public security tracking | Sanitized placeholders | Public files contain no exploit details; private evidence is not committed |

## Findings and Disposition

| ID | Severity | Location | Finding | Disposition |
|----|----------|----------|---------|-------------|
| WF-SEC-01 | block | Private evidence (sanitized) | Membership, role, task-visibility, mutation, and logging boundaries are inconsistent across the journey. | Deferred as CR-WF-01; never auto-fixed |
| CR-053-07 | block | `Version1000Date20260224000000.php:39` | Existing first-house/first-owner race remains open. | Existing CR-053-07 |
| WF-COMP-01 | block | `lib/Service/RoomService.php:224-490` | Completion and the split undo do not preserve all active-state, concurrency, retry, and inverse-operation invariants. | Deferred as CR-WF-02 |
| WF-DATA-01 | major | `HouseSetupService.php:82-183`; `RoomTaskService.php:305-329,481-486` | Setup, task creation, and assignment do not form one canonical rollback-safe aggregate. | Deferred as CR-WF-03 |
| CR-053-09 | major | `lib/Db/RoomTask.php:273-279`; `TaskService.php:293-299` | The accepted recurrence vocabulary still disagrees with completion. | Existing CR-053-09 |
| WF-STATE-01 | major | `RoomTaskService.php:965-1000`; `src/store/roomTasks.js:324-374` | Daily limits, response payloads, and owning Pinia caches diverge across completion and refresh. | Deferred as CR-WF-04 and existing CR-053-20 |
| WF-TEST-01 | major | `TaskAuthorizationTest.php:53-126`; `task-completion-full-flow.spec.ts:80-191` | Live authorization tests are skipped and several workflow E2E assertions are weak or data-dependent. | Deferred as CR-WF-05 |
| WF-ARCH-01 | major | `TaskList.vue:1-1094`; `TaskManager.vue:309`; `TodayView.vue:267` | Oversized orchestration and request-per-item/room fan-out obscure the critical flow. | Deferred as CR-WF-06 plus CR-SVC-15/40/41/44 |
| WF-TZ-01 | minor | `src/views/TodayView.vue:315-318` | Post-completion bucketing used the browser day instead of the house day. | Fixed with regression coverage |
| WF-I18N-01 | minor | `src/main.js:52-61`; `HouseService.php:173-187`; `src/store/house.js:27` | Startup fallback and default house labels bypassed localization. | Fixed with artifacts and tests |
| WF-DOC-01 | minor | `docs/api/README.md:427-446`; release/use-case matrices | Completion payload, removed lifecycle routes, and several workflow status claims were stale. | Fixed where implementation intent was unambiguous |

## Safe Fixes

1. `TodayView` now uses the house timezone for post-completion day bucketing; Jest pins a browser/house offset mismatch.
2. Startup failure and default house labels now use `t()`/`IL10N`; startup fallback uses `textContent`, and l10n artifacts/tests are synchronized.
3. Completion API, release lifecycle, and use-case documentation now match live behavior and the recorded full-undo target.

Security/auth, architecture, migration, and behavior-changing findings were not
auto-fixed.

## Validation

| Gate | Result |
|------|--------|
| Explore preparation + four reviewer tracks | Complete; consolidated and deduplicated by the main agent |
| `npm run lint:fix` + `npm run lint` | Pass |
| `npm run stylelint` | Pass |
| `npm test` | 80 suites, 780 tests passed |
| `npm run test:noise` | Pass, no error-level log noise |
| `npm run build` | Production webpack build passed |
| `npm run l10n:check-keys` | Pass |
| `npm run docs:encoding` | 631 tracked files passed |
| PHPUnit unit (Docker) | 782 tests, 2142 assertions, 0 skipped |
| PHPUnit integration (Docker) | 211 tests, 611 assertions, 21 pre-existing skips; live workflow skips tracked as CR-WF-05 |
| `composer cs:check` (Docker) | 0/258 fixable files |
| Targeted non-seed workflow E2E | 23 tests passed |
| Targeted serial workflow E2E | 10 workflow tests plus 3 setup tests passed with family profile and one worker |
| `git diff --check` | Pass after final tracking edits |

The first combined E2E attempt exposed cross-project seed interference (33 pass,
2 retry-pass, 1 failure). A clean disposable environment plus the canonical
serial split made every selected workflow scenario pass; isolation hardening is
tracked under CR-WF-05.

## Follow-up

- Added CR-WF-01 through CR-WF-06 to `docs/backlog/backlog.md`.
- Updated existing CR-053-18 and CR-053-20 with the recorded decisions.
- No owning plan exists, so no lifecycle transition applies.
- Commit: not made because the review outcome is `BLOCKED`.
