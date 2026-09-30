# Code Review: CS-PLAN-076 WP5

- **Review Date**: 2026-09-30
- **Reviewer**: Kilo
- **Tier**: Standard B, explicit active-plan owner; no subagents
- **Mode**: Deep Slice Review
- **Scope**: WP5 implementation and its full plan manifest, required evidence and durable documentation
- **Base Commit**: `ef417f2feb643dc7220558a80ab314802aa40e91` (`master`)
**Outcome**: resumed finalization `PASS-WITH-FIXES`; the initial `BLOCKED` attempt is retained below

## Executive Summary

Eight new plan-owned findings (RF-076-03..10) were resolved through user-authorized
behavior fixes, missing regression/concurrency evidence and safe documentation
repairs. The full runtime gates are green on the verified source. This is not a
passing lifecycle review: RF-076-11 remains open because a concurrent WP6 draft
and shared documentation appeared during the review. The user selected
**Preserve and stop (Recommended)** rather than an expanded-scope recapture.

WP5 stays in `active/`; no acceptance/lifecycle/backlog closure is certified.
The index remains empty. There is no automatic commit, plan move, WP6 review,
WP6 activation, branch operation or push.

## Scope and Risk Framing

The explicit plan path resolves ownership to
[CS-PLAN-076](../../plans/done/cs-plan-076-wp5-preferences-and-digest-delivery.md).
CS-PLAN-070 is its parent, not an ambiguous competing owner. No `--no-commit`
flag was supplied; edits and one eligible review commit were initially
authorized. The outcome-specific `BLOCKED` rule and the final user decision
disable the commit and transition here.

The initial capture contained 73 tracked dirty paths and 21 untracked paths.
Three pre-existing `.gitkeep` deletions are excluded, leaving 70 tracked and
21 untracked review paths. Staged set: empty. The full initial working-tree
binary diff (including the explicitly excluded deletions) was fingerprinted
with Git's cryptographic blob hash:

- Initial staged binary diff: `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391`.
- Initial unstaged binary diff: `7b18334166e6451e8ed89a261317562a325330f1`.
- Final verified staged fingerprint: `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391`.
- HEAD remained at the captured baseline when finalization was stopped.

### Initial Tracked Manifest

```text
appinfo/info.xml
appinfo/routes.php
docs/README.md
docs/adr/ADR-009-per-house-timezone.md
docs/adr/README.md
docs/backlog/ACTIVE_TASKS.md
docs/engineering/architecture.md
docs/plans/active/cs-plan-076-wp5-preferences-and-digest-delivery.md
docs/standards/ai/skills/docker-dev/SKILL.md
docs/testing/release-test-plan.md
l10n/de.json
l10n/en.json
l10n/templates/chorestrike.pot
lib/BackgroundJob/DailyNotificationJob.php
lib/Command/TriggerJobs.php
lib/Db/MemberPreference.php
lib/Db/MemberPreferenceMapper.php
lib/Db/OccurrenceMapper.php
lib/Migration/Version1032Date20260918000000.php
lib/Notification/ChoreStrikeNotifier.php
lib/Service/AccountErasureService.php
lib/Service/AgreementService.php
lib/Service/CalendarService.php
lib/Service/CompletionPostCommitService.php
lib/Service/CompletionService.php
lib/Service/HouseService.php
lib/Service/MedalService.php
lib/Service/NotificationService.php
lib/Service/PauseService.php
lib/Service/RankingService.php
lib/Service/TaskDueReminderService.php
lib/Service/TestDataService.php
playwright.config.ts
scripts/check-l10n-keys.js
scripts/run-e2e-gate.js
src/services/api.js
src/store/user.js
src/views/Settings.vue
tests/__mocks__/OCP/BackgroundJob/TimedJob.php
tests/__mocks__/OCP/IL10N.php
tests/__mocks__/OCP/IURLGenerator.php
tests/e2e/release-notifications.spec.ts
tests/e2e/required-scenarios.json
tests/integration/Command/TriggerJobsIntegrationTest.php
tests/integration/Db/FamilyRhythmSchemaTest.php
tests/integration/IntegrationTestCase.php
tests/integration/Service/AccountErasureTest.php
tests/integration/Service/CompletionConcurrencyTest.php
tests/integration/Service/HouseMemberRemovalIntegrationTest.php
tests/integration/Service/PauseAgreementConcurrencyTest.php
tests/integration/Service/RankingConsentTest.php
tests/integration/Service/TimezoneDayBucketingTest.php
tests/integration/Service/WeeklyGoalClosureTest.php
tests/unit/BackgroundJob/DailyNotificationJobTest.php
tests/unit/Command/TriggerJobsTest.php
tests/unit/Migration/FreshInstallMigrationSmokeTest.php
tests/unit/Notification/ChoreStrikeNotifierTest.php
tests/unit/RouteMatrixTest.php
tests/unit/Service/AccountErasureServiceTest.php
tests/unit/Service/AgreementServiceTest.php
tests/unit/Service/CompletionServiceTest.php
tests/unit/Service/HouseServiceLoggingTest.php
tests/unit/Service/HouseServiceTest.php
tests/unit/Service/MedalServiceTest.php
tests/unit/Service/NotificationServiceTest.php
tests/unit/Service/PauseServiceTest.php
tests/unit/Service/RankingServiceTest.php
tests/unit/Service/TaskDueReminderServiceTest.php
tests/unit/scripts/localizationGate.spec.js
tests/unit/views/Settings.spec.js
```

