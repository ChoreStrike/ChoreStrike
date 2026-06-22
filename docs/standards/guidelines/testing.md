# Testing Guidelines

> What to test and how. For environment setup and the full command reference, see [`../../TESTING.md`](../../TESTING.md).
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

Backend tests that need Nextcloud core (`OCP\*`) **must** run inside the Docker test environment (`docker-compose.test.yml`). See [`../../TESTING.md`](../../TESTING.md).

## Naming

- **BDD style:** `MethodName_Scenario_ExpectedResult` (PHPUnit) / `describe › it` (Jest).
- Name tests from the user's perspective where possible.

## Coverage Focus

Prioritize:

1. Authorization & ownership (house membership, owner-only ops) — [`security.md`](./security.md).
2. Core domain: tasks, rooms, floors, gamification (sparks/combos), scheduling.
3. Cross-layer contracts (backend payload ↔ Pinia store ↔ Vue component ↔ Playwright selector).
4. Failure paths — every swallowed/translated exception has a regression test.

When task or room payload shapes change, refresh **both** unit regressions and E2E smoke coverage in the same change.

## E2E Notes

- Selectors must match the current Vue components (`src/views/*`, `src/components/*`).
- Legacy specs that drift (e.g. `phase-3-user-management.spec.ts`) are **not** release gates until rewritten against wired views.
- Use the DB profiles (`empty`, `minimal`, `family`) via the Playwright fixtures.

## Before You Commit

```bash
# Frontend
npm test
npm run lint

# Backend (Docker)
docker compose -f docker-compose.test.yml run --rm nextcloud-test \
  vendor/bin/phpunit --configuration phpunit.unit.xml
```

Build must be green before considering work complete. See [`llm-do-dont-checklist.md`](./llm-do-dont-checklist.md).
