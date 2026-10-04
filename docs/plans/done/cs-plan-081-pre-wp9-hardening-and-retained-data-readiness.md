# CS-PLAN-081: Pre-WP9 Hardening and Retained-Data Readiness

> **Status:** Done - final Full `PASS-WITH-FIXES` (2026-10-05); H1-H6 complete, H6-05 evidence-bound disposition explicitly accepted; H7/H-T11 user-rescoped, not passed; no deployment or baseline adoption
> **Effort:** L, original estimate 6-10 engineering days; H7 operator work excluded by the 2026-10-04 scope decision; re-estimate if fixture repairs expand scope
> **Priority:** P1 for the retained-data trial prerequisite, not a blanket reprioritization of every included backlog item
> **Depends on:** WP0-WP8 delivered; Standard A before implementation
> **Related backlog items:** TECH-JEST-001, TECH-LINT-006, TECH-TEST-003, CR-SEC-05, SEC-AUDIT-001, FEAT-UX-032, CR-053-11; bounded contributions to RELEASE-002 and CONCEPT-001; BUG-SEC-001 deferred outside this plan
> **Sequence:** This independent preparation slice is delivered before technical execution of [CS-PLAN-080](../active/cs-plan-080-wp9-cross-db-and-family-evidence.md); it is not another WP of [CS-PLAN-070](../active/cs-plan-070-family-rhythm-and-reward-clarification.md)
> **Drafting baseline:** `master` at `76e282e`, 2026-10-03; runtime evidence inspected, no new runtime gate executed

## 1. Problem Summary

The user intends to start a family trial on a personal productive Nextcloud
server and retain the house structure and tasks across subsequent development.
WP0-WP8 are delivered, but independent hardening items remain and the current
fresh-install-only policy does not promise preservation through future updates.
Completing hypothetical features now would not solve that operational boundary.

Verified drafting baseline (historical, before implementation and rescoping):

- No selected independent backlog fix currently requires a new app table.
  The migration head is Version1033; existing steps have been edited in place.
  Installed databases do not acquire later edits to already-executed steps.
- Membership removal and role updates still rely on controller owner gates;
  `HouseService::inviteMember` has the same caller-only contract. The public
  callers are gated; this is defense-in-depth work, not a claimed HTTP exploit.
- Jest ignores worktree test paths, not the duplicate manual-mock inventory.
  `.npmrc` still enables `legacy-peer-deps` for the lint dependency conflict.
- TECH-TEST-003 records a historical cold-start fixture failure. The claimed
  memoized-house/deletion mechanism is not established by the current code:
  `getOrCreateHouse()` re-queries, and structural deletion now tombstones.
- The setup wizard still drops a requested basement at six generated floors.
- Settings promises an all-progress reset, although `resetHouse()` resets only
  room cleanliness/monsters/last-cleaned and house health. Planning, work history,
  wallets, rewards and achievements survive; the room reset is journaled.
- CR-053-11's live remainder is Snake score ordering, not retired freezes.
  FEAT-UX-028's existing-search-endpoint description is stale.
- SEC-AUDIT-001 and BUG-SEC-001 are independent RELEASE-002 blockers. No approved
  reporting mailbox/public key or generic owner-operation audit contract exists.
- Deployment guidance includes whole-database restore and shared-cache flushing;
  these are not app-isolated rollback operations on a productive Nextcloud.

This drafting round changes plans/tracking only. It does not activate a plan,
lift the compatibility rule, create credentials, deploy, collect family data,
execute destructive recovery, or authorize a commit.

## 2. Strategy

Close the bounded correctness/tooling/security remainders, clarify setup/reset
behavior, and prepare an explicit first-retained-build contract. Then hand the
final candidate to WP9 for its supported-DB matrix and repeated browser gates.

**Current scope (user decision 2026-10-04):** the operator will initially test
alone. H7/H-T11 are excluded from this preparation plan, not completed. Later
email reporting is tracked in BUG-SEC-001; the possible Codeberg-to-GitHub move
is tracked separately in TECH-REPO-001 in the [backlog](../../backlog/backlog.md).
No GPG requirement remains. This does not waive technical gates, WP9 human
evidence or the explicit retained-baseline adoption procedure.

### Ownership and Non-Goals

| Slice | Existing backlog owner | Completion boundary |
|---|---|---|
| H1: test discovery and dependency installation | TECH-JEST-001, TECH-LINT-006 | Canonical mocks only; clean install without peer-dependency bypass |
| H2: cold-start fixture lifecycle | TECH-TEST-003 | Evidence-based root-cause/disposition and deterministic regression, not rerun-only acceptance |
| H3: membership authorization and security hardening | CR-SEC-05, SEC-AUDIT-001 | Service gates, membership HTTP throttling, enumerated owner-operation logs and security assumptions |
| H4: setup and truthful reset UI | FEAT-UX-032; CONCEPT-001 for the reset clarification | Basement selection respected within the existing cap; UI matches the unchanged reset contract |
| H5: Snake ordering remainder | CR-053-11 | Stable row order with equal-score rank semantics unchanged |
| H6: retained-data and recovery preparation | RELEASE-002, partial only | Approved future boundary, rehearsal and deployment checklist; no deployment/field-usage closure |
| H7: deferred reporting channel (outside current scope) | BUG-SEC-001, unassigned | Later approved, reachable email contact; not a prerequisite for operator-only self-testing or this plan's completion |

No self-service leave feature (FEAT-UX-015), general chore approval, Task Pass,
guided tour, search, print view, bulk maintenance, performance sweep, or
TECH-DIGEST-001 repair is included. FEAT-UX-015 remains useful but is not a
schema/data-retention prerequisite. Do not resurrect obsolete freeze mechanics.

WP9 keeps full ownership of three-provider provisioning/portability, parent
RF-070 whole-item reconciliation, two consecutive browser passes and voluntary
adult/child evidence. This plan neither closes CONCEPT-001/RELEASE-002 nor creates
duplicate owners for their remaining work. No family recruitment or production
server changes occur here. All writes stay with main; optional internal agents
may only perform read-only lookups/reviews.

## 3. Backend / Frontend Changes

### 3.1 H1-H2: Trustworthy Test and Install Paths

Add root-scoped Jest module/haste discovery exclusions while preserving canonical
mock mappings. A synthetic temporary nested-worktree fixture must demonstrate
that duplicate mocks are not indexed. Never alter real worktrees, hide warnings,
or exclude legitimate canonical tests.

Resolve the smallest compatible lint peer graph and regenerate the lockfile.
Remove the `.npmrc` bypass only after a clean `npm ci --legacy-peer-deps=false`
and `npm ls` are green. Keep the existing ESLint/Vue policy and supported engine
contract; no unrelated major tooling upgrade or force-install workaround.

For TECH-TEST-003, record exact cold-start ordering and fixture ownership before
editing. Reproduce with synthetic test data or pin a deterministic regression
for the former failure class. Repair the shared-house lifecycle in the test
harness, not production lookup/deletion semantics. A missing production house
must still fail. Three independently fresh, owned test installations must pass
their first full integration run; a failed first run is not erased by a rerun.
If the old defect cannot be reproduced, leave the item open until review accepts
an evidence-backed superseded disposition with the replacement regression.

### 3.2 H3: Membership Gates, Throttling and Owner-Operation Logs

Make `inviteMember`, `removeHouseMember` and `updateUserRole` enforce current
owner authority in the service and recheck under the house-first lock. Gate
invitation before account/email lookup. Preserve scoped 404s, role 403s,
last-owner/self-demotion rules, no-op role revisions, departure cancellations,
retained wallets, preferences/privacy cleanup and rollback. Audit actor identity
comes from authoritative authorization, not an unchecked caller parameter.
Update all callers/tests; do not retain an anonymous compatibility path.

For `UserController::invite/remove/updateRole`, prefer Nextcloud's public
`OCP\AppFramework\Http\Attribute\UserRateLimit` (available since NC27).
Q-081-02 proposes 60 calls per 300 seconds per authenticated user per endpoint,
using elapsed seconds, not household calendar days. No app counter table or
private `OC\*` API is needed. Verify the actual installed server behavior before
implementation. Middleware rejection is an HTTP 429 before controller mutation;
document its native response, not a fictitious app error envelope. Keep ordinary
chore execution outside these limits and do not change supported NC versions.

Enumerate current owner-only mutation entry points before logging changes:
membership, house settings/reset/gates, setup/structure, checklist definitions,
owner planner/agreement/pause actions and owner reward/catalog actions. Pin the
exact methods and uncovered logs in an implementation appendix before editing.
Use existing AppLogger and transaction boundaries; no new general audit store.
Log a stable security event/outcome and minimal numeric actor/resource context.
No email, UID/display-name snapshot, authored content, reason text, payload,
medical detail or credential. Successful-change events occur after successful
commit; rejection/rollback must not appear as success. Avoid duplicating existing
denial/domain events; distinguish no-op/replay from a new mutation.

This is privacy-aware operational logging with Nextcloud's configured retention,
not a tamper-proof or exactly-once durable audit ledger. Q-081-03 must approve
that contract; a dedicated ledger would require a new schema/effort decision.
Update security/API/logging documentation to state actual limits, coverage,
retention ownership and remaining assumptions.

