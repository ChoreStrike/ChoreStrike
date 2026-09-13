# CS-PLAN-068: WP9 - Deterministic Coverage and Release-Quality Closure

> **Status:** Active (Standard A review `PASS-WITH-FIXES` 2026-09-13; implementation not started)
> **Effort:** XL (one sequential package with independently verifiable slices)
> **Priority:** P1
> **Depends on:** [CS-PLAN-058](../active/cs-plan-058-core-workflow-bottom-up-masterplan.md) WP0-WP8 complete; [CS-PLAN-067](../done/cs-plan-067-wp8-batching-decomposition-performance.md) final Standard B `PASS-WITH-FIXES`
> **Related backlog items:** CR-WF-05 (umbrella); CR-053-13; CR-053-18-WP9; CR-053-21 (documentation slice); CR-053-22; BUG-E2E-005/006; RELEASE-002 (program prerequisite only)
> **Parent:** CS-PLAN-058 WP9. No deployment, release tagging, or productive-use declaration is authorized by this plan.

## 1. Problem Summary

WP1-WP8 delivered the core household contracts. WP9 must prove that they work
together from a clean installation, close the remaining coverage gaps, and make
the release evidence trustworthy. A collection of green tests is insufficient
when a required scenario is skipped, its assertions accept any outcome, or a
different project can reset its database during execution.

The planning baseline is `4b99d9f` (2026-09-13, post-WP8). Historical backlog
descriptions must be reconciled with the current tree before changing tests:

| Existing delivery | WP9 responsibility |
|-------------------|--------------------|
| WP2 service/controller authorization and ADR-010 | `TaskAuthorizationTest` and the other live authorization suites have no skips. Credit that delivery; the remaining ten unconditional integration skips are eight family scenarios and two GameController cases, not missing authorization tests. |
| WP3 `emptyhouse` profile and `setup-wizard-journey.spec.ts` | `TestDataService::seedEmptyhouse` resets all app rows/memberships while Nextcloud accounts survive. The first app request bootstraps the owner. Reuse this exact profile, not a new pre-owned or unbootstrapped profile. |
| WP5 effect ledger, complete/undo equality and concurrency tests | Preserve exact recorded inverses and add missing browser-level payload/state evidence, not another undo implementation. |
| WP6/WP7 scheduling, role-aware My Day, cache reconciliation and accessibility | Compose the full journey over these contracts; keep focused failure/role/viewport tests. |
| WP7 core localization guard; WP8 coverage follows extracted components | `coreWorkflowLocalization.spec.js` protects named core surfaces. The CLI still checks only EN/DE parity: keys such as `Request failed`, `Boost Shop` and `This Week's Medals` are missing from both locales. Extend source coverage globally without redoing the WP7 slice. |
| WP8 request/query budgets and final review exceptions | Keep the frozen ceilings. BUG-E2E-005/006 are WP9 work, not permissible standing exceptions to its final gate. |
| Browser orchestration | `playwright.config.ts` separates three Chromium pools, but a local all-project run can still overlap resets. `.forgejo/workflows/release-gate.yml` omits `chromium-scheduling-owner`. |
| Legacy E2E fixture | `tests/e2e/fixtures/test-fixtures.ts` uses removed reset/seed routes and can return false without isolating anything. Its current consumers must switch to explicit fixtures or plain Playwright imports. |
| Documentation | Root README still advertises removed features and a release candidate; `appinfo/info.xml` declares `0.1.1`. ADR-003, fictional component examples, the migration inventory and room-task API examples retain verified drift. |

The parent still has unchecked program acceptance criteria. Closure requires a
criterion-by-criterion evidence map across the completed child plans and the
final tree; do not check them all merely because this child plan passes.

## 2. Strategy

Establish a deterministic test baseline, fix isolation before strengthening
assertions, replace the scratch journey, close the enumerated test/localization
remainders, then integrate durable documentation and perform the final reviews.
Use the existing Nextcloud test environment and test frameworks. Do not create a
second test-data service, generic workflow engine, optimizer, or cache layer.

### 2.1 Ownership and boundaries

| Work | Owner and closure boundary |
|------|----------------------------|
| Core skip/assertion inventory, profile isolation, canonical household journey and result enforcement | CR-WF-05 / this plan. Existing empty-house work remains credited to WP3. |
| Date-independent insights and scheduling-timezone regressions | BUG-E2E-005/006 / this plan; correct BUG-E2E-006's `CS-PLAN-065 WP9` metadata on activation, retaining provenance. |
| Global source/artifact coverage, placeholder parity and dead-key regeneration | CR-053-13 / this plan; WP7's completed core slice is not reimplemented. |
| Test-route release disposition and its negative tests/docs | CR-053-18-WP9 / this plan, subject to D5. |
| Deferred test suites still missing in the live tree | CR-053-22 / this plan, subject to D3. Existing component coverage is credited, not duplicated. |
| Testing truth, current core API/architecture/development statements, root README rewrite and feature-status corrections | CR-053-21 / bounded slice proposed in D6; archive the whole item only if its whole live remainder is verified complete. |
| Masterplan acceptance evidence, backlog hygiene and program prerequisite handoff | CR-WF-05 / CS-PLAN-058 WP9; RELEASE-002 itself remains open. |

Reward/minigame/notification product rules, structural debt remaining after WP8,
manual-cleanliness races, self-service leaving, dependency upgrades, and add-on
features are not adopted. Tests for already shipped behavior are not a product
redesign. If a necessary regression reveals a behavior change outside this
boundary, record it in the owning plan and obtain a scope decision before fixing
it or declaring the test no longer required.

