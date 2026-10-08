# CS-PLAN-080: WP9 Cross-Database Verification and Family Evidence

> **Status:** Active (technical gates passed; human coordination, guardian consent and voluntary participation confirmed 2026-10-07, execution/results pending; final review pending); drafted 2026-10-02; Standard A `PASS` 2026-10-03
> **Effort:** M, 4-6 engineering days plus 14 calendar days of voluntary everyday use; revise after the portability inventory if repairs exceed this allowance
> **Priority:** P1
> **Depends on:** WP0-WP8 delivered; Standard A before implementation; separately authorized, available consenting family testers before human evidence
> **Related backlog items:** CONCEPT-001 (existing parent owner, remains In Progress)
> **Parent:** [CS-PLAN-070](../active/cs-plan-070-family-rhythm-and-reward-clarification.md), WP9 / T12 / G3
> **Delivered prerequisites:** [WP0](../done/cs-plan-071-wp0-write-identity-foundation.md), [WP1](../done/cs-plan-072-wp1-occurrence-timeline.md), [WP2](../done/cs-plan-073-wp2-agreements-capacity-pauses.md), [WP3](../done/cs-plan-074-wp3-work-accounting-and-replay.md), [WP4](../done/cs-plan-075-wp4-rhythm-goals-and-ranking.md), [WP5](../done/cs-plan-076-wp5-preferences-and-digest-delivery.md), [WP6](../done/cs-plan-077-wp6-calm-core-ui.md), [WP7](../done/cs-plan-078-wp7-catalog-and-request-backend.md), [WP8](../done/cs-plan-079-wp8-reward-ui-and-onboarding.md)
> **Execution sequencing update (2026-10-05):** user-requested [CS-PLAN-081](../done/cs-plan-081-pre-wp9-hardening-and-retained-data-readiness.md) is delivered (final Full `PASS-WITH-FIXES`, accepted H6-05 evidence-bound disposition; H7/H-T11 user-rescoped, not passed). Refresh this plan's runtime baseline/coverage mapping before technical execution. WP9 remains active and retains the DB matrix, consecutive browser pair and human evidence; no WP9 lifecycle move, implementation or adoption is claimed by preparation completion.

## 1. Problem Summary

**Target-runtime follow-up (2026-10-05):** the NC31 technical passes below remain
historical evidence for their exact runtime. The user subsequently supplied
Nextcloud 34.0.4.1 / PHP 8.4.25 / PostgreSQL 18.6 (AIO Stable 14.2.0) and requested
that the regular local test baseline follow current stable.
[CS-PLAN-082](../done/cs-plan-082-current-stable-test-baseline-and-data-preservation.md)
(delivered, final Full `PASS-WITH-FIXES` 2026-10-06)
owns that infrastructure/compatibility qualification and renewed target evidence.
Its implementation discovery now distinguishes upstream NC35.0.1 from
AIO14.2.0/NC34.0.4; Q-082-02 now selects NC35 primary plus the named NC34 AIO row.
Its final evidence records both runtime rows, full no-skips provider gates,
P1/P2/P3 preservation/recovery and canonical/CI promotion. The post-repair final
PostgreSQL browser pair `run-29nxkD` + `run-Wy1fPg` passes all three pools (288
executions per run, zero skips/failures/flakes). The pre-CS-PLAN-082 runs below
remain historical evidence, not rewritten to the new runtime.
It does not reroute WP9's human gate or adopt retained data. Do not use the prior
runtime's green checkboxes as target-server readiness; qualification must precede
the separate RELEASE-002 deployment/adoption decision. No runtime change is
authorized by drafting the follow-up.

**Sequencing note (2026-10-06, CS-PLAN-082 promotion slice):** CS-PLAN-082 promoted
the verified NC35.0.1/PHP8.4.25/PostgreSQL18.6 candidate into the canonical
default (`docker/compose.test.yml`, Dockerfile defaults and CI); the old
disposable NC31 canonical stack was retired after evidence capture, while the
`cs-wp9-20261005a-*` projects and this plan's recorded NC31 matrix/browser passes
below remain untouched historical evidence. `compose.test-matrix.yml` default DB
images now mirror the frozen inventory, so future WP9 reruns start from the new
baseline; the recorded runs below predate that change and stay valid for their
exact runtime. The renewed target-runtime browser pair runs as CS-PLAN-082's R4
gate; its result is recorded there and linked, not written over this plan's
historical passes.

The implementation packages have passed their individual final reviews. This
does not close the parent's cross-domain evidence gate. The latest WP8 record
contains a full MariaDB backend pass and one combined browser-gate pass, not a
SQLite/PostgreSQL installation matrix, two consecutive final-tree browser passes,
or the voluntary family evidence required by C5j.

Verified drafting baseline:

- `docker/compose.test.yml` uses MariaDB 10.6, fixed container names and port 8080.
- `docker/test-entrypoint.sh` uses MySQL-specific readiness probes and hardcodes
  `maintenance:install --database=mysql`. A compose DB substitution alone cannot
  produce a valid PostgreSQL or SQLite installation.
- `docker/Dockerfile.test` installs `pdo_mysql` and `pdo_pgsql`; verify actual
  SQLite driver availability rather than assuming it from a base image.
- `docker/compose.test-matrix.yml` does not exist. The isolated SQLite acquisition
  case in `HouseMutationConcurrencyTest.php` is not a full migration/service proof.
- Several real two-connection concurrency suites, including
  `RewardConcurrencyTest.php`, explicitly skip non-MySQL providers. Running them
  unchanged cannot satisfy a no-skips cross-DB gate.
- `npm run test:e2e:gate` already combines `chromium`, `chromium-serial-db` and
  `chromium-scheduling-owner`; the runner rejects missing scenarios, skips,
  failures, flaky/interrupted results and uses zero retries.
