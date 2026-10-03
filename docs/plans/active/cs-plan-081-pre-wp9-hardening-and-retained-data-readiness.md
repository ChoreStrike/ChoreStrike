# CS-PLAN-081: Pre-WP9 Hardening and Retained-Data Readiness

> **Status:** Active (implementation not started); drafted 2026-10-03; Standard A PASS-WITH-FIXES 2026-10-03 (Q-081-01..05 resolved)
> **Effort:** L, 6-10 engineering days plus operator availability for the reporting channel; re-estimate if fixture repairs or the audit-storage decision expand scope
> **Priority:** P1 for the retained-data trial prerequisite, not a blanket reprioritization of every included backlog item
> **Depends on:** WP0-WP8 delivered; Standard A before implementation
> **Related backlog items:** TECH-JEST-001, TECH-LINT-006, TECH-TEST-003, CR-SEC-05, SEC-AUDIT-001, BUG-SEC-001, FEAT-UX-032, CR-053-11; bounded contributions to RELEASE-002 and CONCEPT-001
> **Sequence:** This independent preparation slice runs next, before the technical execution of [CS-PLAN-080](../active/cs-plan-080-wp9-cross-db-and-family-evidence.md); it is not another WP of [CS-PLAN-070](../active/cs-plan-070-family-rhythm-and-reward-clarification.md)
> **Drafting baseline:** `master` at `76e282e`, 2026-10-03; runtime evidence inspected, no new runtime gate executed

## 1. Problem Summary

The user intends to start a family trial on a personal productive Nextcloud
server and retain the house structure and tasks across subsequent development.
WP0-WP8 are delivered, but independent hardening items remain and the current
fresh-install-only policy does not promise preservation through future updates.
Completing hypothetical features now would not solve that operational boundary.

Verified baseline:

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

### Ownership and Non-Goals

| Slice | Existing backlog owner | Completion boundary |
|---|---|---|
| H1: test discovery and dependency installation | TECH-JEST-001, TECH-LINT-006 | Canonical mocks only; clean install without peer-dependency bypass |
| H2: cold-start fixture lifecycle | TECH-TEST-003 | Evidence-based root-cause/disposition and deterministic regression, not rerun-only acceptance |
| H3: membership authorization and security hardening | CR-SEC-05, SEC-AUDIT-001 | Service gates, membership HTTP throttling, enumerated owner-operation logs and security assumptions |
| H4: setup and truthful reset UI | FEAT-UX-032; CONCEPT-001 for the reset clarification | Basement selection respected within the existing cap; UI matches the unchanged reset contract |
| H5: Snake ordering remainder | CR-053-11 | Stable row order with equal-score rank semantics unchanged |
| H6: retained-data and recovery preparation | RELEASE-002, partial only | Approved future boundary, rehearsal and deployment checklist; no deployment/field-usage closure |
| H7: private reporting channel | BUG-SEC-001 | Operator-confirmed contact, usable approved public key and publication evidence |

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

### 3.5 H7 and Tracking

Obtain an operator-approved dedicated reporting address and public GPG key,
validate their fingerprint and perform a controlled receipt/decryption check.
Only approved public material is published in `docs/SECURITY.md`; secrets/raw
messages remain private. Confirm the Codeberg profile publication separately;
the agent must not create accounts/mailboxes, publish unapproved identity details
or modify external profiles without authorization. A supplied key alone is not
proof that the reporting route works. Missing operator readiness blocks H7.

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
| H-T11 | Approved mailbox receives controlled report; approved key decrypts; fingerprint/publication match | Sanitized operator evidence; secret material private |

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
| M | `docs/SECURITY.md`, `docs/standards/guidelines/{security,logging}.md`, `docs/api/README.md` | Reporting channel, limits and audit contract |
| M after approval | `AGENTS.md`, `docs/standards/ai/rules/project-rules.md`, `docs/standards/ai/skills/{php-backend,architecture}/SKILL.md`, `docs/engineering/{deployment,testing}.md`, `docs/testing/release-test-plan.md`, `docs/release/release-strategy.md` | Approved retained-data transition/recovery gates; no claim of premature adoption |
| Conditional M | Existing owner-operation services/controllers and their logging tests | Only exact uncovered methods pinned in H0; not a general controller refactor |
| M | This plan, CS-PLAN-070/080, `docs/{README,plans/README}.md`, `docs/backlog/{backlog,ACTIVE_TASKS}.md` | Sequencing, evidence, actual partial completion |
| Completion only | Monthly backlog archive and final review record | Fully completed items only |