Excluded pre-existing deletions: `docs/plans/active/.gitkeep`,
`docs/plans/new/.gitkeep`, `docs/plans/onhold/.gitkeep`.

### Initial Untracked Manifest and Content Hashes

Sorted relative paths and `git hash-object` fingerprints:

```text
docs/adr/ADR-016-opt-in-digest-notifications.md e0b52f6188942f75b96f8fb4be47e4ef161c83cd
docs/api/notifications.md bae63426ce7b8aba1de917bdd78923d8aaf118b7
docs/concepts/notifications.md a9491920d878176022c85b1bc5ff5e03fb0e1fc0
docs/engineering/notifications.md 03a0035db73c7fc4388dae80e0fa253139788d01
lib/Controller/PreferenceController.php 208e74164ff02d018f828d88e31150b0557bfc91
lib/Db/DigestChange.php 449e6d1985362e19032cef024a25e9b7f1b330bc
lib/Db/DigestChangeMapper.php aace554476094c21fbefceea92da6a422b6d27fd
lib/Db/DigestSlot.php 1081703571d49a7d9adf504504d05f01bcda9869
lib/Db/DigestSlotMapper.php 21b869f395dacde0eb305e5beffe4c9e3494b0d8
lib/Service/DigestScheduleService.php b4edeb5fc5b961fbb2f973fb06f9f47c5bdcf29d
lib/Service/DigestService.php ec8beeaeb4a096f6eacfc0ccefd0504695584bfb
lib/Service/PreferenceService.php 04e47ac47ae37357ffb32fa35d5208709e9ae60c
src/store/preferences.js 387bdcbf92363e59d1218b1ee126ef2ffacd861b
tests/e2e/quiet-digest.spec.ts aa08c7dd3bf4f738c03c4a1ca8605c074188993f
tests/integration/Service/DigestConcurrencyTest.php d3397074955db77d9b4c6edfb5c010c1e7fed618
tests/integration/Service/DigestDeliveryTest.php 3e71a88c06c49e17dc1fa5f0696ed81544168ad1
tests/unit/Controller/PreferenceControllerTest.php 8e7476e678c28dcb27973a56a78bdd5b3e899940
tests/unit/Service/DigestScheduleServiceTest.php 96adf78dfeecb0ef153397336b0985a89fc8b839
tests/unit/Service/DigestServiceTest.php b75abe11a11c96f407a02d4359b83f9f258afbe2
tests/unit/Service/PreferenceServiceTest.php e0f51058968d7e7367d104fb615d161fc29fc8b5
tests/unit/store/preferences.spec.js f213c63d8b1d23435fa51669e9b6405fa3ff574b
```