Keep [ADR-009](../../adr/ADR-009-per-house-timezone.md),
[ADR-010](../../adr/ADR-010-invite-only-membership-visibility-matrix.md),
[ADR-011](../../adr/ADR-011-transactional-completion-and-undo.md), and
[ADR-012](../../adr/ADR-012-actionable-household-scheduling.md) authoritative.
Preserve invite-only membership, visibility 404 versus role 403, one transaction
per aggregate, existing lock orders, title inverses, and WP7 response/store/event
ordering. No schema upgrades, compatibility shims, or production test-clock API.

### 2.2 Standard A decisions (ratified)

D1-D7 were **ratified as recommended** by the user through one structured
batch during the Standard A review on 2026-09-13 (outcome
`PASS-WITH-FIXES`, see `## Review Outcome`). The affected backlog portions
and manifest were updated on activation. Creating the draft did not authorize
that review or its commit; the review request did.

| ID | Recommendation | Alternative / decision boundary |
|----|----------------|---------------------------------|
| D1 | Keep the three Chromium pools, run them sequentially through one local/CI gate, include scheduling-owner in the blocking release workflow, remove the legacy no-op fixture, and make every selected scenario fail rather than skip on missing fixtures. | Separate databases only for a demonstrated CI need. Worker serialization inside one project alone is not cross-project isolation; narrowing required scenarios needs explicit rescoping. |
| D2 | Replace `gui-scratch-flow.spec.ts`, as already ratified by parent D5, with one UI-driven `emptyhouse` journey in `chromium-serial-db`; restore family data after closing its contexts. Account provisioning/authentication and read-only assertions may use fixtures. | A dedicated journey project adds a fourth invocation and is needed only if explicit fixture teardown cannot isolate the existing serial pool. No app-data mutation API or direct store patch may advance the canonical journey. |
| D3 | Close the complete still-live CR-053-22 test remainder, including existing minigame behavior, without changing product rules. | Explicitly rescope named non-core tests to an independent effort and update the parent/backlog before activation; silently inheriting the old deferral is not sufficient. |
| D4 | Extend `check-l10n-keys.js` in place with shared frontend/PHP/catalog extraction, finite dynamic-key ownership and opt-in regeneration; keep current manual singular/plural keys. Missing keys fail immediately; dead keys become a hard gate after inventory/regeneration in this package. | No new gettext migration or parser framework. Unknown dynamic usage must be classified before pruning; a blanket `titles.*` deletion or parity-only checker is not acceptable. |
| D5 | Retain the documented runtime-gated test-tooling contract, including its CSRF exception, with enabled/disabled-mode regression proof and accurate release instructions. | Build-time route exclusion requires an explicit packaging design, artifact-level tests, and manifest expansion before implementation; do not create two casually diverging route tables. |
| D6 | Adopt the enumerated CR-053-21 core/testing truth slice and approved README rewrite; describe the shipped `0.1.1` pre-release metadata separately from alpha/beta roadmap targets. Annotate ADR-003 with ADR-008/current scope instead of inventing ADR-013. | Adopt a larger named docs slice explicitly. Any remaining unrelated part stays open with exact ownership; completed slices are archived immediately. |
| D7 | Finish WP9 through Standard B, then require a separate final Full review across the whole CS-PLAN-058 journey before moving the parent to `done/`. Only the program prerequisite of RELEASE-002 is satisfied. | A combined final Full review must explicitly cover both owning plans and all parent criteria; a child-only passing review can never close the parent or execute a release. |

## 3. Backend / Frontend Changes

### 3.1 Test inventory and enforceable results

Record the executable baseline in `docs/engineering/testing.md`, not a new loose
audit file. Inventory each required test by suite/project, scenario, profile and
owner. Classify every skip/exclusion as live missing coverage, obsolete route,
optional engine capability, or duplicate project selection. Historical totals
and green-on-retry summaries do not establish scenario coverage.

For each live authorization scenario, link a positive and negative current
controller/service proof and, where middleware matters, a real HTTP assertion.
An API dispatcher failure must not be mistaken for a correct denial. Pin status,
standard error envelope, anti-enumeration behavior and zero unauthorized writes.
Delete an obsolete-route test only after `RouteMatrixTest` proves that route is
absent and its successor invariant has an executable owner.

The verified PHP dispositions are concrete:

| Current test | Required action |
|--------------|-----------------|
| `tests/integration/FamilyIntegrationTest.php` | Replace eight skipped family/routine/streak/concurrency/recurrence/cleanliness scenarios with current behavior-bearing tests using `CompletionService` and real persisted data. Link the existing race/recurrence proofs; obsolete mocks/formulas are not grounds to delete still-live behavior coverage wholesale. |
| `tests/integration/Controller/GameControllerIntegrationTest.php` | Rewrite the live `checkMedals` case against serialized medals and real persisted awards, including repeat-read idempotence. Remove only the skipped `handleTaskCompletion` controller case whose route was pruned by CS-PLAN-055. |
| `tests/integration/Controller/TaskAuthorizationTest.php` and authorization peers | Retain the already rewritten, non-skipped WP2 tests; correct the stale inventory in testing docs. Distinguish direct controller integration from actual HTTP/middleware proof such as `WireStatusAuthDenialTest`. |
| MySQL-guarded concurrency tests | Keep their engine guards. The canonical MariaDB Docker gate must execute them, not report them as permitted skips. Optional other-engine runs are separately documented. |

The final gate must fail on a missing required test, an unexpected skip, a
swallowed assertion, or a flaky required result. Prefer existing runner/report
facilities and a small repository-local result check over a custom test platform.
Project exclusions must happen through selection, not `test.skip` branches that
inflate the apparent coverage. Explicit optional capability exceptions need a
named scenario, reason and owner; none may cover a required core scenario.

