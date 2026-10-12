# Code Review: WP9 Operator Core-Use Feedback Round

- **Review Date**: 2026-10-12
- **Reviewer**: Kilo
- **Mode**: Full (4 parallel read-only reviewer tracks: correctness, architecture, security/privacy, performance/quality)
- **Scope**: Uncommitted working tree at `8e484bb` — WP9 operator core-use feedback: TaskManager scope/person/floor/room filters, assignment names via new `useHouseMemberNames` composable, action contrast, My Day room links, participation-dialog validation, unit/e2e regressions, l10n, gate/plan/durable docs
- **Owning plan**: [CS-PLAN-080](../../plans/active/cs-plan-080-wp9-cross-db-and-family-evidence.md) (interim review; plan stays `active/`)
- **Base Commit**: `8e484bbad408d3202996ec3ee36a1b45ff8747d9` (branch `master`, index empty)

## Executive Summary

**Outcome: PASS-WITH-FIXES (interim).** The four tracks returned 15
consolidated findings (2 major, 5 minor, 8 nits). Ten were fixed in-round —
seven as behavior-neutral/auto-eligible fixes, three logic fixes after explicit
user approval in one batched decision. One new plan-owned finding was routed
(RF-080-16, deferred filter-bar extraction); the remaining nits are recorded
below without follow-up. Two reviewer suspicions were investigated and
disproven (see "Verified non-findings"). The BUG-SEC-001 contact-documentation
slice present in the same working tree was reviewed but — per user decision —
excluded from the review commit until the operator verifies mailbox
receipt/reply; no contact details are reproduced in this record.

## Captured Fingerprint

- Branch `master`, HEAD as above, index empty at capture.
- Unstaged diff (29 tracked paths) SHA256:
  `AABF9002CC989EF8F5CC65F96ADDD799A3E95B068604C05D44785796E0E0EA4D`
- Untracked `src/composables/useHouseMemberNames.js` SHA256:
  `916D1B6CC8ABBA73221194C76EFD107ABD94BCE29244663AB7A30EC23C728A45`
- Captured manifest: README.md; docs/{CODE_OF_CONDUCT,CONTRIBUTING,SECURITY}.md;
  docs/backlog/{ACTIVE_TASKS,backlog}.md; docs/concepts/ui-use-cases.md;
  docs/engineering/deployment.md; docs/plans/active/cs-plan-080-…md;
  docs/standards/ai/rules/project-rules.md; docs/testing/release-test-plan.md;
  l10n/{de.json,en.json,templates/chorestrike.pot}; scripts/run-e2e-gate.js;
  src/components/Common/ParticipationDialog.vue;
  src/components/Tasks/TaskListItem.vue; src/utils/taskDisplay.js;
  src/views/{RoomDetail,TaskManager,TodayView}.vue;
  tests/e2e/{family-core-ux,overdue-task-claiming}.spec.ts;
  tests/e2e/required-scenarios.json;
  tests/unit/components/{ParticipationDialog,TaskListItem,TodayView}.spec.js;
  tests/unit/views/{RoomDetail,TaskManager}.spec.js; plus the untracked
  composable.
- Review-round additions beyond the captured set: `src/components/Tasks/TaskList.vue`,
  `tests/unit/components/TaskList.spec.js`,
  `tests/unit/composables/useHouseMemberNames.spec.js` (new).

## Findings

Consolidated across tracks; duplicates merged (the duplicate member fetch was
independently flagged by all four tracks).

