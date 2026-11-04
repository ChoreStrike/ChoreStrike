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
**Status**: Backlog (Round 2, D-089-01; alpha.2 trial feedback 2026-10-24/26; needs operator feedback on design variants before any implementation) · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: task creation
**Owning**: unassigned · **Depends on**: CS-PLAN-080 · **Blocks**: FEAT-UX-050 · **Folded-in**: same mask for New Task and Edit Task with assignees and room (operator, 2026-10-26, CS-PLAN-088 feedback register #11); sub-tasks, assigned members, room picker and mode naming (operator, 2026-11-03, CS-PLAN-089 feedback register)
**Source**: Operator feedback on the New Task dialog (2026-10-24), CS-PLAN-088 feedback register #4

In create mode the form opens with "Start from Template" and a "Repeat" preset
selector (`RecurrencePresetSelector`), while the real recurrence inputs
(Frequency, Every, Scheduling Mode, Flex Days) sit further down under "Schedule &
Recurrence"; the preview repeats "Repeat". Two places for one setting confuse,
and the size and grouping of the controls (six sections in one long scroll) need
a second look.

- [ ] Present two or three variants to the operator (for example presets as
      quick-fill inside the Schedule section; room and assignees first; advanced
      fields collapsed) and record the choice as a decision before coding.
- [ ] Implement the chosen layout once for both entry points (`TaskManager`,
      `RoomDetail`), keeping the `data-testid` hooks the Playwright specs use.
- [ ] Tests: Jest form specs and the affected browser scenarios updated.

**Folded in 2026-10-26 (operator):** New Task and Edit Task should share one
mask, with assignees and room in both. Today there are three: `TaskEditForm` in
create mode (room and assignees), `TaskEditForm` in edit mode in the Tasks tab
(definition only) and `RoomTaskEditModal` in the room view (due date and
assignees).

- [ ] One mask for create and edit in both entry points; edit mode includes the
      assignees (existing per-room-task assignee command) and the room.
- [ ] Changing the room of an existing task needs a backend move command
      (house-scoped, under the room-task lock). Decide what moves along (open
      occurrence, assignees); every completion and journal entry stays with the
      room it happened in. PHPUnit for the move and its history.
- [ ] The edit mask states when the definition is shared by several rooms
      (see FEAT-UX-043).

**Folded in 2026-11-04 (CS-PLAN-089 register G-1, G-2, G-3, T-4):** *Add* also
offers *From template* (FEAT-UX-043). When editing, owners can also edit past
occurrences (FEAT-UX-052); when creating, the creator sets only *Last done on*.
The room is picked with a button, first the floor, then the room. Sub-tasks and
assigned members change only here, no longer in the task details. The family
trial closes first (D-089-06).

- [ ] Sub-tasks and assigned members are edited in the form only.
- [ ] One saved form is one atomic write, so a refusal never leaves it partly
      applied (the partial save of BUG-UX-011).
- [ ] The mode names read clearly. Flex days already work in both modes (T-4
      needs no new behavior), but a mode called "Flexible" suggests otherwise.

Sequencing: land it after BUG-UX-001, FEAT-UX-038 and FEAT-UX-039 so the new
layout is not built twice; show its mockup together with the template picker
of FEAT-UX-043.

---

### [P2] FEAT-UX-041 — Allow completing work before its window opens
**Status**: Backlog (alpha.2 trial feedback 2026-10-26; needs a product decision and a timeline-contract update) · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: completion
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
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

- [ ] Decide: early completion on by default; restriction by an owner house
      setting, by role (children not early), or both; only the next occurrence;
      for a fixed cadence the occurrence counts as done early and the cadence
      stays.
- [ ] Decide the classification and rewards of early work (on time or its own
      class; energy and coins unchanged; streak step on the planned day per C1;
      day-capacity accounting).
- [ ] Update [ADR-013](../adr/ADR-013-occurrence-agreements-and-corrections.md),
      the concepts and `docs/api/` before code.
- [ ] Tests: PHPUnit for early completion on interval and fixed cadence, the
      owner/child rules and the selection; a browser scenario.

BUG-UX-005 covers the in-window case and ships on its own.

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

### [P2] BUG-UX-008 — Recurrence label reads "Daily" for every day interval
**Status**: In Progress (Round 1, D-089-01) · **Effort**: S · **Layer**: frontend · **Journey stage**: task overview
**Owning**: CS-PLAN-089 · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Operator trial feedback on `0.8.0-alpha.3` (2026-11-03), CS-PLAN-089 register D-1

`frequencyLabel()` in `src/utils/taskDisplay.js` ignores `frequencyValue` in its
`daily` branch, while `weekly` and `monthly` handle n > 1. The form preview
already uses the translated `Every {n} days`.

- [x] Task lists show `Every {n} days` for n > 1.
- [x] Regression test in `taskDisplay.spec.js`.

---

### [P2] BUG-UX-009 — Nested `#content` breaks the app frame
**Status**: In Progress (Round 1, D-089-01) · **Effort**: S · **Layer**: frontend · **Journey stage**: every view
**Owning**: CS-PLAN-089 · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Operator trial feedback on `0.8.0-alpha.3` (2026-11-03), CS-PLAN-089 register D-2

`templates/main.php` renders a second `#content` inside the one from Nextcloud's
`layout.user.php`, so the core rule (`position: fixed`, margins, width, height)
applies twice. The frame keeps a gap on the left but reaches the right window
edge, and it extends below the visible area. 44 px stay unused under the header and
the last 36 px of every scroll area never come into view (44 px on a phone), as the
browser test confirmed before the fix.

- [x] The app template keeps only its mount element; `NcContent` positions itself,
      and core's hidden page heading stays.
- [ ] `layout-clearance.spec.ts` pins equal margins, the top edge and a reachable
      end of a long view on both viewports; the primary row passes, the AIO row follows
      with the candidate.
- [ ] Documentation screenshots recaptured.

---

### [P2] BUG-UX-010 — Selects clip their value in the settings
**Status**: In Progress (Round 1, D-089-01; implemented on `fix/alpha4-round1`) · **Effort**: S · **Layer**: frontend · **Journey stage**: settings
**Owning**: CS-PLAN-089 · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Operator trial feedback on `0.8.0-alpha.3` (2026-11-03), CS-PLAN-089 register D-3

A dark area partly covers the value of *Settings → Timezone*. Core fixes the height of
a `<select>`, and `.text-input` adds 12 px block padding, which left 11.5 px for a
17.1 px line in three selects; the task dialog was short too. A `background` shorthand
also removed core's dropdown arrow in eight components.

- [x] Reproduced first, in the browser.
- [x] No select clips its value: one rule on the body, because dialogs are teleported.
- [x] Browser assertion for Settings and the task dialog.

---

### [P2] BUG-UX-011 — Refused due-date and assignee changes answer 500; the dialog saves partly
**Status**: In Progress (Round 1, D-089-01) · **Effort**: M · **Layer**: backend+frontend · **Journey stage**: task editing
**Owning**: CS-PLAN-089 · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Deployment report for `0.8.0-alpha.3`, server log 2026-11-01, CS-PLAN-089 register D-4

`RoomTaskController::updateDueDate()` and `setAssignees()` do not catch
`ConflictException`, so every refusal becomes a 500 with an error-level log entry:
the passed amendment deadline, the child day limit, a stale target.
`TaskController::update()` has the same gap. `bulkAssign()` has none: its service
counts a refused item as skipped.
`RoomTaskEditModal.save()` writes "Last done on" and the due date as two
transactions, so the reported save stored the first and failed on the second,
behind a generic toast. The refusal itself is right (ADR-013): the dialog and the
writer use the oldest open occurrence, which was already missed. A trace of the
case also shows that the documented refusal of "Last done" while overdue runs only
for an interval, not for a fixed cadence (D-088-20, API reference, service docblock).

- [x] The three actions answer 409 with the stable code; the room task controller's
      other mutations are audited against the paths they reach.
- [x] "Last done" is refused on every cadence while an open occurrence has ended
      (D-089-08), so an older miss next to a current window is covered too.
- [x] The dialog shows due-date and assignee refusals inline, says what was saved
      after a partial save and does not record "Last done" twice on retry; the
      assignee chips show the reason too.
- [x] The amendment-deadline message is a reason a person can act on.
- [x] PHPUnit and Jest regressions per the plan; atomic saving follows with
      FEAT-UX-040.

---

### [P2] BUG-UX-013 — A child's My Day hides own tasks above the daily limit
**Status**: In Progress (Round 1, D-089-10; implemented on `fix/alpha4-round1`, cause confirmed by the operator) · **Effort**: S · **Layer**: backend · **Journey stage**: daily execution
**Owning**: CS-PLAN-089 · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Operator report of 2026-11-04, CS-PLAN-089 register D-6

`childDailyRows()` cut the due rows at `maxDailyTasks` in the order `nextDue, createdAt, id`.
The newest tasks fell off first, own tasks included, an unassigned row could take their place,
and the overload banner counts visible rows only. The creation path accepts a task for a child
on a full day, while a later assignment is refused. Contract C2 forbids silently hiding excess
obligations. The operator confirmed that the reported child has a limit.

- [x] Own tasks stay on My Day above the limit; unassigned rows fill only the free slots.
- [x] Regression tests on the real creation path, failing without the fix.
- [x] The operator confirms that the reported child has a limit (2026-11-04).
- [x] Creating a task for a child on a full day stays possible (D-089-11).

---

### [P3] BUG-UX-012 — The setup wizard skips the first upper floor
**Status**: In Progress (Round 1, D-089-01) · **Effort**: S · **Layer**: frontend · **Journey stage**: setup
**Owning**: CS-PLAN-089 · **Depends on**: — · **Blocks**: — · **Folded-in**: numbered floors with a choice of counting (operator, 2026-11-03)
**Source**: Operator trial feedback on `0.8.0-alpha.3` (2026-11-03), CS-PLAN-089 register S-10; numbering defect found in its review

`floorName()` in `SetupWizard.vue` names floors "Ground Floor, 2. Floor, 3.
Floor", which is wrong under either convention. Floor naming differs between
countries, so the operator proposes numbered floors by default, with a choice
whether the first floor above ground counts as 1st or 2nd.

- [ ] Numbered floors by default, with the choice in the wizard, through
      translatable source strings. New setups only; existing floors stay.
- [ ] `SetupWizard.spec.js` covers both countings.

---

### [P2] FEAT-UX-046 — Energy as the only effort measure, shown as bolts
**Status**: In Progress (Round 1, D-089-01; implemented on `fix/alpha4-round1`) · **Effort**: M · **Layer**: backend+frontend · **Journey stage**: task creation
**Owning**: CS-PLAN-089 · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Operator trial feedback on `0.8.0-alpha.3` (2026-11-03), CS-PLAN-089 register T-1, T-2

Estimated time goes; energy stays the only effort measure at 1 energy ≈ 10
minutes, with room for longer tasks. The catalog already fits that scale (medians
of about 10, 15, 25 and 45 minutes for energy 1–4), so only the concept, which
says 5 minutes, changes; no value is converted. The limit rises to 20, the upper
bound of a daily budget (D-089-07). Estimated minutes feed no calculation, so the
column and the API field can stay, which keeps every persisted definition fact and
needs no migration.

- [x] The server accepts 1–20 through one constant, which the ledger's guard uses too;
      the unused `energy_max_per_task` config key goes.
- [x] The form offers 1–5 as bolts plus a custom value up to 20, shown as a bolt
      with a number, and explains a task above a person's daily budget.
- [x] Estimated time leaves the form, the template picker and the task list.
- [x] Concept, API reference, user guide and help follow; tests per the plan.

---

### [P2] FEAT-UX-047 — One task row everywhere
**Status**: In Progress (Round 1, D-089-01) · **Effort**: M · **Layer**: frontend · **Journey stage**: task overview
**Owning**: CS-PLAN-089 · **Depends on**: FEAT-UX-046 · **Blocks**: — · **Folded-in**: removal of the stale *Teamwork bonus* label (review of the feedback, 2026-11-03); *Next appointments* follows the filter (operator decision, 2026-11-04)
**Source**: Operator trial feedback on `0.8.0-alpha.3` (2026-11-03), CS-PLAN-089 register G-7

The same layout wherever a task appears: energy in colour, no clock, flex days
directly after the recurrence ("Every 3 days ±2") instead of a separate display,
floor and room below the title. `TaskListItem` and `TodayView` render their rows
separately today. The *Teamwork bonus* label contradicts ADR-014, which retired
multipliers: shared work splits the reward.

- [ ] One presentational component serves My Day, Tasks and the room view.
- [ ] `FlexWindowDisplay` goes with its only consumer, and with its TECH-LINT-009
      exemption.
- [ ] The energy colour passes the contrast check in both themes.

---

### [P3] FEAT-UX-048 — Remove the catch-up agreement action and Quick clean
**Status**: In Progress (Round 1, D-089-01) · **Effort**: S · **Layer**: frontend · **Journey stage**: daily execution
**Owning**: CS-PLAN-089 · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Operator trial feedback on `0.8.0-alpha.3` (2026-11-03), CS-PLAN-089 register C-3, T-6

Only these two actions go. The catch-up status stays: a missed chore keeps its
original date and can still be completed, and already agreed appointments keep
showing. Retained `manual_delta` entries keep replaying. The two routes stay until
FEAT-UX-050 (D-089-09).

- [ ] The form goes from `TaskListDetails`, the control and its store action from
      `RoomCard` and the rooms store.
- [ ] Refusal texts, help, concepts and the user guide stop offering the actions.
- [ ] The hold-button coverage of `family-core-ux.spec.ts` moves to *Done*.

---

### [P3] FEAT-UX-049 — Floors & Rooms list: rename, task count, one expand control
**Status**: In Progress (Round 1, D-089-01) · **Effort**: M · **Layer**: frontend · **Journey stage**: settings
**Owning**: CS-PLAN-089 · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Operator trial feedback on `0.8.0-alpha.3` (2026-11-03), CS-PLAN-089 register S-7, S-8

Every room in the list gets *Rename room* and shows its number of tasks. The expand
control and the reorder arrows both use chevrons today, and the expand style differs
between menus.

- [ ] Rename reuses the dialog of `RoomDetail`; the count comes from the house's room
      tasks in the owner-only section, without a new API field.
- [ ] One expand control, a right chevron turning 90° when open, in every menu;
      reorder controls use arrows.

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
      Catch-up, Pause, Streak, Quiet time, Ranking, Arcade, Coins).
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
**Status**: Backlog (Round 2, D-089-01; alpha.2 trial feedback 2026-10-26; operator feedback on a mockup before implementation) · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: task creation
**Owning**: unassigned · **Depends on**: CS-PLAN-080 · **Blocks**: — · **Folded-in**: *From template* in the one form (operator, 2026-11-03, CS-PLAN-089 feedback register)
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

