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
| End-to-end | Playwright (local runner; Docker test environment) | `tests/e2e/` | Real Nextcloud + Vue, wired user flows |

Backend tests that need Nextcloud core (`OCP\*`) **must** run inside the Docker test environment (`docker/compose.test.yml`). See [`testing.md`](../../engineering/testing.md).

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

Established by CS-PLAN-034 — keep Jest output free of `[ChoreStrike] [error]` noise by contract:

- **`@nextcloud/axios` is mocked only via the canonical shared mock** `tests/unit/__mocks__/nextcloud-axios.js` (wired through `jest.config.js` `moduleNameMapper`). Never add an inline `jest.mock('@nextcloud/axios', …)` override in a spec. Production application requests go through the callable default export used by `src/services/api.js`. Sole exception: `tests/unit/services/api.spec.js` tests the axios integration itself and needs a bare `default: jest.fn()` (resolving `undefined`) to exercise `api.js`'s error paths — do not copy that shape into component/store specs.
- **Per-test overrides:** use `axios.mockResolvedValue(…)` for code going through `api.js`.
- **Envelope semantics:** the shared mock's default resolves `data: {}` — i.e. "request succeeded, payload empty object". Stores then hold truthy-but-empty state (`personalBest = {}`, `houseUsers = {}`). Whenever a spec asserts state *branches* (loaded vs not, empty vs populated) or consumes a **list** endpoint whose getters spread `[...state.x]`, override the envelope per endpoint (`axios.mockResolvedValue({ data: { success: true, data: [...] } })`) instead of relying on the default.
- **Deliberate error-path tests** (`mockRejectedValue` …) keep their assertions but mock `@/utils/logger` (`{ logger: { error, warn, info, debug } }`, see `tests/unit/store/checklist.spec.js`) so the expected failure does not print. **Never** silence with `jest.spyOn(console, 'error')` — spies hide genuine unexpected failures too.
- **Zero-noise gate:** `npm run test:noise` runs the full Jest suite and fails on any `[ChoreStrike] [error]` line in the output.
- **Docs encoding gate (CS-PLAN-050, C0 hardening CS-PLAN-052):** `npm run docs:encoding` scans all git-tracked text files and fails on invalid UTF-8 byte sequences, forbidden C0 control bytes, a lone CR, or known cp1252-misdetection mojibake signatures (UTF-8 punctuation or umlauts misread as cp1252, e.g. `\u00E2\u20AC…` lead bytes / `\u00C3…` umlaut class — cited as escapes here, never literal mojibake). The C0 signature flags every byte in `0x00–0x08, 0x0B, 0x0C, 0x0E–0x1F` (C0 except TAB/LF/CR; NUL also trips the binary probe) plus any `0x0D` not followed by `0x0A` — the escape-style corruption family that slipped past the original gate. Known residual (accepted): a TAB or LF that *ate a word-initial letter* is indistinguishable from legitimate code indentation and stays undetectable. A bare `?` in prose is never flagged. The gate runs blocking in the release gate; verify the detector with `node scripts/check-docs-encoding.js --self-test` (plants corrupted fixtures — including BEL/VT/ESC and lone-CR — via `Buffer` writes and asserts they are flagged with file + byte offset, while TAB/CRLF fixtures stay green). Related: `l10n:check-keys` is documented in [`i18n.md`](./i18n.md).

## E2E Notes

- Selectors must match the current Vue components (`src/views/*`, `src/components/*`). Verify a selector exists in `src/` before writing a spec against it; specs that reference dead selectors get deleted, not padded.
- A drifting spec is **not** a release gate until rewritten against a wired view; archive or delete obsolete specs instead of preserving fictional coverage.
- Use the DB profiles (`empty`, `emptyhouse`, `minimal`, `family`) via the Playwright fixtures.
- **Canonical browser gate (CS-PLAN-068 D1):** the release gate is
  `npm run test:e2e:gate` — never a raw multi-project `playwright test`
  invocation. The runner validates its required-file/scenario manifest by
  discovery, runs the three Chromium pools sequentially with
  `--workers=1 --retries=0 --forbid-only`, and fails on any
  skipped/failed/flaky required result. New required behavior gets a manifest
  row (file + owning pool + required scenario titles) in
  `scripts/run-e2e-gate.js`; deleting a covered spec or scenario fails
  discovery.
