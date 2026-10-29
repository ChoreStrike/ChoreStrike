# CS-PLAN-088: 0.8.0-alpha.3 — Trial Feedback and Backlog Round

> **Status:** Active - Standard A `PASS-WITH-FIXES` 2026-10-19; drafted 2026-10-19; WP-C toolchain series started 2026-10-20 (branch `chore/tech-dev-001-node24`)
> **Effort:** M — 8–12 engineering days inside a 2–3 week window: feedback intake 2026-10-20 → ~2026-11-02 (the 14-day HUMAN-02 window of CS-PLAN-080 ends the same weekend), candidate freeze, qualification and deployment in the week of 2026-11-03
> **Priority:** P2
> **Depends on:** `0.8.0-alpha.2` deployed on the retained baseline (done 2026-10-19); the family trial's sanitized feedback (collected by the operator); no schema change intended
> **Related backlog items:** RELEASE-002 (field use, alpha release rhythm) · CR-PERF-01 · CR-084-04 · CR-LAYOUT-01 · TECH-DIGEST-001 · TECH-TEST-009 · TECH-DEV-001 · TECH-LINT-008 · WP-A feedback FEAT-UX-038, FEAT-UX-039, BUG-UX-001, BUG-UX-002, BUG-UX-004, BUG-UX-005, BUG-UX-006 (D-088-11, D-088-14, D-088-16) · third admission BUG-UX-003, BUG-UX-007, FEAT-UX-044, FEAT-UX-045 and the FEAT-UX-015 owner-removal fold-in (D-088-19..21) · stretch candidates FEAT-UX-015, TECH-TEST-008 · **not in scope:** TECH-TEST-005 and CONCEPT-001 (owned by CS-PLAN-080), BUG-SEC-001 (awaits the authority's feedback), TECH-TRANSPORT-001 (needs its own instrumentation round), FEAT-UX-018/019/023/027/028/029 (product decisions or features the trial has not asked for)

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
- **WP-C Toolchain:** one series, first, because every later change should be
  built and linted with the chain the candidate ships with (D-088-08: the Node 24
  engines, the bundler major and the ESLint 10 toolchain cannot be installed
  strictly apart). Proof is a content-equivalence comparison of the built `js/`
  (D-088-09) plus the full gates.
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

### Decisions recorded during implementation (operator 2026-10-20)

All questions were answered with their recommended option.

- **D-088-08 Toolchain series:** TECH-DEV-001 and TECH-LINT-008 land as one
  series on one branch. A strict install on Node 24 is impossible while
  `@nextcloud/eslint-config@8` and `@nextcloud/eslint-plugin@2` (both declare
  `node ^20`, `npm ^10`) are installed, and ESLint 10 / `@nextcloud/eslint-config@9`
  need Node ≥ 22.14: the engines, the bundler major and the ESLint toolchain
  share one lockfile state, then the rule families follow as separate commits.
  `@nextcloud/initial-state` (declared `^2.2.0` with `node ^20`, never imported
  by `src/` or `tests/`) leaves `dependencies`; the Node-24-compatible 3.x copy
  stays through `@nextcloud/vue` and `@nextcloud/dialogs`. Rejected: strict
  install without ESLint (not possible), `npm ci` without `--engine-strict`.
- **D-088-09 Bundle proof:** byte identity of the built `js/` is unattainable
  and no longer required: `@nextcloud/webpack-vue-config` 7.1 swaps
  `terser-webpack-plugin` for `minimizer-webpack-plugin` and moves webpack to
  5.112 (measured: every chunk differs, `chorestrike-main.js` shrinks from
  3.93 MB to 3.60 MB). The proof is content equivalence — the same module set
  and module bodies in an unminified same-source build on both chains, emitted
  CSS inspected, both browser gates, a reproducible package build — plus a
  documented, reviewed minifier difference. Rejected: pinning the old
  minimizer/webpack to force byte identity; gates only.
- **D-088-10 Boolean props:** `vue/no-boolean-default` is switched off only for
  the six components that default a Boolean prop to `true` (`ErrorState`, `Modal`,
  `HouseHealthBar`, `QuickStats`, `SetupWizard`, `FlexWindowDisplay`), in a
  commented block of `eslint.config.js`; inverting the props changes their public
  API and callers and is routed to TECH-LINT-009 (user-approved narrow policy
  deviation, 2026-10-20). Rejected: inverting all six props now; disabling the
  rule project-wide.
- **Git:** commits per work item and rule family on the task branch, local only
  (no push, no merge to `master` without a separate request).

### Decisions recorded for WP-A (operator 2026-10-26)

- **D-088-11 WP-A admission:** feedback register #1–#3 enter WP-A with the
  manifest and tests below: FEAT-UX-038, FEAT-UX-039 and BUG-UX-001. FEAT-UX-040
  and the 2026-10-26 register entries (#5–#16) stay in the backlog until
  admitted separately. Still no schema change: `flex_days` stays an integer
  column and stored values are never rewritten.
- **D-088-12 Flex cap:** `flexDays ≤ ceil(0.5 × period days)`, period days =
  `frequencyValue` × unit days (day 1, week 7, month 30). Rejected: `floor`.
  Neighbouring windows may then share one or two days; the canonical selection
  stays earliest-window-end first. For a fixed weekly rule with several weekdays
  the period is the smallest weekday gap (operator-confirmed 2026-10-26;
  Mon+Thu → 3 days → cap 2). Rejected: the full week as the period, which lets
  the windows of one week overlap heavily.
- **D-088-13 Person filter:** an explicit person filter also preselects the
  assignee in the New Task dialog; the "mine"/"unassigned" scopes preselect
  nobody. Rejected: no assignee preselection.
- **D-088-14 Second WP-A admission:** feedback register #5, #10 and #15 enter
  WP-A with the manifest and tests below: BUG-UX-005, BUG-UX-004 and BUG-UX-002.
  BUG-UX-002 starts with a reproduction on a non-UTC house; if that shows a
  genuine stale-token or version mismatch, it becomes P1 and leaves the bundle
  under the hotfix rule. Register #4, #6, #7, #9, #11–#14 and #16 stay in the
  backlog until admitted separately. Still no schema change.
- **D-088-15 One house per instance:** register #8 is rejected, not deferred.
  The operator judged several houses per instance too much effort: a house
  choice in every request and store, per-house membership, notifications and
  background passes, a house switcher and an upgrade path for the retained
  house. FEAT-UX-042 is archived as rejected. Rejected: keeping it as a P4
  candidate for v1.0.
- **D-088-16 Graphics contrast and GUI review:** register #17 (graphics hard to
  see in the dark theme) enters WP-A as BUG-UX-006; a dark-theme audit names
  the affected graphics before the fix and completes the manifest. After the
  WP-A fixes the operator gets a small GUI review of all WP-A items
  (screenshots in light and dark, phone and desktop width) before the freeze;
  its findings are routed scope-first like any other feedback.

### Decisions recorded from the GUI review (operator 2026-10-27)

- **D-088-17 Flex edit widens at once:** growing a task's flex days widens every
  saved occurrence whose window has not ended to the new bounds immediately,
  recorded as a `window` agreement with a version bump. Narrowing applies from
  the next generated occurrence (a saved window never shrinks), and a missed
  occurrence stays missed. Rejected: the new flex days only from the next
  occurrence, which left the planned one without an open window.
- **D-088-18 Clear message before the window:** Done on a row without an open
  target says "Not open yet — this task can be completed within its time
  window." instead of the generic stale failure. Rejected: hiding or disabling
  Done, which FEAT-UX-041 may revisit.
- **D-088-19 Third WP-A admission:** feedback register #7, #9, #14 and #16
  enter WP-A with the manifest and tests below: the owner-removal part of
  FEAT-UX-015, BUG-UX-003, FEAT-UX-044 and FEAT-UX-045 (variant b, per task).
  Self-service departure stays excluded (see *Excluded stretch candidates*).
  Register #4, #6, #11, #12 and #13 (FEAT-UX-040, -041, -043) stay in the
  backlog for a later round; no mockups in this round. They are implemented on
  a fresh branch after the WP-A merge, with their own gate run.
- **D-088-20 "Last done" per room task (FEAT-UX-045, variant b):** the
  room-task edit dialog records "Last done on" (today or a past house-local
  day; owners and members, not children). It appends a room-journal event
  `last_done` dated that day, which stamps "Last cleaned" without a
  cleanliness change, reward, completion fact or rhythm effect; a newer entry
  for the same room task replaces the older one, and a later real completion
  or quick clean still wins. A completion-relative interval re-anchors its
  pending occurrence to `max(today, last done + period)` through the date
  agreement writer; a fixed cadence keeps its dates (C1); an overdue pending
  occurrence refuses the command with its reason (a miss stays a miss,
  catch-up applies). No schema change: `room_events.kind` is a free string and
  older code skips an unknown kind, so a code downgrade only drops the stamp.
  Rejected: (a) a per-room date only; writing `room_tasks.last_completed`
  (completion-owned and recomputed on undo); extra fields in the create form
  and the setup wizard (the first due date already anchors a new task).
- **D-088-21 GUI review of the third admission (operator 2026-10-28):** the ten
  screenshots of the four items in both themes and widths pass. The capture
  found BUG-UX-007 (a direct room-to-room navigation keeps the previous room's
  content); the operator admits its fix into WP-A on the same branch before the
  local merge. Rejected: leaving it in the backlog.

### Decisions recorded for WP-D (operator 2026-10-28)

- **D-088-22 Early freeze:** after the RF-088-11 merge (`dccafeb`) the operator
  freezes the candidate now instead of at the end of the window (~2026-11-02).
  WP-D starts on branch `chore/release-0.8.0-alpha.3` (worktree
  `~/worktrees/chorestrike-alpha3-release`): version bump, changelog, docs,
  screenshots, the pre-candidate Full review, the qualification pipeline and the
  package build and smoke. Feedback arriving from now on is triaged into the
  backlog for the next round unless it is a P0/P1 hotfix. Export, deployment,
  record import, tags, pre-releases and the push each still need their own
  approval. Rejected: freezing on the planned date.
- **D-088-23 Pre-candidate review fixes:** the three small defects of the Full
  review (RF-088-12..14) are fixed in WP-D with regression tests before the
  qualification. Rejected: fixing only the rename; routing all three.
- **D-088-24 "Last done" after a house reset:** an entry recorded after the
  latest reset counts like any other, also for a day before the reset (newest
  entry per room task, a later completion still wins). Rejected: the reset
  wins (documented no-op); refusing days before the reset.
- **D-088-25 Structure and performance findings:** fixed in WP-D (RF-088-16).
  Splitting `RoomTaskReadService` below the file-size guideline — 928 lines
  before alpha.3 — goes to the backlog as CR-SVC-65. Rejected: routing all of
  them to the backlog; a partial or full split in the frozen candidate.
- **D-088-26 Candidate handoff (operator 2026-10-29):** after the qualified
  package smoke the operator approved, each on its own: the evidence commit and
  the local fast-forward merge of `chore/release-0.8.0-alpha.3` into `master`;
  the export of smoke run `run-mvsq7gf7` through the private exchange; and
  pushing `master` with remote CI now rather than after the deployment.
  Deployment, the production AIO update to v14.4.2 / NC 34.0.5, the record
  import, tags and pre-releases stay separate operator steps. The release
  worktree stays until the export, since it holds the qualified run.

## 3. Backend / Frontend Changes

### WP-A Trial feedback (RELEASE-002)

Unknown until collected. Constraints: each fix is a minimal diff with a
regression test in its layer; no schema change (see Strategy); i18n for every
new or changed string with `l10n/` in the same change; no feedback-specific
telemetry. Items that need a product decision (approval workflows, Task Pass,
search, print, bulk ops) are routed to their existing backlog entries, not
implemented here.

Admitted items (D-088-11, D-088-14, D-088-16):

- **FEAT-UX-038 filter prefill:** `TaskManager` passes its floor, room and
  person filters to the create form; `RoomPicker` gains a non-locking initial
  floor/room selection next to the locking `presetRoomId`; `TaskEditForm`
  preselects the filtered person in `AssigneeChips`. Edit mode is unchanged.
- **FEAT-UX-039 flex cap (D-088-12):** one pure policy class computes period days
  and the cap for `TaskService` validation and `OccurrenceRevisionService`
  (whose `min(7, …)` clamp becomes the cap for new or changed input and a hard
  sanity bound otherwise); the form mirrors the formula in a small utility for
  its `max` and hint. A flex value above the new cap that is already stored stays
  valid on unrelated edits. `docs/api/README.md` documents the new range.
  *Implementation note (2026-10-26):* the 7-day clamp mirrored the 7-day
  agreement horizon (`OccurrenceReconcileService::HORIZON_DAYS`). Late windows
  of any length and the early windows of completion-relative tasks (successors
  materialize at completion) work in full; a fixed calendar cadence agrees
  occurrences seven days ahead and so opens at most seven days early. The
  horizon stays unchanged — a longer frontier would delay every rule edit to
  beyond it — and the form states the limit for fixed schedules.
- **BUG-UX-001 dialogs:** `Modal.vue` gains an opt-in prop (default `false`) that
  ignores backdrop clicks, and a sticky action row for slotted form actions;
  every dialog with user input sets it (task create/edit, room-task edit, room
  rename, floor add/edit, sub-task edit, reward editor and request reasons,
  availability, participation, work-split correction). Confirmations and
  informational dialogs keep the backdrop close.
- **BUG-UX-005 open window on My Day:** first confirm the affected view with
  the operator. The filtered daily read then also returns the viewer-visible
  rows whose canonical open window contains today while their planned day lies
  ahead, flagged and with their occurrence block; `TodayView` shows them in a
  group of their own with Done. Visibility and child eligibility apply as for
  due-today rows; completion uses the unchanged canonical target and on-time
  rules; the due-today set and its other consumers (digest, daily limit) stay
  unchanged. Tasks tab (operator-confirmed 2026-10-27): a flex edit kept the
  planned occurrence on its old window, so Done had no open target and failed
  with a bare `STALE_TARGET`. `TaskService::updateTask` now widens the open
  windows of every mounted room task when flex days grow (D-088-17), and the
  store raises `NOT_OPEN_YET` for a row delivered without a target (D-088-18).
- **BUG-UX-004 chip scroll:** confirm the cause in the browser; `AssigneeChips`
  anchors its visually hidden input inside the chip, and the weekday chips in
  `TaskEditForm` get the same check.
- **BUG-UX-002 refusal reason:** reproduce first (D-088-14). The agreement
  services throw a `ConflictException` subclass for a version or
  selection-token mismatch, which `ControllerErrorFormatter` answers with the
  dedicated code `STALE_TARGET`; every other 409 keeps `CONFLICT` and its
  localized reason. `TaskListDetails.runAgreement()` keeps the "changed in the
  meantime" note for `STALE_TARGET` only and shows the server's reason inline
  in the note otherwise (amend, handover, catch-up, restart). Drafts survive
  both; no toast carries server text (design guideline).
- **BUG-UX-006 graphics contrast:** audit every main view and the arcade in the
  dark theme first. Graphics below 3:1 against their background (WCAG 1.4.11:
  bars, tracks, grid cells, icons, game elements) get theme tokens from
  `src/styles/tokens.css` or Nextcloud's semantic colors instead of fixed
  light-theme shades; text contrast stays at zero axe violations.

Admitted items (D-088-19):

- **FEAT-UX-015 owner removal (register #7):** `HouseService::removeHouseMember()`
  accepts an owner target when it is not the acting owner, re-checked on the
  locked house and membership rows; the actor stays an owner, so the D6
  last-owner invariant holds and removal stays durable (invite-only after the
  first owner, ADR-010). Self-removal keeps `CANNOT_REMOVE_OWNER`. The cleanup
  is the existing member departure path. `MemberList` already enables the
  action for every other member; its owner refusal text names self-removal.
- **BUG-UX-003 checklist toggle (register #9):** the daily, due-today and
  overdue rows carry the definition's checklist item count from one grouped
  query; `TodayView` renders the toggle only for a count above zero and drops
  the empty-discovery state. No per-row request.
- **FEAT-UX-044 unique room names (register #14):** `RoomService` create and
  rename, and the setup wizard, resolve a duplicate under the house lock to the
  next free " 2", " 3", … (trimmed, case-insensitive, within the house); the
  response carries the final name, which the UI shows. Existing duplicates stay
  until renamed; no data rewrite.
- **FEAT-UX-045 last done (register #16, D-088-20):** a receipt command
  `PUT /api/v1/room-tasks/{id}/last-done` (`commandId`, `date`) in a small
  service of its own, under the house-first and room-task locks: it records
  the `last_done` journal event (stable id per room task and command), resyncs
  the room cache and, for an interval task, amends the pending occurrence
  through `amendOccurrenceDateUnderLock`. `RoomReplayService` stamps
  `lastCleaned` from the newest `last_done` per room task after the latest
  reset. `RoomTaskEditModal` gains the date field.
- **BUG-UX-007 room switch (D-088-21):** `RoomDetail` watches its room id,
  resets the per-room dialogs and stats and reloads; a slower load of the
  previous room no longer writes its stats, error or loading state.

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
  no `--force` or `--legacy-peer-deps`. Proof (D-088-09): content equivalence of
  the built `js/` against a same-source build on the previous chain, full gates
  on both runtime rows, reproducible `test:package:build`. The unused
  `@nextcloud/initial-state` direct dependency is dropped (D-088-08).
- **TECH-LINT-008** — `eslint.config.js` (flat) on ESLint 10 with
  `@nextcloud/eslint-config` 9, which bundles `eslint-plugin-vue` 10,
  `typescript-eslint` 8, `eslint-plugin-jsdoc` and the Nextcloud rules, so the
  former direct ESLint plugin/parser/resolver devDependencies are removed;
  `npm run lint` covers the same file sets (`src`, `tests/e2e`,
  `tests/docs-screenshots`); new-rule findings fixed per rule
  family; no warning suppression to make the gate green. A deliberate rule
  policy change needs explicit review and approval, not a silent disable; the
  three rules the legacy config already disabled (`jsdoc/require-jsdoc`,
  `jsdoc/tag-lines`, `vue/first-attribute-linebreak`) and the existing Playwright
  and webpack-global overrides carry over unchanged. The
  `postcss-selector-parser`, `fast-xml-parser` and `@vue/eslint-config-typescript`
  overrides are removed; `.eslintrc.js` archived.

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

TECH-TEST-008 and the self-service departure of FEAT-UX-015 stay in their
existing backlog entries, without implementation authorization here (the
owner-removal part of FEAT-UX-015 is admitted by D-088-19). In particular, `removeHouseMember()` is
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
  final gates. Admitted items (D-088-11): FEAT-UX-038 — `RoomPicker`,
  `TaskEditForm` and `TaskManager` specs for room, floor-only, person and
  no-filter cases plus the unchanged edit form; FEAT-UX-039 — PHPUnit policy
  cases per unit and for a weekday set (at the cap, one above, `custom`, legacy
  value kept on an unrelated update), a `TaskService` validation case, an
  `OccurrenceService` selection case with overlapping windows, Jest for the
  utility and the form; BUG-UX-001 — `Modal` spec (backdrop ignored when locked,
  still closing when not; Esc, close button and Cancel always close) and a
  browser check at phone size that a long task form keeps its actions inside the
  viewport and survives a backdrop click. Admitted items (D-088-14): BUG-UX-005 —
  `RoomTaskReadService` cases (open window before the planned day included and
  flagged; a window not yet open and planned-today rows unchanged; child
  eligibility), `TodayView` spec for the group and its Done, a
  `flex-window-scheduling.spec.ts` case completing one day before the planned
  day inside a two-day window; for the Tasks tab, `OccurrenceTimelineTest`
  cases (a flex increase widens the planned occurrence at once with a `window`
  agreement and never shrinks it; a missed occurrence stays missed), a
  `roomTasks` store case (`NOT_OPEN_YET` without a request) and an
  `errorToast` case for its message; BUG-UX-004 — a `task-manager.spec.ts` case
  asserting an unchanged scroll position after toggling a member chip in the
  details of a lower row; BUG-UX-002 — the reproduction result recorded in the
  register first, PHPUnit pinning `STALE_TARGET` for version/token mismatches
  and `CONFLICT` with the localized reason for each domain refusal (formatter,
  controller, services), a `TaskListDetails` spec for a capacity refusal vs. a
  stale conflict (drafts kept in both), a `family-rhythm.spec.ts` case moving an
  occurrence onto a day the member is unavailable. Admitted item (D-088-16):
  BUG-UX-006 — `color-contrast.spec.ts` gains a non-text check measuring the
  audited graphics against their background in both themes (≥ 3:1); the text
  check stays at zero violations. Admitted items (D-088-19): FEAT-UX-015 owner
  removal — `HouseServiceTest` (another owner removed; self-removal and a
  non-owner actor refused) and `HouseMemberRemovalIntegrationTest` (an owner
  target gets the member departure cleanup; the actor stays owner);
  BUG-UX-003 — `RoomTaskServiceDailyTest` for the item count on daily,
  due-today and overdue rows, `TodayView` spec (no toggle without items, toggle
  with items), `workflow-request-budgets.spec.ts` unchanged and green;
  FEAT-UX-044 — `RoomServiceTest` and `HouseSetupServiceTest` for create,
  rename and setup suffixing incl. case, whitespace, the renamed room itself
  and existing duplicates left alone; FEAT-UX-045 — `RoomReplayService` cases
  (the stamp without a cleanliness change, a newer entry replacing an older
  one, a later completion winning, an entry before a reset hidden), service
  cases (interval re-anchor to `max(today, last done + period)`, fixed cadence
  unchanged, future date and child refused, overdue refused with its reason,
  idempotent replay of the receipt), an integration case on a populated house
  (no completion, wallet or rhythm row written) and a `RoomTaskEditModal` spec.
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
- **TECH-DEV-001 / TECH-LINT-008:** `npm ci --engine-strict` on the new range at
  the minimum (24.14.0, the first Node 24 whose bundled npm 11.9.0 satisfies
  `^11.8.2`) and a current patch; Jest, ESLint, Stylelint, production build;
  built `js/` compared for content equivalence (D-088-09); CI `checks` green on
  the new Node; `test:package:build` reproducible (two builds, one archive
  hash). Record the comparison against a same-source build on the previous
  toolchain, not against a different app revision; any difference beyond the
  documented minimizer/webpack effect blocks pending review, not a new ignored
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
archived: archive/2026-10/eslintrc-legacy/.eslintrc.js        (TECH-LINT-008; actual archive month)
modified: src/** and tests/** rule-family fixes, l10n/* where an ellipsis msgid changes   (TECH-LINT-008)
modified: src/views/TaskManager.vue, src/components/Tasks/TaskEditForm.vue,
          src/components/Tasks/RoomPicker.vue,
          tests/unit/views/TaskManager.spec.js, tests/unit/components/TaskEditForm.spec.js,
          tests/unit/components/RoomPicker.spec.js            (FEAT-UX-038)
new:      lib/Service/FlexDaysPolicy.php, tests/unit/Service/FlexDaysPolicyTest.php,
          src/utils/flexDays.js, tests/unit/utils/flexDays.spec.js   (FEAT-UX-039)
modified: lib/Service/TaskService.php, lib/Service/OccurrenceRevisionService.php,
          lib/Db/TaskMapper.php (docblock),
          tests/unit/Service/TaskServiceTest.php, tests/unit/Service/OccurrenceServiceTest.php,
          tests/unit/Service/TaskCreationServiceTest.php,
          tests/integration/Service/TaskCreationAggregateTest.php, tests/e2e/3-floor-house.spec.ts,
          tests/e2e/core-household-journey.spec.ts (weekly flex candidate),
          src/components/Tasks/TaskEditForm.vue, tests/unit/components/TaskEditForm.spec.js,
          docs/api/README.md, docs/concepts/task-model-and-sync.md,
          l10n/en.json, l10n/de.json, l10n/templates/chorestrike.pot   (FEAT-UX-039)
modified: src/components/Common/Modal.vue, tests/unit/components/Modal.spec.js,
          src/views/TaskManager.vue, src/views/RoomDetail.vue, src/views/Settings.vue,
          src/views/InsightsView.vue, src/components/Tasks/TaskEditForm.vue,
          src/components/Tasks/RoomTaskEditModal.vue, src/components/Tasks/TaskChecklist.vue,
          src/components/Rewards/RewardCatalogEditor.vue,
          src/components/Rewards/RewardRequestDetails.vue,
          src/components/Scheduling/AvailabilityEditModal.vue,
          src/components/Common/ParticipationDialog.vue,
          tests/e2e/task-manager.spec.ts,
          docs/standards/guidelines/design.md                  (BUG-UX-001)
modified: lib/Service/RoomTaskReadService.php, lib/Controller/RoomTaskController.php,
          tests/unit/Service/RoomTaskServiceDailyTest.php,
          src/views/TodayView.vue, tests/unit/components/TodayView.spec.js,
          tests/e2e/family-rhythm.spec.ts, docs/api/README.md,
          docs/concepts/task-model-and-sync.md, docs/user-guide.md,
          l10n/en.json, l10n/de.json, l10n/templates/chorestrike.pot   (BUG-UX-005)
modified: lib/Service/TaskService.php, lib/Service/OccurrenceService.php,
          lib/Service/OccurrenceAgreementService.php, lib/Service/OccurrenceRowFactory.php,
          tests/integration/Service/OccurrenceTimelineTest.php,
          src/store/roomTasks.js, src/utils/errorToast.js,
          tests/unit/store/roomTasks.spec.js, tests/unit/utils/errorToast.spec.js,
          docs/adr/ADR-013-occurrence-agreements-and-corrections.md, docs/api/README.md,
          docs/concepts/task-model-and-sync.md, l10n/en.json, l10n/de.json, l10n/templates/chorestrike.pot
          (BUG-UX-005 Tasks tab, D-088-17/18)
modified: src/styles/a11y.css, tests/e2e/family-core-ux.spec.ts   (BUG-UX-004; one rule
          anchors every label-wrapped hidden input: member and weekday chips,
          availability toggles)
new:      lib/Exception/StaleTargetException.php               (BUG-UX-002)
modified: lib/Controller/ControllerErrorFormatter.php, lib/Service/AgreementService.php,
          lib/Service/OccurrenceAgreementService.php,
          tests/unit/Controller/ControllerErrorFormatterTest.php,
          tests/unit/Service/AgreementServiceTest.php,
          tests/integration/Service/OccurrenceTimelineTest.php,
          src/components/Tasks/TaskListDetails.vue, tests/unit/components/TaskListDetails.spec.js,
          tests/e2e/family-rhythm.spec.ts, docs/api/README.md,
          docs/engineering/testing.md                          (BUG-UX-002)
modified: src/utils/icons.js, tests/unit/utils/icons.spec.js, src/styles/tokens.css,
          src/components/Dashboard/HouseHealthBar.vue, src/components/Dashboard/HouseholdGoalBar.vue,
          src/components/Dashboard/RoomCard.vue, src/components/Insights/MemberFairnessTable.vue,
          src/components/Tasks/FlexWindowDisplay.vue, tests/e2e/family-core-ux.spec.ts,
          tests/e2e/color-contrast.spec.ts, docs/standards/guidelines/design.md
                                                               (BUG-UX-006; the audit
          cleared QuickStats, WeekBudgetGrid and the arcade boards)
new:      tests/e2e/fixtures/non-text-contrast.ts, tests/e2e/fixtures/theme.ts   (BUG-UX-006)
modified: lib/Service/HouseService.php, lib/Controller/UserController.php,
          src/components/Members/MemberList.vue,
          tests/unit/Service/HouseServiceTest.php,
          tests/integration/Service/HouseMemberRemovalIntegrationTest.php,
          tests/unit/components/MemberList.spec.js, docs/api/README.md,
          docs/engineering/architecture.md, docs/user-guide.md,
          l10n/en.json, l10n/de.json, l10n/templates/chorestrike.pot
                                                               (FEAT-UX-015 owner removal)
new:      tests/integration/Db/TaskChecklistItemCountTest.php
modified: lib/Db/TaskChecklistItemMapper.php, lib/Service/RoomTaskReadService.php,
          src/views/TodayView.vue, tests/unit/Service/RoomTaskServiceDailyTest.php,
          tests/unit/components/TodayView.spec.js, docs/api/README.md, and the
          read-service constructions in RoomTaskReadServiceTest,
          RoomTaskServiceTest, RoomTaskServiceLoggingTest and
          RoomTaskServiceExtendedFeaturesTest                  (BUG-UX-003)
new:      lib/Service/RoomNamePolicy.php, tests/unit/Service/RoomNamePolicyTest.php,
          tests/integration/Db/RoomMapperLiveNamesTest.php
modified: lib/Db/RoomMapper.php, lib/Service/RoomService.php,
          lib/Service/HouseSetupService.php, src/store/rooms.js, src/views/Settings.vue,
          src/views/RoomDetail.vue, tests/unit/Service/RoomServiceTest.php,
          tests/unit/Service/HouseSetupServiceTest.php, tests/unit/store/rooms.spec.js,
          tests/unit/views/Settings.spec.js, docs/api/README.md, docs/user-guide.md,
          l10n/en.json, l10n/de.json, l10n/templates/chorestrike.pot   (FEAT-UX-044)
new:      lib/Service/RoomTaskLastDoneService.php, lib/Controller/RoomTaskLastDoneController.php,
          tests/integration/Service/RoomTaskLastDoneIntegrationTest.php,
          tests/unit/Controller/RoomTaskLastDoneControllerTest.php
modified: lib/Db/RoomEvent.php, lib/Db/RoomEventMapper.php, lib/Service/RoomReplayService.php,
          lib/Service/RoomTaskService.php (child day-limit check public),
          appinfo/routes.php, tests/unit/RouteMatrixTest.php (route pin 96 → 97),
          tests/unit/Service/RoomReplayServiceTest.php, src/store/roomTasks.js,
          src/components/Tasks/RoomTaskEditModal.vue, tests/unit/store/roomTasks.spec.js,
          tests/unit/components/RoomTaskEditModal.spec.js, docs/api/README.md,
          docs/concepts/task-model-and-sync.md, docs/concepts/ui-use-cases.md,
          docs/user-guide.md, docs/adr/ADR-013-occurrence-agreements-and-corrections.md,
          docs/adr/ADR-014-work-accounting-ledger-and-replay.md, docs/engineering/architecture.md,
          l10n/en.json, l10n/de.json, l10n/templates/chorestrike.pot   (FEAT-UX-045)
modified: src/views/RoomDetail.vue, tests/unit/views/RoomDetail.spec.js   (BUG-UX-007)
```

## 6. Implementation Order

1. Standard A review; resolve Q-088-01..07; on `PASS`/`PASS-WITH-FIXES` move to
   `active/`, set `Owning: CS-PLAN-088` and `In Progress` on the selected
   backlog items, update `ACTIVE_TASKS.md`.
2. Open the feedback register; triage incoming items at least weekly; the
   hotfix rule is in force from day one.
3. WP-C toolchain series (D-088-08): dependency/engine commit with the flat
   config, then one commit per ESLint rule family, then package builder, CI and
   documentation; content-equivalence comparison and full gates before
   anything else lands on it.
4. WP-B, one commit each: CR-PERF-01, TECH-DIGEST-001, CR-084-04, CR-LAYOUT-01,
   TECH-TEST-009.
5. (Folded into step 3 by D-088-08.)
6. WP-A fixes as they arrive, each with its regression test; focused browser
   runs for visible changes. After the admitted fixes: the operator GUI review
   (D-088-16) with screenshots of every changed surface in light and dark at
   phone and desktop width.
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
| Feedback volume exceeds the window | High (seven WP-A items admitted 2026-10-26, a week before the freeze target) | Date is a target; in-scope findings/tests remain required until explicitly user-rescoped; out-of-scope feedback folds into existing backlog owners |
| A P0/P1 finding appears mid-window | Low | Hotfix rule: own branch, requalification, exchange handoff; this plan rebases |
| Toolchain majors change the bundle or lint set | Medium | Content-equivalence `js/` comparison (D-088-09), full gates on both rows, package smoke; each major is one revertible commit series |
| ESLint 10 rule fallout churns many files | Medium | One commit per rule family; rule-policy changes need explicit approval, never suppress warnings to pass |
| A feedback fix needs a schema change | Low | None without a plan update and the populated-baseline upgrade test; the pinned-migration test fails on edits |
| Family data reaches the public register | Low | Role-level sanitized entries only; raw notes stay with the operator in `private/` |
| Node/npm engine or peer mismatch | Medium | Node 24/npm 11 qualification, strict install at the minimum and current patch, no forced peer resolution |
| The alpha.2 real-device record stays open | Medium | Q-088-03 (A) tags alpha.2 only when its record exists; alpha.3 needs its own record either way |

## 8. Acceptance Criteria

- [ ] Every selected item delivered with its tests, or explicitly user-rescoped with recorded approval and backlog state updated; no still-required deferred test
- [ ] Feedback register: every sanitized item routed (fixed with regression test / backlog ID / rejected with reason); no open P0 or P1
- [ ] No schema or migration change (or an approved plan update with the populated-baseline upgrade proof)
- [ ] Built `js/` compared for content equivalence after the toolchain change (D-088-09); `npm ci --engine-strict` passes on the new range at the minimum and a current Node 24 patch; CI green
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
- [x] RF-088-09 (major) - Toolchain ordering: a strict Node 24 install is blocked by `@nextcloud/eslint-config@8`, `@nextcloud/eslint-plugin@2` and the unused `@nextcloud/initial-state@2.2.0` (all `node ^20`/`npm ^10`), so TECH-DEV-001 cannot precede TECH-LINT-008; D-088-08 merges them into one series (found 2026-10-20 while preparing the lockfile; operator answered the recommended option).
- [x] RF-088-10 (major) - Bundle proof: byte identity of `js/` is unattainable across the bundler major (webpack 5.105.2 → 5.112.0, terser → `minimizer-webpack-plugin`; `chorestrike-main.js` 3.93 → 3.60 MB); D-088-09 replaces it with content equivalence plus the gates (operator answered the recommended option).
- [x] RF-088-11 (major) - Runtime currency: AIO `v14.4.1` (seen 2026-10-27) bundles Nextcloud 34.0.5 on PHP 8.4.27 (inventory: AIO 14.3.0, Nextcloud 34.0.4, PHP 8.4.26; APCu, phpredis, Redis and PostgreSQL unchanged, upstream still 35.0.2). `test:runtime:check` fails closed (`AIO apcu metadata`) because the AIO Dockerfile now pins its PECL versions as `ARG …_VERSION` and builds them from GitHub tarballs instead of `pecl install`. Fix the parser for that form (with a fixture test), then promote the AIO row through a reviewed inventory update and requalification before the WP-D qualification. The WP-A browser gate ran on the rows the inventory names. Resolved 2026-10-28: parser fixed, inventory promoted to AIO v14.4.2 (NC 34.0.5, PHP 8.4.27), both rows requalified ([evidence](#rf-088-11-evidence-aio-v1442-runtime-promotion-2026-10-28)).
- [x] RF-088-12 (minor) - FEAT-UX-044: re-saving a room's unchanged name renumbered a legacy duplicate ("Bath" → "Bath 2") and logged it as a change, against "existing duplicates stay" (`RoomService.php` rename). Fixed (D-088-23): an unchanged trimmed name skips the numbering; `RoomServiceTest` pins it.
- [x] RF-088-13 (minor) - FEAT-UX-045: an impossible day such as `2025-02-29` passed the pattern check and returned an untranslated internal message; the controller also passed every other invalid argument verbatim. Fixed (D-088-23): strict calendar parse with the curated message as a `ValidationException`; only that is a 400, anything else a 500 with the safe message (controller and integration tests).
- [x] RF-088-14 (minor) - FEAT-UX-039: a repeat value above about 3·10^17 overflowed the period product into a `TypeError` (500) in `FlexDaysPolicy`. Fixed (D-088-23) by clamping the repeat value at 2 × the sanity bound, where every cap is already the bound, so no result changes (`FlexDaysPolicyTest`, `PHP_INT_MAX` cases).
- [x] RF-088-15 (minor) - FEAT-UX-045: an entry recorded after a house reset never reached "Last cleaned", because the replay starts at the reset and the entry's day lies before it. Fixed (D-088-24): the replay folds the newest entry per room task recorded after the latest reset (by id); a room with a reset reads those entries with one indexed query (the reset-bounded replay budget is now 3), a room without a reset or entries still reads nothing extra. A builder/parser pair owns the persisted `last_done:<roomTaskId>:<command>` key (replay unit tests, integration case with a real reset).
- [x] RF-088-16 (minor) - Structure and performance (D-088-25): `getFilteredDailyTasksForUser` split (22 lines, `childDailyRows` 22, `isChildEligible`); open-window rows derive their selections once, on viewer-visible candidates only, and one shared `withSelectionBlocks` serves the due-today, overdue and open-window paths; widening flex windows reads the open occurrences of all mounted room tasks in one query; a direct room switch refreshes the room list but keeps loaded task definitions unless the new room uses an unknown one (`RoomDetail` spec); one flex-cap input list in `TaskService`; readability fixes in `HouseSetupService` and the last-done controller. The pre-existing file size of `RoomTaskReadService` (993 lines, 928 before alpha.3) went to CR-SVC-65.

## Feedback Register (sanitized)

Role-level, sanitized entries only (adult / child / owner); no names, ages,
accounts, quotations or household details. Raw notes stay with the operator.

| # | Date | Role | Observation | Type / priority | Routing | State |
|---|------|------|-------------|-----------------|---------|-------|
| 1 | 2026-10-24 | owner | New Task dialog does not take over the active floor/room filter | Improvement / P2 | [FEAT-UX-038](../../backlog/archive/2026-10.md#alpha3-trial-feedback-fixes-cs-plan-088-wp-a-10-28) | Delivered (D-088-11, D-088-13); archived 2026-10-28 |
| 2 | 2026-10-24 | owner | Flex days stop at 7 although a 6-month period needs more; wanted: up to 50% of the period (rounded up, D-088-12) | Improvement / P2 | [FEAT-UX-039](../../backlog/archive/2026-10.md#alpha3-trial-feedback-fixes-cs-plan-088-wp-a-10-28) | Delivered (D-088-11, D-088-12); archived 2026-10-28 |
| 3 | 2026-10-24 | owner | A click beside the dialog closes it and discards the input; action buttons scroll away (wanted: close only via X, Cancel, Create or Esc; fixed action row, also in other form dialogs) | Bug / P2 | [BUG-UX-001](../../backlog/archive/2026-10.md#alpha3-trial-feedback-fixes-cs-plan-088-wp-a-10-28) | Delivered (D-088-11); archived 2026-10-28 |
| 4 | 2026-10-24 | owner | The "Repeat" selector at the top contradicts the recurrence inputs further down; control size and organisation need rework, with operator feedback first | Improvement / P2 | [FEAT-UX-040](../../backlog/backlog.md#p2-feat-ux-040--one-task-form-for-new-and-edit-recurrence-layout-and-controls) | Open; design feedback first |
| 5 | 2026-10-26 | owner | A task inside its flex days but before the due date could not be completed | Bug / P2 | [BUG-UX-005](../../backlog/archive/2026-10.md#alpha3-trial-feedback-fixes-cs-plan-088-wp-a-10-28) | Delivered (D-088-14, D-088-17, D-088-18); archived 2026-10-28 |
| 6 | 2026-10-26 | owner | Completing before the due date, even before the flex days, should be allowed by default and move the next due date; at most an owner option or a child restriction | Feature / P2 | [FEAT-UX-041](../../backlog/backlog.md#p2-feat-ux-041--allow-completing-work-before-its-window-opens) | Open; product decision |
| 7 | 2026-10-26 | owner | The account that created the house cannot be removed and appears everywhere | Improvement / P3 | [FEAT-UX-015](../../backlog/backlog.md#p3-feat-ux-015--leave-house-self-service-membership-exit) | Folded in; owner-removal part delivered (D-088-19); self-service departure stays in the backlog |
| 8 | 2026-10-26 | owner | Several houses per instance? (question for v1.0) | Feature / P4 | FEAT-UX-042, [archived as rejected](../../backlog/archive/2026-10.md#feat-ux-042-rejected-one-house-per-instance-10-26) | Rejected (D-088-15): too much effort |
| 9 | 2026-10-26 | owner | My Day shows a checklist button on tasks without a checklist; it vanishes on click | Bug / P3 | [BUG-UX-003](../../backlog/archive/2026-10.md#alpha3-third-admission-cs-plan-088-wp-a-10-28) | Delivered (D-088-19); archived 2026-10-28 |
| 10 | 2026-10-26 | owner | Clicking a person in the task details scrolls the whole screen up | Bug / P2 | [BUG-UX-004](../../backlog/archive/2026-10.md#alpha3-trial-feedback-fixes-cs-plan-088-wp-a-10-28) | Delivered (D-088-14); archived 2026-10-28 |
| 11 | 2026-10-26 | owner | New Task and Edit Task should share one mask, both with assignees and room | Improvement / P2 | [FEAT-UX-040](../../backlog/backlog.md#p2-feat-ux-040--one-task-form-for-new-and-edit-recurrence-layout-and-controls) | Folded in; design feedback first |
| 12 | 2026-10-26 | owner | Templates should mean "copy a task"; a template task in several rooms changes its title everywhere but other details apparently not | Improvement / P2 | [FEAT-UX-043](../../backlog/backlog.md#p2-feat-ux-043--templates-copy-instead-of-shared-definitions-and-a-simple-catalog-picker) | Open; decision on existing shared definitions |
| 13 | 2026-10-26 | owner | Template picker: room type dropdown and task list, short preview, Putzmuffel/Putzteufel switch, special sets such as pets and garden | Feature / P2 | [FEAT-UX-043](../../backlog/backlog.md#p2-feat-ux-043--templates-copy-instead-of-shared-definitions-and-a-simple-catalog-picker) | Open; mockup first |
| 14 | 2026-10-26 | owner | Room names should be unique; duplicates get a number appended (new and renamed rooms) | Improvement / P3 | [FEAT-UX-044](../../backlog/archive/2026-10.md#alpha3-third-admission-cs-plan-088-wp-a-10-28) | Delivered (D-088-19); archived 2026-10-28 |
| 15 | 2026-10-26 | owner | Moving an appointment reported "changed in the meantime" although nothing had changed | Bug / P2 | [BUG-UX-002](../../backlog/archive/2026-10.md#alpha3-trial-feedback-fixes-cs-plan-088-wp-a-10-28) | Delivered (D-088-14; no stale-token defect, no hotfix); archived 2026-10-28 |
| 16 | 2026-10-26 | owner | "Last cleaned" should be settable or correctable afterwards, e.g. right after creating the house | Feature / P3 | [FEAT-UX-045](../../backlog/archive/2026-10.md#alpha3-third-admission-cs-plan-088-wp-a-10-28) | Delivered (D-088-19, D-088-20 variant b); archived 2026-10-28 |
| 17 | 2026-10-26 | owner | Graphics have poor contrast, at least in the dark theme; some are barely visible | Bug / P2 | [BUG-UX-006](../../backlog/archive/2026-10.md#alpha3-trial-feedback-fixes-cs-plan-088-wp-a-10-28) | Delivered (D-088-16); archived 2026-10-28 |

## WP-C Evidence (toolchain series, 2026-10-20)

Local branch `chore/tech-dev-001-node24` (commits `ba88d8b`..`e1ac8bc` on top of the
activated plan; nothing pushed). Not a review outcome: the final Full review and the
remote CI evidence are still open.

- **Dependency state:** engines `node ^24` / `npm ^11.8.2`; `@nextcloud/webpack-vue-config` 7.1.0
  (webpack 5.112, `minimizer-webpack-plugin`), `sass-loader` 17.0.1, ESLint 10.13 with
  `@nextcloud/eslint-config` 9.0.1. The three security/compat overrides are gone: `sockjs`
  and `uuid` left the tree with webpack-dev-server 6, `postcss-selector-parser` 7.1.6 and
  `fast-xml-parser` 5.11.2 resolve patched without them. `@nextcloud/initial-state` is no
  longer a direct dependency (D-088-08).
- **Strict install:** `npm ci --engine-strict --legacy-peer-deps=false` passes on Node 24.14.0 /
  npm 11.9.0 (the minimum, 1469 packages, lockfile unchanged) and on Node 24.22.0 /
  npm 11.19.1 (portable zips, SHA-256 verified against the release checksums; no host
  installation). Host Node 24.13.1 ships npm 11.8.0 and therefore fails the npm range.
- **Lint:** ESLint 10 reported 2594 findings in 194 of 221 files on first contact (2311
  autofixable). Fixed in rule-family commits with a Jest run (156 suites / 2147 tests) before
  each; `npm run lint` now exits 0 without a single warning. Deliberate, reviewed
  deviations from the preset: three rules the legacy config already disabled, the webpack
  and Jest globals, the logger's `console`, the Playwright relaxations, `src/main.js` keeps
  `public-path.js` first and its stylesheets after the app imports (the preset's import
  sorter had moved them, which the existing `assetContract` spec caught), and D-088-10.
  Visible side effects of the new rules: nine message keys changed to the ellipsis
  character with POT/EN/DE in the same commit (two merged into existing keys, so the German
  UI shows "Wird geladen…" / "Speichern…"), component events are emitted in camelCase
  (listeners are unaffected), single-word component `name` options got a prefix, two dead
  props and two dead refs were removed.
- **Bundle (D-088-09):** the production bundle is not byte-identical (numeric module ids
  shift with the new optimizer). Same-source development-mode builds of the old and new
  toolchain are: 58 of 62 files byte-identical; the other four (`main`, `arcade`,
  `rehype-highlight` chunk, license list) contain the identical module sets (974 / 68 / 45
  modules, no module added or removed) and differ only by webpack 5.112 output:
  `.then(()=>n(id))` instead of `.then(n.bind(n,id))`, unused re-exports dropped,
  `__VUE_OPTIONS_API__` folded to `true`, runtime helper variables. No CSS module differs,
  so the Sass API change is neutral.
- **Package builder:** `node:24.14.0` (index `sha256:5a593d74…`, linux/amd64 `sha256:1698b8f4…`,
  verified as v24.14.0 / npm 11.9.0), `npm ci --engine-strict` inside the builder;
  `node scripts/build-package.js --verify-reproducible` (`run-mvgawtf9`): two builds, one
  archive hash `c7aced8a…`, 542 files, asset contract satisfied.
  `tests/unit/scripts/toolchainEngines.spec.js` pins builder, Dockerfile, every workflow
  step and `package.json` to one contract.
- **Gates on the final tree (Node 24.14.0):** Jest 156 / 2147, ESLint, Stylelint, production
  build, `l10n:check-keys` (1332 keys), `docs:encoding`, `backlog:check`, `agents:check`,
  `test:runtime:validate` and `check` (current, no drift). Browser gate, primary row
  (canonical stack, family profile): `run-JEPAkY` passed first attempt — chromium 97,
  chromium-serial-db 176, chromium-scheduling-owner 36, mobile-chromium 12, mobile-webkit 12
  plus three setup results each, zero skipped, failed or flaky (the counts of the last green
  run). Browser gate, AIO row (NC 34.0.4, Redis cache/locking/sessions): `run-HcYDxR`, the
  same counts. The staged AIO project was removed afterwards.
- **Still open for TECH-DEV-001 / TECH-LINT-008:** remote CI evidence (needs a push on explicit
  request), the full PHPUnit suites on both rows and the provider suites (run with the
  WP-D qualification; no PHP changed), `test:package:smoke`, and the final Full review.
  Follow-up TECH-LINT-009 (Boolean props that default to `true`) is routed to the backlog.

## WP-B Evidence (hardening, 2026-10-20)

Local branch `fix/alpha3-hardening` from the merged WP-C state (`b5c8568`); one commit per
item, nothing pushed. Not a review outcome.

- **Delivered and archived:** CR-PERF-01 (`ddf3431`), TECH-DIGEST-001 (`6e5f545`) and
  TECH-TEST-009 (`9a8a634`); archive entries in `docs/backlog/archive/2026-10.md`.
- **Implemented, waiting for a green full browser gate:** CR-084-04 (`56b11f4`,
  `HoldButton.vue` 440 → 287 lines) and CR-LAYOUT-01 (`47b42ed`, tokens on `.app-content`,
  heading-row inset in all 13 consumers). The extended `layout-clearance.spec.ts` (8 tests,
  including varied host metrics and RTL) and every `holdToConfirm` path passed in all gate
  attempts.
- **Jest / lint:** 158 suites / 2189 tests, ESLint 0 findings, Stylelint, `docs:encoding`,
  `backlog:check`, `composer cs:check` (424 files) green.
- **PHPUnit, provider matrix (TECH-TEST-009 criterion):** three complete four-target runs
  with the new `--matrix` mode (MariaDB, PostgreSQL and SQLite on the NC 35.0.2 primary row,
  PostgreSQL + Redis on the NC 34.0.4 AIO row) on the unchanged commit `9a8a634`: unit 1467
  and integration 635 on every target, no failure, error or skip, in all three rounds
  (`wb-round-1..3`, reports kept locally). They also exercised `DigestChangeMapperTest` on
  every provider. The earlier two unnamed SQLite failures stay unattributed; the three clean
  rounds bound the observation as not reproduced, they do not fix a root cause.
- **Browser gates — not green yet.** Four complete gate runs, no retries, each with a different
  small set of failures and all unrelated to the changed code:
  1. primary `run-SZI84s`: three required scenarios (`settings.spec.ts` ×2, `mobile-core`
     handover) with `TypeError: fetch failed` before any assertion, while four staged
     provider rows were also running;
  2. primary `run-QAySf3` (rows removed): one `mobile-core` webkit scenario (Settings not
     reached through the phone navigation within 5 s), not reproduced in five isolated runs;
  3. primary `run-yeFj8s`: one `calm-core-ui` scenario, `fetch failed`;
  4. AIO `run-6x3cI2`: `release-task-lifecycle` (`fetch failed`) and `arcade-mobile` Crumb
     Snake (game already *over* when the steer was checked).

  All seven scenarios pass when run alone on the same stack. The five `fetch failed` cases
  match the open TECH-TRANSPORT-001 pattern (seed/API connection closed before a response;
  no Apache error, OOM or restart; a foreign Testcontainers run was active on the shared
  Docker host); the evidence is folded into that item, not claimed as its cause. The two
  other failures are timing-sensitive and also unattributed. The same two toolchain gates
  passed first time earlier the same day (`run-JEPAkY`, `run-HcYDxR`), so no gate was green
  on this branch yet; CR-084-04 and CR-LAYOUT-01 stay In Progress until one is. *Closed
  2026-10-28:* the full gates over the merged code (`run-zthKaf`, `run-WAcLa3`, see WP-A
  evidence) are green on both rows; both items are archived.

## WP-A Evidence (feedback fixes, 2026-10-26)

Local branch `fix/alpha3-feedback` from `master` `a84ee95`; one commit per item, nothing
pushed or merged. Not a review outcome.

- **Implemented:** BUG-UX-002 (`1837a4f`), BUG-UX-001 (`567bc3f`, scroll-padding follow-up
  `178b6f4`), FEAT-UX-038 (`a5b3cab`), FEAT-UX-039 (`fb0742e`, journey candidate
  `9772b89`), BUG-UX-004 (`40ac1e0`), BUG-UX-005 (`a33d958`), BUG-UX-006 (`95d1ed4`).
- **Reproductions:** BUG-UX-002 in a Europe/Berlin house (no stale-token defect; the refusal
  was a domain reason, so no hotfix); BUG-UX-004 (the browser case failed with the
  unanchored input); BUG-UX-006 dark-theme audit of eight views in both themes and widths
  plus the non-text probe (failed before, passes after).
- **Jest / lint:** 159 suites / 2227 tests, ESLint 0, Stylelint, `test:noise`,
  `l10n:check-keys`, `agents:check`, `backlog:check`, `docs:encoding` green.
- **PHPUnit (canonical NC 35 stack, PostgreSQL):** unit 1498, integration 637, no failure,
  error or skip; `composer cs:check` (427 files) clean.
- **Browser, focused on the canonical stack:** the new or changed cases in
  `family-rhythm.spec.ts` (non-UTC move, open flex window on My Day), `task-manager.spec.ts`
  (phone dialog), `family-core-ux.spec.ts` (chip scroll), `color-contrast.spec.ts` (both
  themes with the non-text probe) and the canonical `core-household-journey.spec.ts`.
- **Operator GUI review (D-088-16), 2026-10-27:** nine screenshots of the changed surfaces
  in both themes and widths — the GUI looks good. Answers: BUG-UX-005 happened in My Day
  and the Tasks tab; BUG-UX-006 meant the Tasks tab too, where the flex window of a task
  row was the remaining weak graphic (fixed, with its own browser case).
- **BUG-UX-005 Tasks tab (D-088-17, D-088-18):** cause confirmed in code — a flex edit left
  the planned occurrence on its old window, so the Tasks-tab Done had no open target. The
  widening and the `NOT_OPEN_YET` message are covered by two `OccurrenceTimelineTest`
  cases and the store and toast specs.
- **Full browser gate, 2026-10-28 (HEAD `4f19e1a`; scenario inventory extended by the five
  new cases, no existing scenario changed):** primary row (canonical stack, NC 35) `run-zthKaf`
  and AIO row (NC 34.0.4, Redis cache/locking/sessions, project `cs082-alpha3wpa-aio`)
  `run-WAcLa3`, both green on the first run — chromium 103, chromium-serial-db 183,
  chromium-scheduling-owner 39, mobile-chromium 15, mobile-webkit 15 (setup results included),
  zero skipped, failed or flaky. PHPUnit on the AIO row: unit 1498, integration 637, no
  failure, error or skip. The seven WP-A items are archived; RF-088-11 stays open for WP-D.
- **Third admission (D-088-19, D-088-20), 2026-10-28:** branch `feat/alpha3-feedback-2` from
  `master` `670793b` (worktree `~/worktrees/chorestrike-alpha3-feedback-2`), one commit per item,
  merged after the gates (below): FEAT-UX-044 (`2774fe8`), FEAT-UX-015 owner removal (`367ac1f`), BUG-UX-003
  (`ac3bb37`, payload fix `821e464`), FEAT-UX-045 (`f44b65f`). No schema change; the route pin
  moves 96 → 97 for `PUT /room-tasks/{id}/last-done`. Jest 159 suites / 2236 tests, ESLint 0,
  Stylelint, `l10n:check-keys` (1344), `backlog:check`, `docs:encoding`, `agents:check`;
  `composer cs:check` (435 files) clean.
- **Third admission gates:** PHPUnit on the canonical stack unit 1521 / integration 646 and on the
  AIO row (NC 34.0.4, Redis) unit 1521 / integration 646 with `--fail-on-skipped`. The first full
  browser gate (`run-Ay7iey`) failed two child My Day checklist scenarios: the execution-row
  serializers dropped the new `checklistItemCount`, so the toggle vanished for tasks with a
  checklist — fixed in `821e464` with controller tests on the HTTP shape. The reruns passed on
  the first attempt on both rows: `run-dSEFzj` (primary) and `run-WuUlh2` (AIO), with the
  WP-A counts (chromium 103, chromium-serial-db 183, chromium-scheduling-owner 39, mobile
  15 + 15) and zero skipped, failed or flaky. A first replay design read the entry index on
  every replay and broke the completion/undo query budgets; the index is now read only for
  rooms with a `last_done` entry (`RoomReplayServiceTest` pins it).
- **Operator GUI review (D-088-21), 2026-10-28:** ten screenshots of the four items in both
  themes and widths pass. BUG-UX-007, found during the capture, is admitted and fixed with a
  `RoomDetail` regression spec (fails without the fix).
- **Gates after the BUG-UX-007 fix (`4a7fd4d`), 2026-10-28:** AIO row `run-qnqrVb` passed on the
  first attempt. The primary row `run-vHWEsl` failed one scenario on a 15 s locator timeout
  (`family-rhythm.spec.ts`, agreed handover): the trace shows correct data, but the room's
  task read took 7.4 s while every response slowed steadily under host load from a parallel
  stack; the scenario's code path is unchanged by the fix. The unchanged rerun `run-Hw7uA8`
  passed on the first attempt. Both green runs carry the WP-A counts with zero skipped, failed
  or flaky required scenarios.
- **Merged** into the local `master` by fast-forward on 2026-10-28 (operator-approved, nothing
  pushed); BUG-UX-003, BUG-UX-007, FEAT-UX-044 and FEAT-UX-045 archived, the FEAT-UX-015
  owner-removal fold-in checked off (self-service departure stays open).

## RF-088-11 Evidence (AIO v14.4.2 runtime promotion, 2026-10-28)

Branch `chore/aio-14.4.2-runtime` from `master` `afec524` (worktree
`~/worktrees/chorestrike-aio-14.4.2`). No app code, schema or migration change.

- **Parser:** `scripts/test-runtime.js` reads the APCu and phpredis pins from the
  `ARG APCU_VERSION=` / `ARG REDIS_VERSION=` lines AIO uses since v14.4.1 and counts a pin
  only while the extension's GitHub tarball download still consumes it. A missing pin or a
  pin the download no longer uses fails closed. The Jest fixture has the v14.4.2 Dockerfile
  shape, with fail-closed cases for both pins and both download lines.
- **Upstream (2026-10-28):** AIO v14.4.2 is the highest stable release (it supersedes the
  v14.4.1 seen on 2026-10-27): NC 34.0.5, PHP 8.4.27, APCu 5.1.28, phpredis 6.3.0, Redis
  8.10.2, PostgreSQL 18.6. Upstream stable is still 35.0.2.
- **Inventory:** `verifiedAt` 2026-10-28, status stays `qualified`. PHP 8.4.26 → **8.4.27**
  (`php:8.4.27-apache`, index `be132665…`, amd64 `f285280a…`); AIO 14.3.0 → **14.4.2** with
  NC 34.0.4 → **34.0.5** (internal 34.0.5.1, revision `77e3cab1…`, notifications
  `4ad7881f…`, viewer `09f8b9ff…`) and update source 34.0.3 → 34.0.4 with its pins as
  patches. PostgreSQL 18.6 and Redis 8.10.2 keep their versions; their images were
  republished, so the digests moved (pgsql `10a5c69a…` / `3b0ef735…`, Redis `9f8d2d78…` /
  `16b124a2…`). PHP is one inventory field, so the primary row moved too: `Dockerfile.test`
  defaults, selection hash primary `0ddc6760…` / AIO `704381b9…`, the compose PHP identity
  and the PostgreSQL digest in all three compose files. Current-state statements in README,
  docker README, development, deployment and testing docs and the docker-dev skill follow;
  historical records keep their versions. The `lcobucci/clock` limitation is the same
  reviewed failure on PHP 8.4.27 (NC 34.0.5 still locks clock 3.0.0), so no new disposition.
- **Images:** all four rebuilt, `verify-image` `frozen-image-identity-match`:
  `cs082-runtime-35.0.2-local` (`51a536ff6efb`), `-35.0.1-local` (`1c84b83b8c03`),
  `-34.0.5-local --runtime=aio` (`60e53ebb229e`), `-34.0.4-local --runtime=aio`
  (`7481bfc91b51`), with exactly the two reviewed upstream limitations.
- **Check:** `test:runtime:check` `current` (upstream 35.0.2, AIO 14.4.2 / NC 34.0.5, no
  drift); `test-runtime.js validate` `valid` / `qualified`.
- **Gates:** canonical stack recreated (`test:runtime:canonical` match, PHP 8.4.27): PHPUnit
  unit 1521 / integration 646 with `--fail-on-skipped`, full browser gate `run-1tN7fk`. AIO
  row `cs082-rf08811-aio` (NC 34.0.5.1 / PHP 8.4.27 / PostgreSQL 18.6 / Redis 8.10.2 for
  sessions, cache and locking): unit 1521 / integration 646, full browser gate `run-anL2IM`.
  Both gates passed on the first attempt with the WP-A counts (chromium 103,
  chromium-serial-db 183, chromium-scheduling-owner 39, mobile 15 + 15) and zero skipped,
  failed or flaky; the AIO stage is torn down.
- **P2 populated update on the AIO row** (`test-runtime-preservation.ps1 -Proof p2 -Row aio`):
  the official Nextcloud upgrade 34.0.4.1 → 34.0.5.1 on populated synthetic facts, the update
  production's AIO will take. 26 migrations unchanged, 3 volumes preserved, all 30 app table
  snapshots equal, sentinels and post-update read/mutation pass. The first run
  (`cs082-adfb0ee0`) passed but left the Redis peer and the project network behind while
  reporting a clean teardown; fixed in this round (R088-11-01 below). The rerun
  `cs082-ecb43565` passed with the same results and an empty residue check.
- **Deployment consequence:** the inventory now names only AIO v14.4.2 / NC 34.0.5. Per the
  pre-deployment checklist the production AIO must run NC 34.0.5 before `0.8.0-alpha.3` is
  deployed (operator step before WP-D's deployment; the last record, `0.8.0-alpha.2` on
  2026-10-19, shows AIO 14.3.0 / NC 34.0.4).

## WP-D Evidence (release candidate, 2026-10-28)

Branch `chore/release-0.8.0-alpha.3` from `master` `dccafeb` (worktree
`~/worktrees/chorestrike-alpha3-release`), frozen early per D-088-22.

- **Candidate:** `0.8.0-alpha.3` in `appinfo/info.xml`, `package.json` and
  `package-lock.json`; `CHANGELOG.md` section *0.8.0-alpha.3 — Trial Feedback Round
  (candidate)* under *Unreleased* (dated heading after the verified deployment, Q-088-04),
  with dated headings for the deployed alpha.2 and alpha.1; README badge, caption and
  status; the docker README's expected version; eight screenshots recaptured on the
  candidate. Roadmap snapshot and documentation map follow after the deployment.
- **Pre-candidate Full review:** RF-088-12..16 fixed with regression tests (§9), CR-SVC-65
  routed ([record](../../backlog/archive/CODE_REVIEW_2026-10-28.md#pre-candidate-full-review---cs-plan-088-080-alpha3-release-delta)).
- **Qualification on the reviewed working tree:** `test:runtime:check` `current`;
  production build; canonical stack recreated (`test:runtime:canonical` match), PHPUnit
  unit 1531 / integration 647; matrix run `a3c` with the same counts on SQLite, MySQL and
  the AIO row (NC 34.0.5 / PostgreSQL 18.6 / Redis); AIO-row browser gate `run-hlouAh`
  green on the first attempt (chromium 103, chromium-serial-db 183,
  chromium-scheduling-owner 39, mobile 15 + 15). Primary browser gate: the first run
  `run-8CHJRU` was invalid during a host slowdown (client-side `socket hang up` and
  `fetch failed`, one correct room view rendered just after its 5 s window; no content
  assertion failed); the complete rerun `run-ECyo8u` passed on the first attempt with the
  same counts. Tested inputs: 22 non-documentation paths against `dccafeb`, diff SHA-256
  prefix `0aeebff1e13d3a85`; only documentation changed afterwards.
- **Candidate commit:** `0142a3881ef8d9b692bf471e0894f04c63e03a06` on
  `chore/release-0.8.0-alpha.3`; its non-documentation diff against `dccafeb` has the
  tested fingerprint `0aeebff1e13d3a85`.
- **Package:** `test:package:build --verify-reproducible` from the clean candidate:
  `reproducible` (run `run-mvsq4ldw`, 548 files). `test:package:smoke` run
  `run-mvsq7gf7`: `package-qualified` at 2026-10-28T23:13Z on `primary@35.0.2` and
  `aio@34.0.5` from the same extracted archive (cold owner bootstrap and the six owner
  cases per row, post-qualification rehash ok, owned resources removed).
  `chorestrike.tar.gz` SHA-256
  `77dcc0a632b28f042c145a150f50b106a268650263a107f76d5df03323165ada` (1,714,603 bytes),
  equal in both runs. This smoke run is the proposed export candidate (the export needs
  its own approval).

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

**Interim Standard B, 2026-10-28 (RF-088-11 runtime promotion): PASS-WITH-FIXES.** Scope:
branch `chore/aio-14.4.2-runtime` at base `afec524`, 15 modified tracked paths (unstaged
diff SHA-256 prefix `94d90c789d0e8bd8` at capture): drift-check parser, inventory, image
and compose pins, spec hashes and current-runtime docs. One minor finding fixed in this
round: R088-11-01, a passing AIO-row P1/P2 left the Redis peer and the project network
behind while reporting a clean teardown (`--remove-orphans` plus a label residue check,
self-test, `testing.md`). No open findings. Gates: runtime check `current`, PHPUnit
1521/646 and full browser gates `run-1tN7fk` / `run-anL2IM` on both rows, P2 on the AIO
row, Jest 159/2240, ESLint, `backlog:check`, `docs:encoding`, `agents:check`. The plan stays
active. Record: [2026-10-28 review](../../backlog/archive/CODE_REVIEW_2026-10-28.md).

**Pre-candidate Full review, 2026-10-28 (WP-D): PASS-WITH-FIXES (interim).** Scope: the
release delta since the qualified alpha.2 candidate, `fcb65cf..dccafeb` over `lib/`,
`appinfo/`, `src/` and `css/` (153 files, binary diff SHA-256 prefix `d3a6587c99ddb015`),
on branch `chore/release-0.8.0-alpha.3`; four read-only tracks (architecture, security,
performance, correctness). Five minor findings, all fixed with regression tests in this
round: RF-088-12..14 (D-088-23), RF-088-15 (D-088-24), RF-088-16 (D-088-25); the
`RoomTaskReadService` file size went to CR-SVC-65. No open findings; nothing exploitable.
Gates: Jest 159/2241, ESLint, stylelint, `composer cs:check`, PHPUnit 1531/647 on the
canonical stack and on SQLite, MySQL and the AIO row (matrix `a3c`), AIO browser gate
`run-hlouAh`, primary browser gate `run-ECyo8u` (rerun after the invalid `run-8CHJRU`
under a host slowdown). The plan stays active for the package build and smoke,
export, deployment and the final completion review. Record:
[pre-candidate Full review](../../backlog/archive/CODE_REVIEW_2026-10-28.md#pre-candidate-full-review---cs-plan-088-080-alpha3-release-delta).
