# CS-PLAN-088: 0.8.0-alpha.3 — Trial Feedback and Backlog Round

> **Status:** Plan (not started) — drafted 2026-10-19; Standard A review proposed
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

Independently of the trial, [`backlog.md`](../../backlog/backlog.md) holds eight
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
  round with a regression test, or a `backlog.md` item with the plan as
  `Source`, or rejected with a reason. The 14-day HUMAN-01/02 evidence itself
  stays with CS-PLAN-080 and is not collected here.
- **Hotfix rule:** a P0/P1 finding (data loss, security, core workflow broken)
  does not wait for this round. It goes to its own `fix/` branch, is
  requalified and handed over through the private exchange under the
  [release skill](../../standards/ai/skills/release/SKILL.md); this plan
  rebases on it. Everything else waits for the bundle.
- **WP-B Hardening:** the five small backlog items, one commit each, behavior
  preserved unless the item is the behavior change.
- **WP-C Toolchain:** the two majors first, because every later change should
  be built and linted with the chain the candidate ships with. Proof is a
  byte-level comparison of the built `js/` (apart from the per-build `?v=`
  stamp) plus the full gates.
- **WP-D Release `0.8.0-alpha.3`:** freeze at the end of the window, version
  bump, changelog, final Full review, the
  [qualification pipeline](../../standards/ai/skills/release/SKILL.md#qualification-pipeline),
  export, operator deployment, verified record import, tag and GitHub
  pre-release per Q-088-03.
- **No schema or migration change.** The 26 applied migrations stay pinned.
  If a feedback fix needs one, the plan is updated first and the step ships
  with the populated-baseline upgrade test the
  [deployment guide](../../engineering/deployment.md#schema-changes-after-adoption) requires.
- **Scope tiers** so the window ends by date, not by scope:

| Tier | Items | Note |
|------|-------|------|
| Must | WP-A fixes, CR-PERF-01, CR-084-04, CR-LAYOUT-01, TECH-DIGEST-001, TECH-TEST-009, TECH-DEV-001, WP-D | TECH-DEV-001 unblocks Dependabot and ends the EOL engine |
| Should | TECH-LINT-008 | own commit series; can slip to the next round without blocking the candidate |
| Stretch | FEAT-UX-015 (leave house), TECH-TEST-008 (per-worktree stack) | only if Must and Should are done by ~2026-10-30; TECH-TEST-008 is tooling only and may land after the candidate |

### Decisions required (one batch for the Standard A review)

- **Q-088-01 Scope tier.** (A, recommended) Must + Should, Stretch only if
  time remains. (B) Must only; TECH-LINT-008 and both stretch items stay in the
  backlog. (C) Must + Should + both stretch items as committed scope.
- **Q-088-02 CR-LAYOUT-01 inset.** (A, recommended) Only the heading row
  clears the navigation toggle; phone content regains the 34 px the whole-view
  inset costs today. (B) Keep the whole-view inset, fix only the token's
  declaration point.
- **Q-088-03 Tags.** (A, recommended) Tag `v0.8.0-alpha.3` on its deployed
  commit after its manual alpha record is complete, and tag `v0.8.0-alpha.2`
  retroactively at `fcb65cf` as soon as the alpha.2 real-device record
  (CS-PLAN-080, RF-080-15) is complete; each tag becomes a GitHub release
  marked *pre-release* with the changelog section as notes. (B) Tag only
  `v0.8.0-alpha.3`. (C) No tags before the first beta.
- **Q-088-04 Changelog sections.** (A, recommended) At deployment, a version
  moves out of `[Unreleased]` into `## [0.8.0-alpha.N] - YYYY-MM-DD`
  (Keep a Changelog), the tag following later. (B) Move only when the tag
  exists. (C) Keep the current `[Unreleased]` grouping.
- **Q-088-05 TECH-DEV-001 engine range.** (A, recommended) Node `^22.11`
  (active LTS; required by `sass-loader` 17; CI matrix on 22; local via a
  version manager). (B) Node `^24` to match the development machine (current
  release line, not yet the Nextcloud toolchain's default). (C) Stay on 20 and
  keep the `uuid` override — not recommended, Node 20 is end-of-life.

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
  apply it to the heading row only. Correct the token comment.
- **TECH-DIGEST-001** — `lib/Db/DigestChangeMapper.php`
  `expireQueuedBefore()`: Doctrine never emits `LIMIT` on `UPDATE`, so expire
  in chunks (bounded `SELECT` of ids, then `UPDATE … WHERE id IN (…)`, loop
  until empty) and keep the documented 500-row bound; the hot digest pass is
  untouched.
- **TECH-TEST-009** — the provider-matrix runner (`scripts/test-runtime-stage.js`
  or the script that fans out the four suites) passes `--log-junit` per provider
  so a failing SQLite integration test is named next time; then attribute the
  two unknown failures or close the item after a documented series of clean
  parallel runs.

### WP-C Toolchain (no app behavior change)

- **TECH-DEV-001** — `package.json` `engines` to the Q-088-05 range,
  `@nextcloud/webpack-vue-config` 7.1 with `sass-loader` 17 (modern Sass API
  only), drop the `sockjs` → `uuid` override; `.github/workflows/*.yml`
  `node-version` and `docs/engineering/development.md` follow. Proof: built
  `js/` byte-identical to the previous chain apart from `?v=`, full gates on
  both runtime rows, reproducible `test:package:build`.
- **TECH-LINT-008** — `eslint.config.js` (flat) on ESLint 10 with
  `@nextcloud/eslint-config` 9, `@vue/eslint-config-typescript` 14 and
  `eslint-plugin-vue` 10; `npm run lint` covers the same file sets (`src`,
  `tests/e2e`, `tests/docs-screenshots`); new-rule findings fixed per rule
  family or disabled with a stated reason; the `postcss-selector-parser` and
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

### Stretch

- **FEAT-UX-015** — `HouseService` leave operation reusing the owner-removal
  path (wallet departure, membership tombstone) with a last-owner guard
  (`CANNOT_LEAVE_LAST_OWNER`, consistent with `CannotRemoveOwnerException`);
  `HouseController` endpoint under `@NoAdminRequired` with membership
  validation in the service; Settings *Leave house* action with a confirmation
  dialog and EN/DE strings.
- **TECH-TEST-008** — as specified in its backlog entry: project name and port
  derived from the worktree pin, collision refusal, `run-tests.js` and the
  browser gate resolving that target, cleanup command, skill and doc updates.

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
  the 16 px gutter; both browser gates.
- **TECH-DIGEST-001:** integration test with more queued rows than one chunk,
  asserting all rows expire and the per-statement bound holds (small chunk size
  injected for the test).
- **TECH-TEST-009:** Jest test that the runner passes the junit path per
  provider; one real four-way parallel matrix run with the report files kept.
- **TECH-DEV-001 / TECH-LINT-008:** `npm ci --engine-strict` on the new range;
  Jest, ESLint, Stylelint, production build; built `js/` compared byte-wise;
  CI `checks` green on the new Node; `test:package:build` reproducible (two
  builds, one archive hash).
- **FEAT-UX-015 (stretch):** unit tests for the guards, integration tests for
  leave, last-owner refusal and wallet departure, one Playwright scenario for
  the Settings action in an existing spec (gate manifest updated with it).
- **WP-D:** `npm run test:runtime:check` current; full browser gate and PHPUnit
  suites on the primary and AIO rows plus the MariaDB/SQLite provider suites;
  `test:package:build` and `test:package:smoke`; the manual alpha record
  ([release-test-plan §18](../../testing/release-test-plan.md#18-execution--sign-off-record-template),
  stage-mapped per §1.2).

## 5. File Manifest

Expected; the final list is fixed at the Standard A review and extended by
WP-A as items arrive.

```
new:      docs/plans/new/cs-plan-088-alpha3-feedback-round.md
new:      src/composables/useHoldPress.js
new:      tests/unit/composables/useHoldPress.spec.js
new:      eslint.config.js                                   (TECH-LINT-008)
new:      lib/Exception/CannotLeaveLastOwnerException.php     (stretch, FEAT-UX-015)
modified: lib/Service/SchedulePlanService.php                 (CR-PERF-01)
modified: lib/Db/DigestChangeMapper.php                       (TECH-DIGEST-001)
modified: src/components/Common/HoldButton.vue                (CR-084-04)
modified: src/styles/tokens.css, the token unit spec, tests/e2e/layout-clearance.spec.ts   (CR-LAYOUT-01)
modified: scripts/test-runtime-stage.js (or the matrix fan-out script)   (TECH-TEST-009)
modified: package.json, package-lock.json, .github/workflows/*.yml, docs/engineering/development.md   (TECH-DEV-001)
modified: appinfo/info.xml, CHANGELOG.md, README.md, docs/release/roadmap.md, docs/README.md,
          docs/engineering/deployment.md, docs/backlog/ACTIVE_TASKS.md, docs/backlog/backlog.md,
          docs/backlog/archive/2026-11.md                     (WP-D)
modified: lib/Service/HouseService.php, lib/Controller/HouseController.php, src/views/Settings.vue,
          l10n/*                                               (stretch, FEAT-UX-015)
archived: archive/2026-11/eslintrc-legacy/.eslintrc.js        (if TECH-LINT-008 lands)
```

## 6. Implementation Order

1. Standard A review; resolve Q-088-01..05; on `PASS`/`PASS-WITH-FIXES` move to
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
7. Stretch items only if steps 3–6 are complete by ~2026-10-30: FEAT-UX-015
   first (user-visible), TECH-TEST-008 may land after the candidate.
8. Freeze ~2026-11-02: version bump, changelog, docs; final Full review;
   qualification pipeline; export; operator deployment; record import; tags
   and pre-releases per Q-088-03; completed items to
   `docs/backlog/archive/2026-11.md`; plan to `done/`.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Feedback volume exceeds the window | Medium | Tier cut (Q-088-01); P2/P3 feedback defers to `backlog.md` with an ID and reason; the window ends by date |
| A P0/P1 finding appears mid-window | Low | Hotfix rule: own branch, requalification, exchange handoff; this plan rebases |
| Toolchain majors change the bundle or lint set | Medium | Byte-level `js/` comparison, full gates on both rows, package smoke; each major is one revertible commit series |
| ESLint 10 rule fallout churns many files | Medium | One commit per rule family or a deliberate disable with reason; no formatting churn beyond the lint fix |
| A feedback fix needs a schema change | Low | None without a plan update and the populated-baseline upgrade test; the pinned-migration test fails on edits |
| Family data reaches the public register | Low | Role-level sanitized entries only; raw notes stay with the operator in `private/` |
| Node 22 not available locally | Medium | Version manager documented in `development.md`; CI validates the range |
| The alpha.2 real-device record stays open | Medium | Q-088-03 (A) tags alpha.2 only when its record exists; alpha.3 needs its own record either way |

## 8. Acceptance Criteria

- [ ] Every item of the selected tier delivered with its tests, or explicitly deferred with a reason and the backlog state updated
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
- [ ] Final Standard B or Full review is `PASS` or `PASS-WITH-FIXES`
- [ ] `docs/backlog/ACTIVE_TASKS.md` updated

## 9. Review Findings (plan-owned)

Findings **within this plan's scope** from implementation rounds or reviews
(scope-first routing per `project-rules.md` §4). Resolve before `done/`;
rescoping to `backlog.md` needs explicit user approval. Out-of-scope findings
go directly to `backlog.md` (or fold into an existing item) instead.

- [ ] (none yet)

## Feedback Register (sanitized)

Role-level, sanitized entries only (adult / child / owner); no names, ages,
accounts, quotations or household details. Raw notes stay with the operator.

| # | Date | Role | Observation | Type / priority | Routing | State |
|---|------|------|-------------|-----------------|---------|-------|
| — | — | — | (none yet) | — | — | — |
