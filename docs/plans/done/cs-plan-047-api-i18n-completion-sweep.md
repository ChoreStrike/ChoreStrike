# CS-PLAN-047: API i18n Completion Sweep

> **Status:** Done (2026-08-25) — implemented + Standard-B reviewed (PASS); moved to `done/`
> **Effort:** M
> **Priority:** P2 (elevated from P3 item: ongoing violation of non-negotiable rule #6 — every user-visible string via `t()`)
> **Depends on:** none (CS-PLAN-046 done; its i18n pattern is reused verbatim)
> **Related issue:** CR-SVC-62 (closes it fully)

## 2a. Audit Deviations from §1 (recorded during step 2 implementation)

The scripted inventory re-run confirmed all counts in §1 and classified every
site. Four §1 item-2 sites turned out to be **not client-visible** and were
left unwrapped:

| Site | Catch path | Decision |
|------|-----------|----------|
| `TaskService:200` IAE ('Unknown or immutable field') | `TaskController::update` outer `\Exception` catch → `safeMessage()` hides | skip |
| `FloorService:130` IAE (reorderFloors cross-house) | only caller `FloorController::reorder` → `safeMessage()` hides | skip |
| `HouseService:137` IAE ('Invalid role…') | `UserController::updateRole` catches IAE → returns curated translated `'Invalid role…'` response, never `getMessage()` | skip |
| `RoomTaskService:870` IAE ('Cannot skip …') | only caller `TaskController::skip` → `safeMessage()` hides | skip |

Audit-promoted additions beyond the frozen §1 set: `TemplateService`
265/269/274 (`materializeTemplate`) are client-visible via
`RoomTaskController::fromTemplate` and `SetupController` (`validation()`
catches) → wrapped (already had `IL10N`). Confirmed internal-only (unwrapped):
`TaskSyncService:394` (`safeMessage()`), `TitleService:92` (curated in
`UserController::selectTitle`), `TitleCatalog:116` +
`RewardConfigService:680` (no catch in `RewardConfigController::update` →
generic NC error page). Integration ctor updates beyond the manifest:
`tests/integration/Controller/BulkAssignIntegrationTest.php`,
`tests/integration/Service/RoomTaskServiceExtendedFeaturesTest.php`.
l10n backfill: 91 backend keys that pre-dated this plan were never synced into
the artifacts (incl. notifier strings); en/de/pot now carry them. Pot fully
regenerated deterministically from en.json (byte-sorted on escaped form,
890 msgids; stale `Admin-Only` msgid dropped).

## 1. Problem Summary

CR-SVC-37 (CS-PLAN-046) swept three API areas onto `IL10N::t()`; the same i18n
rule violation persists across the **rest of the API surface** (logged as
CR-SVC-62 by the CS-PLAN-046 Standard-B review, 2026-08-25). Verified against
the tree on 2026-08-25:

1. **62 controller `'message' => '<English literal>'` sites** across 9
   controllers (`TestController` excluded — test-mode only):
   - `TaskController` — 22 (lines 103, 109, 177, 191, 207, 223, 234, 270, 354,
     360, 385, 396, 427, 449, 504, 510, 528, 534, 601, 755, 771, 904; incl.
     'Validation failed', 'Task not found' ×8, admin-only/private guards, date
     rejections)
   - `ChecklistController` — 8 (90, 110, 137, 151, 182, 211, 224, 347)
   - `RoomTaskController` — 16 (61, 67, 124, 279, 430, 478, 487, 660, 697,
     708, 720, 821, 867, 880, 891, 935; incl. 'User ID is required' ×4)
   - `FloorController` — 4 (172, 290, 326, 372)
   - `SetupController` — 3 (108, 119, 129)
   - `TaskSyncController` — 5 (93, 103, 119, 217, 247)
   - `HouseController` — 2 (88, 268)
   - `SchedulingController` — 1 (196)
   - `RoomController` — 1 (400, 'Room deleted successfully')
   (Backlog's "~45" estimate re-verified to 62; `TaskController` is 22, not
   ~20. Standard-A review 2026-08-25 corrected the draft count 52 → 62: the
   first pass missed 8 `RoomTaskController` sites — fromTemplate roomId/
   templateId guards (61/67), scope guard (124), bulk-assign array validations
   (708/720), stats range validations (867/880/935) — and 2 `TaskSyncController`
   completedAt validations (93/103).)
2. **Service `InvalidArgumentException` literals surfaced via
   `ControllerErrorFormatter::validation()`** (surfaces `getMessage()`
   verbatim, so these reach the client): `HouseSetupService` — 11 throw sites
   (192, 225, 229, 235, 241, 244, 252, 258, 264, 296, 301; backlog's "9"
   re-verified to 11), `SchedulingController` — 6 (109, 231, 264, 284, 288,
   294) + `SchedulingService` — 1 (45), `RoomTaskService` — 5 (216, 228, 358,
   433, 870; backlog's "4" missed line 870), `RoomTaskController` — 2 (226,
   582, 'Invalid nextDue date format'), `TaskService::updateTask` — 1 (200),
   `FloorService` — 1 (130), `HouseService` — 1 (137).
3. **Audit-discovered additions** (classify in step 2, wrap if client-visible):
    - `MinigameService:85` ('Score must be between 0 and …') — unwrapped while
      its siblings (249/256/266) are already wrapped (CS-046 precedent).
   - `TaskSyncService:394`, `TemplateService:265/269/274`, `TitleService:92`,
     `TitleCatalog:116`, `RewardConfigService:680` — client visibility depends
     on the catching controller (`safeMessage()` hides IAE messages unless a
     curated `validation()` catch exists).
4. **Confirmed remainders** (same rule class, found during verification;
   decisions signed off by the user 2026-08-25 — see §2):
   - **D1 = YES:** 24 `NotFoundException`/`ForbiddenException` literals
     surfaced **verbatim** by `ControllerErrorFormatter::safeMessage()` are in
     scope: `AuthorizationService` — 6 (73, 123, 162, 173, 217, 241),
     `RoomService` — 6 (89, 319, 347, 360, 365, 377), `TaskService` — 4 (192,
     195, 245, 248), `RoomTaskService` — 4 (81, 91, 200, 210), `HouseService`
     — 2 (96, 144), `FloorService` — 1 (55), `ActivityService` — 1 (78).
   - **D2 = documented exception:** the `safeMessage()` generic fallback `'An
     unexpected error occurred'` (60+ call sites use the default) stays
     untranslated; the policy is recorded in `i18n.md` + the formatter
     docblock.

Deliberately excluded (not client-facing API text): `TestController` +
`TestDataService` (test-mode only), `TriggerJobs` (CLI), `ChoreStrikeNotifier`
(internal notification plumbing, already l10n-aware via `l10nFactory`).

## 2. Strategy

Mechanical completion sweep following the CS-PLAN-046 pattern exactly: inject
required, non-nullable `IL10N` where missing; wrap every client-visible
literal with `t()` (printf-style **positional** parameters `%1$s`/`%1$d` for
interpolated values — verified against Nextcloud stable31 that `t()` runs
parameters through `vsprintf()`); keep source text identical so identity-mock
unit assertions stay valid; sync `l10n/en.json` + `l10n/de.json` + regenerate
`l10n/templates/chorestrike.pot` in the same change (rule #6). Step 2 runs a
scripted inventory audit first so the wrap list is complete and classified
(client-visible vs internal-only) before any edit. Finally integrate the
contract into `docs/standards/guidelines/i18n.md` so the rule stays closed.

**Decisions (signed off by the user 2026-08-25):**

- **D1 = YES — the 24 NotFound/Forbidden literals are in scope.** They are the
  same rule violation, the services mostly need `IL10N` anyway, and only this
  makes "zero client-visible English literals" actually true.
- **D2 = documented exception — the `safeMessage` fallback stays
  untranslated.** It guards genuinely unexpected errors whose detail is
  deliberately hidden and logged server-side; translating it would churn 60+
  call sites for one rarely-shown string. The policy is recorded in
  `docs/standards/guidelines/i18n.md` + the formatter docblock.

## 3. Backend / Frontend Changes

- **Backend** (`lib/`):
  - Inject `IL10N` (required, non-nullable ctor param) into 8 controllers
    lacking it: `TaskController`, `RoomTaskController`, `ChecklistController`,
    `FloorController`, `SetupController`, `TaskSyncController`,
    `HouseController`, `SchedulingController` (`RoomController`,
    `UserController` already have it).
  - Wrap the 62 controller literals (list above) with `t()`.
  - Inject `IL10N` into 8 services: `HouseSetupService`, `SchedulingService`,
    `RoomTaskService`, `FloorService`, `HouseService` (curated IAE literals)
    plus `RoomService`, `AuthorizationService`, `ActivityService` (D1
    NotFound/Forbidden literals); `TaskService` already has `IL10N`. Wrap all
    of the above literals. Audit may add: `TaskSyncService`, `TitleService`,
    `RewardConfigService` (only if a client-visible catch path exists).
  - Wrap `MinigameService:85` (already has `IL10N`).
  - `ControllerErrorFormatter`: docblock note recording the D2 fallback policy
    (no behavior change).
- **Frontend** (`src/`): **none.** Frontend displays `error.message` verbatim
  in toasts, so localized messages flow through without changes. Jest suite
  expected unchanged (831 tests) — gate-only.
- **Cross-layer contracts:** error envelope shape unchanged
  (`{success, error: {code, message}}`); only `message` text becomes
    locale-dependent. No store/component changes.

## 4. Tests

- **Unit (PHPUnit):** all affected controller/service test files get the
  established identity-mock (`$l10n->method('t')->willReturnCallback(fn
  ($text) => $text)`) added to `setUp()` and the new ctor argument; message
  assertions keep matching the wrapped **source text** (CS-046 semantics).
  Where a changed site has no assertion yet (e.g. `RoomTaskService:870`,
  `MinigameService:85`), add one.
- **Integration (PHPUnit, Docker family profile):** constructor updates in
  `TaskControllerIntegrationTest`, `RoomTaskControllerIntegrationTest`,
  `HouseControllerIntegrationTest`, `FloorControllerIntegrationTest`
  (`RoomControllerIntegrationTest` already wired). No new integration tests
  needed — behavior-neutral text wrapping; existing 400/403/404/409 envelope
  assertions cover the contract.
- **E2E (Playwright):** no spec changes expected; verify by grepping
  `tests/e2e/` for any changed message strings before the gate. If a spec
  asserts an API error string, update it to the (identical) source text or a
  `data-testid`. Spot-run affected suites (`members-owner`, `structure-owner`,
  `task-completion`) with `--retries=0` only if the grep finds hits.
- **l10n gate:** `npm run l10n:check-keys` clean after artifact sync.

## 5. File Manifest

New and modified files (nothing archived):

```
modified: lib/Controller/TaskController.php
modified: lib/Controller/RoomTaskController.php
modified: lib/Controller/ChecklistController.php
modified: lib/Controller/FloorController.php
modified: lib/Controller/SetupController.php
modified: lib/Controller/TaskSyncController.php
modified: lib/Controller/HouseController.php
modified: lib/Controller/SchedulingController.php
modified: lib/Controller/ControllerErrorFormatter.php          (D2 docblock policy note only)
modified: lib/Service/HouseSetupService.php
modified: lib/Service/SchedulingService.php
modified: lib/Service/RoomTaskService.php
modified: lib/Service/FloorService.php
modified: lib/Service/HouseService.php
modified: lib/Service/MinigameService.php                      (wrap line 85 only)
modified: lib/Service/RoomService.php
modified: lib/Service/AuthorizationService.php
modified: lib/Service/ActivityService.php
modified: lib/Service/TaskSyncService.php                      (audit-dependent)
modified: lib/Service/TitleService.php                         (audit-dependent)
modified: lib/Service/RewardConfigService.php                  (audit-dependent)
modified: lib/Titles/TitleCatalog.php                          (audit-dependent)
modified: lib/Service/TemplateService.php                      (audit-dependent)
modified: tests/unit/Controller/TaskControllerTest.php
modified: tests/unit/Controller/TaskControllerLoggingTest.php
modified: tests/unit/Controller/TaskControllerDailyTest.php
modified: tests/unit/Controller/RoomTaskControllerTest.php
modified: tests/unit/Controller/ChecklistControllerTest.php
modified: tests/unit/Controller/FloorControllerTest.php
modified: tests/unit/Controller/SetupControllerTest.php
modified: tests/unit/Controller/TaskSyncControllerTest.php
modified: tests/unit/Controller/HouseControllerTest.php
modified: tests/unit/Controller/SchedulingControllerTest.php
modified: tests/unit/Service/HouseSetupServiceTest.php
modified: tests/unit/Service/SchedulingServiceTest.php
modified: tests/unit/Service/RoomTaskServiceTest.php
modified: tests/unit/Service/RoomTaskServiceLoggingTest.php
modified: tests/unit/Service/RoomTaskServiceDailyTest.php
modified: tests/unit/Service/FloorServiceTest.php
modified: tests/unit/Service/HouseServiceTest.php
modified: tests/unit/Service/HouseServiceLoggingTest.php
modified: tests/unit/Service/MinigameServiceTest.php            (new assertion for saveScore line 85)
modified: tests/unit/Service/RoomServiceTest.php
modified: tests/unit/Service/AuthorizationServiceExceptionTest.php
modified: tests/unit/Service/ActivityServiceTest.php
modified: tests/integration/Controller/TaskControllerIntegrationTest.php
modified: tests/integration/Controller/RoomTaskControllerIntegrationTest.php
modified: tests/integration/Controller/HouseControllerIntegrationTest.php
modified: tests/integration/Controller/FloorControllerIntegrationTest.php
modified: l10n/en.json
modified: l10n/de.json
modified: l10n/templates/chorestrike.pot
modified: docs/standards/guidelines/i18n.md
modified: docs/backlog/backlog.md                              (CR-SVC-62 out)
modified: docs/backlog/archive/2026-08.md                      (CR-SVC-62 in)
modified: docs/backlog/ACTIVE_TASKS.md
```

(Audit-dependent service files are touched only if step 2 classifies one of
their literals as client-visible; their unit tests join the manifest then.)

## 6. Implementation Order

1. **Baseline:** record the green gate state (Docker PHPUnit unit+integration,
   Jest, lint, `l10n:check-keys`). D1/D2 are signed off (§2) — no further
   decisions needed.
2. **Inventory audit:** scripted grep over `lib/` for `'message' =>` literals
   and every `InvalidArgumentException`/`NotFoundException`/
   `ForbiddenException` throw site; classify each against its catching
   controller (`validation()` = client-visible, `safeMessage()` IAE = hidden,
   NotFound/Forbidden = verbatim). Freeze the wrap list; record deviations
   from §1 in this plan file.
3. **Controller sweep:** inject `IL10N` into the 8 controllers, wrap the 62
   literals, update their unit + integration tests. Gate: PHPUnit green.
4. **Service IAE sweep:** inject `IL10N` into `HouseSetupService`,
   `SchedulingService`, `RoomTaskService`, `FloorService`, `HouseService` (+
   audit-dependent), wrap curated + audited literals, wrap
   `MinigameService:85`, update tests. Gate: PHPUnit green.
5. **NotFound/Forbidden sweep (D1=YES):** wrap the 24 literals in
   `RoomService`, `AuthorizationService`, `ActivityService`, `TaskService`,
   `RoomTaskService`, `HouseService`, `FloorService`; update tests. Gate:
   PHPUnit green.
6. **D2 policy note:** record the untranslated-fallback exception in the
   `ControllerErrorFormatter` docblock (entry in `i18n.md` follows in step 9).
7. **l10n sync:** add all new keys to `l10n/en.json` (source text) and
   `l10n/de.json` (German translations), fully regenerate
   `l10n/templates/chorestrike.pot`; `npm run l10n:check-keys` clean.
8. **Full gate:** Docker PHPUnit unit + integration (family profile) /
   `npm test` (Jest unchanged count expected) / `npm run lint` /
   `composer cs:check` / `npm run l10n:check-keys` / `npm run test:noise`.
   E2E grep for changed message strings; spot-run only on hits.
9. **Docs & backlog:** integrate the contract into
   `docs/standards/guidelines/i18n.md` (where `t()` happens — controller vs
   service throw site; printf-positional rule; `validation()` client-safe
   audit requirement; D2 fallback policy; exclusions list); move CR-SVC-62
   from `backlog.md` to `archive/2026-08.md`; update `ACTIVE_TASKS.md`.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Changed message text breaks E2E/Jest assertions | Low (identity mocks keep source text; frontend shows text verbatim) | Step 8 greps `tests/e2e/` for every changed string before the gate; spot-run affected suites `--retries=0` only on hits |
| Ctor signature changes break many test files at once | Certain-but-mechanical | Identity-mock pattern is established (CS-046); per-controller steps keep failures small and reviewable |
| Audit misses a client-visible literal | Medium | Step 2 is scripted (grep over all three exception types + `'message' =>`), not manual recall; wrap list frozen in the plan before edits; acceptance criterion re-runs the grep |
| German translation quality/consistency | Low | Keep existing CS-046/CS-045 de.json phrasing conventions; user is native speaker and can correct at review |
| Scope creep beyond the frozen wrap list | Medium | Internal-only literals (CLI, test-mode, hidden by `safeMessage()`) are explicitly excluded in §1; anything newly discovered goes to the backlog, not into this plan |
| pot regeneration churn | Low | CS-046 already proved full pot regeneration works; `l10n:check-keys` gates artifact consistency |

## 8. Acceptance Criteria

- [x] D1 scope applied (all 24 NotFound/Forbidden literals wrapped) and D2
      policy recorded (formatter docblock + `i18n.md` exception entry).
- [x] Zero unwrapped client-visible English literals in `lib/` per the step 2
      inventory (re-run at the end); exclusions (TestController, CLI,
      notifier) documented in `i18n.md`.
- [x] All touched controllers/services inject required, non-nullable `IL10N`;
      interpolated values use printf-style positional parameters.
- [x] `l10n/en.json`, `l10n/de.json`, `l10n/templates/chorestrike.pot` synced
      in the same change; `npm run l10n:check-keys` clean.
- [x] Identity-mock assertions updated/added; every changed site with a
      client-visible message has test coverage asserting the source text.
- [x] Build gate green: Docker PHPUnit unit + integration (family profile) /
      `npm test` / `npm run lint` / `composer cs:check` / `test:noise`.
- [x] Durable concepts integrated into `docs/standards/guidelines/i18n.md` —
      plans are temporary.
- [x] CR-SVC-62 moved to `docs/backlog/archive/2026-08.md`.
- [x] `docs/backlog/ACTIVE_TASKS.md` updated.

## Review Outcome (Standard B, 2026-08-25)

**Scope:** the full uncommitted CS-PLAN-047 change set (56 files: 18 `lib/`,
25 tests, 3 l10n artifacts, 4 docs/backlog files) against this plan, its §2a
audit record, and the shared checklist. **Tier:** Standard B (code review, no
subagents — single mechanical i18n bundle, no auth/behavior/migration change).

**Checklist:** architecture clean (wrap-only edits, no layering change, ctor
`IL10N` always required non-nullable and before optional `$appLogger`);
authorization untouched (message text only); security unchanged (no new input
paths; client-controlled echoes in `%1$s` member/room-type messages are the
pre-existing curated-`validation()` contract, formatter docblock covers it);
KISS/minimal diff (no churn beyond the wrap list); no workarounds added;
logging untouched; i18n complete after the two auto-fixes below; tests
identity-mock updated everywhere a ctor changed, new `MinigameService::saveScore`
assertions landed; docs integrated (`i18n.md` backend-error contract, D2 in
the formatter docblock).

**§2a verification:** all 8 remaining unwrapped literals re-grepped and each
catch path re-traced — `TaskService:200` + `RoomTaskService:874` hidden by
`TaskController` catch-all → `safeMessage()` (IAE never surfaced, formatter
source-verified); `FloorService:130` hidden by `FloorController::reorder`
catch-all; `HouseService:141` + `TitleService:92`/`TitleCatalog:116` replaced
by curated translated responses in `UserController::updateRole`/`selectTitle`;
`TaskSyncService:394` behind `TaskSyncController` catch-alls;
`RewardConfigService:680` behind a catch-free controller (generic NC error
page). Exclusions (`TestController`, `ChoreStrikeNotifier` internals) intact.
Acceptance grep: zero unwrapped client-visible literals remain in `lib/`.

**Findings (2 minor, both auto-fixed inline):**

1. `Template "%1$s" is not available for level "%2$s"` (audit-promoted
   `TemplateService:274` wrap) was missing from `en.json`/`de.json`/pot —
   rule #6 requires artifact sync in the same change. Added with German
   translation; pot now 892 msgids.
2. `Your task "{task}" was taken over by {claimer}` — the
   `ChoreStrikeNotifier` rich-subject key was missed by the 91-key backfill
   (its parsed `%1$s` twin and the due-today/tomorrow rich subjects were
   synced). Added to en/de/pot mirroring the parsed twin's German.

**Nit (recorded, no edit):** §5 manifest omits `lib/Controller/RoomController.php`
(1 wrapped literal, 'Room deleted successfully'); the change is in scope via
§1 item 1 + §3 and needed no ctor/test update.

**Gate re-verified after fixes:** `npm run lint:fix` clean /
`npm run l10n:check-keys` clean (892=892 keys, no empties) / Jest 831 /
Docker PHPUnit unit 834 OK / integration 200 OK [24 pre-existing skips] /
`composer cs:check` 0 of 267 / `npm run test:noise` clean / E2E grep for
changed message strings: no hits. Scripted code↔artifact cross-check over all
`lib/` `->t()` keys: complete.
