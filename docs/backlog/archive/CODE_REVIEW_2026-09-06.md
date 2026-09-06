# Code Review: CS-PLAN-063 WP4 — Atomic Task Creation and Canonical Assignment (Final Standard B)

**Review Date**: 2026-09-06
**Reviewer**: Kilo (main agent; single-track Standard B per user request)
**Tier**: Standard B (final implementation review of the owning plan)
**Owning Plan**: `docs/plans/done/cs-plan-063-wp4-atomic-task-creation-assignment.md`
**Base Commit**: `7cb798fdd4d2fed5028d57e9f95a5b0bbdcdf58e` on `master`
**Reviewed Scope Fingerprint**: unstaged binary diff `7291137a1d1867d50c86fb5d31d372f86a61b797`,
staged diff empty (index clean). Untracked files (SHA-1 content hashes):

```text
57b95366bb95749e9068b06b270fb114b819029c  lib/Exception/ValidationException.php
ac131f39648288d43942729ef54df1f1ad0c9f7b  lib/Service/TaskCreationService.php
19d82aacb4ae729bb7f568e3a3c8f430d0d9034c  tests/integration/Service/TaskCreationAggregateTest.php
9d4120ebed0434e6acf57e1dbb19a0b7699b6210  tests/integration/Service/TaskCreationConcurrencyTest.php
0428aa80d2f9b7f930a49dfc2baff9b1e8727139  tests/unit/Service/TaskCreationServiceTest.php
```

## Outcome

**PASS-WITH-FIXES** → plan moved `active/ → done/`; CR-WF-03 closed and
archived (both halves delivered); CS-PLAN-058 §9 WP4 row → Done.

## Scope Manifest

The plan's full §5 file manifest: 54 modified files (+1826/−1520) and the 5
untracked files above — backend (`TaskCreationService`, controllers, services,
mappers, `TransactionExecutor`, routes), frontend (stores, views, form,
preset selector), tests (unit/integration/Jest/E2E), docs (API README,
concept, locking note, backlog, masterplan), and `l10n/`.

## Findings

| # | Severity | Location | Issue | Status |
|---|----------|----------|-------|--------|
| F1 | major | `lib/Service/TaskService.php` (validateTaskData) | Present `name: null` on the update path passed validation (`?? null` conflates absent/null) → `setName(null)` → NOT NULL column → 500; pre-WP4 contract was a curated 400 | **Fixed** (user-authorized): key-presence check + regression tests |
| F2 | minor | `TaskCreationService.php:247`, `TaskService.php:194` | `Unknown or immutable field: ` concatenated raw — user-visible 400 message bypassing `t()` (i18n rule; sole documented exception is `safeMessage`) | **Fixed**: `t('Unknown or immutable field: %1$s', [$field])` both sites + en/de l10n keys + substituting test l10n mock |
| F3 | minor | `lib/Service/TaskCreationService.php` | `AppLogger` injected but unused; cross-house creation denial lost its `securityWarning` (deleted `createRoomTask` logged it; `RoomService` throws silently) | **Fixed**: catch-log-rethrow around `validateRoomOwnership` (log-only, stable keys) |
| F4 | minor | `tests/unit/Controller/TaskControllerTest.php` | 3 orphaned `validateTaskData` mock setups (controller no longer calls it) | **Fixed**: removed |
| F5 | nit | `TaskCreationService` + test comments | Ambiguous `(D5)` actor-policy refs (CS-PLAN-061 D5 vs CS-PLAN-063 D5/D6) | **Fixed**: disambiguated to `(CS-PLAN-061 D5)` |
| F6 | nit | `src/store/roomTasks.js` (createFromTemplate) | Template path did not cache the definition in the tasks store (custom path does; plan §3.5) | **Fixed** + new Jest spec |
| F7 | nit | `TaskCreationService::validatePayloadEnvelope` | `assigneeUserIds` silently drops non-string entries (definition fields are strict-400) | **Recorded, no change**: benign (filter precedes insert; membership revalidated under the lock; nothing invalid persists) |

## Decisions (batched structured question, 2026-09-06)

- **F1 null-name update semantics** → user chose *"Fix inline + test
  (Recommended)"*: restore the pre-WP4 curated 400 via key-presence
  validation, add regression tests, rerun gates. Recorded in the plan §10.

## Verification & Gates

Review verified against the shared checklist: architecture (thin controllers
→ `TaskCreationService` aggregate → mappers; no SQL concatenation),
authorization (`@NoAdminRequired` only; house membership + actor gates +
404 anti-enumeration re-checked at source level; under-lock revalidation),
security (client input validated at boundaries; no secrets/PII logged),
i18n (`t()` coverage + l10n artifacts), logging (`AppLogger`/`logger.js`),
tests (risk-adequate: unit gates/rollback, real-DB zero-write, `proc_open`
races, Jest re-pins, E2E journey), docs (durable docs integrated; plans are
temporary), lock discipline (house → room_task order; ascending-id bulk).

Gates (Docker env reset to `TEST_DB_PROFILE=family`; disposable `down -v`):

- PHPUnit unit: **845 OK** (843 pre-fix + 2 review regression tests)
- PHPUnit integration: **278 OK** + 10 pre-existing documented skips;
  `TaskCreationConcurrencyTest` 2/2 executed on MySQL (not skipped)
- `composer cs:check`: **0 findings**
- Jest: **815 OK** (814 pre-fix + 1 review spec), 82 suites
- ESLint (src + tests/e2e): clean; `l10n:check-keys`, `docs:encoding`,
  `backlog:check`: green; webpack production build: clean
- Playwright: chromium **92 passed / 7 pre-existing skips**;
  chromium-serial-db **92 / 5** (rerun after the fix cluster);
  chromium-scheduling-owner **31 / 0** (pre-fix run; only store/i18n/logging
  surfaces changed afterwards, which that pool does not exercise)

Environment note (reviewer-caused, no product defect): the first E2E attempt
failed in `auth.setup` because the reviewer's `docker compose up` omitted
`TEST_DB_PROFILE=family` (entrypoint created only the `minimal` users).
Additionally, changing compose env on the test service requires a full
`down -v` reset — Nextcloud's config lives in the container layer, so a
recreate alone breaks the install state against the persistent DB volume.

## Review Evidence & Dispositions

- Owning plan updated (§10 Standard B outcome, §8 criteria ticked) and moved
  to `done/`.
- CR-WF-03 archived (`archive/2026-09.md`) — both halves delivered;
  CR-SVC-41 WP4 slice annotated (item stays open for WP8);
  CR-062-01 archived by the implementation.
- CS-PLAN-058 §9 WP4 row updated to Done; `ACTIVE_TASKS.md` updated.
- No unresolved findings, no open questions, no new skips.