- Family acceptance rules exist in [UI use cases](../../concepts/ui-use-cases.md#family-acceptance-protocol).
  No family participation, consent or trial result is established by this draft.
- Parent RF-070 entries still include open or partially delivered findings.
  Child delivery is supporting evidence, not automatic whole-item closure.

This drafting round changes documentation only. It does not run runtime gates,
activate the child, contact testers, collect data, commit or close the parent.

## 2. Strategy

Verify the delivered contracts rather than inventing another product slice.
Inventory T0-T12 and parent RF-070-01..17 against actual delivered test paths;
repair only uncovered integration/portability defects with regression tests.
Keep three independent evidence tracks: supported databases, repeated browser
execution, and voluntary family comprehension/everyday use.

Main owns writes, synthesis and evidence integration; an independent reviewer
checks the final result. Internal read-only exploration/review may be delegated.
No visible Agent Manager sessions or independent worktrees are requested.

### Scope and Non-Goals

- In scope: fresh installations, schema/query portability, production-path
  serialization/rollback, cross-domain privacy/erasure, fixture consistency,
  T12 coverage, full gates, durable documentation and C5j evidence.
- No upgrades, old-data backfills, compatibility adapters or new rewards/rhythm rules.
- No CalDAV, local child profiles, Task Pass, cosmetics, general chore approval,
  additional games or completion of the wider FEAT-UX-023 guided-tour remainder.
- TECH-DIGEST-001 and other independent backlog owners are not silently absorbed.
  A defect that blocks this plan's required evidence remains plan-owned until
  resolved or explicitly user-rescoped; unrelated improvements remain outside.
- Technical completion may be recorded separately, but cannot mark WP9, G3 or
  CONCEPT-001 done while required human evidence is absent.

## 3. Backend / Frontend Changes

### 3.1 Isolated Database Matrix

Create `docker/compose.test-matrix.yml` as a self-contained companion to the
canonical compose, reusing its Dockerfile and shared provisioning logic. This
refines the parent's word "override": blindly merging the fixed names,
MySQL dependencies and ports would defeat isolation. Do not replace or reset
the ordinary MariaDB environment.

The companion defines `mysql`, `pgsql` and `sqlite` Compose profiles, each with
a distinct Nextcloud service (`nextcloud-mysql`, `nextcloud-pgsql`,
`nextcloud-sqlite`), matching DB service where required, project-scoped names,
networks and disposable data volumes. No fixed `container_name`, no shared
Nextcloud config/data, no host port unless needed for a documented HTTP check.
Use a unique `-p cs-wp9-<run-id>` project; remove only that owned project after
evidence capture. Never use an unqualified shared-environment `down -v`.

Extend the shared entrypoint with a validated DB selector (canonical default
`mysql`), matching readiness/auth checks and `occ maintenance:install` arguments.
SQLite uses its own on-disk Nextcloud DB, not `:memory:` or a toy PDO schema;
PostgreSQL installs through Nextcloud's supported `pgsql` path. No runtime
external services are introduced. Verify necessary PHP drivers and client tools;
change the test image only where missing. Invalid selectors, incomplete install,
failed app enablement, wrong provider or required seed failure must fail the
matrix gate, not be treated as a ready environment.

For every provider, use the actual app migration chain and `family` fixtures.
Assert installed provider, schema tables/columns/indexes through Nextcloud DB
abstractions, unique command/occurrence/wallet bindings and quiet defaults.
Do not hand-build feature tables or claim an existing-volume start proves a
fresh install. Record Nextcloud/PHP/database versions and image identity.

### 3.2 Portable Integration and Concurrency Proofs

Keep the full canonical MariaDB integration gate. Audit the integration suite
for driver-specific SQL, inserted-ID assumptions, cleanup order and MySQL-only
worker bootstrap/probes. Adapt existing tests where feasible; pin equivalent
production-path assertions when a DB genuinely needs different synchronization.

PostgreSQL races use real independent Nextcloud connections. SQLite races use
separate processes/connections to the same installed on-disk DB and assert
write serialization, not unsupported row locks. Use barriers with bounded waits;
delays must not be the ordering proof. Check both competing results and persisted
effects, including rollback. Removing a skip without exercising the worker is
not coverage. Do not introduce blanket non-MySQL exclusions or newly skipped tests.

Build an explicit provider/scenario coverage table before execution. If a
provider-specific test cannot run unchanged, name its equivalent scenario and
file before accepting any tailored suite. Required cross-domain assertions must
run with `--fail-on-skipped`; an unmapped exclusion blocks acceptance. Aim to
run the full integration suite on all three providers after portable repairs.

Root-cause repairs retain thin controllers, house-first locking, current
authorization under lock, exact integer accounting and Nextcloud queries.
No provider-specific production workaround is added merely to make a test pass.

### 3.3 Fixture and Browser-Gate Reconciliation

Map the parent's T0-T12 to the delivered suite; its 2026-09-18 N/M labels are
historical. Existing `family-rhythm.spec.ts`, `quiet-digest.spec.ts` and reward
specs are modifications only if missing assertions are found, not new files.
Review shared fixture reset boundaries, house clocks, auth identities and
optional-area defaults. Do not seed outcomes that bypass the contract being tested.

Add only missing T12 assertions to existing specs. Update required discovery
and pool ownership together. If discovery changes, regenerate
`tests/e2e/required-scenarios.json` with the existing runner and inspect every
removed/renamed scenario against its replacement; do not lower mandatory counts
to accept a red run.

### 3.4 Family Evidence and Durable Knowledge

Prepare the human protocol and sanitized evidence template in
`docs/testing/release-test-plan.md`, linking the existing C5j rules rather than
creating a competing protocol. Actual participation requires explicit user
authorization/coordination, voluntary adult participation and guardian consent
for the child. Plan activation alone does not authorize recruitment, telemetry,
recording or collection of personal information.

Record only consent-confirmed status and anonymous adult/child role results in
public evidence, never names, age, accounts, household details, medical reasons,
raw quotations or raw notes. Any necessary sensitive material stays in
gitignored `/private/`, subject to separately agreed collection/retention limits.
No automatic analytics or pressure/engagement scoring is added.

Integrate reusable matrix commands/limits into `docs/engineering/testing.md`
and the Docker/testing guidance; integrate manual protocol/results into the
release test plan. Verify ADR-013..017, API and concept docs agree with observed
contracts; repair durable drift where necessary. The child is a temporary
implementation/evidence record, not the only description of how verification works.

## 4. Tests

### 4.1 Required Coverage Matrix

| Gate | Required proof | Primary ownership / files |
|---|---|---|
| DB-01 / T0 | Fresh Nextcloud + full ChoreStrike schema on MariaDB, PostgreSQL and SQLite; exact fields/indexes, provider check, family seed/reset | Matrix compose/entrypoint; new `tests/integration/Db/SupportedDatabaseSchemaTest.php`; existing migration/bootstrap suites |
| DB-02 / T1-T3/T5/T8 | Anchored/interval occurrence selection, saved zones/windows, amendments/pauses, whole-day capacity, chronological rhythm, provisional weeks and consent/privacy | Existing occurrence/agreement/capacity/rhythm/ranking suites; audit and pin exact test identifiers before execution |
| DB-03 / T3/T4/T6 | Equal-share conservation, targeted undo preserving later work/spending, room replay/reset, request fixed terms/underfunding, reserve/settle/refund/departure/role races | Existing completion, room, wallet and reward integration/concurrency suites; provider-equivalent worker assertions |
| DB-04 / T0/T6/T7 | Tombstones and real account erasure across all WP0-WP8 tables, including departed actors, wishes, preferences, queued changes and same-UID non-rebinding | Existing erasure/departure and digest suites; no identifiers resurrected through receipts/history |
| DB-05 / T0/T4/T6/T7 | Competing first wallet/occurrence creation, completion vs correction/reset, fulfillment vs correction/cancel/role/departure, competing digest claims; injected failure after first wallet and room/request writes rolls everything back | Existing house mutation, completion, reward, pause/planner and digest race suites; real two-connection barriers |
| UI-01 / T12 | Owner/member/child My Day; task/date visible; fixed versus relative rhythm; free/paused/pending states; one catch-up card; agreed handover; recorded overload without extra offers | `core-household-journey.spec.ts`, `family-core-ux.spec.ts`, `family-rhythm.spec.ts`, `calm-core-ui.spec.ts` |
| UI-02 / T12 | Stale concurrent drafts/version 409, uncertain-command replay, safe older-completion correction preserving later effects; shared-device reset | `workflow-state-reconciliation.spec.ts`, `family-core-ux.spec.ts`, `family-rewards.spec.ts` |
| UI-03 / T7-T12 | Quiet opt-ins/opt-out, no free/paused task reminders; catalog permanently off core journey; existing requests accessible while browsing hidden; owner/child/adult actions and no foreign details | `quiet-digest.spec.ts`, `ranking-consent.spec.ts`, `family-reward-api.spec.ts`, `family-rewards.spec.ts` |
| UI-04 / T9/T11/T12 | Desktop/mobile layouts, keyboard-only core and request flows, focus/status/error clarity and reduced-motion override | Named UI specs with explicit viewport/keyboard assertions, not merely mobile screenshots |
| HUMAN-01 / C5j | At least one adult and one child understand and operate essential flows without operating help | Sanitized manual record in release test plan; not substitutable by UI automation |
| HUMAN-02 / C5j | Two weeks voluntary everyday use with anonymous pressure/organizing feedback and resolved comprehension/pressure findings | Dated 14-day observation record and final evaluation; missing testers keeps this open |

The DB rows are required on all three providers. A successful isolated SQLite
lock helper or MariaDB-only race is not equivalent to this production-path gate.
The T0-T12 audit must name any missing test and author it before claiming coverage.

### 4.2 Runtime Gate Procedure (After Activation)

1. Freeze the candidate code/fixtures/discovery and record revision plus any
   uncommitted diff identity. Build the production frontend and test image.
2. Validate companion profiles with `docker compose config`; create each owned
   project from empty volumes. Run backend unit and required integration tests
   via `exec -T`, with `--fail-on-skipped`, plus `composer cs:check`.
3. On the canonical MariaDB family environment, coordinate exclusive access;
   seed through the existing `occ chorestrike:test-data:seed --profile=family`
   path and disable AJAX cron via `occ background:cron`. Never overlap integration
   and browser runs on the same DB; use only declared, owned reset boundaries.
4. Run `npm run test:e2e:gate` twice consecutively. Both runs must independently
   discover and execute all three pools with zero retries/skips/failures/flakes.
   A subset rerun cannot erase a failed full gate. A failure or intervening
   runtime/fixture/discovery change restarts the required two-pass sequence.
5. Record run IDs, candidate identity, commands, versions, scope, totals, exit
   statuses and limitations in a sanitized public evidence summary. Raw browser
   reports/traces stay in gitignored `build/e2e-gate/<run-id>/`, never Git/public
   evidence; necessary sensitive human notes stay only in `/private/`.

Proposed matrix command interface (not runnable in this drafting round):

```powershell
docker compose -p cs-wp9-<run-id> -f docker/compose.test-matrix.yml --profile pgsql up -d --build
docker compose -p cs-wp9-<run-id> -f docker/compose.test-matrix.yml exec -T nextcloud-pgsql vendor/bin/phpunit --configuration phpunit.integration.xml --fail-on-skipped
docker compose -p cs-wp9-<run-id> -f docker/compose.test-matrix.yml down -v
```

Replace the placeholders with the owned run identity; repeat with `mysql` /
`nextcloud-mysql` and `sqlite` / `nextcloud-sqlite`. Final validated readiness,
unit, schema and cleanup commands belong in permanent testing documentation.
Use the canonical MariaDB command path unchanged for its ordinary build gate.

Additional gates: `npm test -- --runInBand`, `npm run lint`, `npm run stylelint`,
`npm run test:noise`, `npm run build`, `npm run l10n:check-keys`,
`node scripts/run-e2e-gate.js --self-test`, `npm run docs:encoding`,
`npm run backlog:check` and `git diff --check`. Do not run repository-wide
write-mode formatters over unrelated dirty sources. Add regression tests for
every defect fixed; update l10n together if any user-facing wording changes.

### 4.3 Human Protocol and Evaluation

After technical readiness and separately authorized consent coordination, give
each participant neutral scenario prompts. Guided means presenting the scenario,
not operating the UI or telling them where to click. Record unaided success,
uncertainty, requested assistance or inability honestly.

- Both find their next agreed chore and explain its planned date/window.
- Both explain confirmed/pending rhythm, the weekly milestone and a neutral
  free/paused day without treating neutrality as a penalty or fake success.
- Both disable reminders and optional areas themselves; basic planning remains usable.
- Use synthetic cases for pause, catch-up, owner-recorded agreed handover and
  child request/owner approval/adult fulfillment, including cancellation and
  hidden-catalog request access. Explain effort-only Coins and locked prices.
- Never provoke real missed chores, illness, overload, spending or unwanted
  notifications to obtain evidence. Keep real everyday use voluntary for 14 days;
  withdrawal is permitted and must not be converted into fabricated success.
- At the end, record anonymous feedback on pressure and organizing effort,
  optional-feature choices and comprehension. No activity target or minimum
  number of chores is imposed. Neutral days are valid observed days.

Comprehension failures or pressure problems become plan-owned findings. Resolve
the cause and repeat affected comprehension checks; material behavior changes
require reassessing which observation evidence still represents the final build
and renewing affected technical gates. Missing consent/testers, withdrawal that
leaves the minimum evidence unmet, or an incomplete 14-day period blocks G3.

## 5. File Manifest

Drafting changes: this new child; parent next-child/WP9 links; CONCEPT-001 linkage;
documentation map; live registry. No runtime, schema, tests or l10n changes now.

Implementation manifest below applies only after activation. `M` entries are
bounded by missing coverage/defects, not permission for broad refactoring.

| Kind | Paths | Purpose |
|---|---|---|
| N | `docker/compose.test-matrix.yml` | Isolated supported-provider environments |
| M | `docker/test-entrypoint.sh`, `docker/Dockerfile.test` | DB-selectable real installation/readiness and verified drivers |
| N | `tests/integration/Db/SupportedDatabaseSchemaTest.php` | Real migrated-schema/provider/default/index assertions |
| M | `tests/integration/IntegrationTestCase.php`, existing provider-specific tests/worker bootstraps under `tests/integration/` | Portable fixtures, cleanup and equivalent production-path races; exact touched files pinned by the initial audit |
| N | `tests/integration/Support/{QueryBarrier,QueryBarrierTest}.php` | Bounded real-query/commit barriers, normal worker exit and negative controls |
| N | `tests/integration/Support/FixtureCleanupTest.php` | Full-table before/after isolation checks through real fixture and race-suite teardown |
| M | `tests/integration/FamilyIntegrationTest.php`, `tests/integration/Setup/{HouseSetupIntegrationTest,SetupApplyConcurrencyTest}.php` | Preserve ownership/discovery before cleanup; isolated setup controller routing |
| M | `lib/Service/TestDataService.php`, `lib/Command/TriggerJobs.php`, `tests/fixtures/FamilyTestData.php`, `tests/fixtures/TestDataFixtures.php` | Only verified fixture/clock/seed defects |
| M | `tests/e2e/fixtures/{auth-contexts,database-fixtures,occ-runner,test-house}.ts`, `tests/e2e/fixtures/house-clock.js` | Deterministic identities, reset boundaries and clocks |
| M | `tests/e2e/{core-household-journey,family-core-ux,workflow-state-reconciliation,family-rhythm,quiet-digest,calm-core-ui,ranking-consent,family-reward-api,family-rewards}.spec.ts` | Missing mapped T12/integrated assertions only |
| M | `tests/e2e/required-scenarios.json`, `playwright.config.ts`, `scripts/run-e2e-gate.js` | Required discovery, pool ownership and gate self-test coverage |
| M | `docs/engineering/testing.md`, `docs/testing/release-test-plan.md`, `docs/standards/guidelines/testing.md`, `docs/standards/ai/skills/docker-dev/SKILL.md` | Validated matrix commands, no-skips consistency, human evidence and limits |
| M | This child, CS-PLAN-070, `docs/backlog/{ACTIVE_TASKS,backlog}.md`, `docs/README.md` | Evidence, findings and honest lifecycle/owner status |
| Conditional M | Existing `lib/`, migration, `src/`, unit/integration tests, `l10n/`, concepts/API/ADR files owning an observed defect | Fix root cause with test and durable docs; enumerate exact paths in the finding before editing |
| Completion only | `docs/backlog/archive/YYYY-MM.md`, review record for the actual review date | Completed owner archive and final review evidence, only after all gates pass |

No archive/retirement is planned. No new production schema/table is needed unless
an evidenced defect requires correcting the existing fresh-install chain.

## 6. Implementation Order

1. Standard A reviews this draft, matrix isolation/coverage and family boundary;
   resolve any questions together before the normal `new/ -> active/` transition.
2. Inventory actual T0-T12 tests and RF-070-01..17; record a scenario/provider map,
   exact repair paths and renewed effort estimate if needed (0.5-1 day).
3. Implement isolated DB provisioning/schema checks and portable test workers;
   fix evidenced production defects with regressions; run all provider gates
   (1.5-2.5 days, initial image builds add wall-clock time).
4. Close T12 coverage/discovery gaps, integrate durable technical docs and run
   all quality gates plus two consecutive full browser passes (1-1.5 days).
5. Prepare the human record, then obtain separately authorized coordination and
   consent; run comprehension checks and 14 days voluntary use. If unavailable,
   retain an explicit human-evidence blocker; do not silently pause/move/complete.
6. Resolve findings, evaluate family results and final-build representativeness,
   reconcile remaining parent RF-070 items and durable docs (1-1.5 days).
7. Request final Standard B or Full review for child and parent closure evidence.
   Only a passing review with all gates complete may move completed plans to
   `done/` and archive CONCEPT-001; broader backlog remainders stay open.

The estimate excludes a major cross-DB redesign. Discovery of one requires an
explicit estimate/scope decision, not a weaker test gate or fictional deadline.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| Fixed compose names/ports reset another session's DB | High | Independent companion, unique project identity, isolated volumes/networks, owned cleanup only |
| Non-MySQL skips masquerade as portability | High | Provider/scenario map, real Nextcloud installation and worker connections, no unmapped exclusions, fail-on-skipped |
| SQLite toy lock test misses real aggregate failures | High | On-disk installed DB and service-level interleavings, final invariant/rollback assertions |
| Green runs test stale frontend or seeded outcomes | Medium | Build/candidate identity, seed audit, frozen discovery, exclusive DB use and declared reset boundaries |
| Family evidence coerces or identifies a child | High | Separate authorization and guardian consent, voluntary withdrawal, synthetic edge cases, public anonymity and private boundaries |
| Missing family testers silently closes parent | Medium | Distinct technical/human status; leave G3 open unless explicit user rescoping changes the contract |
| Parent historical findings are checked off by inference | High | Item-by-item delivered test/runtime evidence and whole-item final review; no blanket completion |
| Trial observes an obsolete build | Medium | Record build identity; reassess affected observation and rerun technical gates after material fixes |

## 8. Acceptance Criteria

- [x] Standard A passes; all exposed review questions/decisions are recorded.
- [x] T0-T12 and all remaining parent RF-070 items have exact evidence ownership;
  no still-required gap is hidden in another backlog item.
- [x] Fresh full schema/family fixtures and DB-01..05 proofs pass on MariaDB,
  PostgreSQL and SQLite through real Nextcloud connections, with no unmapped
  exclusions or new/unapproved skips; unrelated environments remain intact.
- [x] Required backend/Jest/lint/stylelint/noise/build/l10n/documentation gates pass.
- [x] UI-01..04 are mandatory in discovery and pass; desktop/mobile/keyboard and
  disabled optional-feature core usage are explicitly demonstrated.
- [x] Two consecutive complete three-pool browser gates pass on the same final
  runtime/fixture/discovery candidate, zero retries/skips/failures/flakes.
- [ ] Consent-confirmed minimum adult/child comprehension evidence is complete,
  including unaided essential operation and optional-feature opt-out.
- [ ] Fourteen days voluntary everyday use and anonymous pressure/organizing
  feedback are recorded; comprehension/pressure findings are resolved, not assumed away.
- [x] Public evidence contains no identifying/sensitive human data or raw traces;
  artifact locations and final-build applicability are recorded honestly.
- [ ] All plan-owned findings and parent whole-item blockers are resolved or
  explicitly user-rescoped; required deferred evidence still blocks completion.
- [x] Durable matrix/protocol/contract outcomes are integrated into permanent docs.
- [ ] Final Standard B or Full review passes for complete WP9 and parent G3;
  plans/tracking and CONCEPT-001 archive reflect actual completion. Technical
  green alone does not authorize closure.

## 9. Review Findings (Plan-Owned)

Standard A passed on 2026-10-03. Cross-DB provisioning and human evidence
remain required deliverables, not already resolved findings. Record new in-scope
findings here with severity, exact file/area, evidence and resolution; reconcile
parent RF-070 entries in the parent without creating duplicate backlog owners.

- [x] **RF-080-01 major:** DB-selectable, isolated provisioning and fail-closed readiness implemented. Owned empty-volume MariaDB/PostgreSQL/SQLite installs in `cs-wp9-20261005b` reached healthy after required family seeds. `TestEnvironmentProvisioningTest` passes 18 cases (245 assertions), including selector/profile refusal and install/provider/app/HTTP/seed failure refusal; this is provisioning evidence, not DB-02..05 acceptance.
- [x] **RF-080-02 major:** all remaining 19 guards / 21 skipped cases in the seven worker files are ported. The original scenarios execute through independent installed Nextcloud connections, production mapper locks (SQLite first-write serialization), typed timestamps/Booleans and bounded query-attempt barriers with normal worker-exit checks. Two real-service pairs additionally hold before their actual outer commit to prevent sequential false positives; the focused independent recheck found no remaining issue in that delta. Final full integration: 593 tests on each provider, zero skips/errors/failures. This closes race portability, not RF-080-10 fixture isolation or whole WP9 acceptance.
- [x] **RF-080-03 major:** `AssigneeRepresentationTest`, `FamilyRhythmSchemaTest` and `SupportedDatabaseSchemaTest` pass on all three installed providers. Nextcloud schema abstractions verify all 30 migrated tables, applied versions, columns/defaults/indexes, provider/on-disk SQLite identity, repeated family seeding and wallet/quiet defaults. PostgreSQL Boolean metadata is accepted in its actual typed representation, not cast to a MySQL string.
- [x] **RF-080-04 major:** all six remaining browser proofs now join the earlier catch-up/handover and DE/EN evidence: neutral empty day; actual fixed/relative explanations and different successors after late work; fresh same-context logout/login with live prior undo and isolated wallet/preferences; opted-in free/paused reminder suppression with a working-member control; Tab/Enter/Space checklist and completion execution; reduced-motion suppression with a live opted-in animation control. Five new scenarios and the strengthened existing child-checklist scenario pass the complete affected specs (34 scenarios plus three setup tests) and full gate `run-7H4sub`. Discovery pins every identity without removing scenarios or lowering minimums. Independent focused checking identified two weak evidence setups, both fixed and rechecked; no remaining finding in that delta. This closes automated T12 gaps, never HUMAN-01/02 or whole WP9.
- [ ] **RF-080-05 block:** HUMAN-01/HUMAN-02: coordination, guardian consent and minimum adult/child voluntary participation confirmed on 2026-10-07; only anonymous results may enter the repository. Release test plan section 12.1 records the decision, start checklist and unfilled execution record. Actual candidate/environment, comprehension results and 14-day evidence remain unconfirmed. Leave WP9/G3/CONCEPT-001 open until actual evidence or explicit user rescoping; authorization alone is not a test pass or retained-baseline adoption.
- [x] **RF-080-06 major:** `RoomTaskChecklistProgressMapper` now protects the speculative insert with a savepoint and re-locks the winner before flipping. The reproduced PostgreSQL SQLSTATE 25P02 is fixed; real two-process/outer commit/outer rollback regressions pass on all three providers. Unit cases preserve the original non-unique DB exception and unexpected non-DB exception without further SQL. The focused independent read-only check's test-gap finding was fixed in-round; no production finding remained in its inspected scope.
- [x] **RF-080-07 major:** `TaskMapper`, `DigestSlotMapper`, `CompletedTaskMapper` now use typed Boolean EXISTS comparisons, nullable timestamp checks and deterministic list-only activity ordering. All affected real-DB authorization, digest, activity and erasure tests pass on every provider; no predicate or visibility rule was weakened.
- [x] **RF-080-08 major:** the optional closed-week seed places the still-uncompleted/unpaused overdue example before the synthetic completed week on every weekday. `TestDataSeedTest` pins this separation. `CompletedTask::occurrenceDue` declares the schema's date type, and raw timeline/rhythm fixture queries use half-open day boundaries because Nextcloud 31 binds mutable dates as datetime parameters on SQLite. The 48-test seed/timeline/rhythm/digest subset passes on every provider; entity metadata is pinned by unit regression.
- [x] **RF-080-09 major:** Digest workers now bootstrap and capture their stale input before the parent acquires its lock; a bounded two-way go/attempt barrier starts their mutation while it is held. All six scenarios pass on SQLite as well as MariaDB/PostgreSQL, preserving competing results, stored effects and notification-outside-transaction assertions. No wait-duration increase or provider skip was used.
- [x] **RF-080-10 major:** base teardown now discovers owned service/worker-created descendants before deleting parents, then removes completion shares/revisions, all owned room journal events, checklist/timeline and wallet facts in dependency order. Premature cleanup in completion/family/setup suites is removed; new setup task definitions are explicitly registered, never inferred from a shared mount. Setup controller tests use an isolated house rather than purging the instance household. Nine `FixtureCleanupTest` cases execute real suite teardown and compare all 30 app tables, proving no orphan side rows, preservation of unrelated fixtures/shared definitions, and repeat/empty cleanup. All cases and the complete 602-test suite pass on every provider; focused independent read-only review found no issue in this delta.

- [x] **RF-080-11 major:** the existing owner agreement form now records a consent-confirmed, full-responsibility handover of one occurrence through the delivered participant-amendment API. `TaskListItem` forwards the already-loaded member list to the details form (previously omitted even for assignment chips). Component/store regressions and the mandatory mobile browser journey preserve the receipt, current authority/capacity checks and future defaults; DE/EN artifacts and actual Nextcloud rendering pass. No new backend command or domain rule was introduced.

- [x] **RF-080-12 major:** the existing batched occurrence-member read now adds nullable `proposal.obligatedUserIds`, separating concrete active obligations from future defaults and voluntary actual-work shares. Child eligibility and My Day use it without extra DB queries or changed visibility rules. Agreement success/409 refresh the loaded room receipt; a committed write remains successful when a refresh fails, with a visible warning. Independent checking exposed a successor-retargeting risk: changed occurrence identity now clears all drafts/consent with an explicit warning, while same-identity conflicts preserve them. Component/store/read regressions, a real-service child/helper case on all three providers and the browser's second same-view amendment cover the repair. Focused independent recheck has no remaining finding; API/use-case docs own the durable contract.

- [x] **RF-080-13 major:** full interim browser gate `run-KYwj6N` exposed premature task creation and six owner-scheduling readiness failures. The bounded `TaskManager.vue` initialization guard and loaded-content scheduling assertions retain their regression tests and unchanged timeouts; the discarded store-wide journal remains absent. Complete rerun `run-L6t9N6` passes all three pools with zero skips/failures/flakes, closing the previously incomplete `run-bCrhwS` handoff. The later full `run-7H4sub` also passes with the expanded T12 inventory. Earlier failed/incomplete attempts remain historical evidence, not qualifying passes.

## Implementation Evidence

### Installation-Preparation Renewal (2026-10-08)

The [scoped candidate record](../../testing/install-preparation-2026-10-08.md)
separates new NC34 and NC35 browser evidence, fingerprinted pre-commit source/package
hashes, dependency disposition and native platform limitations. It also retains
the failed room-order snapshot and mobile-overflow attempts and their minimal
test/CSS repairs. Final NC34 pair `run-X1Q29H` + `run-xhZQwU` and NC35 pair
`run-qJq5WQ` + `run-FvG4KC` each pass 290 first-attempt executions per run with
zero skips/failures/flakes; the same extracted production archive also passes
desktop/mobile smoke on both rows. No historical NC35 run or backend-only NC34
result is relabelled as current NC34 browser proof.
The explicitly deferred cross-cutting tooling work belongs to RELEASE-002.
RF-080-05, comprehension/14-day evidence and baseline adoption remain open.

### Human Evidence Coordination (2026-10-07)

- The user authorized coordination, confirmed guardian consent and subsequently
  confirmed voluntary participation meeting the adult/child minimum. Public
  evidence records only that confirmation and anonymous adult/child outcomes;
  no personal relationship details, identifiers or raw statements are copied.
- The existing [execution record](../../testing/release-test-plan.md#121-family-acceptance-evidence-c5j--wp9)
  now separates confirmed authorization/participation from pending
  candidate/environment selection, comprehension and actual observation dates.
  No comprehension result or observation day has been supplied or inferred.
- The operator conducts the neutral scenarios and supplies sanitized outcomes.
  No automated recruitment, recording, telemetry or sensitive-note collection
  is authorized or started. Private raw-note collection would need separately
  agreed collection/retention limits; none is needed for the prepared record.
- Technical evidence remains the recorded WP9 matrix/browser gates plus the
  renewed runtime qualification in CS-PLAN-082, not a new execution claim.
  Record the actual trial build/runtime and assess its applicability before use.
  Real retained data requires the separate [baseline adoption](../../engineering/deployment.md#first-retained-baseline)
  decision; the disposable canonical test stack is not a retained family instance.
- Next: confirm the actual trial environment and remaining start checks, perform
  HUMAN-01, then record the actual 14-day HUMAN-02 period and anonymous final
  feedback. Keep RF-080-05, parent whole-item closure and final review open.
  No deployment, adoption or lifecycle transition is performed here. The user
  separately requested a documentation commit/push and a bounded public-entry
  description refresh; this does not authorize a forge migration or trial start.
- Validation: `npm run docs:encoding`, `npm run backlog:check` and
  `git diff --check` pass for this documentation-only slice. Before the requested
  publication, `npm run lint:fix` passes without source changes on Node 20.19.0;
  `npm test -- --runInBand` passes 113 suites / 1466 tests. The printed Composer
  stale-lock messages are explicit mocked upstream-warning cases in
  `testRuntime.spec.js`, not a new installation failure. No runtime, test,
  fixture or build inputs changed; backend/browser gates were not rerun and no
  renewed runtime qualification or human pass is claimed.

### Baseline Refresh (2026-10-05)

- Runtime baseline: `bd7ba8c` (`fix(hardening): finalize CS-PLAN-081 readiness review`).
  The index is empty; the only pre-existing changes are the unrelated deletions
  of `docs/plans/{active,new,onhold}/.gitkeep`, preserved untouched.
- Docker daemon is reachable (29.8.0). Canonical `chorestrike-test` and
  `chorestrike-db` are healthy; unrelated containers exist and are outside WP9
  ownership. Matrix work must neither reset nor remove these environments.
- The drafting provisioning limitations still exist: MySQL-only entrypoint,
  permissive seed failure and early HTTP-only readiness, no matrix companion.
  The current test image additionally has APCu/native-rate-limit configuration
  from CS-PLAN-081; preserve it on every provider.
- Backend portability and browser/parent evidence inventories are running as
  independent internal read-only audits. Their exact mappings precede accepting
  any provider-tailored suite or claiming T0-T12/whole-item RF closure.
- Human gates remain open: implementation does not authorize recruitment,
  consent collection, personal notes, telemetry or a family trial. Technical
  evidence cannot substitute for HUMAN-01/HUMAN-02 or close WP9/G3/CONCEPT-001.

### Pre-Execution Coverage Inventory (2026-10-05)

This is coverage ownership and a repair inventory, **not a runtime pass**.
All providers will run the same full unit/integration configurations with
`--fail-on-skipped`; no provider exclusions or substitute tailored suites are
accepted. Existing test identities below retain their assertions, with the
SQLite worker acquiring a real installed-DB write before its signal, PostgreSQL
using independent connections, and MariaDB retaining its original proof.

| Provider repair owner | Exact existing scenario identities (class prefix omitted inside the cell) | MariaDB / PostgreSQL / SQLite disposition |
|---|---|---|
| `Db/BootstrapConcurrencyTest.php` | `testConcurrentCreateForInstance_ExactlyOneHouse`; `testConcurrentEnsureHouseMember_ExactlyOneOwner_LoserDenied`; `testConcurrentEnsureHouseMember_ExactlyOneMembershipAfterRace`; `testCreateOrGet_RaceLoserReceivesExistingRow` | Same scenarios on all three; pin unique-insert loser convergence and house-first winner visibility |
| `Db/ChecklistToggleConcurrencyTest.php` | `testToggle_TwoConcurrentFirstToggles_SingleRowFlippedOnce` | Same occurrence-scoped first-toggle race on all three |
| `Setup/SetupApplyConcurrencyTest.php` | `testConcurrentApplyRace_ExactlyOneStructure_LoserGetsConflict`; `testRemovedMemberAssigneeDuringApply_Yields400_AndZeroWrites` | Same aggregate/assignee race on all three |
| `Service/ConcurrentCompletionTest.php` | `testFindForUpdateBuildsOnCommittedWinnerState`; `testCompleteRoomTaskAfterConcurrentWinner_NoSpuriousTakeover`; `testTwoUsersSameRoomConcurrentCompletions_SumOfBothDeltasLand` | Same committed-state/no-lost-update proof; SQLite serialization rather than row locks |
| `Service/CompletionConcurrencyTest.php` | `testSameRoomCompletion_BlockedOnRoomLock_BuildsOnCommittedWinnerCleanliness`; `testConcurrentUndo_LoserOfRevertibleRace_IsIdempotentZeroWriteNoOp` | Same persisted-room/targeted-undo invariants; replace worker `NOW()` |
| `Service/HouseMutationConcurrencyTest.php` | `testCompleteVsMemberRemoval_MemberRemovedBehindHouseLock_Yields403ZeroWrites`; `testCompleteVsComplete_BehindHouseLock_CommittedWinnerYieldsOccurrenceConflict409`; `testDeleteRoomTaskVsCompletion_RoomTaskTombstonedBehindHouseLock_Yields409ZeroWrites`; `testConcurrentMembershipCreation_MembershipCreatedBehindHouseLock_OneWalletExactly` | Same house-first authorization/identity proofs; replace `NOW()`/`CURDATE()`; existing raw-PDO SQLite test remains supplemental only |
| `Service/SchedulePlanConcurrencyTest.php` | `testTwoAppliesOppositeInputOrder_SerializeWithOneCleanConflict`; `testApplyVsConcurrentDueDateEdit_BlockedOnRowLock_RecomputesAndConflicts` (all existing holder-mode data rows) | Same stale/conflict and holder-commit/normal-exit controls on all three |
| `Service/TaskCreationConcurrencyTest.php` | `testConcurrentMountRace_ExactlyOneRow_LoserGets400`; `testRemovedMemberDuringCreate_Yields400_AndZeroWrites` | Same no-duplicate/no-orphan proof on all three |
| `Service/PauseAgreementConcurrencyTest.php` | `testPlanApplyVsPause_SerializesIntoOneConsistentState`; `testDuplicateRestarts_ExactlyOneSuccessor`; `testConcurrentShortenings_SameExpectedRevision_OneStaleConflict`; `testReplayedReceiptUuid_TwoConnections_SingleEffect`; `testInjectedFailureAfterPauseInsert_RollsBackEffectsAndReceipt` | Remove shared provider refusal; preserve all race/rollback scenarios |
| `Service/RewardConcurrencyTest.php` | `testFulfillVsCancelRace_ExactlyOneFinancialEffect`; `testConcurrentOwnerCancelVsRoleRebase_BothSerializeCoherently`; `testMidAggregateFailure_RollsBackWalletLedgerRequestAndVersion`; `testRefundRetries_AreIdempotentOnTheSameUuidAndConflictOtherwise` | Remove shared provider refusal; preserve all money/role/rollback scenarios |

Provider-independent `DigestConcurrencyTest` and `OwnerCommandAuthorizationTest`
already provide service-level pipe barriers/SQLite first-write templates. They
remain mandatory alongside the adapted scenarios, not substitutes for them.
Fixture cleanup uses QueryBuilder deletes and returned table-aware IDs; audit
also identified service-created side-row leakage for verification during runs.
Owned empty-volume installation is the fresh-run boundary; no existing volume
start or base fixture cleanup is claimed to prove a fresh installation.

| Group / gates | Delivered test ownership to execute (existing unless noted) | Remaining WP9 work |
|---|---|---|
| T0 / DB-01,04,05 | `FamilyRhythmSchemaTest`, `HouseMutationConcurrencyTest`, `AccountErasureTest`, `RoleGuardApiTest`, `InviteOnlyMembershipTest`; new `SupportedDatabaseSchemaTest` | Whole-chain/schema/provider/family guard, portable workers and all-provider evidence |
| T1 / DB-02,05 | `OccurrenceServiceTest`, `OccurrenceTimelineTest`, `CalendarServiceTest`, `ChecklistToggleConcurrencyTest` | Full-provider anchored/interval/saved-zone/window/receipt assertions |
| T2 / DB-02,05 | `AgreementServiceTest`, `PauseServiceTest`, `PauseAgreementTest`, `PauseAgreementConcurrencyTest`, `SchedulePlanAggregateTest`, `RoleGuardApiTest` | Portable pause/planner races; integrated handover/neutral browser evidence |
| T3 / DB-02,03,05 | `EffortAllocationServiceTest`, `SchedulePlanServiceTest`, `SchedulePlanConcurrencyTest`, `WorkShareMapperEnergyTest`, `CompletionCorrectionTest` | All-provider exact conservation/capacity and rollback evidence; the drafting `TeamworkRewardTest` pointer was stale, not a missing required suite |
| T4 / DB-03,05 | `CompletionUndoStateEqualityTest`, `CompletionConcurrencyTest`, `ConcurrentCompletionTest`, `CompletionCorrectionTest`, `RoomReplayServiceTest`, `RoomReplayConcurrencyTest` | Portable races, older correction preserving later effects, whole-aggregate failure assertions |
| T5 / DB-02 | `RhythmServiceTest`, `WeeklyGoalServiceTest`, `RhythmProjectionTest`, `WeeklyGoalClosureTest`, `MedalServiceTest`, `TitleServiceTest` | Chronological/provisional/neutral all-provider execution |
| T6 / DB-03,04,05 | `WalletServiceTest`, `RewardServiceTest`, `RewardRequestServiceTest`, `RewardLifecycleTest`, `RewardConcurrencyTest`, `HouseMemberRemovalIntegrationTest`, `UserControllerIntegrationTest` | Real portable lifecycle/money/role/departure races and erasure |
| T7 / DB-04,05, UI-03 | `DigestServiceTest`, `DigestScheduleServiceTest`, `DigestDeliveryTest`, `DigestConcurrencyTest`, `TimezoneDayBucketingTest`, `quiet-digest.spec.ts` | All-provider gates plus explicit free/paused browser reminder suppression |
| T8 / DB-02,04, UI-02,03 | `RankingServiceTest`, `RankingConsentTest`, `PreferenceServiceTest`, `preferences.spec.js`, `user.spec.js`, `ranking-consent.spec.ts` | Same-device identity reset; privacy/consent on every provider |
| T9 / UI-01,02,04 | `TodayView`, `SchedulePlanner`, `RecurrencePresetSelector`, `TaskList`, `TaskChecklist`, `UndoSnackbar`, `ParticipationDialog`, `PauseSettings` Jest suites; `calm-core-ui.spec.ts` | Free-day/fixed-relative/core keyboard/reduced-motion runtime proof |
| T10 | `MinigameServiceTest`, `MinigameControllerIntegrationTest`, `MinigameScoreMapperTest`, `ArcadeView.spec.js`, `SnakeLeaderboard.spec.js`, `snake-game.spec.ts` | Preserve CS-PLAN-081 Snake ordering and no-Coin/no-extra-game contracts; replace stale drafting pointers with actual delivered owners |
| T11 / UI-03,04 | `rewards.spec.js`, `Rewards.spec.js`, `RewardRequestList`, `RewardRequestDetails`, `RewardCatalogEditor` Jest suites; `family-reward-api.spec.ts`, `family-rewards.spec.ts` | Preserve fixed terms/hidden promises/role privacy, verify true keyboard flow |
| T12 / UI-01..04, HUMAN-01,02 | `core-household-journey.spec.ts`, `family-core-ux.spec.ts`, `workflow-state-reconciliation.spec.ts`, `family-rhythm.spec.ts`, `quiet-digest.spec.ts`, `calm-core-ui.spec.ts`, reward/ranking specs; release test plan | RF-080-04 browser gaps, final-candidate consecutive full pair, separately authorized human evidence |

The parent's original N/M labels are historical. RF-070-07/08/10/14 are already
closed; RF-070-01/02/03/04/06/09/11/12/15 retain delivered WP1-WP8 ownership
through the rows above but are not checked off by inference. RF-070-05's Snake
ordering remainder is delivered by CS-PLAN-081; RF-070-13's catalog UI remainder
is delivered by CS-PLAN-079. RF-070-16/17 required DB-04 cross-provider erasure at
inventory time; the later `cs-wp9-20261005e` full-provider reports include the
actual tombstone, reward erasure and same-UID non-rebinding cases. The parent's
technical evidence reconciliation below its findings supplies exact ownership.
Whole-item verdicts remain with the parent final review and human gate.

Effort remains provisionally the approved 4-6 technical days plus 14 calendar
days: PostgreSQL repairs appear test-side/mechanical; SQLite interleavings and
validated missing UI seams are the concentration risk. No production redesign,
weaker gate, deadline claim or human-evidence rescoping is assumed.

### Provisioning and First Portability Repairs (2026-10-05)

Continuation preserved the existing WP9 edits and unrelated `.gitkeep`
deletions. The older `cs-wp9-20261005a`, canonical MariaDB and unrelated Docker
environments were not reset, recreated or removed. Project
`cs-wp9-20261005b` had no pre-existing volumes and independently installed all
three providers through the shared entrypoint, app migrations and family seed.

- Image: `chorestrike-wp9-test:local`, identity
  `sha256:681fcd58637874c5270aaaeab5b3cf1a7f79871c135d970c913060ecadf0c145`.
- Runtime: Nextcloud `31.0.14.1`, PHP `8.2.34`; PDO drivers `sqlite,mysql,pgsql`.
  Databases: MariaDB `10.6.25`, PostgreSQL `16.14`, SQLite `3.46.1`.
- Companion validation: all three profiles passed `config --quiet`;
  sequential `up -d [--build] --wait --wait-timeout 600` passed for each provider.
- Selector/readiness unit regression: 18 tests / 245 assertions, no skips;
  Bash syntax validation passed for the entrypoint and command-double fixture.
- The first full matrix (`--fail-on-skipped --order-by=default
  --do-not-cache-result`) exposed real gaps, not accepted exclusions:
  MariaDB 586 tests / 4862 assertions / 1 failure; PostgreSQL 586 / 4627 /
  22 errors / 8 failures / 21 skipped cases; SQLite 586 / 4702 /
  13 failures / 21 skipped cases. These attempts remain failed evidence.
- Repaired production queries: typed Boolean EXISTS comparisons, nullable
  dispatch timestamp checks, activity COUNT without list sorting, and stable
  same-second activity ordering. The PostgreSQL first-toggle race additionally
  required a savepoint and locked winner re-read; real-DB regressions prove
  both outer commit and rollback without masking unrelated exceptions.
- `RewardConcurrencyTest`, `PauseAgreementConcurrencyTest` and
  `ChecklistToggleConcurrencyTest` now execute on every provider. The seven
  other RF-080-02 race files retain their unresolved provider guards; their
  21 skipped cases still prevent full matrix acceptance.
- Workflow budgets retain the existing MariaDB ceilings. Tests separately
  assert exact PostgreSQL identity-read overhead (1/8/3) and SQLite lock-write
  overhead (2/7/7) for due-date/completion/undo, preserving exact lock/result
  checks and the single post-commit authoritative read.
- Combined repaired schema/race/query/erasure subset: **86 tests** each;
  MariaDB/PostgreSQL **2105 assertions**, SQLite **2108**, zero skips/errors.
  Local JUnit evidence: `build/wp9-20261005b/{mysql,pgsql,sqlite}-focused.xml`.
  This subset diagnoses/validates repairs, never substitutes for the full gate.
- Final full unit configurations: **1334 tests / 4500 assertions** on each provider,
  no skips. Jest: **109 suites / 1230 tests**; missing Settings child-component
  doubles were repaired rather than suppressing Vue warnings; noise gate green.
  ESLint, stylelint, build, l10n (1270 keys), gate-runner self-test, PHP coding
  standards, encoding/backlog checks and `git diff --check` passed at this stage.
- Durable matrix commands/limits are integrated into engineering/testing and
  Docker/testing guidance; savepoint semantics into engineering/development;
  the unfilled human record into release-test-plan section 12.1. No browser
  pair, human result, final review, plan transition, commit or adoption is claimed.

### Second Full Matrix and Handoff (2026-10-05)

After the query repairs, closed-week fixture separation, raw DATE-boundary
corrections and two-way digest worker barrier, the same complete integration
configuration was rerun with `--fail-on-skipped --order-by=default
--do-not-cache-result` (no filters/exclusions):

| Provider | Discovered | Executed without skip | Assertions | Errors / failures | Skipped | Gate outcome |
|---|---:|---:|---:|---:|---:|---|
| MariaDB | 586 | 586 | 4862 | 0 / 0 | 0 | PASS, exit 0 |
| PostgreSQL | 586 | 565 | 4758 | 0 / 0 | 21 | FAIL, nonzero because skips are forbidden |
| SQLite | 586 | 565 | 4761 | 0 / 0 | 21 | FAIL, nonzero because skips are forbidden |

Local reports are `build/wp9-20261005b/{mysql,pgsql,sqlite}-full-2.xml`, with
final unit reports alongside as `*-unit.xml`. The unit mock's missing public
`Entity::getFieldTypes()` accessor was corrected after the second integration
run; it affects the unit bootstrap only, not the installed-DB integration tree.
No previous failing attempt is erased by these later results.

Candidate: `bd7ba8c` plus the retained WP9 working changes. Final tracked
runtime/test diff fingerprint (`git diff --binary -- docker lib src tests
scripts phpunit.integration.xml phpunit.unit.xml | git hash-object --stdin`):
`80b12cb99505125d8f734ad656770fb132b111bd`. New-file Git blob identities:

| File | Blob identity |
|---|---|
| `docker/compose.test-matrix.yml` | `8f1169490afb84bd5fb82be50431d5601e8918cc` |
| `tests/fixtures/provision-command.sh` | `a633c7a5fd570c6c6c36460be15415163c4e4057` |
| `tests/integration/Db/SupportedDatabaseSchemaTest.php` | `2e78f94feba0881d4897412d5016710e31b62063` |
| `tests/unit/Command/TestEnvironmentProvisioningTest.php` | `45f955494b4cb8bc4c273470a6fa89e13b4900ce` |

Next technical slice remains **RF-080-02**: port the 21 cases in
`BootstrapConcurrencyTest`, `CompletionConcurrencyTest`,
`ConcurrentCompletionTest`, `HouseMutationConcurrencyTest`,
`SchedulePlanConcurrencyTest`, `TaskCreationConcurrencyTest` and
`SetupApplyConcurrencyTest`. Replace raw holder locks/timestamps with the
production provider primitives and bounded worker barriers, preserve winner
commit/normal-exit/rollback assertions, then rerun the **full** matrix. Do not
merely remove guards or accept this partial gate. The previous assumption that
PostgreSQL changes would be entirely test-side was disproved by RF-080-06/07;
the bounded repairs above did not require a production redesign.

RF-080-04 T12 gaps, final-candidate consecutive browser pair, canonical MariaDB
browser coordination, HUMAN-01/HUMAN-02 and the final child/parent review remain
open. No parent RF whole-item closure is inferred from these technical results.

All owned PHPUnit/helper processes had exited before cleanup. The qualified
all-profile `down -v` removed only `cs-wp9-20261005b` containers/network/volumes;
the local JUnit reports remain in ignored `build/wp9-20261005b/`. The older
`cs-wp9-20261005a`, canonical containers and unrelated environments remain
untouched. No technical/human gate or plan lifecycle was weakened to end this
implementation round.

### Portable Race Completion and No-Skips Matrix (2026-10-05)

This continuation owns only the new `cs-wp9-20261005c` environment. No volumes
existed for that project before provisioning; all three family installations
became healthy through the shared entrypoint and real migration chain. Existing
WP9 edits and unrelated `.gitkeep` deletions were preserved. Neither the older
`cs-wp9-20261005a` nor the canonical/unrelated Docker environments was reset.

- New work: the seven remaining RF-080-02 worker files, the shared test-only
  `Support/QueryBarrier.php` and seven barrier regression cases; no production
  behavior, schema, UI or localization changed in this continuation.
- The barrier observes actual SQL through the existing `QueryCounter` DBAL
  seam. Holders use production mapper locks and release only at the contender's
  relevant INSERT/lock attempt. Successful cases require persisted effects and
  normal worker exit; missing attempts, abnormal exit, stderr and stalled workers
  are negative controls. Setup/task creation keep their under-lock validation
  checks; the orphan-mount count now checks the whole room, not nonexistent task 0.
- Initial focused attempts exposed an incorrect test helper method name and an
  untyped PostgreSQL Boolean assignment; both test-side defects were corrected.
  A first complete matrix then passed 592 tests per provider (4900 assertions
  on MariaDB/PostgreSQL, 4904 on SQLite). These are retained intermediate results,
  not the final-candidate evidence after the next refinement.
- The independent read-only pass identified that two start-signal-only service
  pairs could pass sequentially. Both helpers now pause at their real outer
  `"COMMIT"`, after their aggregate writes/locks; parents wait for that marker
  and release through their contending house-lock query. No wrapper transaction
  or fake service is introduced. The focused recheck resolved that finding,
  and all three complete suites were rerun, not just the affected subset.

Final commands on each `nextcloud-{mysql,pgsql,sqlite}` service use `exec -T`:

```text
vendor/bin/phpunit --configuration phpunit.unit.xml --fail-on-skipped --do-not-cache-result --log-junit build/wp9-20261005c/<provider>-unit.xml
vendor/bin/phpunit --configuration phpunit.integration.xml --fail-on-skipped --order-by=default --do-not-cache-result --log-junit build/wp9-20261005c/<provider>-full-2.xml
```

| Provider | Full unit tests / assertions | Full integration tests / assertions | Errors / failures / skips | Outcome |
|---|---:|---:|---:|---|
| MariaDB | 1334 / 4500 | 593 / 4906 | 0 / 0 / 0 | PASS |
| PostgreSQL | 1334 / 4500 | 593 / 4906 | 0 / 0 / 0 | PASS |
| SQLite | 1334 / 4500 | 593 / 4909 | 0 / 0 / 0 | PASS |

Runtime: Nextcloud `31.0.14.1`, PHP `8.2.34`, MariaDB `10.6.25`, PostgreSQL
`16.14`, SQLite `3.46.1`. All three installed containers use image
`sha256:8798744d6e1acabb23372918b1781cb8118bab1ae20e67e9495e12f3de26f637`.
Candidate: `bd7ba8c` plus the preserved/current working changes; tracked runtime
and test diff fingerprint (same command as the preceding handoff):
`9f817795d5e2c3cc3280db8dc17bcfaae403c5a0`. The previous four new-file blob
identities are unchanged; the two new support-file identities are:

| File | Blob identity |
|---|---|
| `tests/integration/Support/QueryBarrier.php` | `3f62a2def5b58de6c2564df73349d98b9a0141e7` |
| `tests/integration/Support/QueryBarrierTest.php` | `22c1dc0e884097f4c862a117a749b39df0c951d8` |

Quality gates: Jest **109 suites / 1230 tests** (`--runInBand`) and noise check,
ESLint, stylelint, production build, l10n (**1270 keys**), browser-runner self-test
and PHP coding standards (**0/409** files needing fixes) pass. Frontend gates use
the cached supported toolchain via
`npx --offline --package=node@20.19.0 --package=npm@10.8.2`; the initial host-Node-24
lint deprecation was avoided by rerunning on the supported engine, not suppressed.
The standalone SQLite PDO lock proof remains supplemental; no provider-specific
exclusion or weaker gate was introduced. Durable barrier guidance and the stale
skip inventory were updated in `docs/engineering/testing.md`.

**Next:** RF-080-10 fixture cleanup with isolation regressions, RF-080-04 T12
gaps, then the final-candidate consecutive browser pair and parent whole-item
reconciliation. No browser gate, human participation/consent/trial, formal final
review, lifecycle move, commit, deployment or retained-baseline adoption is
claimed. The green matrix alone does not close WP9/G3/CONCEPT-001.

Final compose validation, documentation encoding, backlog/link checks and
`git diff --check` passed. After all owned test/helper processes exited, the
qualified all-profile `down -v` removed only `cs-wp9-20261005c` containers,
network and volumes. JUnit reports remain in ignored `build/wp9-20261005c/`;
the older matrix and canonical/unrelated environments remain untouched.

### Owned Fixture Isolation and Final Matrix Recheck (2026-10-05)

This continuation resolves RF-080-10 only; it does not implement RF-080-04 or
claim the browser/human gates. Existing WP9 work and unrelated `.gitkeep`
deletions remain intact. No production behavior, schema, UI or l10n changed.

- Central cleanup discovers descendants under tracked houses/rooms before any
  parent deletion, including committed helper mounts and service completions.
  Completion-dependent rows and source-free room baselines/manual events are
  cleared; wallet entries precede wallets. Checklist items belong only to
  explicitly owned definitions, not every definition mounted in an owned room.
- Removed destructive early completion cleanup from `CompletionConcurrencyTest`,
  `ConcurrentCompletionTest`, `HouseMutationConcurrencyTest` and
  `FamilyIntegrationTest`. Both setup suites register newly materialized task
  definitions and leave structure/timeline cleanup to the base. The setup
  controller matrix overrides instance routing only; actual lookup, membership
  authorization and setup continue through production services on an owned house.
- `Support/FixtureCleanupTest` has eight cases invoking real existing tests
  through `runBare()` plus a production-fact fixture scenario. Exact snapshots
  of all 30 tables cover completion/undo shares and revisions, checklist,
  timelines, room events, wallets, worker floors/mounts, foreign households,
  shared definitions and repeated/empty cleanup. All nine cases pass on all
  providers (131 assertions per focused run). The independent focused read-only
  review found no issue; this is not a formal lifecycle review.
- Initial focused verification exposed a test setup error: the new checklist
  mapper call lacked its required transaction. It was corrected through
  `TransactionExecutor`; no production workaround was introduced. PHP coding
  standards subsequently requested only arrow-function whitespace, repaired
  locally before the final full-matrix recheck.

Owned project `cs-wp9-20261005d` had no volumes before provisioning. All three
real family installations became healthy on the existing tested image
`sha256:8798744d6e1acabb23372918b1781cb8118bab1ae20e67e9495e12f3de26f637`.
The canonical environment, older `cs-wp9-20261005a` and unrelated containers were
not reset or used for this slice's tests.

Full commands use `exec -T nextcloud-{mysql,pgsql,sqlite}`:

```text
vendor/bin/phpunit --configuration phpunit.unit.xml --fail-on-skipped --do-not-cache-result --log-junit build/wp9-20261005d/<provider>-unit.xml
vendor/bin/phpunit --configuration phpunit.integration.xml --fail-on-skipped --order-by=default --do-not-cache-result --log-junit build/wp9-20261005d/<provider>-full-2.xml
```

| Provider | Full unit tests / assertions | Final full integration tests / assertions | Errors / failures / skips | Outcome |
|---|---:|---:|---:|---|
| MariaDB | 1334 / 4500 | 602 / 5037 | 0 / 0 / 0 | PASS |
| PostgreSQL | 1334 / 4500 | 602 / 5037 | 0 / 0 / 0 | PASS |
| SQLite | 1334 / 4500 | 602 / 5040 | 0 / 0 / 0 | PASS |

Earlier complete passes are retained as `*-full.xml` (602 tests each;
5059 assertions on MariaDB/PostgreSQL, 5062 on SQLite). The recheck's 22-assertion
difference is explained by existing state-dependent user-list assertions in
`GameControllerIntegrationTest` (21) and a HTTP-server readiness retry assertion
in `TestModeHttpTest` (1), not removed scenarios or skipped execution. The
cleanup regression identities and assertions are unchanged between those runs.

Candidate remains `bd7ba8c` plus all preserved/current working changes. Tracked
runtime/test diff fingerprint (the preceding handoff's command):
`ac4c9d492b4f98a99f456f4e293b470e567a0df4`. The six previous new-file identities
are unchanged; `Support/FixtureCleanupTest.php` adds blob
`31040617ac4761dcf054e4f557adc61d45e0f223`.

Quality gates pass: Jest **109 suites / 1230 tests**, noise, ESLint, stylelint,
production build, l10n (**1270 keys**), browser-runner self-test, PHP coding
standards (**0/410**), all-profile compose validation and whitespace checks.
Frontend execution uses the supported cached Node `20.19.0` / npm `10.8.2`
toolchain. Durable fixture-ownership guidance is integrated into
`docs/engineering/testing.md`. Documentation encoding and backlog/link validation
pass. After all owned PHPUnit/helper processes exited, qualified all-profile
`down -v` removed only `cs-wp9-20261005d` containers, network and volumes. Reports
remain in ignored `build/wp9-20261005d/`; older/canonical environments are intact.

**Next:** RF-080-04 missing T12 assertions, pinned browser discovery, two
consecutive complete final-candidate browser gates, then parent whole-item
reconciliation. HUMAN-01/HUMAN-02 remain unauthorized/uncollected and block
WP9/G3/CONCEPT-001 closure. No commit, plan move, deployment or retained-baseline
adoption is performed.

### T12 Handover Repair and Catch-Up Evidence (2026-10-05)

The initial RF-080-04 audit found a missing operation, not just missing browser
assertions: owners could amend dates but could not record a one-occurrence
handover. Implementing that existing contract exposed the concrete-obligation
read gap and stale room receipts (RF-080-11/12). This continuation therefore
delivers that bounded integration slice plus catch-up deduplication; it does
not claim the six remaining RF-080-04 proofs or whole T12 completion.

- Production files: `TaskListDetails.vue`, `TaskListItem.vue`,
  `store/scheduling.js`, `TodayView.vue`, and `RoomTaskReadService.php`.
  The only backend addition is a batched read projection, not a migration,
  command, permission or financial-rule change. Default assignees are preserved;
  active obligations are distinct from voluntary work-share proposals.
- UI receipt refresh covers both success and 409. An independent focused check
  identified a retry that could silently carry consent to a successor occurrence;
  the repair binds drafts/consent to the occurrence identity, displays its date
  and clears the draft with a warning on replacement. The same-ID case preserves
  input. The independent static recheck has no remaining actionable finding;
  it is not a formal lifecycle review.
- Added mandatory browser identities in `family-rhythm.spec.ts`:
  `an owner records an agreed handover in the UI without changing future assignments`
  and `My Day shows one catch-up card with its original planned date, not a duplicate today`.
  The handover uses a 390px touch viewport, verifies the 44px selector, performs
  a second amendment in the same open details using the refreshed version, and
  verifies the released owner's personal list and receiving child's execution.
- `family-core-ux.spec.ts` extends its real Nextcloud DE/EN case to the handover
  labels and future-default clarification. Source/POT/EN/DE agree on **1275 keys**.
  The generated discovery inventory adds exactly the two scenarios; its other
  diff is object-key ordering from the canonical runner, not removed coverage.
  All 49 required specs retain their owning pool, without lower minimums.
- Initial focused attempts caught incorrect test assumptions (child cap setup
  through the self-only endpoint, unlocalized date comparison and helper shares
  incorrectly counted toward the obligated 100%). The tests were corrected to
  use actual owner/member contracts; no production validation was weakened.
  The incomplete German warning translation and local lint issues were repaired
  before the final full quality gates.
- Durable contracts are integrated into `docs/api/README.md` and
  `docs/concepts/ui-use-cases.md`; parent progress no longer says fixture isolation
  is pending. Parent RF whole-item closure remains for final evidence/review.

Owned project `cs-wp9-20261005e` had no volumes before provisioning. Every profile
installed through the actual fresh chain/family seed and became healthy. Image
identity remains
`sha256:8798744d6e1acabb23372918b1781cb8118bab1ae20e67e9495e12f3de26f637`
(the preceding matrix runtime; PHPUnit reports PHP `8.2.34`). Complete unit and
integration commands retain `--fail-on-skipped --do-not-cache-result`, with
integration additionally `--order-by=default` and reports under
`build/wp9-20261005e/<provider>-{unit,full}.xml`:

| Provider | Full unit tests / assertions | Full integration tests / assertions | Errors / failures / skips | Outcome |
|---|---:|---:|---:|---|
| MariaDB | 1334 / 4505 | 603 / 5070 | 0 / 0 / 0 | PASS |
| PostgreSQL | 1334 / 4505 | 603 / 5070 | 0 / 0 / 0 | PASS |
| SQLite | 1334 / 4505 | 603 / 5073 | 0 / 0 / 0 | PASS |

Jest initially passes **109 suites / 1239 tests**, both `--runInBand` and the complete
zero-noise gate; after the RF-080-13 view regression, the final complete
`test:noise` run invokes `npm test` and passes **109 suites / 1241 tests**.
ESLint, stylelint, production build, l10n, browser-runner
self-test and PHP coding standards (**0/410**) pass. Canonical MariaDB also
passed its pre-edit **602-test** integration baseline before browser work; the
table above, not that baseline, verifies the changed backend. No integration
suite overlaps browser mutations on the same database.

Candidate: `bd7ba8c` plus preserved/current changes; tracked runtime/test diff
fingerprint (same command as preceding rounds): initially
`527836e0af6b103a61c2a4bc42e09d650abaed17`, then
`8a8c55fc245d72d04319d667e19cb44617b9dd56` after the frontend-only RF-080-13
view/readiness repair. Backend/matrix inputs remain unchanged from the passing
provider runs. All seven previously listed new-file
blob identities remain unchanged. Localization blobs:
`en.json` = `f144daa46f792fe69f11d28b4bbc3eb8b8870704`,
`de.json` = `7bb6361c49c358659813bbe769105bbc55ae7810`,
POT = `68bedde3c07b706cbd5063fc2c0a717ea72a2917`.

After all owned PHPUnit/helper processes exited, qualified all-profile `down -v`
removed only `cs-wp9-20261005e` containers/network/volumes. Ignored JUnit evidence
remains; `cs-wp9-20261005a`, canonical containers and unrelated environments were
not recreated or removed. Canonical browser tests use declared serial resets and
AJAX cron is disabled through `occ background:cron`.

Complete browser run `build/e2e-gate/run-KYwj6N` is **FAIL**, not evidence for
the required pair: the normal pool passes; the serial DB pool has one atomic
creation failure; the owner-scheduling pool has six premature-readiness failures.
All new handover/catch-up and DE/EN cases pass in that full run. The subsequent
targeted runs pass after the bounded RF-080-13 repairs. The complete rerun
`build/e2e-gate/run-bCrhwS` stops during `chromium`, before any completed pool
report; the background process is no longer registered and a process check
finds no remaining gate/test-run worker. It is **INCOMPLETE**, never PASS or a
qualifying consecutive run. No browser retry/skip/timeout relaxation was used.

**Next technical action:** rerun `npm run test:e2e:gate` in the supported Node
environment and resolve any remaining full-run failure before closing RF-080-13.
Then continue free-day neutrality, fixed/relative explanation,
same-device identity reset, free/paused reminder suppression, keyboard-only core
execution and reduced-motion override. Then run the required consecutive
complete browser pair on that final runtime/fixture/discovery candidate and
reconcile the parent whole-item evidence. HUMAN-01/HUMAN-02 remain separately
unauthorized/uncollected; no commit, final review, plan transition, deployment
or retained-baseline adoption is performed.

### Final Automated T12 Evidence and Consecutive Browser Pair (2026-10-05)

The continuation first completed the prior RF-080-13 rerun as `run-L6t9N6`:
all three pools passed, no retries/skips/failures/flakes. That pass predates the
new evidence scenarios and does **not** count toward the final pair below.

Only browser tests, discovery and documentation changed in this continuation;
all existing WP9 production/backend/matrix edits and unrelated `.gitkeep`
deletions were preserved. No new production behavior, migration or l10n key was
needed. The six remaining RF-080-04 proofs are implemented in the five existing
specs documented in permanent release-test-plan section 12.2. Five scenarios
were added; the existing child checklist scenario now also traverses the real
keyboard order and completes work without mouse/programmatic focus. Discovery
retains every previous identity, all 49 required spec owners and raised (never
lowered) affected minimums. Other manifest diff movement is existing generated
object-key ordering, not removed coverage.

Initial focused execution caught two test-setup mistakes: weekday values must
use the API's named weekdays, and the positive confetti control can outlive a
short DOM-removal expectation. The recurrence input was corrected; reduced
motion is now verified first, then a live no-preference change demonstrates the
positive control, without waiting for animation duration or changing timeouts.
Independent focused checking found two insufficient setups: no-work alone did
not exercise explicit weekday unavailability, and an undo entry could expire
before logout. Both were strengthened (real planned obligation before making
the dispatch weekday free; live undo assertion immediately before logout).
Static recheck found no remaining issue in those deltas. Complete affected
specs then passed **34 scenarios plus 3 setup tests**, zero skips/failures.
This was an implementation check, not a formal lifecycle review.

Final candidate remains `bd7ba8c` plus preserved/current changes:

- Tracked runtime/test diff fingerprint (same `git diff --binary -- docker lib
  src tests scripts phpunit.integration.xml phpunit.unit.xml | git hash-object
  --stdin` command): `bf63485de4d32d9a09679d4c98d12702a61ccd09`, unchanged
  before, between and after the pair.
- Built `js/chorestrike-main.js` Git blob:
  `e7e4f78ec4061c59e75bf30eff55a21bcb13f6e2`; discovery inventory blob:
  `422316db948d5ce4c1d4bd2803bafb873a729fe7`.
- All seven untracked source blob identities remain unchanged from the preceding
  matrix/fixture rounds. No runtime/fixture/discovery/build changes occurred
  between the two complete gates; only documentation was updated.
- Final build/browser toolchain: Node `20.19.0`, npm `10.8.2`. Canonical browser
  image: `sha256:795f1662233b5012f584f30aae5eedbeaabf0b394598e74afc8570fd113f0067`,
  with the live bind-mounted app and final frontend build. This is distinct from
  the isolated matrix image recorded above; no image rebuild is implied.

`npm run test:e2e:gate` ran twice consecutively with the runner's
`--workers=1 --retries=0 --forbid-only` enforcement:

| Run / UTC window | Chromium passed | Serial DB passed | Scheduling owner passed | Skips / failures / flakes | Result |
|---|---:|---:|---:|---|---|
| `run-7H4sub`, 13:03:43-13:27:42 | 85 | 164 | 39 | 0 / 0 / 0 | PASS, exit 0 |
| `run-VTfa1r`, 13:28:17-13:58:01 | 85 | 164 | 39 | 0 / 0 / 0 | PASS, exit 0 |

Each pool total includes three authentication/seed setup tests: **279 domain
scenarios + 9 setup executions = 288 passes per complete gate**. Local raw
reports, sanitized runner summaries and traces stay under ignored
`build/e2e-gate/<run-id>/`; no raw report or identifying human data is published.
No integration suite or unrelated reset overlapped the browser reservation.

Renewed quality gates pass: canonical MariaDB **1334 unit / 4505 assertions**
and **603 integration / 5069 assertions**, no skips; Jest **109 suites / 1241
tests**, including the zero-noise gate; ESLint, stylelint, production build,
l10n (**1275 keys**), browser-runner negative controls, PHP coding standards
(**0/410**), documentation encoding, backlog/link checks and diff whitespace.
The cross-provider `cs-wp9-20261005e` reports remain applicable to the unchanged
backend/matrix inputs, rather than being misrepresented as a new matrix run.

The parent now has an exact RF-070 ownership table; stale drafting references
to nonexistent teamwork/minigame suites were replaced with delivered owners.
The stored final provider reports explicitly include tombstone, reward erasure
and same-UID non-rebinding tests, correcting the earlier inventory's still-open
DB-04 note. Parent whole-item findings remain unchecked pending final review,
not silently closed by file existence or inherited child passes.

**Remaining boundary:** RF-080-05 / HUMAN-01 and HUMAN-02 are not authorized,
started or collected. Separately authorized coordination, consent, unaided
adult/child comprehension and 14 days voluntary use still block WP9/G3 and
CONCEPT-001 completion. Final Standard B/Full review remains pending. Canonical
browser processes have exited and the reservation is released; no matrix or
unrelated containers/volumes were modified or removed in this continuation.
No commit, plan move, deployment, recruitment or retained-baseline adoption
was performed.

## Draft Validation

2026-10-02: `npm run backlog:check`, `npm run docs:encoding` and
`git diff --check` passed for the documentation round. Referenced existing
browser specs and provisioning paths were inspected; matrix compose and schema
test paths are explicitly future files. This is not Standard A or runtime
evidence. Unrelated pre-existing `.gitkeep` deletions were left untouched.

## Review Outcome

- **Review:** Standard A, 2026-10-03 (main agent, no subagents; review record
  `docs/backlog/archive/CODE_REVIEW_2026-10-03.md`).
- **Baseline:** `master` at `821195d853c43a2fc32e22b4bf9ea4f0fe2f0f7b`; index
  empty at capture (staged-diff sha1
  `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391`); unstaged-diff sha1
  `fc62c603a31d55e4de8b006ff9c0a43012219852` covering the draft-support
  tracking package plus the unrelated pre-existing `.gitkeep` deletions,
  preserved unstaged; untracked plan SHA256
  `10C41B27785AB2EEFA5A6E3B8E2751B6259F804B8855BEAC2B2B3E45F61EE9F1`.
- **Outcome:** `PASS` — zero findings, zero open decisions.
- **Verification:** every section-1 baseline citation exact against the
  runtime (MariaDB 10.6 with fixed names/port 8080, MySQL-only entrypoint
  probes and hardcoded `--database='mysql'`, `pdo_mysql`/`pdo_pgsql` without
  an asserted SQLite driver, `compose.test-matrix.yml` absent, the isolated
  toy-schema SQLite lock case in `HouseMutationConcurrencyTest.php`,
  `markTestSkipped` on non-MySQL in `RewardConcurrencyTest.php`, the
  three-pool zero-retry/no-skip gate in `scripts/run-e2e-gate.js`, the
  `#family-acceptance-protocol` rules in `ui-use-cases.md`, open/partial
  parent RF-070 items); manifest all-N absent / all-M present; parent
  CS-PLAN-070 authorizes exactly this WP9 slice (WP9 row, T12 mapping, G3
  gate, 4-6-day + 14-calendar-day allowance); no overlap with any other
  active plan; nine template sections, per-step ownership, coherent scope,
  named risks, complete acceptance criteria and a concrete test plan all
  verified.
- **Fixes:** none required.
- **Decisions:** none — the parent authorizes exactly this slice, the C5j
  consent/anonymity rules are already approved, and no behavior,
  architecture, migration or security question arose.
- **Gates:** `npm run backlog:check`, `npm run docs:encoding` and
  `git diff --check` green after the activation edits; runtime gates belong
  to implementation steps 2-6 and were intentionally not run (no runtime
  file changed).
- **Lifecycle:** plan moved `new/` → `active/`; CONCEPT-001 stays In
  Progress with CS-PLAN-080 as the WP9 owner; parent header/WP9 row,
  `docs/README.md`, `docs/plans/README.md`, `docs/backlog/backlog.md` and
  `ACTIVE_TASKS.md` updated; review record
  `CODE_REVIEW_2026-10-03.md` created.

### Interim Standard B — 2026-10-06 — `PASS-WITH-FIXES` (plan stays active)

- **Scope:** the WP9 slice of the remaining uncommitted round at `master`
  `5dd32a5` (dual attribution with CS-PLAN-082, operator-resolved): production
  mappers/`RoomTaskReadService`/`TestDataService`, handover frontend
  (`TaskListDetails`/`TaskListItem`/`scheduling.js`/`TodayView`/`TaskManager`),
  the isolated matrix + entrypoint provisioning, portable race workers with
  `QueryBarrier`, fixture-isolation teardown, T12/handover browser scenarios
  and discovery, this plan's evidence sections and the durable docs.
- **Baseline:** index empty at capture; unstaged-diff SHA256
  `4435B8C302140AE7E06A12F5060FF5312BE211D165637646794F6F8676B525BD`;
  runtime/test candidate fingerprint `d917dc6b4a127a9ea01c3f8bf8550d627372cb43`.
- **Verification:** no weakened tests — removed provider guards were replaced
  by real cross-provider workers, schema assertions ported to Nextcloud
  abstractions (equal/stronger), query budgets keep MariaDB ceilings with
  exactly pinned provider overhead, gate minimums only raised; handover/
  occurrence-identity contracts match the recorded RF-080-11/12 resolutions
  (consent cleared on occurrence replacement, preserved on same-ID conflict);
  i18n 1275 keys exact. Review gates green (details:
  `docs/backlog/archive/CODE_REVIEW_2026-10-06.md`), including a fresh NC35
  MariaDB candidate run: unit 1341/4636 OK, integration 603 OK, zero skips.
- **Findings:** none against this plan's slice; the review's single minor
  finding (`scripts/test-runtime.js` CLI error message) belongs to
  CS-PLAN-082's slice and was fixed in-round.
- **Boundary:** RF-080-05 / HUMAN-01/02 still block WP9/G3/CONCEPT-001; the
  NC31 browser pair remains historical for the newer runtime (CS-PLAN-082 R4
  owns the renewed pair). No plan move, no human-evidence claim.