## Package Results

### Scope, Wiring and Contracts

Reviewed the full WP5 plan against the runtime, not merely its status edit.
The controller remains a self-only, authenticated thin transport; the shared
command service owns mutation membership/receipt/lock checks. Ranking
consent/re-consent/withdraw/disable preserve delivery state and advance its
version; membership removal and erasure purge membership-keyed rows. Schema
changes amend the fresh-install migration, with no compatibility shim.

Digest dispatch is the only remaining notification path. The Nextcloud
adapter call occurs after the durable claim commits, uncertain outcomes do
not resend, and awards/title/weekly receipts remain in-app. SQL remains in
mappers with bound parameters; logs contain no rendered source payload.
Localization is exact across the source catalog and EN/DE/POT artifacts.

### Findings and Corrective Evidence

| ID | Severity | Area | Resolution |
|---|---|---|---|
| RF-076-03 | major | `DigestService::countRelevantTasks` | Exclude fulfilled/covered, differently appointed, currently invisible and recognized-paused work; unit and real-policy integration pins. |
| RF-076-04 | major | claim/materialization/expiry | House-first serialization, fresh slot read and current revision/zone/time/window/day checks; stale expiry cannot overwrite a committed claim. |
| RF-076-05 | major | retained dispatch spacing | Apply different-zone history to first materialization as well as revision; ordinary same-zone days retain their wall time. |
| RF-076-06 | major | queue cutoff | Resolve preference time even before a slot exists; at/after-cutoff sources target the next day. |
| RF-076-07 | major | section-4 evidence | Six real worker-connection tests use pipe barriers rather than sleeps; takeover queue insertion/rollback/replay is pinned, with immutable completion identity. |
| RF-076-08 | major | source rechecks | Explicitly authorized current-source and existing visibility-policy integration; negative unit and real-policy integration tests. Only sanitized evidence is recorded. |
| RF-076-09 | major | partial preference validation | Explicitly authorized merged-state validation under lock; incomplete quiet range and enabled-empty-time updates reject without writes. |
| RF-076-10 | minor | durable docs/mapping | Canonical API/testing/concepts/gamification entry points and notification docs repaired; changed-file sizes measured; actual ADR name and equivalent test seams recorded in the plan. |
| RF-076-11 | block | finalization drift | OPEN: concurrent WP6 draft/shared metadata appeared after capture; user chose preservation and stopped finalization. |

No new backlog item was created for plan-scope findings. RF-076-03..10 are
resolved in the owning plan; RF-076-11 blocks its completion. The independent
Jest worktree-mock warning is already owned by `TECH-JEST-001`; it was neither
suppressed nor repaired by deleting worktrees.

### Documentation and Backlog Sync

Updated the WP5 plan's findings/decisions/evidence, this review record and
only the review-owned WP5 registry entry after the stop decision. Earlier
safe repairs integrated the durable behavior into API, engineering/testing,
concepts, the gamification skill and the changed-file size inventory.
No owning backlog item was closed or archived, and the parent was not moved.

Concurrent `cs-plan-077-wp6-calm-core-ui.md` and its parent/index/backlog
metadata are not claimed as reviewed or review-owned. They remain untouched.
The existing rolling registry/history is preserved rather than mixed into
a blocked finalization package.

## Tests and Validation