- [ ] Decide the handling of existing shared definitions on the retained
      baseline. Recommended: no bulk rewrite; editing a definition mounted in
      several rooms asks "this room only / all rooms", and "this room only"
      splits off a copy for that room task while every completion, occurrence
      and journal row stays valid. New setups create one definition per room.
- [ ] Picker per the sketch, shown as a mockup first (together with FEAT-UX-040).
- [ ] Catalog content: Putzteufel a strict superset of Putzmuffel with equal or
      higher frequencies (enforced by a catalog test); new sets for pets and
      garden, plus proposals for the operator (e.g. plants, laundry, car/bike);
      all strings through `t()` with `l10n/`.
- [ ] Tests: catalog invariants, per-room definitions on setup, split-on-edit
      keeping history, picker specs.

---

### [P2] FEAT-UX-050 — Postpone, direct handover and no separate details view
**Status**: Backlog (Round 2, D-089-01) · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: daily execution
**Owning**: unassigned · **Depends on**: CS-PLAN-080, FEAT-UX-040, FEAT-UX-051 · **Blocks**: — · **Folded-in**: —
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

- [ ] ADR-013, the task-model concept and the API reference describe postponement
      before code.
- [ ] Postpone, handover button and the restart home implemented; PHPUnit for the
      window, the limit, the target and the rights; browser scenario.