Upstream contracts inspected during drafting: [UserRateLimit](https://github.com/nextcloud/server/blob/stable31/lib/public/AppFramework/Http/Attribute/UserRateLimit.php),
[ARateLimit](https://github.com/nextcloud/server/blob/stable31/lib/public/AppFramework/Http/Attribute/ARateLimit.php),
[middleware](https://github.com/nextcloud/server/blob/stable31/lib/private/AppFramework/Middleware/Security/RateLimitingMiddleware.php).
These public source checks are not evidence of a local runtime test.

### 3.3 H4-H5: Small User-Facing Corrections

Q-081-05 proposes a maximum of five above-ground floors when a basement is
selected, six total, with a visible explanation and consistent input/preview/
payload. Apartments retain six floors without a basement. Toggling the basement
must never silently lose a selected structural component; backend caps stay
unchanged. Localize any new labels/hints.

Correct the reset card, confirmation and success wording to describe the actual
room/house-health reset and explicitly retained setup, tasks, agreements,
completion history, finances/reward requests and achievements. Keep owner
visibility, literal confirmation, cancellation, stale-version reconciliation
and same-command retry behavior. Do not change the backend reset or add a
gamification-reset endpoint/epoch. Add a required browser scenario proving
retention after the action, not just danger-zone visibility.

Make Snake row selection deterministic with `score DESC, id ASC` in ordered
score-list/best-row queries. Equal scores remain tied in `getUserRank()`; no
ranking rule, consent policy, reward value or new index is implied. Cover global,
period and personal ordered reads without touching the household rhythm ranking.

### 3.4 H6: First-Retained-Build and Recovery Contract

Q-081-01 proposes protecting persisted data by default from one explicitly
declared trial baseline, including setup/structure, definitions/room tasks,
assignments/checklists, recurrence/occurrences/agreements/pauses, completions,
work shares/corrections/journal, membership/preferences and financial/reward
facts. Scores/awards are separable, but resetting them still needs an explicit
future scoped decision; derived streak/title rows are not independent history.

The declared baseline is chosen only after WP9's technical gates. Record its
revision/build identity, actual app version, NC/PHP/provider versions, ordered
migration identifiers and file hashes. Do not claim the draft's current version
or an unchanged-app reinstall proves an upgrade. No release tag is created here.

This plan prepares the approved boundary/checklist and rehearses recovery; it
does not require WP9 to finish first, select the production baseline early or
deploy it. Until explicit baseline adoption, disposable development installs
remain under the current fresh-install rule. After adoption, already-applied
migration files are immutable and future schema changes use new ordered steps,
real populated-baseline upgrade tests and fresh/upgrade parity checks on the
supported providers. No compatibility with older disposable development states.
Do not add a fake/no-op migration, speculative backfill or legacy wrapper now.

Integrate this approval-dependent transition into canonical rules/bootstrap,
backend/architecture guidance and permanent deployment/release/testing docs
during implementation, without claiming that adoption has already occurred.

Rehearse a consistent backup/restore using synthetic data in an explicitly owned
disposable environment, with no overlapping tests/browser jobs. Include code,
config, DB and applicable data state; restore only into the owned test target.
Compare structure IDs/relations, planning/history facts, balances/reservations,
and synthetic non-ChoreStrike sentinel data. Raw dumps/manifests with identities
stay gitignored, never public evidence. Record sanitized commands, target identity,
before/after assertions and actual results in the release test plan.

Remove any implication that code downgrade alone reverses schema changes or that
a whole-Nextcloud DB restore/shared cache flush is app-isolated. Document write
quiescence, other-app rollback impact, recovery window and operator approval.
Do not introduce unsafe table-only restore or shared Redis `flushall` as an app
recipe. Actual personal-server validation/adoption remains RELEASE-002 work after
technical readiness, with explicit target/access authorization.

### 3.5 Deferred H7 and Tracking

The user explicitly removed H7 from this plan on 2026-10-04 because the next
stage is operator-only self-testing. BUG-SEC-001 remains open outside this plan
for a later ordinary private email reporting route, intended to use the new
GitHub-associated email once available. A dedicated mailbox, GPG key,
fingerprint/decryption test and Codeberg profile publication are no longer
required here. The possible forge migration needs its own assessment and
authorization (TECH-REPO-001); it is not implementation scope for this plan.
Only operator-approved contact details may be published later. No account,
mailbox, repository migration or external profile action is authorized now.

Correct stale CR-053-11, FEAT-UX-028 and TECH-TEST-003 descriptions without
pretending open behavior was fixed. On activation assign included standalone
items to this plan; preserve the parent owner of CONCEPT-001 and partial owner
of RELEASE-002. At completion archive only fully closed standalone items.
Leave the broader release, tour/search/leave remainders and parent evidence open.

## 4. Tests

| Gate | Required assertion | Primary files/evidence |
|---|---|---|
| H-T1 | Nested duplicate mocks ignored; canonical mocks/tests still discovered; no duplicate warning suppression | New `tests/unit/scripts/jestDiscovery.spec.js`; `jest.config.js` |
| H-T2 | Clean dependency install without bypass, valid peer tree; existing lint/style/build behavior preserved | `.npmrc`, package/lockfile; clean-install command record |
| H-T3 | Deterministic fixture lifecycle regression; genuine missing house still rejected; three fresh first full integration passes | `IntegrationTestCase.php`, `ChecklistApiTest.php`; new `tests/integration/Db/FixtureHouseLifecycleTest.php` |
| H-T4 | Direct non-owner/child/foreign calls denied; owner demotion race denied under lock; all existing departure/role/wallet invariants retained | HouseService/UserController unit tests; membership/controller/concurrency integration suites |
| H-T5 | Public attributes match approved limits; real middleware allows boundary then 429 with zero rejected-call effects; identities/endpoints isolated | UserController unit tests; new required HTTP scenarios in `family-membership-hardening.spec.ts` |
| H-T6 | Each enumerated owner mutation covered by minimal safe event; no false success on denial/rollback; no sensitive context or duplicate success on replay | Existing service logging tests; uncovered operation tests pinned by H0 inventory |
| H-T7 | Six total with basement, no basement loss; apartment/toggle boundaries; server cap unchanged | SetupWizard Jest suite; `setup-wizard-journey.spec.ts` |
| H-T8 | Truthful reset card/confirmation/notice; cancel does nothing; reset retains populated setup/planning/history/finances/awards; retry does not erase later work | Settings/store Jest; `settings.spec.ts`; existing HouseService/RoomReplayConcurrency suites |
| H-T9 | Stable equal-score row selection in all ordered reads; equal-score ranks remain tied; period/house isolation | MinigameScoreMapper unit tests; real-DB MinigameController integration suite |
| H-T10 | Synthetic backup/restore retains core and financial relations and unrelated sentinel state; no production/shared-state damage | Named rehearsal record in `docs/testing/release-test-plan.md` |

H-T11 is removed from the required gate set by the explicit 2026-10-04 user
rescoping. It was not executed or passed; the later reachable-email acceptance
belongs to BUG-SEC-001, without a GPG/decryption requirement. H-T1..H-T10 and
the implementation gates below remain unchanged.

Rate-limit HTTP tests use a dedicated synthetic owner identity and bounded
requests. If a supported Nextcloud test override shortens the limit/window,
verify the production default separately and restore the real settings before
full gates. Do not disable throttling to make required flows green. Pin every
new/changed E2E scenario and exclusive DB-pool ownership in discovery.

Implementation build gate: `npm test -- --runInBand`, `npm run lint`,
`npm run stylelint`, `npm run test:noise`, `npm run build`,
`npm run l10n:check-keys`, `node scripts/run-e2e-gate.js --self-test`,
Docker unit/integration PHPUnit with `--fail-on-skipped`, `composer cs:check`,
and one complete `npm run test:e2e:gate` on the final candidate. Use the
[Docker skill](../../standards/ai/skills/docker-dev/SKILL.md) command path;
never overlap integration/recovery/browser mutation jobs. No new/unapproved skips.
WP9 must still run its own full provider matrix and consecutive gate pair after
these changes; this plan's pass does not substitute for either.

Drafting gate: `npm run backlog:check`, `npm run docs:encoding`,
`git diff --check`, plus explicit inclusion of the untracked new draft in link/
dependency/encoding checks. No runtime gate is claimed for a documentation draft.

## 5. File Manifest

Current drafting package: this file; plan/doc indices; backlog and live registry;
parent/WP9 sequencing notes. No runtime or canonical-policy change in this round.

| Kind | Paths | Purpose |
|---|---|---|
| M | `jest.config.js`, `.npmrc`, `package.json`, `package-lock.json` | Discovery and peer graph; no broad dependency sweep |
| N | `tests/unit/scripts/jestDiscovery.spec.js`, `tests/integration/Db/FixtureHouseLifecycleTest.php` | Discovery/fixture regressions |
| M | `tests/integration/IntegrationTestCase.php`, `tests/integration/Controller/ChecklistApiTest.php` | Evidenced fixture repair; other dependent fixtures only if H0 pins the same cause |
| M | `lib/Service/HouseService.php`, `lib/Controller/UserController.php` | Service-side membership authority, public throttle attributes and audit context |
| M | `tests/unit/Service/{HouseServiceTest,HouseServiceLoggingTest}.php`, `tests/unit/Controller/UserControllerTest.php` | Direct-call, invariant, rate-contract and log regressions |
| M | `tests/integration/Service/{HouseMemberRemovalIntegrationTest,HouseMutationConcurrencyTest}.php`, `tests/integration/Controller/{UserControllerIntegrationTest,InviteOnlyMembershipTest}.php`, `tests/integration/RoleGuardApiTest.php` | Real ownership/departure/role proofs |
| N | `tests/e2e/family-membership-hardening.spec.ts` | Real middleware 429 and unchanged membership effects |
| M | `src/components/Setup/SetupWizard.vue`, `src/views/Settings.vue`, matching `tests/unit/components/SetupWizard.spec.js`, `tests/unit/views/Settings.spec.js` | Cap/reset wording and interactions |
| M | `tests/unit/store/house.spec.js`, `tests/e2e/{setup-wizard-journey,settings}.spec.ts` | Reset identity/retention and cap journey |
| M | `lib/Db/MinigameScoreMapper.php`, `tests/unit/Db/Mapper/MinigameScoreMapperTest.php`, `tests/integration/Controller/MinigameControllerIntegrationTest.php` | Deterministic Snake ordering |
| M | `tests/e2e/required-scenarios.json`, `scripts/run-e2e-gate.js`, `playwright.config.ts`, `l10n/{en,de}.json`, `l10n/templates/chorestrike.pot` | Mandatory discovery/pools and complete translations |
| M | `docs/SECURITY.md`, `docs/standards/guidelines/{security,logging}.md`, `docs/api/README.md` | Reporting-policy rescoping, limits and audit contract; no new contact publication |
| M after approval | `AGENTS.md`, `docs/standards/ai/rules/project-rules.md`, `docs/standards/ai/skills/{php-backend,architecture}/SKILL.md`, `docs/engineering/{deployment,testing}.md`, `docs/testing/release-test-plan.md`, `docs/release/release-strategy.md` | Approved retained-data transition/recovery gates; no claim of premature adoption |
| Conditional M | Existing owner-operation services/controllers and their logging tests | Only exact uncovered methods pinned in H0; not a general controller refactor |
| M | This plan, CS-PLAN-070/080, `docs/{README,plans/README}.md`, `docs/backlog/{backlog,ACTIVE_TASKS}.md` | Sequencing, evidence, actual partial completion |
| Completion only | Monthly backlog archive and final review record | Fully completed items only |

**H6 executable rehearsal additions (pinned 2026-10-04 before edits):**
`scripts/run-recovery-rehearsal.ps1` orchestrates explicitly named, isolated
test-only containers with no published ports or canonical-stack mutation;
`tests/recovery/fixture.php` seeds/validates synthetic non-empty retained facts
through the existing services. Raw snapshots/dumps/code/config/data artifacts
remain under gitignored `private/`; only sanitized evidence is public. No
production migration, schema or runtime behavior change is included.

No migration, new production table, deletion/archive of runtime files, new
reset economy or local child profile is planned. Any evidenced need changes the
scope/estimate explicitly before implementation, not through a hidden helper.

**Authorized review-repair manifest additions (2026-10-03):**

| Type | Paths / purpose |
| --- | --- |
| N | `lib/Exception/CannotDemoteSelfException.php` - precise endpoint error mapping |
| N | `tests/unit/Service/CatalogAuditTest.php`, `ChecklistAuditTest.php`, `CommandAuditTest.php` - post-commit, replay, denial, rollback and no-change audit contracts |
| M | `lib/Service/AppLogger.php`, `ChecklistService.php`, `CommandService.php`, `RoomService.php`, `RewardRequestService.php` - complete numeric audit path/inventory and authoritative access guards |
| M | `docker/Dockerfile.test`, `docker/test-entrypoint.sh` - native request-shared APCu counters in the disposable test environment |
| M | `playwright.config.ts`, `scripts/run-e2e-gate.js`, `tests/unit/scripts/browserGate.spec.js`, `tests/e2e/fixtures/occ-runner.ts` - exclusive required-spec ownership and test-owned identity provisioning |
| M | `lib/Service/TestDataService.php`, `tests/integration/Service/TestDataSeedTest.php` - historically valid performer membership and real quiet-recognition fixture |
| M | `tests/unit/Service/AppLoggerTest.php`, `FloorServiceTest.php`, `RoomServiceTest.php`, `HouseSetupServiceTest.php`, `SchedulingServiceTest.php`, `RewardRequestServiceTest.php` - exact audit/authority regression matrix |
| M | `tests/integration/Service/RewardLifecycleTest.php`, `RewardConcurrencyTest.php` - remove obsolete role-caller arguments |
| M | `docs/engineering/testing.md`, `docs/standards/guidelines/logging.md` - durable native-cache and operational-event contracts |
| M | Owning plan, `ACTIVE_TASKS.md`, `docs/backlog/archive/CODE_REVIEW_2026-10-03.md` - review evidence/finding routing, not plan completion |

The exact repaired 67-path snapshot and per-untracked-file hashes are in the
review record; it additionally declares the record as a finalization path.

## 6. Implementation Order

1. **H0 / main:** Standard A resolves Q-081-01..05, verifies the manifest and
   independent ownership, then activates normally. Capture candidate identity,
   exact owner-operation coverage, fixture reproduction and command isolation.
2. **H1-H2 / main:** fix discovery/peer installation, then diagnose/isolate fixtures
   and establish the cold-start passes. Record accepted superseded dispositions
   rather than inventing an old root cause (1.5-3 days).
3. **H3 / main:** service authorization plus approved platform throttling and
   enumerated privacy-aware logs, with denial/race/rollback/HTTP tests (2-3 days).
4. **H4-H5 / main:** basement/reset clarity and stable Snake ordering, complete
   l10n, regressions and required browser discovery (0.5-1 day).
5. **H6 / main:** approved boundary/docs and synthetic recovery rehearsal
   (1-2 days). H7 is user-rescoped to later backlog work; missing contact/key
   evidence no longer blocks this preparation plan.
6. **Closure / main + independent reviewer:** all final gates, durable integration,
   scoped final Standard B or Full review, archive fully completed owners and
   leave partial release/parent items open (0.5-1 day). Re-estimate discoveries.
7. **Handoff:** CS-PLAN-080 performs its remaining technical work on this reviewed
   candidate; after its technical readiness, the operator explicitly adopts the
   retained baseline before first real data and coordinates voluntary human use.
   No circular prerequisite on WP9 human completion; no closure by technical green.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| Draft approval mistaken for productive-use declaration | High | Separate approved future policy from explicit post-WP9 baseline adoption |
| Harness patch masks missing production data | High | Test-only ownership fix; production missing-house negative regression |
| Throttle breaks setup or shared browser fixtures | Medium | Membership routes only, approved generous per-user limits, dedicated test identities; full gate with real defaults |
| Logs expose family data or overpromise durable audit | High | Minimal context, post-commit success, retention owned by NC; Q-081-03 contract explicit |
| Whole-server restore/cache flush harms other apps | High | Owned synthetic rehearsal, quiescence and impact checklist; no production restore or shared flush recipe |
| Deferred reporting contact forgotten before external use | Medium | Explicit H7 rescoping recorded; BUG-SEC-001 retains the later external-use obligation, coordinated with TECH-REPO-001 |
| Prep absorbs optional features or duplicates WP9 | Medium | Existing owner table, explicit non-goals and technical/human handoff |

## 8. Acceptance Criteria

- [x] Standard A passes and Q-081-01..05 are explicitly resolved/recorded. (Standard A `PASS-WITH-FIXES` 2026-10-03; all five recommended options approved — see §9 and Review Outcome)
- [x] H1-H5 changes and all H-T1..09 regressions pass; no suppression, fixture
  fallback, force-install or newly/unapproved skipped test substitutes for proof.
  (Verified across the implementation rounds and re-verified by the interim
  Standard B review 2026-10-04: Jest 109/1228, Docker unit 1313/integration 568
  with `--fail-on-skipped` and zero skips, plus the recorded fresh-install and
  browser evidence for H-T3/H-T5/H-T7/H-T8.)
- [x] Three independently fresh first full integration passes establish the
  accepted TECH-TEST-003 disposition, with named deterministic lifecycle coverage.
- [x] Membership changes preserve current owner/last-owner, scope, wallet,
  departure/role and transactional invariants; native 429 behavior is demonstrated.
- [x] Every current owner-only mutation in the pinned inventory has the approved
  safe operational logging coverage; no sensitive content or false success log.
  (Repaired H0/H3 inventory complete with post-commit numeric audit seams and
  replay/no-change/denial/rollback regressions; re-verified by the interim
  Standard B review 2026-10-04.)
- [x] UI and l10n accurately explain the unchanged reset/cap contracts and the
  registered browser scenarios prove retained facts after reset.
- [x] H-T10 synthetic recovery succeeds; durable docs state other-app rollback
  impact and the future retained-baseline/adoption procedure without claiming
  a real-server test, actual upgrade or baseline adoption that did not happen.
  (Final-candidate H-T10 `cs081-recovery-2f8aff4b3ee8` record in the release test plan:
  30 app snapshots, whole DB row equality, captured-file hash equality,
  negative controls and complete owned-resource cleanup all pass.)
- **User-rescoped (2026-10-04):** H7/H-T11 are excluded for operator-only
  self-testing and retained as later BUG-SEC-001 work, not marked passed or done.
- [x] Final implementation build/backend/browser/documentation gates pass on the
  reviewed candidate; WP9's later matrix/gate-pair/human obligations remain explicit.
- [x] Durable decisions live in permanent docs; no completed standalone backlog
  item remains open, and CONCEPT-001/RELEASE-002 retain their honest partial status.
- [x] All plan-owned findings and decisions are resolved or explicitly user-rescoped;
  required deferred work still blocks completion.
- [x] Final Standard B or Full review passes, tracking is updated and the complete
  preparation plan moves to `done/`; no production deployment/tag/push is implied.

## 9. Review Findings (Plan-Owned)

Standard A ran 2026-10-03 and passed with one minor manifest-path finding fixed
in-round (see ## Review Outcome). The reset-copy discrepancy is an in-scope deliverable
under CONCEPT-001/H4, not a duplicate standalone backlog item. Record newly
discovered in-scope findings here; out-of-scope findings follow canonical routing.

### H7 Post-Review Scope Decision (2026-10-04)

The user explicitly requested that H7 be left out at this stage because only the
operator will test initially. This supersedes Q-081-04 and the historical
operator-blocked H7/H-T11 statements below; those review snapshots remain intact.
BUG-SEC-001 returns to the unassigned backlog for later private email reporting,
without mandatory GPG. The intended contact is a new GitHub-associated email,
subject to availability, actual mailbox reachability and publication approval.
The likely Codeberg-to-GitHub migration is recorded as TECH-REPO-001 for a later
decision and scoped effort, not assumed approved or already performed.

This is an explicit scope change, not successful H-T11 evidence or a new review
verdict. RF-081-H6-05, final implementation/documentation gates and the final
Standard B/Full review remain required. The plan stays `active/`; RELEASE-002,
CONCEPT-001, WP9 evidence and retained-baseline adoption are not closed or waived.
No commit, publication, external account action, migration or deployment is
authorized by this documentation request.

### Implementation round 1 (2026-10-03)

These are the original implementation claims and evidence, retained as history.
The Full interim review below supersedes the H1/H3 completeness claims; it is
the current source for verified repairs, final gates and the remaining blocker.

- **H0 owner-operation inventory (pinned before logging edits):** membership
  (`HouseService::inviteMember`/`removeHouseMember`/`updateUserRole`),
  house settings/reset/gates (`updateHouse`, `resetHouse`, `setCatalogEnabled`),
  setup (`HouseSetupService::applySetup`), structure (`FloorService`
  create/rename/reorder/delete, `RoomService` create/update/delete), owner
  planner (`SchedulingService::updateMemberAvailabilityByNextcloudUserId`),
  reward catalog (`RewardService::createItem`/`updateItem`/`setCatalogEnabled`),
  checklist definitions (`ChecklistService`, item add/delete already logged).
- **Done this round:** H1 (jest discovery exclusion + synthetic worktree
  fixture regression `tests/unit/scripts/jestDiscovery.spec.js`; npm peer
  graph resolved without the bypass — `.npmrc` deleted, clean `npm ci` +
  `npm ls` green, lint/stylelint/build unchanged); H2 (cold-start failure
  REPRODUCED on fresh volumes 2× pre-fix at a deterministic position —
  `AuthorizationIntegrationTest::testRequireHouseOwnerSucceedsForOwner`,
  "Not a member of this house"; mechanism: first-time NC account
  provisioning on a fresh install leaves a dangling transaction on the
  shared connection that later rolls back fixture inserts; harness repair in
  `IntegrationTestCase::actingAs` closes it before fixture writes; two
  consecutive fresh-volume first runs green post-fix; deterministic
  replacement regression `tests/integration/Db/FixtureHouseLifecycleTest.php`
  pins the lifecycle + the genuine-missing-house negative); H3 (service-side
  owner gates with locked rechecks, `CannotRemoveOwnerException`, actor from
  authoritative authorization; native `UserRateLimit(60, 300)` on
  invite/remove/updateRole with a reflection contract test and the
  `family-membership-hardening.spec.ts` 429 scenario; post-commit audit
  events for membership/settings/structure/setup/planner/catalog with
  minimal numeric context — `HouseServiceLoggingTest` pins
  post-commit/no-false-success/no-sensitive-context); H4 (five-above-ground
  basement cap with visible hint + clamp; truthful reset card/confirmation/
  success wording naming the retained facts; unit + required E2E scenario);
  H5 (`score DESC, id ASC` tiebreak in all four ordered reads; equal-score
  ranks stay tied; mapper unit pins).
- **Gates this round:** Jest 1225/1225 (109 suites), ESLint/stylelint clean,
  webpack build green, l10n 1270 keys EN/DE/POT exact, `test:noise`,
  `run-e2e-gate.js --self-test`, Docker PHPUnit unit 1135 + integration 531
  `--fail-on-skipped` zero skips (incl. two fresh-volume first runs),
  `composer cs:check` 0/399.
- **Remaining (blocks `done/`, recorded here per finding routing):** H6
  durable docs (retained-baseline adoption procedure, recovery rehearsal
  record H-T10, rollback-wording corrections); one complete
  `npm run test:e2e:gate` on the final candidate incl. the two new required
  scenarios; H7 reporting channel (operator-blocked); final Standard B/Full
  review; backlog status/archive updates at closure.

- minor · §5 file manifest · `RoleGuardApiTest.php` cited under `tests/integration/Controller/` but the file lives at `tests/integration/RoleGuardApiTest.php` — corrected in the activation round (2026-10-03). Resolved.

Standard A had no remaining findings. The 2026-10-03 Full interim review below
was `BLOCKED` on RF-081-B02. Its engine blocker is resolved by the 2026-10-04
continuation; the latest outcome at the end of this plan is authoritative.

### Full Interim Review Repairs (2026-10-03)

- **Scope/ownership:** explicit CS-PLAN-081; captured unstaged/untracked implementation
  at `master` / `605cd8349baa458ea907794005ad15b6c11a56f5`, index empty (tree
  `06d515417a7386646f826b398d229c6a4790a76f`). Unrelated lifecycle `.gitkeep`
  deletions excluded. Initial unstaged binary-diff SHA-256:
  `3de562602b470baf4a221145fc29564c2def4962c4b987b9d9b6b10764f85cf6`;
  complete staged/unstaged/untracked capture SHA-256:
  `acbc93818900e1d64ab8729eb383f07ba91bb7553a1baf735361ea05bb2e2496`.
- **Decisions:** the user approved Full interim review instead of Standard B
  because authorization is in scope; approved repairing the findings in this
  round rather than deferring them; approved only sanitized public placeholders
  for unremediated security findings. No `--no-commit` override was supplied.
  The final outcome is `BLOCKED` on engine compatibility; no transition or commit.
- **Initial reproduced gates:** Jest 1225, Docker unit 1135 and integration 531
  (zero skips), lint/stylelint/build/l10n/noise/docs/backlog/runner self-test green.
  `npm ls pinia` failed with `ELSPROBLEMS`; the full E2E gate failed Chromium
  discovery because the new membership helper contained `await` outside `async`.
  The earlier H1 `npm ls` and H3 coverage claims are not accepted as proof.

- [x] RF-081-B01 · major · `package-lock.json` · nested router/Pinia peer conflict
  resolved with Pinia 3.0.4; clean `npm ci --legacy-peer-deps=false` and full
  `npm ls --all --omit=optional` pass on Node 20.19.0/npm 10.8.2, without a
  peer bypass. Engine warnings are tracked separately in B02.
- [x] RF-081-B02 · major · `package.json` · Babel-loader upgrade raises the minimum
  above the advertised Node contract; preserve the contract or obtain explicit
  approval for a revised minimum and validate it. Strict Node 20.19.0/npm 10.8.2
  dry-run installation also fails `EBADENGINE`: `@nextcloud/files@4.0.0` requires
  Node 24, while the root still advertises Node `^20.0.0`. No engine range was
  silently changed, warning suppressed or peer bypass restored. Resolved
  2026-10-04 after explicit Node `^20.19.0` / npm `^10.8.2` approval, dialogs
  `~7.2.0`, strict clean installation, complete peer/engine validation and all
  required gates on the exact minimum versions (see latest Review Outcome).

**RF-081-B02 continuation decision (2026-10-04):** one structured question batch
approved the recommended Node `^20.19.0` / npm `^10.8.2` contract and dialogs
`~7.2.0`, with strict minimum-version installation and all required gates before
resolution. The complete locked graph also requires Node 20.19 through stylelint,
its CSS tools, router tooling and optional native tooling; browserslist requires
npm >=10.5.0. The approved npm floor is the bundled, previously exercised 10.8.2,
not a claim that every earlier npm release is technically incompatible.
Dialogs 7.3 alone introduces mandatory files 4 (Node 24); dialogs 7.2 uses files
3.12.2 and retains the app's toast API. A Node-24-only switch would conflict with
the current ESLint, initial-state and Webpack config engines and is not approved.
Align development/build prerequisites and minimum-version CI without changing
Vue 3, Pinia 3, ESLint/Vue policy or repaired runtime behavior. No bypass, wider
toolchain/UI major upgrade, H6/H7 rescoping or retained-baseline adoption approved.
The required engine evidence is now green; this does not imply security clearance
or whole-plan completion.

**Approved engine-repair manifest additions:**
`docs/engineering/{development,deployment}.md` (accurate supported build engines
and strict installation), `.forgejo/workflows/{test,full-matrix,release-gate}.yml`
(exact minimum-version assertions and strict clean-install regression gate),
`tests/jest.setup.deprecations.js` (comment aligned with the approved contract).
Package and lockfile remain existing H1 manifest paths. No runtime authorization,
logging, throttle, reset-retention or required browser-spec behavior is changed.
- [x] RF-081-B03 · block · membership spec · invalid helper replaced; real
  Chromium discovery passes and the membership cases execute.
- [x] RF-081-B04 · major · H-T5 · three real middleware cases pass with fresh
  synthetic owners, exact allowed statuses, otherwise-valid rejected writes,
  full membership-state equality, identity/endpoint isolation and completion
  after exhaustion. APCu supplies native request-shared counters; no cache flush,
  limiter bypass or shortened window. Accounts are test-owned and cleaned up.
- [x] RF-081-B05 · major · browser runner/config · membership and reset specs
  pinned to the serial DB pool, required scenario identities and minimum counts;
  unit/self-test reject missing/wrong-pool discovery and absent passing evidence.
- [x] RF-081-B06 · major · reset browser proof · populated retained setup/structure,
  task/occurrence agreement, completion allocation/history, wallet reservation,
  reward terms and a real earned weekly medal compared through authoritative
  reads after cancel, injected failure, retry and replay. Actual success notice
  asserted; rooms/house health reset separately verified. Focused run: 4/4 pass
  first attempt (3 setup + retention), no retries/skips. The historical performer
  fixture now joins before its work, with an integration regression for recognition.
- [x] RF-081-B07 · major · H3 audit inventory · completed direct/shared-command
  post-commit numeric event seams. Actual logger sink excludes UID snapshots;
  receipt replay, denial, rollback and no-change semantics tested. Follow-up floor
  no-op and catalog/request logging-evidence findings resolved with real command/
  transaction execution, including 33 reward audit cases and empty floor ordering.
  Architecture follow-up returned no remaining findings.
- [x] RF-081-B08 · block · authorization hardening · user-authorized repairs,
  including the private follow-up blocker, verified with denial/rollback/no-success
  regressions and the 532-test integration suite. Final security follow-up returned
  no findings. Technical evidence remains private; no unremediated details published.
- [x] RF-081-B09 · minor · `UserController::updateRole` · typed self-demotion vs
  generic authority denial, with controller regression; backend gates green.
- [x] RF-081-B10 · minor · role-service test callers · obsolete caller-ID arguments
  removed; authoritative sessions and backend integration gates green.
- [x] RF-081-B11 · minor · setup hint · localized total-floor description, not
  always sixth floor; UI/EN/DE/POT parity, Jest and build green.
- [x] RF-081-B12 · minor · Jest discovery regression · subprocess error/signal/
  exit assertions, uniquely created test-owned temporary directories and bounded
  Windows cleanup; explicit no-owned-path cleanup regression. Final full
  Jest/noise gate green (109 suites / 1228 tests).

### Full Interim Continuation Findings (2026-10-04)

Four new read-only tracks reviewed the complete candidate, not only the engine
delta. Main validated and deduplicated their findings against source. These
follow-ups stay with H3 and the owning plan. They are not caused by the engine
repair, not rescoping to the backlog, and not authorization for broader repairs.

- [x] RF-081-B13 · major · `ChecklistService.php:291` · the fresh missing-item
  branch references undeclared `$l` instead of `$l10n`, producing an error rather
  than the intended scoped not-found result. Repair with a between-reads
  disappearance regression, rollback and no mutation/success-audit assertions.
  Resolved 2026-10-04: the branch uses `$this->l10n` for the scoped 404;
  `ChecklistAuditTest` pins the item-disappeared/denial/rollback/visibility-
  revoked modes with rollback and no false-success audit (source + regressions
  re-verified by the interim Standard B review).
- [x] RF-081-B14 · minor · `SchedulingService.php:275` · identical or empty
  availability updates emit `changed` despite preserving preferences. Compare
  locked before/after facts and test `no-change` without logging their content.
  Resolved 2026-10-04: `$preferences !== $previous` on the locked entity drives a
  truthful `no-change` outcome; `testUpdateMemberAvailability_AuditIsNumericTruth-
  fulAndPostCommit` covers empty/identical/changed/cleared-limit inputs.
- [x] RF-081-B15 · minor · `ChecklistService.php:293-295` · repeated per-item
  task-access reads run under the house lock. Deduplicate fresh task IDs while
  preserving authoritative access checks; add a multi-item query-count regression.
  Resolved 2026-10-04: `array_unique` over fresh task IDs with the full
  access-check matrix retained; `testReorder_ManyItems_ChecksFreshTaskAccessOnce-
  PerDistinctTask` pins one access check per distinct task across four items.
- [x] RF-081-B16 · block · shared owner-command review surface · confirmed
  security follow-up prevents clearance. Technical evidence and path distinctions
  remain private under the approved disclosure boundary. Explicit repair approval,
  appropriate regressions and fresh security-review clearance are required.
  Resolved 2026-10-04: `CommandService::runCommand` invokes the under-lock
  `authorizeUnderLock` guard on execution AND replay before any receipt answer;
  every owner-capable command consumer supplies it (occurrence amend/catch-up/
  restart, all five pause commands, scheduling apply, house reset, reward catalog,
  reward-request lifecycle), with fresh-identity/visibility/authority rechecks and
  the documented self-service member boundary unchanged. The new
  `OwnerCommandAuthorizationTest` proves all 36 action×loss×replay combinations
  behind a real two-connection house-lock barrier with domain/receipt snapshot
  equality and no false-success audit. Source-verified and gate-verified by the
  interim Standard B review; technical evidence stays private.

At the blocked review checkpoint, no new runtime fix had been applied outside
the approved dependency/configuration/documentation scope. The subsequent
structured decision below now authorizes this bounded repair round; clearance
still requires the regressions, affected gates and fresh review evidence.

**Repair decision (2026-10-04):** one structured question batch explicitly
approved "Repair now" (recommended) for RF-081-B13..B16, including the affected
shared-command consumers, direct/current-authority, concurrency, replay and
rollback regressions, required gate reruns and review follow-ups. Authorized
manifest extensions are the relevant `AgreementService`, `PauseService` and
`SchedulePlanService` consumers and their matching unit/integration regressions;
exact paths are pinned before edits. Main owns all writes. No broader feature,
retained-data/migration-policy change, H6/H7 rescoping, deployment or final plan
completion is approved. One eligible scoped interim-review commit remains
conditional on canonical clearance; none occurs while `BLOCKED`.

**Pinned repair additions before code edits:**
`lib/Service/{AgreementService,PauseService,SchedulePlanService}.php`,
`tests/unit/Service/{AgreementServiceTest,PauseServiceTest,SchedulePlanServiceTest}.php`,
`tests/integration/Service/OwnerCommandAuthorizationTest.php` (new bounded
direct/race/replay regression suite). Existing `ChecklistAuditTest.php` and
`SchedulingServiceTest.php` remain captured review paths. Pre-repair branch/HEAD/
empty-index verification passed; approved implementation fingerprint remains
`8804bab1c52192dfac677a8118333fe8ff3fa6cd3189f2d66688622086d691ae`.
The complete pre-repair scope including the recorded authorization is
`03246314736325a003b98ec8b9523fc53c38c4908f78e33f1364d47ad58500fe`.

**Repair verification checkpoint (2026-10-04):** all four bounded corrections
are implemented. New checklist/no-op regressions first reproduced their failures;
the final backend gates pass 1313 unit tests and 568 integration tests with zero
skips. The new integration suite proves 36 real consumer execution/replay cases
with an explicit two-connection barrier, original committed receipts and unchanged
domain/receipt snapshots on denial, without extra success audits. The harness
uses the production lock primitive for each supported provider and introduces
no skip; this round's executed DB evidence is the canonical MariaDB profile,
not a WP9 cross-engine evidence claim. `composer cs:check` is 0/404. Strict clean
minimum install, full peer/optional engine graph, Jest/noise 109/1228, lint,
stylelint, build, l10n, runner self-test and docs checks are green. Full browser
rerun and four fresh read-only review deliverables remain required before
closing RF-081-B13..B16 and finalization. Frozen implementation fingerprint
`733d9bad5a79edb5e4ff59e2c8344a4e97dd326c1779f8b48a130276c59ec644`;
81-path complete scope before this evidence-only checkpoint update
`8f4f7be996b7116274efe483edb9d987887541fe344dd1fcc11e19439e7dc8d6`.

**Repair cluster evidence:** Docker unit 1241/1241 (4035 assertions, zero skips)
after authoritative owner denial and post-commit audit tests; scoped PHP fixer
applied to authorized repair paths only. Final candidate's first full integration
run on another independently fresh installation: 532/532 (2979 assertions, zero
skips), after waiting for entrypoint/account/seed completion. This supplies the
remaining cold-start evidence; the earlier 531-test fresh run preceded the richer
historical fixture/regression. Frozen-candidate full browser gate `run-pqNIwe`
passes all 49 required specs across three sequential pools, with zero required
skips/failures/flaky cases and no retries. H6/H7 remain required with no rescoping;
H1's engine-compatibility probe is red. No passing final review, completion
transition or automatic commit is claimed.

**Repaired H0/H3 exact inventory:**

| Surface | Methods | Operational event seam |
| --- | --- | --- |
| Membership/settings | `HouseService::inviteMember`, `removeHouseMember`, `updateUserRole`, `updateHouse` | Post-commit `AppLogger::audit`, authoritative numeric actor/member/house IDs |
| Reset | `HouseService::resetHouse` | `CommandService` post-commit `house.reset` receipt; replay emits no second committed event |
| Setup | `HouseSetupService::applySetup` | Post-commit numeric setup counts/IDs, no authored setup payload |
| Floors | `FloorService::createFloor`, `updateFloorName`, `reorderFloors`, `deleteFloor` | Post-commit structural events |
| Rooms | `RoomService::createRoom`, `updateRoomName`, `deleteRoom` | Post-commit structural events |
| Checklist definitions | `ChecklistService::addTaskItem`, `updateTaskItem`, `deleteTaskItem`, `reorderTaskItems` | Post-commit numeric item/task/house IDs, no label text |
| Planner | `SchedulingService::updateMemberAvailabilityByNextcloudUserId`, `SchedulePlanService::apply` | Direct post-commit numeric member event; shared committed schedule receipt |
| Agreements | `AgreementService::amend`, `catchUp`, `restart` | Shared committed occurrence receipt (also covers authorized self-service actions) |
| Pauses | `PauseService::requestForSelf`, `createHousePause`, `approve`, `reject`, `recognizeIllness`, `shorten` | Shared committed pause receipt, never reason/medical text |
| Catalog | `RewardService::createItem`, `updateItem`, `setCatalogEnabled` | Numeric item/house/actor events with explicit replay/no-change outcomes |
| Reward lifecycle | `RewardRequestService::approve`, `reject`, `cancel`, `fulfill`, `correct` | Post-commit request/action/actor IDs; replay emits no duplicate committed event |

Read-only surfaces, jobs, non-owner room-task execution, digest preferences and
unrelated administrative systems are not claimed as owner-change coverage.
The shared command event does not imply that every accepted command required
owner authority. Durable event semantics and diagnostic-vs-audit distinction
are in `docs/standards/guidelines/logging.md`; native-cache test prerequisites
and isolation are in `docs/engineering/testing.md`.

### H6 Implementation Findings (2026-10-04)

- [x] RF-081-H6-01 · minor · `RewardService::createItem` · strict synthetic
  recovery seeding reproduces an undefined-key warning for an omitted optional
  description. The normalization contract requires title/price only; the insert
  must default the optional description to null. Bounded safe fix paths pinned:
  `lib/Service/RewardService.php`, `tests/unit/Service/CatalogAuditTest.php`.
  Real callback-execution regression reproduced the undefined-key error before
  the fix; all 14 `CatalogAuditTest` cases pass after null-default insertion.
  The warning-free rich recovery fixture also passes. No new feature,
  authorization, schema or receipt contract.

**H7 operator checkpoint (2026-10-04):** the structured question returned
"Not ready yet" (`Noch nicht bereit`). No approved address/key/fingerprint,
controlled receipt/decryption or authorized profile-publication evidence is
available. H7/H-T11 remain required and explicitly operator-blocked; no
rescoping, external action, unapproved identity publication or completion.

- [x] RF-081-H6-02 · major · recovery runner · internal read-only check found
  missing upstream failure propagation in the file-manifest pipeline. Repaired
  with `bash -o pipefail -ec`, a non-empty manifest assertion and an executed
  upstream-failure negative control; final H-T10 rejects it with expected exit 1.
- [x] RF-081-H6-03 · minor · recovery runner · cleanup now attempts every owned
  container/network independently, retains the original error plus teardown
  failures, and finalizes PASS only after complete teardown. Injected first-
  removal failure self-test and final positive teardown both pass.
- [x] RF-081-H6-04 · minor · recovery evidence · redacted operation identities and
  explicit exit codes now include archive capture, readiness, candidate identity,
  assertions and cleanup. Named actual sanitized record integrated into the
  permanent release test plan; raw manifests/config/data remain private.
- [x] RF-081-H6-05 · major · integration gate · after an initial tool-timeout/load
  episode, a complete run failed `SchedulePlanConcurrencyTest`'s due-date-holder
  final row assertion plus four subsequent missing-house/member fixture errors
  (568 tests, 1 failure/4 errors). The focused race passes 2/15; an exclusive full
  diagnostic repeat passes 568/3182, zero skips. That rerun is not root-cause
  evidence or closure: investigate inherited transaction/fixture state and record
  a deterministic regression/disposition before final review. No production
  fallback or blind fixture commit is introduced.
  Read-only investigation confirms that test cleanup retains NC accounts, the
  H2 provisioning guard is limited to new-account creation, and PHPUnit's
  `depends,defects` result cache can reorder the next run. An inherited outer
  transaction or connection/nesting desynchronization remains a hypothesis, not
  an established root cause. Required next diagnostic: boundary transaction
  invariants/opener evidence and cache-independent full-suite ordering; do not
  normalize unknown transaction state by blindly committing it.
  Additional cache-independent `--order-by=default --do-not-cache-result` full
  run passes 568/3203 with zero skips after the browser gate. This removes the
  result-order confounder from the current green evidence but does not establish
  the earlier failure's root cause; H6-05 stayed open at this historical checkpoint.
  Closed only by the explicit 2026-10-05 evidence-bound disposition and independent
  correctness clearance below, not attributed to the holder or repaired by reruns.

**H6 implementation evidence (2026-10-04):** future-only retention boundary
integrated in bootstrap/canonical rules, backend/architecture skills and permanent
deployment/testing/release docs. Unsafe code-only rollback/shared Redis flushing
recipes removed; quiescence, other-app impact, operator approval and exact
baseline adoption identity explicit. New guarded executable recovery harness
passes H-T10 (`cs081-recovery-e6d385fad062`, 29.50-second recovery interval,
70.62-second full preparation/capture/rehearsal). This is a MariaDB synthetic
same-build restore, not production validation, upgrade/WP9 portability evidence
or adoption. H7 remains required; no plan closure, standalone backlog archive,
commit, tag, deployment or external publication in this implementation round.
Internal read-only safety follow-up closes H6-02..04 with no new H6 findings;
this is not the final Standard B/Full review and does not clear H6-05/H7.

**Current-candidate gates (2026-10-04):** Node 20.19.0/npm 10.8.2 used through
the isolated `npx --package=node@20.19.0 --package=npm@10.8.2` wrapper (host
defaults are newer; neither project engines nor global configuration changed).
Jest `--runInBand`: 109 suites/1228 tests, zero pending; noise gate clear with the
approved warning baseline unchanged; lint/stylelint/build, l10n 1270 exact keys,
runner self-test green. Docker unit 1314/4246 assertions and integration
568/3203 (`--fail-on-skipped`, zero skips; cache-independent final diagnostic
run), `composer cs:check` 0/405. Full browser `run-ssTUDT` passes all 49 required
specs in three sequential pools, no required skips/failures/flaky cases/retries;
integration, recovery and browser mutation jobs did not overlap. Initial loaded
120-second shell timeouts and the subsequent failing integration attempt are
retained in H6-05, not erased by these passes. Backlog/encoding/whitespace checks
pass. These are implementation checkpoint gates, not whole-plan final-review
clearance: H7 and H6-05 remain open, no final review/commit/`done/` transition.

### H6 Full Interim Review Evidence (2026-10-04)

- [x] RF-081-H6-06 · minor · recovery readiness · user-authorized authenticated
  TCP/application `SELECT 1` repair replaces socket-only ping. Rejection/retry,
  bounded exhaustion and exact argument-vector self-tests pass; source follow-up
  is clear. Corrected H-T10 `cs081-recovery-0f68dce7b4de` passes all 30 app
  snapshots, whole DB/file equality, negative controls and complete owned cleanup.
- [x] RF-081-H6-07 · minor · `tests/integration/Controller/TestModeApiTest.php` ·
  randomized seed 8105 exposed an absolute zero-house assertion in the invalid-
  profile no-writes proof. A test-owned nonempty house reproduces the old failure;
  the authorized repair compares exact ordered house snapshots before/after.
  Focused 8/61, randomized 568/3182 and default 568/3182 pass, zero skips; focused
  read-only review reports no findings. No production behavior was changed.
- [x] RF-081-H6-05 · major · integration fixture/transaction diagnosis · earlier
  due-date-holder failure and subsequent missing fixtures remain unexplained.
  Same-process read-only DBAL/native-PDO probes record 2,272 before/after
  boundaries across cached and randomized full runs: all nesting 0/native false,
  no observed leak/desynchronization. This does not sample in-test/helper state
  and cannot prove the earlier cascade's cause. The due-date helper signals before
  commit without a separate commit barrier and computes its expected day
  independently of the parent. These are concrete remaining diagnostic seams,
  not proven production defects. Keep mandatory deterministic disposition open;
  never repair by blindly committing inherited transaction state.
  Historical open checkpoint superseded solely by the user-approved 2026-10-05
  disposition and independent clearance in Final-Candidate Investigation.

**Decisions:** structured batches approved Full interim escalation, H6-06 repair,
in-round H6-05 diagnosis, H6-07 repair, then keeping H6-05 open with the above
evidence. No production/security repair, required-work rescoping, H7 publication,
retained-baseline adoption or completed-plan claim was authorized. Private probe
scripts/logs are diagnostic artifacts, not production code or a committed gate.
H7/H-T11 remain required and operator-blocked.

### H6-05 Deterministic Diagnosis And Authorized Holder Repair (2026-10-04)

- **Capture before intervention:** `master`, HEAD
  `46d37a29ce705fd187ceb43aacaa2d694dfff512`, index empty; full index-entry
  SHA-256 `4dcd956c326aa8dc3e030072f8767cae742217810168b82bbac521409a2e4c9d`;
  staged binary diff SHA-256 `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`;
  unstaged binary diff SHA-256 `c4f08ea7358ad9c823860656b5adb688fabb071688c080a0261e95e28c3d948a`;
  complete staged/unstaged/sorted-untracked capture SHA-256
  `d9dd6d8ca586c40fdbb55d99ef134cf2bff118b54cee7e7fd2fd6702c1f28623`.
  The capture includes, but every reviewed/write/commit set excludes, the three
  unrelated lifecycle `.gitkeep` deletions. Untracked runner/fixture content
  hashes match the preceding review exactly. Capture algorithm and manifest
  are recorded in the review record.
- **Observed, not inferred:** a private bootstrap instruments the unchanged
  shared test base and executor in memory, without normalizing transactions.
  The full default-order run passes 568/3183, zero skips. Its 12,843 same-process
  records include 1,587 begin pairs, 1,465 commit pairs, 122 rollback pairs,
  fixture inserts/account boundaries and 525 before/after base cleanup pairs.
  Every sampled DBAL/native-PDO pair agrees; nesting is 0/false outside and
  1/true inside the sampled transaction operations. No pre-cleanup leak was
  observed. This is intermediate-state evidence, not a historical-cause proof.
- **Deterministically reproduced harness failure class:** injecting an early
  conflict before the holder commits produces the original assertion class
  (expected next day, actual current day). The helper is still running when
  the old `finally` terminates it; its independent uncommitted edit rolls back.
  Normal real-apply tracing observes the parent's 1/true pre-rollback and
  0/false post-rollback/cleanup, and the holder's successful native commit.
  The injected conflict is a negative control, not proof of the original trigger.
- **Timeout process fact:** a bounded, DB-free private PHPUnit test starts a
  helper, then a two-second shell timeout kills the Docker client. `docker top`
  subsequently observes both container PHPUnit and helper still alive (about
  ten seconds elapsed); both exit within their bounded lifetimes. No later
  mutation job starts before container-side exit is verified. Historical
  timeout overlap remains a hypothesis, not an attributed original cause.
- **Separate missing-fixture disposition:** killing the row holder's independent
  transaction does not erase the parent's or later tests' house/member fixtures.
  The four historical errors are therefore not attributed to this holder fix.
  Original per-error names/stacks are absent from the available raw artifacts;
  historical secondary investigation mentions scheduling preferences and seed
  fixtures but cannot supply a defensible four-error mapping. Shared-connection
  inheritance/desynchronization and overlapping timed-out writers remain
  unconfirmed hypotheses. RF-081-H6-05 remains open; no rerun-only acceptance.
- **Structured decisions:** the operator approved `Holder absichern`, explicitly
  chose `Offen halten` for H6-05 and confirmed `Weiterhin nicht bereit` for H7.
  H7 still lacks approved address/key/fingerprint, controlled receipt/decryption
  and publication approval/evidence. No rescoping or external action authorized.
- **Pinned bounded repair:** `tests/integration/Service/SchedulePlanConcurrencyTest.php`
  now passes one parent-computed UTC target day, requires a separate post-commit
  marker and successful helper exit before cleanup, and fails boundedly without
  either proof. Real race, early-conflict wait, missing-commit rollback and stalled
  helper rejection pass 5 tests/35 assertions. `IntegrationTestCase::actingAs`
  and production `TransactionExecutor` are unchanged. Private probes remain
  gitignored diagnostic aids, not the durable regression.
- [x] RF-081-H6-08 · minor · holder timeout negative control · the initial
  half-second sleep helper could exit before the parent checked its timeout
  under load. Correctness-track finding repaired within the authorized bounded
  holder scope: readiness signal plus a STDIN hold until parent cleanup replaces
  the timer. Focused 5/35 and final complete integration 571/3202 pass, zero
  skips; read-only correctness follow-up returns no remaining findings.

### Remaining-Package Standard B Findings (2026-10-04)

- [x] RF-081-H6-09 · minor · recovery runner log redaction · the private
  `commands.log` sanitizer anchored its `-p<value>` pattern at argument start
  only, so the synthetic root password embedded in the dump/restore shell
  strings (`mysqldump -uroot -proot …`, lines 158–162/170–171) was logged
  unredacted while every separate password argument was redacted. The
  credential is synthetic, hardcoded in the tracked runner and confined to the
  gitignored `private/` log, but this contradicted the runner's own redaction
  intent and the RF-081-H6-04 evidence claim. User-approved in-round repair
  ("Fix now + re-rehearse"): extracted testable `Get-SanitizedArguments` with
  `(?:^|\s)-p\w` token matching, added a dedicated self-test, reran the full
  isolated rehearsal `cs081-recovery-673681ab35b7` (PASS; executed log contains
  zero unredacted occurrences) and recorded the fresh hashes in the release
  test plan. No rehearsal behavior, ownership or assertion change.

### Final-Candidate Investigation (2026-10-05)

- [x] RF-081-H6-10 · minor · `TimezoneDayBucketingTest.php:169` · the first
  final default-order integration gate fails 571/3202 with one failure and zero
  errors/skips: at Sunday 22:00+ UTC Berlin has entered Monday's week while UTC
  remains in the previous week. The test incorrectly requires only a 1-2 hour
  offset between independently sampled current-week starts. This is separate
  from the historical H6-05 fixture errors. Pinned test-only repair:
  `tests/integration/Service/TimezoneDayBucketingTest.php`, using the existing
  `WeeklyGoalService::progressForWeek` explicit-clock seam and exact summer,
  winter and cross-week Sunday boundary expectations. No production change.
  The red run remains evidence; container process inspection confirms no
  PHPUnit/helper survived before another mutation job started.
  Fixed with four deterministic explicit-clock datasets: focused 7/29 and
  uninstrumented full default-order 574/3219 pass, zero skips/errors/failures.

**H6-05 bounded investigation:** read-only artifact inventory confirms the
original four per-error names/stacks and contemporaneous command/process
timestamps are absent. The retained plan/review summaries establish the
568-test run's 1 failure/4 errors, not a defensible mapping of those four errors
to individual tests. The original evidence gap cannot be reconstructed by a
new green run. No root cause for that quartet is claimed.

Source inspection confirms owned app-row cleanup, retained Nextcloud accounts,
new-account-only H2 provisioning handling, instance-wide destructive test seeding
and cached `depends,defects` ordering. Scheduling-preference fixtures use fresh
unique account identities; the seed tests deliberately replace the disposable
app state. These are lifecycle facts, not evidence that any specific historical
test leaked a transaction or overlapped a writer.

Exclusive bounded uninstrumented diagnosis covers `FixtureHouseLifecycleTest`,
`SchedulePlanConcurrencyTest`, `SchedulingPreferencesIntegrationTest` and
`TestDataSeedTest`: 21/147 pass. A separately logged same-process diagnostic of
the identical set passes 21/147 and records 568 states: 105 begin pairs,
103 commit pairs, 2 rollback pairs, 21 pre/post cleanup pairs and fixture/account
boundaries. All sampled DBAL/native pairs agree; no sampled fixture, account-entry
or cleanup boundary carries a transaction. This extends, but does not replace,
the earlier 2,272 boundary and 12,843 intermediate records. The private probes
are not committed gates, do not normalize transaction state and preserve the
historical logs. No further mutation overlap or client timeout occurred here.

**Accepted disposition (2026-10-05):** accept H6-05 as an unattributed
historical integration anomaly with an explicit irrecoverable evidence
gap, not a demonstrated fix of the four errors or a zero-risk guarantee. The
separately repaired holder has deterministic regression coverage; fixture
lifecycle and genuine-missing-house regressions remain mandatory. Enforce
exclusive jobs and verify container process exit after client timeouts; on any
recurrence retain exact failures and in-process/process evidence before reruns
and reopen investigation. Root-cause attribution to holder, transaction leak or
overlap remains prohibited without proof. The structured batch explicitly approved
`Disposition freigeben (Recommended)`; the independent correctness follow-up
explicitly accepted this evidence-backed disposition with no remaining finding.
RF-081-H6-05 is closed as accepted uncertainty, not a demonstrated cause/fix of
the quartet. No required gate,
WP9 matrix/browser pair/human evidence or adoption obligation is waived.

**Fresh final-candidate gates so far:** exact Node 20.19.0/npm 10.8.2 wrapper;
strict clean `npm ci --engine-strict --legacy-peer-deps=false`, full `npm ls --all`
and all 1,018 locked Node/npm engine ranges (including optional packages) pass.
The first inline engine-check command had a PowerShell quoting/parser failure;
it did not execute Node or mutate dependencies. A private script then completed
the check without bypass/suppression. npm reports the unchanged 77 advisories
(8 low/9 moderate/60 high); no audit-clean claim or force upgrade.
Jest/noise 109/1228, lint/stylelint/build, l10n 1270 exact keys and runner self-test
pass; Docker unit 1314/4246, post-repair default integration 574/3219
(`--fail-on-skipped`, zero skips) and `composer cs:check` 0/405 pass. Unit/JS
sources are unchanged by the timezone-test repair. Browser and final docs gates
are still pending at this checkpoint; no final verdict, commit or transition.

**Final Full review repair decision (2026-10-05):** all four independent tracks
delivered valid findings. The user explicitly approved `Jetzt reparieren
(Recommended)` for the bounded security/data-integrity fix, missing H-T7/H-T9
regressions, gate reruns and sanitized public tracking; technical unremediated
security details remain private. Main owns every write.

- [x] RF-081-H6-11 · major · `docs/api/README.md` · missing/contradictory native
  membership rate-limit and operational-audit contract. Corrected factual
  documentation: per-user/per-endpoint 60/300 elapsed seconds, native 429 outside
  app envelope, request-shared cache assumptions and post-commit numeric logging
  with Nextcloud-managed retention/durability limits; separate minigame limit.
- [x] RF-081-H6-12 · minor · `docs/release/release-strategy.md` · stale mandatory
  reporting key/decryption requirement. Aligned with the approved H7 rescope and
  later reachable-mailbox/publication obligation, GPG optional.
- [x] RF-081-H6-13 · major · H-T7 · browser cap/basement/apartment-toggle evidence
  missing despite the earlier completeness claim. Required new journeys belong
  to the existing serial spec and required discovery manifest; no runtime change.
  Added two required serial journeys covering input clamp/hint, off/on and
  apartment/house toggles, six preview floors, submitted names and authoritative
  persisted names with basement present only for the house case. Focused 5/5
  actual journeys plus 3 setup projects pass first attempt, zero skips/retries.
  The first focused run failed a new assertion that read `entry.name` instead of
  the existing API's `entry.floor.name`; 3 later serial cases did not run. That
  failed attempt remains private raw evidence, not valid completed coverage.
- [x] RF-081-H6-14 · major · H-T9 · missing real-DB equal-score ordering/tied-rank
  and period/house isolation proof. Add exact row-identity assertions for all
  four ordered reads and equal-rank expectations; no ranking-rule change.
  Exact real entity-ID expectations now cover global/period/personal-best/user
  reads, strict tied ranks, foreign-house and other-game exclusion and an old
  higher score excluded from the period. All rows/houses are explicitly owned
  and cleaned; focused backend repair cluster passes 65/362, zero skips.
- [x] RF-081-H6-15 · major · reset authority/receipt identity · independent security
  review identified a remaining live-actor contract edge case. Repair explicitly
  approved with execution/replay race regressions and no false-success evidence;
  detailed unremediated evidence remains private. Clearance is required before
  completion or commit.
  Implemented minimal original/current membership-ID comparison before receipt
  lookup on execution/replay. The real two-connection suite now covers reset
  demotion, departure and replacement identity with domain/house/room/journal/
  receipt equality and no extra success event: 42/260 pass. The validated
  original-guard negative control fails both replacement cases (2 tests, 5
  assertions), followed by green repaired coverage. Earlier scaffolding attempts
  used unsupported `fetchAssociative` on a Nextcloud result and failed; these
  are not valid original-defect proof. A private in-memory probe produced no
  test output and is not accepted as a passing diagnostic. No blind transaction
  normalization, production fallback or skip was added. Independent security
  follow-up inspected the corrected guard/barrier/cleanup and returned no finding;
  security clearance now covers the bounded repair (no independent gate execution).

Pinned repair paths: `lib/Service/HouseService.php`,
`tests/integration/Service/OwnerCommandAuthorizationTest.php`,
`tests/integration/Controller/MinigameControllerIntegrationTest.php`,
`tests/e2e/setup-wizard-journey.spec.ts`, `tests/e2e/required-scenarios.json`,
`scripts/run-e2e-gate.js` and matching runner unit tests if required; permanent
API/release/testing docs, owning plan/tracking/review record. No broad refactor.
The pre-repair browser attempt was deliberately interrupted in the third pool
before runtime edits; it is retained, has no complete-gate validity and is not
relabelled PASS. Container process inspection showed no PHPUnit/OCC/helper writer
after stop and before the repair tests. A complete post-repair gate is mandatory.

**Post-repair evidence checkpoint:** Jest/noise 109/1229, unit 1314/4246,
uninstrumented default-order integration 581/3282 (zero skips), lint/stylelint,
build, l10n 1270, runner self-test and PHP standards 0/405 pass. Fresh H-T10
`cs081-recovery-2f8aff4b3ee8` passes all snapshots/DB/files/negative controls and
complete owned teardown on the changed runtime/build archive. Full browser
gate is running exclusively; final tracking/docs gates and commit remain pending.
The first post-repair whole browser attempt rejected the new scenario inventory
ordering at discovery, before mutations. Corrected alphabetical ordering and a
real-inventory Jest pin pass: final Jest/noise are 109/1230. The complete fresh
post-inventory gate is required; no failed attempt is removed or relabelled.
The first scoped PHP-fixer command rejected missing explicit configuration;
the corrected four-path command changed zero files. A supplementary ESLint
invocation incorrectly applied production-only rules to the CLI runner (16
existing console errors/9 JSDoc warnings); it changed no unrelated code and is
not the canonical lint gate. Canonical `npm run lint` passes with its intended
source/E2E scope; CLI output is not suppressed or replaced by a production logger.

### Decisions for Standard A

All entries were presented as one structured question batch in the 2026-10-03
Standard A review; every recommended option was explicitly approved and is
recorded per row below. They are resolved decisions, not pending proposals.

| ID | Recommended option | Alternative / consequence | State |
|---|---|---|---|
| Q-081-01 | Preserve all data by default from one explicitly adopted post-WP9 technical baseline; no retroactive support for disposable installs | Enable preservation at this plan's activation instead: protect the current chain immediately and revise WP9's in-place fresh-chain repair assumption | Resolved 2026-10-03: post-WP9 baseline adoption (recommended option approved) |
| Q-081-02 | Native UserRateLimit: 60 calls / 300 elapsed seconds / authenticated user / invite, remove and updateRole separately | 30 / 300 for the same routes: stricter; no new aggregate limiter/table selected by either option | Resolved 2026-10-03: 60 calls / 300 elapsed seconds per endpoint |
| Q-081-03 | AppLogger operational owner-change events with NC-managed retention and explicit durability limits | Durable dedicated audit ledger: new schema/privacy/effort design required; revise before activation | Resolved 2026-10-03: AppLogger operational contract with NC-managed retention |
| Q-081-04 | Use an operator-approved existing dedicated mailbox and usable public key; H7 remains required | Operator provisions a new dedicated mailbox/key with explicit authorization; no agent account creation or unapproved external publication | Resolved 2026-10-03; superseded by explicit H7/H-T11 removal for operator-only self-testing on 2026-10-04 (see H7 Post-Review Scope Decision) |
| Q-081-05 | Visible five-above-ground limit when basement selected, six total, consistently bounded input/preview | Explicitly explain basement replacement of the sixth floor before confirmation; no silent omission allowed | Resolved 2026-10-03: five above-ground floors with basement, six total (Q-081-05) |

## Draft Validation

2026-10-03: `npm run backlog:check`, `npm run docs:encoding` and
`git diff --check` passed. Both existing documentation checkers also passed with
Git discovery extended in-process to `ls-files --cached --others
--exclude-standard`, including this untracked draft (34 link-checked scoped
files, 879 encoding-checked text files). No checker, Git index or runtime file
was changed for that supplementary validation.

Backlog status descriptions were corrected without closing open items; the
draft/parent/WP9 sequence and rolling registry were updated. This is planning
evidence only, not Standard A, runtime validation, reporting-channel proof, a
recovery rehearsal or deployment. Q-081-01..05 remain proposals for the required
review. Unrelated pre-existing `.gitkeep` deletions remain untouched.

## Review Outcome

The outcomes below are historical review evidence. The later H7 Post-Review
Scope Decision in section 9 overrides their H7/H-T11 completion requirements,
without changing the recorded verdicts or clearing RF-081-H6-05/final gates.

**Standard A — 2026-10-03 — `PASS-WITH-FIXES`** (Kilo main agent; no subagents — Standard A tier; one minor manifest path fixed in-round).

- **Scope:** this plan file; the uncommitted drafting package named in §5
  (doc/plan indices, backlog/live registry, monthly archive rollover, parent and
  WP9 sequencing notes) was reviewed as the plan's declared manifest. No runtime
  file touched.
- **Baseline:** `master` @ `76e282e`; index empty (tree `5b868e3`); unstaged
  binary-diff SHA-256 `E182140A555BD74DFB3095DF853AB9DC0B1D33D3B2DBB9F9EE8077EEEF10E617`;
  untracked plan SHA-256 `CC506A10DE4A63AC19813CA1011E5A87AF5AF5146CD226E9318063847FB4C090`.
  Unrelated pre-existing `.gitkeep` deletions preserved unstaged.
- **Verification:** baseline citations exact against the tree — migration head
  `Version1033`; `HouseService::inviteMember` has no owner parameter
  (lib/Service/HouseService.php:156) while `removeHouseMember`/`updateUserRole`
  take caller parameters (:227/:303); all four ordered `MinigameScoreMapper`
  queries lack the `id` tiebreak; `SetupWizard.vue:551-555` silently drops the
  basement at six floors; `.npmrc` enables `legacy-peer-deps`; `jest.config.js:4`
  ignores only `node_modules`/`archive`/`.kilo` test paths while module-mapping
  mocks. Every §4 gate command exists (`test:noise`, `l10n:check-keys`,
  `stylelint`, `test:e2e:gate` verified in package.json). All ten cited backlog
  IDs exist and are open. No overlap with active CS-PLAN-070/080 (boundaries
  cross-referenced in all three plans). Template sections 1-9 complete; every
  slice H1-H7 has a named backlog owner and a validatable completion boundary.
  §5 manifest verified against the tree: every M path exists and no N path
  exists (after the RoleGuardApiTest.php path fix below).
- **Decisions:** Q-081-01..05 resolved via one structured question batch, all
  recommended options approved (post-WP9 baseline adoption; 60 calls/300 s per
  endpoint; AppLogger operational audit contract; existing operator-approved
  mailbox/key; five-above-ground basement cap, six total). Recorded in §9.
- **Fixes:** one minor — §5 manifest cited `RoleGuardApiTest.php` under
  `tests/integration/Controller/`; the file lives at `tests/integration/RoleGuardApiTest.php`
  (verified). Corrected; drafting gates rerun green.
- **Gates:** `npm run backlog:check` (33 scoped files), `npm run docs:encoding`
  (878 tracked files) and `git diff --check` green before and after activation
  edits. Runtime gates belong to implementation H1-H7.
- **Transition:** moved `new/` → `active/`. Standalone owners TECH-JEST-001,
  TECH-LINT-006, TECH-TEST-003, CR-SEC-05, SEC-AUDIT-001, BUG-SEC-001,
  FEAT-UX-032 and the CR-053-11 Snake remainder marked In Progress under this
  plan; CONCEPT-001/RELEASE-002 keep their parent/partial ownership. Review
   record appended to `docs/backlog/archive/CODE_REVIEW_2026-10-03.md`.

### Full Interim - 2026-10-03 - BLOCKED

- **Scope/identity:** explicit plan owner, captured implementation plus authorized
  review repairs; exact 67-path pre-finalization manifest and untracked content
  hashes in the review record. `master` / `605cd8349baa458ea907794005ad15b6c11a56f5`,
  staged set empty. Scoped snapshot SHA-256:
  `85e530fa4b43f4cdf230748add4119edb5b09d8cc4341accc6b7f57b7be29949`.
  Review-generated evidence edits follow that snapshot; unrelated `.gitkeep`
  deletions remain untouched and excluded.
- **Decisions:** structured user approvals for Full interim escalation, in-round
  security/behavior repairs and sanitized-only public security placeholders.
  No engine-policy change or required-work rescoping was approved.
- **Findings:** RF-081-B01/B03..B12 resolved with regressions; all four final
  read-only follow-up tracks clear. RF-081-B02 remains required and open: declared
  Node `^20.0.0` conflicts with Babel-loader's newer minimum and the installed
  `@nextcloud/files@4.0.0` Node 24 requirement.
- **Gates:** Jest 109/1228, Docker unit 1241/4035 assertions, fresh-install first
  integration 532/2979 assertions, lint/stylelint/build/noise/l10n/self-test,
  backend standards and docs/backlog/whitespace green. Full browser gate passes
  all three pools with zero skipped/failed/flaky required scenarios. Strict
  engine installation probe on Node 20.19.0/npm 10.8.2 fails `EBADENGINE`.
- **Final implementation checkpoint:** the test-only ownership safeguard was
  verified by rerunning full Jest/noise; app/backend/browser code unchanged.
  Implementation/durable-doc fingerprint excluding only review-generated plan,
  tracking and record plus unrelated `.gitkeep` deletions:
  `346c493491ccde4960fce99c443e5cdff0e35ae0e8680da9768d1154bc11cd48`.
- **Routing/docs:** remaining finding stays here, not in a duplicate backlog
  item. Exact audit/native-cache contracts integrated in permanent docs;
  sanitized substantial review record updated. No standalone item is declared
  complete or archived while its owning plan/review remains blocked.
- **Transition/commit:** none. Plan stays `active/` because the required engine
  gate is red and H6/H7 are incomplete. Canonical `BLOCKED` finalization stops
  before staging/commit; no branch change, push, deployment or baseline adoption.

### Full Interim Continuation - 2026-10-04 - BLOCKED

- **Owner/scope:** complete uncommitted CS-PLAN-081 candidate: prior exact
  67-path implementation/review manifest, its review record and six approved
  engine-support additions (74 paths). Unrelated three lifecycle `.gitkeep`
  deletions preserved/excluded. H6 recovery/docs and H7 operator evidence remain
  required, unimplemented and unrescoped; this is not a completion review.
- **Initial identity:** `master` / `605cd8349baa458ea907794005ad15b6c11a56f5`,
  index empty, index-entry SHA-256
  `8161914fd6b502f0b387fad9fd485b1f7edcaad7217c0ead567eab50326a833f`.
  Complete initial scope SHA-256
  `db3725dd2eae1af59f28043ece57f172b457f2a851cc60a9930ce30fe87e19d3`;
  initial implementation fingerprint matched the prior checkpoint exactly.
- **Decision/repair:** one structured question approved Node `^20.19.0`, npm
  `^10.8.2` and dialogs `~7.2.0`. npm regenerated the compatible files 3.12.2 /
  sharing 0.3 graph. Existing Vue 3, Pinia 3, ESLint/Vue policy and previous
  runtime repairs are unchanged. Durable build prerequisites and all three CI
  workflows now pin/assert the exact minimum and require strict clean installs.
- **Frozen reviewed identity:** scoped unstaged binary-diff SHA-256
  `1577c833dba1cab74b8450b79ec50a623577a79dcb2b33b2a7bf498a06ea1a51`;
  complete reviewed scope SHA-256
  `cbed20d0b577d34e5819a6de7894e42cfa5aa2c91dea398be2867d8fb4e1652a`;
  implementation/durable-doc SHA-256 excluding evidence-only plan/registry/record
  `8804bab1c52192dfac677a8118333fe8ff3fa6cd3189f2d66688622086d691ae`.
  All ten untracked content hashes match the previous final checkpoint. The
  overnight branch/HEAD/index/content check matched exactly; outcome-only evidence
  edits below follow this snapshot.
- **Required gates:** strict `npm ci --engine-strict --legacy-peer-deps=false`,
  full `npm ls --all` including optional peers, and every locked Node/npm engine
  range including optional packages pass on exact Node 20.19.0/npm 10.8.2.
  Jest 109 suites / 1228 tests, noise gate same counts and approved warning
  baseline unchanged; Docker unit 1241/4035 assertions, integration 532/2980
  assertions with `--fail-on-skipped` and zero skips; lint, stylelint, production
  build, l10n 1270 exact keys, runner self-test, `composer cs:check` 0/403,
  backlog/encoding/whitespace green. Full browser gate `run-rCVDzo` passes all
  49 required specs across three sequential pools, zero required skips, failures,
  flaky cases or retries. Integration finished before browser seeding/execution.
- **Full review:** all four read-only deliverables received and validated:
  correctness, architecture, security/auth/data-flow, performance/quality.
  Duplicate findings consolidated as RF-081-B13..B16. Main source inspection
  confirms them. Security clearance is absent; no passing outcome is inferred
  from green runtime gates. npm's 77 reported audit advisories remain visible;
  the security track found no confirmed app exploit path from those advisories,
  and no audit-clean or unrelated major-upgrade claim is made.
- **Routing/finalization:** RF-081-B02 resolved; four newly confirmed findings
  remain plan-owned. Only sanitized security placeholders are public. Durable
  engine docs, substantial review record and live registry updated; no duplicate
  backlog item or completed-owner archive entry. Behavior/security repair
  authorization is not inferred from the engine decision or prior approvals.
  `BLOCKED` means no automatic staging/commit; branch/HEAD/index are unchanged,
  plan stays `active/`, no push/deployment/baseline adoption or policy transition.

### Standard B Interim - 2026-10-04 - PASS

- **Tier/scope:** Standard B (code) interim review of the complete uncommitted
  CS-PLAN-081 candidate — the prior 67-path manifest, the approved engine
  additions and the authorized RF-081-B13..B16 repair round (65 modified
  implementation/durable-doc paths plus 11 untracked; evidence files tracked
  separately). Explicit plan owner; not a completion review: H6 recovery/docs
  and H7 operator evidence remain required and unrescoped, so the plan stays
  `active/` regardless of this outcome.
- **Identity:** `master` / `605cd8349baa458ea907794005ad15b6c11a56f5`, index
  empty at capture; full raw unstaged diff (all paths incl. evidence and
  `.gitkeep` deletions) SHA-256
  `c74ae8e408c65587bd0112b9b4108f6afd9b915282487763f3143b70ecdeabb9`.
  Drift check against the frozen checkpoint: 9 of the 10 recorded untracked
  content hashes match byte-for-byte; `ChecklistAuditTest.php` differs exactly
  by the authorized B13/B15 regressions; `OwnerCommandAuthorizationTest.php`
  (`bdba888bee2788944f17de99fdc14b6f532ea65ddd93cacfec6430f71011039d`) is the
  authorized B16 addition. No unrelated drift; unrelated lifecycle `.gitkeep`
  deletions preserved unstaged and excluded.
- **Review:** no subagents (Standard B). Main agent re-reviewed the four repair
  findings against source and regressions: B13 (`$this->l10n` scoped 404 on the
  fresh missing-item branch), B14 (locked before/after no-change comparison with
  truthful audit outcome), B15 (fresh-task dedup with the multi-item query-count
  pin), B16 (under-lock `authorizeUnderLock` guard invoked on execution AND
  replay before any receipt answer, supplied by every owner-capable command
  consumer: occurrence amend/catch-up/restart, all five pause commands,
  scheduling apply, house reset, reward catalog, reward-request lifecycle; the
  documented self-service member boundary is unchanged). No new findings;
  nothing to route. The earlier "H1's engine-compatibility probe is red" note
  in the repair-cluster history describes the pre-resolution state and is
  superseded by the RF-081-B02 continuation outcome plus this review's fresh
  engine evidence.
- **Gates rerun green 2026-10-04:** Jest 109 suites / 1228 tests; `test:noise`
  error-level free; ESLint and stylelint exit 0; webpack production build;
  l10n 1270 keys exact; runner `--self-test`; Docker unit 1313 tests / 4234
  assertions and integration 568 tests / 3203 assertions, both
  `--fail-on-skipped` with zero skips; `composer cs:check` 0/404;
  `npm ls --all --omit=optional` exit 0; `backlog:check` 34 files,
  `docs:encoding` 879 files, `git diff --check` clean. The full three-pool
  browser gate was not rerun in this review: `run-pqNIwe` remains the recorded
  green evidence at this exact candidate and the final gates rerun at the
  plan's final review.
- **Transition/commit:** interim passing review — no `done/` move (H6/H7 open).
  One scoped conventional commit of the reviewed package (implementation plus
  review evidence), excluding the three unrelated `.gitkeep` deletions, per the
  automatic review-commit rule. No push, branch change, deployment or baseline
  adoption.

### H6 Full Interim - 2026-10-04 - NEEDS-FOLLOW-UP

- **Scope:** explicit CS-PLAN-081; 14 captured H6 implementation/evidence paths
  plus the authorized `TestModeApiTest.php` regression. Three unrelated lifecycle
  `.gitkeep` deletions excluded and preserved. Exact manifest in the review record.
- **Identity:** `master` / `56697d273b0324d2b7d1a172d57569129438fc42`; initial
  index empty, index-entry SHA-256
  `39b326ce622cbf4356b331d32211c34fe3d3070e8081992c4b2afe9412930698`;
  initial complete capture `1f7f986493ea080f79bc30f86c954b14b2d31acd3f054b4ea99f3260131be48d`;
  repaired pre-finalization capture
  `52d5a619789e45f733e5a3626d2d5d25ca7e01a49289c4e873deb120c2540063`.
- **Review:** all four read-only Full tracks received/validated; architecture,
  security/data-flow and quality clear; correctness H6-06 repaired and re-reviewed.
  Authorized diagnosis found and repaired H6-07. H6-05 remains open; green reruns
  and clean sampled boundaries are not a deterministic root-cause disposition.
- **Gates:** minimum Node 20.19.0/npm 10.8.2; Jest/noise 109/1228, lint/stylelint,
  production build, l10n 1270 and browser-runner self-test green. Docker unit
  1314/4246; final default and random-seed-8105 integration each 568/3182,
  `--fail-on-skipped`, zero skips; PHP style 0/405. Full three-pool browser
  `run-oi7XAv` passes 49 required specs, zero required skipped/failed/flaky cases.
  Corrected isolated recovery passes; no overlapping DB/browser/recovery jobs.
  Backlog/encoding/whitespace gates pass. Initial diagnostic failure retained
  above, then deterministically repaired, not hidden by a rerun.
- **Routing/finalization:** H6-05 stays plan-owned; H7 remains required. No new
  backlog item or standalone completion/archive. `NEEDS-FOLLOW-UP` authorizes
  only evidence-only plan findings/outcome, review record, live registry and the
  verified domain-neutral `TestModeApiTest.php` correction in the scoped commit.
  Existing H6 implementation/policy/acceptance/manifest edits and the untracked
  recovery runner/fixture are deliberately left out of the index and commit.
  No `done/` move, branch change, push, deployment or retention adoption.

### H6-05 Continuation Full Interim - 2026-10-04 - NEEDS-FOLLOW-UP

- **Owner/scope:** this active plan; complete remaining H6 implementation,
  recovery runner/fixture and durable docs, plus the authorized holder regression.
  Four read-only tracks validated; main owns every write. Exact 15-path reviewed
  candidate plus the review record finalization path in the latest review entry.
- **Identity:** `master` / `46d37a29ce705fd187ceb43aacaa2d694dfff512`, empty
  captured index. Initial complete fingerprint
  `d9dd6d8ca586c40fdbb55d99ef134cf2bff118b54cee7e7fd2fd6702c1f28623`;
  reviewed pre-finalization fingerprint
  `7646f9ee0cdf23d7d690e9ff48db9a6412a3d680f9c4d51fd1ec495af7a4912e`.
  Evidence-only updates and the named recovery re-verification record follow that
  snapshot. Untracked hashes unchanged; foreign `.gitkeep` deletions preserved.
- **Disposition:** the holder-abort class and survival of PHPUnit/helper after a
  client timeout are deterministically observed. The repaired holder requires one
  parent-computed UTC day, successful commit marker and normal helper exit before
  assertions/cleanup. In-process DBAL/native-PDO evidence observes no mismatch
  in 12,843 original-candidate states. None proves the original four fixture
  errors' cause; they remain separate and RF-081-H6-05 stays open by explicit
  operator decision. H7 checkpoint remains not ready; H-T11 is still mandatory.
- **Review fix:** RF-081-H6-08 timeout-test readiness/hold handshake repaired
  within the approved bounded harness scope; correctness follow-up clear. Other
  three tracks return no new findings. No unknown transaction commit/rollback,
  production fallback, warning suppression or test skip added.
- **Fresh gates:** minimum Node 20.19.0/npm 10.8.2; Jest/noise 109/1228,
  lint/stylelint/build, l10n 1270 exact keys, runner self-test and dependency tree
  pass. Docker unit 1314/4246, final uninstrumented default integration 571/3202,
  zero skips; focused holder 5/35 and PHP standards 0/405 pass. Build-complete
  isolated recovery `cs081-recovery-592eb59f90a0` passes all 30 app snapshots,
  whole DB/files, divergence/negative controls and teardown. Full browser
  `run-UfqJZd` passes all 49 required specs in three sequential pools, no required
  skips/failures/flaky cases. Integration/recovery/browser never overlap; final
  evidence documentation/backlog/whitespace checks rerun before commit.
- **Finalization:** `NEEDS-FOLLOW-UP`, not whole-plan PASS. Only this round's
  evidence-only findings/outcome hunks, review record, live registry and verified
  `SchedulePlanConcurrencyTest.php` repair enter the one scoped review commit.
  Remaining H6 policy/recovery/RewardService implementation and durable-doc
  updates stay uncommitted. No duplicate backlog item, owner archive, `done/`
  transition, branch change, amend, push, tag, publication, deployment, production
  restore or baseline adoption.

### Remaining H6 Package Standard B - 2026-10-04 - PASS-WITH-FIXES

- **Tier/scope:** Standard B (code) interim review of the remaining uncommitted
  CS-PLAN-081 candidate — the H6 durable docs (bootstrap/canonical rules,
  backend/architecture skills, deployment/testing/release/release-test-plan),
  the untracked recovery runner/fixture, the RF-081-H6-01 RewardService
  null-description fix with its `CatalogAuditTest` regression, and this plan's
  H6 implementation/evidence hunks. Explicit plan owner CS-PLAN-081; not a
  completion review: H6-05 (operator-approved open) and H7/H-T11
  (operator-blocked) keep the plan in `active/` regardless of this outcome.
  Three foreign lifecycle `.gitkeep` deletions excluded and preserved unstaged.
- **Identity:** `master` / `172129ca49b7c7435e0567b838a70a0977e44e78`, index
  empty at capture; unstaged binary-diff SHA-256
  `63A77C74A8762B53088632BC31CE4FD8134047433F2D796C0149F01D5D8FDA64`;
  untracked content SHA-256 at capture `89ec38d3336ef0df…` (runner) and
  `5246278609fd3cc6…` (fixture) — byte-identical to the preceding review's
  gate-verified candidate (runner blob `152051a9…`, fixture blob `b2e3fed9…`),
  and the unstaged path set exactly matches that review's 15-path manifest
  minus the two files it committed clean. Full-suite evidence (Docker unit
  1314/4246, integration 571/3202, Jest 109/1228, browser `run-UfqJZd`,
  recovery `cs081-recovery-592eb59f90a0`) therefore applies byte-for-byte to
  the captured candidate.
- **Review:** no subagents (Standard B). Checklist pass over architecture,
  authorization, security (isolation, whitelist archive, gitignored private
  evidence, credential redaction), workarounds, logging, i18n, tests and
  docs. One new finding — RF-081-H6-09 (minor, runner log redaction gap) —
  fixed in-round after explicit user approval ("Fix now + re-rehearse"):
  testable `Get-SanitizedArguments` with embedded-token matching plus a
  dedicated self-test. Post-fix runner content SHA-256
  `770093eebc9304e2377f523c78a586075822fb2489c17c7ab7c397d13933af1f`
  (blob `190faebd71bb1d3f6fe6053756dc6874d4237b8d`); fixture unchanged.
- **Fresh gates:** all three runner self-tests PASS (cleanup failure-path,
  authenticated TCP readiness, log sanitization); full isolated rehearsal
  `cs081-recovery-673681ab35b7` PASS (30 snapshots, whole-DB row equality,
  file-hash equality, divergence and negative controls, recovery 28.12 s /
  total 69.98 s, zero cleanup failures; executed log has zero unredacted
  password occurrences); focused `CatalogAuditTest` 14/14 (137 assertions)
  with `--fail-on-skipped`; `backlog:check` 34 files, `docs:encoding` 890
  files, `git diff --check` / staged check clean. PHP/JS implementation files
  are byte-identical to the recorded full-suite green evidence above.
- **Transition/commit:** interim passing review — no `done/` move (H6-05/H7
  open). One scoped conventional commit of the complete reviewed H6 package
  (durable docs, runner, fixture, RewardService fix + regression, plan
  evidence, review record, live registry), excluding the three foreign
  `.gitkeep` deletions, per the automatic review-commit rule. No push, branch
  change, deployment, tag or baseline adoption.

### Full Final - 2026-10-05 - PASS-WITH-FIXES

- **Whole-plan scope:** delivered H1-H6, history and full manifest, acceptance,
  gates and durable docs; four independent read-only tracks and final consistency
  follow-ups return no remaining findings. Main owns all writes.
- **Identity:** `master` at `2d0313f08c7be6dbc5a54cccfd620fa1a22f2ca5` plus the
  authorized package; 100-path pre-finalization capture fingerprint
  `b0289203c2e0fa06f0e4ae42180803993fb17cda96a806391c2d21e146bd564d`;
  frozen non-doc implementation fingerprint
  `f3a68b1291c9ec2da9c718acee32168c11f33b8c1a9d460597dcdd8d031e1eb9`.
  Exact manifest/diff/index evidence in the [final record](../../backlog/archive/CODE_REVIEW_2026-10-05.md).
- **Repairs/decisions:** RF-081-H6-10..15 resolved with permanent API/release/testing
  integration and explicit reset-identity repair authorization. H6-05 closed by
  the user-approved, independently accepted non-attributed historical anomaly
  disposition with evidence gaps/recurrence duties, not a quartet cause/fix claim.
  Red, invalid diagnostic, rejected-discovery and interrupted attempts remain
  explicit. H7/H-T11 rescoped, never relabelled PASS.
- **Fresh final evidence:** strict minimum Node 20.19.0/npm 10.8.2 clean install,
  full peer tree and all 1,018 locked engine ranges; Jest/noise 109/1230,
  lint/fixer/stylelint/build, l10n 1270 and runner self-test; Docker unit 1314/4246,
  default integration 581/3282 (`--fail-on-skipped`, zero skips), PHP standards
  0/405. Full browser `run-f45itl` passes 49 required specs in three sequential
  pools, no required skips/failures/flaky cases/retries. A bounded wait timeout
  did not cancel the tracked runner; actual completion/container exit were checked.
- **Recovery:** fresh changed-runtime/build H-T10 `cs081-recovery-2f8aff4b3ee8`
  passes 30 snapshots, whole DB/files, divergence/negative controls, 26 migrations
  and owned teardown; permanent exact-runtime record in the release test plan.
  No older archive substituted or DB mutation overlap.
- **Transition:** eligible `active/` to `done/`; seven completed standalone owners
  move to October history. BUG-SEC-001/TECH-REPO-001 remain later work;
  CONCEPT-001/RELEASE-002 remain partial, WP9 retains matrix/browser-pair/human
  evidence. Final tracking/link/encoding/whitespace and drift checks rerun before
  exactly one scoped conventional review commit. Foreign `.gitkeep` deletions
  excluded; no amend, branch change, push, tag, PR, publication, deployment,
  personal-server access or retained-data adoption.