| Gate | Verified Result |
|---|---|
| `npm test -- --runInBand` | 97 suites, 1062 tests passing; pre-existing duplicate worktree mock warnings remain under TECH-JEST-001. |
| `npm run test:noise` | Passing; no error-level log noise outside the recognized synthetic gate-fixture output. |
| `npm run lint` | Passing, including the corrected browser replacement. |
| `npm run build` | Production Webpack build passed during review; no frontend production source changed afterward. |
| `npm run l10n:check-keys` | 1014 source/catalog keys, exact EN/DE/POT coverage and placeholders. |
| Docker PHPUnit unit `--fail-on-skipped` | 1028 tests, 3287 assertions; no skips. |
| Docker PHPUnit integration `--fail-on-skipped` | 493 tests, 2645 assertions; no skips. |
| Docker `composer cs:check` | 0 of 377 files require formatting, including the final real-source command fixture. |
| `npm run test:e2e:gate` | `run-xpEKYP`: all 45 required specs uniquely selected, all three pools passed sequentially, zero failed/skipped/flaky required scenarios. |
| Targeted `quiet-digest` | 6 tests including setup passed; not used as a substitute for the complete gate. |
| Backlog/encoding/whitespace | Checks run during review and again after evidence recording; final command results remain visible in the session. |

The Docker daemon was available, but the stopped/recreated test container
initially could not install against retained database state. Only the canonical
disposable test environment was reset and bootstrapped with the `family`
profile. The final suites used that healthy environment. Earlier interrupted
browser runs are not completion evidence. A full integration run exposed an
old fake organizational-source fixture; it was replaced by a real source and
the complete suite rerun green, without weakening the new source rechecks.

## Verified Post-Fix Source Fingerprint

The tracked binary diff restricted to `appinfo`, `l10n`, `lib`,
`playwright.config.ts`, the two changed gate/localization scripts, `src` and
`tests` hashes to `c580f4a139cdacac40b90875aec89b53f03c470b`.
This restricted fingerprint intentionally excludes shared documentation and
the concurrent WP6 work; it identifies the verified runtime/test state, not
an eligible full finalization package.

```text
lib/Controller/PreferenceController.php 208e74164ff02d018f828d88e31150b0557bfc91
lib/Db/DigestChange.php 449e6d1985362e19032cef024a25e9b7f1b330bc
lib/Db/DigestChangeMapper.php aace554476094c21fbefceea92da6a422b6d27fd
lib/Db/DigestSlot.php 1081703571d49a7d9adf504504d05f01bcda9869
lib/Db/DigestSlotMapper.php 4b713b6502270f3d5bf7e854d44c5118ba23f355
lib/Service/DigestScheduleService.php dee5a09c6870f5f4033eeb1db3fe3c4255d80289
lib/Service/DigestService.php 04c7d77793d0cb9a5697e4500393eb31aca4c108
lib/Service/PreferenceService.php e4f3743b1554ca48e26e454200d270f965a577b4
src/store/preferences.js 387bdcbf92363e59d1218b1ee126ef2ffacd861b
tests/e2e/quiet-digest.spec.ts 6ab1af245a8d487792b9f7a5b0354f1180b08917
tests/integration/Service/DigestConcurrencyTest.php cdacf050512e3d4e881b9fe92e97f316a8895ba6
tests/integration/Service/DigestDeliveryTest.php eb29d99012699ccf9e2ae6652983af053ca6bf1b
tests/unit/Controller/PreferenceControllerTest.php 8e7476e678c28dcb27973a56a78bdd5b3e899940
tests/unit/Service/DigestScheduleServiceTest.php 561e897ac9a225da4fedc4b2e77bc49ab825bc90
tests/unit/Service/DigestServiceTest.php 55eac38bb3ad347293a304f81ce88b0fad1e9435
tests/unit/Service/PreferenceServiceTest.php df299f07409a15cc18dc66b2b363a3ca3d911d9c
tests/unit/store/preferences.spec.js f213c63d8b1d23435fa51669e9b6405fa3ff574b
```

## Questions and Decisions

1. **Q-076-B1: finding disposition.** Options: record blockers (recommended)
   or fix in this review. Answer: **Fix in this review**. The behavior fixes
   and missing regression/concurrency evidence were authorized; no required
   test was deferred or rescoped.
2. **Q-076-B2: source relevance and recipient policy.** Options: enforce
   rechecks (recommended) or keep the finding open. Answer:
   **Enforce rechecks (Recommended)**, explicitly authorizing this
   authorization-relevant change and its negative tests.
3. **Q-076-B3: partial preference validation.** Options: validate merged
   state (recommended) or keep the finding open. Answer:
   **Validate merged state (Recommended)** under the house lock.
