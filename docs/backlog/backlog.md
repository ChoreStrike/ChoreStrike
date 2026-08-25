# Backlog — Open Items

> **Single source of truth for everything not yet done.** Bugs, features,
> tech-debt, and review follow-ups are consolidated here (the old `bugs.md` /
> `features.md` / `phase-9-*` / `phase-8-*` files are dissolved).
> **Done work does not live here** — completed items move to
> [`archive/YYYY-MM.md`](archive/) at completion. See [`README.md`](README.md)
> for the workflow and [`docs/standards/ai/rules/project-rules.md` §4](../standards/ai/rules/project-rules.md)
> for the rules.
>
> Priority: **P0** critical · **P1** high · **P2** should-have · **P3** nice-to-have · **P4** future.

---

## Bugs

### [P3] BUG-E2E-003 — `title-selection.spec.ts` "Selecting a pack marks it active" flaky
**Status**: Open · **Effort**: S · **Source**: CS-PLAN-038 full-suite gate run (2026-08-21)

`tests/e2e/title-selection.spec.ts:39` intermittently fails (initial attempt,
passes on retry) even standalone on the unmodified tree — **stash-verified**
during the CS-038 gate: the `active-pack-tag` does not appear within 10 s of
clicking `select-pack-button`. Likely a race between admin's house-membership
bootstrap (`ensureHouseMember` on `/users/current`) and the pack-selection PUT,
or a concurrent family re-seed wiping admin's house user mid-test in parallel
runs.

- [ ] Reproduce with tracing, identify the failing request (PUT vs membership
      bootstrap), fix the race or harden the spec's wait; keep it out of
      soft-skip territory.

---

### [P3] BUG-SEC-002 — `PUT /users/{id}/role` lacks self-demotion / last-owner guard
**Status**: Resolved 2026-08-24 (CS-PLAN-045) → [`archive/2026-08.md`](archive/2026-08.md)

---

### [P3] BUG-SEC-001 — Dedicated security reporting address + GPG key
**Status**: Backlog · **Effort**: S · **Source**: CS-001 security review

`SECURITY.md` points reporters to the Codeberg user profile (no private advisory
feature). A dedicated `security@` address + published GPG key would make private,
encrypted reporting frictionless.

- [ ] Dedicated security contact published in `SECURITY.md` + Codeberg profile.
- [ ] GPG public key published; fingerprint referenced in `SECURITY.md`.
- [ ] `SECURITY.md` "maintainer note" removed once the channel exists.

---

### [P3] BUG-ROOM-001 — Re-evaluate the `RoomController::index` POST→GET rewrite workaround
**Status**: Deferred (low) · **Effort**: S · **Source**: CS-020 (deferred AC)

The body-sniffing POST→GET rewrite workaround was left as harmless dead code
after the Docker env got correct `mod_rewrite` routing (`docker/apache-nextcloud.conf`).
Confirm whether production shapes still need it; if not, remove it.

- [ ] Confirm production routing; remove the workaround if no longer needed.

---

### [P3] BUG-E2E-001 — `3-floor-house.spec.ts`: 2 pre-existing failures in the serial pool
**Status**: Open · **Effort**: M · **Source**: CS-PLAN-031 gate run (2026-08-18)

Running the full `chromium-serial-db` project (family profile) fails 2 of 13
tests in `tests/e2e/3-floor-house.spec.ts`:

- `Navigation: Can navigate to all 3 floors from dashboard` (`:45`)
- `Task Management: Add cleaning task to Gästebad` (`:192` — times out
  waiting for a `Save`/`Create` button)

Verified **pre-existing** during the CS-031 gate: both tests fail identically
on the unmodified tree (stash-verified). All 16 `Release:*` specs pass; the
plan-level gates (`Release:*`) never covered this spec. Not related to any
CS-031 change (backend-only, no floor/task-create surface touched).

- [ ] Reproduce standalone, capture the error context, and fix the spec or
      the underlying UI flow; re-add to the blocking serial gate.