No migration, new production table, deletion/archive of runtime files, new
reset economy or local child profile is planned. Any evidenced need changes the
scope/estimate explicitly before implementation, not through a hidden helper.

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
5. **H6-H7 / main + authorized operator:** approved boundary/docs, synthetic
   recovery rehearsal and functioning reporting channel (1-2 days plus operator
   availability). Missing key/contact/evidence remains an explicit blocker.
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
| Operator channel silently deferred while marking done | Medium | H7 acceptance remains required unless explicitly user-rescoped |
| Prep absorbs optional features or duplicates WP9 | Medium | Existing owner table, explicit non-goals and technical/human handoff |

## 8. Acceptance Criteria

- [x] Standard A passes and Q-081-01..05 are explicitly resolved/recorded. (Standard A `PASS-WITH-FIXES` 2026-10-03; all five recommended options approved — see §9 and Review Outcome)
- [ ] H1-H5 changes and all H-T1..09 regressions pass; no suppression, fixture
  fallback, force-install or newly/unapproved skipped test substitutes for proof.
- [ ] Three independently fresh first full integration passes establish the
  accepted TECH-TEST-003 disposition, with named deterministic lifecycle coverage.
- [ ] Membership changes preserve current owner/last-owner, scope, wallet,
  departure/role and transactional invariants; native 429 behavior is demonstrated.
- [ ] Every current owner-only mutation in the pinned inventory has the approved
  safe operational logging coverage; no sensitive content or false success log.
- [ ] UI and l10n accurately explain the unchanged reset/cap contracts and the
  registered browser scenarios prove retained facts after reset.
- [ ] H-T10 synthetic recovery succeeds; durable docs state other-app rollback
  impact and the future retained-baseline/adoption procedure without claiming
  a real-server test, actual upgrade or baseline adoption that did not happen.
- [ ] H-T11 reporting/contact/key evidence and authorized publication are complete.
- [ ] Final implementation build/backend/browser/documentation gates pass on the
  reviewed candidate; WP9's later matrix/gate-pair/human obligations remain explicit.
- [ ] Durable decisions live in permanent docs; no completed standalone backlog
  item remains open, and CONCEPT-001/RELEASE-002 retain their honest partial status.
- [ ] All plan-owned findings and decisions are resolved or explicitly user-rescoped;
  required deferred work still blocks completion.
- [ ] Final Standard B or Full review passes, tracking is updated and the complete
  preparation plan moves to `done/`; no production deployment/tag/push is implied.

## 9. Review Findings (Plan-Owned)

Standard A ran 2026-10-03 and passed with one minor manifest-path finding fixed
in-round (see ## Review Outcome). The reset-copy discrepancy is an in-scope deliverable
under CONCEPT-001/H4, not a duplicate standalone backlog item. Record newly
discovered in-scope findings here; out-of-scope findings follow canonical routing.

- minor · §5 file manifest · `RoleGuardApiTest.php` cited under `tests/integration/Controller/` but the file lives at `tests/integration/RoleGuardApiTest.php` — corrected in the activation round (2026-10-03). Resolved.

No open findings remain.

### Decisions for Standard A

All entries were presented as one structured question batch in the 2026-10-03
Standard A review; every recommended option was explicitly approved and is
recorded per row below. They are resolved decisions, not pending proposals.

| ID | Recommended option | Alternative / consequence | State |
|---|---|---|---|
| Q-081-01 | Preserve all data by default from one explicitly adopted post-WP9 technical baseline; no retroactive support for disposable installs | Enable preservation at this plan's activation instead: protect the current chain immediately and revise WP9's in-place fresh-chain repair assumption | Resolved 2026-10-03: post-WP9 baseline adoption (recommended option approved) |
| Q-081-02 | Native UserRateLimit: 60 calls / 300 elapsed seconds / authenticated user / invite, remove and updateRole separately | 30 / 300 for the same routes: stricter; no new aggregate limiter/table selected by either option | Resolved 2026-10-03: 60 calls / 300 elapsed seconds per endpoint |
| Q-081-03 | AppLogger operational owner-change events with NC-managed retention and explicit durability limits | Durable dedicated audit ledger: new schema/privacy/effort design required; revise before activation | Resolved 2026-10-03: AppLogger operational contract with NC-managed retention |
| Q-081-04 | Use an operator-approved existing dedicated mailbox and usable public key; H7 remains required | Operator provisions a new dedicated mailbox/key with explicit authorization; no agent account creation or unapproved external publication | Resolved 2026-10-03: existing operator-approved mailbox/key (Q-081-04) |
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