4. **Q-076-B4: concurrent documentation.** Options: preserve and stop
   (recommended), or explicitly recapture an expanded document scope.
   Answer: **Preserve and stop (Recommended)**. Retain verified WP5 fixes
   and evidence, leave the concurrent WP6 work and index untouched, and
   perform no move or commit.

## Recommendation and Handoff

- Final outcome: **BLOCKED**, specifically RF-076-11 scope drift, not a green
  diff being used to pretend that the plan is complete.
- Plan transition: none; WP5 remains active. Parent/CONCEPT-001 and the
  partially owned FEAT-UX-024/025 items remain open.
- Commit: none; the index was never modified. All implementation/review
  changes remain visible in the working tree.
- Next eligible finalization requires a fresh stable-scope review that
  reconciles the concurrent documentation with its owner; no automatic
  continuation or additional work is scheduled by this record.

**Review Completed**: 2026-09-30 (blocked finalization, user-directed stop)

## Resumed Finalization: PASS-WITH-FIXES

The subsequent explicit user instruction on 2026-09-30 resumes WP5 closure
and excludes the new plan. This is **Q-076-B5**, superseding B4's stop for
WP5 only. The earlier blocked attempt remains historical evidence; it is
not rewritten as a passing capture. No WP6 review/activation is performed.

Fresh baseline: `master` at `ef417f2feb643dc7220558a80ab314802aa40e91`,
index empty (`e69de29bb2d1d6434b8b29ae775ad8c2e48c5391`); complete
unstaged binary-diff fingerprint `2004c01dacaabee8f56a048528087daaaffc0e1f`.
All 17 untracked runtime/test hashes above and tracked source fingerprint
`c580f4a139cdacac40b90875aec89b53f03c470b` remain unchanged. Thus the
reviewer's passing runtime gates, including the complete E2E `run-xpEKYP`,
apply to the current source; only review/tracking documentation changes on
resumption. Final documentation/index/whitespace gates are rerun.

Additional closeout scope is the WP5 lifecycle metadata in the parent,
plans/docs indexes and backlog, monthly archive and repaired historical
review link. The WP5 plan is moved to `docs/plans/done/` with all criteria
and RF-076-01..11 complete. FEAT-UX-024/025 delivered slices are recorded
in the monthly archive, but their remaining UI scope stays open;
CONCEPT-001/CS-PLAN-070 remain active for WP6-WP9 and family evidence.
The rolling registry is consolidated with interim history in the archive.
The risk inventory explicitly attributes cross-DB verification to parent
WP9 rather than falsely claiming a SQLite run in this MariaDB review.

Explicit exclusions: the pre-existing three `.gitkeep` deletions;
`docs/plans/new/cs-plan-077-wp6-calm-core-ui.md` (captured hash
`e37bc59ac3a815f25415450fa6f1f7b528a4c37a`; the excluded untracked file
was subsequently removed outside this review and was neither staged nor
restored); and its shared-document
metadata hunks in the docs/plans indexes, registry, backlog and parent.
Those are retained in the worktree and removed only from the proposed
index package by a scoped reverse patch. No reset/stash or worktree
reversion is used. No new-plan content is reviewed or committed.

RF-076-11 is now **resolved**: fresh stable capture plus explicit user
scope/exclusion makes the one WP5-only review commit eligible. The final
staged package and excluded paths are checked before commit;
no push or branch operation is authorized.

Because excluded WP6 metadata can reference the now-absent draft in the
working tree, final documentation gates also run against an export of the
proposed index. This verifies the actual WP5 commit tree without deleting
or staging anyone else's metadata or claiming the excluded draft was reviewed.

---

# Plan Review: CS-PLAN-077 WP6 — Standard A (Activation)

