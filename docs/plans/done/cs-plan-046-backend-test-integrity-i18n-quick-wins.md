# CS-PLAN-046: Backend Test Integrity & i18n Quick-Wins

> **Status:** Done (2026-08-25) — implemented + Standard-B reviewed (PASS); moved to `done/`
> **Effort:** M (four S-effort items bundled)
> **Priority:** P2
> **Depends on:** none (the Docker real-DB integration harness is green since CS-PLAN-036/037)
> **Related backlog items:** CR-016-10 (P2), CR-SVC-52 (P3), CR-SVC-37 (P3), CR-027-02 (P3)
> **Decisions requested at activation:** D1 = fate of dead `TemplateService::searchTemplates`

## 1. Problem Summary

After the backend-hardening sweep (CS-PLAN-045), four small, well-defined items remain open that
share a theme: shipped fixes without durable regression coverage, and rule violations
(i18n) / dead code that pre-release quality should not carry:

1. **CR-016-10** — `CompletedTaskMapper::getConsumedEnergyForUserOnDate`
   (lib/Db/CompletedTaskMapper.php:186) originally shipped with a mangled
   `select('COALESCE(SUM(...)) AS …')` that failed at runtime with SQLSTATE 1054; it was fixed
   to `selectAlias(createFunction(...), ...)` (source:
   [`archive/CODE_REVIEW_2026-07-28.md`](../../backlog/archive/CODE_REVIEW_2026-07-28.md)).
   The only current coverage is mock-based (`SchedulingServiceTest.php:48/84` stubs the mapper),
   which by construction cannot catch SQL errors — the exact defect class of the original bug.
   The backlog item's precondition "unblock the integration harness first" is satisfied: the
   Docker real-DB suite runs green (196 integration tests as of CS-PLAN-045).
2. **CR-SVC-52** —
   `RoomServiceTest::testCompleteRoomTask_NewTitleInGameResult_DispatchesTitleNotificationAfterCommit`
   (tests/unit/Service/RoomServiceTest.php:872) asserts `notifyTitleUnlocked` is dispatched,
   but with the mocked `IDBConnection` a dispatch **before** commit would pass too — the
   ordering guarantee (the point of CR-SVC-12) is untested.
3. **CR-SVC-37** — user-facing strings are English literals returned to clients:
   - `TaskService::validateTaskData` (lib/Service/TaskService.php:285 ff., 10 literals)
     surfaces in the `TaskController` 400 envelope `details[]`
     (TaskController.php:265–273);
   - `MinigameService::purchaseBoost` throws an interpolated literal
     ("Insufficient coins. Need {$cost}, have {…}", MinigameService.php:256–258) that reaches
     the client verbatim via `ControllerErrorFormatter` (`$e->getMessage()`,
     ControllerErrorFormatter.php:41/64);
   - `RoomController` carries 7 unwrapped `VALIDATION_ERROR` literals
     ('Floor ID is required', 'Room name is required', 'Room type must be at most
     50 characters', 'Invalid room type', 'Cleanliness change must be between -100 and 100';
     RoomController.php:92–93, 206–207, 216–217, 229–230, 239–240, 355–356, 427–428). The
     CS-PLAN-045 review noted these follow the method's pre-existing style — this sweep wraps
     them in one pass.
   This violates the canonical i18n rule (every user-visible string via `t()`).
4. **CR-027-02** — `TemplateService::searchTemplates` (TemplateService.php:246) has no caller
   since the legacy `template#search` route was removed together with `TemplateController`.
   It also matches raw English source text rather than the localized view, so German queries
   miss. Its only remaining references are its own unit tests
   (tests/unit/Service/TemplateServiceTest.php:157–173).

## 2. Strategy

One sweep, four independent small items, ordered test-integrity → i18n → dead code:

- Real-DB mapper regression tests follow the established
  `tests/integration/Db/*RegressionTest.php` patterns on `IntegrationTestCase` (Docker).
- The ordering assertion records a shared call-sequence log appended by both the commit
  callback and the notification-service mock.
- i18n follows the existing in-repo precedent (`TemplateService`, `UserController`): inject
  `OCP\IL10N`, wrap literals with `$this->l10n->t()` using **printf-style positional
  parameters** (`%1$s`, `%2$d`, …) instead of string interpolation, and sync the `l10n/`
  artifacts in the same change. Nextcloud's `L10N::t()` feeds the parameter array to
  `vsprintf()` (see `OC\L10N\L10NString::__toString`), so named `{placeholder}` tokens are
  **not** substituted — the interpolated message must become positional, matching the
  `ChoreStrikeNotifier` precedent.
- Dead-code removal follows the pre-release no-shelf rule (delete instead of shelving);
  gated on decision D1 below.

### Decision requested at activation