- [ ] The details view goes; checklist ticking moves to FEAT-UX-051 and editing to
      FEAT-UX-040.
- [ ] The routes without a client go: `PUT /rooms/{id}/cleanliness` and
      `occurrence#catchUp` (D-089-09). Journal replay and read paths stay.

---

### [P2] FEAT-UX-051 — Completion dialog: record for someone else, sub-tasks on completion
**Status**: Backlog (Round 2, D-089-01) · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: completion
**Owning**: unassigned · **Depends on**: CS-PLAN-080 · **Blocks**: FEAT-UX-050 · **Folded-in**: —
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

- [ ] ADR-014, the concepts and the API reference state the new rule before code.
- [ ] Marking all sub-tasks runs inside the completion command, so it stays atomic
      and undo stays correct.
- [ ] The tap replaces today's hint in `HoldButton`, with keyboard parity.
- [ ] PHPUnit for recording for others, private-task visibility and undo; Jest and a
      browser scenario for tap and hold.

---

### [P2] FEAT-UX-052 — Task history with owner corrections
**Status**: Backlog (Round 2, D-089-01) · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: review and correction
**Owning**: unassigned · **Depends on**: CS-PLAN-080 · **Blocks**: — · **Folded-in**: —
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

- [ ] ADR-014 and the concepts describe the date correction before code.
- [ ] History view per task, reachable from the one form (FEAT-UX-040).
- [ ] Tests for all three corrections across coins, rhythm, weekly goal and room.