---

### [P3] BUG-E2E-002 — Own-card Edit button never visible in E2E → owner modal tests skip vacuously
**Status**: Resolved 2026-08-24 (CS-PLAN-043) → [`archive/2026-08.md`](archive/2026-08.md)

---

### [P4] BUG-UI-001 — Week-total/analysis display garbled while an energy input is empty or invalid
**Status**: Open · **Effort**: S · **Source**: Review of CS-PLAN-043 (2026-08-24)

`EnergyBudgetSettings` `totalWeeklyEnergy` and `BudgetAnalysis`
(average/peak/low cards) reduce over the budget map with plain `sum + energy`.
While a day input is cleared (`''`) or mid-typing invalid (`NaN`), the totals
degrade to string concatenation (`"1015"`) or `NaN` in the display. Verified
**pre-existing** (the old `v-model.number` component had identical semantics);
save is already guarded by up-front 1–20 validation, so this is display-only
during invalid intermediate input.

- [ ] Coerce/normalize non-integer budget values before the display reduces
      (e.g. treat them as 0 or the stored fallback) in both components; pin
      with a Jest case for an empty day input.

---

## Tech Debt / Code Quality

### [P3] SEC-AUDIT-001 — Security hardening follow-ups from P8-SECURITY-001 audit
**Status**: Open · **Effort**: M · **Source**: security audit 2026-03-05 (record archived 2026-08-20, DOC-STD-001)

- [ ] Rate limiting for sensitive endpoints (user management).
- [ ] Audit logging for owner-level operations.
- [ ] Document security assumptions in the API documentation (`docs/api/`).

---

### [P2] TECH-LINT-004 — Stylelint `use-logical` warnings (26, physical→logical property sweep)
**Status**: Resolved 2026-08-23 (CS-PLAN-044) → [`archive/2026-08.md`](archive/2026-08.md)

---

### [P3] TECH-LINT-006 — npm peer-dep ERESOLVE requires `--legacy-peer-deps`
**Status**: Open · **Effort**: S · **Source**: CS-026 dependency reinstall (2026-08-12)

`npm install` fails with `ERESOLVE`: `eslint-config-standard@17.1.0` wants
`eslint-plugin-n@^15 || ^16` but `package.json` pins `eslint-plugin-n@^17.24.0`
(via `@nextcloud/eslint-config@8.3.0`). The repo therefore installs with
`--legacy-peer-deps`. Pre-existing (not introduced by CS-026), but now documented.
Resolving it cleanly likely needs an `eslint-plugin-n`/`eslint-config-standard`
bump coordinated with the Nextcloud eslint config.

---

### [P2] P9-E2E-002b — E2E long-tail `waitForTimeout` cleanup
**Status**: Backlog · **Effort**: M–L · **Source**: carved out of CS-015

~13 specs still carry ~27 `waitForTimeout` calls + residual text/positional
selectors (`multi-user-interactions`, `smoke-tests`, `minimal-smoke-tests`,
`daily-tasks-flow`, `room-management`, + 1-offs). Migrate to
`getByTestId`/`getByRole` + auto-waiting. Prerequisite met: CS-018 proved the
suite flake-free (3× full-matrix `--retries=0`).

---

### [P2] P9-DOC-005 — `development.md` example-code audit
**Status**: Open · **Effort**: S · **Source**: CS-014 review (2026-07-26)

Two deferred example-code drifts: (1) a fictional
`TaskService::calculateNextDue(Task): \DateTime` in the coding-standards example
(no such method — scheduling is owned by `SchedulingService`/`TaskSchedulerService`
/`SmartSchedulerService`/`lib/Service/Scheduling/`); (2) the "new API endpoint"
example shows raw `axios.post('/apps/ChoreStrike/...')`, contradicting the
canonical `src/services/api.js` client enforced by `apiContract.spec.js`.

