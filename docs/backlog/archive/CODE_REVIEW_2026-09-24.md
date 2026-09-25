# Code Review: CS-PLAN-073 WP2 — Standard B Final Attempt (Agreements, Capacity and Pauses)

**Review Date**: 2026-09-24
**Reviewer**: Main agent (Kilo, skills: review, plan-management, git-workflow)
**Tier**: Standard B (code, final attempt) — full-package review against the owning plan's manifest, acceptance criteria, required-test evidence, durable-doc integration and implementation history
**Scope**: the complete uncommitted WP2 implementation increment on `master` (at capture: 52 modified + 14 untracked files) implementing `docs/plans/active/cs-plan-073-wp2-agreements-capacity-pauses.md`
**Base Commit**: `master` @ `fedf2122d4438c3a89f683cd5a87bd126f397d8a`
**Owning plan**: CS-PLAN-073 / WP2 — all findings routed plan-owned per project-rules §4 Finding Routing; no out-of-scope items, no new backlog entries

---

## Scope And Capture

- Branch `master`, HEAD `fedf212`, index empty at capture (2026-09-24 ~05:40 UTC).
- Unstaged binary-diff sha1 `6b1817d9d5f4f0f322d2a0b510c28bab2612a2ae`;
  reviewed-scope fingerprint (SHA256 over unstaged diff + sorted untracked
  paths with per-file content hashes)
  `ea36afdfb9764e3c5b6c67a2f2c2bdf66bcef0d43af92413d2f32da1b99ed846`.
- Reviewed package: Version1031 in-place extension (`pauses` table +
  occurrence suspension/restart columns), `Pause`/`PauseMapper`,
  `PauseProjectionService`, `DailyCapacityService`, `AgreementService`
  (+`AgreementCommandContext`), `PauseService`, `OccurrenceController`,
  `PauseController`, routes (pin 70→79), `CompletedTaskMapper` frozen-energy
  join, `SchedulePlanService` command/minor-unit cutover (fingerprint v2,
  CR-SVC-64 ordering), selection/token pause integration, TimelineJob pass,
  erasure/reset/departure hooks, `scheduling.js` commandId, l10n EN/DE/POT,
  ADR-013 note, api README, and the test-suite updates/new suites listed in
  the plan's Implementation Record.
- The three pre-existing unrelated `.gitkeep` deletions
  (`docs/plans/{active,new,onhold}/`) stayed excluded from the review package
  and from the commit, consistent with prior rounds.

## Outcome

