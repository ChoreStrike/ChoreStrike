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