- [ ] Replace/drop the fictional `calculateNextDue` example; rewrite the endpoint
      example to use the shared `api` client.

---

### [P3] TECH-DOCS-001 — Recurring `?` corruption of non-cp1252 characters in backlog docs
**Status**: Open · **Effort**: S · **Source**: Review of CS-PLAN-035 (2026-08-19)

`docs/backlog/archive/2026-08.md` repeatedly accumulated literal `?` bytes in
place of `→` / `≤` (CS-033 and CS-034 commits carried them; the CS-035 review
repaired all of them). Signature: characters present in Windows-1252 (`—`, `§`)
survive, characters outside it (`→`, `≤`) become `?` — i.e. a write path that
mis-detects the file as ANSI while its content is still cp1252-safe, then
re-encodes on save. Files that already contain a non-cp1252 character are
detected as UTF-8 and write fine.

- [ ] After editing backlog/archive docs, byte-verify non-ASCII content
      (e.g. scan for literal `0x3F` in prose) or write with explicit UTF-8;
      consider a small `npm run docs:encoding` check in the doc-touching gates.

---

## Review Follow-ups — CS-016 (Scheduling UI)

> From [`archive/CODE_REVIEW_2026-07-28.md`](archive/CODE_REVIEW_2026-07-28.md).
> CR-016-11 is **resolved** (CS-018); CR-016-12 is **resolved** (CS-032);
> CR-016-02 is **resolved** (CS-033); the open UI-side items
> **CR-016-03/04/05/06/08/13/14** are **resolved 2026-08-24 by CS-PLAN-043**
> (record: [`archive/2026-08.md`](archive/2026-08.md)); **CR-016-15** is
> **resolved 2026-08-24 by CS-PLAN-045**. **CR-016-10** is
> **resolved 2026-08-24 by CS-PLAN-046**
> (record: [`archive/2026-08.md`](archive/2026-08.md)). All CS-016 review
> items are closed.

### [P2] CR-016-10 — Regression test for `getConsumedEnergyForUserOnDate` SQL fix
**Status**: Resolved 2026-08-24 (CS-PLAN-046) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] CR-016-09 — `DailyTaskList.vue` tech debt (pre-existing)
**Status**: Resolved 2026-08-21 (CS-PLAN-038, component archived) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] CR-016-15 — Shared backend energy-bounds validation (drift risk)
**Status**: Resolved 2026-08-24 (CS-PLAN-045) → [`archive/2026-08.md`](archive/2026-08.md)

---

## Review Follow-ups — Full Review of `lib/Service` (2026-08-04)

> From [`archive/CODE_REVIEW_2026-08-04.md`](archive/CODE_REVIEW_2026-08-04.md).
> Full Review (4 parallel reviewer subagents) of all 29 `lib/Service` files. Three
> minor findings were **auto-fixed** this review (gate green); the rest are open.

> **Resolved 2026-08-09 by [CS-PLAN-025](../plans/done/cs-plan-025-service-security-concurrency-cleanup.md)**
> (record: [`archive/2026-08.md`](archive/2026-08.md)):
> the 2 P0 + 6 P1 items **CR-SVC-01/02/03/04/05/06/07/12** and the dead-cluster
> delete **CR-SVC-08** landed. **CR-SVC-09/10** were **resolved 2026-08-16** by
> [CS-PLAN-029](../plans/done/cs-plan-029-service-hardening.md)
> (record: [`archive/2026-08.md`](archive/2026-08.md)). The remaining open items
> below (CR-SVC-11/13/…, P2/P3) stay here.
>
> **Reopened 2026-08-09 by the CS-025 implementation review:** **CR-SVC-04**
> plus follow-ups **CR-SVC-45/46/47** — all four **resolved 2026-08-10**
> (record: [`archive/2026-08.md`](archive/2026-08.md)).
> The review's smaller follow-ups **CR-SVC-48/49** were **resolved 2026-08-16**
> by [CS-PLAN-029](../plans/done/cs-plan-029-service-hardening.md)
> (record: [`archive/2026-08.md`](archive/2026-08.md)).
> **CR-SVC-50** was **resolved 2026-08-20** by
> [CS-PLAN-036](../plans/done/cs-plan-036-query-batch-optimization.md)
> (record: [`archive/2026-08.md`](archive/2026-08.md)); **CR-SVC-52** was
> **resolved 2026-08-24 by CS-PLAN-046** (record:
> [`archive/2026-08.md`](archive/2026-08.md)).

