# Backlog — Open Items

> **Single source of truth for everything not yet done.** Entries are organized
> into the five dependency-oriented sections fixed by
> [CS-PLAN-058 §2.2](https://github.com/ChoreStrike/ChoreStrike/blob/c03e940d071251ed62cedbe9ef4a7a2640d0f294/docs/plans/done/cs-plan-058-core-workflow-bottom-up-masterplan.md#22-backlog-as-the-execution-source-of-truth):
> **Core Workflow Program**, **Core Hardening**, **Deferred Add-ons**,
> **Infrastructure and Documentation**, and **Release**. Every open entry carries
> the standardized metadata block (see [`README.md`](README.md) for the format).
> **Done work does not live here** — completed items move to
> [`archive/YYYY-MM.md`](archive/) at completion. `npm run backlog:check`
> machine-verifies open-only entries, unique IDs, metadata, dependency
> references, and tracked links.
>
> Priority: **P0** critical · **P1** high · **P2** should-have · **P3** nice-to-have · **P4** future.

---

## Core Workflow Program

> The CR-WF umbrellas owned the core-journey acceptance criteria
> ([CS-PLAN-058](https://github.com/ChoreStrike/ChoreStrike/blob/c03e940d071251ed62cedbe9ef4a7a2640d0f294/docs/plans/done/cs-plan-058-core-workflow-bottom-up-masterplan.md);
> review record: [`archive/CODE_REVIEW_2026-09-02.md`](archive/CODE_REVIEW_2026-09-02.md)).
> **Program closure 2026-09-14:** CR-WF-01..08 and every plan-owned item are
> archived; the final Full review (`PASS-WITH-FIXES`, D-FR-03 disposition)
> folded the remaining service-level pointers into their concrete owners
> (CR-053-11, CR-053-19) and re-sectioned the non-blocking remainders. This
> section is intentionally empty — no open item blocks or extends the core
> program.

*(No open items — the program's acceptance lives in CS-PLAN-058 §8; follow-up
findings of the final review are routed to Core Hardening / Deferred Add-ons
below.)*

---

## Core Hardening

> Standalone correctness, API, persistence, i18n, and security remainders. Each
> entry states which work package it blocks (CS-PLAN-058 §2.2).

### [P2] FEAT-UX-040 — One task form for new and edit: recurrence, layout and controls
**Status**: In Progress (CS-PLAN-091 WP-B6, implemented 2026-11-19, the operator's GUI review 2026-11-21, interim Full review `PASS-WITH-FIXES` 2026-11-21); before: Backlog (Round 2, D-089-01; alpha.2 trial feedback 2026-10-24/26; needs operator feedback on design variants before any implementation) · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: task creation
**Owning**: CS-PLAN-091 WP-B6 · **Depends on**: CS-PLAN-080 · **Blocks**: FEAT-UX-050 · **Folded-in**: same mask for New Task and Edit Task with assignees and room (operator, 2026-10-26, CS-PLAN-088 feedback register #11); sub-tasks, assigned members, room picker and mode naming (operator, 2026-11-03, CS-PLAN-089 feedback register); first date, read-only next window, sleeping and bonus switches (operator, 2026-11-09, CONCEPT-002)
**Source**: Operator feedback on the New Task dialog (2026-10-24), CS-PLAN-088 feedback register #4

In create mode the form opens with "Start from Template" and a "Repeat" preset
selector (`RecurrencePresetSelector`), while the real recurrence inputs
(Frequency, Every, Scheduling Mode, Flex Days) sit further down under "Schedule &
Recurrence"; the preview repeats "Repeat". Two places for one setting confuse,
and the size and grouping of the controls (six sections in one long scroll) need
a second look.

- [x] Present two or three variants to the operator (for example presets as
      quick-fill inside the Schedule section; room and assignees first; advanced
      fields collapsed) and record the choice as a decision before coding (the
      simplified-core mockups; D-091-85 to D-091-88, 2026-11-18).
- [x] Implement the chosen layout once for both entry points (*New Task* and *New*
      in a room heading, both in `TaskCreateDialog` since CS-PLAN-090 WP-C), keeping
      the `data-testid` hooks the Playwright specs use (`TaskForm`, 2026-11-19; the
      specs follow the form's hooks).
- [x] Tests: Jest form specs and the affected browser scenarios updated.

**Folded in 2026-10-26 (operator):** New Task and Edit Task should share one
mask, with assignees and room in both. Today there are three: `TaskEditForm` in
create mode (room and assignees), `TaskEditForm` in edit mode in the Tasks tab
(definition only) and `RoomTaskEditModal` in the room view (due date and
assignees).

- [x] One mask for create and edit in both entry points; edit mode includes the
      assignees (existing per-room-task assignee command) and the room (one
      `PUT /room-tasks/{id}` carries both).
- [x] Changing the room of an existing task needs a backend move command
      (house-scoped, under the room-task lock). Decide what moves along (open
      occurrence, assignees); every completion and journal entry stays with the
      room it happened in. PHPUnit for the move and its history (the move inside
      the form's save: completions, closed cycles and assignees belong to the room
      task and move with it, the room journal keeps every entry in its room;
      `RoomTaskEditTest`).
- [x] The edit mask states when the definition is shared by several rooms
      (see FEAT-UX-043).

**Folded in 2026-11-04 (CS-PLAN-089 register G-1, G-2, G-3, T-4):** *Add* also
offers *From template* (FEAT-UX-043). When editing, owners can also edit past
occurrences (FEAT-UX-052); when creating, the creator sets only *Last done on*.
The room is picked with a button, first the floor, then the room. Sub-tasks and
assigned members change only here, no longer in the task details. The family
trial closes first (D-089-06).

- [x] Sub-tasks and assigned members are edited in the form only.
- [x] One saved form is one atomic write, so a refusal never leaves it partly
      applied (the partial save of BUG-UX-011).
- [x] The mode names read clearly (*After completion*, *Fixed day*, *Once*). Flex days already work in both modes (T-4
      needs no new behavior), but a mode called "Flexible" suggests otherwise.

**Folded in 2026-11-09 (operator, CONCEPT-002):** the form shows the next window
read-only, derived from *Last done* (or the first date of a new task, which stays
editable) and the rhythm. A fixed day offers a weekday or a day of the month. It
gains the switches for sleeping and bonus tasks. Adults and the owner edit;
children do not. The mockups under family review are the variants step of this
item. Detail: [simplified core](../concepts/simplified-core.md#due-windows).

Sequencing: land it after BUG-UX-001, FEAT-UX-038 and FEAT-UX-039 so the new
layout is not built twice; show its mockup together with the template picker
of FEAT-UX-043.

---

### [P2] FEAT-UX-041 — Allow completing work before its window opens
**Status**: In Progress (CS-PLAN-091 WP-B2, implemented 2026-11-18, interim Full review `PASS-WITH-FIXES` 2026-11-18); before: Backlog (alpha.2 trial feedback 2026-10-26; needs a product decision and a timeline-contract update) · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: completion
**Owning**: CS-PLAN-091 WP-B2 · **Depends on**: — · **Blocks**: — · **Folded-in**: operator decision of 2026-11-09 (CONCEPT-002)
**Source**: Operator feedback (2026-10-26), CS-PLAN-088 feedback register #6

Work is completable only inside its occurrence window. Before the window opens
the row has no completion target (`OccurrenceSelectionService::selectFromAgreed()`),
and the Tasks tab then answers Done with the generic "The task changed since it
was displayed". The operator wants early completion by default: completing
before the due date, even before the flex days, fulfills the next occurrence,
and an interval task's next due date moves earlier accordingly. A restriction
should at most be an owner option, or apply to children only.

The approved timeline rules already cover part of it: early completion moves a
completion-relative interval but not a fixed cadence, and early work shows as
done at once while its streak step waits for the planned day
([task timeline C1](../concepts/task-model-and-sync.md#approved-timeline-contract-c1-2026-09-17),
[rewards](../concepts/gamification-and-rewards.md)). What is missing is a
completion target before the window.

- [x] Decide: early completion on by default; restriction by an owner house
      setting, by role (children not early), or both; only the next occurrence;
      for a fixed cadence the occurrence counts as done early and the cadence
      stays. **Decided (operator, 2026-11-09, CONCEPT-002):** always possible for
      everyone, without an owner option or a child restriction, except on the
      same local day as the previous completion of that task
      ([simplified core](../concepts/simplified-core.md#due-windows)).
- [x] Decide the classification and rewards of early work (on time or its own
      class; energy and coins unchanged; streak step on the planned day per C1;
      day-capacity accounting). **Decided (CS-PLAN-091 D-091-02):** classified at
      completion and frozen; early work is on time.
- [x] Update [ADR-013](../adr/ADR-013-occurrence-agreements-and-corrections.md),
      the concepts and `docs/api/` before code. Done as ADR-021, which supersedes
      it (WP-0).
- [x] Tests: PHPUnit for early completion on interval and fixed cadence, the
      same-day refusal and the cycle target (`CycleTimelineTest`,
      `CompletionServiceTest`); a browser scenario (`family-rhythm`). No owner or
      child rule is left to test.

BUG-UX-005 covers the in-window case and ships on its own.

---

### [P1] FEAT-UX-068 — Derived due windows replace stored appointments
**Status**: In Progress (CS-PLAN-091 WP-B1, interim Full review `PASS-WITH-FIXES` 2026-11-18) · **Effort**: XL · **Layer**: backend+frontend · **Journey stage**: daily execution
**Owning**: CS-PLAN-091 WP-B1 · **Depends on**: — · **Blocks**: FEAT-UX-041, FEAT-UX-050, FEAT-UX-052, FEAT-UX-059 · **Folded-in**: overdue as one red entry per task, the catch-up entries go (CONCEPT-002, 2026-11-09)
**Source**: Operator decisions of 2026-11-09 and 2026-11-10 (CONCEPT-002)

What is due follows from the last completion (or a new task's first date), the rhythm and the
flex days; future appointments are no longer stored or negotiated. Modes: after completion,
fixed weekday, fixed day of month. One red entry per task until it is done once. Closed cycles
stay history; the recognitions classify work at completion (D-091-02), treat absences as
neutral (D-091-03) and keep what was earned before the update (D-091-04). Detail:
[simplified core](../concepts/simplified-core.md#due-windows).

- [x] ADR-021 superseding ADR-013 (except its pause part) before code.
- [x] Window core, closed-cycle history, the cutover and the new schema steps with
      populated-upgrade tests on all providers (interim Full review 2026-11-18; the cutover
      starts on the update day, D-091-41).
- [x] Tests per CS-PLAN-091 §4 (WP-B1), proof P4 on both runtime rows included.

---

### [P2] FEAT-UX-069 — Sleeping and bonus tasks
**Status**: In Progress (CS-PLAN-091 WP-B4, implemented 2026-11-18, interim Full review `PASS-WITH-FIXES` 2026-11-18) · **Effort**: M · **Layer**: backend+frontend · **Journey stage**: task setup
**Owning**: CS-PLAN-091 WP-B4 · **Depends on**: FEAT-UX-068 · **Blocks**: — · **Folded-in**: —
**Source**: Operator decision of 2026-11-09 (CONCEPT-002)

A task can sleep, optionally until a wake date (lawn mowing in winter): no window, never
overdue, no capacity. Bonus tasks are voluntary and always available: never due or suggested,
at most one completion per person, task and day, energy and coins paid, outside the weekly
goal, the rhythm and the ranking.

- [x] Sleep and wake per room task; bonus as a definition property; *Today* lists bonus tasks
      (implemented 2026-11-18, D-091-65 to D-091-69; interim Full review `PASS-WITH-FIXES`, D-091-81 to D-091-84. The
      sleep control and the bonus switch moved into the one form with WP-B6, 2026-11-19).
- [x] Tests per CS-PLAN-091 §4 (WP-B4): `SleepAndBonusTest`, `WindowServiceTest`, the
      controller and store specs, and the browser scenario of a bonus day on *Today*.

---

### [P2] FEAT-UX-070 — Coin factor per member and bonus days
**Status**: In Progress (CS-PLAN-091 WP-B5, implemented 2026-11-18, interim Full review `PASS-WITH-FIXES` 2026-11-18) · **Effort**: M · **Layer**: backend+frontend · **Journey stage**: rewards
**Owning**: CS-PLAN-091 WP-B5 · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Operator decisions of 2026-11-09 and 2026-11-10 (CONCEPT-002)

The owner sets a coin factor per member (× 1, × 2 or × 3) and starts bonus days (one to three
days, × 1.5). Both apply to coins only, multiply with each other and are frozen with the
completion, so undo and attribution reverse exactly what was paid. Energy and every
recognition stay unchanged. Amends ADR-014.

- [x] ADR-014 amendment in ADR-021 before code (decision 11, WP-0).
- [x] Owner settings, frozen factors, *Today* shows a running bonus period (implemented
      2026-11-18 with the schema step `Version1037`, D-091-64 and D-091-70 to D-091-73; interim
      Full review `PASS-WITH-FIXES`, the erasure of a departed account fixed, D-091-78).
- [x] Tests per CS-PLAN-091 §4 (WP-B5): `CoinMultiplierTest`, `CoinMultiplierStepTest`,
      `EffortAllocationServiceTest`, the controller and settings specs, the provider matrix
      and proof P4 on both runtime rows.

---

### [P3] CR-SVC-65 — Split `RoomTaskReadService` below the file-size guideline
**Status**: Backlog · **Effort**: M · **Layer**: backend · **Journey stage**: —
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: CS-PLAN-088 pre-candidate Full review (2026-10-28, D-088-25)

`lib/Service/RoomTaskReadService.php` has 993 lines (928 before `0.8.0-alpha.3`)
against the ~300-line guideline. The review fixed what alpha.3 added (the My Day
method is split, one selection derivation per read, one shared block builder);
the file itself stayed, because splitting the central read service moves code
that My Day, the room views and the completion preview share, which the frozen
candidate did not take on. Cohesive parts to extract: the participation
proposal derivation (`proposalBlocksFor` and its helpers, about 120 lines), the
My Day rows (filtered daily, child rows, open-window rows, checklist counts)
and the payload/time-zone helpers.

- [ ] The read service and each extracted class stay within ~300 lines, with no
      change of any response shape.
- [ ] Unit and integration suites green unchanged, the query-budget tests
      included; a full browser gate on both rows.

---

### [P2] BUG-UX-014 — A changed energy does not reach open tasks
**Status**: Merged, not released (Full review `PASS-WITH-FIXES` 2026-11-05, on local `master`; no release of its own, it ships with the next planned release, operator 2026-11-05) · **Effort**: S · **Layer**: backend, frontend · **Journey stage**: task setup, daily execution
**Owning**: — · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Operator report of 2026-11-05 on the deployed `0.8.0-alpha.4`

A saved energy changed the definition and the next schedule revision only. Open occurrences kept
their value, so the task dialog showed the new one while the row and the payout used the old
one: until completion on an interval task, for up to eight more dates on a daily fixed rule, and
forever on a one-time task. `0.8.0-alpha.4` made this visible, because rows used to show the
definition's value (FEAT-UX-047). The operator decided that the edit applies at once to all open
work, and that open work already diverged is aligned once after the update. The job pass does
that instead of a migration: it is the first step that would have run since the retained
baseline and it needs no schema change. Fairness counted the live definition, the whole value
for the completer and undone work. The Full review of 2026-11-05 found that a one-time task
edited before completion stayed active and came back with the next job pass, which pre-dates this
item and is fixed with it.

- [x] An edit applies to all open occurrences of the definition, with version bump and `energy` evidence.
- [x] Undo, restart and the timeline job keep open work at the current energy.
- [x] Definition evidence no longer pins a derived successor on undo (`window` had the same defect); the evidence stays.
- [x] A one-time task edited before completion stays done; a finished room task gets no new work from an edit.
- [x] The restart gate charges the energy the row will carry.
- [x] A completion conflict rereads the rows, so the next attempt works without a page reload.
- [x] Task list and My Day reread their rows after a saved edit.
- [x] Fairness adds up the frozen shares of valid work.
- [x] ADR-013, concepts, API and user guide updated.
- [x] Reviewed and merged ([review](archive/CODE_REVIEW_2026-11-05.md#full---bug-ux-014-energy-edits-and-open-work)).
- [x] ~~The read-only count before and after that deployment.~~ Superseded (CS-PLAN-091
      RF-091-15, D-091-162): the deployment that carries the fix is `0.8.0-alpha.5`, whose
      derived windows read the definition's energy for open work; no job pass aligns stored
      occurrences any more, so the count would never reach 0. The production smoke checks a
      task whose energy was changed before the update instead
      ([deployment.md](../engineering/deployment.md#updates-on-the-retained-baseline)).
- [ ] Released with `0.8.0-alpha.5` and verified on the deployed version: the production smoke
      shows a task whose energy was changed before the update with the definition's energy
      (CS-PLAN-091 WP-G handoff, D-091-162).

---

### [P3] BUG-UX-015 — An energy or flex edit drops queued agreement digest lines
**Status**: In Progress (CS-PLAN-091 WP-B7: the agreement queue retired 2026-11-21, interim Full review `PASS-WITH-FIXES` 2026-11-21; ships with `0.8.0-alpha.5`); before: Open · **Effort**: S · **Layer**: backend · **Journey stage**: notifications
**Owning**: CS-PLAN-091 WP-B7 · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of BUG-UX-014, 2026-11-05

`AgreementService` queues an `agreement` digest line with the occurrence version as its revision,
and `DigestService::orgSourceIsRelevant()` requires that version at send time. An edit that only
records definition evidence bumps the version too, so a queued line about a changed date or
participant is dropped without a replacement. Flex widening has done this since D-088-17, and
BUG-UX-014 adds energy edits and the job pass. The digest is a daily summary and the change
itself stays visible in the app.

- [x] A queued agreement line survives an evidence-only version bump, with a regression test.
      Closed with the agreement queue instead (D-091-12, WP-B7 2026-11-21): nothing queues or
      counts such a line any more, and a retained row is skipped and never consumed
      (`DigestServiceTest`, `DigestDeliveryTest`).

---

### [P3] BUG-UX-016 — A read that started before a creation can drop the new task from a list
**Status**: Backlog · **Effort**: S · **Layer**: frontend · **Journey stage**: task management
**Owning**: — · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Browser gate `run-v47nfT` while implementing CS-PLAN-090 WP-B, 2026-11-11

`createTaskAggregate` and the template creation add the new room task and its definition to
the stores, but a room-task or definition read that was already in flight keeps its
generation and replaces the list when it lands, without the new row. The gate showed it once
on a cold server: a task created right after a room page opened was missing from the room's
list. That page offered no creation while it loaded from then on, and went with
CS-PLAN-090 WP-C; the task list never offered creation before its first read. So no surface
triggers it today. Checked again against the one form of CS-PLAN-091 WP-B6 (2026-11-22,
D-091-163): *New Task* stays disabled while the task list loads, a room's add button exists
only on loaded groups and *Today* offers no creation; what remains is a refresh read started
by an earlier write that lands after a creation. The store rule itself, that an accepted mutation supersedes
earlier reads of its slice, is not applied to creations, and a superseded initial read must be
repeated, not only dropped.

- [ ] A creation supersedes in-flight room-task and definition reads and the list is read again.
- [ ] A store test lands a read that started before the creation after it.

---

### [P3] BUG-API-002 — Member routes refuse Nextcloud user IDs with a space or an apostrophe
**Status**: Backlog · **Effort**: S · **Layer**: backend, frontend · **Journey stage**: household setup
**Owning**: — · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of CS-PLAN-090 (final Full review, 2026-11-15; rescoped by the operator the same day)

Two routes take a member's Nextcloud user ID in the path and restrict it to
`[a-zA-Z0-9_.@-]+`: `PUT /scheduling/members/{userId}` (older) and
`POST /house/members/{userId}/arcade-reset` (CS-PLAN-090 WP-J, which copied the pattern).
Nextcloud also allows spaces and apostrophes in user IDs, so an owner can neither edit the
planning of such a member nor reset their arcade progress; the router answers 404 before any
check. Authorization is not affected, because the services resolve the member in the
caller's house.

- [ ] Both routes accept the character set Nextcloud allows for user IDs, and the client encodes the ID.
- [ ] Route and integration tests with an ID that holds a space and an apostrophe.

---

### [P3] BUG-I18N-002 — Names in translated strings show HTML entities
**Status**: Backlog · **Effort**: M · **Layer**: frontend · **Journey stage**: —
**Owning**: — · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of CS-PLAN-090 (final Full review, 2026-11-15)

`translate()` of `@nextcloud/l10n` escapes placeholder values as HTML by default. Vue then
renders the result as text, so a name such as "Bad & WC" or "Mom's room" in a string like
"Move {category} up" reads `Bad &amp; WC` or `Mom&#39;s room`, in visible text and in
accessible names. It affects every string that carries a member, room, category or reward name
and is older than CS-PLAN-090; `errorToast.js` already passes `escape: false` for its one
case. Vue's own escaping keeps the text safe, so the fix is a rule for text bindings, not a
security change.

- [ ] Strings rendered as Vue text pass their placeholders unescaped through one shared rule.
- [ ] Jest cases with `&`, `'` and `<` in a name; no `v-html` is introduced.

---

### [P3] CR-SVC-66 — Pause coverage regroups the whole pause history per occurrence
**Status**: Backlog · **Effort**: S · **Layer**: backend · **Journey stage**: —
**Owning**: — · **Depends on**: — · **Blocks**: — · **Folded-in**: the same cost per window day of the recognitions (review of CS-PLAN-091 WP-B1, 2026-11-18)
**Source**: Review of CS-PLAN-090 (final Full review, 2026-11-15)

`PauseProjectionService::stampSuspension()` called `excusedWalletsOn()` once per occurrence,
and each call runs `governingRevisionsOn()` over the house's whole pause history: it groups
and sorts the revisions again and computes each boundary key with new time-zone objects.
A selection read therefore costs occurrences × lifetime revisions. **Folded-in (review of
CS-PLAN-091 WP-B1, 2026-11-18):** on `feat/simplified-core-bc` the stamping went with the
occurrences, and `walletsAbsentThroughout()` runs the same regrouping once per window day of
every recognition fact. Since ADR-020 every
deleted absence adds a `cancelled` revision that keeps governing, so the history only grows.
Older than CS-PLAN-090; the household has a small history today.

- [ ] The revisions are grouped and their boundary keys computed once per read, then shared by every date.
- [ ] Projection results unchanged; a query/CPU budget test with a long history.

---

### [P3] BUG-SEC-003 — Erasing an already-departed account leaves its links in stored appointments
**Status**: In Progress (CS-PLAN-091, D-091-158: fixed on `feat/simplified-core-bc` 2026-11-22, D-091-159; ships with `0.8.0-alpha.5`); before: Backlog · **Effort**: S · **Layer**: backend · **Journey stage**: —
**Owning**: CS-PLAN-091 (round before the candidate) · **Depends on**: — · **Blocks**: — · **Folded-in**: the departed account's personal absences, which the sweep kept too (2026-11-22)
**Source**: Review of CS-PLAN-091 WP-0 and WP-B1 (interim Full review, 2026-11-18)

`AccountErasureService::eraseAccount()` removes an erased member's wallet links from stored
appointments (`occMemberMapper->deleteByWalletId()`, the anonymized rule-default and agreement
snapshots) only in its membership loop. The final sweep for an account that had already left
every house deletes the wallet but leaves those rows naming the deleted wallet id. Older than
CS-PLAN-091; the review fixed the same gap for the new cycle tables and the sub-task ticks.

- [x] The orphan-wallet sweep removes or anonymizes the wallet in `occ_members`, the
  rule-default and the agreement snapshots, as the membership loop does: both paths share
  one wallet step, which also removes the personal absences (D-091-159).
- [x] `AccountErasureTest` covers a departed account with stored appointments, and
  `AccountErasureServiceTest` pins that both paths remove the same wallet links. The
  interim review extended the erasure in this plan: plain user-id consent subjects, the
  creator of definitions, takeover names and the assigner (CS-PLAN-091 D-091-164 to D-091-166).

---

### [P3] BUG-UX-017 — The organization digest is called weekly but arrives with the daily summary
**Status**: In Progress (CS-PLAN-091, D-091-158: fixed on `feat/simplified-core-bc` 2026-11-22, D-091-161; ships with `0.8.0-alpha.5`, the screenshot recaptured with the WP-G candidate 2026-11-23); before: Backlog · **Effort**: S · **Layer**: frontend · **Journey stage**: notifications
**Owning**: CS-PLAN-091 (round before the candidate) · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: CS-PLAN-091 WP-B7 implementation, 2026-11-21

The notification settings name the second switch *Weekly organization digest*, and the user
guide repeats the name. `DigestService` has no weekly pass: household updates (work recorded
for someone's tasks, absences, reward requests) are queued for the member's next daily slot
and counted in the one summary of that day. WP-B7 reworded the hint below the switch, which
had promised *a weekly overview of catch-ups and absences*; the label, the user guide and
the screenshot text keep the old name. Older than CS-PLAN-091.

- [x] The switch, its German copy, the user guide and the screenshot alt text name what is
  delivered, or a weekly delivery is decided and built: *Daily household updates* /
  *Tägliche Haushaltsupdates*, counted in the one daily summary (D-091-161).
- [x] The guide's screenshot shows the new label (recaptured with the WP-G candidate, 2026-11-23).

---

### [P3] CR-SVC-67 — Methods without a caller from before CS-PLAN-091
**Status**: Backlog · **Effort**: S · **Layer**: backend+frontend · **Journey stage**: —
**Owning**: — · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: CS-PLAN-091 WP-B7 implementation, 2026-11-21

WP-B7 removed the code that lost its last caller in CS-PLAN-091 (RF-091-06). A scan by name
found methods that had no production caller on `master` before the plan either; several are
still called by their own tests. They stayed, because WP-B7 owns only what the plan orphaned
(D-091-113):

- `CompletedTaskMapper`: `getUserStatsByHouse`, `countByRoomTaskAndUserOnDate`, `countByUserOnDate`,
  `countByUserOnDateGrouped`, `getMaxRoomCompletionCountByHouse`,
  `findCompletedRoomTaskIdsBetweenByHouse`, `isValidCompletion`
- `RoomTaskMapper`: `deactivate`, `findDueOnDate`, `findActiveDueBetweenByHouse`,
  `findActiveDueIdsBetweenByHouse`; `RoomMapper::resetByHouseId`; `RoomEventMapper`:
  `findEventsByRoom`, `countByRoom`, `findByRoomIds`, `deleteAll`
- `TaskAssigneeMapper`: `isAssignee`, `removeAssignee`; `TaskChecklistItemMapper`:
  `deleteByTaskId`, `countByTaskId`; `HouseUserMapper`: `findByHouseIdAndNextcloudUserId`,
  `findAllForJobs`; `MemberPreferenceMapper::findAllByHouseUserIds`; `PauseMapper::deleteByHouseId`;
  `RewardRequestMapper::sumUnfulfilledPriceByWalletId`; `WorkShareMapper`: `sumRevision`,
  `deleteAll`; `CompletionRevisionMapper`: `maxRevisionOf`, `deleteAll`
- entities: `Room::getCleanlinessLabel`, `Task` (`getEstimatedTimeRange`, `getPriorityLabel`,
  `getFrequencyLabel`), `HouseUser` (`addContributionMinor`, `resetStreak`),
  `MemberPreference::clearRankingConsent`, `RewardRequest::isTerminal`
- services: `AuthorizationService::isHouseOwner`, `HouseService::hasFloors`,
  `RoomService::getRoomTask`, `TaskService::getMyPrivateTasks`, `RewardConfigService`
  (`getAllConfig`, `updateConfig`, `setTitlesEnabled`), `TitleCatalog::isValidPack`
- frontend: the store members `dismissCelebration` and `clearDismissedCelebrations`
  (`game.js`), `clearDetail` (`rewards.js`), `activeTasks` (`tasks.js`), `isChild`
  (`user.js`), and the exports `COIN_MINOR_PER_ENERGY` (`minorUnits.js`), `setMuted`,
  `isMuted`, `isSoundEnabled` and `preloadSounds` (`sound.js`)

The scan matches names, so each entry needs its callers checked by type before it goes.

- [ ] Each listed method is removed with its tests, or its caller is named.

---

### [P3] BUG-UX-018 — Two rare corrections of a fixed rhythm lose a cycle or its postponement
**Status**: Backlog · **Effort**: M · **Layer**: backend · **Journey stage**: completion
**Owning**: — · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of CS-PLAN-091 (final Full review, 2026-11-23); user-rescoped by the operator, D-091-175

Two corrections of a task with fixed days are not repaired; ADR-021 names them as known
limits:

- When two cycles were both done early and the older entry is deleted, its cycle never
  becomes current again — the current cycle follows the later valid work — and no pass
  records it as missed, so it drops out of the streak and the weekly goal without a trace.
- A room task stores one postponement for one cycle key. When a cycle was postponed and
  done and the next cycle is then postponed, undoing the first entry reopens its cycle
  without its postponement and its count of presses (D-091-62 assumes the stored
  postponement is still the reopened cycle's).

Both need two corrections in a row on one fixed task. The final review fixed the related
case where a second undo made a cycle current again that an earlier undo had recorded as
missed (D-091-174).

- [ ] A cycle that an undo leaves behind a later valid one is recorded as missed once its
  window ends, or becomes current again; tested on PostgreSQL, MySQL and SQLite.
- [ ] A reopened cycle takes its postponement and its count from the undone completion's
  frozen window, or D-091-62 is narrowed to the case it holds for; tested.

---

### [P3] BUG-SEC-004 — Command receipts keep a hash derived from an erased member's user ID
**Status**: Backlog · **Effort**: M · **Layer**: backend · **Journey stage**: —
**Owning**: — · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of CS-PLAN-091 (final Full review, 2026-11-23); public as a sanitized entry by the operator's decision, D-091-176

A command receipt stores a SHA-256 of its payload, so that a retry replays it. Several
payloads name members by their Nextcloud user ID — the work split of a completion since
CS-PLAN-074 WP3, the coin factor and the form's assignees since CS-PLAN-091 — and the hash
is not keyed. Erasure removes the receipt's actor but keeps the hash, so someone with
database access who guesses the user ID can still tie the receipt to the erased person.
Data minimisation, not a remote exposure.

- [ ] Receipt payload identities name members by their internal ids, or the hash is keyed
  with an instance secret; receipts that already exist keep replaying.
- [ ] An erasure test shows that no receipt of the house is derived from the erased user ID.

---

### [P3] CR-SVC-68 — Reads repeated on the paths of Today, the lists and the cycle commands
**Status**: Backlog · **Effort**: M · **Layer**: backend+frontend · **Journey stage**: daily execution
**Owning**: — · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of CS-PLAN-091 (final Full review, 2026-11-23); user-rescoped by the operator, D-091-176

The release stays within its request budgets, but some reads repeat:

- A *Today* that was left still drives its reads: `forgetDailyLoad()` clears only the
  load, so a row refresh from another view rereads `GET /room-tasks/filtered-daily` (the
  whole suggestion) and the bonus rows, and the next completion in the task list reads the
  load again for a page nobody shows.
- The overdue, bonus and calendar range reads read the assignees of the house twice and
  the house row again although the caller holds it (`RoomTaskReadService::getBonusTaskRows()`,
  `withCycleBlocks()` without a zone, `RoomTaskController::serializeExecutionRows()`, the
  range read of `RoomTaskService`): about two statements per request that `TodayRows`
  already avoids (D-091-148).
- The completion, the undo, the sleep command and `RoomTaskCommand::run()` read the house
  row again under the lock that holds it: one to three primary-key reads per command.

- [ ] A left *Today* drops its rows and bonus rows, so no refresh reads them.
- [ ] The read paths reuse the assignee map and the house they hold, the commands pass the
  house; `WorkflowQueryBudgetTest` and `CycleCommandQueryBudgetTest` are tightened.

---

### [P3] CR-SVC-69 — Erasure rewrites the frozen coin factors row by row under the house lock
**Status**: Backlog · **Effort**: S · **Layer**: backend · **Journey stage**: —
**Owning**: — · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of CS-PLAN-091 (final Full review, 2026-11-23); user-rescoped by the operator, D-091-176

`CompletedTaskMapper::forgetCoinFactorWallet()` reads every completion of the house in
pages of 500 and updates each one whose frozen coin factors name the erased wallet. Since
the release every completion freezes the factor of every member with a wallet, so that is
one update per completion; `CycleMapper::forgetWallet()` reads every closed cycle the same
way. Both run in the synchronous erasure transaction under the house lock, which holds
the household's writes meanwhile, and the work grows by thousands of rows a year.

- [ ] Rows with the same resulting map are updated together, and candidates are filtered
  before they are read; the same for the cycles' responsible members.
- [ ] An erasure test with a long history pins the statement count.

---

### [P3] CR-SVC-70 — Three methods above the method-size guideline
**Status**: Backlog · **Effort**: S · **Layer**: backend+frontend · **Journey stage**: —
**Owning**: — · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of CS-PLAN-091 (final Full review, 2026-11-23); user-rescoped by the operator, D-091-176

`RoomTaskEditService::aggregate()` (about 40 lines), `CompletionDateCommand::changeDate()`
(about 45) and the store action `_submitCompletion()` of `src/store/roomTasks.js` (about
100, grown with the cycle key and the bonus credit) exceed the ~30-line guideline; the size
inventory names files only.

- [ ] The window-change selection leaves `aggregate()`, the guard and the answer leave
  `changeDate()`, the success reconciliation and the events leave `_submitCompletion()`;
  behaviour and budgets unchanged.

---

## Deferred Add-ons

> Explicitly outside the core critical path (CS-PLAN-058 §1.3). These cannot
> enter the critical path without a plan update.

The section reflects CS-PLAN-058's historical grouping. The CS-PLAN-070 draft
now explicitly selects notification settings and dated pauses for its new core,
followed by the optional family catalog; those choices do not activate code work.

### [P3] FEAT-UX-015 — Leave house (self-service membership exit)
**Status**: Open (semantics decided, endpoint/UI open; re-sectioned from the Core Workflow Program section 2026-09-14, D-FR-03; the owner-removal fold-in was delivered by CS-PLAN-088 WP-A, D-088-19, merged 2026-10-28; self-service departure stays unassigned) · **Effort**: M · **Layer**: backend+frontend · **Journey stage**: authorization
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: removing another owner (operator feedback 2026-10-26, CS-PLAN-088 feedback register #7)
**Source**: UX-003 remainder (CS-PLAN-038); owner-transfer/last-owner semantics decided by CS-PLAN-061 D6 (ADR-010, user-ratified 2026-09-03)

Members cannot leave a house on their own; removal is owner-only. The
owner-transfer semantics are decided (D6): the **last owner cannot leave** —
ownership must be transferred first (consistent with `CANNOT_REMOVE_OWNER` /
`CANNOT_DEMOTE_LAST_OWNER`; an ownerless house would allow stranger
re-bootstrap). Remaining: the backend leave endpoint + Settings action.

- [x] Decide owner-transfer/last-owner semantics for leaving. (D6, ADR-010, 2026-09-03)
- [ ] Backend leave endpoint (+ guards) + Settings Leave button + tests.

**Folded in 2026-10-26 (operator):** the account that bootstrapped the house
(the first to open the app, often the Nextcloud admin) is an owner, and
`removeHouseMember()` refuses every owner target (`CANNOT_REMOVE_OWNER`). It
therefore stays in member pickers, filters and rankings. Workaround today:
another owner first changes that account's role to member, then removes it.

- [x] An owner can remove another owner directly while at least one owner
      remains (D6 last-owner invariant unchanged, re-checked under the house
      lock), with the same departure cleanup as a member removal; PHPUnit for the
      last-owner race and the cleanup, Settings spec for the enabled action.
      *Implemented 2026-10-28 on `feat/alpha3-feedback-2` (CS-PLAN-088
      D-088-19): only self-removal is refused; `HouseServiceTest`,
      `HouseMemberRemovalIntegrationTest` (cleanup, serialized mutual removal)
      and the `MemberList` spec; browser gates green; merged into the local
      `master` 2026-10-28.*

---

### [P3] FEAT-UX-018 — Task-approval workflow for children
**Status**: Backlog · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: task approval
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: CONCEPT-001
**Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

design.md §5 lists "task approval workflow for children"; gamification open
decisions touch it too. Today every completion is final immediately.

**2026-09-17 decision (CS-PLAN-070 D8/D11):** routine chore completion remains
immediate; child reward redemption has separate owner approval. A general chore
approval queue is explicitly outside that delivery slice, not a prerequisite for
the new rhythm model. This item remains a future option requiring a new decision.

- [x] First-slice decision: keep immediate completion; defer general chore approval.
- [ ] Revisit optional quality review only after a separate product decision.
- [ ] If selected, backend: needs-review state + owner approve/reject endpoints with
      membership/owner gating + tests.
- [ ] Owner approval queue UI + child-facing "waiting for approval" feedback.

---

### [P4] FEAT-UX-019 — Task Pass & support actions (gag mechanic)
**Status**: Backlog · **Effort**: M · **Layer**: backend+frontend · **Journey stage**: gamification
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: CONCEPT-001
**Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

gamification-and-rewards.md sketches a post-MVP "Task Pass" gag mechanic;
explicitly future / needs decision.

CS-PLAN-070 D11 explicitly defers this feature outside the first delivery slice
(2026-09-17). Transferring chores without agreement would conflict with the
approved responsibility model; a future product decision is still required.

- [ ] Product decision whether the gag mechanic fits the target audience.
- [ ] Only after decision: concept extension + endpoint design sketch.

---

### [P3] FEAT-UX-023 — Onboarding & help layer
**Status**: In Progress - WP6 essential help slice DELIVERED 2026-09-30; WP8 essential onboarding/catalog/contextual-help slice DELIVERED 2026-10-02 via CS-PLAN-079 (five contextual topics on the shared named Modal plus reward glossary terms); wider tour scope remains open · **Effort**: M · **Layer**: frontend · **Journey stage**: onboarding
**Owning**: CS-PLAN-077 WP6 (delivered); CS-PLAN-070 WP8 (essential slice); wider guided tour/help unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: CONCEPT-001
**Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

**WP6 slice delivered (2026-09-30):** [CS-PLAN-077](../plans/done/cs-plan-077-wp6-calm-core-ui.md)
delivered the essential slice as ONE navigation-level entry
(`HelpEntry.vue` + `HelpGlossary.vue`, always reachable from the nav,
modal glossary) instead of per-view entry points — one always-visible
entry beat three scattered ones (scope consolidation recorded in the
plan's Review Findings). WP8 continues the parent's essential account/role,
share/rhythm/pause and catalog/contextual-help slice; whole-item closure still
requires any broader guided tour/help scope to be completed or explicitly scoped.

The navigation glossary is delivered. Contextual hints and onboarding/catalog
guidance remain; a wider guided tour is not implied by the essential WP8 slice.

Family-rhythm clarification (CS-PLAN-070 D10): explain existing Nextcloud
accounts for children, wizard member invites versus child-role setup, shared
device sign-in and optional work versus personal commitments. No local child
profile or guardian-consent model should be implied by onboarding copy.

- [x] Help entry point reachable from the navigation (WP6 delivered one
      nav-level entry; per-view contextual entry points remain WP8).
- [x] Glossary unifying the core household vocabulary (Agreement,
      Catch-up, Pause, Streak, Quiet time, Ranking, Arcade, Coins). Since
      CS-PLAN-091 WP-B7 *Due window* and *Overdue* stand for *Agreement* and
      *Catch-up*, and the topic *Agreements and shares* is *Work shares*.
- [x] Contextual hints for the selected model (accounts/roles, agreements/shares,
      progress/windows, catalog gates, owner pricing) as manually triggered
      context topics — delivered with the WP8 slice 2026-10-02; do not teach
      removed combo/freeze mechanics as target behavior.
- [x] Detail the essential WP8 slice and its tests in [CS-PLAN-079](../plans/done/cs-plan-079-wp8-reward-ui-and-onboarding.md) (2026-10-02;
      documentation only, no activation or runtime implementation).
- [ ] Complete wider guided tour/help scope separately before whole-item closure.

---

### [P4] FEAT-UX-027 — Print view (weekly plan / calendar)
**Status**: Backlog · **Effort**: S · **Layer**: frontend · **Journey stage**: print view
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

Future: paper-friendly weekly plan for fridge-door households.

- [ ] Print styles or dedicated print route reusing CalendarView range data.
- [ ] No new endpoints unless a gap is proven.

---

### [P3] FEAT-UX-028 — Global search
**Status**: Backlog · **Effort**: M · **Layer**: backend+frontend · **Journey stage**: search
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

Global search has no UI. **Status correction (2026-10-03):** the old
`GET /tasks/search` route and `searchTasks` wrapper were already pruned; do not
plan against an existing endpoint/wrapper. A future implementation must define
the actual search contract, not revive a dead API by assumption.

- [ ] Search entry point covering task definitions + room tasks (NC search
      integration or in-app field).
- [ ] Define the API or Nextcloud integration needed for the selected search scope.
- [ ] Tests.

---

### [P4] FEAT-UX-029 — Bulk maintenance ops
**Status**: Backlog · **Effort**: M · **Layer**: backend+frontend · **Journey stage**: bulk maintenance
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

Future: owner tools to reschedule/clear overdue tasks in bulk and seasonally
deactivate/reactivate by filter (cf. the CS-PLAN-042 bulk-endpoint pattern).

- [ ] Server-side bulk reschedule endpoint + owner action on the overdue list.
- [ ] Seasonal deactivate/reactivate by filter (room/category/idle).
- [ ] Styled confirmations per cross-cutting conventions + tests.

---

### [P2] FEAT-UX-043 — Templates: copy instead of shared definitions, and a simple catalog picker
**Status**: In Progress (CS-PLAN-091 WP-B6, form part only, implemented 2026-11-19, interim Full review `PASS-WITH-FIXES` 2026-11-21); before: Backlog (Round 2, D-089-01; alpha.2 trial feedback 2026-10-26; operator feedback on a mockup before implementation) · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: task creation
**Owning**: CS-PLAN-091 WP-B6, form part only · **Depends on**: CS-PLAN-080 · **Blocks**: — · **Folded-in**: *From template* in the one form (operator, 2026-11-03, CS-PLAN-089 feedback register)
**Source**: Operator feedback (2026-10-26), CS-PLAN-088 feedback register #12

The setup wizard creates **one** task definition per catalog template and mounts
it in every room of that type (`HouseSetupService` reuses
`$createdTasks[$template['id']]`). Two bedrooms therefore share one definition:
a rename in one room renames both, a rule change revises every mounted room
task, while already agreed dates and the per-room assignees stay as they were,
hence "the title changes everywhere, other details apparently not". The New
Task form's "Prefill from catalog" already copies. Operator direction: "use a
task as template": a template only fills the form, afterwards every room's task
is independent.

Picker sketch (operator): a "Choose from template" button; room type as a
dropdown and its tasks as a list; picking one fills the form; a small preview
with the start of the description, frequency etc. A Putzmuffel ↔ Putzteufel
switch: the catalog already has both levels, but only the setup wizard uses
them; Putzteufel means the same tasks plus more, with higher frequencies.
Special sets such as pets (cats, …) and garden (the `outdoor` type has three
templates today).

- [x] Decide the handling of existing shared definitions on the retained
      baseline. Recommended: no bulk rewrite; editing a definition mounted in
      several rooms asks "this room only / all rooms", and "this room only"
      splits off a copy for that room task while every completion, occurrence
      and journal row stays valid. New setups create one definition per room
      (as recommended; D-091-88, implemented in WP-B6 2026-11-19).
- [x] Picker per the sketch, shown as a mockup first (together with FEAT-UX-040)
      (*From template* in the one form: room type, its templates with rhythm and
      energy, a choice fills the form; WP-B6 2026-11-19).
- [ ] The Putzmuffel ↔ Putzteufel switch in the picker, together with the catalog
      content below (outside WP-B6's form part, D-091-96; the form fills the setup
      wizard's default level until then).
- [ ] Catalog content: Putzteufel a strict superset of Putzmuffel with equal or
      higher frequencies (enforced by a catalog test); new sets for pets and
      garden, plus proposals for the operator (e.g. plants, laundry, car/bike);
      all strings through `t()` with `l10n/`.
- [ ] Tests: catalog invariants, per-room definitions on setup, split-on-edit
      keeping history, picker specs.

---

### [P2] FEAT-UX-050 — Postpone, direct handover and no separate details view
**Status**: In Progress (CS-PLAN-091 WP-B3, postpone implemented 2026-11-18, interim Full review `PASS-WITH-FIXES` 2026-11-18; the details view went with WP-B6 2026-11-19, the cleanliness route with WP-B7 2026-11-21); before: Backlog (Round 2, D-089-01) · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: daily execution
**Owning**: CS-PLAN-091 WP-B3 · **Depends on**: CS-PLAN-080, FEAT-UX-040, FEAT-UX-051 · **Blocks**: — · **Folded-in**: operator decisions of 2026-11-09 (CONCEPT-002)
**Source**: Operator trial feedback on `0.8.0-alpha.3` (2026-11-03), CS-PLAN-089 register T-6, G-4, G-5, G-6

For tasks with flex days, *Postpone* moves the due date one day later per press, up
to the flex-day limit, and replaces *Move this occurrence to*. *Hand this occurrence
to* becomes a direct button. The separate details view may then go.

- **Writer:** the date writer re-centres the whole window
  (`OccurrenceRowFactory::applyWindow()`), so every press would also extend the
  deadline and the limit would never apply. Postpone needs its own path that moves
  only the planned day inside the saved window.
- **Target:** the shown occurrence by key, version and token, never the oldest open
  one (see BUG-UX-011). A missed occurrence cannot be postponed, but it no longer
  blocks a later occurrence, which the dialog of Round 1 still cannot move.
- **Rights (D-089-03):** assignees and adults postpone without the consent tick; the
  child day limit still applies. Handover stays owner-only with the consent tick.
- **Details view:** it can go once *Restart the linked interval work on* has a home,
  for example a conditional row button; the feedback did not list that action, and
  without it paused interval tasks have no restart.

**Folded in 2026-11-09 (operator, CONCEPT-002):** the postpone rule changes. Every
member may postpone, children included, which widens D-089-03: up to three times per
cycle, each press by half the flex days, rounded up. Due windows become derived state
and the catch-up entries go, so *Move this occurrence to* and the missed-occurrence
target disappear with them. Whether *Restart* and the single-cycle handover are still
needed is for the owning plan to settle. Detail:
[simplified core](../concepts/simplified-core.md#due-windows).

- [x] ADR-021 (superseding ADR-013), the task-model concept and the API reference describe
      postponement before code.
- [x] Postpone implemented; no single-cycle handover and no restart home (CS-PLAN-091
      D-091-10); PHPUnit for the window, the limit, the target and the rights; browser
      scenario (`PostponeAndHistoryTest`, `mobile-core`). Since WP-B6 the button sits in
      the one opened row on *Today* and in the task list (D-091-55).
- [x] The details view goes; checklist ticking moves to FEAT-UX-051 and editing to
      FEAT-UX-040 (WP-B6 2026-11-19: `TaskRowDetails` holds the facts, the sub-tasks,
      postpone, record for someone else, edit, sleep and the history).
- [x] The routes without a client go: `occurrence#catchUp` with the old writers (WP-B1)
      and `PUT /rooms/{id}/cleanliness` (WP-B7 2026-11-21, D-089-09). Journal replay and
      read paths stay (`RoomReplayConcurrencyTest`, `RouteMatrixTest`).

---

### [P2] FEAT-UX-051 — Completion dialog: record for someone else, sub-tasks on completion
**Status**: In Progress (CS-PLAN-091 WP-B2, implemented 2026-11-18, interim Full review `PASS-WITH-FIXES` 2026-11-18); before: Backlog (Round 2, D-089-01) · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: completion
**Owning**: CS-PLAN-091 WP-B2 · **Depends on**: CS-PLAN-080 · **Blocks**: FEAT-UX-050 · **Folded-in**: —
**Source**: Operator trial feedback on `0.8.0-alpha.3` (2026-11-03), CS-PLAN-089 register C-1, C-2

Hold-to-complete stays. A short tap opens a picker for who did the task, like for
shared tasks; with sub-tasks the same dialog picks the finished ones. Holding marks
all sub-tasks as done.

- **Operator decision:** every household member, children included, may record a
  completion for any other member. This reverses Q-074-02, which the server enforces
  (`The completer must take part in the work`). Coins, rhythm and arcade unlocks
  follow the work shares, so they need no special path.
- **Who recorded (source-project answer, 2026-11-03):** the history shows it when the
  recorder differs from the people credited. `completed_tasks.user_id` already keeps
  the audit actor, so no schema step is needed.
- **Partly ticked (D-089-02):** the task stays open and the ticks stay saved.

- [x] ADR-014, the concepts and the API reference state the new rule before code
      (ADR-021 decision 10, WP-0).
- [x] Marking all sub-tasks runs inside the completion command, so it stays atomic
      and undo stays correct.
- [x] The tap replaces today's hint in `HoldButton`, with keyboard parity (hold
      completes, a tap opens the picker, D-091-47).
- [x] PHPUnit for recording for others, private-task visibility and undo; Jest and a
      browser scenario for tap and hold (`RecordingForOthersTest`, `CompletionPicker`
      and `HoldButton` specs, `mobile-core`, `family-core-ux`).

---

### [P2] FEAT-UX-052 — Task history with owner corrections
**Status**: In Progress (CS-PLAN-091 WP-B3, implemented 2026-11-18, interim Full review `PASS-WITH-FIXES` 2026-11-18; the history moved into the one row with WP-B6 2026-11-19); before: Backlog (Round 2, D-089-01) · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: review and correction
**Owning**: CS-PLAN-091 WP-B3 · **Depends on**: CS-PLAN-080 · **Blocks**: — · **Folded-in**: —
**Source**: Operator trial feedback on `0.8.0-alpha.3` (2026-11-03), CS-PLAN-089 register T-5

Each task shows its next due date and all past occurrences with who completed them.
No new storage is needed: `occurrences`, `completed_tasks`, `work_shares` and
`completion_revs` hold everything. Owners can delete an entry, which is the existing
undo; assign it to someone else, which is the existing attribution correction; and
move it to another date, which is new.

- **Move date:** a frozen work fact changes (ADR-014), which touches the work date and
  zone, the rhythm classification, the weekly goal and the room journal. It needs its
  own audited correction kind.
- **Most recent entry:** editing it recalculates the next due date. Undo already does;
  a moved date re-anchors an interval's successor, and a fixed cadence stays.

**Folded in 2026-11-09 (operator, CONCEPT-002):** the rights widen. Every member may
change the date of, or delete, their own latest entry of a task; the owner may correct
every entry. Correcting the last date is also the way to move a task, because the next
window is no longer edited directly. Detail:
[simplified core](../concepts/simplified-core.md#people-and-rights).

- [x] ADR-014 and the concepts describe the date correction before code (ADR-021
      decision 9, WP-0).
- [x] History view per task, reachable from the one form (FEAT-UX-040). Implemented in
      the opened row of the task list (`GET /room-tasks/{id}/history`, D-091-54,
      D-091-55); since WP-B6 it sits in the one opened row, next to *Edit*.
- [x] Tests for all three corrections across coins, rhythm, weekly goal and room
      (`PostponeAndHistoryTest`, `CompletionCorrectionTest`,
      `CompletionUndoStateEqualityTest`).

---

### [P3] FEAT-UX-055 — Icons and colours for floors and rooms
**Status**: Backlog (Round 3, D-089-01) · **Effort**: M · **Layer**: backend+frontend · **Journey stage**: setup
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Operator trial feedback on `0.8.0-alpha.3` (2026-11-03), CS-PLAN-089 register S-9

Each floor and room gets an icon and a colour, with sensible defaults and a manual
choice: about 20–40 icons and 10–20 colours, perhaps a background per floor. Every
colour works in the light and the dark theme. Rooms already have an icon; floors have
neither, and rooms have no colour.

- [ ] New columns are the first schema step on the retained baseline: a new ordered
      migration, a populated-baseline upgrade proof and fresh/upgrade parity on all
      providers (project rules §1).
- [ ] Palette contrast checked in both themes.

---

### [P3] FEAT-UX-058 — Household statistics
**Status**: Backlog (Round 3, D-089-01) · **Effort**: M · **Layer**: frontend · **Journey stage**: review and correction
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: a chart of cleanliness over time (operator, 2026-11-21, GUI review of CS-PLAN-091 WP-B6)
**Source**: Operator trial feedback on `0.8.0-alpha.3` (2026-11-03), CS-PLAN-089 register T-5

Which tasks get done regularly and which do not, also by category (D-089-04). The operator
would like this area to grow. It compares tasks, not people, so ADR-015 does not apply, and
it reads the existing occurrence history.

- [ ] Insights shows the first evaluations; the definitions of "regular" are written down.

**Folded in 2026-11-21 (operator, GUI review of CS-PLAN-091 WP-B6):** a chart of the
cleanliness over time, with a choice of what the axes show (house, floor or room; the
period). It needs the derived cleanliness of FEAT-UX-071 and a value kept per room and day,
because a past day cannot be derived again once rules and assignees have changed.

- [ ] Chart of cleanliness over time with its choices; drawn in the app, no external
      service.

---

### [P2] FEAT-UX-071 — Cleanliness derived from the due windows
**Status**: Backlog (operator proposal in the GUI review of CS-PLAN-091 WP-B6, 2026-11-21; concept and ADR before any implementation) · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: review and correction
**Owning**: unassigned · **Depends on**: FEAT-UX-068 · **Blocks**: — · **Folded-in**: the stored `house_health` column and the `houseHealth` field of the house payload, which nothing maintains since `GET /house/health` went (CS-PLAN-091 RF-091-11, 2026-11-21): the statistics compute the health on read, the column keeps its last written value and a reset still writes 100
**Source**: Operator proposal (2026-11-21)

A room's cleanliness is a stored value today: each completion raises it by an effect in the
room journal (ADR-014). The operator proposes to derive it from the tasks' due windows, so
that early work pays:

- **Task:** 100 % from the day of the completion to the last flex day; then falling to 50 %
  at the first flex day of the next window, and to 0 % one day after its last flex day.
- **Room:** the mean of its tasks, weighted by their priority (1 to 3).
- **Floor:** the mean of its rooms. **House:** the mean of all rooms, not of the floors.

Open points for the concept (first assessment, 2026-11-21):

- Which day ends the 100 % when work was early or late: the end of the fulfilled window, or
  the completion day plus the flex days.
- A household that does everything on the planned day stays near 75 %, and a daily task
  without flex days reads 50 % every morning. Either that is the incentive, or 100 % holds
  until the next window opens and falls inside it.
- A task that was never done, sleeping, bonus, one-time and ended tasks, and a room without
  tasks.
- Private and admin-only tasks inside a value everyone sees.
- What becomes of the stored value, the room journal with its retained entries, *Last
  cleaned* and the house health that reads the cleanliness (an ADR next to ADR-014).

- [ ] Concept and ADR: the curve, the edge cases, the retained journal.
- [ ] One derivation next to `WindowService` for task, room, floor and house; PHPUnit for
      the curve and the weights.
- [ ] The room rows, floor headings and the house show the derived value; help text.

---

### [P3] FEAT-UX-059 — Planned date and scheduler rules
**Status**: In Progress (CS-PLAN-091 WP-C, implemented 2026-11-21, GUI review and interim Full review passed 2026-11-22; RF-091-14 implemented and its interim Full review passed 2026-11-22); before: Backlog (later, D-089-01; the operator wants the scheduler discussed separately) · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: scheduling
**Owning**: CS-PLAN-091 WP-C · **Depends on**: FEAT-UX-050 · **Blocks**: — · **Folded-in**: refused assignments and date moves after creation (operator decision, 2026-11-04); live suggestion, preferred days and team placement (operator, 2026-11-09, CONCEPT-002)
**Source**: Operator trial feedback on `0.8.0-alpha.3` (2026-11-03), CS-PLAN-089 register T-7

The report proposes a separate *scheduled* date per task, set by the scheduler and allowed
outside the flex days when no valid plan exists otherwise. Source-project answer
(2026-11-03): no second date on the task. The occurrence already separates obligation
(window) and plan (`planned_date`). The planner sets the planned day inside the window,
reusing the path of FEAT-UX-050; when nothing fits, it proposes an agreed amendment through
the existing date writer, which keeps its evidence row. Today `SchedulePlanService` allows
only dates inside the window.

Overbooking belongs here too (D-089-13). A day may be overbooked when a task is created, but
a later assignment or date move is refused at a person's budget or a child's limit, and with
energy up to 20 a task can exceed a whole weekday. The operator wants both reworked together.

**Folded in 2026-11-09 (operator, CONCEPT-002):** the scheduler discussion took place.
The planner becomes a live suggestion on *Today* and in the calendar, computed on read
and never applied. It honours a new per-member setting for preferred days, recalculates
after every change and places team tasks by the responsible person alone. Capacity then
shapes the suggestion instead of refusing (confirmed by the operator, 2026-11-10). Detail:
[simplified core](../concepts/simplified-core.md#scheduler).

- [x] Scheduler discussion with the operator; the decision recorded before a plan
      (2026-11-09, [simplified core](../concepts/simplified-core.md#scheduler)).
- [x] The refusals after creation become a visible overload: they went with ADR-021 and
      ADR-022 (CS-PLAN-091 WP-B1), and the live suggestion shows a task that fits no day of
      its window and a day with more than it holds (WP-C, 2026-11-21).
- [x] The live suggestion on *Today* and in the calendar, preferred days, team tasks and a
      child's limit as a second budget (ADR-022, D-091-126 to D-091-135; 2026-11-21).
- [x] The operator's GUI review of *Today* and the calendar (accepted as it is, D-091-136)
      and the interim Full review (`PASS-WITH-FIXES`, 2026-11-22).
- [x] RF-091-14: structure and performance of the live suggestion, before the WP-G candidate
      (implemented 2026-11-22, D-091-144 to D-091-152; interim Full review `PASS-WITH-FIXES`
      2026-11-22, D-091-153 to D-091-157).

---

### [P4] FEAT-UX-060 — Ranking over chosen periods
**Status**: Backlog (later, D-089-01; needs its own decision) · **Effort**: M · **Layer**: backend+frontend · **Journey stage**: ranking
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Operator trial feedback on `0.8.0-alpha.3` (2026-11-03), CS-PLAN-089 register T-5

The report asks for leaderboards computed for any period. ADR-015 shows only the current
week, and consent, which children give themselves, starts at the next full week and never
applies backwards. A period across older weeks would compare people who never agreed to it.

- [ ] Decide whether and how far ADR-015 changes; children's consent stays self-only.

---

### [P3] FEAT-UX-061 — More languages
**Status**: Backlog (later, D-089-01; once the texts of Rounds 1–3 settle) · **Effort**: L · **Layer**: frontend · **Journey stage**: every view
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Operator trial feedback on `0.8.0-alpha.3` (2026-11-03), CS-PLAN-089 register I-1

Support the more widely used European languages; English, Spanish and Portuguese would also
cover the Americas. About 1,340 strings, the task templates and the role-title packs of the
content policy need translating. `src/i18n.js` registers only German and bundles it, because
AIO can deny l10n JSON, so several languages need per-language chunks.

- [ ] Decide the languages and who reviews the translations.

---

## Infrastructure and Documentation

> Backlog hygiene, lint/dependency, docs truth, and operational security.
> Independently schedulable unless a gate depends on it (CS-PLAN-058 §2.2).

### [P1] TECH-TEST-005 — Successor ownership and mobile lifecycle coverage
**Status**: In Progress (automated scope delivered by CS-PLAN-084; operator real-device run open) · **Effort**: M · **Layer**: tests · **Journey stage**: completion / handover
**Owning**: CS-PLAN-080 (real-device run; user-rescoped from CS-PLAN-084 WP-G on 2026-10-17) · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: lifecycle test inventory, 2026-10-13

Delivered by [CS-PLAN-084](../plans/done/cs-plan-084-family-trial-ux-baseline.md)
WP-G: successor-ownership integration proofs after takeover, one-occurrence
handover and reassignment, the claim-confirm E2E, and the `mobile-chromium` /
`mobile-webkit` projects with the seven-scenario `mobile-core.spec.ts` in every
full browser gate. **Open:** the operator's recorded real-device run (Android +
iPhone, child and owner, light and dark) against the deployed instance before
the family trial starts
([release-test-plan §14.1](../testing/release-test-plan.md#141-real-device-checklist-cs-plan-084-operator)),
owned by [CS-PLAN-080](../plans/active/cs-plan-080-wp9-cross-db-and-family-evidence.md).
Emulation proves layout and request flow, not a real finger, screen reader or
vibration motor.

---

### [P3] TECH-TRANSPORT-001 — Attribute intermittent browser API connection termination
**Status**: Backlog · **Effort**: S · **Layer**: tooling · **Journey stage**: test-runtime reliability
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: 2026-10-11 core-feedback gate fixture-seed connection closures; 2026-10-14 CS-PLAN-084 WP-B/WP-E/WP-F/WP-C focused-run closures; 2026-10-15 CS-PLAN-084 full-gate closures with access-log correlation; 2026-10-18 CS-PLAN-087 interim gates (NC 35 seed `fetch failed` under foreign host load, NC 34 AIO `socket hang up` on `GET /api/v1/users` in `family-rewards`, no Apache error; both passed in isolation); 2026-10-18 CS-PLAN-086 WP-F staged row: one failure of `arcade-games` "a crafted score for a locked game is refused" (a seed and one POST, no WP-F code on its path; the error text was not captured, so it stays unattributed), green in three later runs; 2026-10-20 CS-PLAN-088 WP-B gates: three full primary-row gates and one AIO-row gate each lost one to three required scenarios (`settings`, `mobile-core` handover, `calm-core-ui`, `release-task-lifecycle`) to seed/API `fetch failed` before any assertion, with no Apache error, OOM or restart and a foreign Testcontainers run active on the shared Docker host; every one passes in isolation. Two non-transport scenarios failed once and passed in isolation too (`mobile-core` webkit navigation to Settings, `arcade-mobile` Crumb Snake reaching *over* before the steer), also unattributed; 2026-11-10 CS-PLAN-090 WP-A interim gate `run-YXOhYK` (primary row): `mobile-core` webkit "dashboard tiles lead to their targets" did not see Today within 5 s of the tile tap, while the failure screenshot shows Today rendered; `mobile-chromium` passed the same scenario, no WP-A code is on its path, and the next complete gate `run-G53HP8` passed, so it stays unattributed; 2026-11-11 CS-PLAN-090 WP-C full gate `run-B7GTOA`: seed POST of `validation.spec.ts` closed ("other side closed") before any assertion, eight tests skipped behind it, the other four projects passed (see the review findings of CS-PLAN-090); 2026-11-12 CS-PLAN-090 RF-090-12 runtime requalification, AIO row (Nextcloud 35.0.2, Redis 8.10.3): `run-F2Vfj0` lost one scenario of `workflow-state-reconciliation.spec.ts` and `run-6OZBYh` four of `mobile-core.spec.ts` on `mobile-webkit`; three of the five are seed requests closed ("other side closed") before any assertion that never reached the server's access log, two are timeouts within the same five minutes. A foreign Testcontainers run started during the first failure window and the later part of the second, while the two closures at the start of the second have no such record. The next complete gates passed on both rows (`run-zBcjiF`, `run-RwKd3N`), each started after a quiet host; 2026-11-13 CS-PLAN-090 interim review of WP-H and WP-I, AIO row (`run-cyYpmo`, started after three quiet minutes): the test's own `apiRequestContext` GET `/floors/with-rooms` in `task-completion-full-flow.spec.ts` ended in `socket hang up` before any assertion and is absent from the server's access log, where the page's own GET of the same second and all 325 requests of that window answered 200. The other failure of that run was an application race, fixed as RF-090-37 of CS-PLAN-090. After that fix the AIO row passed (`run-8xxQBQ`), and the primary row (`run-mvRorq`, canonical stack, started after three quiet minutes) lost `arcade-games.spec.ts` "Sock Match saves a round of found pairs to its own board" the same way: the test's own GET `/minigames/spots/personal-best` ended in `socket hang up` after the score POST and is absent from the access log, whose 413 requests of that 20-second window all answered 2xx or 3xx. The same scenario passed on both rows earlier in that session (`run-yV6aya`, `run-8xxQBQ`); the symptom again spans both rows and both containers; 2026-11-15 CS-PLAN-090 interim review of WP-K, AIO row (`run-O6eNGi`): in `task-manager.spec.ts` "User can open add task modal from Task Manager" *Add task* stayed disabled for 14 s, because the page's three room-task reads waited 14.2 s on the server and answered 200 together; in the same window the staged PostgreSQL's checkpoint spent 14.7 s in fsync for 137 buffers, with no error in the logs and no foreign container. A stall of the Docker host's disk, not of the transport or the app; the complete rerun passed (`run-x6gdyU`); 2026-11-22 CS-PLAN-091 interim review of WP-C, canonical stack (`run-DRAuF0`, no foreign container): the test's own GET `/house` in `family-reward-api.spec.ts` and POST `/setup/apply` in `setup-wizard-journey.spec.ts` ended in `socket hang up` before any assertion, one test skipped behind the second; the POST is absent from the access log. No WP-C code is on either path; the complete rerun passed (`run-ULfOXm`)
**Source**: installation follow-up qualification, 2026-10-09 (`run-J0ndFy`; sanitized summary in [the handoff](../testing/install-preparation-2026-10-08.md#diagnostic-boundaries))

An NC35 browser API GET terminated before any HTTP response during parallel
local runtime qualification. Retained traces exclude an early context close or
fixture teardown, but do not identify the terminating layer. This is distinct
from the reproduced application races, which were repaired. Both subsequent
complete sequential v4 gates pass without retries or skipped required tests.

The initial v5 NC35 full gate later also recorded fixture seed POST failures
(`fetch failed`, cause `SocketError: other side closed`) before application
assertions, followed by an outer runner timeout. No restart/OOM or Apache fatal
event was found. A controlled keep-alive hypothesis did not reproduce failure;
no request-retry or speculative header change was made. The affected suite
passes in isolation. Retain this additional evidence; do not narrow the item
to parallel-runtime load or claim that the transport cause has been established.
The complete unchanged-source NC35 requalification `run-RPFVu9` subsequently
passes all 295 executions without retries; that does not close this attribution
item or erase the failed run.

Core-feedback gate `run-t4pJYd` (2026-10-11) records two further seed POST
`fetch failed` / `SocketError: other side closed` failures before the
family-rhythm date-amendment and house-pause assertions. This is recurrence of
the observed symptom, not new root-cause evidence. No backend source changed in
that round. Raw reports stay local; RF-080-15 retains the required full-gate
requalification blocker rather than treating these tests as passed or skipped.

CS-PLAN-084 WP-B focused runs (2026-10-14, canonical NC35, CSS-only round)
record one seed POST `fetch failed` / `SocketError: other side closed`
(scheduling-apply-flow, scheduling-owner pool started concurrently with the
chromium pool) and one authenticated GET `socket hang up`
(insights-flow, serial pool alone) before assertions. Both specs pass isolated
in their pools (11/11, 10/10). Recurrence evidence only; no cause established.
WP-E/WP-F focused runs (2026-10-14, serial pool alone) add one seed `fetch
failed` (calm-core-ui, round 4) and one seed-fixture target preflight failure
(calm-core-ui free-day test, round 5), both before any assertion; calm-core-ui
passes isolated (9/9). WP-C (round 6, serial pool alone) adds one seed POST
`fetch failed` / `SocketError: other side closed` (room-management rename,
before any assertion); room-management passes isolated (10/10).

**Attribution evidence (CS-PLAN-084 rounds 8–11, 2026-10-14/15, full sequential
gates):** `run-fVpMiR` 3, `run-02sTE9` 8 and `run-Tiu5Sv` 2 seed POST
`fetch failed` closures (all `DatabaseManager.seed`, Node fetch from the
fixture), against six complete gates on both rows with none. Correlated with
the canonical container's Apache access log, the failed seed POSTs **never
reached Apache** (every one of 328 logged seed POSTs in the window answered
200; no restart, OOM, fatal or Apache error). The terminating layer is
therefore before Apache — the client's pooled connection or the Docker Desktop
loopback port proxy — not the app or Nextcloud. The two failing runs were the
latest in a long local session; the same session also produced load-timing
assertion failures (5 s expectations) that pass isolated. The next AIO-row gate
`run-jbuGNm` (NC 34.0.4, other container and port) failed once with a
Playwright `apiRequestContext` GET `socket hang up` — a different client — and
that GET is likewise absent from its Apache access log. The shared layer of
both rows and both clients is the Docker Desktop loopback port forwarding /
host networking of the long-running session. After an operator-approved Docker
Desktop restart (no other change) both full gates passed cleanly (`run-L6HInO`
canonical, `run-Vq2pkA` AIO) — consistent with, not proof of, a degraded
long-running port forwarder.

**Recurrence (2026-10-17, requalification of `master` `0d27d38`, staged rows):**
earlier full gates in the same Docker Desktop session passed on both rows
(`run-nKi6vJ`, `run-p5CXyQ`, `run-6jDStt`). Later in that session the
primary row failed twice: `run-BQqw1G` on one `DatabaseManager.seed` POST
`fetch failed` absent from Apache's access log (23 logged seed POSTs in the
window, all 200), and `run-8f3fhe` on one more such closure plus three views
that stayed empty past their 5 s expectations and one dark-theme contrast
sample, each in a minute where Apache logged 13–33 requests instead of the
usual 400–1300; no restart, OOM or fatal. After an operator-approved Docker
Desktop restart the fresh row passed first time (`run-I3xSLN`). Same signature
as above — the stall sits before Apache and grows with session age; still not
proof of the terminating layer.

**Deferral reason / scope:** attribution needs separate test-runtime/transport
instrumentation and load comparison; it is not an outstanding bootstrap or
Settings implementation criterion. Passing sequential gates are not proof of
parallel-runtime reliability. No request-error filter or retry workaround was
introduced, and raw traces/headers remain private.

- [ ] Correlate sanitized client, server and Docker lifecycle evidence to identify the terminating layer.
- [ ] Compare isolated and parallel runtime execution without changing required assertions or mutation retry semantics.
- [ ] Fix a reproducible cause and add a targeted regression; otherwise record the bounded attribution result and remaining uncertainty.

---

### [P1] CONCEPT-001 — Family-first rhythm and reward contracts
**Status**: In Progress — only the family evidence remains, in CS-PLAN-080 (parent CS-PLAN-070 in `done/` since its final Standard B `PASS-WITH-FIXES` 2026-10-18) · **Effort**: XL implementation (clarification was M) · **Layer**: docs+backend+frontend · **Journey stage**: product definition
**Owning**: CS-PLAN-080 (since 2026-10-18; before that CS-PLAN-070) · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: User-requested family-first concept review (2026-09-16)

[CS-PLAN-070](../plans/done/cs-plan-070-family-rhythm-and-reward-clarification.md)
is the decision register and owning review-finding record. The user approved
24 core choices across D1-D11 and 47 C1-C5 answers (6/11/9/8/13) on 2026-09-17;
the technical draft added on 2026-09-18 maps schema/APIs, transactions/races,
exact files/tests and ten work packages (48-76 engineering days before contingency,
plus two weeks of voluntary family use). Q-070-01/02 on structural-deletion history
and true account erasure were answered in the 2026-09-18 Standard A review
(tombstoned minimum-history retention; account erasure/anonymization), which
passed with `PASS-WITH-FIXES` and activated the plan; the earlier dismissed
question batch was never counted as consent.
Runtime findings stay in that plan, not parallel backlog entries. Existing
add-on owners remain; notification/pause work belongs
to the selected core and the optional catalog follows it. Chore approval/Task Pass,
external sync, local child profiles, cosmetics and non-Snake games are outside
this delivery slice. No item is completed by the WP0 delivery alone.
WP0 (write/identity foundation) was sub-planned and delivered by the child
[CS-PLAN-071](../plans/done/cs-plan-071-wp0-write-identity-foundation.md)
(Standard A `PASS-WITH-FIXES` 2026-09-18; **final Standard B
`PASS-WITH-FIXES` 2026-09-19, plan in `done/`**): house-first lock order,
Version1030 commands/wallets/wallet_entries, wallet identity at every
membership path, `CommandService`, tombstone deletion with retained
evidence, account erasure + `UserDeletedListener`, T0 suites. WP1
(occurrence timeline) was sub-planned and delivered by the child
[CS-PLAN-072](../plans/done/cs-plan-072-wp1-occurrence-timeline.md)
(Standard A `PASS` 2026-09-20; **final Standard B `PASS-WITH-FIXES`
2026-09-23, plan in `done/`**): immutable rule revisions, stable occurrence
keys, saved windows/time basis, deterministic target selection, write-free
projections, `TimelineJob`, completion identity via `CommandService`
receipts, occurrence-scoped checklist/undo, current-client integration
and — per Q-072-01 Option A — the minimal common agreement writer with
`agreements` persistence and the necessary C2
deadline/authorization/capacity checks; ADR-013 recorded. The WP3+
behavior packages remain open in the parent; CONCEPT-001 remains open.
WP2 was sub-planned as [CS-PLAN-073](../plans/done/cs-plan-073-wp2-agreements-capacity-pauses.md)
(Standard A `PASS-WITH-FIXES` 2026-09-23; final Standard B `PASS-WITH-FIXES`
2026-09-25 — delivered; Q-073-01/02/03 resolved with the
recommended options, including the frozen cross-zone capacity/pause comparison):
agreements, exact planned shares, whole-day capacity, pauses, catch-up/restart
and midnight-stale 409, with concrete test/file ownership and explicit
WP3/WP4/WP5/WP6 handoffs. WP2 is delivered; later packages remain open.
WP3 was sub-planned and delivered by the child
[CS-PLAN-074](../plans/done/cs-plan-074-wp3-work-accounting-and-replay.md)
(activated 2026-09-25, Standard A `PASS`; **final Standard B re-review
`PASS-WITH-FIXES` 2026-09-27, plan in `done/`**): actual shares, exact
minor-unit wallet accounting (sole ledger authority, ADR-014), targeted
undo/attribution with departed-wallet conservation, room journal/replay
(CR-SVC-63 closed), the Q-074-01A rhythm/weekly/retained-recognition
correction closure, retention/erasure sweeps and the minimal client/no-Coin
arcade cutover; RF-074-WP3-01..10 resolved. Later packages (WP4+) remain
open in the parent; CONCEPT-001 remains open.
WP4 (rhythm/goals/ranking) was sub-planned as
[CS-PLAN-075](../plans/done/cs-plan-075-wp4-rhythm-goals-and-ranking.md)
(activated 2026-09-27 via Standard A `PASS-WITH-FIXES`; Q-075-01/02 Option A
selected) and **delivered** (final Standard B `PASS-WITH-FIXES` 2026-09-29):
rhythm/goal read contract, consent-filtered ranking with real
consent persistence in WP4,
single-series retained recognition and retired-mechanic removal (ADR-015).
WP5 (preferences/digest delivery) was sub-planned as
[CS-PLAN-076](../plans/done/cs-plan-076-wp5-preferences-and-digest-delivery.md)
(final Standard B `PASS-WITH-FIXES` 2026-09-30; delivered with ADR-016,
self-only preferences, quiet shared digest and complete T7 evidence).

WP6 (calm core UI) was sub-planned as
[CS-PLAN-077](../plans/done/cs-plan-077-wp6-calm-core-ui.md) and activated
2026-09-30 via Standard A `PASS-WITH-FIXES` (Q-077-01..05 all Option A);
**delivered** (final Standard B `PASS-WITH-FIXES` 2026-10-01): all-age My Day
landing/content, preference-gated calm feedback, full
`NotificationPreferences.vue` + optional-area consumers, pause UI, occurrence
agreement entry points, shared-device store reset, no-Coin closure and the
essential help slice; FEAT-UX-024/025 closed with it.
WP7 was delivered via [CS-PLAN-078](../plans/done/cs-plan-078-wp7-catalog-and-request-backend.md)
(final Standard B `PASS-WITH-FIXES` 2026-10-01; catalog/C4 backend, ADR-017).
WP8 was delivered via [CS-PLAN-079](../plans/done/cs-plan-079-wp8-reward-ui-and-onboarding.md)
(final Standard B `PASS-WITH-FIXES` 2026-10-02: reward UI/onboarding, two catalog gates,
persistent request surfaces, exact prices, uncertain-command replay, essential help).
CONCEPT-001 remains In Progress for WP9 (cross-DB/family evidence) — the closing gate.
WP9 is planned as [CS-PLAN-080](../plans/active/cs-plan-080-wp9-cross-db-and-family-evidence.md)
(drafted 2026-10-02, activated 2026-10-03 via Standard A `PASS`, zero findings;
WP9 owner): isolated MariaDB/PostgreSQL/SQLite verification,
two consecutive combined browser gates and voluntary adult/child comprehension
plus 14-day everyday-use evidence. Its NC31 technical matrix and consecutive
browser pair passed on 2026-10-05; human coordination, guardian consent and
minimum voluntary participation were confirmed on 2026-10-07, but comprehension
and 14-day evidence remain uncollected.
**Consolidation (2026-10-18, user decision):** CS-PLAN-070 closed with a final
Standard B `PASS-WITH-FIXES` (RF-070-01..17 verdicts, durable docs reconciled);
its G3 family evidence and this item's closure moved unchanged to CS-PLAN-080,
now the single owner. Close this item only after the real-device run, HUMAN-01/02
and CS-PLAN-080's final review pass.
The separate TECH-RUNTIME-001 qualification is delivered by CS-PLAN-082
(final Full `PASS-WITH-FIXES` 2026-10-06, archived); the old-runtime passes remain
historical and do not replace its NC35/NC34 evidence or authorize retained use.

**Pre-WP9 preparation delivered (2026-10-05):**
[CS-PLAN-081](../plans/done/cs-plan-081-pre-wp9-hardening-and-retained-data-readiness.md)
passed its final Full review (`PASS-WITH-FIXES`).
Its bounded H4 reset-copy clarification contributes to this existing parent
owner; H6 retained-data/deployment preparation belongs to RELEASE-002. It does
not close parent findings or replace WP9 evidence.

- [x] Core D1-D11 choices recorded through 24 explicit answers (2026-09-17).
- [x] Six C1 and eleven C2 choices recorded with future acceptance scenarios (2026-09-17).
- [x] Nine C3 choices recorded with accounting/replay design consequences and future scenarios (2026-09-17).
- [x] Eight C4 choices recorded with a durable actor/state matrix and future scenarios (2026-09-17).
- [x] Thirteen C5 choices recorded with delivery/UI/consent contracts, future scenarios and a family-test protocol (2026-09-17).
- [x] Specify technical cross-contract draft, exact file/test manifest, work-package owners/dependencies and estimates (2026-09-18; not implementation approval).
- [x] Resolve Q-070-01/02 explicitly and have Standard A accept the integrated design before activation (2026-09-18: tombstone retention + account erasure; Standard A `PASS-WITH-FIXES`).
- [ ] Resolve the plan's findings or explicitly rescope to named owners before completion.

---

### [P1] CONCEPT-002 — Simplified core target picture
**Status**: In Progress — confirmed by the operator 2026-11-10; the plan for Release A is active (CS-PLAN-090, Standard A 2026-11-10), those for B and C are open · **Effort**: XL implementation (the target picture was M) · **Layer**: docs+backend+frontend · **Journey stage**: product definition
**Owning**: unassigned · **Depends on**: — · **Blocks**: FEAT-UX-040, FEAT-UX-041, FEAT-UX-050, FEAT-UX-052, FEAT-UX-059 · **Folded-in**: —
**Source**: Operator feedback at the close of the family trial (2026-11-09)

The operator's feedback after the trial goes beyond the planned Round 2 (D-089-01). It
replaces agreed appointments with derived due windows, requests with trust, and the applied
plan with a live suggestion. [`simplified-core.md`](../concepts/simplified-core.md) is the
target picture and the decision record. It changes recorded contracts (ADR-012, ADR-013,
ADR-014, calm core UX), so each of its three releases needs its own plan, a Standard A review
and a superseding ADR before code. Until then the shipped contracts stay binding.

The decisions are folded into the items that already own a topic (FEAT-UX-040, FEAT-UX-041,
FEAT-UX-050, FEAT-UX-052, FEAT-UX-059). The other Round 2 items (FEAT-UX-043, FEAT-UX-051,
FEAT-UX-053, FEAT-UX-054) keep their content, and the release plans slot them. Round 3 is not
affected. Release A delivered FEAT-UX-062 to FEAT-UX-067 with FEAT-UX-053, FEAT-UX-054,
FEAT-UX-056 and FEAT-UX-057 (CS-PLAN-090, closed 2026-11-16, archived). Releases B and C are planned
together in CS-PLAN-091 (active since 2026-11-16); its new items are FEAT-UX-068 (derived windows and
the single red entry), FEAT-UX-069 (sleeping and bonus tasks) and FEAT-UX-070 (coin factor and
bonus days).

All three releases stay alpha versions; the beta follows after them (operator, 2026-11-10).
A, B and C reach the family instance as one version: Release A, qualified as
`0.8.0-alpha.5`, is not deployed on its own, and B and C share one plan (operator,
2026-11-15, [delivery](../concepts/simplified-core.md#delivery)).

- [x] Operator decisions of 2026-11-09 recorded in the concept.
- [x] Mockups reviewed with the family; the operator confirms the target picture (2026-11-10).
- [ ] The concept's open points are settled in the owning plans.
- [ ] Plans drafted with Standard A: Release A (CS-PLAN-090, Standard A 2026-11-10, closed
      2026-11-16) and one plan for B and C; all three share one deployment.

---

### [P3] TECH-DEV-002 — A rebuilt bundle is not fetched on the development stack
**Status**: In Progress (CS-PLAN-091, D-091-158: fixed on `feat/simplified-core-bc` 2026-11-22, D-091-160; before the GUI review of the whole release); before: Backlog · **Effort**: S · **Layer**: infra · **Journey stage**: —
**Owning**: CS-PLAN-091 (round before the candidate) · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Operator report during CS-PLAN-091 WP-B7, 2026-11-21

After a rebuild on the canonical development stack, an open browser kept the old
`chorestrike-main.js` and loaded the new lazy chunks next to it: *Tasks* stayed empty until a
forced reload. Nextcloud serves scripts with a `?v=` cache buster as
`max-age=15778463, immutable`; the buster follows the app version, which stays
`0.8.0-alpha.5` through the whole plan (D-091-06), so the URL of the entry bundle never
changes between builds. A release bumps the version, so installs are not affected. On
2026-11-21 the theming cache buster of the stack was raised once by hand
(`occ config:app:set theming cachebuster`), which changes the suffix of every script URL.

- [x] A build for the development stack changes the URL of the entry bundle, or the stack
  serves the app's scripts with revalidation; the browser gates keep their behaviour. The
  test entrypoint holds the theming cache buster at the entry bundle's build time
  (D-091-160); verified live on the canonical stack (a rebuild moved the page's address
  to `?v=…-<build time>`); the full browser gate passed on its first run (`run-MfpeVt`,
  2026-11-22).
- [x] [`testing.md`](../engineering/testing.md#rebuilt-bundles-and-gui-reviews-tech-dev-002)
  names the step for a GUI review.

---

### [P3] TECH-LINT-009 — Boolean props that default to `true`
**Status**: Backlog · **Effort**: S · **Layer**: frontend · **Journey stage**: —
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: CS-PLAN-088 D-088-10 (operator, 2026-10-20)

`vue/no-boolean-default` of the Nextcloud ESLint preset wants Boolean props to
default to `false`. Three components default one to `true` and are exempted
file by file in `eslint.config.js`: `ErrorState` (`showRetry`), `Modal`
(`show`) and `SetupWizard` (`show`); `HouseHealthBar` and `QuickStats` went with
the Dashboard (CS-PLAN-090 WP-B). Inverting them (for example `hideRetry`) changes each
component's public API, every caller and the specs, so it was kept out of the
toolchain change. `show` on the two dialogs is the usual visibility idiom and
may be better left as a permanent, documented exception.

- [ ] Each exempted prop is inverted with its callers and specs, or its exception is documented as permanent in `eslint.config.js`.
- [ ] The file-by-file exemption block is removed.

---

### [P2] TECH-TEST-008 — One reusable Docker test stack per worktree
**Status**: Backlog · **Effort**: M · **Layer**: tests+tooling · **Journey stage**: —
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: operator request during CS-PLAN-086 WP-B, 2026-10-17

All worktrees share the canonical stack of `docker/compose.test.yml` (fixed
container names `chorestrike-test`/`chorestrike-db`, port 8080, compose
project `docker`). `verify-canonical` only refuses a stack started from
another checkout; it does not separate them. On 2026-10-17 a session
recreated and reseeded the canonical stack for its own browser runs while a
second worktree (`feat/public-presentation`) existed — the next stack user
must recreate it from its own checkout and loses the other's state.

Isolated targets already exist as qualification tooling: the staged rows of
`scripts/test-runtime-stage.js` (own `cs082-*` project, own loopback port and
volumes, bind mount of the calling worktree; PHPUnit via `docker exec`,
Playwright and the browser gate via `NEXTCLOUD_URL` + `NEXTCLOUD_CONTAINER`
with per-target auth state). They are not a per-worktree workflow: every `up`
needs a fresh project name (no reuse, no cleanup), `scripts/run-tests.js`
only knows the canonical stack, and the `docker-dev` skill documents the
shared stack as the rule.

Target: every worktree gets its own reusable stack whose project name and
loopback port derive deterministically from the worktree pin, so parallel
sessions never touch each other's database.

- [ ] Derive project name and port from the pinned worktree; refuse collisions with another worktree's stack.
- [ ] `run-tests.js`, `test:runtime:canonical` and the browser gate resolve that target by default (`NEXTCLOUD_URL`/`NEXTCLOUD_CONTAINER` stay explicit overrides); recreating a stack reseeds only its own volumes.
- [ ] A cleanup command removes a worktree's stack with the worktree and lists stale `cs082-*` leftovers without deleting other owners' resources.
- [ ] Update the `docker-dev` and `git-workflow` skills, `development.md` and `testing.md` (canonical source, then `npm run agents:sync`).
- [ ] Tests for the derivation, collision refusal and target resolution (Jest, Docker-free), plus one real two-worktree run.

---

### [P3] BUG-SEC-001 — Security reporting contact for later external use
**Status**: In Progress (GitHub-only reporting channels published 2026-10-18; authority feedback on the deferred legal/privacy notice pending) · **Effort**: S · **Layer**: docs · **Journey stage**: security reporting
**Owning**: unassigned · **Depends on**: — · **Blocks**: RELEASE-002 (external-use stage only) · **Folded-in**: H7/H-T11 user rescoping (2026-10-04); operator-approved project mailbox and public-contact guidance (2026-10-12); reporting-policy refinements and legal-notice deferral (2026-10-13); GitHub-only contact channels (2026-10-18)
**Source**: CS-001 security review; CS-PLAN-081 H7 Post-Review Scope Decision; user-provided project address 2026-10-12; explicit policy and scoped-commit request 2026-10-13; operator decision 2026-10-18

The operator initially tests alone and explicitly removed H7/H-T11 from
[CS-PLAN-081](../plans/done/cs-plan-081-pre-wp9-hardening-and-retained-data-readiness.md)
on 2026-10-04. No new reporting address or channel test is required for that
self-test stage or preparation-plan completion. This item remains open for
later external tester onboarding / public release, not a completed H7 claim.

The operator supplied a project mailbox on 2026-10-12 for public project
contact. README and `docs/SECURITY.md` documented that mailbox; contributor
and conduct guidance distinguish public issues from private reports. This
ordinary-email route was independent of the repository migration (TECH-REPO-001,
completed 2026-10-16) and does not require GPG, a separate `security@` alias or
a GitHub-associated address. GitHub private vulnerability reporting is now
offered alongside it as the preferred channel. The operator
authorized a scoped local documentation commit on 2026-10-13, not a push,
mailbox login, test email or account/profile change.

The refined policy requires explicit permission before publicly crediting a
reporter, explains that ordinary email is not end-to-end encrypted, requests
notification when upstream issues affect the app, and distinguishes fixes on
`master` / subsequent releases from unsupported older-release backports.

**Operator decision (2026-10-13):** do not add a legal notice or separate
privacy information while awaiting feedback from the responsible media
authority. This is a temporary deferral, not a finding of compliance or an
exemption. Incoming report emails and sender data are distinct from household
data processed in other operators' Nextcloud installations; `docs/SECURITY.md`
is an operational reporting policy, not complete data-protection information.

Mailbox creation, the receipt of a test email and replying from the project
address are operator-confirmed (2026-10-17). The contact was public in README and
`docs/SECURITY.md` on GitHub from the approved CS-PLAN-085 push (2026-10-16).

**Operator decision (2026-10-18):** the mailbox is no longer published.
Questions go to GitHub Discussions (Q&A, enabled 2026-10-18); security reports
use GitHub private vulnerability reporting only; Code of Conduct reports use
GitHub's *Report content → Report to repository admins*. README, user guide,
`docs/SECURITY.md`, contributing and conduct guidance, the issue-template
chooser and the organization profile follow this. The address remains in Git
history and closed plans.

The operator enabled reported content for all users (2026-10-18). The item
remains open only for the authority's feedback on the deferred legal notice
and privacy information. No public security issues or sensitive real-user
payloads.

- [x] Record the operator-approved reporting mailbox (2026-10-12).
- [x] Add the contact to local README/security guidance and replace the deferred-contact note; GPG remains optional.
- [x] Refine public-credit consent, email limitations, affected-dependency reporting and release/backport wording (2026-10-13).
- [x] Confirm receipt of a test email (operator, 2026-10-17).
- [x] Confirm a reply sent from the project address without exposing a private sender address (operator, 2026-10-17).
- [x] Withdraw the mailbox from public docs; route questions to GitHub Discussions and security/conduct reports to GitHub's private reporting (operator decision, 2026-10-18).
- [x] Enable *Settings → Moderation options → Reported content* so conduct reports reach the maintainer (operator: all users, 2026-10-18).
- [ ] Reassess the deferred legal notice and privacy information after the authority's response, including the handling of incoming reports.
- [x] Publish the verified contact with an explicitly approved source update before external-user onboarding; retain correct links for the selected hosting platform (public on GitHub since the CS-PLAN-085 push, 2026-10-16; delivery verified 2026-10-17).

---

### [P3] TECH-TEST-010 — Nested Jest probe failed once in a full run
**Status**: Backlog · **Effort**: S · **Layer**: infra · **Journey stage**: testing
**Owning**: — · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Full Jest run on the working tree, 2026-11-05

In one of three consecutive full runs, the nested `jest --runInBand --no-cache` probe of
`jestDiscovery.spec.js` (*does not index dependency package metadata from extracted build
artifacts*) exited non-zero, failing 1 of 2294 tests. The spec alone and the two following full
runs were green. The assertion discards the probe's output, so the cause is unknown; a slow
uncached probe under parallel load and other suites writing below `build/` are unverified
candidates.

- [ ] The spec reports the probe's stdout and stderr when its exit status is not 0.
- [ ] The next occurrence is attributed and its cause fixed.

---

### [P3] TECH-TEST-015 — Controller integration tests leave the instance house behind
**Status**: Backlog · **Effort**: S · **Layer**: infra · **Journey stage**: testing
**Owning**: — · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: CS-PLAN-091 WP-B1 implementation, 2026-11-18

A controller test whose fixture house does not carry the instance id makes the controller
create the instance house (`HouseService::getOrCreateHouse()`, default name *My House*)
and nothing removes it: 19 integration classes leave one house row behind when run alone
(for example `ArcadeSettingsControllerIntegrationTest`, `ChecklistApiTest`,
`TaskAuthorizationTest`, `RoleGuardApiTest`, `SupportedDatabaseSchemaTest`,
`TaskCreationAggregateTest`). A full run ends clean only because a later test resets the
tables. The row has the lowest id, so every job-shaped pass that takes the one household
of the instance (`HouseMapper::findOneForJobs()`) silently works on it instead of the
fixture house; the cycle job tests therefore call the per-house pass (D-091-37).

- [ ] Fixture houses of controller tests carry the instance id the controller resolves, or
  the base test case removes an instance house a test created.
- [ ] `FixtureCleanupTest` proves that a controller test leaves no house row.

---

### [P3] TECH-TEST-011 — Browser specs that wait for `networkidle` on a hash route
**Status**: Backlog · **Effort**: S · **Layer**: tests · **Journey stage**: testing
**Owning**: — · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Browser gate `run-zQecqD` on `chore/deps-2026-11`, 2026-11-05

A `page.goto()` between hash routes keeps the document, so `waitForLoadState('networkidle')`
returns at once: with six API requests held for 1.5 s it waited 1 ms, and 1636 ms on a fresh
document. `color-contrast.spec.ts` measured views before their data loaded because of this,
missed a real contrast defect and failed once mid-transition. It is corrected.
`layout-clearance.spec.ts` walks all views the same way, and `calendar`,
`error-handling`, `flex-window-scheduling`, `scheduling`, `self-availability-member` and
`workflow-request-budgets` use the same wait. Whether each of them depends on it is unverified.

- [ ] Each call is reviewed; a measurement of loaded content waits for that content.
- [ ] A corrected `layout-clearance.spec.ts` shows whether loaded views still pass.

---

### [P3] TECH-TEST-014 — A tap in `mobile-webkit` can open a second page
**Status**: Backlog · **Effort**: S · **Layer**: tests · **Journey stage**: testing
**Owning**: — · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of CS-PLAN-090 WP-K (interim Standard B), browser gate `run-flsDoF`, 2026-11-15

In `mobile-core.spec.ts` on `mobile-webkit` (primary row), "settings stay reachable through the
phone navigation for a child" tapped the *Settings* entry of the open phone navigation, a router
link. Playwright recorded a second page in the same context 0.3 s later; that page loaded the app
and showed Settings, while the test's page stayed on Today with the navigation open, so
`settings-view` never appeared there within 5 s. The first tap attempt had found the entry not
stable while the navigation slid in. Nothing in `src/` opens a window, `mobile-chromium` passed
the same scenario, and the complete rerun passed (`run-SXfzOH`). The `mobile-webkit` failure in
the WP-A review of 2026-11-10 (Today not seen 5 s after a Dashboard tile tap, the screenshot
showed Today) has the same signature; its trace is gone, and it was folded into
TECH-TRANSPORT-001 as unattributed.

- [ ] The cause is found (Playwright's WebKit, the iPhone descriptor or the link's touch handling) and fixed, not waited out.
- [ ] A phone spec that taps a link fails with a clear message when a second page opens.

---

### [P3] TECH-DEPS-001 — Major updates Dependabot cannot land
**Status**: Backlog · **Effort**: L · **Layer**: infra · **Journey stage**: —
**Owning**: unassigned (the in-range slice was delivered with CS-PLAN-090 WP-H) · **Depends on**: — · **Blocks**: — · **Folded-in**: Dependabot PRs #8 and #11 (2026-11-05); `fast-xml-parser` 5.11.2 advisory (2026-11-13, resolved in range); Dependabot PR #12, `follow-redirects` 1.16.3, taken in range (2026-11-15, with the candidate of CS-PLAN-090); Dependabot PR #13 (2026-11-15: `@nextcloud/vue` 9.14.1, `@playwright/test` 1.65, `node-polyfill-webpack-plugin` 4.1, `@axe-core/playwright` 4.14, rows of the table below; kept out of `0.8.0-alpha.5`, CS-PLAN-091 D-091-158)
**Source**: Dependabot triage of 2026-11-05

PR #11 groups 29 npm updates and stops at `npm ci` with ERESOLVE, so its compatible part landed
by hand on 2026-11-05. What remains has a peer blocker or moves a core library, and each row
needs its own slice. Green Dependabot checks prove little here: neither workflow on a PR runs
PHPUnit or the browser gate.

| Update | Blocker on 2026-11-05 |
|---|---|
| Babel 8, `@nextcloud/babel-config` 2, Jest 30, `jest-environment-jsdom` 30 | `@vue/vue3-jest` 29.2.6, its latest release, peers Jest 29, `babel-jest` 29 and Babel 7; `@babel/plugin-syntax-dynamic-import` peers Babel 7 |
| TypeScript 7 | `typescript-eslint` peers `<6.1.0` |
| `webpack-cli` 7, `node-polyfill-webpack-plugin` 4.1 | `@nextcloud/webpack-vue-config` 7.1.0, its latest release, peers `^6.0.1` and exactly `4.0.0` |
| `stylelint-config-recommended-vue` 2, `postcss-html` 2 | `@nextcloud/stylelint-config` peers `^1.6.1` |
| `@nextcloud/vue` 9.14, `@nextcloud/dialogs` 7.4 or 8 | `@nextcloud/vue` 9.14 peers dialogs `^7.4 \|\| ^8` and files `^4.1.1`; dialogs stays on `~7.2.0` until a dedicated update qualifies it ([development guide](../engineering/development.md)) |
| `pinia` 4, `vue-router` 5 | No peer blocker; they carry 17 and 7 `src` files, so they need the browser gate |
| `@playwright/test` 1.64, `@axe-core/playwright` 4.14 | No peer blocker; they change the gate itself, so they go alone and not with app updates |
| PHPUnit 13 (PR #8) | Four majors: 65 `@dataProvider` docblocks in 45 test files, removed in PHPUnit 12, three non-static providers, 9.5 schemas in both `phpunit.*.xml`, and the 9.6.34 pin in `docker/test-runtime.json` |

Four advisories stay open behind these rows, and the bounded disposition in
[RELEASE-002](#p2-release-002--beta-deployment--real-world-alpha-usage) keeps applying to the
build and test chains:

- `minimatch` below 11.0.1 (moderate): 9.0.9 through `webdav` under dialogs, 10.2.6 through the
  ESLint and `js-beautify` chains. No major override without a test of the affected chain.
- `glob` 13.0.6 (moderate) through `js-beautify`.
- `deepmerge` 4.3.1 (low) through `jest-config`, no fixed release published.
- `elliptic` 6.6.1 (low) through `node-polyfill-webpack-plugin` 4.0.0.

- [ ] Each row above lands in its own slice, or is declined with a reason.
- [ ] PR #8, PR #13 and the rest of PR #11 are closed by those slices or declined.
- [x] `lucide-vue-next` and `ajv` have no importer in tracked code: remove them after a peer
      check instead of updating them (2026-11-13, CS-PLAN-090 WP-H; `ajv` 8 stays installed
      through `schema-utils`).
- [x] `dependabot.yml` limits the `npm-all` group to minor and patch updates, so one blocked
      major no longer stops the whole group (2026-11-13, CS-PLAN-090 WP-H).

---

## Release

> Stage path: `v0.8.x` alpha → `v0.9.x` beta → `v1.0.0` stable (manual install).
> App Store submission is explicitly deferred until stable quality is proven.

### [P2] RELEASE-002 — Beta deployment + real-world alpha usage
**Status**: In Progress (`0.8.0-alpha.4` deployed 2026-11-05 and its CS-PLAN-089 slice finished 2026-11-09; CS-PLAN-090 finished 2026-11-16 with its `0.8.0-alpha.5` candidate qualified 2026-11-15 and not deployed (D-090-81); CS-PLAN-091 active 2026-11-16 for the joint `0.8.0-alpha.5` of A, B and C, whose candidate `2fe6cc11` was qualified 2026-11-23 and waits for export and deployment; field use and later beta remain open) · **Effort**: M · **Layer**: — · **Journey stage**: release
**Owning**: CS-PLAN-091 WP-G (the `0.8.0-alpha.5` release slice of A, B and C); CS-PLAN-080 (family evidence, alpha tags); later beta onboarding unassigned · **Depends on**: — · **Blocks**: RELEASE-003 · **Folded-in**: retained-data trial readiness and safe recovery boundary (2026-10-03 user request); concrete AIO installation handoff/runbook gaps (2026-10-07); bounded remaining build/test dependency disposition (2026-10-08 user decision); alias-free and deterministic package qualification gates (CS-PLAN-083, 2026-10-10)
**Source**: staged release effort (roadmap); blocking-gate list fixed by CS-PLAN-058 §2.2/§3

Deploy to a personal Nextcloud instance; verify no impact on other apps;
real-world `v0.8.x` alpha usage; (optional) private tester onboarding for
`v0.9.x` beta with structured feedback intake.

[CS-PLAN-083](../plans/done/cs-plan-083-release-qualification-gates.md) completed
its bounded alias-free provisioning, deterministic clean-candidate identity
and wired same-archive NC35/NC34 qualification slice on 2026-10-10 (final Full
`PASS-WITH-FIXES`, all RF-083-01..17 resolved). Renewed candidate `853f661`
produces the same 522-file archive in two builds and the strict package smoke;
full browser pair `run-IDVdFy`/`run-EpjkYH` and fresh/populated preservation
proofs pass. The initial `325f23b` qualification remains historical, superseded
by the review-hardened candidate. This contribution does not close RELEASE-002,
perform deployment/adoption or replace CS-PLAN-080's independent human evidence.
See the [completion/review record](archive/CODE_REVIEW_2026-10-10.md).

The verified production package identity and the concrete adoption record are
in the deployment guide since 2026-10-17. It remains a generic guide, not an AIO
runbook: target-specific AIO commands stay in the server's operations project,
never guessed from container paths or the disposable test stack.

The [2026-10-08 app qualification](../testing/install-preparation-2026-10-08.md)
owns the corrected asset contract, exact candidate artifact and renewed runtime
evidence. Six remaining build/test advisory roots are explicitly deferred here
under bounded trusted-input/no-production-tooling conditions. Recheck stable
fixes and review any necessary peer/engine migration before later builds; this
is not operator risk acceptance, production approval or resolution of NC34's
separately recorded native third-party platform failure.

Exact blocking gates:
- [x] CS-PLAN-058 WP1..WP9 exit gates, each passed with its Standard B review
      (program closed; plan in `done/` 2026-09-16).
- [x] Final Full review of the complete owner/member/child journey: `PASS` or
  `PASS-WITH-FIXES` (CS-PLAN-058 §3 WP9) — Full review `PASS-WITH-FIXES`
  2026-09-14 incl. the D-FR-04 browser-gate pair.

The existing-runtime WP9 technical/provider and browser-pair gates passed on
2026-10-05. TECH-RUNTIME-001's current-stable qualification is delivered by
[CS-PLAN-082](../plans/done/cs-plan-082-current-stable-test-baseline-and-data-preservation.md)
(final Full `PASS-WITH-FIXES` 2026-10-06): NC35 primary/NC34 AIO row, all providers,
populated preservation/recovery, canonical/CI promotion and the post-repair browser
pair pass. Still blocking: explicit safe deployment/adoption decisions and the
deployment/field-usage work itself. This is not a deployment or retained-baseline sign-off.
SEC-AUDIT-001 is completed by CS-PLAN-081 (2026-10-05), not a deployment sign-off.
BUG-SEC-001 remains required before later external tester onboarding / public
release, but is explicitly not a blocker for operator-only self-testing or
CS-PLAN-081 completion (user decision 2026-10-04). This limited exception does
not waive deployment safety, technical readiness or retained-baseline adoption.

**Retained-data preparation delivered (2026-10-05):**
[CS-PLAN-081](../plans/done/cs-plan-081-pre-wp9-hardening-and-retained-data-readiness.md)
H6 (final Full `PASS-WITH-FIXES`; Q-081-01 resolved to post-WP9 baseline
adoption) documents the first protected build/adoption boundary, future
data-preserving migration rules, synthetic recovery rehearsal and truthful
whole-Nextcloud rollback impact. This is a partial contribution, not deployment
or field-usage completion. The intended family candidate must pass
CS-PLAN-080's technical matrix/browser gates before real retained data; human
evidence then remains with CS-PLAN-080/CONCEPT-001. No premature
productive-use declaration or circular prerequisite on a completed human trial
is introduced.

**Deployment `0.8.0-alpha.1` (2026-10-17):** qualified by
[CS-PLAN-084](../plans/done/cs-plan-084-family-trial-ux-baseline.md) round 12
(both runtime rows, MariaDB/SQLite provider suites; the package smoke runs on
the committed candidate and is recorded in the plan). Deployed by the operator
to the production AIO on 2026-10-17 and adopted as the first retained baseline
([adoption record](../engineering/deployment.md#adoption-record-2026-10-17)):
verified package identity, unchanged 26-migration inventory, all app tables
preserved, other apps unaffected, production smoke passed
([CS-PLAN-080](../plans/active/cs-plan-080-wp9-cross-db-and-family-evidence.md#deployment-and-retained-baseline-adoption-080-alpha1-2026-10-17)).
Still open here: real-world `v0.8.x` field use (the family trial in CS-PLAN-080)
and the optional `v0.9.x` tester onboarding.

**Alpha.3 round (2026-10-19):** [CS-PLAN-088](../plans/done/cs-plan-088-alpha3-feedback-round.md)
passed Standard A with fixes and owns only the next feedback/release bundle.
Q-088-03/04 select full-sign-off-gated alpha.2/alpha.3 tags and deployment-dated
changelog sections as policy, not permission to publish or deploy. The operator
retains those task-specific approvals; completing the bundle does not close
this whole item or CS-PLAN-080's family evidence.

**Deployment `0.8.0-alpha.4` (2026-11-05):** qualified in
[CS-PLAN-089](../plans/done/cs-plan-089-alpha4-trial-defects-and-everyday-fixes.md#review-outcome)
and deployed by the operator as an app update on the retained baseline
([record](../engineering/deployment.md#update-to-080-alpha4-2026-11-05)): verified
package identity, unchanged 26-migration inventory, all app tables preserved, other
apps unaffected, production smoke reported as passed. The plan closed on 2026-11-09
with its final Standard B review, which closes neither this item nor CS-PLAN-080's
family evidence.

---

### [P2] RELEASE-003 — v1.0.0 stable artifacts
**Status**: Backlog · **Effort**: M · **Layer**: — · **Journey stage**: release
**Owning**: unassigned · **Depends on**: RELEASE-002 · **Blocks**: — · **Folded-in**: —
**Source**: staged release effort (roadmap)

Release notes, app screenshots, `v1.0.0` stable manual-install release,
App Store readiness evaluation (post-stable only).

---

### Milestone exit criteria (not yet satisfied)
- **`v0.9.x` Beta**: private tester onboarding; reproducible core flows across
  setups; documented install/update/rollback for testers; known-issues list;
  feedback intake operational; no open P0 / no unaccepted P1.
- **`v1.0.0` Stable**: documented supported workflows; release notes + known
  issues + install/upgrade guides; lint clean for supported paths; targeted
  automated + manual validation; release decision independent of App Store.

> Done release-prep work (security audit, CI release gate, integration/E2E
> stabilization, deployment checklist) is recorded in [`archive/`](archive/).
