---
name: testing
description: Writing and reviewing tests across PHPUnit, Jest, and Playwright
---

# Skill: Testing

**Load when:** Writing or reviewing tests, or changing behavior that must be covered.

**Canonical references:**
- [`testing.md`](../../../guidelines/testing.md)
- [`testing.md`](../../../../engineering/testing.md) — environment setup & command reference

Load [`docker-dev`](../docker-dev/SKILL.md) for Docker environment commands and troubleshooting.

---

## Rule

- **Every new feature needs a test. Every bug fix needs a regression test.**
- Tests are deterministic and isolated. No shared mutable state.

## Where Each Test Lives

| Type | Tool | Location |
|------|------|----------|
| Backend unit | PHPUnit | `tests/unit/` |
| Backend integration | PHPUnit (Docker) | `tests/integration/` |
| Frontend unit | Jest + @vue/test-utils | `tests/unit/` |
| E2E | Playwright (local runner; Docker test environment) | `tests/e2e/` |

Backend tests needing `OCP\*` run **only** inside the Docker test env (`docker-compose.test.yml`).

## Naming

- BDD: `MethodName_Scenario_ExpectedResult` (PHPUnit); `describe › it` (Jest).

## Coverage Priority

1. Authorization & ownership (house membership, owner-only ops).
2. Core domain: tasks, rooms, floors, gamification (coins/combos), scheduling.
3. Cross-layer contracts (payload ↔ store ↔ component ↔ selector).
4. Failure paths — every swallowed/translated exception has a regression test.

When payload shapes change, refresh **both** unit and E2E coverage in the same change.

## Commands

```bash
# Frontend unit tests + lint (local)
npm test
npm run lint

# E2E via Playwright (local, against running Docker test env)
npx playwright test tests/e2e/ --project=chromium
```

Backend unit/integration commands and environment recovery live only in the
`docker-dev` skill to avoid drifting command copies.

## Interactive E2E

When the active tool exposes browser automation, it may be used for interactive
verification against the running Docker test environment. The checked-in
Playwright suites remain the canonical E2E gate.

Use DB profiles (`empty` / `minimal` / `family`) via the Playwright fixtures for E2E seeding.