### [P3] CR-SVC-52 — CR-SVC-12 unit test does not assert dispatch-after-commit ordering
**Status**: Resolved 2026-08-24 (CS-PLAN-046) → [`archive/2026-08.md`](archive/2026-08.md)

> **2026-08-11 — Full Review of the 4 active plans** (CS-017/022/023/025; 4 parallel
> `reviewer` subagents + gate re-verification; record:
> [`CODE_REVIEW_2026-08-11.md`](CODE_REVIEW_2026-08-11.md)). Gate re-verified green on
> the uncommitted CS-025 reopened-items tree + 1 auto-fix (`TaskSyncService::setSparksEarned`
> dead line → `setCoinsEarned`): PHPUnit unit 690 / integration 150 (24 pre-existing
> skips) / Jest 677 / ConcurrentCompletionTest 2/2 (incl. the 2.1 s real-lock proof) /
> ESLint clean. **One auto-fix applied**, **one new [P1] concurrency finding
> (CR-SVC-53)** surfaced, and the minor perf/quality follow-ups below (CR-SVC-54..58).

> **Resolved 2026-08-20 by
> [CS-PLAN-036](../plans/done/cs-plan-036-query-batch-optimization.md)**
> (record: [`archive/2026-08.md`](archive/2026-08.md)): **CR-SVC-55/56** and the
> **read-path** cluster of **CR-SVC-11** are closed; the write-path remainder
> (resetHouse, deleteRoom, reorderFloors, bulkAssignTasks) is split into
> **CR-SVC-60** below.

### [P3] CR-SVC-59 — Add `AppLogger` to logger-less services
**Status**: Open · **Effort**: M · **Source**: CR-SVC-13 remainder (review of `lib/Service`, 2026-08-04; split off 2026-08-19)
~16 services have no logger at all. The raw-`LoggerInterface` half of CR-SVC-13 is
closed by CS-PLAN-035 (5 services migrated to `AppLogger`); this remainder tracks
incremental `AppLogger` adoption in services that swallow failures silently.
- [ ] Survey the logger-less services and inject `AppLogger` where failure paths
      (catch blocks, best-effort skips) currently go unlogged.

> **Resolved 2026-08-20 by
> [CS-PLAN-037](../plans/done/cs-plan-037-bulk-write-batching.md)**
> (record: [`archive/2026-08.md`](archive/2026-08.md)): **CR-SVC-60** closed —
> all four write hot spots converted to set-based writes with explicit,
> transactional, orphan-free delete cascades (D1=YES/D2=DELETE).

### [P3] CR-SVC-61 — `bulkAssignTasks` has no controller/route caller
**Status**: Resolved 2026-08-22 (CS-PLAN-042) → [`archive/2026-08.md`](archive/2026-08.md)

### [P2] CR-SVC-15 — Decompose God-methods / God-services
**Status**: Open · **Effort**: L · **Source**: Review of `lib/Service` (2026-08-04)
Over the ~300-line/~30-line guidelines: `RoomService::completeRoomTask` (~200 lines),
`GameLogicService` (517, 7 concerns), `handleTaskCompletion` (~113) /
`checkAndAwardMedals` (~62, 5 near-identical award blocks), `RoomTaskService` (833),
`RewardConfigService` (607), `TaskService::createTask` (24 positional params).
- [ ] Extract cohesive steps (overdue-takeover, post-commit notification dispatch,
      medal-rules table) and split GameLogicService along domain lines.