- **Pool isolation is enforced at runtime (CS-PLAN-068 §3.2):** the
  reset-owning pools are exactly `chromium-serial-db` and
  `chromium-scheduling-owner` (they run sequentially behind the gate). The
  `seededData` fixture throws a pool-guard violation anywhere else, and a
  missing test mode is a hard error — there are **no no-op fixtures and no
  runtime skips** for a broken environment. Failures fail; skips need an
  engine-capability reason.
- **Canonical journey:** cross-cutting household flows belong in
  `core-household-journey.spec.ts` (one test, named `test.step` phases,
  `emptyhouse` profile, UI-only mutations). Keep API-driven
  negative/fault-injection proofs in the focused specs, not the journey.
- **Auto-waiting, assertion-bearing tests only (CS-PLAN-050):**
  - No `page.waitForTimeout(...)` in specs — replace fixed sleeps with durable-outcome assertions (`expect(locator).toBeVisible()`, `expect.poll`/`toPass()`), which poll until the UI state is real.
  - No vacuous conditionals: `if (count > 0) { ... }` around assertions and try/catch-swallowed failures can pass without testing anything. Either assert the durable outcome unconditionally, or `test.skip(condition, 'reason')` explicitly — a skip without a reason is a defect. Completion receipts and before/after snapshots make reward assertions exact (never compare a value to itself; CS-PLAN-068 §3.4).
  - Shared dashboard floor expansion lives in `tests/e2e/fixtures/dashboard-floors.ts` (`expandAllFloors`, `expandUntilRoomVisible`); the Dashboard renders floors collapsed, so specs needing room cards must expand through these helpers instead of copy-pasting toggle loops.
  - Conditional *setup* is only acceptable when a durable assertion follows; conditional *assertions* are not. When de-vacuousing exposes a broken locator (strict-mode violations, hidden elements), fix the locator — never re-add the conditional.

## Test-Mode Tooling Contract (CS-PLAN-068 D5)

The test-tooling surface (`POST /api/v1/test/seed`, `POST …/reset`,
`GET …/profiles`, backed by `TestController`/`TestDataService`) is **plain
test tooling, not an app contract**:

- It is gated by `NEXTCLOUD_TEST_MODE` **in the server process** — unset,
  `false` or any non-`true` value answers `403 NOT_TEST_MODE` before any
  mutation (refusal-before-mutation, proven in-process by
  `TestModeApiTest` and live by the fixture probes).
- The routes carry `@NoAdminRequired` (never `@PublicPage`): an
  authenticated session is still required even in test mode.
- Reset/seed clears ChoreStrike app tables only; other apps' config and
  Nextcloud accounts survive.
- Changing PHPUnit's process environment never changes an already-running
  web server: the in-process proof (`TestModeApiTest`) and the live HTTP
  probes (Playwright fixtures against the Docker container) are separate,
  honestly labeled evidence layers.

## Before You Commit

```bash
# Frontend
npm test
npm run lint
# When frontend or build configuration changed
npm run build

# Backend (Docker — env must be up; always exec, never `compose run`)
docker compose -f docker/compose.test.yml exec -T nextcloud-test vendor/bin/phpunit --configuration phpunit.unit.xml
docker compose -f docker/compose.test.yml exec -T nextcloud-test vendor/bin/phpunit --configuration phpunit.integration.xml

# Browser gate when E2E-relevant behavior changed (Docker env up)
npm run test:e2e:gate
```

Build must be green before considering work complete. See [`llm-do-dont-checklist.md`](./llm-do-dont-checklist.md).