| ID | Decision | Plan proposal |
|----|----------|---------------|
| D1 | CR-027-02 fate | **Delete** `searchTemplates` + its 4 unit-test cases. No caller exists; capability stays in git history and can be re-added when a template-picker search field is actually built (wiring it would mean new UI + localized matching = separate feature plan). |

## 3. Backend / Frontend Changes

### Backend (`lib/`) — production code changes only for items 3 and 4

1. **CR-016-10 — real-DB regression test (no production code change)**
   - New `tests/integration/Db/CompletedTaskMapperEnergyTest.php` extending
     `IntegrationTestCase`, seeding the full fixture chain (house → house user → floor → room
     → task → room task → completed tasks) via the existing helper methods /
     `TestDataService` idioms used by `WriteBatchRegressionTest`/`BatchQueryRegressionTest`.
2. **CR-SVC-52 — ordering assertion (test-only change)**
   - In the existing test, introduce `$callOrder = []`; make the mocked
     `IDBConnection::commit` callback append `'commit'` and the `NotificationService`
     `notifyTitleUnlocked` mock append `'notify'`; assert `array_search('notify') >
     array_search('commit')` (both present). No production change.
3. **CR-SVC-37 — service/controller i18n**
   - `TaskService`: inject `IL10N`; wrap all `validateTaskData` error literals with
     `$this->l10n->t()`.
   - `MinigameService`: inject `IL10N`; convert the interpolated insufficient-coins message to
     positional style: `t('Insufficient coins. Need %1$d, have %2$d', [$cost, $lockedUser->getCoins()])`
     (`t()` parameters run through `vsprintf()` — named `{placeholder}` tokens would reach the
     client unsubstituted); also wrap `'User not found in house'` / `'Unknown boost: …'` where
     they reach clients (the boost id keeps its `%1$s` echo — audited client-safe per the
     `ControllerErrorFormatter::validation()` contract).
   - `RoomController`: inject `IL10N` (none today); wrap the `VALIDATION_ERROR` literals.
   - `ControllerErrorFormatter` passes messages through untouched — no change needed there;
     Nextcloud's server-side `t()` performs the `vsprintf` substitution, so response shapes
     stay identical.
4. **CR-027-02 — dead-method removal (gated on D1)**
   - Delete `TemplateService::searchTemplates` incl. its PHPDoc; re-grep for callers
     immediately before the deletion commit.

### Frontend (`src/`)

- None. Error envelopes keep their shape (`code`/`message`/`details`); German users get
  translated messages once `l10n/de.json` ships the keys.

### l10n artifacts (same change)

- Add the new source strings to `l10n/en.json`, translate them in `l10n/de.json`, extend
  `l10n/templates/chorestrike.pot`; gate via `npm run l10n:check-keys`.

## 4. Tests

- **PHPUnit unit**
  - `RoomServiceTest`: ordering assertion fails if dispatch precedes commit (verify by
    temporarily swapping the order → red, then restore).
  - `TemplateServiceTest`: remove the 4 `searchTemplates` cases (lines ~150–175); suite stays
    green for the remaining coverage.
  - `TaskServiceTest` / `TaskServiceWhitelistTest` / `MinigameServiceTest` /
    `RoomControllerTest` / `RoomControllerIntegrationTest`: constructor updates for the
    injected `IL10N` (identity mock per the `TemplateServiceTest.php:21–24` precedent —
    note the identity mock returns the source text verbatim, so message assertions match the
    wrapped source string, not the parameter-substituted output); validation-message
    assertions updated to the wrapped strings.
- **PHPUnit integration (Docker, family profile)**
  - New `CompletedTaskMapperEnergyTest` cases:
    1. returns `0` for a user with no completions on the date;
    2. sums `energy_cost` across multiple completions (different room tasks) for one user on
       one date;
    3. day boundary `[00:00, +1d)`: completion just before midnight counts, completion at/after
       next-day midnight does not;
    4. other users' completions on the same date are ignored (cross-user isolation).
- **E2E (Playwright)** — not affected; no UI/DOM change. Run the standard release smoke set
  only as part of the final gate if the full gate is executed anyway.

## 5. File Manifest

```
new:      tests/integration/Db/CompletedTaskMapperEnergyTest.php
modified: lib/Service/TaskService.php                 (IL10N ctor param; t()-wrap validateTaskData messages)
modified: lib/Service/MinigameService.php             (IL10N ctor param; t()-wrap purchaseBoost messages)
modified: lib/Controller/RoomController.php           (IL10N ctor param; t()-wrap VALIDATION_ERROR literals)
modified: lib/Service/TemplateService.php             (remove searchTemplates [D1])
modified: tests/unit/Service/RoomServiceTest.php      (dispatch-after-commit ordering assertion)
modified: tests/unit/Service/TemplateServiceTest.php  (drop searchTemplates cases)
modified: tests/unit/Service/TaskServiceTest.php      (ctor update; message assertions)
modified: tests/unit/Service/TaskServiceWhitelistTest.php (ctor update)
modified: tests/unit/Service/MinigameServiceTest.php  (ctor update; message assertions)
modified: tests/unit/Controller/RoomControllerTest.php (ctor update; message assertions)
modified: tests/integration/Controller/RoomControllerIntegrationTest.php (ctor update)
modified: l10n/en.json                                (new source strings)
modified: l10n/de.json                                (translations)
modified: l10n/templates/chorestrike.pot              (template refresh)
```