### [P2] CR-SVC-16 — `RewardConfigService` registry refactor (DRY)
**Status**: Open · **Effort**: M · **Source**: Review of `lib/Service` (2026-08-04)
Every config key is touched in 4-5 places (const + get + set + `updateConfig` branch +
`getAllConfig` entry); `updateConfig` mirrors `getAllConfig`.
- [ ] Drive get/set/update/all from a single `key => [type, default, clamp]` registry.

### [P3] CR-SVC-19 — `RewardConfigService` inconsistent clamping
**Status**: Resolved 2026-08-24 (CS-PLAN-045) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] CR-SVC-32 — `calculateDaysEarly` DST/timezone edge cases
**Status**: Resolved 2026-08-24 (CS-PLAN-045) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] CR-SVC-33 — `TaskService::completeTask` reward-less Task-level path
**Status**: Resolved 2026-08-24 (CS-PLAN-045, D1=delete) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] CR-SVC-37 — Service-layer i18n: hardcoded user-facing strings
**Status**: Resolved 2026-08-24 (CS-PLAN-046, incl. the RoomController
VALIDATION_ERROR literals) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] CR-SVC-62 — Remaining client-visible English literals (CR-SVC-37 remainder)
**Status**: Resolved 2026-08-25 (CS-PLAN-047, incl. the D1 NotFound/Forbidden
sweep and the D2 untranslated-fallback policy) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] CR-SVC-40 — `RoomService` reaches below its layer (`IDBConnection`)
**Effort**: M — injects `IDBConnection` for `beginTransaction`/`lockForUpdate` (persistence
plumbing in a service). Move transaction+lock into the mapper/repository.

### [P3] CR-SVC-41 — Service DI inconsistencies
**Effort**: M — nullable `?AppLogger` in 3 services (silent null-skip risk);
`getFilteredDailyTasksForUser` takes `HouseUserMapper` as a param despite ctor injection;
duplicated stat helpers; duplicated `validate*Ownership` across Floor/Room/RoomTask services.

### [P3] CR-SVC-44 — Minor performance cluster
**Effort**: M — `TaskService::getAllTasks` unbounded `findAll`; `createDefaultTemplates`
15 sequential inserts; `StreakFreeze`/`StreakRestock` per-user UPDATEs; `assignTask`/
`unassignTask`/`deactivate`/`reactivate`/`updateDueDate` triple-fetch pattern;
`extractHouseIdFromSchedule` 3 dependent queries.

---

## Review Follow-ups — CS-027 (House Setup Wizard & Cleaning Templates)

> From the Standard-B review of the CS-PLAN-027 implementation (2026-08-13).
> Six smaller findings were auto-fixed inline (plan §13); CR-027-03/04 were
> closed by CS-PLAN-030, **CR-027-01** was closed 2026-08-24 by CS-PLAN-045.
> **CR-027-02** was **resolved 2026-08-24 by CS-PLAN-046 (D1=delete)**
> (record: [`archive/2026-08.md`](archive/2026-08.md)). All CS-027 review
> items are closed.

### [P3] CR-027-01 — Free room create/update persists unvalidated `roomType`
**Status**: Resolved 2026-08-24 (CS-PLAN-045, D2=reject 400) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] CR-027-02 — `TemplateService::searchTemplates` dead + unlocalized since D7
**Status**: Resolved 2026-08-24 (CS-PLAN-046, D1=delete) → [`archive/2026-08.md`](archive/2026-08.md)

---

## Review Follow-ups — Usability & UI Review (2026-08-20)

