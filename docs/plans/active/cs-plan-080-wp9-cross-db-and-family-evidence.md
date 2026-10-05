# CS-PLAN-080: WP9 Cross-Database Verification and Family Evidence

> **Status:** Active (implementation not started); drafted 2026-10-02; Standard A `PASS` 2026-10-03
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
[CS-PLAN-082](../active/cs-plan-082-current-stable-test-baseline-and-data-preservation.md)
(active, Standard A `PASS` 2026-10-05)
owns that infrastructure/compatibility qualification and renewed target evidence.
It does not reroute WP9's human gate or adopt retained data. Do not use the prior
runtime's green checkboxes as target-server readiness; qualification must precede
the separate RELEASE-002 deployment/adoption decision. No runtime change is
authorized by drafting the follow-up.

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

- [ ] Standard A passes; all exposed review questions/decisions are recorded.
- [ ] T0-T12 and all remaining parent RF-070 items have exact evidence ownership;
  no still-required gap is hidden in another backlog item.
- [ ] Fresh full schema/family fixtures and DB-01..05 proofs pass on MariaDB,
  PostgreSQL and SQLite through real Nextcloud connections, with no unmapped
  exclusions or new/unapproved skips; unrelated environments remain intact.
- [ ] Required backend/Jest/lint/stylelint/noise/build/l10n/documentation gates pass.
- [ ] UI-01..04 are mandatory in discovery and pass; desktop/mobile/keyboard and
  disabled optional-feature core usage are explicitly demonstrated.
- [ ] Two consecutive complete three-pool browser gates pass on the same final
  runtime/fixture/discovery candidate, zero retries/skips/failures/flakes.
- [ ] Consent-confirmed minimum adult/child comprehension evidence is complete,
  including unaided essential operation and optional-feature opt-out.
- [ ] Fourteen days voluntary everyday use and anonymous pressure/organizing
  feedback are recorded; comprehension/pressure findings are resolved, not assumed away.
- [ ] Public evidence contains no identifying/sensitive human data or raw traces;
  artifact locations and final-build applicability are recorded honestly.
- [ ] All plan-owned findings and parent whole-item blockers are resolved or
  explicitly user-rescoped; required deferred evidence still blocks completion.
- [ ] Durable matrix/protocol/contract outcomes are integrated into permanent docs.
- [ ] Final Standard B or Full review passes for complete WP9 and parent G3;
  plans/tracking and CONCEPT-001 archive reflect actual completion. Technical
  green alone does not authorize closure.

## 9. Review Findings (Plan-Owned)

No formal review has run for this draft. Cross-DB provisioning and human evidence
are planned deliverables, not already resolved findings. Record new in-scope
findings here with severity, exact file/area, evidence and resolution; reconcile
parent RF-070 entries in the parent without creating duplicate backlog owners.

No open review findings recorded yet.

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