Add a small `scripts/run-e2e-gate.js` entry point using the existing Playwright
CLI/discovery and JSON result format. Run the three selected projects separately,
check required file/scenario discovery against the reviewed inventory, and fail
on absent, skipped, failed or flaky results. Its self-test covers selection gaps,
duplicate project ownership and synthetic result failures. Keep output under
ignored test-result paths, redact sensitive artifacts and propagate nonzero
subprocess exits. Expose `npm run test:e2e:gate`; no external service or new test
framework is needed.
Keep the small explicit required-file/scenario manifest in this runner and link
it from the testing docs. It must not learn its requirements solely from the run
it validates, which would silently accept a deleted or unselected required test.

### 3.2 Database and profile ownership

Audit every spec/helper that seeds, resets, alters roles or global appconfig, or
assumes a mutable shared fixture. Give each such scenario a deterministic
starting state and move it into the reset-owning pool where necessary. Keep the
WP8 read-only request-budget spec read-only; do not move a reset into its default
pool merely for convenience.

Remove `fixtures/test-fixtures.ts` after migrating every importer: genuine
read-only tests import Playwright directly; data-mutating tests consume the
existing `database-fixtures.ts` and own a declared profile. Do not merely point
the old no-op reset at the live endpoint, which would introduce resets in the
parallel pool. Make `visibility-fixtures.ts` fail with a sanitized diagnostic
instead of returning false so its callers can skip.

Make the canonical local/CI gate run project selections sequentially against one
disposable database. Prevent reset fixtures from running in non-reset projects;
reseed the next owner's declared profile only after all previous contexts are
closed. Global authentication setup must not accidentally bootstrap the house
that the canonical first-owner scenario is meant to create.

Use existing `empty`, `emptyhouse`, and `family` responsibilities explicitly:
reset-only app state, named reset-only wizard state, and seeded multi-role data.
Assert identities, membership roles and profile preconditions before exercising
behavior. Provision synthetic Nextcloud accounts independently of ChoreStrike
memberships. Setup/invitations inside the canonical journey must use the real UI.
Profile reset must clear its documented app-data/config effects and must not
delete arbitrary Nextcloud users or other applications' data.

`auth.setup.ts` intentionally seeds family and saves role-specific sessions before
dependent projects. The journey's `seededData` must reset *after* this setup and
before any app navigation. Close all owner/member/child contexts even on failure,
then restore family data in teardown, as the WP3 wizard spec already does. Pin
this ordering and make reset attempts from the non-reset pool fail loudly.
Do not probe a bootstrap endpoint with the default admin or another account
between `emptyhouse` reset and the intended owner's first app navigation.

### 3.3 Canonical household journey

Replace, do not duplicate, the scratch spec with
`tests/e2e/core-household-journey.spec.ts`. Use one test with named `test.step`
phases and one database owner, so intermediate states are not a chain of
order-dependent test cases. Reuse current auth, dashboard, setup and task helpers
only where they drive UI actions rather than bypass them.

| Phase | Required unconditional proof |
|-------|------------------------------|
| First owner | Open the app as an authenticated account with no app household; exactly one instance house and one owner exist after bootstrap/reload. The separate real-DB concurrency test remains required. |
| Setup | Wizard sets house name, rooms/floors and template level; preview/apply creates the announced structure. Optional initial assignments use only existing members. |
| Invitations | Owner invites an adult and a child through the UI; isolated sessions enter with the expected membership and role. A non-invited account does not auto-enroll. |
| Tasks and assignments | UI creates custom and template-based room tasks, sets recurrence/due/flex, visibility, instructions/checklist and canonical assignees; persisted reads and reload match the submitted values. |
| Scheduling | Owner previews actual room-task changes and explicitly applies them; no writes during preview. Configure sufficient availability, a legal house-local due day and child limits, then verify Calendar and My Day from the applied result. |
| Child execution | Child root opens My Day; the expected assigned task, room and instructions/checklist are usable and owner controls are absent. Mark checklist progress and complete through the UI. |
| Undo and reload | Capture the completion response, assert the exact domain delta, immediately use the matching queued Undo control, then verify restored task/room/member/checklist state without and with reload. |
| Identity/retry | Re-enter as owner/member/child without another reset; state and visibility remain authoritative. Focused tests prove duplicate requests, removed membership, stale previews and failure recovery. |

Make at least one candidate start on a future day inside a valid flex window;
the owner explicitly moves it to **house-local today** and assigns it to the
child through the scheduling UI. Configure the child's availability/capacity for
all weekdays through existing controls. This proves an actual applied change
and makes the task executable in My Day regardless of the run's weekday.

Browser API reads may capture before/after snapshots and responses. They must not
create memberships/tasks, schedule, complete, undo or repair the state under test.
Keep API-driven negative/fault-injection fixtures in the focused tests, not the
happy journey. Desktop is the canonical long path; focused owner/member/child
mobile cases retain the complete role/accessibility matrix without duplicating
the entire long journey for every viewport.

### 3.4 Exact assertions and deterministic dates

Use a known eligible occurrence, known assignee, non-saturated cleanliness and
controlled reward inputs. Assert the completion receipt's exact credited delta
against independently captured before/after totals and the existing frozen-rule
tests; never compare a value to itself or accept both success and failure.
Use actual user IDs, not fixed seeded row IDs or ambiguous task names.
Capture the before-state and immutable completion receipt, then trigger the
matching Undo promptly before lengthy verification. Assert captured receipts
and restored state afterwards; do not extend the product's short undo window
just to accommodate a slow sequence of test-only network reads.

Undo evidence follows ADR-011: room-task recurrence/activity/claim fields, room
cleanliness/lastCleaned, completer wallet/contribution/streak/freeze/activity,
recorded title additions and checklist progress return to their before-state.
The retained ledger row is marked undone rather than deleted. Already awarded
medals, weekly-goal notification claims and sent notifications are documented
non-retractions, not missing undo fields. Persisted exhaustive equality stays in
the real-DB suite; browser proof covers the observable response and projections.
Repeated undo is a zero-write no-op; no reward-rule recalculation is permitted.

