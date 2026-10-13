---
name: testing
description: Writing and reviewing tests across PHPUnit, Jest, and Playwright
---

# Skill: Testing

**Load when:** Writing or reviewing tests, or changing behavior that must be covered.

**Canonical references:**
- [`testing.md`](../../../guidelines/testing.md) — strategy, Jest mocking contract, E2E rules, test-mode tooling
- [`e2e-testid-conventions.md`](../../../guidelines/e2e-testid-conventions.md) — before touching any `tests/e2e/*.spec.ts`
- [`docker-dev`](../docker-dev/SKILL.md) — backend commands and environment recovery (the only copy)
- [`engineering/testing.md`](../../../../engineering/testing.md) — environment setup, provider matrix

---

## Rule

- **Every feature needs a test; every bug fix needs a regression test.** Deterministic, isolated, no shared mutable state.
- Never done while a required test is missing, newly/unapproved skipped, or red (project-rules §2).

## Where Tests Live

| Type | Tool | Location |
|------|------|----------|
| Backend unit | PHPUnit (Docker) | `tests/unit/` (`*.php`, e.g. `Service/`, `Controller/`, `Db/`) |
| Backend integration | PHPUnit (Docker) | `tests/integration/` |
| Frontend unit | Jest + @vue/test-utils | `tests/unit/` (`*.spec.js`, e.g. `components/`, `store/`, `scripts/`) |
| E2E | Playwright (local runner, Docker test env) | `tests/e2e/` |

Naming: `MethodName_Scenario_ExpectedResult` (PHPUnit); `describe › it` (Jest).

## Coverage Priority

1. Authorization & ownership. 2. Core domain: tasks, rooms, floors, rewards (wallet, work splits, corrections), scheduling. 3. Cross-layer contracts (payload ↔ store ↔ component ↔ selector) — refresh unit **and** E2E coverage when payload shapes change. 4. Failure paths.

## Commands

```bash
npm test                  # Jest (also: npm run test:noise for the zero-noise gate)
npm run lint
npx playwright test tests/e2e/<spec>.ts --project=<owning pool> --retries=0   # focused iteration
npm run test:e2e:gate     # canonical browser gate (three Chromium pools) — the only release proof
```

E2E seeding uses the DB profiles `empty`, `emptyhouse`, `minimal`, `family` via the Playwright fixtures. Browser automation from the agent tool may support interactive checks; the checked-in suites remain the gate.
