# CS-PLAN-088: 0.8.0-alpha.3 — Trial Feedback and Backlog Round

> **Status:** Active - Standard A `PASS-WITH-FIXES` 2026-10-19; drafted 2026-10-19; implementation not started
> **Effort:** M — 8–12 engineering days inside a 2–3 week window: feedback intake 2026-10-20 → ~2026-11-02 (the 14-day HUMAN-02 window of CS-PLAN-080 ends the same weekend), candidate freeze, qualification and deployment in the week of 2026-11-03
> **Priority:** P2
> **Depends on:** `0.8.0-alpha.2` deployed on the retained baseline (done 2026-10-19); the family trial's sanitized feedback (collected by the operator); no schema change intended
> **Related backlog items:** RELEASE-002 (field use, alpha release rhythm) · CR-PERF-01 · CR-084-04 · CR-LAYOUT-01 · TECH-DIGEST-001 · TECH-TEST-009 · TECH-DEV-001 · TECH-LINT-008 · stretch candidates FEAT-UX-015, TECH-TEST-008 · **not in scope:** TECH-TEST-005 and CONCEPT-001 (owned by CS-PLAN-080), BUG-SEC-001 (awaits the authority's feedback), TECH-TRANSPORT-001 (needs its own instrumentation round), FEAT-UX-018/019/023/027/028/029 (product decisions or features the trial has not asked for)

## 1. Problem Summary

`0.8.0-alpha.2` has run on the family instance since 2026-10-19 and the family
trial collects everyday-use feedback with the operator. The
[release strategy](../../release/release-strategy.md) for the `v0.8` alpha says:
keep issue handling lightweight, create a new alpha tag when a meaningful bundle
of fixes is ready, P0/P1 block a candidate, P2/P3 may be deferred when
documented. The operator decided on 2026-10-19: collect the feedback and,
unless something is really urgent, ship one new version in two to three weeks
with everything that has accumulated by then.

Independently of the trial, [`backlog.md`](../../backlog/backlog.md) holds seven
unowned items that fit an alpha update without a schema change: three small
hardening findings from recent reviews (CR-PERF-01, CR-084-04, CR-LAYOUT-01),
one pre-existing mapper defect (TECH-DIGEST-001), one test-runtime observation
(TECH-TEST-009) and two toolchain majors that Dependabot can no longer deliver
on the current engine range (TECH-DEV-001: Node 20 is end-of-life since
2026-04-30 and blocks `sass-loader` 17; TECH-LINT-008: ESLint 10 flat config).
Two medium items are plausible stretch goals (FEAT-UX-015 leave house,
TECH-TEST-008 per-worktree Docker stack); the remaining add-ons need product
decisions first.

Release housekeeping is also open: no `v0.8.0-alpha.x` tag exists yet (the
manual alpha record is still open in CS-PLAN-080), and `CHANGELOG.md` carries
two deployed versions under `[Unreleased]`.

## 2. Strategy

One plan, four work streams, one candidate:

- **WP-A Trial feedback (RELEASE-002):** the operator collects feedback
  privately; only sanitized, role-level items enter the
  [feedback register](#feedback-register-sanitized) below (the same privacy
  rule as [release-test-plan §12.1](../../testing/release-test-plan.md#121-family-acceptance-evidence-c5j--wp9):
  no names, ages, quotations or household details). Each item is triaged with
   the release strategy's type and priority and routed scope-first: fix in this
   round with a regression test; unresolved in-scope defects stay in Review
   Findings until fixed or explicitly user-rescoped; out-of-scope items fold
   into an existing backlog entry before a new entry is created, or are
   rejected with a reason. The 14-day HUMAN-01/02 evidence itself
  stays with CS-PLAN-080 and is not collected here.
- **Hotfix rule:** a P0/P1 finding (data loss, security, core workflow broken)
  does not wait for this round. It goes to its own `fix/` branch, is
  requalified and handed over through the private exchange under the
  [release skill](../../standards/ai/skills/release/SKILL.md); this plan
  rebases on it. Everything else waits for the bundle.
- **WP-B Hardening:** the five small backlog items, one commit each, behavior
  preserved unless the item is the behavior change.
- **WP-C Toolchain:** the Node/bundler update first, because every later change should
  be built with the chain the candidate ships with. ESLint follows WP-B in
  its own series; all earlier work is revalidated on the final chain. Proof is a
  byte-level comparison of the built `js/` (apart from the per-build `?v=`
  stamp) plus the full gates.
- **WP-D Release `0.8.0-alpha.3`:** freeze at the end of the window, version
  bump, changelog, final Full review, the
  [qualification pipeline](../../standards/ai/skills/release/SKILL.md#qualification-pipeline),
  export, operator deployment, verified record import, tag and GitHub
  pre-release per Q-088-03. Plan approval is not authorization to create
  branches, publish, tag, deploy or import operator feedback: each operation
  requires its own explicit task-specific approval. A current completed backup
  and confirmation of the established recovery procedure precede deployment.
- **No schema or migration change.** The 26 applied migrations stay pinned.
  If a feedback fix needs one, explicit approval of a reviewed plan update is
  required first and the step ships
  with the populated-baseline upgrade test the
  [deployment guide](../../engineering/deployment.md#schema-changes-after-adoption) requires.
- **Scope tiers** express priority, not automatic permission to defer required
  work or tests. The date is a target; a smaller release requires explicit
  user rescoping before closure. New feedback is admitted with an exact
  file/test manifest; security details stay private, never in the public register.

| Tier | Items | Note |
|------|-------|------|
| Must | WP-A fixes, CR-PERF-01, CR-084-04, CR-LAYOUT-01, TECH-DIGEST-001, TECH-TEST-009, TECH-DEV-001, WP-D | TECH-DEV-001 unblocks Dependabot and ends the EOL engine |
| Should (selected) | TECH-LINT-008 | own commit series; deferral requires explicit user rescoping |
| Outside selected scope | FEAT-UX-015 (leave house), TECH-TEST-008 (per-worktree stack) | remain unassigned in the backlog; a separately approved and reviewed contract/test plan is required before admission |

### Decisions recorded (Standard A, operator 2026-10-19)

All seven structured questions were answered with their recommended option.
The scope/engine recommendations below replace the draft alternatives.

- **D-088-00 Worktree:** reuse the existing pinned `docs/alpha3-feedback-plan`
  worktree, not `master`; one scoped review commit, no merge or push.
- **Q-088-01 Scope:** Must + Should selected. Stretch stays unassigned in the
  backlog until a separately approved plan extension; not selected merely
  because time remains. Rejected: Must only; committing both stretch items now.
- **Q-088-02 Layout:** only the heading row clears the toggle; regular phone
  content returns to the 16 px gutter. Rejected: preserve whole-view inset.
- **Q-088-03 Tags:** alpha.2 at qualified `fcb65cf` and alpha.3 at its exact
  deployed candidate, each only after its complete stage-mapped manual sign-off
  (not just the real-device checklist). Intended GitHub releases are pre-releases.
  Tagging/publishing needs later task-specific authorization; no such operation
  is authorized or executed by this review. Rejected: alpha.3 only; wait for beta.
- **Q-088-04 Changelog:** own dated version section at verified deployment;
  reconcile alpha.1/alpha.2 from existing deployment records in WP-D. Tagging
  may follow later. Rejected: wait for tag; keep the Unreleased grouping.
- **Q-088-05 Engines:** Node `^24`, npm `^11.8.2`; qualify the minimum and
  current Node 24 patch with strict engine/peer checks, then update CI and local
  instructions. Rejected: Node `^22.14.0` / npm `^10.8.2`. Registry evidence:
  ESLint 10 requires Node `^22.13.0` on the 22 line; Nextcloud ESLint config
  9.0.1 requires `^22.14`, so the draft's `^22.11` was insufficient. No host
  toolchain installation is part of this review.
- **Q-088-06 Digest:** bounded batches with queued/cutoff predicates rechecked
  on UPDATE, deterministic progress and positive limits; cross-provider and
  interleaving regressions. Rejected: document an unbounded single UPDATE.
- **Q-088-07 Matrix:** extend `scripts/run-tests.js` for explicit verified
  targets, unique reports and preserved exit codes, leaving provisioning in
  `test-runtime-stage.js`. Three complete clean parallel rounds may close the
  observation as bounded non-reproduction, never as an attributed root-cause
  fix. Rejected: documentation-only matrix commands.

## 3. Backend / Frontend Changes

### WP-A Trial feedback (RELEASE-002)

Unknown until collected. Constraints: each fix is a minimal diff with a
regression test in its layer; no schema change (see Strategy); i18n for every
new or changed string with `l10n/` in the same change; no feedback-specific
telemetry. Items that need a product decision (approval workflows, Task Pass,
search, print, bulk ops) are routed to their existing backlog entries, not
implemented here.

### WP-B Hardening

- **CR-PERF-01** — `lib/Service/SchedulePlanService.php` `apply()`: key
  `plan['response']['changes']` by `roomTaskId` once before the due-date and
  assignee loops; no query or budget change.
- **CR-084-04** — `src/components/Common/HoldButton.vue`: move the
  press/hold/settle timer logic into `src/composables/useHoldPress.js`; the
  scoped ring/hint styles stay or move to a shared sheet if that alone brings
  the file under ~300 lines. No behavior change; the 600 ms hold, the hint on
  a short tap and the double-submission guard are the regression net.
- **CR-LAYOUT-01** — `src/styles/tokens.css`: declare `--cs-view-inline-start`
  where `--default-clickable-area` and `--app-navigation-padding` resolve
  (`.app-content`, the `NcAppContent` root) instead of `:root`; per Q-088-02
  apply it to the heading row only in all 13 consumers listed in the manifest.
  Correct the token comment; keep desktop/mobile navigation clearance and RTL.
- **TECH-DIGEST-001** — `lib/Db/DigestChangeMapper.php`
  `expireQueuedBefore()`: Doctrine never emits `LIMIT` on `UPDATE`, so expire
  in chunks (bounded ordered `SELECT` of ids, then `UPDATE … WHERE id IN (…)`).
  Reapply queued disposition and cutoff predicates to the UPDATE, so rows
  consumed or moved after selection are not overwritten. Reject nonpositive
  limits, cap statements at 500 IDs, and use a fixed upper-ID watermark plus
  forward ID cursor for finite progress under concurrent writers; later rows
  wait for the next sweep. Keep bound parameters and Nextcloud query builders;
  the hot digest pass is untouched.
- **TECH-TEST-009** — extend `scripts/run-tests.js` with an explicit matrix-target
  mode; keep its no-argument canonical mode and stage provisioning separate.
  Validate the four owned targets with the existing target/runtime checks;
  never provision or reseed them from this mode. Run both full suites per
  target, allowing independent targets in parallel; pass `--log-junit` with
  unique run/runtime/provider/suite paths and retain complete stdout/stderr
  locally, not in tracked docs. Missing reports, failed processes or skipped
  required tests fail the aggregate. Document commands and evidence in
  `docs/engineering/testing.md`. Attribute observed failures with regressions,
  or record three complete clean four-target runs as bounded non-reproduction
  on one unchanged source/runtime set; no retries or discarded failed attempts.

### WP-C Toolchain (no app behavior change)

- **TECH-DEV-001** — `package.json` Node and npm `engines` to Q-088-05,
  `@nextcloud/webpack-vue-config` 7.1 with `sass-loader` 17 (modern Sass API
  only), drop the `sockjs` → `uuid` override; `.github/workflows/*.yml`
  `node-version`, npm setup and `docs/engineering/development.md` follow.
  Update the pinned Node image/version/digests in `docker/package-build.json`
  and the package builder's npm provisioning/strict engine check together:
  the current isolated builder is still Node 20.19, independent of host/CI.
  Preserve clean-source and immutable-image qualification; update its tests
  and README requirements, not only the CI `setup-node` inputs.
  Validate dependency engine and peer ranges before changing the lockfile;
  no `--force` or `--legacy-peer-deps`. Proof: built
  `js/` byte-identical to the previous chain apart from `?v=`, full gates on
  both runtime rows, reproducible `test:package:build`.
- **TECH-LINT-008** — `eslint.config.js` (flat) on ESLint 10 with
  `@nextcloud/eslint-config` 9, an ESLint-10-compatible release of
  `@vue/eslint-config-typescript` 14 and
  `eslint-plugin-vue` 10; `npm run lint` covers the same file sets (`src`,
  `tests/e2e`, `tests/docs-screenshots`); new-rule findings fixed per rule
  family; no warning suppression to make the gate green. A deliberate rule
  policy change needs explicit review and approval, not a silent disable.
  The `postcss-selector-parser` and
  `fast-xml-parser` overrides removed; `.eslintrc.js` archived.

### WP-D Release `0.8.0-alpha.3` (RELEASE-002)

App metadata `0.8.0-alpha.3` in `appinfo/info.xml` and `package.json`;
`CHANGELOG.md` section per Q-088-04; README status badge and screenshot
caption (`docsDrift.spec.js` enforces both), roadmap snapshot and
documentation map; final Full review; qualification pipeline; export of the
selected smoke run; operator deployment through the private exchange; verified
record under
[*Updates on the Retained Baseline*](../../engineering/deployment.md#updates-on-the-retained-baseline);
tags and GitHub pre-releases per Q-088-03.

### Excluded stretch candidates

FEAT-UX-015 and TECH-TEST-008 stay in their existing backlog entries, without
implementation authorization here. In particular, `removeHouseMember()` is
owner-only and refuses any owner target: calling it unchanged cannot implement
self-service departure. Any later plan must specify server-derived self identity,
house-first locking and last-owner races, departure cleanup/rollback, route,
store/UI and exact tests before approval. Per-worktree stack architecture,
collision/cleanup safety and its two-worktree proof likewise need a separate
reviewed plan; this round's explicit matrix targets do not deliver that feature.

## 4. Tests

- **WP-A:** one regression test per fix in the layer of the defect (PHPUnit
  unit/integration, Jest, or a Playwright scenario in an existing spec);
  browser-visible fixes get a focused run on the canonical stack before the
  final gates.
- **CR-PERF-01:** existing `SchedulePlanService` apply tests stay green; add a
  unit case with several changed rows in both phases asserting identical
  results to the current implementation.
- **CR-084-04:** `tests/unit/components/HoldButton.spec.js` unchanged and green;
  new `tests/unit/composables/useHoldPress.spec.js` for hold, early release,
  settle and keyboard hold; the browser `holdToConfirm` paths in both gates.
- **CR-LAYOUT-01:** the token unit spec describes the real cascade;
  `tests/e2e/layout-clearance.spec.ts` asserts the computed inset on phone and
  desktop widths and, per Q-088-02, that content below the heading starts at
  the 16 px gutter; vary host metrics to disprove fallback-only derivation,
  check every consumer and RTL, and run both browser gates.
- **TECH-DIGEST-001:** integration test with more queued rows than one chunk,
  asserting all rows expire and the per-statement bound holds (small chunk size
  passed through the existing limit argument). Cover empty input, the exact
  cutoff day, nonqueued rows, invalid limits, and consumption/requeue between
  selection and update; verify finite progress and no lost newer state on
  MariaDB, PostgreSQL and SQLite. Use production query paths and deterministic
  interleaving, not timing sleeps.
- **TECH-TEST-009:** Jest pins unique report paths per run/runtime/provider/suite,
  verified target selection, missing report/process/skip failure propagation and
  unchanged canonical mode. Three real four-target parallel runs retain full
  reports; any failure stays evidence and requires attribution or an explicit
  unresolved finding, never a false green.
- **TECH-DEV-001 / TECH-LINT-008:** `npm ci --engine-strict` on the new range;
  Jest, ESLint, Stylelint, production build; built `js/` compared byte-wise;
  CI `checks` green on the new Node; `test:package:build` reproducible (two
  builds, one archive hash). Record the normalized JS comparison against a
  same-source build on the previous toolchain, not against a different app
  revision; any additional difference blocks pending review, not a new ignored
  pattern. Inspect emitted CSS and browser appearance for Sass API changes.
- **WP-D:** `npm run test:runtime:check` current; full browser gate and PHPUnit
  suites on the primary and AIO rows plus the MariaDB/SQLite provider suites;
  `test:package:build` and `test:package:smoke`; the manual alpha record
  ([release-test-plan §18](../../testing/release-test-plan.md#18-execution--sign-off-record-template),
  stage-mapped per §1.2).

## 5. File Manifest

Selected scope; the main implementer owns each named work package and its
tests, the operator owns private feedback and production actions, and the
final Full review independently checks the whole manifest. WP-A extends the
manifest only when a concrete fix is admitted. CI publication waits for an
explicit push request; local gates do not claim remote CI evidence.

```
modified: docs/plans/active/cs-plan-088-alpha3-feedback-round.md
new:      src/composables/useHoldPress.js
new:      tests/unit/composables/useHoldPress.spec.js
new:      eslint.config.js                                   (TECH-LINT-008)
modified: lib/Service/SchedulePlanService.php                 (CR-PERF-01)
modified: tests/unit/Service/SchedulePlanServiceTest.php
modified: lib/Db/DigestChangeMapper.php                       (TECH-DIGEST-001)
new:      tests/integration/Db/DigestChangeMapperTest.php
modified: src/components/Common/HoldButton.vue                (CR-084-04)
verified: tests/unit/components/HoldButton.spec.js (unchanged regression net)
modified: src/styles/tokens.css, tests/unit/css/view-inline-start.spec.js,
          tests/e2e/layout-clearance.spec.ts                  (CR-LAYOUT-01)
modified: src/views/ArcadeView.vue, src/views/CalendarView.vue, src/views/Dashboard.vue,
          src/views/InsightsView.vue, src/views/Leaderboard.vue, src/views/Rewards.vue,
          src/views/RoomDetail.vue, src/views/SchedulingView.vue, src/views/TodayView.vue,
          src/views/TaskManager.vue, src/views/Settings.vue,
          src/components/Rewards/RewardRequestList.vue,
          src/components/Rewards/RewardRequestDetails.vue
modified: scripts/run-tests.js, docs/engineering/testing.md   (TECH-TEST-009)
new:      tests/unit/scripts/runTestsMatrix.spec.js
modified: package.json, package-lock.json, .github/workflows/*.yml, docs/engineering/development.md   (TECH-DEV-001)
modified: docker/package-build.json, docker/Dockerfile.package,
          scripts/build-package.js, tests/unit/scripts/buildPackage.spec.js,
          tests/unit/scripts/runtimeWorkflows.spec.js, README.md (TECH-DEV-001)
modified: appinfo/info.xml, CHANGELOG.md, README.md, docs/release/roadmap.md, docs/README.md,
          docs/engineering/deployment.md, docs/backlog/ACTIVE_TASKS.md, docs/backlog/backlog.md,
          docs/backlog/archive/2026-11.md                     (WP-D)
archived: archive/2026-11/eslintrc-legacy/.eslintrc.js        (if TECH-LINT-008 lands)
```

## 6. Implementation Order

1. Standard A review; resolve Q-088-01..07; on `PASS`/`PASS-WITH-FIXES` move to
   `active/`, set `Owning: CS-PLAN-088` and `In Progress` on the selected
   backlog items, update `ACTIVE_TASKS.md`.
2. Open the feedback register; triage incoming items at least weekly; the
   hotfix rule is in force from day one.
3. WP-C TECH-DEV-001 (engine range, bundler major, override removal, CI node);
   byte-level `js/` comparison and full gates before anything else lands on it.
4. WP-B, one commit each: CR-PERF-01, TECH-DIGEST-001, CR-084-04, CR-LAYOUT-01,
   TECH-TEST-009.
5. TECH-LINT-008 as its own commit series (config, then rule families).
6. WP-A fixes as they arrive, each with its regression test; focused browser
   runs for visible changes.
7. Check the selected scope against the date; seek explicit user rescoping
   for any unfinished selected item. Do not absorb stretch or omit required tests.
8. Freeze ~2026-11-02: version bump, changelog, docs; final Full review;
   qualification pipeline; export; operator deployment; record import; tags
   and pre-releases per Q-088-03; completed items to
   `docs/backlog/archive/YYYY-MM.md` for the actual completion month immediately.
   After all evidence and durable docs exist, run the final completion review
   (Full) and only then move the plan to `done/`. The pre-candidate Full review
   is interim while deployment/sign-off/publication evidence remains open.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Feedback volume exceeds the window | Medium | Date is a target; in-scope findings/tests remain required until explicitly user-rescoped; out-of-scope feedback folds into existing backlog owners |
| A P0/P1 finding appears mid-window | Low | Hotfix rule: own branch, requalification, exchange handoff; this plan rebases |
| Toolchain majors change the bundle or lint set | Medium | Byte-level `js/` comparison, full gates on both rows, package smoke; each major is one revertible commit series |
| ESLint 10 rule fallout churns many files | Medium | One commit per rule family; rule-policy changes need explicit approval, never suppress warnings to pass |
| A feedback fix needs a schema change | Low | None without a plan update and the populated-baseline upgrade test; the pinned-migration test fails on edits |
| Family data reaches the public register | Low | Role-level sanitized entries only; raw notes stay with the operator in `private/` |
| Node/npm engine or peer mismatch | Medium | Node 24/npm 11 qualification, strict install at the minimum and current patch, no forced peer resolution |
| The alpha.2 real-device record stays open | Medium | Q-088-03 (A) tags alpha.2 only when its record exists; alpha.3 needs its own record either way |

## 8. Acceptance Criteria

- [ ] Every selected item delivered with its tests, or explicitly user-rescoped with recorded approval and backlog state updated; no still-required deferred test
- [ ] Feedback register: every sanitized item routed (fixed with regression test / backlog ID / rejected with reason); no open P0 or P1
- [ ] No schema or migration change (or an approved plan update with the populated-baseline upgrade proof)
- [ ] Built `js/` compared byte-wise after each toolchain change; `npm ci --engine-strict` passes on the new range; CI green
- [ ] `0.8.0-alpha.3` qualified: runtime check `current`, full browser gates and PHPUnit suites on both runtime rows, MariaDB/SQLite provider suites, reproducible package build and smoke
- [ ] Manual alpha record completed before any tag; tags and GitHub pre-releases per Q-088-03
- [ ] Deployed through the private exchange; the operator's sanitized report verified and imported into the deployment guide's *Updates on the Retained Baseline*
- [ ] `CHANGELOG.md`, README status/badge/caption, roadmap snapshot and documentation map name the new version
- [ ] Build gate green (`npm test` + lint + backend tests)
- [ ] Required tests ran without new or unapproved skips
- [ ] All implementation decisions and review questions resolved and recorded
- [ ] All plan-owned review findings (§9) resolved or user-rescoped to `backlog.md`
- [ ] Owning backlog items updated or archived according to their actual completion state
- [ ] Durable concepts/decisions integrated into the permanent docs (`docs/concepts/`, `docs/adr/`, `docs/engineering/`, `docs/api/`, `docs/standards/`) — plans are temporary
- [ ] Final Full completion review after all required evidence is `PASS` or `PASS-WITH-FIXES`
- [ ] `docs/backlog/ACTIVE_TASKS.md` updated

## 9. Review Findings (plan-owned)

Findings **within this plan's scope** from implementation rounds or reviews
(scope-first routing per `project-rules.md` §4). Resolve before `done/`;
rescoping to `backlog.md` needs explicit user approval. Out-of-scope findings
go directly to `backlog.md` (or fold into an existing item) instead.

- [x] RF-088-01 (major) - Scope/lifecycle: automatic deferral and premature finalization conflicted with required-test and rescoping rules; Q-088-01 selects Must + Should, excludes stretch, and requires explicit rescoping plus a post-evidence completion review.
- [x] RF-088-02 (major) - Toolchain: draft Node ^22.11 cannot support selected ESLint/Nextcloud config minima and npm was unspecified; Q-088-05 selects Node ^24/npm ^11.8.2 with minimum/current strict qualification and compatible peers.
- [x] RF-088-03 (major) - TECH-TEST-009: named staging script does not execute suites; Q-088-07 assigns the existing test runner, report isolation/failure contracts, exact tests and three-run non-reproduction criterion.
- [x] RF-088-04 (major) - Digest plan: split select/update lacked concurrency predicates, progress and boundary tests; Q-088-06 approves bounded conditional updates and all-provider regressions.
- [x] RF-088-05 (major) - Release: tag approval/sign-off and completion ownership were ambiguous; Q-088-03 records policy only, preserves operation-specific approval and complete manual sign-off, and leaves CS-PLAN-080 human evidence untouched.
- [x] RF-088-06 (minor) - Manifest/tests: missing view consumers, named PHP/runner tests and dynamic cascade proof added; excluded stretch requires separate contract/test review before admission.
- [x] RF-088-07 (minor) - Count/order/policy: seven, not eight, backlog items; actual toolchain ordering clarified; no silent lint suppression; monthly archive uses actual completion date.
- [x] RF-088-08 (major) - Package builder: host/CI upgrade alone leaves the isolated package builder on pinned Node 20.19; add its image inventory, npm/engine checks, tests and README requirements to WP-C.

## Feedback Register (sanitized)

Role-level, sanitized entries only (adult / child / owner); no names, ages,
accounts, quotations or household details. Raw notes stay with the operator.

| # | Date | Role | Observation | Type / priority | Routing | State |
|---|------|------|-------------|-----------------|---------|-------|
| — | — | — | (none yet) | — | — | — |

## Review Outcome

**Standard A, 2026-10-19: PASS-WITH-FIXES.** Scope: this plan, checked against
the selected backlog items, CS-PLAN-080 ownership, current source/test entry
points, release rules and package metadata. No subagents or implementation edits.

- Base HEAD: `55275172ab83c38ef7ce742b9afe0a3322003f19`, branch
  `docs/alpha3-feedback-plan`, user-approved existing pinned worktree.
- Capture: clean index/worktree, no untracked paths; staged and unstaged binary
  diffs both empty (SHA-256 `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`).
  Exact reviewed plan: `docs/plans/new/cs-plan-088-alpha3-feedback-round.md`,
  tracked regular-file mode `100644`, Git blob `3c151ca84e8a7a18f7152021fd0c93dbab856145`.
  Activation destination was absent; CS-PLAN-080 is the sole pre-existing active plan.
- RF-088-01..08 resolved at plan level. D-088-00 and Q-088-01..07 recorded above;
  no unanswered decisions or open Standard A findings. Implementation and all
  future runtime/acceptance gates remain unchecked, not claimed as delivered.
- Gates: `npm test -- --runInBand` (155 suites / 2137 tests), `npm run lint`,
  `npm run backlog:check`, `npm run docs:encoding`, `npm run agents:check`,
  `git diff --check`. Lint exits successfully with the already tracked Node 24
  `DEP0180` toolchain warning (TECH-DEV-001), not a new lint finding. Composer
  warning/retry text in Jest is deliberate fixture output in runtime/builder tests.
  An intermediate unstaged move caused four metadata-test ENOENT failures on
  the old index path; stage the reviewed move and require a full green rerun
  before commit, without changing or skipping those tests.
- No PHP, app, dependency or runner implementation changed; Docker backend,
  browser, production-build and package gates are not required for this docs-only
  Standard A and were not run. They remain binding implementation/release gates.
- Transition: `new/` to `active/`; selected seven backlog items become In
  Progress, RELEASE-002 assigns only this alpha-round slice. Stretch and
  CS-PLAN-080 human-evidence ownership are unchanged. Record:
  [2026-10-19 review](../../backlog/archive/CODE_REVIEW_2026-10-19.md#standard-a---cs-plan-088-alpha3-feedback-round).
- Finalization is one scoped review commit; no merge, push, tag, deployment,
  feedback import or host toolchain change.
