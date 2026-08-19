# CS-PLAN-034: Kill the Jest `[ChoreStrike] [error]` noise — canonical `@nextcloud/axios` mock

> **Status:** Done (2026-08-19) — implementation + review complete, moved to `done/`
> **Effort:** M
> **Priority:** P2
> **Depends on:** none
> **Related:** backlog [TECH-LINT-005](../../backlog/backlog.md) (`docs/backlog/backlog.md:131-150`); closes umbrella [TECH-LINT-003](../../backlog/backlog.md) (`docs/backlog/backlog.md:93-100`) when landed; supersedes the two CS-026 silencing workarounds (MemoryGame, SchedulingView).

## 1. Problem Summary

Full Jest suite (70 suites / 701 tests) is green but emits **108 `[ChoreStrike] [error]` lines** (measured 2026-08-18 via per-spec runs). Root cause chain:

- `src/services/api.js:45` calls the **default export** of `@nextcloud/axios` (`axios({ method, url, …config })`), then unwraps the `{ success, data }` envelope and throws `ApiError` on failure (`src/services/api.js:52-57`).
- The canonical shared mock `tests/unit/__mocks__/nextcloud-axios.js:1-5` — wired for every spec via the `moduleNameMapper` in `jest.config.js:21` — exports a **non-callable object** as default whose named verbs resolve `{ data: {} }` (**no `success` envelope**). So any spec in which the real `api.js` runs gets an error on *every* call → store/view catch → `src/utils/logger.js:88` → `console.error`.
- Two component specs override the shared mock inline with an even smaller broken shape `{ get, post }` (`Game2048.spec.js:5-8`, `MemoryGame.spec.js:5-8`) — same failure class.