> From the usability/UI workflow review (house setup → tasks → assignment →
> completion, adults + children). Full record:
> [`archive/USABILITY_REVIEW_2026-08-20.md`](archive/USABILITY_REVIEW_2026-08-20.md).
> **UX-001/002/003 resolved 2026-08-21 by CS-PLAN-038**; `CR-016-09` closed by
> archiving DailyTaskList. Related existing items: `CR-016-05`/`CR-016-03`
> (scheduling merge/split), `CR-SVC-33` (reward-less Task-level path,
> resolved 2026-08-24 by CS-PLAN-045).
> Implementation status: **CS-PLAN-041 landed 2026-08-22** (UX-005/006/013),
> **CS-PLAN-042 landed 2026-08-22** (UX-004/008/009/011 + CR-SVC-61 endpoint);
> **CS-PLAN-044 landed 2026-08-23** (UX-007/010/012 + TECH-LINT-004 +
> FEAT-UX-030/031); **CS-PLAN-043 landed 2026-08-24** (UX-014).

### [P3] FEAT-UX-015 — Leave house (self-service membership exit)
**Status**: Open · **Effort**: M · **Source**: UX-003 remainder (CS-PLAN-038)

Members cannot leave a house on their own; removal is owner-only. Needs a
product decision on owner-transfer semantics when the *last* owner leaves
(interplay with the BUG-SEC-002 last-owner guard), then a backend endpoint +
Settings action.

- [ ] Decide owner-transfer/last-owner semantics for leaving.
- [ ] Backend leave endpoint (+ guards) + Settings Leave button + tests.

### [P1] UX-001 — Task creation is three inconsistent paths; TaskManager creates invisible tasks
**Status**: Resolved 2026-08-21 (CS-PLAN-038) → [`archive/2026-08.md`](archive/2026-08.md)

### [P1] UX-002 — No "Today / my tasks" surface (child persona gap)
**Status**: Resolved 2026-08-21 (CS-PLAN-038) → [`archive/2026-08.md`](archive/2026-08.md)

### [P1] UX-003 — Member management has zero UI (backend + stores complete)
**Status**: Resolved 2026-08-21 (CS-PLAN-038) → [`archive/2026-08.md`](archive/2026-08.md); leave-house remainder → FEAT-UX-015

### [P2] UX-004 — Structure gaps: room rename/delete + floor reorder UI-less; floor mgmt duplicated
**Status**: Resolved 2026-08-22 (CS-PLAN-042) → [`archive/2026-08.md`](archive/2026-08.md)

### [P2] UX-005 — Dead/misleading controls
**Status**: Resolved 2026-08-22 (CS-PLAN-041) → [`archive/2026-08.md`](archive/2026-08.md)

### [P2] UX-006 — No undo for completion; native confirms; silent Settings saves
**Status**: Resolved 2026-08-22 (CS-PLAN-041) → [`archive/2026-08.md`](archive/2026-08.md)

### [P2] UX-007 — Kid-sized controls & inconsistent vocabulary
**Status**: Resolved 2026-08-23 (CS-PLAN-044) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] UX-008 — Assignment discoverability
**Status**: Resolved 2026-08-22 (CS-PLAN-042) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] UX-009 — Checklist editor implemented but permanently locked
**Status**: Resolved 2026-08-22 (CS-PLAN-042) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] UX-010 — Hardcoded colors bypass tokens; stale mobile.css selectors
**Status**: Resolved 2026-08-23 (CS-PLAN-044) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] UX-011 — Setup wizard polish
**Status**: Resolved 2026-08-22 (CS-PLAN-042) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] UX-012 — Modal accessibility
**Status**: Resolved 2026-08-23 (CS-PLAN-044) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] UX-013 — Boost prices duplicated client-side
**Status**: Resolved 2026-08-22 (CS-PLAN-041) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] UX-015 — Undo snackbar holds only the latest completion
**Status**: Open · **Effort**: S · **Source**: Review of CS-PLAN-041 (2026-08-22)

The global `UndoSnackbar` (CS-PLAN-041) keeps a single undo slot: completing a
second task within the ~5 s window silently replaces the first task as the undo
target, so a misclick on the first task can no longer be reverted. Decide
between queueing undo targets (stacked/snackbar list) or explicitly accepting
the single-slot limitation in the design docs.

