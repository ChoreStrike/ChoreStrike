---
name: testing
description: Writing and reviewing tests across PHPUnit, Jest, and Playwright
---

# Skill: Testing

**Load when:** Writing or reviewing tests, or changing behavior that must be covered.

**Canonical references:**
- [`testing.md`](../../../guidelines/testing.md)
- [`../../TESTING.md`](../../../../TESTING.md) — environment setup & command reference
- [`docker-dev`](../docker-dev/SKILL.md) — Docker test environment workflow

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
| E2E | Playwright (Docker MCP) | `tests/e2e/` |

Backend tests needing `OCP\*` run **only** inside the Docker test env (`docker-compose.test.yml`).

## Naming

- BDD: `MethodName_Scenario_ExpectedResult` (PHPUnit); `describe › it` (Jest).

## Coverage Priority

1. Authorization & ownership (house membership, owner-only ops).
2. Core domain: tasks, rooms, floors, gamification (sparks/combos), scheduling.
3. Cross-layer contracts (payload ↔ store ↔ component ↔ selector).
4. Failure paths — every swallowed/translated exception has a regression test.

When payload shapes change, refresh **both** unit and E2E coverage in the same change.

## Commands

```bash
# Frontend unit tests + lint (local)
npm test
npm run lint

# Backend unit tests (Docker)
docker compose -f docker-compose.test.yml run --rm nextcloud-test \
  vendor/bin/phpunit --configuration phpunit.unit.xml

# Backend integration tests (Docker)
docker compose -f docker-compose.test.yml run --rm nextcloud-test \
  vendor/bin/phpunit --configuration phpunit.xml tests/integration/

# E2E via Playwright (local, against running Docker test env)
npx playwright test tests/e2e/ --project=chromium
```

## E2E with Docker MCP

The project uses the **Docker MCP gateway** (`MCP_DOCKER`) for browser-based E2E testing. The gateway provides a containerized Playwright instance — use `MCP_DOCKER_browser_*` tools for interactive E2E verification during development. The test environment must be running (`docker compose -f docker-compose.test.yml up -d`) before E2E tests.

Use DB profiles (`empty` / `minimal` / `family`) via the Playwright fixtures for E2E seeding.