Measured per-spec attribution (per-file Jest runs; the backlog's "~95 lines / ~8 store specs" under-represents the real distribution):

| Spec | Lines | Mechanism |
|---|---|---|
| `tests/unit/views/Dashboard.spec.js` | 50 | `ArcadeSection.vue:197-203` not mocked → real minigame store → real `api.js` → broken shared mock (5 fetches × 10 tests) |
| `tests/unit/views/Settings.spec.js` | 34 | real `user.js:61-71` + `Settings.vue:317` (`loadTitle`) → same |
| 10 store specs (`minigame` 4, `house` 3, `roomTasks` 3, `titles` 2, `game`/`tasks`/`rooms`/`user`/`setup`/`scheduling` 1 each) | 18 | **deliberate** `mockRejectedValue` error-path tests → logger |
| `tests/unit/components/Game2048.spec.js` | 3 | inline `{ get, post }` mock → default not callable |
| `tests/unit/views/RoomDetail.spec.js` | 2 | real setup store `fetchCatalog` (`setup.js:38`) → broken shared mock |
| `tests/unit/utils/confetti.spec.js` | 1 | deliberate canvas-failure test → `confetti.js:63` |
| `MemoryGame.spec.js`, `SchedulingView.spec.js` | 0 (silenced) | CS-026 `console.error` spies hide the same noise |

## 2. Strategy

**Mock-side fix** (backlog primary option — accepted). Make the shared mock support how production actually calls axios, instead of changing production to match a broken mock:

1. **One canonical mock**: rewrite `tests/unit/__mocks__/nextcloud-axios.js` so the default export is a **callable `jest.fn()`** resolving the *success envelope* `{ data: { success: true, data: {} } }`, while keeping named verb mocks (`get/post/put/patch/delete`) with the same envelope plus `defaults`/`interceptors`. Specs override per test via `axios.mockResolvedValue(…)` (default) or `axios.get.mockResolvedValue(…)` (named).
2. **Remove the three inline axios-mock overrides** so every spec uses the shared mock (Game2048, MemoryGame, DailyTaskList). Game2048/MemoryGame switch `axios.get.mockResolvedValue(…)` → `axios.mockResolvedValue(…)` because the real `api.js` calls the default export. `DailyTaskList.spec.js` keeps `axios.get.mockResolvedValue(…)` because `DailyTaskList.vue:136` calls `axios.get` directly (bypassing `api.js`).
3. **Deliberate error-path tests stay, their console output goes**: mock `src/utils/logger.js` in the specs that test error paths (the established pattern in `tests/unit/store/checklist.spec.js:13-20`), so `mockRejectedValue` assertions are kept (optionally strengthened with `expect(logger.error).toHaveBeenCalled()`), without printing.
4. **Un-silence the two CS-026 specs** (remove their `console.error` spies) and close TECH-LINT-003 (umbrella) together with TECH-LINT-005.
5. **Verifiable gate**: new `npm run test:noise` fails when any `[ChoreStrike] [error]` line appears in Jest output.

**Alternative rejected — switch `api.js` to named verbs (`axios.get`/`axios.post`)** (backlog `docs/backlog/backlog.md:148-150`):

- It is a production change requiring its own review; this plan is scoped to test infrastructure (P2 tech-debt, minimal diff).
- It would **not** fix the noise anyway: the shared mock's named verbs resolve `{ data: {} }` without `success`, so `src/services/api.js:52-57` would still throw `ApiError` and the logger noise would persist. The missing-envelope problem is independent of the call style.
- The codebase already uses both call styles (`api.js` default-call vs `DailyTaskList.vue:136` direct `axios.get`), so the canonical mock must support both shapes regardless.

Note: `tests/unit/services/api.spec.js:8-11` already mocks `@nextcloud/axios` correctly (`__esModule: true, default: jest.fn()`) — it is the reference shape and needs no change.

## 3. Backend / Frontend Changes

No production code changes (`lib/`, `src/` untouched). All changes are test infrastructure:

**A. Canonical shared mock (core fix — eliminates 84/108 lines with zero spec edits):**

- `tests/unit/__mocks__/nextcloud-axios.js` — rewrite:

	```js
	const envelope = () => Promise.resolve({ data: { success: true, data: {} } })
	const axiosMock = jest.fn(envelope)
	axiosMock.get = jest.fn(envelope)
	axiosMock.post = jest.fn(envelope)
	axiosMock.put = jest.fn(envelope)
	axiosMock.patch = jest.fn(envelope)   // api.js exposes patch; old mock lacked it
	axiosMock.delete = jest.fn(envelope)
	axiosMock.defaults = { headers: { common: {}, get: {}, post: {}, put: {}, delete: {} } }
	axiosMock.interceptors = {
		request: { use: jest.fn(), eject: jest.fn() },
		response: { use: jest.fn(), eject: jest.fn() },
	}
	export default axiosMock
	```

- Beneficiaries without edits: `Dashboard.spec.js` (50), `Settings.spec.js` (34), `RoomDetail.spec.js` (2). Empty `data: {}` is safe for the stores involved (`minigame.js:122/175/189` read plain properties; `user.js:64` assigns the object; `setup.js` catalog consumers render empty).

**B. Inline axios-mock removal / alignment (3 specs):**

- `tests/unit/components/Game2048.spec.js` — delete `jest.mock('@nextcloud/axios', …)` (lines 5-8); line 23 becomes `axios.mockResolvedValue({ data: { success: true, data: { score: 0 } } })`.
- `tests/unit/components/MemoryGame.spec.js` — same mock removal + line 23 rename; **also remove the CS-026 silencing** (comment + `jest.spyOn(console, 'error')` at lines 24-29; drop the now-empty `afterEach` restore at lines 32-34 if it only restores that spy).
- `tests/unit/components/DailyTaskList.spec.js` — delete the inline object mock (lines 13-21); keep all `axios.get.mockResolvedValue(…)` lines (component calls `axios.get` directly at `DailyTaskList.vue:136`).

**C. Logger mock for deliberate error-path specs (11 specs, checklist-style `jest.mock('@/utils/logger', () => ({ logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() } }))`):**

- `tests/unit/store/minigame.spec.js` (4 noise lines; mock block at lines 5-10)
- `tests/unit/store/house.spec.js` (3; lines 5-11)
- `tests/unit/store/roomTasks.spec.js` (3; line 11)
- `tests/unit/store/titles.spec.js` (2; line 9)
- `tests/unit/store/game.spec.js` (1; line 5)
- `tests/unit/store/tasks.spec.js` (1; line 5)
- `tests/unit/store/rooms.spec.js` (1; line 5)
- `tests/unit/store/user.spec.js` (1; line 10)
- `tests/unit/store/setup.spec.js` (1; line 5)
- `tests/unit/store/scheduling.spec.js` (1; line 10)
- `tests/unit/utils/confetti.spec.js` (1; deliberate canvas-failure test → `confetti.js:63`)

All `mockRejectedValue` call sites and their assertions remain unchanged (error paths still tested deliberately). Where trivial, add `expect(logger.error).toHaveBeenCalled()` to the error tests (strengthening, optional).

**D. Un-silence CS-026 workaround (SchedulingView; overlaps B for MemoryGame):**

- `tests/unit/components/SchedulingView.spec.js` — remove the `console.error` spy (comment + `jest.spyOn` at lines 45-48), add the logger mock from C. The failing-init test (`api.get.mockRejectedValueOnce`, line 111) keeps asserting the error banner.

**E. Zero-noise gate:**

- New `scripts/check-test-noise.js`: spawn `npm test`, scan combined stdout/stderr for `[ChoreStrike] [error]`, print offending lines with count, exit non-zero if > 0.
- `package.json` scripts: add `"test:noise": "node scripts/check-test-noise.js"`.

## 4. Tests

This plan **is** a test-infrastructure change; there are no new product tests. Verification:

- **Full Jest suite green**: `npm test` — 70 suites / 701 tests pass, no assertions weakened or skipped.
- **Error paths still tested deliberately**: every existing `mockRejectedValue`/`mockRejectedValueOnce` site (23 across the suite, e.g. `house.spec.js:84,91,125`, `api.spec.js:80,105,123`) keeps its behavior; assertions on `store.error` / error banners unchanged. Logger mocking replaces *printing*, not *testing*.
- **Zero-noise gate**: `npm run test:noise` exits 0 with zero `[ChoreStrike] [error]` occurrences in Jest output (PowerShell equivalent used during verification: `npx jest 2>&1 | Select-String -SimpleMatch '[ChoreStrike] [error]'` → no matches).
- **Un-silenced specs re-checked**: MemoryGame + SchedulingView produce zero noise without their CS-026 spies.
- Lint: `npm run lint` green. Backend (PHPUnit/Docker) untouched — not in scope, no gate run needed beyond the frontend gate above (per AGENTS.md the build gate applies to changed code; if in doubt run the Docker backend suite once).

## 5. File Manifest

```
modified:  tests/unit/__mocks__/nextcloud-axios.js
modified:  tests/unit/components/Game2048.spec.js
modified:  tests/unit/components/MemoryGame.spec.js
modified:  tests/unit/components/DailyTaskList.spec.js
modified:  tests/unit/components/SchedulingView.spec.js
modified:  tests/unit/store/minigame.spec.js
modified:  tests/unit/store/house.spec.js
modified:  tests/unit/store/roomTasks.spec.js
modified:  tests/unit/store/titles.spec.js
modified:  tests/unit/store/game.spec.js
modified:  tests/unit/store/tasks.spec.js
modified:  tests/unit/store/rooms.spec.js
modified:  tests/unit/store/user.spec.js
modified:  tests/unit/store/setup.spec.js
modified:  tests/unit/store/scheduling.spec.js
modified:  tests/unit/utils/confetti.spec.js
new:       scripts/check-test-noise.js
modified:  package.json
modified:  docs/backlog/backlog.md            (close TECH-LINT-005 + TECH-LINT-003 → archive/2026-08.md)
modified:  docs/backlog/ACTIVE_TASKS.md
```

Untouched beneficiaries: `tests/unit/views/Dashboard.spec.js`, `tests/unit/views/Settings.spec.js`, `tests/unit/views/RoomDetail.spec.js` (fixed purely by the shared mock).

## 6. Implementation Order

1. Rewrite `tests/unit/__mocks__/nextcloud-axios.js` (callable default + envelope + named verbs + `defaults`/`interceptors`).
2. Run `npm test`; confirm Dashboard/Settings/RoomDetail noise is gone (−84 lines) and no assertions broke. Fix envelope shape if a store chokes on `data: {}`.
3. Remove inline axios mocks + switch to `axios.mockResolvedValue` in `Game2048.spec.js` and `MemoryGame.spec.js`; drop MemoryGame's CS-026 silencing. Re-run both specs.
4. Normalize `DailyTaskList.spec.js` to the shared mock (keep `axios.get.mockResolvedValue` lines).
5. Add the logger mock to the 11 error-path specs (C); un-silence `SchedulingView.spec.js` (D).
6. Add `scripts/check-test-noise.js` + `package.json` `test:noise` script; run it → must be zero.
7. `npm run lint` (lint:fix if needed); full build gate.
8. Backlog hygiene: move TECH-LINT-005 **and** TECH-LINT-003 from `docs/backlog/backlog.md` to `docs/backlog/archive/2026-08.md`; update `docs/backlog/ACTIVE_TASKS.md`.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| Dashboard/Settings semantics shift (error-by-accident → empty-success) changes rendered output that a test asserts on | Low | Dashboard/Settings specs assert only structural stubs/testids (verified); full-suite run in step 2 catches any drift; per-test `mockResolvedValue` overrides remain possible |
| Shared-mock default envelope masks a *genuine* unexpected error in specs relying on the default | Medium | `test:noise` gate keeps output clean-by-contract; unexpected errors would surface as assertion failures (state no longer in error), and deliberate error tests keep `mockRejectedValue` |
| A store crashes on empty `data: {}` (e.g. array method on object) | Low | Audited call sites (`minigame.js`, `user.js`, `titles.js`, `setup.js`) are property-read safe; step 2 verifies; envelope default can be tuned per finding |
| `jest.clearAllMocks()` interactions: implementations set via `mockResolvedValue` persist across tests in a file | Medium | Same behavior as today's `jest.fn(() => Promise.resolve(...))` impl; specs already re-set mocks in `beforeEach`; watch for cross-test bleed in step 2 |
| Un-silencing MemoryGame/SchedulingView exposes *new* noise not counted here | Low | Those specs' noise sources are fully fixed by steps 1/3/5; `test:noise` is the safety net |
| Rejecting the named-exports alternative leaves the dual call style (`api.js` default-call vs `DailyTaskList.vue:136` direct `axios.get`) in place | Accepted | Mock now supports both; aligning `DailyTaskList.vue` onto `api.js` is a production cleanup → optional backlog note, separate review |

## 8. Acceptance Criteria

- [ ] `npm test` green (70 suites / 701 tests), no skipped or weakened assertions; all `mockRejectedValue` error-path tests intact
- [ ] `npm run test:noise` exits 0 — zero `[ChoreStrike] [error]` lines in Jest output
- [ ] No inline `jest.mock('@nextcloud/axios', …)` overrides remain in specs; `tests/unit/__mocks__/nextcloud-axios.js` is the single canonical mock (callable default + envelope + named verbs)
- [ ] CS-026 `console.error` silencing removed from `MemoryGame.spec.js` and `SchedulingView.spec.js`
- [ ] TECH-LINT-005 and umbrella TECH-LINT-003 moved to `docs/backlog/archive/2026-08.md`
- [ ] Build gate green (`npm test` + lint + backend tests)
- [ ] Durable concepts/decisions integrated into the permanent docs (`docs/concepts/`, `docs/adr/`, `docs/engineering/`, `docs/api/`, `docs/standards/`) — plans are temporary
- [ ] `docs/backlog/ACTIVE_TASKS.md` updated

## Review Outcome

**Reviewed:** 2026-08-19 · Standard Review tier A (plan, pre-activation) · **Verdict: GO-WITH-CHANGES** (all changes applied inline during review)

- Verified against the tree: root-cause chain (`api.js:45` default-export call, `api.js:52-57` envelope unwrap, shared mock's non-callable default without envelope/patch, `logger.js:88`), the three inline mock overrides (`Game2048.spec.js:5-8`, `MemoryGame.spec.js:5-8`, `DailyTaskList.spec.js:13-21`), both CS-026 silencing spies, the `DailyTaskList.vue:136` direct-`axios.get` exception, the `tests/unit/store/checklist.spec.js:13-20` logger-mock pattern, `api.spec.js:8-11` reference shape, and backlog TECH-LINT-005/003. No overlap with active plans (none) or sibling CS-PLAN-035 (backend PHP logging).
- Fixed inline (reference accuracy only): stale backlog line refs (`:94-113`→`:131-150`, `:56-63`→`:93-100`, `:111-113`→`:148-150`), `checklist.spec.js` path missing `store/`, `SchedulingView.spec.js` spy lines `43-46`→`45-48` and failing-init test line `84`→`111`.
- Notes (no edit): (1) §4 vs acceptance criterion on the backend gate — no PHP changes, but run the Docker backend suite once if in doubt; (2) `scripts/check-test-noise.js` must combine stdout+stderr and tolerate Jest's console banner/ANSI wrapping around `[ChoreStrike] [error]`; (3) `DailyTaskList.vue` bypassing the canonical `api.js` client is deferred production cleanup (risk row 6) — backlog candidate, separate review.

## Implementation Outcome (2026-08-19)

Implemented as planned (§3 A–E, order per §6) with these deviations:

- **Suite size:** 71 suites / 717 tests (plan measured 70/701 — the tree grew with CS-033); all green, no assertions weakened or skipped.
- **Beyond the manifest (same workaround class):** `tests/unit/store/scheduling.spec.js` also carried a CS-026-style `console.error` silencing spy (its comment pre-announced removal by CS-034) — replaced with the logger mock. `tests/unit/components/SelfAvailabilitySettings.spec.js` (CS-032-era) had the identical silencing pattern — also replaced, so the noise gate honestly covers that spec instead of a spy masking future unexpected errors.
- **Backend gate:** not run — zero backend changes (lib/, composer, PHP tests untouched); frontend gate (Jest + `test:noise` + ESLint) fully green.
- Verification: `npm run test:noise` → exit 0, zero `[ChoreStrike] [error]` occurrences (108 → 0). Mock-matcher self-tested against ANSI + CRLF input. Durable conventions integrated into `docs/standards/guidelines/testing.md` ("Frontend Unit Test Mocking (Jest)"); TECH-LINT-005 + TECH-LINT-003 archived in `docs/backlog/archive/2026-08.md`; `ACTIVE_TASKS.md` updated.