---

### [P3] FEAT-UX-053 — Owner-managed task categories
**Status**: Backlog (Round 2, D-089-01) · **Effort**: M · **Layer**: backend+frontend · **Journey stage**: task creation
**Owning**: unassigned · **Depends on**: CS-PLAN-080 · **Blocks**: FEAT-UX-058 · **Folded-in**: —
**Source**: Operator trial feedback on `0.8.0-alpha.3` (2026-11-03), CS-PLAN-089 register T-3, S-4

Owners manage the categories in the household settings. *Other* always exists, cannot
be deleted, is the default and is the fallback for a deleted or unknown category.
`tasks.category` is a free string without server validation today, with six fixed
values in `TaskEditForm`, and it appears nowhere but the form. They therefore get a
purpose (D-089-04): a filter in the Tasks list and an evaluation in the statistics.

- [ ] Decide the storage: a typed app-config list needs no schema step, since there is
      one house per instance (the ADR-019 pattern); a table would be a migration.
- [ ] Tasks keep stable keys; unknown values read as *Other* and nothing rewrites
      retained rows.
- [ ] Filter, settings section and tests.

---

### [P3] FEAT-UX-054 — Personal and household settings structure
**Status**: Backlog (Round 2, D-089-01) · **Effort**: M · **Layer**: frontend · **Journey stage**: settings
**Owning**: unassigned · **Depends on**: CS-PLAN-080 · **Blocks**: — · **Folded-in**: —
**Source**: Operator trial feedback on `0.8.0-alpha.3` (2026-11-03), CS-PLAN-089 register S-1, S-2, S-3, S-5