`NEEDS-FOLLOW-UP` — the delivered increment is green on every runnable gate
and seven defects were safely fixed in-round (three of them block-level: the
new pause and occurrence-agreement endpoints were dead on arrival, and the
planner apply broke the receipt size bound on larger batches), but 7 major
logic findings, 7 evidence/docs gaps and 5 minor/nits remain open. All are
plan-owned (RF-073-WP2-01..18 in the plan's §9) and block the `done/` move.
No lifecycle transition; evidence-only commit per the NEEDS-FOLLOW-UP rule —
the implementation files (including the verified safe fixes) remain
uncommitted in the working tree for the fix round.

## Fixed In-Round (safe fixes; gates rerun afterwards)

| ID | Sev | File(s) | Issue → Fix |
|----|-----|---------|-------------|
| SF-1 | block | `lib/Controller/PauseController.php` | `create()` forwarded the `scope` selector into `PauseService`'s closed-shape validation → every `POST /pauses` answered 400. Controller consumes/unsets the selector; unit test pins the shape. |
| SF-2 | block | `lib/Controller/OccurrenceController.php` | `run()` forwarded `roomTaskId` into `AgreementService`'s closed-shape validation → every amend/catch-up/restart answered 400. Extracted + unset; closures take the explicit id; unit test pins the shape. |
| SF-3 | block | `lib/Service/SchedulePlanService.php` | `applyAggregate` stored full `roomTasks` bodies in the receipt refs → `CommandService`'s 8 KiB bound rejected larger batches (plan allows 100 rows) AFTER the writes. Compact refs; fresh and replay answers both rebuild authorized rows post-commit. |
| SF-4 | minor | `lib/Service/SchedulePlanService.php` | `authoritativeRoomTasks` used an undefined `$tz` → derived `status`/`daysUntilDue` rendered in the server zone + PHP warning. Resolves the house timezone. |
| SF-5 | minor | `lib/Service/PauseService.php` | `recognizeIllness` receipt payload omitted `subjectUserId` → reused UUID with a different subject replayed instead of conflicting. Included in the payload hash. |
| SF-6 | minor | `lib/Service/PauseService.php` | `listForViewer` N+1 (`findByGroup` + `findByHouseId` per row). Single pass latest-per-group + one wallet map. |
| SF-7 | nit | `lib/Service/AgreementService.php` | Second class `AgreementCommandContext` in the same file broke PSR-4 one-class-per-file autoloading. Moved to its own file. |

## Open Findings (plan-owned; full text in the plan's §9)

- **Major logic (7):** RF-01 amend-date writer re-selects its own target
  instead of the resolved key (wrong occurrence moved; stale result version);
  RF-02 handover deletes instead of persisting the neutral release; RF-03
  overlapping pause provenance overwrites instead of composing as a union
  (frozen §3.4 rule); RF-04 catch-up with participants loses the work
  appointment on replace; RF-05 virtual→materialized command boundary skips
  suspension stamping (restart rejects suspended virtual predecessors; amend
  can move derived-suspended targets); RF-06 planner apply carries/records no
  consent attestation; RF-07 helper commitments skip the command capacity
  checks.
- **Evidence gaps (5):** RF-08 `DailyCapacityServiceTest` missing; RF-09
  `PauseAgreementConcurrencyTest` missing; RF-10 required extensions missing
  (`OccurrenceTimelineTest`, `RoleGuardApiTest`, `AccountErasureTest`,
  `HouseMemberRemovalIntegrationTest`, `CompletionServiceTest`); RF-11
  CR-SVC-64 midnight-409 proof missing; RF-12 client contract specs + E2E
  scenarios/required-scenarios/gate untouched and unrun.
- **Client/docs (2):** RF-13 minimum client surface incomplete (share labels,
  consent controls, neutral/overload clarification); RF-14 durable docs
  missing (ADR-012, engineering architecture/testing, three concept docs,
  release-test-plan).
- **Minor/nit (4):** RF-15 frozen-energy LEFT JOIN COALESCE fallback masks
  missing occurrence links; RF-16 capacity evaluator re-collects 2-3× per
  preview; RF-17 six files over the ~300-line guideline without recorded size
  exceptions; RF-18 kind-label/409-vs-404/shorten-pending/pause_ref nits.

## Checklist Verification (shared Standard checklist)

- **Architecture**: thin controllers → services → mappers respected;
  `PauseProjectionService` correctly kept as a lower evaluator (no DI cycle);
  `OccurrenceAgreementService` remains the single under-lock occurrence
  writer. One PSR-4 violation fixed (SF-7). File-size guideline breaches
  recorded (RF-17).
- **Authorization**: all nine new routes `@NoAdminRequired`, none
  `@PublicPage`; membership asserted in every controller; owner gates in the
  services; foreign/invisible targets 404 via house-scoped fetch +
  `TaskAccessPolicy`; authorize-before-replay honored in both command
  coordinators; commandId validated after the owner gate (403 before 400).
- **Security**: closed payload shapes with unknown-field rejection; bounded
  enums (reason codes, roles, scopes); parameterized SQL everywhere
  (spot-audited all new mapper methods); no secrets/PII logged (receipt
  payloads carry bounded codes/ids only); erasure extended to pause
  subjects/requesters/deciders and consent snapshots; no `v-html`; error
  responses via `ControllerErrorFormatter` (no stack traces).
- **KISS/duplication**: reuses WP1 writers/facade, `CommandService`, wallet
  identity, existing selection machinery; no parallel recurrence engine.
  Perf duplication inside `DailyCapacityService` recorded (RF-16).
- **Workarounds**: none; no `// HACK:` introduced; no suppressed warnings.
- **Logging**: no raw `console.*` in the client diff; backend adds no
  unstructured logging.
- **i18n**: all 32 new backend strings via `t()`; EN/DE/POT regenerated
  idempotently (989 keys exact; localization gate green).
- **Validation/tests**: new unit suites for the projection rules, pause
  commands, agreement validation and both controllers; DB-backed
  `PauseAgreementTest` (8 tests) + schema surface; existing suites updated
  (constructor seams, route pin, measured query budgets with justification,
  commandId contracts). Gaps recorded as RF-08..12.

## Decisions (batched question tool, answered 2026-09-24)

1. **E2E gate timing** → *Defer to the fix round* (recommended): the gate and
   the new required scenarios run once, after the major findings are resolved;
   running now would only re-verify unchanged legacy flows. Recorded as
   RF-073-WP2-12.
2. **Fix-round sequencing** → *End review, fix round next* (recommended):
   evidence-only commit now; a dedicated implementation round resolves
   RF-01..18 + missing tests/docs, then a final Standard B re-review owns the
   `done/` move.

## Gates (rerun after the safe fixes)

| Gate | Result |
|------|--------|
| PHPUnit unit (local) | OK — 1023 tests, 3005 assertions |
| PHPUnit integration (Docker, fresh `down -v` chain, `--fail-on-skipped`) | OK — 412 tests, 1866 assertions, zero skips |
| Jest (`npm test`) | OK — 1070 tests, 100 suites |
| ESLint (`npm run lint`) | clean |
| php-cs-fixer (`cs:check`) | 0 of 349 fixable |
| webpack production build | compiled successfully |
| `l10n:check-keys` | 989 source/catalog keys, EN/DE/POT exact |
| `docs:encoding` | 771 tracked files clean |
| `test:noise` | clean |
| `backlog:check` | green (26 scoped files link-checked) |
| `git diff --check` | clean |
| `test:e2e:gate` | NOT RUN — deferred by decision 1 (RF-12) |

## Tracking

- `ACTIVE_TASKS.md`: In Progress entry updated (review outcome, fix round is
  the next step).
- `backlog.md`: CR-SVC-64 stays In Progress with a note — the mechanism
  (fingerprint v2 `evaluationDay` + 409-before-relative-date ordering) is
  implemented, but the item closes only when the RF-11 proof test passes.
  FEAT-UX-025 unchanged (backend slice partially delivered; UI portions stay
  WP6). CONCEPT-001 and the parent stay open.
- Plan: §9 findings checklist + Review Outcome appended; header status
  updated; plan remains in `active/`.

## Commit

Evidence-only scoped commit on `master` (this record, the plan's §9/Review
Outcome/status updates, `ACTIVE_TASKS.md`, the CR-SVC-64 backlog note). No
implementation file, no lifecycle move, no branch/PR action. The unrelated
`.gitkeep` deletions remain untouched in the working tree.

---

## Standard B Re-review at 75f2da3

**Date:** 2026-09-24. **Tier:** Standard B, finalization attempt, no subagents.
**Outcome:** `BLOCKED`. **Owning plan:**
[CS-PLAN-073](../../plans/active/cs-plan-073-wp2-agreements-capacity-pauses.md).

### Scope and Capture

The explicit plan path identifies ownership; `uncommitted` selects the remaining
WP2 package, not merely the unchanged plan Markdown. Captured 47 modified tracked
files plus 15 untracked files. The three unrelated
`docs/plans/{active,new,onhold}/.gitkeep` deletions remain excluded and untouched.

- Branch: `master`; HEAD: `75f2da3e1fe3f8b3f46d128ad6f92263649bdb78`.
- Index empty; index tree: `10c93ac062abac95b4707383becd2b65598b9142`.
- Staged binary-diff SHA-256 (empty): `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`.
- Unstaged scoped binary-diff SHA-256: `6d935e5f602679abe058911827f5d9a1db063f0dd8a4db4d2e8a362799775f2a`.
- Complete scope SHA-256: `fe55523f55f8792c402464a10a9dfdbca4601589b32d9d2191d51e441412e712`.
- Recipe: SHA-256 of staged `git diff --cached --binary -- <manifest>`, NUL,
  unstaged `git diff --binary -- <manifest>`, NUL, then compact JSON of sorted
  `[relativePath, git hash-object contentHash]` pairs for every untracked path.
  Review-owned documentation is outside this captured implementation fingerprint.

Exact captured manifest:

```text
appinfo/routes.php
docs/adr/ADR-013-occurrence-agreements-and-corrections.md
docs/api/README.md
l10n/de.json
l10n/en.json
l10n/templates/chorestrike.pot
lib/BackgroundJob/TimelineJob.php
lib/Controller/OccurrenceController.php
lib/Controller/PauseController.php
lib/Controller/RoomTaskController.php
lib/Controller/SchedulingController.php
lib/Db/AgreementMapper.php
lib/Db/CompletedTaskMapper.php
lib/Db/Occurrence.php
lib/Db/OccurrenceMapper.php
lib/Db/Pause.php
lib/Db/PauseMapper.php
lib/Migration/Version1031Date20260918000000.php
lib/Service/AccountErasureService.php
lib/Service/AgreementCommandContext.php
lib/Service/AgreementService.php
lib/Service/CompletionService.php
lib/Service/DailyCapacityService.php
lib/Service/HouseService.php
lib/Service/OccurrenceAgreementService.php
lib/Service/OccurrenceCompletionService.php
lib/Service/OccurrenceSelectionService.php
lib/Service/OccurrenceService.php
lib/Service/PauseProjectionService.php
lib/Service/PauseService.php
lib/Service/RoomTaskReadService.php
lib/Service/SchedulePlanService.php
lib/Service/TestDataService.php
src/store/scheduling.js
tests/e2e/scheduling-apply-flow.spec.ts
tests/integration/Db/FamilyRhythmSchemaTest.php
tests/integration/Db/WorkflowQueryBudgetTest.php
tests/integration/IntegrationTestCase.php
tests/integration/Service/ConcurrentCompletionTest.php
tests/integration/Service/PauseAgreementTest.php
tests/integration/Service/RoomTaskServiceExtendedFeaturesTest.php
tests/integration/Service/SchedulePlanAggregateTest.php
tests/integration/Service/SchedulePlanConcurrencyTest.php
tests/unit/BackgroundJob/TimelineJobTest.php
tests/unit/Controller/OccurrenceControllerTest.php
tests/unit/Controller/PauseControllerTest.php
tests/unit/Controller/SchedulingControllerTest.php
tests/unit/RouteMatrixTest.php
tests/unit/Service/AccountErasureServiceTest.php
tests/unit/Service/AgreementServiceTest.php
tests/unit/Service/HouseServiceLoggingTest.php
tests/unit/Service/HouseServiceTest.php
tests/unit/Service/OccurrenceServiceTest.php
tests/unit/Service/PauseProjectionServiceTest.php
tests/unit/Service/PauseServiceTest.php
tests/unit/Service/RoomTaskReadServiceTest.php
tests/unit/Service/RoomTaskServiceDailyTest.php
tests/unit/Service/RoomTaskServiceLoggingTest.php
tests/unit/Service/RoomTaskServiceTest.php
tests/unit/Service/SchedulePlanServiceTest.php
tests/unit/components/SchedulePlanner.spec.js
tests/unit/store/scheduling.spec.js
```

### Review Coverage and Findings

Reviewed the canonical skills/checklist, active tracking, full owning plan and
its manifest/test/acceptance requirements, previous review record and recent
implementation history. Inspected agreement command normalization/writers,
pause projection, planner/client changes, capacity gates, completed-energy
query and agreement unit coverage. The missing required suites are a critical
completion blocker; this round stops short of an exhaustive review of all 62
paths and makes no security, fresh-migration or full-manifest clearance claim.

| Severity | Finding | Current evidence | Status |
|---|---|---|---|
| block | RF-08/RF-09 required suites absent | No `DailyCapacityServiceTest.php` or `PauseAgreementConcurrencyTest.php` under `tests/`; plan section 4 explicitly requires both | Open |
| major | RF-19 receipt hash omits semantic command inputs | `AgreementService.php:435-441` returns only common metadata; `CommandService.php:114-124` hashes that payload and replays before invoking the domain callback | New, plan-owned |
| major | RF-20 catch-up projection depends on unrelated pause presence | `PauseProjectionService.php:148-156` returns the default appointment before reading members when no recognized pause exists | New, plan-owned |
| major | RF-21 joint planner placement validates one member and double-counts replacements | `SchedulePlanService.php:739-768` returns the full candidate set after one full-energy check; old allocation removal happens afterwards at `:594-606` | New, plan-owned |
| major | RF-22 selection token accepted but never checked | `AgreementService.php:358,373-388` stores the token but target validation only checks completion/version | New, plan-owned |
| major | RF-01..07 remain | Wrong-target date selection, deleted release rows, overwritten pause provenance, lost catch-up appointment on member replacement, missing command suspension derivation, planner consent omission, skipped helper capacity | Open |
| evidence/client/docs | RF-10..14 remain unclosed | Required test extensions, browser scenarios, minimum client and durable-doc requirements lack completion evidence | Open |

No runtime fix applied: new findings require behavior/validation changes outside
the safe review auto-fix categories. Corrected RF-01's overly broad wording:
an expired older miss causes a conflict, not a successful date write; another
amendable occurrence can still be selected instead of the supplied key. Existing
RF-15..18 were not closed or treated as verified resolved.

### Current Gate Results

| Command | Result |
|---|---|
| `npm test -- --runInBand` | 100 suites / 1070 tests passed; known TECH-JEST-001 duplicate-worktree-mock warnings |
| `npm run lint` | Passed, check-only |
| `npm run build` | Production webpack build passed |
| `docker exec chorestrike-test vendor/bin/phpunit --configuration phpunit.unit.xml --fail-on-skipped` | 1023 tests / 3005 assertions, zero skips |
| `docker exec chorestrike-test vendor/bin/phpunit --configuration phpunit.integration.xml --fail-on-skipped` | 412 tests / 1866 assertions, zero skips |
| `docker exec chorestrike-test composer cs:check` | 0 of 349 files need fixes |
| `npm run l10n:check-keys` | 989 keys, exact source/POT/EN/DE coverage |
| `npm run test:noise` | Passed; synthetic browser-gate failure output is a passing harness test, not E2E execution |
| `npm run test:e2e:gate` | Not run, explicit decision below; still required |

Docker containers were healthy and the existing environment was used. No
disposable-volume reset/fresh-install proof or browser/mobile validation was
repeated. Passing existing tests cannot replace the missing required suites.
Post-edit gates passed: `npm run backlog:check` (26 scoped files),
`npm run docs:encoding` (772 tracked files), and `git diff --check`.
Final capture comparison reproduced the exact 62-path implementation hash
`fe55523f55f8792c402464a10a9dfdbca4601589b32d9d2191d51e441412e712`;
branch/HEAD and empty-index diff hash were unchanged.

### Decision and Finalization

The structured question offered `Retain deferral (Recommended)` versus
`Run existing gate`. The user selected **Retain deferral (Recommended)**:
run the complete browser gate in the fix round once required WP2 scenarios
exist. This is not a scope reduction, test waiver, or permission to finalize.

- Plan section 9 adds RF-19..22; no new in-scope backlog items.
- TECH-JEST-001 absorbs this round's repeated warning evidence; its tooling
  fix remains out of scope. No warnings suppressed or worktrees changed.
- Plan header, Review Outcome and ACTIVE_TASKS updated; all acceptance and
  backlog-completion states remain unchanged.
- **No lifecycle transition or automatic commit.** Canonical review section 5
  classifies missing required gates as `BLOCKED`, including where a previous
  round used `NEEDS-FOLLOW-UP` with a deferral. The earlier record is retained
  as history, not reused as authorization or proof of completion.
- Review-owned documentation remains uncommitted; implementation and unrelated
  changes are preserved. Resume the implementation/fix round before another
  full finalization attempt.