### [P3] UX-016 — Remaining Modal call sites have no accessible name
**Status**: Open · **Effort**: S · **Source**: Standard-B review of CS-PLAN-044 (2026-08-23)

CS-PLAN-044 gave the shared `Modal.vue` an `ariaLabel` prop, but only
`OverdueClaimDialog` passes one. The other ~15 call sites (TaskManager
create/edit, Settings floor add/edit/reset, RoomDetail task add/edit + rename/
delete, TaskChecklist item edit, TitlePicker, ArcadeSection game modals,
AvailabilitySettings edit, ConfirmDialog) render `role="dialog"` without an
accessible name (WCAG 4.1.2 Name, Role, Value).

- [ ] Every Modal call site passes `ariaLabel` (or Modal grows an
      `aria-labelledby` heading fallback) — screen-reader announcement verified.
- [ ] design.md §6 *Dialogs* names the accessible-name requirement explicitly.

### [P4] UX-017 — AvailabilitySettings day-item border hexes bypass tokens
**Status**: Open · **Effort**: XS · **Source**: CS-PLAN-044 known remaining, logged by Standard-B review (2026-08-23)

`AvailabilitySettings.vue` day-item borders hardcode `#4caf50`/`#f44336`
(~lines 449/454). Outside the CS-PLAN-044 swept-component list; replace with
`var(--color-success)`/`var(--color-error)` when a dark-mode pass touches the
component.

---

## Review Follow-ups — UI Use-Case Review (2026-08-21)

> From the Standard-B review of
> [`../concepts/ui-use-cases.md`](../concepts/ui-use-cases.md) and the endpoint
> audit (both 2026-08-21). These are **whitespace candidates**: documented
> intent without UI surface (Group A) and review ideas (Group B), indexed from
> the *Whitespace* section of `ui-use-cases.md`. No expose-or-prune decision is
> pre-empted by their existence; P4 items are explicitly "future / needs
> decision". **FEAT-UX-016/017/020/022 were resolved by CS-PLAN-040
> (2026-08-21) → [`archive/2026-08.md`](archive/2026-08.md).**

### [P3] FEAT-UX-018 — Task-approval workflow for children
**Status**: Open · **Effort**: L · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

design.md §5 lists "task approval workflow for children"; gamification open
decisions touch it too. Today every completion is final immediately.

- [ ] Product decision recorded: approval flow vs. current auto-complete
      (which roles, which tasks).
- [ ] Backend: needs-review state + owner approve/reject endpoints with
      membership/owner gating + tests.
- [ ] Owner approval queue UI + child-facing "waiting for approval" feedback.

### [P4] FEAT-UX-019 — Task Pass & support actions (gag mechanic)
**Status**: Open · **Effort**: M · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

gamification-and-rewards.md sketches a post-MVP "Task Pass" gag mechanic;
explicitly future / needs decision.

- [ ] Product decision whether the gag mechanic fits the target audience.
- [ ] Only after decision: concept extension + endpoint design sketch.

### [P3] FEAT-UX-021 — NC-Tasks sync surface
**Status**: Open · **Effort**: M · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

Six `task-sync` routes are backend-only (task-model-and-sync.md). Blocked on
the cluster's expose-or-prune decision.

- [ ] Record the expose-or-prune decision for the 6 `task-sync` routes first.
- [ ] If exposed: link/unlink + pending/conflict list UI (RoomDetail or
      TaskManager) + tests; if pruned: close this item referencing the ADR.

### [P3] FEAT-UX-023 — Onboarding & help layer
**Status**: Open · **Effort**: M · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

No tour, glossary, or contextual hints; vocabulary drift (UX-007) makes
self-service discovery harder.

- [ ] Help entry point per major view (Dashboard, TodayView, SchedulingView).
- [ ] Glossary unifying the UX-007 vocabulary drift (priority labels,
      Admin-Only, Scope).