## 6. Implementation Order

1. **CR-016-10**: write `CompletedTaskMapperEnergyTest`, run against the Docker family
   profile; confirm all four cases pass (a red run would be a live SQL bug — stop and fix).
2. **CR-SVC-52**: add the ordering log + assertion; mutation-check it by temporarily moving
   the dispatch before the transaction commit (expect red), then restore.
3. **CR-027-02** (after D1 sign-off): delete `searchTemplates` + its 4 test cases; final
   caller re-grep in the same commit.
4. **CR-SVC-37**: inject `IL10N` into the three classes, wrap literals (printf-style
   positional parameters for the interpolated message), update the affected unit tests, sync
   `l10n/` artifacts.
5. Full build gate + backlog/archive bookkeeping (see §8).

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Integration fixture complexity: completed-task rows need the full FK chain (house user, room task, task) | Medium | Reuse the seeding helpers/patterns proven in `WriteBatchRegressionTest` / `BatchQueryRegressionTest` |
| `IL10N` injection churns many manual service constructions in unit tests | Certain, mechanical | Identity mock per `TemplateServiceTest` precedent; constructors gain one additional required parameter — update all instantiation sites (manifest §5 lists all five test files) in the same commit |
| Wrapped messages alter client-visible text | Low | Server-side `t()` renders the `vsprintf` parameters before responding; envelope shape unchanged; unit tests assert the wrapped source text (identity mock does not substitute parameters) |
| Deleting `searchTemplates` removes a future-wanted capability | Low (gated on D1) | Git history preserves it; re-add with localized matching when a picker search UI is built |
| Day-boundary semantics differ across DB backends (SQLite/MySQL/PostgreSQL datetime comparison) | Low | Test uses explicit `PARAM_DATE` bindings identical to the mapper's own query; suite runs on the same Docker profile as CI |

## 8. Acceptance Criteria

- [x] `CompletedTaskMapperEnergyTest` covers zero-case, sum-case, day-boundary case, and
      cross-user isolation; green in the Docker family profile.
- [x] Ordering assertion demonstrably catches an inverted dispatch order (mutation check done
      during implementation).
- [x] No unwrapped English literal remains in `validateTaskData`, `purchaseBoost`, or the
      `RoomController` `VALIDATION_ERROR` sites; `npm run l10n:check-keys` clean.
- [x] `searchTemplates` removed with zero remaining references (grep-verified) [D1].
- [x] Build gate green: `npm test` + `npm run lint` + `npm run stylelint` + PHPUnit unit +
      Docker PHPUnit integration suite.
- [x] Backlog entries (CR-016-10, CR-SVC-52, CR-SVC-37, CR-027-02) closed and moved to
      `docs/backlog/archive/2026-08.md`; `docs/backlog/ACTIVE_TASKS.md` updated.
- [x] Knowledge integration: `docs/engineering/testing.md` mentions the mapper-level real-DB
      regression convention (extend only if not already covered); no other durable concepts
      introduced by this sweep.

## Review Outcome

**Standard-B review 2026-08-25 — PASS.** Scope: all uncommitted CS-PLAN-046
changes (4 production PHP files, 7 test files, 1 new integration test, l10n
en/de/pot, docs). Checklist clean: architecture (thin controllers, no layer
crossing), authorization (no auth path touched), security (boost-id echo in
`Unknown boost: %1$s` audited client-safe per the
`ControllerErrorFormatter::validation()` contract; JSON envelope, no XSS
surface), i18n (printf-positional parameters per Nextcloud `vsprintf()`
semantics; 18 new keys synchronized en/de/pot), dead-code grep (only
historical doc references remain), integration-test schema/cleanup audit
(insert covers all NOT NULL columns; tracked-ID `tearDown` cleanup runs
before the base cascade). 1 auto-fix: `RoomServiceTest` `$callOrder` array
explicitly initialized (was created only implicitly through the closure
reference; plan specified `$callOrder = []`). 1 backlog item logged:
**CR-SVC-62** (remaining client-visible English literals across the rest of
the controller/service surface — CR-SVC-37 remainder, flagged during the plan
review, now registered). Gate re-verified green after the auto-fix: lint +
stylelint 0/0, l10n:check-keys clean, Jest 831/831, Docker PHPUnit unit
834/834, integration 200 [24 pre-existing skips] incl.
`CompletedTaskMapperEnergyTest` 4/4.
