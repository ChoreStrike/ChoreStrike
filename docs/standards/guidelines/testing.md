# Testing Guidelines

> What to test and how. For environment setup and the full command reference, see [`testing.md`](../../engineering/testing.md).
> Canonical rules: [`../ai/rules/project-rules.md`](../ai/rules/project-rules.md).

---

## Principles

- **Every new feature needs a test.** **Every bug fix needs a regression test.**
- Test behavior, not implementation. Prefer the public API.
- Keep tests fast, deterministic, and isolated. No shared mutable state between tests.

## Layers

| Layer | Tool | Location | Purpose |
|------|------|----------|---------|
| Backend unit | PHPUnit | `tests/unit/` | Services, mappers, entities, pure logic |
| Backend integration | PHPUnit (Docker) | `tests/integration/` | Controllers, API, multi-service flows |
| Frontend unit | Jest + @vue/test-utils | `tests/unit/` | Components, Pinia stores, views, l10n consistency |
| End-to-end | Playwright (Docker) | `tests/e2e/` | Real Nextcloud + Vue, wired user flows |

Backend tests that need Nextcloud core (`OCP\*`) **must** run inside the Docker test environment (`docker-compose.test.yml`). See [`testing.md`](../../engineering/testing.md).

## Naming

- **BDD style:** `MethodName_Scenario_ExpectedResult` (PHPUnit) / `describe › it` (Jest).
- Name tests from the user's perspective where possible.

## Coverage Focus

Prioritize:

1. Authorization & ownership (house membership, owner-only ops) — [`security.md`](./security.md).
2. Core domain: tasks, rooms, floors, gamification (coins/combos), scheduling.
3. Cross-layer contracts (backend payload ↔ Pinia store ↔ Vue component ↔ Playwright selector).
4. Failure paths — every swallowed/translated exception has a regression test.

When task or room payload shapes change, refresh **both** unit regressions and E2E smoke coverage in the same change.

## Frontend Unit Test Mocking (Jest)

Established by [CS-PLAN-034](../../plans/done/cs-plan-034-jest-axios-mock-noise.md) — keep Jest output free of `[ChoreStrike] [error]` noise by contract:

- **`@nextcloud/axios` is mocked only via the canonical shared mock** `tests/unit/__mocks__/nextcloud-axios.js` (wired through `jest.config.js` `moduleNameMapper`). Never add an inline `jest.mock('@nextcloud/axios', …)` override in a spec — the shared mock supports both production call styles: the callable default export resolving the success envelope (`src/services/api.js`) and the named verbs (`get/post/put/patch/delete`, e.g. `DailyTaskList.vue`). Sole exception: `tests/unit/services/api.spec.js` tests the axios integration itself and needs a bare `default: jest.fn()` (resolving `undefined`) to exercise `api.js`'s error paths — do not copy that shape into component/store specs.
- **Per-test overrides:** `axios.mockResolvedValue(…)` for code going through `api.js` (default-call), `axios.get.mockResolvedValue(…)` for components calling a named verb directly.
- **Envelope semantics:** the shared mock's default resolves `data: {}` — i.e. "request succeeded, payload empty object". Stores then hold truthy-but-empty state (`personalBest = {}`, `houseUsers = {}`). Whenever a spec asserts state *branches* (loaded vs not, empty vs populated) or consumes a **list** endpoint whose getters spread `[...state.x]`, override the envelope per endpoint (`axios.mockResolvedValue({ data: { success: true, data: [...] } })`) instead of relying on the default.
- **Deliberate error-path tests** (`mockRejectedValue` …) keep their assertions but mock `@/utils/logger` (`{ logger: { error, warn, info, debug } }`, see `tests/unit/store/checklist.spec.js`) so the expected failure does not print. **Never** silence with `jest.spyOn(console, 'error')` — spies hide genuine unexpected failures too.
- **Zero-noise gate:** `npm run test:noise` runs the full Jest suite and fails on any `[ChoreStrike] [error]` line in the output.

## E2E Notes

- Selectors must match the current Vue components (`src/views/*`, `src/components/*`).
- Legacy specs that drift (e.g. `phase-3-user-management.spec.ts`) are **not** release gates until rewritten against wired views.
- Use the DB profiles (`empty`, `minimal`, `family`) via the Playwright fixtures.

## Before You Commit

```bash
# Frontend
npm test
npm run lint

# Backend (Docker — env must be up; always exec, never `compose run`)
docker exec chorestrike-test vendor/bin/phpunit --configuration phpunit.unit.xml
```

Build must be green before considering work complete. See [`llm-do-dont-checklist.md`](./llm-do-dont-checklist.md).