Separate personal from household settings and group them into categories that start
collapsed, so the page fits one screen. *Notifications* becomes one category, and
*House Name* and *Timezone* join *Floors & Rooms* in a household category.

Source-project answer (2026-11-03): collapsible sections work with two levels, not
three, which get unwieldy on a phone: a switch *Personal | Household* on top, categories
below. Non-owners see only *Personal*. `Settings.vue` has 1440 lines against the ~300
guideline and is split per category along the way.

- [ ] Structure shown as a mockup first.
- [ ] Browser specs open collapsed categories through one helper; help links open
      their target category.

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

### [P3] FEAT-UX-056 — Owner controls for the arcade
**Status**: Backlog (Round 3, D-089-01) · **Effort**: M · **Layer**: backend+frontend · **Journey stage**: arcade
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Operator trial feedback on `0.8.0-alpha.3` (2026-11-03), CS-PLAN-089 register S-6

Owners change the unlock threshold of each game and reset a person's unlock progress
to start a new round, only after a confirmation prompt. This amends ADR-019, which
keeps thresholds as one constant map, treats unlocks as permanent and stores nothing.

- [ ] ADR-019 amended before code.
- [ ] Thresholds become typed app config, as the gate switch already is. A reset stores
      a start point per person from which finished chores count; the work ledger stays
      untouched and highscores stay (D-089-05).