| ID | Severity | Location | Issue | Status |
|---|---|---|---|---|
| RF-080-R1 | major | src/composables/useHouseMemberNames.js | Mount-time member fetch with no cache guard races/duplicates the views' pre-existing guarded fetches; two parallel member-loading mechanisms with inconsistent error handling and a refetch on every navigation. | **Fixed (user-approved)**: composable skips the fetch when `houseUsers` is already loaded (established TaskList guard), retry preserved; new composable unit spec (4 cases); the two affected view failure-tests moved to a cold store. |
| RF-080-R2 | major | src/components/Tasks/TaskList.vue:204 | Claim dialog snapshotted the assignee name once at dialog open with no reactivity — opening before the member list resolves permanently showed the raw (lower-case) user id. | **Fixed (user-approved)**: `claimAssigneeName` is now computed from `houseUsers` + stored assignee id; regression covers name resolution after dialog open; e2e gates on the row's resolved name before clicking claim. |
| RF-080-R3 | minor | src/views/TaskManager.vue:343 | Default scope `mine` with unresolved `currentUser` (cold deep-link before `/users/current` resolves) hid every assigned task — inconsistent with `TodayView.isMine`'s documented unknown-user fallback. | **Fixed (user-approved)**: `matchesScopeFilter` skips the assignee exclusion while the actor id is unknown; regression added. |
| RF-080-R4 | minor | src/utils/taskDisplay.js:22 | `assignmentLabel` defaulted only `undefined`, not `null` (a null assignees payload would throw during render), and resolved names via `members.find` inside `map` (O(assignees×members) per call in list templates). | **Fixed**: `ids = userIds ?? []` normalization plus Map-based name lookup (behavior-identical). |
| RF-080-R5 | minor | tests/e2e/overdue-task-claiming.spec.ts:117 | Claim-dialog assertion depended on the unguarded name race (see RF-080-R2) — a snapshot before load would fail permanently. | **Fixed**: the test waits for the row's resolved `Dad` label before opening the dialog. |
| RF-080-R6 | minor | src/views/InsightsView.vue:131 | Suspected: a null `correctionDraft` could silently de-exempt correction mode in the participation dialog. | **Verified non-finding**: `correctionDraft` is a computed that always returns an array (draft passthrough or mapped allocation fallback); the correction exemption holds. |
| RF-080-R7 | minor | src/views/TaskManager.vue:302 | Default-scope change suspected of breaking unaudited `#/tasks` consumers. | **Verified non-finding (evidence)**: full gate `run-t4pJYd` ran with the new default and failed only on the two corrected takeover tests plus two TECH-TRANSPORT-001 seed closures; all other `#/tasks` specs passed. Requalification stays tracked by RF-080-15. |
| RF-080-R8 | minor | src/views/TaskManager.vue:37 | File grew to ~818 lines (guidance ~300) with the new filter surface. | **Routed**: RF-080-16 (plan-owned, deferred extraction of the presentational filter bar). `matchesScopeFilter` extraction applied in-round. |
| RF-080-R9 | minor | docs/SECURITY.md | Unverified mailbox published as the reporting channel while BUG-SEC-001 keeps receipt/reply verification operator-pending. | **Routed (user decision)**: contact-documentation slice excluded from the review commit until operator verification; no address reproduced here. |
| RF-080-R10 | nit | src/views/TodayView.vue:135/201 | Verbatim-duplicated assignee-label expression. | **Fixed**: extracted `assigneeLabel(item)` method. |
| RF-080-R11 | nit | src/composables/useHouseMemberNames.js | Missing `@return` JSDoc on the new export. | **Fixed** (plain `object` typing to satisfy `jsdoc/no-undefined-types`). |
| RF-080-R12 | nit | src/views/TodayView.vue:237 | Comment continuation line gained an extra tab. | **Fixed**: re-indented to the block alignment. |
| RF-080-R13 | nit | src/views/TaskManager.vue | Mixed CRLF/LF line endings in the working copy (conversion warning). | **Fixed**: normalized to LF (no BOM). |
| RF-080-R14 | nit | tests/e2e/family-core-ux.spec.ts:95 | One-shot overflow assertion could flake on transient reflow at 390px. | **Fixed**: wrapped in `expect.poll`. |
| RF-080-R15 | nit | src/components/Tasks/TaskListItem.vue:29 | `title` tooltip ("Mark as complete") differs from the aria-label/visible "Done" text; active button reuses the completed CheckCircle glyph. | **Recorded, no change**: aria-label matching the visible label is the WCAG 2.5.3-correct pairing; the more descriptive tooltip is acceptable. Icon distinctness is a design-polish nit; revisit with RF-080-16. |

Security track verifications (clean, for the record): the participation guard
is client-side defense-in-depth over an existing server-enforced rule
(correction-mode exemption matches backend "completer or owner" semantics);
the composable reuses `fetchHouseUsers()` gated by `assertHouseMember` (member
display names stay members-only); no `v-html` in `src`; no secrets/PII logged;
`?scope=` values allowlisted; `obligatedUsersIds ?? assignees` chain consistent
with the server contract; doc diffs contain only sanitized run IDs.

## Gates

- `npm test` — 118 suites / 1671 tests passed (1665 before the review
  regressions; the previously recorded TECH-TEST-004 package-smoke failure did
  not reproduce in either run — recurrence note added to that uncommitted item).
- `npm run lint`, `npm run stylelint`, `npm run build`,
  `npm run l10n:check-keys` (1280 keys), `npm run docs:encoding`,
  `npm run backlog:check` — green.
- No PHP changed in this round; canonical Docker backend gates unchanged from
  the 2026-10-12 record (unit 1372 / integration 604, skips forbidden).
- Browser: focused family/takeover evidence predates the review hardening;
  full-gate requalification remains the open RF-080-15 requirement.

## Decisions (batched structured questions, answered 2026-10-12)

1. **Commit scope** — WP9-only (recommended, chosen): BUG-SEC-001 contact docs
   and their backlog/ACTIVE_TASKS hunks stay uncommitted pending operator
   mailbox verification.
2. **Duplicate member fetch** — fix now (chosen): cache guard + unit spec.
3. **Unresolved-identity scope guard** — fix now (chosen): mirror
   `TodayView.isMine` + regression.
4. **Claim-dialog reactivity** — fix now (chosen): computed name + regression.

## Lifecycle

Plan stays `active/` (RF-080-05 human evidence, RF-080-14 incident status/code,
RF-080-15 full requalification/retest, RF-080-16 deferred extraction). No
`done/` move, no human-evidence, deployment or adoption claim. One scoped
review commit on `master` covers the WP9 slice plus this record, the plan
update and the WP9 tracking hunks.
