# CS-PLAN-062: WP3 — Atomic House Setup and Structure

> **Status:** Done (final Standard B review PASS-WITH-FIXES 2026-09-05; all §8
> acceptance criteria verified, gates rerun green by the review; see §10)
> **Effort:** M
> **Priority:** P1
> **Depends on:** [CS-PLAN-058](cs-plan-058-core-workflow-bottom-up-masterplan.md) (active; this plan executes its work package WP3 — WP0, WP1, WP2 are Done), WP2 authorization baseline (2026-09-04, ADR-010)
> **Related backlog items:** CR-WF-03 setup/structure half (owned); delivers CR-WF-05's empty-house-profile criterion as the WP3 exit-gate artifact (annotated, CR-WF-05 umbrella stays WP9); CR-WF-08 wizard-usability line served for the setup surface only (umbrella stays WP7)
>
> **Implementation note (2026-09-04):** payload validation runs before the
> in-transaction gate (per §3.1's numbered order), so the synthetic
> invalid-payload 409 probe in `release-setup-wizard.spec.ts` was updated to a
> fully valid payload — the 409 envelope/contract itself is unchanged and still
> pinned by that spec. With the rename folded into the setup command, the
> wizard no longer refreshes the house store via a separate PUT, so
> `Dashboard.handleWizardApplied` additionally refetches the house payload
> (one added line beyond the §5 manifest); the invite step no longer
> auto-finishes on a fully successful batch so the per-recipient joined
> statuses stay visible (explicit Finish button, label switches from
> "Skip and finish" to "Finish" when nothing is pending). `MemberList` and its
> spec already consumed the house-user payload directly, and
> `src/store/setup.js` needed no change (the shared API client already
> surfaces `ApiError.status`/`code` for the 409 recovery) — both manifest
> entries verified as no-ops.
>
> **Gate record (2026-09-04/05):** unit (host) 635 OK; Docker unit 822 OK;
> Docker integration 270 OK (+10 pre-existing documented skips, no new skips);
> Jest 813 OK; ESLint + `cs:check` + `l10n:check-keys` + `docs:encoding` +
> `backlog:check` + `test:noise` + `git diff --check` clean. Playwright
> `chromium` + `chromium-serial-db` + `chromium-scheduling-owner`: the WP3
> surfaces (setup-wizard-journey ×2, release-setup-wizard ×2) pass in every
> run; the remaining matrix shows pre-existing serial-pool flakes —
> `insights-flow.spec.ts:121` fails deterministically **on a clean `master`
> tree too** (stash-verified; date-sensitive, tracked + annotated as
> BUG-E2E-005), and `dashboard.spec.ts:49` / `error-handling.spec.ts:78` /
> `room-management.spec.ts:179` fail only sporadically under full-matrix load
> and pass in isolation (27/27 verified). Final ruling on the gate belongs to
> the Standard B review.

## 1. Problem Summary

CS-PLAN-058 §3/WP3 requires first-run setup to become one reliable owner
operation: the empty-house assertion and house lock inside the setup
transaction, the validated house name part of the setup command, one
rollback-safe aggregate for floors/rooms/auto rooms/materialized tasks/
initial room-task links, membership revalidation under the transaction
boundary, recoverable per-recipient invitation feedback, and an empty-house
E2E profile that completes the wizard entirely through the UI. WP2 left the
setup surface authorized (owner gate in `HouseSetupService`, bootstrap
preamble in the designated `setup#apply` endpoint) but did not touch setup
atomicity. The live tree does not yet satisfy the WP3 mandate; all facts
below were verified on 2026-09-04.

### 1.1 First-run gate and house lock are outside the setup transaction (CR-WF-03)

- The empty-house assertion runs in the controller **before** the service
  transaction: `SetupController::apply` checks
  `HouseService::hasFloors` (`lib/Controller/SetupController.php:105-113`)
  and only then calls `HouseSetupService::applySetup`, whose transaction
  begins later (`lib/Service/HouseSetupService.php:112`). Two concurrent
  `apply` requests can both pass the gate and both create the full
  structure (TOCTOU) — there is no house lock anywhere in the setup path.
- The lock primitive exists and is the documented first lock in the
  deterministic order (`HouseMapper::findForUpdate`,
  `lib/Db/HouseMapper.php:118-139`; order `house → room_task →
  task/assignee/reward` in `lib/Db/TransactionExecutor.php:24-31`) — the
  setup aggregate simply never acquires it.
- `HouseService::hasFloors` (`lib/Service/HouseService.php:337-339`)
  materializes every floor entity for a boolean; the in-transaction gate
  needs a count/exists primitive instead.

### 1.2 The house name is not part of the setup command (CR-WF-03)

- The wizard collects the house name (step 1) but the apply payload has no
  `houseName` field. The name is applied **after** a successful apply via a
  separate owner-gated `PUT /house` call
  (`src/components/Setup/SetupWizard.vue:512-521`), and a failure there is
  swallowed — only logged, while the wizard advances to the invite step
  (`:518-520`). The house can end up set up under the default name with no
  user-visible error.
- CS-PLAN-058 WP3 requires the validated house name inside the setup
  command and transaction; `HouseSetupService::applySetup`
  (`lib/Service/HouseSetupService.php:93-202`) never touches it today.

### 1.3 Assignee membership is validated only before the transaction (CR-WF-03)

- `validateFloors` checks every room's `assigneeUserId` against
  `HouseUserMapper::findByHouseAndUser`
  (`lib/Service/HouseSetupService.php:283-292`) — **before** `begin()`.
  A membership removed between validation and the writes would leave
  initial `task_assignees` rows pointing at a former member. CS-PLAN-058
  WP3 requires revalidating the relevant membership under the transaction
  boundary.

### 1.4 Invitation feedback is count-only and the structural outcome can be misreported (CR-WF-03)

- Wizard step 4 invites per email via `userStore.inviteUser`
  (`src/components/Setup/SetupWizard.vue:535-566`); feedback is a success/
  failure **count** (`:549-559`) — the user cannot tell *which* email
  failed or who joined.
- The store's invite contract disagrees with the backend:
  `src/store/user.js:134-136` branches on `data.exists` / `data.user`,
  but `UserController::invite` returns the house-user object directly as
  `data` (`lib/Controller/UserController.php:205-209`) — the branch is
  dead and a joined user is never surfaced from the response.
- Combined with §1.2, the wizard can report "set up + invites sent" while
  the house-name write silently failed — CS-PLAN-058 WP3 requires
  per-recipient invitation results and never misreporting the structural
  setup outcome.

### 1.5 The wizard never shows what was created (CR-WF-03 / CR-WF-08 setup line)

- `applySetup` returns `{floors, rooms, tasks, roomTasks, skipped}`
  (`lib/Service/HouseSetupService.php:195-201`); the wizard stores it in
  `applyResult` (`src/components/Setup/SetupWizard.vue:510`) and never
  renders it — step 4 opens without telling the owner what exists now or
  which templates were skipped at the chosen level.

### 1.6 Wizard accessibility and mobile gaps (CR-WF-08 setup surface)

- The `.room-remove` icon button has no 44 px target and no accessible
  name (`src/components/Setup/SetupWizard.vue:124-129`, style `:669`);
  `.wizard-skip` and `.invite-add` are under-sized text buttons
  (`:616-625,:703`). `.invite-remove` already meets 44 px (`:701`).
- The modal overlay has no focus trap and closes on backdrop click,
  discarding all input (`:10`); the step list carries no `aria-current`
  (`:22-35`). CS-PLAN-058 WP3 requires keyboard-only usability, clear
  labels, and ≥ 44 px touch targets for the wizard.

### 1.7 No empty-house E2E profile — the WP3 exit gate is unreachable (CR-WF-05 criterion delivered here)

- `release-setup-wizard.spec.ts` documents the gap verbatim: "an E2E happy
  path needs a user-bearing empty profile which the test fixtures do not
  provide" (`tests/e2e/release-setup-wizard.spec.ts:10-14`); it covers only
  the catalog read and the family-profile 409 gate (`:47-63`).
- `TestDataService` knows only `empty` (no users, `:214-218`), `minimal`
  (structure already present → wizard 409s), and `family`
  (`lib/Service/TestDataService.php:515-524`);
  `PROFILE_USERS.empty` is empty
  (`tests/e2e/fixtures/database-fixtures.ts:21-22`).
- The Nextcloud accounts of the family profile survive
  `TestDataService::reset()` (only `chorestrike_*` tables are truncated —
  `tests/e2e/auth.setup.ts:18-19`), so an authenticated empty-house profile
  can reuse the existing family logins.

### 1.8 What already holds (preserve, do not redesign)

- Owner gate inside the service (`HouseSetupService.php:94`, WP2 §3.4) with
  the bootstrap preamble staying in the designated endpoint.
- Full pre-write validation (level, options, floor/room names, room types,
  capacity caps, assignee membership) and one rollback-on-Throwable
  transaction (`:96-98,:112-193`).
- Auto rooms (hallway per floor, household room with basement rule,
  `:352-384`), level-exclusive skip reporting, and BUG-TEMPLATE-001
  non-swallowing skip logs (`:126-153`).
- Setup room tasks keep the WP1-canonical fields; their fixed `now + 1d`
  initial due date (`:109-110`) stays — creation-due-date/flex semantics
  are WP4/WP6 scope, not WP3.

## 2. Strategy

Harden the existing aggregate in place; no second setup surface, no task-
creation changes (WP4), no completion/scheduling/UX-program work
(WP5/WP6/WP7). Each step starts from a failing or missing test
(CS-PLAN-058 §2.3):

1. **Move the first-run gate into the setup transaction**: `applySetup`
   opens the transaction, locks the house row (`findForUpdate` — first lock
   of the documented order), rechecks floor existence via a new count
   primitive, and throws `ConflictException` (`HOUSE_ALREADY_SET_UP`) on
   violation. The controller's pre-check is deleted (one gate owner) and
   maps the exception to the unchanged 409 envelope.
2. **Fold the house name into the setup command**: optional `houseName`
   field, validated like other names, written to the house row inside the
   same transaction (decision D1). The wizard sends it with the payload and
   drops the separate post-apply rename call.
3. **Revalidate assignee memberships under the lock** before the writes
   (fast-fail pre-validation stays for UX).
4. **Make the wizard report truthfully**: render the apply result (what was
   created, what was skipped, confirmed house name), show per-recipient
   invite statuses, keep all input on recoverable validation failures, and
   render a mid-wizard 409 as a recoverable "already set up" state
   (decision D4/D5).
5. **Fix the invite store contract** to the real backend payload so
   per-recipient success can name the joined user (decision D5).
6. **One accessibility pass over the wizard**: ≥ 44 px targets, accessible
   names for icon buttons, focus trap + Esc, `aria-current` on steps.
7. **Add the `emptyhouse` E2E profile and the full wizard journey spec**
   (serial pool): bootstrap → owner → wizard → persisted structure →
   invite → invited member view, plus a forced-failure zero-write proof
   (decisions D6/D7).
8. **Durable docs + backlog dispositions**; full quality gate.

## 3. Backend / Frontend Changes

### 3.1 Transactional first-run gate and house lock (CR-WF-03)

- New `FloorMapper::countByHouseId(int $houseId): int` primitive
  (single COUNT query, prepared statement).
- `HouseService::hasFloors` delegates to it (behavior unchanged).
- `HouseSetupService::applySetup` restructured:
  1. `requireHouseOwner` (unchanged, pre-transaction).
  2. Full payload validation (unchanged: level, options, floors, names,
     room types, assignee membership pre-check).
  3. `transactions->run(...)`: first statement acquires
     `HouseMapper::findForUpdate($houseId)` (house-first lock order),
     then `countByHouseId > 0` → throw
     `ConflictException('HOUSE_ALREADY_SET_UP')` with the existing
     localized message; otherwise create floors/rooms/auto rooms/tasks/
     room-task links/assignees exactly as today, plus §3.2/§3.3.
- `SetupController::apply`: delete the `hasFloors` pre-check; catch
  `ConflictException` → 409 with the unchanged
  `HOUSE_ALREADY_SET_UP` code/message (envelope unchanged, so
  `release-setup-wizard.spec.ts:47-63` stays green).

### 3.2 House name in the setup command (CR-WF-03)

- `setup#apply` payload gains optional `houseName` (decision D1):
  absent/blank → keep the current name; present → trim, validate
  ≤ `MAX_NAME_LENGTH` (reuse `validateName` with a `house` context),
  and set it on the locked house entity inside the transaction.
  Invalid name → `\InvalidArgumentException` → 400 `VALIDATION_ERROR`
  before any write.
- Response gains `houseName` (the effective name) so the wizard can
  confirm it.
- `SetupWizard.vue`: include `houseName` in the apply payload; remove the
  post-apply `houseStore.updateHouseName` call and its swallowed-failure
  path (`:512-521`). `HouseService::updateHouse` stays for the Settings
  rename surface (unchanged).

### 3.3 Membership revalidation under the transaction (CR-WF-03)

- Inside the transaction, before any assignee write, re-run
  `HouseUserMapper::findByHouseAndUser` for every distinct
  `assigneeUserId`; a miss throws `\InvalidArgumentException`
  (400, existing message shape) and rolls back every write of the run.
  The pre-transaction check remains as the fast-fail UX path.

### 3.4 Invitation feedback contract (CR-WF-03)

- Invitations stay **outside** the structure transaction and outside
  `setup#apply` (decision D4): wizard step 4 keeps per-recipient
  `user#invite` calls; the backend endpoint is unchanged (owner gate
  before email lookup, per-recipient 404 `USER_NOT_FOUND` — WP2).
- `src/store/user.js::inviteUser`: drop the dead `data.exists`/`data.user`
  branch; return/consume the house-user object the backend actually sends
  (decision D5). `MemberList` and its spec are adjusted to the same
  contract.
- `SetupWizard.vue` step 4: per-recipient status rows (email → joined /
  failed + localized reason code), replacing the count-only toast
  feedback; failures keep the step open with the failing rows editable
  (retry without re-typing). Structural success is reported from the apply
  response only — an invite failure can never read as a setup failure.

### 3.5 Wizard result, recovery, and accessibility (CR-WF-03 / CR-WF-08 setup surface)

- Step 4 header renders the apply result: confirmed house name, floor/
  room/task counts, and a localized note when templates were skipped at
  the chosen level (`skipped` ids → catalog labels).
- Recoverable failures preserve all input: validation 400s keep the user
  on the preview step with the localized message; a 409
  `HOUSE_ALREADY_SET_UP` (e.g. second tab raced ahead) renders an
  explicit "already set up — continue to the app" state instead of a
  generic error.
- Accessibility pass: `.room-remove` / `.wizard-skip` / `.invite-add`
  (and any remaining small control) get ≥ 44 px targets; icon buttons get
  `aria-label`s; the overlay becomes a labeled modal dialog with a focus
  trap, Esc closes (backdrop click no longer discards input silently —
  decision D8); step list items carry `aria-current="step"`.
- All new/changed strings through `t()`; `l10n/` artifacts updated in the
  same change.

### 3.6 Empty-house E2E profile (WP3 exit gate)

- `TestDataService`: new `emptyhouse` profile (decision D6) — `reset()`
  only (no ChoreStrike rows, no memberships); the Nextcloud family
  accounts already exist via the auth-setup family seed. Listed in the
  profiles endpoint with a description.
- `tests/e2e/fixtures/database-fixtures.ts`: `DatabaseProfile` gains
  `emptyhouse`; `PROFILE_USERS.emptyhouse` lists the family credentials
  the journey spec uses (mom as first owner, dad as invitee).
- `playwright.config.ts`: the new journey spec joins
  `SEED_DEPENDENT_SPECS` (serial pool — it reseeds).

### 3.7 Durable docs

- `docs/api/README.md` Setup Wizard section: `houseName` request/response
  field, 409 semantics enforced inside the transaction, invite feedback
  contract.
- `docs/concepts/house-setup-and-templates.md`: atomic setup contract
  (lock + gate + house name + revalidation), invitation feedback, and the
  empty-house E2E profile.
- No new ADR: setup atomicity refines ADR-007's transaction/lock doctrine
  (already recorded in `docs/engineering/development.md`); the lock order
  comment in `TransactionExecutor` is amended with the setup usage.

## 4. Tests

- **Unit (PHPUnit, host) — `HouseSetupServiceTest`, `SetupControllerTest`:**
  - `houseName` validation (blank keeps name, over-length → 400 path,
    trimmed value persisted) and response field.
  - Gate under lock: mocked mappers verify `findForUpdate` precedes the
    floor count; count > 0 → `ConflictException` before any create call.
  - Revalidation: assignee present pre-check but absent under the
    transaction → exception + `rollBack()` verified, no assignee writes.
  - Forced mid-transaction failure (decision D7): a mapper mock throws
    after floor/room creates → `rollBack()` called, `commit()` never
    (mock-level sabotage; the real-DB proof is the race + E2E pair below).
  - Controller: `ConflictException` → 409 `HOUSE_ALREADY_SET_UP` envelope;
    `houseName` passthrough.
- **Integration (Docker, real DB) — `HouseSetupIntegrationTest` extended,
  new `SetupApplyConcurrencyTest`:**
  - Apply with `houseName` persists name + structure atomically; second
    apply → 409 with zero additional rows.
  - Removed-member assignee (membership deleted between pre-validation and
    apply) → 400 and zero floors/rooms/tasks/assignees persisted.
  - Two-connection apply race following the established `proc_open`
    helper pattern (`BootstrapConcurrencyTest` precedent, MySQL/MariaDB
    only, documented SQLite skip): exactly one structure wins, the loser
    gets 409, floor/room/task counts are exactly single.
  - Controller-level matrix via `actingAs`: owner 200, member 403,
    non-member 403 (invite-only), already-set-up 409.
- **Jest — `SetupWizard.spec.js`, `setup.spec.js`, `user.spec.js`,
  `MemberList.spec.js`:**
  - Payload includes `houseName`; no `updateHouseName` call during setup.
  - Step 4 renders apply result (counts, skipped note, house name) and
    per-recipient invite statuses (joined name / failed reason); input
    preserved on validation error; 409 renders the recoverable state.
  - `inviteUser` consumes the house-user payload (no `exists` branch).
  - A11y attributes: 44 px class/labels on former icon buttons,
    `aria-label`s, `aria-current`, modal role + Esc behavior.
- **E2E (Playwright) — new `tests/e2e/setup-wizard-journey.spec.ts`
  (serial pool, `emptyhouse` profile):**
  1. Login as `mom` on the wiped instance → she bootstraps to owner
     (journey step 1) → wizard auto-opens (owner-gated Dashboard entry).
  2. Complete every wizard step through the UI: house name, floors/rooms,
     level, preview, apply.
  3. Assert the rendered result panel and the persisted structure via API
     (exactly the previewed floors/rooms, house name set, one owner).
  4. Invite `dad` (email provisioned via the `occ-runner` precedent from
     `members-owner.spec.ts`) → per-recipient success row; `dad` context
     sees the member view (no owner controls).
  5. Forced failure: a second apply attempt (and an invalid-assignee
     variant) proves the 409/400 paths with zero additional structure and
     the wizard input intact.
  - `release-setup-wizard.spec.ts` keeps its family-profile catalog/409
    assertions; its header comment about the missing profile is updated.
- **Gate (CS-PLAN-058 §4.5):** full gate including Docker unit +
  integration, `composer cs:check`, all applicable Playwright projects.

## 5. File Manifest

```
new:      tests/e2e/setup-wizard-journey.spec.ts
new:      tests/integration/Setup/SetupApplyConcurrencyTest.php
modified: lib/Controller/SetupController.php (gate deleted; ConflictException → 409; houseName threading)
modified: lib/Service/HouseSetupService.php (transactional gate + lock, houseName, revalidation)
modified: lib/Service/HouseService.php (hasFloors delegates to count primitive)
modified: lib/Db/FloorMapper.php (countByHouseId)
modified: lib/Service/TestDataService.php (emptyhouse profile + profiles list)
modified: lib/Controller/TestController.php (profile listing if enumerated there)
modified: lib/Db/TransactionExecutor.php (lock-order docblock setup usage — implied by §3.7)
modified: src/views/Dashboard.vue (house refetch after apply — implementation note)
modified: tests/integration/Controller/TestControllerTest.php (emptyhouse profile assertions)
modified: src/components/Setup/SetupWizard.vue (payload, result panel, per-recipient invites, recovery, a11y)
modified: src/store/setup.js · src/store/user.js (invite contract)
modified: tests/e2e/fixtures/database-fixtures.ts (emptyhouse profile + PROFILE_USERS)
modified: tests/e2e/release-setup-wizard.spec.ts (header comment)
modified: playwright.config.ts (SEED_DEPENDENT_SPECS entry)
modified: tests/unit/Service/HouseSetupServiceTest.php · tests/unit/Controller/SetupControllerTest.php
modified: tests/unit/components/{SetupWizard,MemberList}.spec.js · tests/unit/store/{setup,user}.spec.js
modified: tests/integration/Setup/HouseSetupIntegrationTest.php
modified: docs/api/README.md · docs/concepts/house-setup-and-templates.md
modified: docs/engineering/development.md (setup usage note in the locking section, if present)
modified: l10n/{en.json,de.json,templates/chorestrike.pot}
modified: docs/backlog/{backlog.md,ACTIVE_TASKS.md} (+ archive/2026-09.md completion entries — owned by the Standard B review)
modified: docs/plans/active/cs-plan-058-core-workflow-bottom-up-masterplan.md (§9 progress row WP3)
modified: docs/plans/new/cs-plan-062-wp3-atomic-house-setup-structure.md (this plan; moves to active/ on review pass)
```

## 6. Implementation Order

1. Write failing tests first: gate/lock + houseName + revalidation unit
   cases (red), integration house-name atomicity + zero-write cases (red),
   Jest payload/result cases (red).
2. §3.1 transactional gate + house lock + `countByHouseId`; controller 409
   mapping. Integration + unit green.
3. §3.2 house name in the command + §3.3 revalidation; tests green.
4. §3.6 `emptyhouse` profile (backend + fixtures + config) so the E2E
   target exists before the wizard changes land.
5. §3.4 + §3.5 wizard: payload, result panel, per-recipient invites,
   recovery states, invite store contract; Jest green.
6. Accessibility pass; Jest green.
7. E2E journey spec + forced-failure proof; `chromium`,
   `chromium-serial-db`, `chromium-scheduling-owner` green.
8. Full §4.5 gate; scoped `npm run lint:fix` beforehand.
9. §3.7 durable docs; backlog dispositions (CR-WF-03 criteria, CR-WF-05
   annotation), CS-PLAN-058 §9 WP3 row, `ACTIVE_TASKS.md`; request
   Standard B review.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Concurrent double-apply race test is flaky | Medium | Established `proc_open` two-connection pattern from `BootstrapConcurrencyTest`; MySQL/MariaDB-only with the existing documented SQLite skip style; deterministic lock holder |
| Moving the 409 gate changes the response contract | Low | Same code/message/envelope; `release-setup-wizard.spec.ts` 409 assertion guards it; controller pre-check deleted only after the service gate is green |
| House lock deadlocks with completion/assignment paths | Low | Setup only runs on a structurally empty house (no room_task rows exist yet); house-first lock order is the documented order start (`TransactionExecutor` docblock) |
| `houseName` payload change breaks existing consumers | Low | Field is additive/optional; wizard updated in the same step; Jest + integration pin the contract |
| `emptyhouse` reseed races parallel specs | Medium | Journey spec joins `SEED_DEPENDENT_SPECS` (serial pool, workers=1) like every seed-mutating spec; family NC accounts survive resets |
| Invite step needs a provisioned email in E2E | Medium | `occ-runner` email-provisioning precedent from `members-owner.spec.ts`; fallback: assert the per-recipient failure row for an email-less user |
| Scope creep into task creation (WP4) | Medium | Manifest freeze: only `HouseSetupService` touches materialization; setup due-date/flex semantics explicitly out of scope (§1.8) |
| A11y changes shift layouts E2E locators rely on | Low | Locators use stable `data-testid`s; style changes scoped to the wizard; journey spec exercises the same controls |
| Mid-transaction failure has no deterministic real-DB trigger | Medium | Decision D7: mock-level sabotage + real-DB race + E2E zero-write proof together; documented in the plan, not silently accepted |

## 8. Acceptance Criteria

- [x] The first-run gate is enforced inside the setup transaction: the
      house row is locked (`findForUpdate`) before the floor-existence
      recheck; the controller pre-check is gone (single gate owner); a
      two-connection concurrent apply yields exactly one structure and one
      409 (`SetupApplyConcurrencyTest`, documented SQLite skip only).
- [x] `houseName` is part of the setup command: validated, persisted
      atomically with the structure, returned in the response; the wizard
      performs no separate rename request during setup.
- [x] Assignee memberships are revalidated under the transaction boundary;
      an assignee removed before the write yields 400 and zero persisted
      floors/rooms/tasks/assignees (integration).
- [x] No setup failure path leaves partial structure: unit sabotage
      rollback, integration zero-write cases, and the E2E forced-failure
      step all pass (decision D7).
- [x] The wizard shows exactly what was created (house name, counts,
      skipped templates) and per-recipient invitation outcomes; invite
      failures never read as structural failures; input survives
      recoverable validation errors; a raced 409 renders a recoverable
      "already set up" state.
- [x] Invite store contract matches the backend payload (no dead `exists`
      branch); per-recipient success can name the joined user; MemberList
      updated to the same contract.
- [x] Wizard a11y: every interactive element ≥ 44 px, icon buttons carry
      accessible names, modal focus trap + Esc, `aria-current` on steps —
      asserted in Jest.
- [x] The `emptyhouse` E2E profile exists and the new journey spec
      completes the wizard entirely through the UI (bootstrap → owner →
      structure → invite → member view) in the serial pool with no
      conditional skips — the WP3 exit gate.
- [x] All setup strings localized (errors included), standard API envelope
      everywhere; `l10n:check-keys` green.
- [x] Build gate green (CS-PLAN-058 §4.5) with no new or unapproved skips
      (the two-connection race skip follows the existing documented
      MySQL-only inventory).
- [x] Owning backlog items dispositioned: CR-WF-03 setup-half criteria
      checked/annotated (task-creation half stays WP4), CR-WF-05
      empty-house criterion annotated as delivered by WP3's exit gate;
      CS-PLAN-058 §9 WP3 row updated. Final archive moves are owned by the
      Standard B review.
- [x] Durable docs updated (API README, setup concept, locking note) —
      plans are temporary.
- [x] All implementation decisions and review questions resolved and
      recorded.
- [x] Final Standard B review is `PASS` or `PASS-WITH-FIXES`;
      `ACTIVE_TASKS.md` updated.

## 9. Plan Review Decision Register

The Standard A review must ratify or revise these proposed decisions before
activating the plan:

| ID | Proposed decision | Rationale | Status |
|----|-------------------|-----------|--------|
| D1 | `houseName` is optional in `setup#apply`: absent/blank keeps the current name; present is trimmed, length-validated, and written inside the setup transaction; response echoes the effective name | CS-PLAN-058 WP3 mandates the house name in the command/transaction; optional keeps re-runs and API-only callers backward-compatible within the pre-release fresh-install policy | Ratified (Standard A, 2026-09-04) |
| D2 | The first-run gate moves entirely into `applySetup`'s transaction (house lock via `findForUpdate`, floor count recheck, `ConflictException` → unchanged 409 envelope); the controller pre-check is deleted | One gate owner closes the TOCTOU; the response contract stays identical for existing consumers | Ratified (Standard A, 2026-09-04) |
| D3 | Assignee memberships are revalidated under the lock inside the transaction (pre-transaction validation stays as fast-fail UX) | CS-PLAN-058 WP3 explicit requirement; prevents assignee rows for concurrently removed members | Ratified (Standard A, 2026-09-04) |
| D4 | Invitations stay outside the structure transaction **and** outside `setup#apply`: wizard step 4 keeps per-recipient `user#invite` calls; no new batch endpoint | Preserves the masterplan wizard order (apply → invite family); per-recipient results already exist per call; a folded post-commit invite phase would change the apply contract against KISS. The masterplan bullet "keep invitations outside the app-data transaction" is satisfied as a guardrail: no invite write ever joins the structure transaction | Ratified (Standard A, 2026-09-04) |
| D5 | Invite response contract normalized on the backend shape (`data` = house-user object); the store's dead `exists`/`user` branch is deleted and consumers (wizard, MemberList) use the house-user payload | One truthful contract; enables per-recipient success to name the joined user without backend changes | Ratified (Standard A, 2026-09-04) |
| D6 | New explicit `emptyhouse` E2E profile (reset-only; family NC accounts reused from the auth-setup seed) instead of reusing `empty` | Makes the WP3 exit gate self-documenting (`PROFILE_USERS` accurate); `empty` keeps its "no users" semantics for future use | Ratified (Standard A, 2026-09-04) |
| D7 | Forced-failure proof = mock-level rollback sabotage (unit) + real-DB race + E2E validation-failure zero-write; no deterministic mid-transaction mapper failure is forced through the real DB | A natural mid-transaction failure trigger does not exist after pre-write validation (materialization errors are skipped-by-design); the three-layer proof covers rollback, contention, and user-visible recovery | Ratified (Standard A, 2026-09-04) |
| D8 | Overlay backdrop click no longer dismisses the wizard; Esc closes (focus trap retains input otherwise); the Dashboard re-entry point stays available | Discarding a half-entered house on a stray click is the least family-friendly failure mode; dismiss remains possible via Esc/skip button | Ratified (Standard A, 2026-09-04) |

Any rejected decision requires this plan, its affected backlog acceptance
criteria, and the corresponding risk/test sections to be updated before the
review can pass.

## 10. Review Outcome

**Standard A plan review — PASS (2026-09-04).**

- **Scope:** this plan file — sole regular `*.md` candidate in
  `docs/plans/new/`, untracked at review start, SHA-256
  `F2011D4B0161D3D18E39F7A3B35E26A33D975C8FA8174EF37854FE14B6BEC09F`.
- **Base:** branch `master` @ `5e39e76`; index empty; the only other
  working-tree change was this plan's own draft-tracking entry in
  `docs/backlog/ACTIVE_TASKS.md` (review-owned, not unrelated drift).
- **Verification:** every §1 live-tree claim spot-checked against current
  sources — controller gate `SetupController.php:105-113`, transaction begin
  `HouseSetupService.php:112` (owner gate `:94`, return shape `:195-201`,
  rollback-on-Throwable `:190-193`), lock primitive/order
  `HouseMapper.php:118-139` / `TransactionExecutor.php:24-31`,
  `hasFloors` materialization `HouseService.php:337-339`, rename-swallow and
  count-only invite feedback `SetupWizard.vue:512-521,:549-559,:510`, dead
  store branch `user.js:134-136` vs backend payload
  `UserController.php:205-209`, E2E gap comment
  `release-setup-wizard.spec.ts:10-14` (409 assertions `:47-63`), profile
  inventory `TestDataService.php:214-218,:515-524`,
  `PROFILE_USERS.empty` `database-fixtures.ts:21-22`, NC-account survival
  `auth.setup.ts:18-19`, wizard a11y claims (`.room-remove` `:124-129,:669`,
  `.wizard-skip` `:616`, `.invite-add` `:703`, `.invite-remove` 44 px
  `:701`, backdrop `:10`, step list `:22-34`), and the cited test
  precedents (`BootstrapConcurrencyTest` `proc_open` + documented SQLite
  skip, `occ-runner` in `members-owner.spec.ts`, `SEED_DEPENDENT_SPECS`
  serial pool in `playwright.config.ts`) — **all exact**.
- **Alignment:** WP3 mandate cross-checked against CS-PLAN-058 §246-278
  (1:1); template sections complete; scope coherent; no overlap (child plan
  of the active masterplan, WP1/WP2 precedent); owning backlog IDs valid
  (CR-WF-03 setup/structure half owned; CR-WF-05/CR-WF-08 cross-delivery
  annotations only, umbrellas stay WP9/WP7); risks named with mitigations;
  no workaround-stacking (D7 is a documented proof strategy, not a silent
  workaround); acceptance criteria and test plan adequate for the risk.
- **Findings:** none (block/major/minor/nit: 0); no fixes required.
- **Decisions:** D1–D8 ratified by the user as proposed via one batched
  structured question (2026-09-04); register above updated.
- **Gates:** docs-only review — no source files changed, no test/lint gates
  due; factual verification stands in for the plan-review gate.
- **Transition:** `new/ → active/` with this commit; CR-WF-03 marked
  In Progress for the setup/structure half; `ACTIVE_TASKS.md` updated.

**Final Standard B code review — PASS-WITH-FIXES (2026-09-05).**

- **Scope:** the whole WP3 package in the working tree of branch `master`
  (base HEAD `861b3963c3cdbf7ab1a74180660ea67662da0cde`, index empty;
  unstaged-diff SHA-256 `F689746C88A34D495589215C780DD5AE3434A85B825A60D5B089FB664C3B870D`
  pre-fix) + untracked `tests/e2e/setup-wizard-journey.spec.ts` and
  `tests/integration/Setup/SetupApplyConcurrencyTest.php`, all 29
  §5-manifest paths (§5 entries verified; `TransactionExecutor.php`,
  `Dashboard.vue`, and `TestControllerTest.php` were modified per §3.7/the
  implementation note/§3.6 and added to the manifest), plus the full plan:
  all 14 §8 acceptance criteria, §4 test evidence, durable-doc integration,
  D1–D8 compliance.
- **Verification highlights:** gate order lock → floor recheck → membership
  revalidation → house-name write → materialization confirmed in
  `HouseSetupService::applySetup` (single gate owner; controller maps
  `ConflictException` to the unchanged 409 envelope); `houseName` validated
  pre-transaction (fail ⇒ zero writes), trimmed and echoed; D5 contract
  truthful in store/wizard/backend docs; D8 modal (focus trap, Esc, no
  backdrop discard, `aria-current`, 44 px + accessible names); `emptyhouse`
  profile + serial-pool journey without conditional skips; no SQL
  concatenation, no `console.*`, all new strings in `l10n/`.
- **Findings:** F1 minor-docs (manifest omissions — fixed inline);
  F2 minor-behavior (store `error` stayed set on the recoverable 409 so the
  red `wizard-error` line duplicated the recovery panel — fixed inline with
  a Jest assertion, user-ratified); F3 nit (`validateHouseName` treats a
  non-string `houseName` as absent — logged as CR-062-01 for WP4);
  F4 pre-existing (stylelint `use-logical` warning in `NotAuthorizedState.vue`
  — logged as TECH-LINT-007). Security/auth tracks: none.
- **Gate rulings (review re-runs on a fresh disposable env):** Jest 813;
  Docker unit 822; Docker integration 270 OK with the 10 pre-existing
  documented skips and no new skips (MySQL race tests included); ESLint,
  `cs:check` (0/270), `l10n:check-keys`, `docs:encoding`, `backlog:check`,
  `test:noise`, `git diff --check`, and webpack build green; Playwright
  `chromium` 92 passed (7 documented skips); `chromium-serial-db` 94
  passed with the WP3 surfaces 7/7 (twice, incl. after the F2 fix);
  sole residual `room-management.spec.ts:179` is the documented pre-existing
  load flake (10/10 in isolation, tracked under BUG-E2E-005/CR-WF-05),
  and `insights-flow:121` passed on this run. The plan's §Gate-record
  rulings are hereby accepted.
- **Decisions:** D1–D8 all confirmed implemented as ratified. Review
  questions resolved 2026-09-05: F2 inline fix, F3 backlog nit (user-ratified).
- **Transition:** `active/ → done/` with this commit; CR-WF-03 stays
  partially open (task-creation half ⇒ WP4), CR-WF-05/CR-WF-08 umbrellas
  remain (criteria already annotated as delivered); no fully completed
  backlog item to archive; review record:
  [`../../backlog/archive/CODE_REVIEW_2026-09-05.md`](../../backlog/archive/CODE_REVIEW_2026-09-05.md).