- [ ] Owner-only checks on both layers; tests for a reset, an undo across the start
      point and the score lock.

---

### [P3] FEAT-UX-057 — Selectable colour schemes
**Status**: Backlog (Round 3, D-089-01) · **Effort**: M · **Layer**: frontend · **Journey stage**: settings
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Operator trial feedback on `0.8.0-alpha.3` (2026-11-03), CS-PLAN-089 register L-1

About 4–6 selectable schemes, each working in the light and the dark theme. Hard-coded
colours remain in about a dozen components outside `tokens.css`, mostly scheduling and
the calendar, and move to tokens first.

- [ ] Decide personal or household scope; personal fits the split of FEAT-UX-054.
- [ ] Contrast checked per scheme and theme.

---

### [P3] FEAT-UX-058 — Household statistics
**Status**: Backlog (Round 3, D-089-01) · **Effort**: M · **Layer**: frontend · **Journey stage**: review and correction
**Owning**: unassigned · **Depends on**: FEAT-UX-053 · **Blocks**: — · **Folded-in**: —
**Source**: Operator trial feedback on `0.8.0-alpha.3` (2026-11-03), CS-PLAN-089 register T-5

Which tasks get done regularly and which do not, also by category (D-089-04). The operator
would like this area to grow. It compares tasks, not people, so ADR-015 does not apply, and
it reads the existing occurrence history.

- [ ] Insights shows the first evaluations; the definitions of "regular" are written down.

---

### [P3] FEAT-UX-059 — Planned date and scheduler rules
**Status**: Backlog (later, D-089-01; the operator wants the scheduler discussed separately) · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: scheduling
**Owning**: unassigned · **Depends on**: FEAT-UX-050 · **Blocks**: — · **Folded-in**: —
**Source**: Operator trial feedback on `0.8.0-alpha.3` (2026-11-03), CS-PLAN-089 register T-7

The report proposes a separate *scheduled* date per task, set by the scheduler and allowed
outside the flex days when no valid plan exists otherwise. Source-project answer
(2026-11-03): no second date on the task. The occurrence already separates obligation
(window) and plan (`planned_date`). The planner sets the planned day inside the window,
reusing the path of FEAT-UX-050; when nothing fits, it proposes an agreed amendment through
the existing date writer, which keeps its evidence row. Today `SchedulePlanService` allows
only dates inside the window.

- [ ] Scheduler discussion with the operator; the decision recorded before a plan.

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
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: 2026-10-11 core-feedback gate fixture-seed connection closures; 2026-10-14 CS-PLAN-084 WP-B/WP-E/WP-F/WP-C focused-run closures; 2026-10-15 CS-PLAN-084 full-gate closures with access-log correlation; 2026-10-18 CS-PLAN-087 interim gates (NC 35 seed `fetch failed` under foreign host load, NC 34 AIO `socket hang up` on `GET /api/v1/users` in `family-rewards`, no Apache error; both passed in isolation); 2026-10-18 CS-PLAN-086 WP-F staged row: one failure of `arcade-games` "a crafted score for a locked game is refused" (a seed and one POST, no WP-F code on its path; the error text was not captured, so it stays unattributed), green in three later runs; 2026-10-20 CS-PLAN-088 WP-B gates: three full primary-row gates and one AIO-row gate each lost one to three required scenarios (`settings`, `mobile-core` handover, `calm-core-ui`, `release-task-lifecycle`) to seed/API `fetch failed` before any assertion, with no Apache error, OOM or restart and a foreign Testcontainers run active on the shared Docker host; every one passes in isolation. Two non-transport scenarios failed once and passed in isolation too (`mobile-core` webkit navigation to Settings, `arcade-mobile` Crumb Snake reaching *over* before the steer), also unattributed
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