Repair the swallowed reward-toast expectation, conditional assertions and
`toBeGreaterThanOrEqual(0)` combo tautology in
`task-completion-full-flow.spec.ts`; retain its already strong WP5 inverse test.
Replace fixture-dependent skips across the explicit section 5 spec list with
guaranteed preconditions and unconditional assertions. Missing auth state, rooms,
eligible tasks or a valid fixture response are failures, not runtime skips.

Fix BUG-E2E-005 by aligning the assertion to the displayed week and using
controlled per-day capacity, not the weekday-dependent family profile. Pin
Saturday/Sunday/Monday behavior without weakening the marker assertion or
requiring a particular execution day. Record the notification-dedup docs portion
of that item separately from the calendar test correction.

BUG-E2E-006 is specifically the UTC-seeded `todayKey` followed by a house change
to `Europe/Berlin` in `SchedulePlanAggregateTest`:
`SchedulePlanService::buildPlan` validates against **house-local**, not server-UTC,
today. Late in the UTC day Berlin is already tomorrow, so `apply()` throws a
validation error before reaching the expected fingerprint conflict. Correct the
backlog's west-of-UTC/setup-error description in the implementation slice.

Use a test-only legal future start date that remains valid in both compared
timezones and seed its task/flex window consistently; preserve the original
UTC-to-Berlin transition and exact conflict/zero-write assertion. Parameterize
opposite-direction timezone changes, and pin the fixture-date calculation at
midnight/DST boundaries. No production `ITimeFactory` constructor sweep is needed
for this fixture defect. Browser clock control alone does not freeze PHP; do not
accept sleeps, catch-all assertions or retry-until-lucky execution.

### 3.5 Deferred tests and test-tooling boundary

CR-053-22's two backend suites and all eight direct component suites are still
missing. Add the following bounded coverage:

| Test owner | Required behavior |
|------------|-------------------|
| New `Controller/MinigameControllerIntegrationTest.php` | All nine registered minigame routes: score, wallet, leaderboard, weekly leaderboard, personal best, rank, recent scores, boosts and purchase; real-DB controller results, membership denial, validation, limits, exact deltas and failure envelopes. Use existing wire-test patterns where dispatch/middleware must be proven. |
| New `Service/TestDataSeedTest.php` | `empty`/`emptyhouse` leave no app rows; minimal/family persist the declared structure, users/roles, assignments and config; repeated seed/reset has no duplicates/orphans; default admin is an invited member, never an accidental owner; unrelated app/Nextcloud data survives. Derive expected profile contents from current seed code, not stale docblock counts. |
| New direct Jest component suites | `ActivityFeed`, `HouseStatsCards`, `MemberFairnessTable`, `WeekBudgetGrid`, `RoomPicker`, `BoostShop`, `SnakeGame`, `SnakeLeaderboard`: real data/empty/loading/error branches where applicable; rendering, emitted selection/input, store calls, timers and unmount cleanup. Existing stubs/store tests do not exercise these component behaviors. |

Preserve shipped game rules and focus assertions on authorization,
payloads, validation, isolation, state branches, input events and failure recovery.
Tests cannot be shallow mount-only assertions or mock the behavior they claim
to verify. Use fake timers for game/leaderboard refresh loops and the shared
Nextcloud axios mock for frontend network branches.

Under D5, test the registered test routes with test mode enabled and disabled,
including authentication, malformed input, profile enumeration, seed/reset
effects and refusal before mutation outside test mode. Restore the process
environment after each test. Keep the plain test-tooling contract separate from
the normal application's authenticated/CSRF-protected mutation contracts.
Document the operator boundary accurately; any newly discovered exploitable
detail follows the private security-disclosure process and blocks finalization.
Retain the mock-based `Controller/TestControllerTest.php` response/error tests;
add a separately named `Controller/TestModeApiTest.php` for real service/DB route
behavior. Pin disabled/unset/invalid mode through real-DB controller/service calls
with a test-owned process environment; prove authentication and enabled-mode
dispatch separately over HTTP. Label those evidence layers honestly: changing
PHPUnit's environment does not change an already running web server. A live
disabled-server/artifact test would require a separately reviewed sequential
server-lifecycle addition, not a new runtime toggle endpoint.

### 3.6 Global localization closure

Extend `scripts/check-l10n-keys.js` in place: export the extraction/validation
functions for Jest and keep the default CLI check-only. Reuse the proven escaped
literal handling from `coreWorkflowLocalization.spec.js` for all `src/**/*.js`
and `src/**/*.vue`, add PHP localization literals from `lib/`, and handle the
finite dynamic sources explicitly. No AST dependency or new localization
framework is proposed; extraction fixtures must cover actual syntax variants.

| Dynamic source | Required ownership |
|----------------|--------------------|
| `lib/Titles/TitleCatalog.php` | Keep its complete pack/tier label-key set, including completion/title-picker/notifier consumers; regression-check the finite registry against the catalog. No blanket prefix exemption. |
| `lib/Templates/TaskTemplates.json`, `RoomTypes.json` | Derive translated names/descriptions/instructions/default names and level labels from their current catalog/TemplateService owners, not a duplicated handwritten catalog. |
| Frontend constant maps | Explicitly own medal, energy and recurrence label values passed through variable-key `t()` calls; prove their source-to-registry correspondence. |

Share this inventory between checking and an explicit `--write` regeneration
mode. Default checks must reject a source key missing from both locales or POT,
and an unclassified dynamic usage cannot disappear silently. Cover escaped and
multiline strings, named frontend placeholders and positional PHP placeholders;
retain the documented sanitized generic formatter fallback and internal-only
exclusions. Current production plurals use manual singular/plural key pairs, not
`ngettext`: cover both branches without introducing a new plural API.