- [ ] Contextual hints for gamification terms (combo, medal, streak freeze).

### [P3] FEAT-UX-024 — Notification settings
**Status**: Open · **Effort**: M · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

UC-14 reminders are passive server jobs with no settings surface.

- [ ] Backend: per-user notification preferences (storage + membership-gated
      endpoints).
- [ ] Settings surface: digest toggle, quiet hours; background jobs respect
      them.
- [ ] Service + settings tests.

### [P4] FEAT-UX-025 — Vacation / house-pause mode
**Status**: Open · **Effort**: M · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

Future / needs decision: pause semantics undefined (streaks? due dates?
notifications? per-member vs whole-house).

- [ ] Product decision on pause semantics + scope recorded.
- [ ] Concept extension before any implementation.

### [P4] FEAT-UX-026 — Real-world reward shop
**Status**: Open · **Effort**: L · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

Future / needs decision: coins → owner-defined real-world rewards beyond the
coin/boost model.

- [ ] Product decision + gamification-doc concept extension (reward model,
      redemption flow).
- [ ] Implementation only after the concept lands.

### [P4] FEAT-UX-027 — Print view (weekly plan / calendar)
**Status**: Open · **Effort**: S · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

Future: paper-friendly weekly plan for fridge-door households.

- [ ] Print styles or dedicated print route reusing CalendarView range data.
- [ ] No new endpoints unless a gap is proven.

### [P3] FEAT-UX-028 — Global search
**Status**: Open · **Effort**: M · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

`GET /tasks/search` exists with a dead store wrapper (`searchTasks`); no UI.

- [ ] Search entry point covering task definitions + room tasks (NC search
      integration or in-app field).
- [ ] Decide fate of the dead `searchTasks` wrapper with the implementation.
- [ ] Tests.

### [P4] FEAT-UX-029 — Bulk maintenance ops
**Status**: Open · **Effort**: M · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

Future: owner tools to reschedule/clear overdue tasks in bulk and seasonally
deactivate/reactivate by filter (cf. CR-SVC-61 bulk-endpoint pattern).

- [ ] Server-side bulk reschedule endpoint + owner action on the overdue list.
- [ ] Seasonal deactivate/reactivate by filter (room/category/idle).
- [ ] Styled confirmations per cross-cutting conventions + tests.

### [P3] FEAT-UX-030 — A11y & kids-mode conventions
**Status**: Resolved 2026-08-23 (CS-PLAN-044) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] FEAT-UX-031 — Error/empty/offline-state conventions
**Status**: Resolved 2026-08-23 (CS-PLAN-044) → [`archive/2026-08.md`](archive/2026-08.md)

---

## Release / Rollout

> Stage path: `v0.8.x` alpha → `v0.9.x` beta → `v1.0.0` stable (manual install).
> App Store submission is explicitly deferred until stable quality is proven.

### [P2] RELEASE-002 — Beta deployment + real-world alpha usage
**Status**: Pending — deploy to a personal Nextcloud instance; verify no impact
on other apps; real-world `v0.8.x` alpha usage; (optional) private tester
onboarding for `v0.9.x` beta with structured feedback intake.

### [P2] RELEASE-003 — v1.0.0 stable artifacts
**Status**: Pending — release notes, app screenshots, `v1.0.0` stable
manual-install release, App Store readiness evaluation (post-stable only).

### Milestone exit criteria (not yet satisfied)
- **`v0.9.x` Beta**: private tester onboarding; reproducible core flows across
  setups; documented install/update/rollback for testers; known-issues list;
  feedback intake operational; no open P0 / no unaccepted P1.
- **`v1.0.0` Stable**: documented supported workflows; release notes + known
  issues + install/upgrade guides; lint clean for supported paths; targeted
  automated + manual validation; release decision independent of App Store.

> Done release-prep work (security audit, CI release gate, integration/E2E
> stabilization, deployment checklist) is recorded in [`archive/`](archive/).
