# Test Environment Setup

This guide explains how to set up a Docker-based test environment for ChoreStrike that includes all Nextcloud dependencies required for PHPUnit tests.

## Prerequisites

### Preferences and Digest Evidence (WP5)

The [notification pipeline](notifications.md) is covered by
`PreferenceServiceTest`/`PreferenceControllerTest` (self-only transport and the
merged validation matrix), `DigestScheduleServiceTest` (real calendar DST
gap/fold, UTC control, windows, cutoff and retained-history spacing), and
`DigestServiceTest` (dispatch-time relevance, current visibility, source-state
checks, quiet hours, pre-claim retry and uncertain outcomes).

`DigestDeliveryTest` verifies real-DB expiry/coalescing and a takeover queue
write sabotaged after insertion: the source completion and queue roll back
together, then the same receipt succeeds once and replays without another row.
`DigestConcurrencyTest` uses pipe barriers and a held house lock, never sleep
timing, with separate Nextcloud worker processes for materialization, claims,
crash-after-claim, preference toggles/time changes and timezone changes.
Preference/departure/erasure/schema extensions remain in their existing suites.

`quiet-digest.spec.ts` replaces the retired award-bell scenario and belongs to
`chromium-serial-db`: silent defaults and the Settings save, one combined
task/organizational digest with replay suppression, and quiet/missed-window
suppression. A passing subset is not the release gate; run
`npm run test:e2e:gate` for all required scenarios in all three pools.

`family-rewards.spec.ts` (CS-PLAN-070 WP8, CS-PLAN-079) also belongs to
`chromium-serial-db`: it flips the catalog gate, roles and wallets against
the shared family seed and drives the reward UI flows through the browser
(owner administration while the catalog is off, child request → approval →
delivery, adult self-approval/cancellation/correction, funding hold,
privacy/identity boundaries, uncertain-command replay, and the
mobile/keyboard/EN-DE proof). APIs only set up data and stimulate
concurrent changes; the measured actions run in the UI.

### All Platforms (Windows, Linux, macOS)