Regenerate POT and EN/DE artifacts from this inventory, preserving valid German
translations and documented server-driven title keys. Report and resolve
case-variant mismatches and remove only keys with no live source/catalog owner.
The legacy `template.*` keys and pruned `Search query is required` are deletion
candidates only after source/catalog reconciliation. Do not reuse historical
approximate dead-key counts as a target. `--write` must not silently fill missing
German translations with English and call that complete; unresolved translations
remain blocking until real German copy is supplied. Retain WP7's core
localization regression or fold it into an equivalent stronger test without
losing coverage of the WP8 extracted components.

Checker tests must intentionally omit a key from both locales, drop a POT entry,
break named/printf placeholders or one singular/plural branch, drop a required
dynamic/catalog key, and insert a dead key. All must fail for the intended
reason, while valid dynamic keys and preserved translations pass. Deterministic
regeneration run twice produces no
second diff. Verify representative DE/EN core and previously uncovered views in
the browser; this is copy/artifact closure, not a UI redesign.

### 3.7 Durable documentation and program handoff

Update existing permanent docs instead of making this plan the system manual:

| Durable owner | Required integration |
|---------------|----------------------|
| `README.md` | Full user-approved truth rewrite: Nextcloud household app, email-based invitations, shipped workflow, actual PHP/Nextcloud requirements and `0.1.1` pre-release metadata. Distinguish `v0.8.x`/`v0.9.x` roadmap goals; remove tower-defense/Sparks, approval/AI promises and the false release-candidate claim. No version bump. |
| `docs/engineering/testing.md` | Profiles and exact project ownership, canonical gate commands, required scenario/skip inventory, clock policy, state-equality boundaries and current evidence locations. |
| `docs/engineering/architecture.md`, `development.md` | Replace fictional `TaskForm`/`TemplateSelector`/`fetchHouseStats`/`TaskItem` examples with live counterparts; document migration ordering/fresh-install hooks and include the WP5 Version1029 chain change. Retain WP8's size exceptions and budgets; prefer generated inventory commands to drifting counts. |
| `docs/api/README.md` | Current routes, camelCase payload examples, identity/assignee types, error/role policy, setup/create/preview/apply/complete/undo and test-tooling boundary. |
| `docs/adr/ADR-003-task-scope-system.md`, `docs/adr/README.md` | Annotate the historical five-value scope/schema description with the current `room`/`floor`/`house` model and ADR-008; repair the missing `TASK_SCOPES.md` link without inventing a replacement historical document. Preserve the original decision as history. |
| `docs/concepts/task-model-and-sync.md`, `ui-use-cases.md`, `gamification-and-rewards.md` | Verify the public core workflow/feature-status statements against WP2-WP8, mark unimplemented add-ons explicitly, and link current ADR/API owners instead of duplicating contracts. |
| `docs/standards/guidelines/i18n.md`, `testing.md`, `security.md`, and `docs/standards/ai/skills/i18n/SKILL.md` | Source/catalog extraction and checker policy, mandatory scenario enforcement and the ratified test-mode disposition. Keep the skill a thin pointer to the canonical guideline. |
| `docs/release/roadmap.md` | Distinguish historical deliveries from current capability and remaining release work; program completion is not deployment approval. |

Build the parent's acceptance evidence map with exact test/doc owners. Credit
existing deliveries, resolve stale present-tense statements, and archive resolved
backlog stubs/slices without closing unrelated remainders. Keep CR-SVC-15/44,
CR-053-19, CR-053-11 and CR-SVC-63 outside this implementation boundary; do not
silently declare their remaining work complete for a cleaner release summary.

RELEASE-002 currently also has incoming blockers BUG-SEC-001 and SEC-AUDIT-001.
The final handoff must retain those independent obligations even if the core
program prerequisite is satisfied. No productive-use declaration, compatibility
policy change, deployment, tag, release artifact publication or App Store action
is part of WP9.

## 4. Tests

### 4.1 Required coverage matrix

| Layer | Required evidence |
|-------|-------------------|
| Fresh installation | Disposable Docker volume installs the existing migration chain; no upgrade shim. First-owner browser proof plus real-DB bootstrap race proof. |
| Authorization | Current positive/negative controller/service matrix for owner/member/child/invited/removed/non-member and cross-house/private/admin-only access; HTTP status/envelope and no-write assertions where applicable. |
| Aggregates | Preserve setup/create/assignment rollback, scheduling fingerprint/conflict, completion/undo exact equality, idempotence and two-connection concurrency tests from WP1-WP8. |
| Determinism | Profile seed/reset isolation; weekday/week-boundary/UTC-local/DST cases; no state leak between projects or repeated runs. |
| Test tooling | Enabled/disabled-mode routes and seed/reset effects; runner selection/result guard rejects missing, skipped and flaky required scenarios. |
| Deferred coverage | Every still-live CR-053-22 case has its own behavior-bearing test, or explicit pre-activation user rescoping changes the requirement. |
| Frontend | Retain payload/store reconciliation, stale-response ordering, event silence/retry and role/accessibility coverage; close the enumerated missing component suites. |
| Localization | Global source -> POT -> EN/DE coverage, placeholders/plurals, dynamic catalogs and deterministic regeneration; representative real-browser DE/EN rendering. |
| E2E | One canonical household journey plus focused setup, creation, scheduling, privacy/roles, completion/undo, error recovery and mobile/a11y proofs. |
| Performance | WP8 `WorkflowQueryBudgetTest`, request-count/store-DAG and browser request-budget proofs remain green at their frozen ceilings. |

### 4.2 Reproducibility and negative proof