- **Review Date**: 2026-09-30
- **Reviewer**: Kilo
- **Tier**: Standard A (plan), single target; no subagents
- **Scope**: `docs/plans/new/cs-plan-077-wp6-calm-core-ui.md` plus the WP6 drafting-round metadata package preserved uncommitted by the RF-076-11 decision (parent WP6 pointer/staleness annotations, `docs/plans/README.md`, `docs/README.md`, `backlog.md` WP6-slice annotations, `ACTIVE_TASKS.md`)
- **Base Commit**: `fd70cd735283f0715b30a1cd96776be49aff4711` (`master`; index empty; the plan file was the only untracked review path)
**Outcome**: `PASS-WITH-FIXES` — eligible `new/ → active/` transition and one scoped activation commit

## Verification

All §1 baseline citations spot-verified exact at base: router landing special
case (`src/router.js:12`, `:76`), `App.vue:145` application, Dashboard tagline
and wallet (`:36`, `:53`), `useCelebration.js:30-52` unconditional
confetti/sound, `confetti.js:10` reduced-motion, module-local mute
(`sound.js:8`), Settings inline section (`:36-151`), `arcadeVisible` /
`catalogVisible` with no consumer outside `store/preferences.js`, no pause
functions in `api.js`, occurrence agreement routes (`routes.php:135-137`) and
pause lifecycle routes (`:140-145`), planner `Paused` label (`:412`),
Leaderboard consent-flow ranges, all eleven store `reset()` sites at the cited
lines, `user.js:311-327` resetting only user+preferences, SnakeLeaderboard
"Boosts used" (`:64`) with l10n keys (`en.json:99`), dead
`release-boost-shop` regex (`playwright.config.ts:29`), 1014-key l10n
baseline, every §4/§5 N path absent and every M path present (eighteen §4
M-files, including `tests/unit/composables/useCelebration.spec.js` — see
RF-077-02).

Plan-only checks passed: per-step ownership with exit evidence, coherent
single-package scope, no overlap with the only active plan (the parent),
risks and mitigations named, acceptance criteria complete, test plan adequate
for every §3 surface, backlog ownership wired (CONCEPT-001, FEAT-UX-023
essential slice, FEAT-UX-024/025 remainders), dependencies WP0-5 in `done/`.

## Findings

- RF-077-01 · minor · plan §5 · the drafting-round provenance sentence claimed
  the WP6 metadata "already shipped with the WP5 review round" while it was
  excluded from the scoped commit and uncommitted — **fixed** in-round; the
  metadata lands with the activation commit.
- RF-077-02 · minor · plan §4 · `tests/unit/composables/useCelebration.spec.js`
  marked N although it exists at baseline — **fixed** to M in-round.

No findings remain open.

## Decisions (batched structured question; recommendations first)

- Q-077-01 **Option A** — all roles land on My Day; Dashboard secondary
  household overview.
- Q-077-02 **Option A** — essential help slice only; tour/catalog stay WP8.
- Q-077-03 **Option A** — `PauseSettings.vue` self-only in Settings plus owner
  house controls in the owner House section (parent WP6 row drift annotated).
- Q-077-04 **Option A** — quiet in-app award toasts; effects opt-in;
  reduced-motion override; no modal.
- Q-077-05 **Option A** — both optional-area toggles now; arcade consumed,
  catalog persists until WP8.

## Finalization

Plan updated (header status, §4 manifest fix, §5 provenance fix, §6 step 0,
§9 findings resolved, §10 decisions, Review Outcome) and moved
`new/ → active/`. Parent WP6 row, `docs/README.md`, `docs/plans/README.md`,
`backlog.md` (FEAT-UX-023 set In Progress with CS-PLAN-077 owning; 024/025
owning updated; stale "awaits its final review" sentences corrected;
CONCEPT-001 narrative) and `ACTIVE_TASKS.md` (In Progress entry plus draft
entry consolidation) updated with the activation. Pre-existing
`docs/plans/{new,active,onhold}/.gitkeep` deletions preserved unstaged.
Gates on the reviewed package: `npm run backlog:check`, `npm run
docs:encoding`, `git diff --check`, plan standalone whitespace check.
Runtime tree untouched; no runtime suites apply to a documentation-only round.