- **Docker**: Version 20.10 or higher
  - Windows: [Docker Desktop](https://docs.docker.com/desktop/install/windows-install/)
  - Linux: [Docker Engine](https://docs.docker.com/engine/install/)
  - macOS: [Docker Desktop](https://docs.docker.com/desktop/install/mac-install/)

- **Git**: For cloning the repository

## Quick Start

### Run Tests with Docker Compose

```bash
# Build and run all tests
docker compose -f docker/compose.test.yml up --build

# Or with legacy docker-compose:
docker-compose -f docker/compose.test.yml up --build
```

### Using the Test Script (Linux/macOS/WSL)

```bash
# Make the script executable
chmod +x scripts/run-tests.sh

# Run tests
./scripts/run-tests.sh
```

**Note for Windows users**: Use Git Bash, WSL, or PowerShell to run the shell script.

## Manual Setup (Detailed)

### 1. Clone Repository

```bash
git clone https://github.com/ChoreStrike/ChoreStrike.git
cd ChoreStrike
```

### 2. Build Test Environment

```bash
# Build the Docker image (first time takes ~3-4 minutes)
docker compose -f docker/compose.test.yml build --no-cache
```

### 3. Run Tests

> Always `docker exec` into the **running** `chorestrike-test` container — never `docker compose run` (a fresh container skips the entrypoint, so Nextcloud is not installed and PHPUnit dies with `Exception: Not installed`).

```bash
# Make sure the environment is up
docker compose -f docker/compose.test.yml up -d

# Run all unit tests
docker exec chorestrike-test vendor/bin/phpunit --configuration phpunit.unit.xml

# Run all integration tests
docker exec chorestrike-test vendor/bin/phpunit --configuration phpunit.integration.xml

# Run a specific test file
docker exec chorestrike-test vendor/bin/phpunit tests/unit/Service/TaskServiceTest.php

# Run a specific test method
docker exec chorestrike-test vendor/bin/phpunit --filter testCreate_ValidData_ReturnsCreatedTask
```

### 4. Interactive Development

#### Native User-Rate Limits

The disposable test image installs APCu and the entrypoint configures
`memcache.local` plus `ratelimit.protection.enabled=true`. Nextcloud 31's native
rate-limiter uses its distributed-cache factory, which falls back to the local
cache. A `NullCache` cannot retain attempts across requests and is not valid
evidence for a 429 boundary. Production operators must likewise provide a
working request-shared Nextcloud cache; the app does not implement a replacement
limiter or assume that an unconfigured cache enforces the annotations.

`family-membership-hardening.spec.ts` owns fresh synthetic NC accounts through
OCC, verifies all three 60-calls/300-second endpoint boundaries and both
identity/endpoint isolation, then deletes only those accounts. It runs solely
in `chromium-serial-db`, without cache flushing or shortened limit windows.
`settings.spec.ts` also owns the serial pool: its populated reset proof compares
setup/structure, task/occurrence facts, completion allocation/history, wallet
reservations, reward terms and earned recognition after cancel, failure, retry
and receipt replay. Never run either spec concurrently with another DB writer.

For interactive debugging or running commands inside the container:

```bash
# Start a shell in the test container
docker exec -it chorestrike-test bash

# Then inside the container:
vendor/bin/phpunit --filter testCreate_ValidData_ReturnsCreatedTask
```

## Platform-Specific Notes

### Windows

#### Docker Desktop + WSL2 (Recommended)

1. Install [Docker Desktop](https://docs.docker.com/desktop/install/windows-install/)
2. Enable WSL2 backend in Docker Desktop settings (Settings → General → Use WSL 2)
3. Enable Virtualization in BIOS (if not already enabled)
4. Install [WSL2](https://docs.microsoft.com/en-us/windows/wsl/install):
   ```powershell
   wsl --install
   ```
5. Clone and run tests from WSL:
   ```bash
   wsl
   cd /mnt/c/path/to/ChoreStrike
   docker compose -f docker/compose.test.yml up --build
   ```

#### Docker Desktop + PowerShell (Alternative)

1. Install Docker Desktop
2. Open PowerShell
3. Run:
   ```powershell
   docker compose -f docker/compose.test.yml up --build
   ```

### Linux

```bash
# Install Docker (example for Ubuntu/Debian)
sudo apt update
sudo apt install docker.io docker-compose-plugin

# Add user to docker group (requires logout/login)
sudo usermod -aG docker $USER

# Run tests
docker compose -f docker/compose.test.yml up --build
```

### macOS

```bash
# Install Docker Desktop
brew install --cask docker

# Run tests
docker compose -f docker/compose.test.yml up --build
```

## Troubleshooting

### "Cannot connect to Docker daemon"

- **Linux**: Ensure your user is in the `docker` group and Docker service is running:
  ```bash
  sudo systemctl start docker
  ```
- **Windows/macOS**: Ensure Docker Desktop is running

### "Class OCP\* not found"

This means the test is running outside the Docker environment or Nextcloud core is not properly loaded. Always use `docker compose -f docker/compose.test.yml` to run tests.

### "Failed to open stream: No such file or directory"

The Nextcloud server clone might have failed. Rebuild the image:
```bash
docker compose -f docker/compose.test.yml down --rmi all
docker compose -f docker/compose.test.yml build --no-cache
```

### Permission Denied Errors

```bash
# Fix permissions on Linux/WSL
sudo chown -R $USER:$USER .
```

### Slow Test Execution

On Windows without WSL2, file operations can be slow. Enable WSL2 backend in Docker Desktop for better performance.

### Interrupted DB Jobs and Fixture Diagnosis

A client/tool timeout does **not** prove that container-side PHPUnit or its
helper processes stopped. Before another integration, seeding, recovery or
browser mutation job, inspect `docker top chorestrike-test -eo pid,ppid,etime,args`
and verify that the previous PHPUnit/OCC/helper writers have exited. Wait for
bounded owned workers or stop only positively identified test-owned processes;
never start a second writer on the assumption that losing the Docker client
cancelled the first. The CS-PLAN-081 DB-free timeout probe demonstrated this
survival mechanism, not overlap during its historical failing run.

Preserve each failed attempt's command, candidate identity, output/test names,
stacks, ordering, timestamps and process state before rerunning. PHPUnit's
`depends,defects` cache can reorder later runs. For an order-independent
diagnostic comparison, explicitly use `--order-by=default --do-not-cache-result`
alongside `--fail-on-skipped`; a green rerun is neither root-cause proof nor
permission to discard the earlier failure. Probe both DBAL nesting and native
transaction state without committing/rolling back unknown inherited state.
The integration base cleans its owned app fixtures but retains Nextcloud
accounts; new-account provisioning and reuse therefore exercise different paths.

Concurrency helpers must prove successful commit **and normal exit** before
parent cleanup or final assertions. A pre-commit readiness signal proves only
that the worker reached that point. `SchedulePlanConcurrencyTest` covers real
apply, early conflict, aborted commit and stalled-worker rejection with one
parent-selected target day; an aborted independent row-holder transaction does
not explain disappearance of other tests' house/member fixtures.

Time-sensitive tests should use existing explicit-clock seams and fixed
expectations. At Sunday 22:30 UTC in summer (23:30 in winter), Berlin and UTC
can select different calendar weeks. `TimezoneDayBucketingTest` now pins both
same-week offsets and these cross-week cases through
`WeeklyGoalService::progressForWeek`; comparing live current-week starts as if
they always differ by only one or two hours is invalid.

## Continuous Integration

### Retained-Baseline and Recovery Evidence

The [canonical retention rule](../standards/ai/rules/project-rules.md#data-retention-boundary-approved-not-yet-adopted)
is approved for future explicit adoption, not active for disposable dev installs.
After adoption, exercise every new migration against a populated copy of that
recorded baseline on all supported providers; assert preserved identifiers,
relations, planning/history and financial facts and fresh/upgrade schema parity.
An unchanged-app reinstall, edited already-applied step, or restore of the same
build does not prove an upgrade.

Recovery runs need exclusive ownership, with no integration/browser/cron mutation
jobs overlapping the backup point or assertions. Use separately named disposable
containers/volumes and isolated network; never restore into the personal server,
the canonical browser pool or unrelated volumes. Capture matched code/config,
whole DB and applicable data while writers are paused, then compare ChoreStrike
facts **and unrelated Nextcloud sentinel state** after restoration. Test a
deliberate post-backup divergence to show restoration actually replaced state.
Raw artifacts/manifests remain private/gitignored. See the
[release recovery record](../testing/release-test-plan.md#16-upgrade--recovery)
for exact assertions and actual sanitized evidence; the
[deployment procedure](deployment.md#rollback) owns operator approval and scope.

The authoritative CI workflow is `.forgejo/workflows/release-gate.yml`
(GHA-compatible, runs on Forgejo Actions / Codeberg CI and GitHub Actions).
It is the single source of truth for blocking gate commands.

### Blocking gate commands

| Command | Purpose |
|---------|---------|
| `npm run lint` | ESLint, 0 errors required |
| `npm run stylelint` | Stylelint, 0 errors required (`src/` Vue/CSS/SCSS) — CS-026 |
| `npm test` | Jest (Vue components, stores, API contract) |
| `npm run build` | Webpack production build |
| `composer validate --strict` | `composer.json` sanity |
| `composer cs:check` | PHP coding standard (Nextcloud CS), 0 diff required — CS-026 |
| `vendor/bin/phpunit --configuration phpunit.unit.xml` | Backend unit tests — Entity/Service/Controller/BackgroundJob/Command/Contract/Notification/Migration/l10n suites (via Docker) |
| `vendor/bin/phpunit --configuration phpunit.integration.xml` | Backend integration tests (via Docker) |
| `npm run test:e2e:gate` | Canonical sequential browser gate (CS-PLAN-068 D1): manifest discovery check + chromium → chromium-serial-db → chromium-scheduling-owner sequentially, `workers=1 --retries=0 --forbid-only`, fails on any skipped/failed/flaky required scenario (via Docker) |

### Non-blocking (advisory)

| Command | Reason |
|---------|--------|
| _(none — stylelint + PHP CS were promoted to blocking in CS-026, 2026-08-12; stylelint still emits ~26 `use-logical` warnings, deferred → backlog TECH-LINT-004)_ |

> **Unit-gate scope (CS-PLAN-020, 2026-07-29):** `phpunit.unit.xml` now runs the
> full green unit set — Controller + Service + Entity(Db) + BackgroundJob +
> Command + Contract + Notification + Migration + l10n suites. The migration mock deps
> (`ISchemaWrapper`/`IOutput`/`SimpleMigrationStep`) were added to
> `bootstrap-entity.php` so the Migration suite runs in the CI unit gate, and a
> `FreshInstallMigrationSmokeTest` guards the recurring `changeSchema() return
> null` defect class. The 4 stale controller-test excludes that once lived in
> the former umbrella `phpunit.xml` are resolved (the umbrella config was
> removed in CS-PLAN-069; `composer test` now runs `phpunit.unit.xml`
> directly): `GameControllerTest`/`RoomControllerTest`/
> `RoomTaskControllerTest` re-included after drift repair,
> `PageControllerTest` archived (framework wiring covered by E2E).
> CS-PLAN-023 (2026-08-04) added the **Command** suite (OCC commands;
> Symfony Console surface mocked under `tests/__mocks__/Symfony/`) and the
> **Contract** suite (`RouteMatrixTest`, which previously lived in no suite;
> now incl. the route-shadowing guard).

### E2E in CI (blocking, CS-PLAN-020)

E2E is a **blocking job** in `.forgejo/workflows/release-gate.yml`. Since
CS-PLAN-068 it runs the canonical sequential runner `npm run test:e2e:gate`
(scripts/run-e2e-gate.js) instead of a raw `playwright test` invocation.
The runner:

1. **Discovery** — `playwright test --list --reporter=json` per project and
   validates the required-file/scenario manifest in the script: every
   required spec must be selected by exactly ONE project (a deleted or
   unselected required test is a selection gap; two pools owning one
   spec is a duplicate-ownership error).
2. **Execution** — the three Chromium pools run SEQUENTIALLY with
   `--workers=1 --retries=0 --forbid-only` against one disposable database
   (`family` seed): `chromium`, then `chromium-serial-db`, then
   `chromium-scheduling-owner`.
3. **Result check** — zero skipped, failed, flaky or interrupted tests in
   any required spec; a missing test environment FAILS instead of skipping
   (no-op fixtures were removed by CS-PLAN-068 §3.2).

The runner self-test (`node scripts/run-e2e-gate.js --self-test`) feeds
synthetic discovery/report fixtures through the same checks (selection gap,
duplicate ownership, synthetic skip/flaky/failure) without launching a
browser. The full browser matrix (firefox/webkit) stays in
`.forgejo/workflows/test.yml` and is a documented follow-up once the
chromium gate proves stable in CI. The authorisation-denial wire contract
(plain HTTP 403, not a 200 OCS envelope) is verified by the E2E specs
`admin-only-tasks.spec.ts` and `overdue-task-claiming.spec.ts`.

### Running the gate locally

```bash
# Frontend
npm ci
npm run lint
npm test -- --ci
npm run build

# Backend (Docker)
docker compose -f docker/compose.test.yml up -d
docker compose -f docker/compose.test.yml exec nextcloud-test \
  bash -c "cd /var/www/html/custom_apps/chorestrike && \
    vendor/bin/phpunit --configuration phpunit.unit.xml && \
    vendor/bin/phpunit --configuration phpunit.integration.xml"

# Browser gate (Docker env up, canonical sequential runner)
# Stabilization (CS-PLAN-068): disable the AJAX job scheduler first — under
# AJAX cron, background jobs execute inline with page loads and cause
# periodic slow windows that flake the pools (the gate never depends on
# scheduled jobs; time-travel specs trigger jobs explicitly via
# `occ chorestrike:trigger-jobs`).
docker compose -f docker/compose.test.yml exec -T nextcloud-test \
  su -s /bin/bash www-data -c "cd /var/www/html && php occ background:cron"
npm run test:e2e:gate
docker compose -f docker/compose.test.yml down
```

### Contract tests (Phase 2 of CS-PLAN-007)

- `tests/unit/RouteMatrixTest.php` — every route in `appinfo/routes.php`
  resolves to a public method on the corresponding controller class.
  Catches the "missing controller method" and "unrouted implemented method"
  drift patterns.
- `tests/unit/store/apiContract.spec.js` — every API endpoint called by
  the Pinia stores (`src/store/*.js`) via the shared `api` client matches at
  least one declared route. Derived from the same `appinfo/routes.php` source.
  Also asserts no store uses the deprecated `/index.php/apps/chorestrike`
  prefix form (single canonical prefix `/apps/chorestrike` only).
- `tests/unit/services/api.spec.js` — shared API client (`src/services/api.js`)
  contract: URL prefix construction, `{ success, data, error }` envelope
  unwrap, `!success` / HTTP-error → `ApiError`, and method-verb mapping.

These tests are part of the blocking gate.

### Timezone / day-boundary tests (CS-PLAN-057)

Calendar-day semantics bucket in the house timezone (ADR-009), so a fresh
schema (`down -v` + `up -d`) is required when a timezone migration is added
— an existing test DB has no `migrations:execute` command on Nextcloud 31.

- `tests/unit/Service/CalendarServiceTest.php` — pins the
  `dayBounds`/`weekStart`/`dayKey` primitives around DST transitions in
  UTC, Europe/Berlin and Pacific/Auckland (spring-forward 23h /
  fall-back 25h windows, Sunday week-start semantics).
- `tests/integration/Service/TimezoneDayBucketingTest.php` — real-DB pins:
  a completion at 22:30 UTC (00:30 Berlin) counts for the NEXT Berlin day
  in the duplicate guard and the streak/freeze window; the Berlin weekly
  window differs from UTC's; the minigame weekly leaderboard follows the
  house week (D3 fix).
- `tests/unit/utils/dateKeys.spec.js` — frontend `dateKeyInZone` pins
  (midnight crossings, epoch/invalid guards, DST overlap).

The E2E container runs UTC; timezone behavior is covered at the
unit/integration level above.

## E2E Tests with Playwright

End-to-end tests run against a full Docker-based Nextcloud instance with the ChoreStrike app enabled.

### Prerequisites

- Docker and Docker Compose
- Node.js 20.19.0 / npm 10.8.2 minimum within the supported major ranges; see
  [the build prerequisites](development.md) and `package.json` engines.
- Playwright browsers installed: `npx playwright install chromium`

### Setting up the Test Environment

#### 1. Start Docker Test Environment

```bash
# Start the test environment (Nextcloud + MariaDB)
docker compose -f docker/compose.test.yml up -d

# Wait for services to be ready (~30 seconds)
docker compose -f docker/compose.test.yml logs -f nextcloud-test
```

The test environment includes:
- **MariaDB** (`chorestrike-db`): Database with healthchecks
- **Nextcloud Test** (`chorestrike-test`): Apache + PHP with Nextcloud stable29
- **Auto-setup**: Nextcloud installs automatically, ChoreStrike app is linked and enabled

#### 2. Verify Environment is Ready

```bash
# Check containers are running
docker ps

# Check Nextcloud is accessible
curl http://localhost:8080/apps/chorestrike/

# Check logs if issues occur
docker compose -f docker/compose.test.yml logs nextcloud-test
docker compose -f docker/compose.test.yml logs chorestrike-db
```

#### 3. Run E2E Tests

```bash
# Run all E2E tests
npx playwright test tests/e2e/ --project=chromium

# Run specific test file
npx playwright test tests/e2e/3-floor-house.spec.ts --project=chromium

# Run with UI mode for debugging
npx playwright test tests/e2e/ --ui

# Run in headed mode (visible browser)
npx playwright test tests/e2e/ --project=chromium --headed
```

### E2E Test Structure

> The full, current list of spec files is the source of truth — run
> `git ls-files 'tests/e2e/*.spec.ts'` (or `ls tests/e2e/*.spec.ts`) for it.
> The groupings below are a navigational aid, not an exhaustive contract.

| Test File | Coverage |
|-----------|----------|
| `minimal-smoke-tests.spec.ts`, `fast-core-tests.spec.ts` | Fast smoke coverage for boot, shell, hash deep-links, and key pages (lean auto-waiting smoke layer, CS-PLAN-050) |
| `validation.spec.ts`, `error-handling.spec.ts`, `today-view.spec.ts` | Data validation, error handling, and daily-task flows (Today view) |
| `dashboard.spec.ts`, `dashboard-navigation.spec.ts`, `core-functionality.spec.ts` | Dashboard and cross-view navigation coverage |
| `room-management.spec.ts`, `room-detail.spec.ts`, `3-floor-house.spec.ts` | Room/floor CRUD, room-detail flows, full-house scenarios |
| `task-manager.spec.ts`, `task-completion.spec.ts`, `task-completion-full-flow.spec.ts`, `task-checklist-and-features.spec.ts` | Task management, creation, checklist, and completion flows |
| `settings.spec.ts`, `core-household-journey.spec.ts` | Settings and the canonical empty-house UI-only setup/invite/create/plan/complete/undo journey |
| `leaderboard.spec.ts`, `ranking-consent.spec.ts`, `title-selection.spec.ts` | Gamification: opt-in/closed leaderboard surface (WP4), the consent lifecycle (owner enable → join pending → withdraw, serial pool), and the single-series title surface (no pack selection) |
| `private-tasks.spec.ts`, `admin-only-tasks.spec.ts`, `teamwork-rewards.spec.ts` | Visibility (private/admin-only) and teamwork-bonus rewards (CS-PLAN-005) |
| `overdue-task-claiming.spec.ts`, `multi-user-interactions.spec.ts` | Overdue takeover and multi-user/family interactions |
| `time-travel-overdue.spec.ts`, `time-travel-streak-notification.spec.ts` | Time-travel flows (CS-PLAN-023): overdue via seeded past due-date, streak-freeze + at-risk notification via `trigger-jobs --as-of` |
| `snake-game.spec.ts` | Minigame (Snake arcade) coverage — the arcade is its own opt-in surface under `#/arcade` (CS-PLAN-070 WP6), no longer a Dashboard section |
| `calm-core-ui.spec.ts` | Calm core UI (CS-PLAN-070 WP6): all-role My Day landing with the Dashboard as the explicit secondary overview (`#/dashboard`), Coin-free My Day with the wallet staying in the secondary overview, the optional arcade hiding together with its navigation entry while `arcadeVisible` is off, and the neutral pause lifecycle (child request → owner decision → early end). Runs in `chromium-serial-db` (preference/pause state is seed-mutating) |
| `scheduling.spec.ts`, `flex-window-scheduling.spec.ts`, `availability-settings-flow.spec.ts` | Smart Scheduling UI — availability grid, energy budget, capacity calendar, owner-plan preview gating (revived by CS-PLAN-016; plan preview/apply contract by CS-PLAN-065) |
| `scheduling-apply-flow.spec.ts` | Owner plan exit journey (CS-PLAN-065): preview → adjust → apply over real seeded tasks, applied-assignment verification in My Day + calendar, stale-preview 409, unplaced overdue zero-flex row, retired taskCount contract rejection |
| `family-rhythm.spec.ts` | Occurrence-timeline client contract (CS-PLAN-072 WP1, ADR-013): a UI completion carries the full receipt (`commandId`/`occurrenceId`/`expectedVersion`/`selectionToken`), a new command on a fulfilled occurrence is a 409 (never credits another target), a replayed `commandId` returns the original identity, and a stale receipt after a due-date move is a 409. Runs in `chromium-serial-db` (seed-mutating). The deep enumeration/revision/catch-up math stays owned by the service-level `OccurrenceTimelineTest` |
| `self-availability-member.spec.ts` | Non-owner scheduling surface: "My Availability" self-service toggles, hidden owner grid, persist/reload round-trip (CS-PLAN-032; runs in default `chromium` as `admin`, ignored by firefox/webkit) |

> **Archived (CS-PLAN-015, 2026-07-26):** `phase-3-user-management.spec.ts`
> (retired group-assignment UI) and `phase-4-advanced-mechanics.spec.ts` (phantom
> components) were moved to `archive/2026-07/e2e-legacy/`. The scheduling trio
> (`scheduling`, `flex-window-scheduling`, `availability-settings-flow`) was
> also archived but has been **revived by CS-PLAN-016** (2026-07-28) and is
> active again — see the table above.

### E2E Auth & Stable Selectors (CS-PLAN-015 / 015b)

- **Auth via per-user `storageState`:** the Playwright `setup` project
  ([`tests/e2e/auth.setup.ts`](../../tests/e2e/auth.setup.ts)) logs in the admin
  plus every **`family`** user once and saves the browser contexts to
  `build/.playwright-auth/{userId}.json` (gitignored `build/` tree). Auth is
  **decoupled from
  `TEST_DB_PROFILE`**: the setup always seeds + authenticates the `family`
  identities every spec consumes (mom/dad/teen/kid/grandma/friend), while
  `TEST_DB_PROFILE` only controls the per-test seed DATA (re-seeded by the
  `seededData` fixture). This keeps family-pinning specs green under every CI
  matrix row (`empty`/`minimal`/`family`). Accounts survive re-seeding
  (`TestDataService::reset()` touches only `chorestrike_*` tables, never
  `oc_users`). The browser projects depend on setup and start authenticated as
  admin, so specs that run as admin need **no login call** — just navigate.
- **Multi-user auth patterns** (see
  [`e2e-testid-conventions.md`](../standards/guidelines/e2e-testid-conventions.md) §6):
  - Single-user describe → `test.use({ storageState: userStatePath('mom') })`.
  - Per-test / multi-user → `createUserContext(browser, 'teen')` from
    [`tests/e2e/fixtures/auth-contexts.ts`](../../tests/e2e/fixtures/auth-contexts.ts).
  - **No-logout rule:** no spec may navigate to or fetch `/logout` — it
    invalidates the shared server-side session for every parallel worker.
- **Seed-race containment:** the runner's file/project registry and frozen
  scenario inventory below are the current ownership contract. Reset
  consumers use the serial DB or scheduling-owner pools; no other project
  may call either destructive fixture entry point. The original
  CS-PLAN-015b seven-spec inventory is historical, not the current selection.
- **Seed/test-endpoint auth (CS-PLAN-019 + CS-PLAN-023):** the test endpoints
  require an authenticated session (no `@PublicPage`). The E2E fixtures
  (`database-fixtures.ts`) and `auth.setup.ts` therefore authenticate with the
  admin account via Basic auth and use POST for seed/reset. The time-travel
  specs additionally shell out to `occ` via `tests/e2e/fixtures/occ-runner.ts`.
- **Scheduling owner-context (CS-PLAN-018 Phase 3b):** `scheduling.spec.ts` and
  `availability-settings-flow.spec.ts` run in a dedicated
  `chromium-scheduling-owner` project as `dad`
  (`storageState: 'build/.playwright-auth/dad.json'`, `fullyParallel: false`,
  `workers: 1`). Root cause: admin is a lone `member` (house 418) with no
  owner-only availability data; owners are `dad`/`mom` (house 420). The
  parallel pool excludes these specs via `testIgnore`.
- **Stable selectors:** prefer `getByRole`/`getByLabel`, fall back to
  `getByTestId`. Never use `text=` or positional CSS selectors. No
  `waitForTimeout` — use auto-waiting locators/assertions. See
  [`docs/standards/guidelines/e2e-testid-conventions.md`](../standards/guidelines/e2e-testid-conventions.md).
- **Flake acceptance run:** the live config retries twice, which masks flakes —
  verify with `npx playwright test --retries=0` across the full matrix
  (chromium + firefox + webkit + chromium-serial-db + chromium-scheduling-owner).
  CS-PLAN-018 Phase 7 (2026-07-29) proved the suite flake-free: 3× full-matrix
  `--retries=0`, 0 failed.

### Full-Matrix Verification (CS-PLAN-051, BUG-E2E-004)

The blocking release gate stays chromium-shaped (`chromium` +
`chromium-serial-db`, see `.forgejo/workflows/release-gate.yml`). The full
browser matrix is verified two ways:

1. **Manually (acceptance):** bring up the Docker env, reseed the family
   profile, then run `npx playwright test --retries=0 --reporter=line` with
   `TEST_DB_PROFILE=family` and `NEXTCLOUD_TEST_MODE=true`. Acceptance = two
   **consecutive** green runs. A full matrix run takes ~10–13 min locally.
2. **Scheduled CI (non-blocking):**
   [`.forgejo/workflows/full-matrix.yml`](../../.forgejo/workflows/full-matrix.yml)
   runs the whole matrix weekly (Mondays 04:00 UTC) and on `workflow_dispatch`
   with `--retries=0`, uploads the Playwright report as an artifact, and never
   blocks a release. Treat red scheduled runs as flake leads, not gate
   failures: reproduce the failing spec standalone (3× `--retries=0` in the
   failing browser), then under load (run it alongside fast-core + release
   specs) before diagnosing.

Full-matrix stabilization history (CS-PLAN-051, 2026-08-26):

- `InsightsView.vue` renders the shared `ErrorState` with retry for **both**
  failed fetches (stats → `insights-error`, members → fairness section); a
  transient fetch failure never surfaces as a false "No members yet" hint.
- `TaskList.vue` carries `data-testid="visibility-lock-icon"` on private rows
  (mirror of the crown testid); the icon specs assert API-created fixture rows
  (`tests/e2e/fixtures/visibility-fixtures.ts`) instead of seed-content
  regexes, and no longer use `waitForLoadState('networkidle')`.
- `icons.js` fluent components render via `h()` (the runtime `template:`
  string needed the compiler the esm-bundler build does not ship), and the
  webpack asset rule no longer double-handles the Fluent SVGs — before this
  fix every fluent icon rendered empty under production bundling.
- `snake-game.spec.ts` pause test starts and pauses in one renderer task
  (`evaluate`: click + synchronous `keydown`), with a bounded retry that
  clicks whichever start/play-again button is present.
- `task-checklist-and-features.spec.ts` moved to `SEED_DEPENDENT_SPECS` —
  it mutates seed state and uses seed-coupled row locators.

### Test Architecture

The Playwright suite runs against the real Vue frontend inside the Docker-based Nextcloud test environment.

Important implications:

- **Selectors must match the current Vue components** (`src/views/*`, `src/components/*`).
- **Form flows matter**. For example, current task creation uses `TaskEditForm.vue`, while room assignment uses `TaskForm.vue`.
- **Release confidence comes from wired views**, not from orphaned or legacy-only components.

When changing task or room flows, update these layers together:

- `src/views/TaskManager.vue`
- `src/views/RoomDetail.vue`
- `src/components/Tasks/TaskList.vue`
- `src/components/Tasks/TaskForm.vue`
- `src/components/Tasks/TaskEditForm.vue`
- the affected Playwright specs

### Troubleshooting E2E Tests

**Timeout waiting for dashboard-view:**
- Ensure Docker containers are running: `docker ps`
- Check Nextcloud logs: `docker logs chorestrike-test`
- Verify app is enabled: `docker exec chorestrike-test occ app:list | grep chorestrike`

**Database connection errors:**
- Check MariaDB is healthy: `docker compose -f docker/compose.test.yml ps`
- Restart environment: `docker compose -f docker/compose.test.yml restart`

**Test flakiness:**
- Increase timeouts in `playwright.config.ts`
- Run with `--workers=1` for sequential execution

### Stopping the Test Environment

```bash
# Stop and remove containers
docker compose -f docker/compose.test.yml down

# Stop and remove containers + volumes (clean slate)
docker compose -f docker/compose.test.yml down -v
```

---

## Test Results

Do not treat hardcoded suite counts in this document as authoritative. The repository evolves quickly. Use the commands below as the source of truth and update this document whenever the release-gate interpretation changes.

### Current Automated Test Inventory

### Playwright E2E

```bash
# Canonical gate: discovery check + three sequential pools (requires the
# Docker environment running)
npm run test:e2e:gate

# Targeted single-pool run (local development only — NOT the release gate)
npx playwright test tests/e2e/ --project=chromium
```

Repository status (CS-PLAN-068 refresh):

- The blocking browser gate is `npm run test:e2e:gate` — see "E2E in CI"
  above. The runner manifest (`scripts/run-e2e-gate.js`, `REQUIRED_SPECS`)
  pins every required spec file, its owning pool and its required scenario
  titles; a required test that disappears or a spec selected by two pools
  fails discovery before any browser launches.
- The canonical household journey lives in `core-household-journey.spec.ts`
  (`chromium-serial-db`, `emptyhouse` profile): one test with named
  `test.step` phases covering first owner bootstrap, wizard setup,
  invitations (adult + child), custom/template task creation with
  instructions/checklist/assignees, scheduling preview→override→apply,
  child execution on My Day, completion receipts with exact credited
  deltas, queued undo (live + after reload) and identity/visibility
  authority on re-entry. The retired `gui-scratch-flow` spec is archived
  under `archive/2026-09/wp9-deterministic-coverage/`.
- Test-mode and DB fixtures fail loudly: `seededData` only runs in the
  reset-owning pools (`chromium-serial-db`, `chromium-scheduling-owner`),
  and a missing test mode is an error, not a skip. The no-op
  `fixtures/test-fixtures.ts` shim is gone — read-only specs import
  Playwright directly, and `visibility-fixtures.ts` throws a sanitized
  diagnostic instead of enabling a runtime skip.
- Localization coverage is gated by `npm run l10n:check-keys`, which now
  scans ALL `src/**` and `lib/**` `t()` literals against en.json, de.json
  and the POT template (exclusions per `i18n.md` D2 §5).

### Jest Frontend Tests

```bash
npm test
```

Repository status:

- For the current Jest spec count, run `git ls-files 'tests/unit/**/*.spec.js' | wc -l` (the count grows as coverage expands).
- Coverage includes:
  - Vue components
  - Pinia stores
  - routed views
  - localization consistency checks

### PHPUnit Unit and Integration Tests

```bash
docker compose -f docker/compose.test.yml exec nextcloud-test \
  vendor/bin/phpunit --configuration phpunit.unit.xml

docker compose -f docker/compose.test.yml exec nextcloud-test \
  vendor/bin/phpunit --configuration phpunit.integration.xml
```

> `phpunit.unit.xml` runs unit tests only; `phpunit.integration.xml` runs
> integration tests only (real Nextcloud + DB bootstrap). The former umbrella
> `phpunit.xml` was removed in CS-PLAN-069 — `composer test` runs
> `phpunit.unit.xml` directly (including the mock-based `Db/Entity` +
> `Db/Mapper` suites, no-DB-safe per CS-PLAN-022). The dedicated configs are
> the canonical per-suite commands.
> Run via `exec` against the running container: `docker compose run --rm`
> overrides the entrypoint, so its fresh container has no installed
> Nextcloud and the integration bootstrap fails with `Not installed`.

Repository status:

- For the current integration test count, run `git ls-files 'tests/integration/**/*Test.php' | wc -l` (the count grows as coverage expands).
- Coverage includes controller, API, family, and extended room-task service scenarios.
- Controller integration tests require the Docker/Nextcloud environment.

#### WP2 agreement/pause/capacity test surface (CS-PLAN-073)

The WP2 package added dedicated, canonical proof surfaces — extend these
instead of duplicating their lock choreography elsewhere:

- `tests/unit/Service/DailyCapacityServiceTest.php` — the whole-day
  evaluator directly: exact minor-unit share vectors, occurrence-identity
  dedupe, the frozen Q-073-02 saved-zone time basis, work appointments on
  their actual (cross-zone) day, neutral suspension, helper-only release,
  child limits/overload reasons, the commitment gates and the
  replacement-removal rule (`assertCommitmentsFit(..., $replacedOccurrence)`).
- `tests/unit/Service/{PauseService,PauseProjectionService}Test.php` —
  pause lifecycle gates and the projection math (union provenance, helper
  release, appointment intersection vectors). The 2026-09-25 fix round
  added: per-group revision precedence over the FULL persisted history
  (multi-shortening boundaries, pending/rejected govern nothing, saved-zone
  midnight/DST boundaries, group independence — RF-073-WP2-23), the
  recognition-eligibility gate (late ordinary acknowledgment vs original
  deadline, decision time = approval, retrospective marker, day-level rule
  — RF-073-WP2-25), joint suspension on ANY paused obligated participant
  (RF-073-WP2-24), virtual/materialized participant parity
  (RF-073-WP2-26) and the viewer-projection field whitelist
  (RF-073-WP2-28).
- `tests/integration/Service/PauseAgreementTest.php` — DB-backed pause ↔
  occurrence agreement facts (suspension, catch-up appointments, union
  provenance per `pause_ref`, shortening/illness/role gates). Fix round:
  full-history shortening with inert job replay, pending child shortening
  until owner acknowledgment, expired-miss eligibility (ordinary vs
  retrospective), request-before/approval-after-expiry, and the joint
  suspension → capacity-zero → explicit feasible resumption chain with
  over-budget/after-deadline/no-consent zero-write rejections.
- `tests/integration/Service/PauseAgreementConcurrencyTest.php` — REAL
  two-connection races (second bootstrapped Nextcloud process via
  `proc_open`, file barriers): plan-vs-pause serialization, duplicate
  restarts (exactly one successor), same-revision shortening (one stale
  conflict), replayed receipt UUIDs across connections (single effect) and
  an injected failure after the pause insertion rolling back pause +
  effects + receipt. Sequential calls never count as concurrency evidence.
- `tests/integration/Service/OccurrenceTimelineTest.php` — the WP2 fix-round
  agreement regressions: explicit-target amendments, persisted neutral
  handover releases with consent evidence, appointment-carrying catch-ups,
  receipt identity (semantic inputs + action), stale-token/zero-write
  conflicts, derived suspension at the command boundary and helper capacity.
  The 2026-09-25 fix round added: date-only/catch-up follow-ups after a
  handover validating the CONCRETE arrangement in both directions (released
  default paused → move succeeds; concrete participant paused → conflict,
  RF-073-WP2-27) and the stopped-cron parity proof (write-free virtual GET
  with revision-derived suspension, rejected completion, job materialization
  converging to the same key/state/provenance/appointment,
  RF-073-WP2-26).
- `tests/integration/RoleGuardApiTest.php` — the role/visibility matrix on
  the real HTTP surface; fix round: the pinned `GET /pauses` wire response
  proving the field whitelist (personal reason/illness marker are
  self/owner-only in every state, unrelated adults and children receive
  neutral facts only, RF-073-WP2-28). A mocked-controller test cannot prove
  field filtering.
- `tests/integration/Service/SchedulePlanAggregateTest.php` — the CR-SVC-64
  local-midnight proof via the injected `SchedulePlanService::setClock()`
  seam (preview before midnight, apply after → write-free 409; malformed
  input stays 400) and the joint/replacement placement rules.
- Query-budget pins: `WorkflowQueryBudgetTest` (the completion flow budget
  includes the pause-independent appointment stamping, RF-073-WP2-20).
- Browser layer: `family-rhythm.spec.ts` describe *"Family rhythm —
  agreements & pauses (WP2)"* (API-created pauses rendering neutral/overload
  clarifications, child-visible agreement identity, denied child approvals,
  owner 404 on private tasks) and `scheduling-apply-flow.spec.ts`
  (shares/consent/draft retention, consent-less apply 400). Titles are pinned
  in `tests/e2e/required-scenarios.json`.

#### WP3 work-accounting test surface (CS-PLAN-074, ADR-014)

The WP3 package added the canonical proof surfaces for the ledger, the room
journal and the correction aggregates — extend these instead of duplicating
the lock choreography:

Unit (mock-based, `phpunit.unit.xml`):

- `tests/unit/Service/CompletionServiceTest.php` — the completion/undo/
  attribution aggregate seams (frozen facts, exact union deltas incl. the
  departed-wallet union, deviation-review gate, wallet lock order, recognition
  recompute, post-commit isolation).
- `tests/unit/Service/RoomReplayServiceTest.php` — the journal fold: the
  documented clamp example, undo invalidation (the correction time never
  becomes `last_cleaned`), reset authority over earlier corrections,
  `(effective_at, id)` ordering, batched cursor paging, command-UUID
  idempotency of the manual/reset writers.
- `tests/unit/Service/{WalletService,RhythmService,WeeklyGoalService,
  EffortAllocationService,CommandService}Test.php` — exact minor-unit math,
  safe-integer/negative gates, cached-balance + funding-hold maintenance,
  streak derivation (neutral pending days, corrected-record shrinking), the
  due∪completed weekly evaluation, command replay/conflict identity.
- `tests/unit/Service/ActivityServiceTest.php` — the viewer-scoped
  `canCorrect` authority pins (completer/owner vs participation-only vs
  undone).
- `tests/unit/Controller/CompletionControllerTest.php` — the closed-set
  correction payloads (undo/attribution) and the shared validation helper.
- `tests/unit/utils/minorUnits.spec.js` — exact minor-unit formatting incl.
  negatives, sub-unit trimming and the signed safe-integer boundaries.

Integration (real Nextcloud + DB, `phpunit.integration.xml`):

- `tests/integration/Service/CompletionCorrectionTest.php` — the correction
  contract end to end: attribution rewrites the selected allocation with
  exact union deltas, the frozen work facts and the room journal stay
  byte-identical, undo reverses the LATEST selected revision once, the
  deviation requires the explicit review confirmation, participants without
  authority are rejected, a departed audit actor renders the placeholder
  (no 500), and the departed participant's retained wallet is reversed
  exactly (no payout beyond the frozen total).
- `tests/integration/Service/RoomReplayConcurrencyTest.php` — replay through
  the real services: completion + undo folds, manual deltas journaling the
  REQUESTED value with clamps, command-UUID replay no-ops (delta never
  repeats, reset never re-resets over later work), reset authority after an
  undone completion.
- `tests/integration/Service/RhythmProjectionTest.php` — streaks and the
  weekly goal re-derive from the valid history inside the aggregates
  (multi-day runs, corrected-record shrinking C3g, neutral pending days,
  50→100→undo percent re-evaluation, empty-week zero).
- `tests/integration/Service/HouseMemberRemovalIntegrationTest.php` — the
  removal cascade plus the WP3 §3.6 regression: departure preserves the
  wallet, and a later owner attribution reverses the departed wallet exactly.
- Browser layer: `insights-flow.spec.ts` *"owner corrects a recorded work
  split from the activity history (WP3 Â§3.6)"* — the feed correction action,
  the reused participation form pre-fill, the receipt-protected PUT and the
  versioned read model (pinned in `tests/e2e/required-scenarios.json`).

#### WP7 reward catalog & request test surface (CS-PLAN-070 WP7 / CS-PLAN-078)

Unit (mock-based, `phpunit.unit.xml`):

- `tests/unit/Service/{RewardService,RewardRequestService}Test.php` — the
  catalog scopes/validation, the versioned gate command, the C4 actor/state
  matrix, immutable frozen terms, one-shot money effects, role rebase,
  departure/erasure hooks and the digest recipient matrix.
- `tests/unit/Service/WalletServiceTest.php` — the WP7 reserve/release/
  settle/refund primitives incl. the C3.10 hold arithmetic.
- `tests/unit/Controller/{RewardController,RewardRequestController}Test.php`
  — strict payload validation and the 400/403/404/409 HTTP mapping.
- `tests/unit/Service/HouseServiceTest.php` — role-revision provenance
  (real change vs no-op write) and the departure-hook ordering.
- `tests/unit/Service/DigestServiceTest.php` — `reward_request` relevance
  (current revision, superseded rows, demoted owners, purged requests).
- `tests/unit/Service/AccountErasureServiceTest.php` — the WP7 purge order
  (reward data before the wallet dies, both erasure paths).
- `tests/unit/RouteMatrixTest.php` — the 94-route pin + the WP7 surface
  contract (no item-delete, no wallet/ledger dump routes).

Integration (real Nextcloud + DB, `phpunit.integration.xml`):

- `tests/integration/Service/RewardLifecycleTest.php` — the C4.1-9
  lifecycle against the real DB: exact wallet/ledger reconciliation, audit
  chain, role rebase, departure/rejoin, gate/archive/drift rules,
  idempotent replay.
- `tests/integration/Service/RewardConcurrencyTest.php` — C4.11-14
  two-connection races: fulfill vs cancel exactly-once, approval vs role
  rebase, mid-aggregate rollback, idempotent refund retries.
- `tests/integration/Controller/RewardApiTest.php` — the HTTP contract
  through real controllers (scopes, DTO whitelists, gate endpoint,
  placeholder rendering).
- `tests/integration/Service/CommandServiceTest.php` — the WP7 guard seam
  on execution and replay.
- `tests/integration/Db/FamilyRhythmSchemaTest.php` — the Version1033
  tables/indexes/defaults + `role_revision`.
- Browser layer: `family-reward-api.spec.ts` (serial pool — gate flip,
  catalog scopes, the full request lifecycle through the real HTTP seam)
  and the `title-selection.spec.ts` read-availability pin; titles are
  pinned in `tests/e2e/required-scenarios.json`.

## Without Docker (Limited)

> **Not applicable on the main development machine** — Docker Desktop is installed there and is the canonical test path (see project-rules §2 "Test Environment (Docker)"). This fallback exists **only for machines without any Docker installation**. AI agents must not treat a failed `docker` probe as a reason to use this section; diagnose the daemon first.

Running tests without Docker is possible but **limited**:

```bash
# Install PHP 8.2+ locally (winget on Windows, apt on Linux, brew on macOS)
winget install PHP.PHP.8.3  # Windows example

# Install Composer (see https://getcomposer.org/download/)
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php

# Install dependencies
php composer.phar install

# Run tests (many will fail due to missing OCP classes)
vendor/bin/phpunit
```

**Note**: Tests requiring Nextcloud core classes (OCP\*) will fail. Use Docker for full test coverage.

## Continuous Integration

The Docker test environment is also used in CI/CD pipelines. See the
**Continuous Integration** section above for the authoritative
`.forgejo/workflows/release-gate.yml` workflow and blocking gate commands.

## Security Regression Test Pack (Recommended)

After security-related backend changes, run these tests first:

```bash
# House-scoped scheduling authorization checks
php vendor/bin/phpunit tests/unit/Service/SchedulingServiceTest.php

# House-scoped task sync authorization checks
php vendor/bin/phpunit tests/unit/Service/TaskSyncServiceTest.php
```

Then run the core domain confidence suite:

```bash
php vendor/bin/phpunit tests/unit/Db/TaskTest.php
php vendor/bin/phpunit tests/unit/Db/RoomTaskTest.php
php vendor/bin/phpunit tests/unit/Service/GameLogicServiceTest.php
php vendor/bin/phpunit tests/unit/Service/HouseServiceTest.php
php vendor/bin/phpunit tests/unit/Service/RoomServiceTest.php
php vendor/bin/phpunit tests/unit/Service/FloorServiceTest.php
```

## Docker Cleanup and Manual Validation

Yes, you can test manually in Docker. If old test containers are still running, stop and clean them before a fresh run.

### 1) Check running containers

```bash
docker ps -a
```

### 2) Stop stale test containers

```bash
docker stop <container_id_1> <container_id_2>
```

### 3) Optional: remove stopped containers

```bash
docker rm <container_id_1> <container_id_2>
```

### 4) Optional: remove outdated test image

```bash
docker images
docker rmi <image_id>
```

### 5) Run manual test session in container

```bash
docker exec -it chorestrike-test bash

# inside container
vendor/bin/phpunit tests/unit/Service/SchedulingServiceTest.php
vendor/bin/phpunit tests/unit/Service/TaskSyncServiceTest.php
```

## Test Database Profiles

ChoreStrike supports three test database profiles for different testing scenarios:

### Available Profiles

| Profile | Users | Floors | Rooms | Tasks | Use Case |
|---------|-------|--------|-------|-------|----------|
| `empty` | 0 | 0 | 0 | 0 | Clean slate, scratch flows |
| `minimal` | 2 (adult, kid) | 1 | 1 | 0 | Basic smoke tests |
| `family` | 6 (mom, dad, teen, kid, grandma, friend) | 3 | 6 | 12 | Multi-user interactions |

### Using Profiles

#### Docker Compose

```bash
# Run with minimal profile (default)
docker compose -f docker/compose.test.yml up

# Run with family profile
TEST_DB_PROFILE=family docker compose -f docker/compose.test.yml up

# Run with empty profile
TEST_DB_PROFILE=empty docker compose -f docker/compose.test.yml up
```

```powershell
# PowerShell 5.1 equivalent (the dev machine's shell) — VAR=value prefix syntax does not work there:
$env:TEST_DB_PROFILE='family'; docker compose -f docker/compose.test.yml up
```

#### OCC Command (canonical seed path)

```bash
# Seed database with specific profile
docker exec chorestrike-test php occ chorestrike:test-data:seed --profile=family

# Reset database
docker exec chorestrike-test php occ chorestrike:test-data:seed --reset
```

#### API Endpoint

> **CS-PLAN-019 (security hardening):** the HTTP test endpoints require an
> authenticated Nextcloud session (`@NoAdminRequired`, no `@PublicPage`) and
> are gated by `isTestMode()`. Test mode is active **only** when the explicit
> `NEXTCLOUD_TEST_MODE=true` environment variable is set on the PHP process —
> a generic `CI` env var no longer activates it. The canonical seed path is
> the `occ` command above; the HTTP endpoints are a secondary convenience for
> authenticated test tooling.

```bash
# Seed via HTTP (requires authenticated session + NEXTCLOUD_TEST_MODE=true)
curl -X POST "http://localhost:8080/apps/chorestrike/api/v1/test/seed/family"

# Reset database
curl -X POST "http://localhost:8080/apps/chorestrike/api/v1/test/reset"

# Get available profiles
curl "http://localhost:8080/apps/chorestrike/api/v1/test/profiles"
```

### Playwright Integration

Use the database and auth fixtures in your E2E tests:

```typescript
import { test, expect } from './fixtures/database-fixtures'
import { createUserContext, userStatePath } from './fixtures/auth-contexts'

// Single-user describe: all tests run as mom
test.describe('Mom tests', () => {
  test.use({ databaseProfile: 'family' })
  test.use({ storageState: userStatePath('mom') })

  test('mom sees tasks', async ({ page }) => {
    await page.goto('/apps/chorestrike/')
    // ... test code
  })
})

// Multi-user flow: create owned contexts
test('two users see same house', async ({ browser }) => {
  const mom = await createUserContext(browser, 'mom')
  const teen = await createUserContext(browser, 'teen')
  try {
    // ... test code with mom.page and teen.page
  } finally {
    await mom.context.close()
    await teen.context.close()
  }
})
```

### Family Profile Details

The `family` profile creates a realistic family scenario:

**Users:**
- `mom` (owner) - Full availability, kitchen/laundry preferences
- `dad` (owner) - Full availability, garage/yard preferences
- `teen` (child) - Weekend only, bedroom/bathroom preferences
- `kid` (child) - Weekend only, toys/pets preferences, max 2 daily tasks
- `grandma` (member) - Limited availability, kitchen/living room
- `friend` (member) - Saturday only, max 1 daily task

**Rooms:**
- Ground Floor: Kitchen (75% clean), Living Room (60%), Master Bathroom (70%)
- First Floor: Master Bedroom (80%), Kids Bedroom (40%), Kids Bathroom (50%)
- Second Floor: (empty, for expansion)

**Tasks (12 total):**
- Daily: Dishes, counters, trash, toys, beds
- Weekly: Vacuum, dust, laundry, toilets, sinks

**Time-travel seeds (CS-PLAN-023):**
- `mom` carries the streak state: `currentStreak=5`, `streakFreezes=0` (no
  completions exist in the seed) — the at-risk/freeze flow target.
- "Take out trash" (assigned to `dad`) is seeded with a due-date three days
  in the past — immediately overdue against the real clock.

### CI/CD Integration

The Forgejo/Codeberg workflow runs tests with all profiles:

```yaml
strategy:
  matrix:
    profile: [empty, minimal, family]
    browser: [chromium, firefox]
```

---

## Mapper-Level Real-DB Regression Tests

Mock-based mapper/service tests can only pin the *query shape* — they stub
`IDBConnection`/mappers and by construction cannot catch real SQL errors
(wrongly quoted aggregate expressions → SQLSTATE 1054/42S22, broken joins,
day-boundary drift across SQLite/MariaDB/PostgreSQL). Whenever a mapper query
is fixed or its semantics are behavior-critical, add a **real-DB regression
test** in `tests/integration/Db/` extending `IntegrationTestCase` (Docker,
family profile):

- Seed the full FK chain via the base-class helpers (`createHouse` →
  `createFloor` → `createRoom` → `createTask` → `assignTaskToRoom` →
  `addHouseMember`); insert auxiliary rows (e.g. completions) with an explicit
  tracked-ID helper and clean them up in `tearDown` (the base cleanup does not
  know them). Precedents: `BatchQueryRegressionTest`, `WriteBatchRegressionTest`,
  `RoomTaskMapperDailyTest`, `CompletedTaskMapperEnergyTest`.
- Pin the semantics that mocks cannot see: empty-result defaults, sums/aggregates,
  half-open day boundaries (`[00:00, +1d)`), cross-user/cross-house isolation.
- Extend this suite for new mapper fixes; do not re-cover a method that already
  has a real-DB regression test.

## Workflow Query Budgets & Executed-Query Counting (CS-PLAN-067 §4.1)

Performance budgets are enforced as **literal, frozen statement counts** in
`tests/integration/Db/WorkflowQueryBudgetTest.php`, measured with
`tests/integration/Support/QueryCounter.php` against the real MySQL/MariaDB
profile of the canonical Docker stack.

**Counting seam (slice-1 verification):** the bundled DBAL 3.10.4 marks
`Configuration::setSQLLogger()` deprecated in favor of connection-time
middleware — but middleware cannot be retrofitted onto the already-constructed
Nextcloud connection, while the logger IS still consulted at call time by
`Connection::executeQuery()/executeStatement()` and `Statement::execute()`
(DBAL 3.10.4 source lines 1089/1197/1384/1496/1546/1625, Statement:183).
`QueryCounter::attach()` installs that logger (no deprecation is emitted —
doctrine/deprecations is inert unless explicitly enabled) and `detach()` in a
`finally` MUST follow every install so a later test is never observed.
`doctrine/dbal` internals were not patched and no debug endpoint was added.

**Observed-statement accounting:** the counter logs transaction control too
(`"START TRANSACTION"` / `"COMMIT"`), so budgets include them. Frozen budgets
(reasoning documented per test):

| Workflow | Budget | Composition |
|---|---|---|
| House-wide instance read | 3 | house-scoped fetch + batched task definitions + batched assignees; CONSTANT in row count (Q(100) = Q(10)) |
| Empty house read | 1 | mapper fetch only — no enrichment |
| `updateDueDate` | 19 | 3 gate reads + owning-house resolution (room → floor → house, CS-PLAN-071) + BEGIN + house/room-task FOR UPDATE + the CS-PLAN-072 WP1 agreement-writer tail (timeline read, occurrence amendment, `agreements` evidence INSERT, derived-projection UPDATE) + COMMIT + exactly ONE authoritative after-commit re-read |
| `complete()` | 45 | receipt resolution (timeline reads + selection) + command receipt read/INSERT + occurrence gates + ADR-011/071 house-first locks (house → room_task → room → house_user) + reward-phase `findForUpdate` + checklist + reward window + single ledger INSERT with frozen occurrence evidence + timeline effects (fulfill, catch-up cover, successor anchor, projection) + post-commit weekly-goal cluster + response selection block |
| `undo()` | 36 | same house-first gate/lock structure + effect-record restore (ledger read) + the CS-PLAN-072 WP1 timeline release (occurrence reopen, successor deletion, projection re-derivation) + transaction control + post-commit weekly-goal read |

> **CS-PLAN-072 WP1 note:** the occurrence timeline (ADR-013) moved recurrence
> authority off the mutable `next_due` column, so `updateDueDate` now writes
> through the agreement writer and `complete()`/`undo()` resolve/release durable
> occurrences. The budgets grew accordingly and were re-measured on the canonical
> Docker stack; `updateDueDate`'s former "nothing runs after COMMIT" proof became
> "exactly one authoritative re-read after COMMIT" (the response entity).

**Rules:** every budget test also asserts exact result IDs or state (a lower
count must never be bought with wrong data); budgets are re-measured on the
canonical Docker stack before any change; a regression fails CI, an
improvement should tighten the number.

Frontend counterpart: `tests/unit/views/requestCounts.spec.js` (real Pinia
stores, mocked API boundary — exactly one house-wide list request in the
TaskManager init set) and `tests/e2e/workflow-request-budgets.spec.ts`
(real HTTP boundary — the TaskManager/Scheduling load issues exactly ONE
`GET /api/v1/room-tasks` without a `roomId` param).

---

## Time-Travel Testing (CS-PLAN-023)

Time-dependent flows — "task overdue after N days", streak-freeze
consumption, and the streak-at-risk notification — are testable in Docker
**without waiting real time**. The three BackgroundJobs
(`DailyNotificationJob`, `StreakFreezeJob`, `StreakRestockJob`) are thin
delegates: they derive "yesterday" from the injected `ITimeFactory` and
hand the pass to services (`StreakAtRiskService`, `StreakFreezeService`,
`StreakRestockService`). Time is injectable at the logic level, and the
`occ` trigger below calls the same services directly.

### `occ chorestrike:trigger-jobs` (test mode only)

```bash
# Run one job type now (yesterday = the real yesterday)
docker exec chorestrike-test su -s /bin/bash www-data -c \
  "cd /var/www/html && php occ chorestrike:trigger-jobs streak-freeze"

# Simulate "a day passed": --as-of sets the reference day, and
# "yesterday" is derived as as-of minus one day (as-of=tomorrow ⇒
# yesterday=today). Zero clock trickery.
docker exec chorestrike-test su -s /bin/bash www-data -c \
  "cd /var/www/html && php occ chorestrike:trigger-jobs streak-freeze --as-of=$(date -d '+1 day' +%Y-%m-%d)"

# All three passes. Order is deterministic: daily-notification runs
# BEFORE streak-freeze so the at-risk reminder fires while the streak is
# still positive (resolves the 06:00 ordering race noted in info.xml).
docker exec chorestrike-test su -s /bin/bash www-data -c \
  "cd /var/www/html && php occ chorestrike:trigger-jobs all"
```

- `<type>` ∈ `daily-notification | streak-freeze | streak-restock | all`.
- Guarded by `TestDataService::isTestMode()` (`NEXTCLOUD_TEST_MODE=true`),
  mirroring `TestDataSeed` — it refuses to run in production.
- `[--user=UID]` is accepted but not yet scoped in the services; the
  command logs a notice and runs the full pass.
- `streak-restock` is **time-independent** (it sets freezes to the
  configured weekly value) — triggering it once is sufficient and it
  ignores `--as-of`.

### Seeded time-travel state (`family` profile)

- **Overdue:** one room task ("Take out trash", assigned to `dad`) is
  seeded with a due-date three days in the past, so the overdue flow is
  live against the real clock — no shift needed.
- **Streak/at-risk:** `mom` is seeded with a 5-day streak and zero
  freezes (and no completions exist in the seed), so
  `daily-notification` + `streak-freeze` with `--as-of=tomorrow`
  exercise the reminder + reset within seconds.

E2E coverage: `tests/e2e/time-travel-overdue.spec.ts` and
`tests/e2e/time-travel-streak-notification.spec.ts` (both in the
`chromium-serial-db` project). The specs trigger the jobs via
`tests/e2e/fixtures/occ-runner.ts` (`docker exec`, container
auto-detected; override with `NEXTCLOUD_CONTAINER`).

### Optional: `libfaketime` (not wired, demoted)

A whole-container clock shift (`LD_PRELOAD` libfaketime) is only needed
for global multi-day transitions that cannot be encoded as `--as-of` or
seeded past dates. It is **off by default and not wired** into
`docker/Dockerfile.test`. Rule if it is ever introduced: never combine
`libfaketime` with time-relative seeded dates in one assertion —
"yesterday" and seeded due-dates drift inconsistently under a shift.
Prefer seed + `--as-of` (no clock shift) as the default.

---

## Coverage Measurement (CS-PLAN-022 Phase 1)

Coverage is generated with built-in tooling only (no nyc/c8/istanbul). CI emits
artifacts non-blocking; thresholds are added in Phase 6 once the baseline is stable.

### PHPUnit coverage (PCOV)

The Docker test image (`docker/Dockerfile.test`) includes the PCOV extension.
Generate reports locally:

```bash
docker exec chorestrike-test bash -c "composer test:coverage"
```

Artifacts: `build/coverage/phpunit-clover.xml` + `build/coverage/phpunit-html/`.

### Jest coverage

```bash
npm run test:coverage
```

Artifacts: `build/coverage/jest/` (HTML + lcov).

### Baseline (2026-07-30, CS-PLAN-022 Phase 1+4)

| Metric | PHPUnit (unit gate) | Jest |
|--------|-------------------|------|
| Statements | 54.3% (5310/9787) | 90.3% |
| Branches | — | 80.4% |
| Methods | 47.2% (395/837) | 68.3% |
| Lines | 54.3% | 90.3% |
| Tests | 760 | 667 |

PHPUnit coverage uses `processUncoveredFiles="false"` (only files loaded during
tests are measured; loading all `lib/` files fails on unmocked OCP dependencies).

---

## Program Baseline (CS-PLAN-059 WP0, 2026-09)

Point-in-time inventory recorded as the CS-PLAN-058 program baseline. It is
date-stamped, not self-updating: later work packages refresh their rows as
part of their own documentation integration.

### PHPUnit skip inventory (WP9 refresh, 2026-09-13)

The unit suite has no skip call sites. The integration suite's remaining
**18 `markTestSkipped` call sites** are ALL conditional engine guards in the
two-connection concurrency suites — they run green inside the Docker gate
(MySQL/MariaDB) and only skip on engines without the concurrency primitives:

| File | Skips | Reason class |
|---|---|---|
| `tests/integration/Db/BootstrapConcurrencyTest.php` | 4 | Conditional MySQL two-connection guard. |
| `tests/integration/Service/ConcurrentCompletionTest.php` | 3 | Same conditional environment guard. |
| `tests/integration/Db/ChecklistToggleConcurrencyTest.php` | 1 | Same. |
| `tests/integration/Service/CompletionConcurrencyTest.php` | 2 | Same. |
| `tests/integration/Service/MinigameConcurrencyTest.php` | 2 | Same. |
| `tests/integration/Service/SchedulePlanConcurrencyTest.php` | 2 | Same. |
| `tests/integration/Service/TaskCreationConcurrencyTest.php` | 2 | Same. |
| `tests/integration/Setup/SetupApplyConcurrencyTest.php` | 2 | Same. |

Closed by CS-PLAN-068 (WP9): the eight unconditional
`FamilyIntegrationTest` skips (rewritten as real-DB `CompletionService`
scenarios), the two `GameControllerIntegrationTest` skips (`checkMedals`
against real persisted awards; the obsolete `handleTaskCompletion` case
deleted — the route prune is pinned by
`RouteMatrixTest::testPrunedCS055RoutesAreNotReintroduced`). New WP9 suites:
`MinigameControllerIntegrationTest` (all nine minigame routes),
`TestModeApiTest` (all disabled routes/enabled dispatch), `TestModeHttpTest`
(anonymous HTTP denial and authenticated disabled-server denial with a
test-owned web-server environment, no process-only substitute), `TestDataSeedTest`
(four seed profiles, idempotent reseeding).

The former CR-053-22 deferrals (Minigame controller integration suite,
`TestDataSeedTest`, and Jest specs for 8 uncovered components — ActivityFeed,
HouseStatsCards, MemberFairnessTable, WeekBudgetGrid, RoomPicker, BoostShop,
SnakeGame, SnakeLeaderboard) are **delivered**; the release gate requires
zero newly skipped core-workflow tests (CR-WF-05).

### Playwright project split

`playwright.config.ts` defines six projects:

| Project | testMatch | testIgnore | Notes |
|---|---|---|---|
| `setup` | `*.setup.ts` | — | Auth bootstrap: logs in every `family` role once and saves per-user storageState. |
| `chromium` | all specs | seed-dependent + scheduling-owner spec lists | Default parallel pool, admin storageState; part of the blocking release gate. |
| `firefox` / `webkit` | all specs | seed-dependent + scheduling-owner + self-availability spec lists | Full browser matrix (advisory; weekly full-matrix run). |
| `chromium-serial-db` | seed-dependent spec list (incl. `core-household-journey.spec.ts`) | — | `fullyParallel: false`, `workers: 1`; reset-owning pool; blocking gate. |
| `chromium-scheduling-owner` | scheduling-owner spec list | — | `workers: 1`, runs as `dad` (owner storageState); reset-owning pool (`scheduling-apply-flow` seeds); blocking gate. |

Reset-owning pools are exactly `chromium-serial-db` and
`chromium-scheduling-owner`, and they run SEQUENTIALLY behind the gate. The
`DatabaseManager` enforces this in both `seed()` and `reset()`, including
direct fixture calls: a mutation attempt from any other project fails
before a request. Setup provisioning is mandatory; missing test mode, seed
errors and login failures fail setup. Runtime guard regressions live in
`tests/unit/scripts/databaseFixtures.spec.js`.

The runner's file/project registry and the reviewed
`tests/e2e/required-scenarios.json` pin the exact scenario selection.
`node scripts/run-e2e-gate.js --write-manifest` is an explicit authoring
operation; inspect its diff when changing intended coverage. The normal
gate never learns a new baseline. Discovery errors, missing owner reports,
empty attempts, expected failures, skipped/interrupted/flaky results and
retries all fail. `browserGate.spec.js` exercises the actual main pipeline
as well as pure validators; `--self-test` remains a lightweight CLI smoke.

Every run gets a fresh `build/e2e-gate/run-*/` directory, so stale reports
cannot satisfy a new subprocess failure. Raw JSON and retained-on-failure
traces are local debugging material that may contain browser/network data;
never publish or commit them. CI uploads only sanitized `summary-*.json`
containing file/title/status/attempt metadata. Failure traces do not depend
on a retry (the gate always has `--retries=0`).

### Core-journey E2E specs (WP9 delivered the deterministic journey)

`gui-scratch-flow.spec.ts` was retired by CS-PLAN-068 §3.3 (archived under
`archive/2026-09/wp9-deterministic-coverage/`). The canonical household
journey is `core-household-journey.spec.ts` (`chromium-serial-db`,
`emptyhouse` profile, ONE test with named `test.step` phases) — see the
Playwright inventory above for its phase list. Focused specs at the WP9
boundary: setup wizard (`release-setup-wizard`, `setup-wizard-journey`),
task creation/assignment (`task-manager`, `room-detail`), scheduling
(`scheduling`, `flex-window-scheduling`, `availability-settings-flow`,
`scheduling-apply-flow` in `chromium-scheduling-owner`), My Day
(`today-view`), completion/undo (`task-completion`,
`task-completion-full-flow` with the strengthened exact-receipt
assertions), lifecycle (`release-task-lifecycle`), members/roles
(`members-owner`, `release-visibility-roles`), insights (`insights-flow`
— controlled per-day capacity), checklist (`task-checklist-and-features`).
`ScheduleCalendar.spec.js` fixes Saturday/Sunday/Monday clocks and uses the
same `house-clock.js` fixture date helper and actual planned-energy store.
`SchedulePlanAggregateTest::clockBoundaries` covers midnight, positive and
negative extreme offsets and both Berlin DST transitions; a stale plan's
start key is computed once and shared by preview/apply.

The canonical journey selects exact wizard room types/level, compares
preview/persisted counts, pins template materialization, proves preview
does not write due dates/assignees, and checks Calendar/My Day re-entry.
It chooses a curated timezone through the real Settings UI so the long
journey is several hours from local midnight. Fresh wizard rooms exercise
the cleanliness cap; the focused completion flow separately proves the
unsaturated `75 -> 95` delta. Both retain exact wallet/contribution and
recorded-inverse evidence; checklist progress is checked after undo/reload.
`family-core-ux.spec.ts` additionally changes the real Nextcloud user
language and verifies DE/EN rendering while restoring the test preference.

## Remaining Coverage Gaps

The biggest remaining gaps are no longer basic component or store tests. Those areas now have meaningful automated coverage. The highest-value remaining work is here:

### 1. Pipeline and Coverage Ownership

- The blocking release workflow builds app assets in the E2E job and runs
  all three Chromium pools sequentially. Firefox/WebKit remain the
  separately owned advisory matrix, not an exception to this gate.
- The old Snake/BoostShop/leaderboard and test-tooling deferrals are covered
  by the component/HTTP/runner suites above. Missing new required evidence
  is a plan blocker, never a reason to restore a fixture-dependent skip.
- Pruned reward-config and task-sync routes are not missing live UI coverage.
  Reintroducing them requires its own product/API scope and tests.
- The assertion-free combo smoke in `task-completion.spec.ts` was replaced
  by the exact false/false/true combo receipt sequence in
  `task-completion-full-flow.spec.ts`; no live combo behavior was dropped.

### 2. Remaining Product Work

Open product/security/release work lives in `docs/backlog/backlog.md`;
in-scope review findings stay with their owning plan. Historical test
counts and deferred-route lists are not evidence of current gaps.

### 3. Cross-Layer Contract Changes

- Task and room flows are sensitive to payload-shape drift between:
  - backend room-task payloads
  - Pinia stores
  - `TaskList.vue`
  - `RoomDetail.vue`
  - `TaskManager.vue`
  - Playwright selectors
- Whenever these contracts change, add or refresh both unit regressions and E2E smoke coverage in the same change.

### 4. Release Validation Order

Before manual server testing or production rollout, prefer this order:

```bash
# 1. Frontend unit coverage
npm test

# 2. Backend unit and integration coverage in Docker
docker compose -f docker/compose.test.yml exec nextcloud-test vendor/bin/phpunit --configuration phpunit.unit.xml
docker compose -f docker/compose.test.yml exec nextcloud-test vendor/bin/phpunit --configuration phpunit.integration.xml

# 3. Canonical browser gate (all three pools sequentially)
npm run test:e2e:gate
```