Run the whole canonical browser gate twice consecutively from its declared reset
boundaries with **retries disabled**. Required scenarios must have zero skips,
failures and flaky outcomes on both runs. Do not use an isolated passing rerun
to erase a full-pool failure. Exercise weekday and timezone variations through
controlled fixtures/data providers rather than waiting for another calendar day.

Demonstrate that the test-result guard rejects a deliberately missing selection,
a required skip and a pass-on-retry result. These guard self-tests use synthetic
reports, not intentionally broken committed application tests. The baseline
inventory distinguishes legitimate unselected optional browser engines from a
required test that was registered but never ran.

### 4.3 Full quality gate

Run targeted tests while implementing, then the full gate after the last change
and again after review fixes. Existing commands below gain a strict PHPUnit skip
gate and the proposed Node browser entry point. Freeze that entry point in the
permanent testing docs and the blocking release workflow before the E2E slices.
Do not run PHP integration and browser projects concurrently on the same DB.

```text
npm run lint:fix          # only when the entire write scope is safe
npm run lint
npm run stylelint
npm test
npm run test:noise
npm run build
npm run l10n:check-keys
npm run docs:encoding
npm run backlog:check
docker exec chorestrike-test vendor/bin/phpunit --configuration phpunit.unit.xml
docker exec chorestrike-test vendor/bin/phpunit --configuration phpunit.integration.xml --fail-on-skipped
docker exec chorestrike-test composer cs:check
node scripts/run-e2e-gate.js --self-test  # new in WP9
npm run test:e2e:gate                   # new; run twice for final evidence
git diff --check
```

`test:e2e:gate` runs `chromium`, `chromium-serial-db`, then
`chromium-scheduling-owner`, each with `--workers=1 --retries=0 --forbid-only`
and machine-readable discovery/results checked by the runner. It must execute
`core-household-journey.spec.ts` exactly once in the serial pool. Optional
Firefox/WebKit matrices remain separate and cannot reset this gate's live DB.
Retain traces on failures without relying on an enabled retry to capture them.

Use `docker-compose.test.yml` and the running `chorestrike-test` container,
never `docker compose run`. A clean-volume proof is required implementation
work; do not reset an environment owned by another session. Docker Desktop is
installed: diagnose daemon/build readiness per the `docker-dev` skill instead of
claiming Docker is unavailable. PowerShell uses `$env:TEST_DB_PROFILE='family'`
before the Compose command, not Bash environment-assignment syntax.

Documentation-only draft verification is limited to encoding, backlog/link and
whitespace checks, explicitly including this untracked file without staging it.
No application gate is claimed to have run during plan creation.

## 5. File Manifest

The implementation manifest is bounded by the current inventory and must be
ratified by Standard A before any implementation starts. New files
are allowed only where no existing test/tool owner exists; no blanket `tests/**`
or `docs/**` edit authorization. Route/schema/business-policy changes outside
the named boundaries require a plan update.

```text
new:      tests/e2e/core-household-journey.spec.ts
replaced: tests/e2e/gui-scratch-flow.spec.ts
removed:  tests/e2e/fixtures/test-fixtures.ts (migrate every importer first)
new:      scripts/run-e2e-gate.js (sequential runner, result checks, --self-test)
new:      tests/integration/Controller/MinigameControllerIntegrationTest.php
new:      tests/integration/Controller/TestModeApiTest.php
new:      tests/integration/Service/TestDataSeedTest.php
new:      tests/unit/components/{ActivityFeed,HouseStatsCards,MemberFairnessTable,WeekBudgetGrid,RoomPicker,BoostShop,SnakeGame,SnakeLeaderboard}.spec.js
modified: tests/unit/services/coreWorkflowLocalization.spec.js
modified: package.json
modified: .forgejo/workflows/release-gate.yml
modified: playwright.config.ts
modified: tests/e2e/auth.setup.ts
modified: tests/e2e/fixtures/{database-fixtures,visibility-fixtures}.ts
modified: tests/e2e/{task-completion-full-flow,insights-flow,setup-wizard-journey}.spec.ts
modified: tests/e2e/{admin-only-tasks,overdue-task-claiming,private-tasks,teamwork-rewards,room-management}.spec.ts
modified: tests/e2e/{release-task-lifecycle,release-visibility-roles,release-notifications}.spec.ts
modified: tests/e2e/{3-floor-house,core-functionality,room-detail,self-availability-member,title-selection}.spec.ts
modified: tests/e2e/{availability-settings-flow,calendar,dashboard,fast-core-tests,flex-window-scheduling,leaderboard,minimal-smoke-tests,scheduling,settings,snake-game,task-checklist-and-features,task-manager,task-completion}.spec.ts (remaining legacy-fixture imports; only mechanical migration unless isolation tests require more)
modified: tests/integration/FamilyIntegrationTest.php
modified: tests/integration/Controller/GameControllerIntegrationTest.php
modified: tests/integration/Service/SchedulePlanAggregateTest.php
modified: l10n/en.json
modified: l10n/de.json
modified: l10n/templates/chorestrike.pot
modified: scripts/check-l10n-keys.js
modified: README.md
modified: docs/engineering/testing.md
modified: docs/engineering/architecture.md
modified: docs/engineering/development.md
modified: docs/api/README.md
modified: docs/adr/ADR-003-task-scope-system.md
modified: docs/adr/README.md
modified: docs/concepts/{task-model-and-sync,ui-use-cases,gamification-and-rewards}.md (verified truth/feature-status slice only)
modified: docs/standards/guidelines/i18n.md
modified: docs/standards/ai/skills/i18n/SKILL.md
modified: docs/standards/guidelines/testing.md
modified: docs/standards/guidelines/security.md
modified: docs/release/roadmap.md
modified: docs/plans/README.md
modified: docs/plans/active/cs-plan-058-core-workflow-bottom-up-masterplan.md
modified: docs/plans/active/cs-plan-068-wp9-deterministic-coverage-release-closure.md
modified: docs/backlog/backlog.md
modified: docs/backlog/ACTIVE_TASKS.md
modified: docs/backlog/archive/2026-09.md
```