### [P3] TECH-LINT-009 — Boolean props that default to `true`
**Status**: Backlog · **Effort**: S · **Layer**: frontend · **Journey stage**: —
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: CS-PLAN-088 D-088-10 (operator, 2026-10-20)

`vue/no-boolean-default` of the Nextcloud ESLint preset wants Boolean props to
default to `false`. Six components default one to `true` and are exempted
file by file in `eslint.config.js`: `ErrorState` (`showRetry`), `Modal`
(`show`), `HouseHealthBar` (`showRefreshButton`), `QuickStats`
(`showActions`), `SetupWizard` (`show`) and `FlexWindowDisplay`
(`showStatus`). Inverting them (for example `hideRetry`) changes each
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

### [P2] TECH-RUNTIME-003 — Promote AIO v14.4.3 and the moved PHP image
**Status**: In Progress (Round 1, D-089-01; inventory promoted, `test:runtime:check` reports `current`, the AIO row and browser gates follow with the candidate) · **Effort**: M · **Layer**: tests · **Journey stage**: —
**Owning**: CS-PLAN-089 · **Depends on**: — · **Blocks**: RELEASE-002 (next deployment) · **Folded-in**: —
**Source**: Weekly `runtime-check` run of 2026-11-02, drift `aio-bundle` and `moved-php-image`

AIO v14.4.3 bundles the same Nextcloud 34.0.5 and components, and the multi-arch index
behind `php:8.4.27-apache` moved while its `linux/amd64` digest stayed. The project rules forbid deploying to a runtime the inventory
does not name, so the promotion comes first, as in RF-088-11.

- [ ] Reviewed inventory update, image and compose pins, spec hashes and docs.
- [ ] Both runtime rows requalified; the check reports `current` at the freeze.

---

### [P3] TECH-LOG-001 — Expected owner refusals fill the log; entries lack the `app` key
**Status**: In Progress (Round 1, D-089-01) · **Effort**: S · **Layer**: backend+frontend · **Journey stage**: scheduling
**Owning**: CS-PLAN-089 · **Depends on**: — · **Blocks**: — · **Folded-in**: missing `app` key in `AppLogger` (deployment report, 2026-11-03)
**Source**: Deployment report for `0.8.0-alpha.3`, server log 2026-10-29, CS-PLAN-089 register D-5

`initializeScheduling()` fetches the owner-only member availability for every user, so each
non-owner visit to *Scheduling* writes `Owner role required` at warning level, which hides
real warnings. Separately, `AppLogger` sets no `app` key, so entries show `no app in context`
and the post-deployment log check cannot filter by app.

- [x] Only owners fetch the member availability; a store spec covers the roles.
- [x] `AppLogger` adds the `app` key; `AppLoggerTest` covers it; the deployment guide
      names the filter.

---

## Release

> Stage path: `v0.8.x` alpha → `v0.9.x` beta → `v1.0.0` stable (manual install).
> App Store submission is explicitly deferred until stable quality is proven.

### [P2] RELEASE-002 — Beta deployment + real-world alpha usage
**Status**: In Progress (`0.8.0-alpha.3` deployed 2026-10-29 and its CS-PLAN-088 slice finished; CS-PLAN-089 activated for the alpha.4 slice; field use and later beta remain open) · **Effort**: M · **Layer**: — · **Journey stage**: release
**Owning**: CS-PLAN-089 WP-F (alpha.4 release slice only); CS-PLAN-080 (family evidence, alpha tags); later beta onboarding unassigned · **Depends on**: — · **Blocks**: RELEASE-003 · **Folded-in**: retained-data trial readiness and safe recovery boundary (2026-10-03 user request); concrete AIO installation handoff/runbook gaps (2026-10-07); bounded remaining build/test dependency disposition (2026-10-08 user decision); alias-free and deterministic package qualification gates (CS-PLAN-083, 2026-10-10)
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