Archive the superseded scratch spec for local reference under
`archive/2026-09/wp9-deterministic-coverage/` before removing its tracked path;
do the same for the removed legacy fixture. Never include those ignored copies
or test reports/authentication state in a commit.
Lifecycle reviews update moved plan paths and inbound tracked links in the same
review package. Review evidence uses the actual review date's existing monthly
archive convention, not a predetermined future result.

## 6. Implementation Order

1. Complete Standard A: resolve D1-D7, verify live inventory and exact manifest,
   record ownership decisions, activate this child plan and update tracking only
   on a passing outcome. Keep one active implementation WP.
2. Capture required tests/skips/projects/profiles and the parent evidence map;
   add failing regressions for result enforcement and fixture isolation first.
3. Fix project/DB ownership and the canonical local/CI runner. Test seed/reset and
   D5 test-mode behavior before relying on them for browser evidence.
4. Replace the eight stale family-scenario skips and live medal skip, remove only
   the obsolete GameController route case, and credit retained authorization
   coverage. Fix BUG-E2E-005/006 with explicit date-boundary regressions.
5. Replace the scratch journey; strengthen completion/undo and focused failure,
   role, mobile and localization-browser assertions. No API/DB progression
   shortcuts in the canonical user journey.
6. Close the still-live CR-053-22 tests and global CR-053-13 extraction/artifact
   work. An interim Standard B may review a coherent slice; incomplete required
   work keeps the plan active.
7. Integrate durable docs, reconcile every owning backlog criterion, run the full
   gate including two retry-free browser runs, and finish the parent evidence map.
8. Obtain final Standard B for WP9; archive completed items and mark parent WP9
   complete only on a passing outcome with all required work resolved.
9. Obtain final Full review across the entire parent journey. Only that passing
   review, with all parent criteria/tests/docs/findings complete, moves
   CS-PLAN-058 to `done/` and satisfies RELEASE-002's program prerequisite.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Old backlog descriptions cause duplicate implementation or test deletion | High | Current-tree scenario inventory; credit WP2-WP8 deliveries and map successor coverage before removing obsolete cases. |
| Serial tests still race another project's reset | High | One exclusive DB owner and sequential project entry point, exact selection and reset-context teardown. |
| Fixtures bypass the first-owner/invitation behavior being proved | High | Separate account provisioning from memberships; canonical journey starts without app household data and mutates only through UI. |
| Calendar luck or PHP/browser clock disagreement produces intermittent green | High | Controlled capacity/date fixtures, explicit week/UTC-local boundaries; no sleep or retry-based acceptance. |
| Undo equality ignores recorded fields or demands forbidden non-retractions | Medium | ADR-011 field-level matrix, real-DB exhaustive proof plus browser projection assertions. |
| Dead-key cleanup deletes dynamic translations or corrupts placeholders | High | Shared extraction/checking inventory, finite catalog ownership, negative checker tests and repeatable regeneration. |
| Deferred game/component tests turn into product redesign | Medium | D3 test-only scope; freeze formulas/rules and obtain explicit scope decisions for newly exposed behavior changes. |
| Passing WP9 is presented as release approval | High | Separate child Standard B, parent Full review and independent release/security obligations; no deployment/tagging. |
| Broad docs work erases historical decisions or expands endlessly | Medium | D6 named truth slices, annotate/supersede ADRs appropriately, preserve archival evidence and leave unrelated remainders explicit. |

## 8. Acceptance Criteria

- [ ] AC-01: D1-D7 are resolved and recorded, exact manifest/ownership ratified, and no unapproved scope adoption or unanswered implementation decision remains.
- [ ] AC-02: Every required scenario and baseline skip/exclusion has a current executable owner and explicit disposition; no live authorization case disappears with an obsolete test.
- [ ] AC-03: Seed/reset and non-reset project selections are disjoint and execute under one exclusive DB owner; reset side effects, role preconditions and authentication setup are regression-proven.
- [ ] AC-04: A clean Docker install and unbootstrapped first-owner browser path pass; WP1's race-safe exactly-one-house/owner proof stays green.
- [ ] AC-05: One replacement household journey executes setup, invitations, task creation/assignment, scheduling, child My Day, completion and undo through the real UI with unconditional assertions and no app-data progression shortcuts.
- [ ] AC-06: Completion proves exact deltas; undo restores the ADR-011 state and client projections before/after reload, respects documented non-retractions and is idempotent; existing race/rollback proofs stay green.
- [ ] AC-07: BUG-E2E-005/006 are fixed and regression-proven across their weekday/timezone boundaries; the dedup-documentation portion of BUG-E2E-005 is resolved before closing that item.
- [ ] AC-08: Every still-required CR-053-22 test is implemented and passing, not silently deferred or replaced by a mount-only assertion.
- [ ] AC-09: The ratified test-mode release contract has enabled/disabled-mode no-write protection tests, accurate docs and no open security blocker.
- [ ] AC-10: Global source/POT/EN/DE coverage, dynamic catalogs, placeholders/plurals and deterministic artifact regeneration pass; real DE/EN core rendering stays correct.
- [ ] AC-11: Owner/member/child desktop/mobile role, focus, label, 44 px target, checklist and recovery coverage remains green; the long desktop journey does not replace those focused proofs.
- [ ] AC-12: Required-result enforcement rejects missing/skipped/flaky evidence; the complete sequential browser gate passes twice with retries disabled and zero skipped required scenarios.
- [ ] AC-13: Full quality gate passes after the final change and review fixes, including all backend/Jest tests and unchanged WP8 request/query ceilings; no pre-existing required red is accepted as WP9 completion.
- [ ] AC-14: All durable outcomes and the adopted CR-053-21 slice are integrated into permanent docs; README/API/testing claims match executable behavior rather than historical plan promises.
- [ ] AC-15: Completed owning items/slices are archived immediately, resolved stubs removed, unrelated remainders and independent RELEASE-002 blockers preserved, and ACTIVE_TASKS/plan indexes current.
- [ ] AC-16: All plan-owned findings are resolved or explicitly user-rescoped; final WP9 Standard B is `PASS` or `PASS-WITH-FIXES`. Parent closure still requires the separately recorded final Full review and complete parent acceptance evidence.

## 9. Review Findings (plan-owned)

Standard A ran 2026-09-13 (`PASS-WITH-FIXES`); planning decisions D1-D7 are
ratified in section 2.2, not silently treated as approved findings. New
in-scope implementation/review findings belong here and block completion until
resolved; out-of-scope findings fold into an existing backlog item before any
new one is created. Sensitive security details must not be written to public
plan/backlog/review records.

- [x] RF-068-01 · minor · §3.5 minigame coverage table · "seven registered
  minigame routes" understated the tree: `appinfo/routes.php` +
  `MinigameController` register **nine** (`minigame#userRank`,
  `minigame#recentScores` omitted from the enumeration) · fixed in the review
  round (enumeration corrected to nine; coverage contract now complete).

## 10. Draft Verification and Review Handoff

Draft creation is documentation-only. Standard A is mandatory before activation;
this request does not authorize implementation, a lifecycle move or a commit.
The review must verify the code-grounded inventory, D1-D7, required-result and
clock feasibility, backlog portions, exact file manifest and the two-stage
closure contract. Application test execution remains section 4 implementation
work, not a claimed result of writing this plan.

Draft verification on 2026-09-13:

- `npm run docs:encoding` and `npm run backlog:check` passed. Both existing
  checkers also passed with their in-memory Git enumeration expanded to include
  this untracked draft; neither checker nor the Git index was modified.
- `git diff --check` and the new-file `git diff --no-index --check` passed.
- Read-only E2E, backend-test and localization/docs inventories informed this
  draft; the main-agent consistency pass corrected profile semantics, stale skip
  attribution, the timezone failure mechanism and exact document paths.
- No application files were changed, no application tests/builds were run, no
  formal review was performed and no commit or lifecycle move was made.

## Review Outcome

- **Review:** Standard A (plan), 2026-09-13, requested explicitly with commit
  authorization (no `--no-commit` override).
- **Scope:** this plan file as the single regular `*.md` candidate in
  `docs/plans/new/`; destination `docs/plans/active/` free of collisions.
- **Base state:** `master` @ `4b99d9ff79b95a1fe64ebec657cd25a00c27aafb`, clean
  index; untracked plan content hash `9611ac75565091fc9fb2e01491981cc3df74312c`;
  five unstaged companion modifications (ACTIVE_TASKS, backlog.md,
  archive/2026-09.md, plans/README.md, masterplan) verified as the draft-owned
  package — no unrelated drift.
- **Outcome:** `PASS-WITH-FIXES`.
- **Decisions:** D1-D7 ratified as recommended by the user (one structured
  batch, 2026-09-13); recorded in §2.2.
- **Findings:** RF-068-01 (minor) — §3.5 said "seven registered minigame
  routes"; the tree registers nine (`minigame#userRank`, `minigame#recentScores`
  in `appinfo/routes.php` + `MinigameController`). Fixed in round: enumeration
  corrected to "nine". No other findings; no security findings.
- **Inventory verification (spot checks, all confirmed):** three Chromium pools
  in `playwright.config.ts` and `chromium-scheduling-owner` missing from
  `.forgejo/workflows/release-gate.yml`; exact unconditional skip census
  (8 × `FamilyIntegrationTest` + 2 × `GameControllerIntegrationTest`; all other
  skips are MySQL engine guards); legacy `fixtures/test-fixtures.ts` calls the
  removed `api/reset-test-data`/`api/seed-minimal` routes and returns false;
  `Request failed`/`Boost Shop`/`This Week's Medals` absent from en+de while
  `check-l10n-keys.js` only diffs de↔en; CR-053-22 deferred list (2 backend
  suites + 8 existing components) matches the tree; BUG-E2E-005/006 mechanisms
  code-grounded (`SchedulePlanAggregateTest` UTC `todayKey` + `Europe/Berlin`,
  `SchedulePlanService` house-timezone bucketing; `task-completion-full-flow`
  swallowed toast + `toBeGreaterThanOrEqual(0)` tautology); all referenced
  backlog IDs resolve; parent D5 scratch-replacement ratification verified;
  `emptyhouse` profile and test-mode contract (`NEXTCLOUD_TEST_MODE`,
  `@NoCSRFRequired`) verified.
- **Gates:** `npm run docs:encoding` green (706 tracked files), `npm run
  backlog:check` green, `git diff --check` clean, untracked-file
  `git diff --no-index --check` clean (exit 1 = expected differences-found
  code, zero whitespace errors). Application gates are §4 implementation work.
- **Transition:** moved `new/ → active/` by this review; owning backlog items
  CR-WF-05, CR-053-13, CR-053-18-WP9, CR-053-21 (D6 slice), CR-053-22,
  BUG-E2E-005, BUG-E2E-006 set In Progress; BUG-E2E-006 owning corrected to
  this plan (provenance retained); inbound tracked links (ACTIVE_TASKS,
  plans/README.md, masterplan WP9 row) repointed; review record appended to
  `docs/backlog/archive/CODE_REVIEW_2026-09-13.md`.
