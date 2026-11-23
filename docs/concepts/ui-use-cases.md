# UI Use Cases & GUI Element Inventory

## Current Workflow (WP9, September 2026)

This matrix supersedes the August endpoint audit below for shipped core
behavior. The older audit remains historical context, not a promise that
its pruned routes or superseded write bodies are available.

| Surface | Current behavior and authority |
|---|---|
| First owner and setup | First eligible bootstrap joins the owner; Settings/setup wizard writes the complete house/floor/room/template aggregate once. Later users require invitations. |
| Invitations and roles | Owners manage membership; members manage visible tasks; children execute tasks and their own availability but do not manage definitions. |
| Task creation | TaskManager's *New Task* and *New* in a room heading of its list by room open the one form (CS-PLAN-091 WP-B6, FEAT-UX-040); *From template* fills it from the setup catalog, the chosen room's type first. `POST /room-tasks` creates the whole aggregate — definition, sub-tasks, room task, assignees, first date and sleep — in one atomic write. |
| Room task editing | One form for a new and an existing task: *Edit* in the opened row opens it, and one save is one atomic `PUT /room-tasks/{id}` of the definition, sub-tasks, room, assignees, first date and sleep (BUG-UX-011). A definition several rooms share asks *Only this room* or *All rooms* first (FEAT-UX-043). The first date moves the first window until the first completion; afterwards the form shows the next window read-only (ADR-021). Description, instructions, priority, category and visibility sit under a collapsed *More* (D-091-85); the form deletes the task after a confirmation (D-091-86). It shows member-load errors and retries without discarding input. Children never open it. |
| Handover | There is no handover of a single appointment any more (ADR-021 §13): assignees change in the task, and anyone may record work for someone else. A held Done button completes a task for its assignees at once; a short tap opens the picker for who did it and which sub-tasks are done (CS-PLAN-091 WP-B2). |
| Postpone, history | The opened row — the same on Today and in the task list (CS-PLAN-091 WP-B6, FEAT-UX-050) — offers **Postpone** to every member (up to three times per cycle, not for a red task or one without flex days) and the task's history: who did it when, with **Change date** and **Delete** on the entries the viewer may correct — the owner any, everyone else their own latest (CS-PLAN-091 WP-B3). Insights lists postponements, date changes, deletions and absences between the completions. |
| Task creation readiness | Task Manager enables New Task only after the initial definition/instance/count reads succeed. A failed load requires retry first, so creation cannot race those initial snapshots or appear to disappear after a successful write. The aggregate response still renders the task without a post-create refetch. |
| Task discovery | Task Manager defaults to the current person's assigned tasks plus unassigned work. My tasks / Unassigned / Everyone are explicit buttons and the presets of *People*: a tick per member and one for unassigned work, with *All* and *None*, so the buttons and the ticks always say the same. Below them a quick filter takes a floor or one of its rooms. The detail filters (status, priority, energy, visibility, category, search) sit behind *Filters* (GUI review of CS-PLAN-091 WP-B6, D-091-106). Assignment names are visible without expansion. These are display filters, never authorization changes. |
| Upcoming appointments | The calendar shows them. Today no longer lists the next agreed appointments (CS-PLAN-090 WP-B, D-090-11). |
| Completion participation | Initial completion requires the signed-in actor to have a positive actual-work share. The dialog refuses missing identity or an excluded actor with an explanation; attribution correction intentionally does not require the correcting owner to participate. |
| Scheduling | Nothing is saved as a plan (ADR-022, CS-PLAN-091 WP-B1 and WP-C). The server computes a live suggestion from the windows, the members' energy and preferred days and the absences: *Today* shows what it places on today, the calendar's week the next 14 days with the load. Today's list is stored once a day, so it does not jump. The weekly plan's page is gone; its address leads to the calendar. |
| Completion and undo | One complete receipt freezes the work evidence and updates wallet/contribution/streak/room/checklist projections before events (WP3 minor units, ADR-014). The queued targeted undo reverses the latest selected allocation from the valid history, including after reload; medals and sent notifications are not revoked. The Insights history exposes the authorized correction (completer/owner `canCorrect`) reusing the participation form; a 409 keeps the draft. |
| Child/mobile | The bare root lands on Today for every role. Focused member/child mobile tests retain 44px controls, role gating and checklist execution. |
| Localization | Real Nextcloud DE and EN rendering is tested in `family-core-ux.spec.ts`; source/catalog ownership and artifacts are enforced globally. |
| Arcade | Five household games on one shell (Spot Sprint, Sock Match, Crumb Snake, Tile Breaker, Dust Runner), scores only and per game; access unlocks from finished chores unless an owner switches the gate off (CS-PLAN-086, ADR-019). The server `ArcadeCatalog` is the only game list. |

Contracts: [API](../api/README.md), [task/undo model](task-model-and-sync.md),
[testing and deterministic gate](../engineering/testing.md).

## Approved Calm UX and Evidence C5 (2026-09-17)

Implemented by WP4-WP8 (ADR-015/016, [calm core UX](calm-core-ux.md)). The
[clarification plan](../plans/done/cs-plan-070-family-rhythm-and-reward-clarification.md)
records the thirteen explicit C5 answers. The family evidence (C5j) remains open in
[CS-PLAN-080](../plans/active/cs-plan-080-wp9-cross-db-and-family-evidence.md).

| Surface | Approved target |
|---|---|
| Entry after setup | Today for adults and children, the one landing page: the own status (open energy against the day, weekly goal, streak, Coins), overdue work first, today's due tasks and what is already possible. Keep planning, household and open requests reachable, without making arcade/catalog/rankings the primary area. The Dashboard merged into Today; `/dashboard` redirects (CS-PLAN-090 WP-B). |
| Catch-up/overload | Separate catch-up without stacks of identical cards; show overload as a clarification need, not a demand to clear all accumulated work. |
| Progress | Small confirmed personal streak/shared weekly progress line, with pending/provisional and neutral states distinguished. A free day is a neutral empty view, not an invitation to manufacture work. |
| Completion | Short visible confirmation with undo access and no interrupting award modal. Sound requires a personal opt-in; the celebration animation is on by default since CS-PLAN-090 (D-090-05) and each person can switch it off. |
| Accessibility | OS reduced-motion overrides animation choices. Errors, status and keyboard focus remain clear without sound/motion; preserve desktop/mobile/keyboard coverage. |
| Preferences | Server-side per person/house across devices. Each person, including children, controls notification categories, sound/motion and optional-area visibility. No owner opt-in on another person's behalf or shared-device cross-account carryover. |
| Catalog | Household owner enablement remains necessary; personal hiding does not hide authorized management of existing requests under C4. |

### Consent and Rankings

- Notifications default off and share the
  [C5 delivery contract](task-model-and-sync.md#approved-delivery-contract-c5-2026-09-17).
  Opt-out immediately stops unsent notices; departure invalidates opt-ins and
  rejoin starts quiet/off. Financial retention under C4 is not retained consent.
- Rankings remain off by default. Household owner enablement only exposes the
  option; each member must consent themselves. Children may refuse or withdraw.
- After consent, scoring begins next Monday 00:00 in household time, never the
  current partial week. Show pending participation without a rank until then.
  There is no retroactive import of earlier tasks.
- Compare current-week on-time fulfillment percentage of eligible personal
  agreements from household-public tasks only, following C1/C2 amendments and
  week attribution. Open windows make the result provisional. Do not expose private
  tasks through aggregates, rank by Coins/speed, or use those as tie-breakers.
  Equal percentages tie; zero opportunities is neutral/unranked.
- Withdrawal removes the person from visible rankings. Withdrawal, household
  ranking disablement or rejoin requires fresh personal consent and another
  next-full-week start. Shared activity is separate; C4 request privacy still applies.

### Family Acceptance Protocol

- After working implementation, conduct a voluntary guided comprehension test
  with at least one adult and one child, with guardian consent for minors,
  followed by two weeks of voluntary everyday use. This specifies a future gate,
  not authorization to contact people or collect their data during clarification.
- Both participants should find their next agreed task, explain progress and
  neutral days, and disable optional features without operating help. Exercise
  pause, catch-up, handover and reward workflows with test cases where needed;
  never provoke real misses or unnecessary chores for evidence.
- Technical tests separately cover desktop/mobile and keyboard. Record anonymized
  feedback on perceived pressure and organizing effort, not engagement targets.
  No names, child-identifying data or sensitive raw notes in public evidence;
  any necessary private material stays in the gitignored private area.
- Missing testers or trial results leaves the required evidence open. Record
  comprehension failures and pressure problems as findings for resolution, not
  an assumed passing result. Do not substitute automated UI tests for the family
  trial without explicit rescoping.

## Historical Audit (August 2026)

## Purpose

Define **which GUI elements ChoreStrike needs, when and where** — organized by
use case / workflow, with every user-facing action mapped to its backing API
endpoint and its implementation status. This is the working reference for
deciding what to build next and what stays backend-only for now.

Grounded in:
- Endpoint audit 2026-08-21 (all 111 API routes in `appinfo/routes.php`
  checked against the frontend; `page#index` excluded; 56 routes then had no
  live frontend consumer) — **re-audited 2026-08-27 (CS-PLAN-053)**: route set
  unchanged at 110; 42 routes currently have no production consumer
  (30 none + 12 test-only; full matrix in
  [`../backlog/archive/CODE_REVIEW_2026-08-27.md`](../backlog/archive/CODE_REVIEW_2026-08-27.md) L3-0).
  **Prune executed 2026-08-29 (CS-PLAN-055)**: the 39 no-consumer + test-only
  routes were **deleted** (kept: 67 production + 3 `/test/*` E2E harness +
  `page#index` = 71 total); the mirroring dead store-getter/action layer in
  `src/store/*` was removed with them. The expose-or-prune decision that
  previously left these recorded neutrally (CR-053-14) is now resolved to
  prune; re-adding a route requires a consumer and an updated `RouteMatrixTest`
  pin. Pruned rows below are marked ⛔ (was ❌ dead-wrapper).
- Usability review 2026-08-20 (findings **UX-001 … UX-014**,
  [`../backlog/archive/USABILITY_REVIEW_2026-08-20.md`](../backlog/archive/USABILITY_REVIEW_2026-08-20.md))
  re-verified after CS-PLAN-038 (Today view, unified task creation, members UI).

## How to read

**Current roles:** *Owner* (household management), *Member* (task management),
and *Child* (execution only). Historical rows that call children "members"
pre-date the distinct child policy; use the current matrix above.

**Status per action:**

| Symbol | Meaning |
|---|---|
| ✅ | Wired: UI element + endpoint, reachable in the running app |
| ⚠️ | Impaired: present but dead, hidden, duplicated, or not persisting what it shows |
| ❌ | No UI: endpoint exists, frontend missing (a store wrapper may exist as dead code) |
| ⛔ | Nothing: neither endpoint nor UI |

**Where** names the mounting view/component (`src/views`, `src/components`).
`Endpoint: client state` means the surface renders locally computed state
without a dedicated endpoint call.

---

## Use case map

| UC | Use case | Primary surfaces |
|---|---|---|
| 01 | First-run setup & house creation | TodayView → SetupWizard |
| 02 | House administration | Settings, InsightsView |
| 03 | Structure: floors | Settings |
| 04 | Structure: rooms | Settings, TaskManager (list by room) |
| 05 | Task definitions (catalog) | TaskManager |
| 06 | Task creation (materialize room tasks) | TaskManager |
| 07 | Task lifecycle maintenance | TaskManager, TaskForm |
| 08 | Daily execution (Today) | TodayView, TaskList |
| 09 | Assignment & teamwork | TaskForm, the completion picker |
| 10 | Planning: availability, energy, preferred days, the suggestion | Settings → Planning (own days, energy and preferred days), Settings → Household → Planning of the members (owner), CalendarView (the suggestion) |
| 11 | Progress & gamification | TodayView (status line), InsightsView (ranking section), Settings |
| 12 | Minigames | ArcadeCatalog (optional Arcade view) |
| 13 | Members, roles & household changes | Settings → MemberList |
| 14 | Notifications & reminders | (passive — no surface yet) |
| 15 | Calendar overview & planning horizon | CalendarView |

---

## UC-01 — First-run setup & house creation

**Goal:** new household is playable within minutes. **Personas:** Owner.
**Entry points:** app open with empty house → SetupWizard (auto, on Today); *Set up your home* on Today and under *Floors & Rooms* in Settings while the house has no floors (`SetupWizardEntry`, CS-PLAN-090 D-090-12).

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Auto-create house on first open | first authenticated read (`App.vue` membership bootstrap) | `GET /house` | ✅ |
| Guided wizard: house name, home type, floor/room counts, cleaning level | SetupWizard step 1 | `GET /setup/catalog` | ✅ |
| Room list editing (rename, type, person per room, add/remove) | SetupWizard step 2 | client state | ✅ |
| Preview (floors/rooms/≈tasks) + apply | SetupWizard step 3 | `POST /setup/apply` | ✅ |
| "Start empty" skip | SetupWizard | localStorage flag (per house) | ✅ (persistent since CS-PLAN-042; manual entry point stays) |
| House naming step | SetupWizard step 1 → apply | `POST /setup/apply` + `PUT /house` | ⚠️ two-step save; a failed name update is logged but setup still reports success (workflow review 2026-09-02) |
| Per-room assignee dropdown | SetupWizard step 2 | client state | ✅ (hidden while the house has a single member, CS-PLAN-042) |
| "Invite your family" final step (step 4, after apply) | SetupWizard | `POST /users/invite` | ✅ (since CS-PLAN-038) |

## UC-02 — House administration

**Goal:** rename, monitor, reset the household. **Personas:** Owner (member
visibility should be read-only). **Entry points:** Settings; Insights.

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Rename house | Settings | `PUT /house` | ⚠️ silent save, no toast (UX-006) |
| House health bar | — | — | ⛔ removed with the Dashboard (CS-PLAN-090 WP-B, D-090-11); house health shows in the Insights statistics, which compute it on read; the refresh route is pruned (CS-PLAN-091 RF-091-11) |
| Household weekly goal | TodayView status line (`TodayStatusLine`) | `GET /game/state` | ✅ (CS-PLAN-090 WP-B; the Dashboard bar is gone) |
| House statistics | InsightsView `HouseStatsCards` | `GET /house/stats` | ✅ (live since CS-PLAN-040; wrapper renamed to `fetchInsights`) |
| Reset house (typed `RESET` confirm) | Settings danger zone | `POST /house/reset` | ✅ server owner-gated |
| Personal and household areas with collapsible categories (FEAT-UX-054) | Settings: switch *Personal \| Household*, one component per category, all closed at first; `?category=` opens one and names the one opened last | — | ✅ (CS-PLAN-090 WP-D, D-090-18, D-090-47) |
| Role-gate owner controls in Settings | Settings: the household area exists for the owner only, also by a link | — | ✅ (CS-PLAN-042: house/floors/danger/members sections hidden for members — no 403 paths) |

## UC-03 — Structure: floors

**Goal:** model the home's storeys. **Personas:** Owner. **Entry points:**
Settings (sole CRUD+reorder surface).

| Action | Where | Endpoint | Status |
|---|---|---|---|
| List floors with rooms | Settings structure section | `GET /floors/with-rooms` | ✅ (the read-only Dashboard `FloorList` is gone, CS-PLAN-090 WP-B) |
| Add floor (modal: name) | Settings | `POST /floors` | ✅ (single surface since CS-PLAN-042) |
| Rename floor (modal) | Settings | `PUT /floors/{id}` | ✅ (styled confirm/toasts; CS-PLAN-041/042) |
| Delete floor (styled confirm) | Settings | `DELETE /floors/{id}` | ✅ (CS-PLAN-041) |
| Reorder floors (up/down buttons) | Settings floor rows | `POST /floors/reorder` | ✅ (CS-PLAN-042) |
| Expand/collapse a floor | Settings room list | client state | ✅ |

## UC-04 — Structure: rooms

**Goal:** model rooms and their state. **Personas:** Owner. **Entry points:**
Settings (create, rename, delete); the task list by room shows a room's state.

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Add room (inline name input per floor row) | Settings structure section | `POST /rooms` | ✅ (CS-PLAN-042; icon defaults, the old FloorList icon-picker modal was removed with the duplicate surface) |
| Rename room | Settings floor room list, which also counts the room's active tasks (the room page and its header went with CS-PLAN-090 WP-C) | `PUT /rooms/{id}` | ✅ (CS-PLAN-042, FEAT-UX-049) |
| Delete room (styled confirm) | Settings floor room list | `DELETE /rooms/{id}` | ✅ (CS-PLAN-042) |
| Cleanliness change (Quick clean +10) | — | — | ⛔ control removed (FEAT-UX-048), route pruned (CS-PLAN-091 WP-B7, D-089-09); retained journal entries keep replaying |
| "Rooms needing cleaning" list | — | `GET /rooms/needing-cleaning` | ⛔ pruned (CS-PLAN-055 — route + dead `fetchRoomsNeedingCleaning` wrapper gone) |
| Open a room | TaskManager → *By room*: floors are headings, rooms collapsible rows; `/room/:id` redirects to `/tasks?group=room&room=:id` (CS-PLAN-090 WP-C) | the house-wide `GET /room-tasks` of the list | ✅ |
| Room facts (open and overdue counts, cleanliness percentage, *Last cleaned*) | room heading of the list by room | `GET /floors/with-rooms` + the list's rows | ✅ (CS-PLAN-090 WP-C, D-090-19; the room cards went with WP-B) |
| Task stats card (total/active/overdue/due-today/completion) | — | — | ⛔ card removed with the room page (CS-PLAN-090 WP-C, D-090-11), route pruned (CS-PLAN-091 WP-B7, D-090-10) |

## UC-05 — Task definitions (catalog)

**Goal:** manage reusable task definitions. **Personas:** Owner. **Entry
points:** TaskManager.

| Action | Where | Endpoint | Status |
|---|---|---|---|
| List definitions | TaskManager | `GET /tasks` | ✅ |
| Create definition (+ materialize room task, see UC-06) | TaskManager → `TaskForm` | `POST /room-tasks` | ✅ (one form, CS-PLAN-091 WP-B6; `POST /tasks` pruned in CS-PLAN-063) |
| Edit definition | task row → *Edit* → `TaskForm` | `PUT /room-tasks/{id}` | ✅ (CS-PLAN-091 WP-B6; `PUT /tasks/{id}` pruned) |
| Delete definition | — | `DELETE /tasks/{id}` | ⛔ pruned (CS-PLAN-055) |
| Search definitions | — | `GET /tasks/search` | ⛔ pruned (CS-PLAN-055) |
| Browse by category / categories | — | `GET /tasks/category/{c}`, `GET /tasks/categories` | ⛔ pruned (CS-PLAN-055) |
| Private (personal) task list | TaskManager visibility filter (the Dashboard chip is gone, CS-PLAN-090 WP-B) | `GET /tasks` + client-side `?visibility=private` scope | ✅ (CS-PLAN-041; `GET /tasks/my-private` was pruned in CS-PLAN-055 — the client-side filter is the only path) |
| Task-level complete/skip/reschedule/daily | — | legacy `PUT /tasks/{id}/…`, `GET /tasks/daily` | ⛔ pruned (CS-PLAN-055; RoomTask equivalents are the live completion path; `PUT /tasks/{id}/uncomplete` stays as the undo primitive) |

### `/tasks` query presets (router contract, CS-PLAN-084 WP-C/WP-D, CS-PLAN-090 WP-C)

Links into the TaskManager may preset its filters once on entry; unknown
values keep the defaults, and the filters stay freely changeable afterwards.
The list shows client-side pages of 20 compact rows (D-084-02); `page` mirrors
the current page, is dropped on page 1 and resets whenever a filter changes. The
scope buttons with *People* and the floor/room quick filter stay visible; the detail filters sit in a panel behind a *Filters*
toggle (with an active-filter count) that starts collapsed on phones (≤ 768 px).
The default view issues one active-only house read; the *Completed* status
loads finished (inactive, never deleted) tasks on demand with one extra
`activeOnly=false` read each time it is selected (D-084-07).

A switch groups the list *By due date · By room · By person* (CS-PLAN-090
WP-C). By due date is the paged list, missed work first, with a heading per
due class. By room, floors are headings and rooms collapsible rows with their
open and overdue counts, a small cleanliness percentage and *Last cleaned*; by
person, each member with tasks is such a row, the unassigned tasks last. One
group is open at a time and its rows render when it opens, so the grouped
lists have no pager. A grouped list starts with everyone's tasks unless the
link names a `scope` (D-090-41); the other filters apply to every grouping,
and the same single house read feeds all three.

| Query | Values | Effect |
|---|---|---|
| `scope` | `mine`, `unassigned`, `all` | scope buttons (My tasks / Unassigned / Everyone) |
| `status` | `active`, `overdue`, `due-today` | status filter (same rules as the select: overdue once the window has ended, D-084-08) |
| `visibility` | `private` | visibility filter on private tasks, scope `all` (CS-PLAN-041) |
| `page` | `2`, `3`, … | page of the list by due date; a page beyond the end shows the last page |
| `group` | `room`, `person` | grouping; omitted for the list by due date |
| `room` | room id | with `group=room`: the open room (the target of the `/room/:id` redirect) |

## UC-06 — Task creation (materialize room tasks)

**Goal:** every create path ends in a visible room task. **Personas:** Owner.
**Entry points:** TaskManager → *New Task*, and *New* in a room heading of its list by room.

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Fill from a cleaning template | `TaskForm` → *From template* (`TaskTemplateFill`: the chosen room's type first, the setup wizard's default level) | `GET /setup/catalog`, then the form's `POST /room-tasks` | ✅ (CS-PLAN-091 WP-B6: a template only fills the form; `POST /room-tasks/from-template` pruned) |
| Custom task with the room picker (one button: a floor opens its rooms, a room is the choice) | `TaskForm` → `RoomPicker` | `POST /room-tasks` | ✅ one atomic aggregate (CS-PLAN-063, CS-PLAN-091 WP-B6) |
| Assignees at creation (member chips) | `TaskForm` → `AssigneeChips` | `POST /room-tasks` (`assigneeUserIds`) | ✅ |
| Sub-tasks at creation | `TaskForm` → `SubTaskEditor` | `POST /room-tasks` (`checklistItems`) | ✅ (CS-PLAN-091 WP-B6) |
| First date at creation | `TaskForm` (default tomorrow; the first window lies around it) | `POST /room-tasks` (`firstDate`) | ✅ (ADR-021, D-091-19) |
| Refusal feedback | `showRefusal` toast; the form keeps its input | — | ✅ (one atomic write: nothing is half created) |

## UC-07 — Task lifecycle maintenance

**Goal:** keep room tasks correct over time. **Personas:** Owner. **Entry
points:** TaskManager, the task row and its form.

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Edit due date | retired with ADR-021 (CS-PLAN-091 WP-B1): the window follows from the first date, the rule and the last completion; the one form's first date sets the first window (CS-PLAN-091 WP-B6) | — (`PUT /room-tasks/{id}/due-date` removed) | ⛔ retired (CS-PLAN-091 WP-B1) |
| Record "last done on" (FEAT-UX-045) | retired: the first date replaces it (D-091-19) | — (`PUT /room-tasks/{id}/last-done` removed) | ⛔ retired (CS-PLAN-091 WP-B1) |
| Edit assignees | task row → *Edit* → `TaskForm` → `AssigneeChips` | `PUT /room-tasks/{id}` (`assigneeUserIds`) | ✅ (CS-PLAN-091 WP-B6; `PUT /room-tasks/{id}/assignees` pruned) |
| Move to another room | `TaskForm` → `RoomPicker` | `PUT /room-tasks/{id}` (`roomId`) | ✅ (CS-PLAN-091 WP-B6: the history moves with the task, the room journal keeps its entries) |
| Delete room task | `TaskForm` → *Delete task*, after a confirmation | `DELETE /room-tasks/{id}` | ✅ (D-091-86) |
| Deactivate (pause) task | — | `POST /room-tasks/{id}/deactivate` | ⛔ pruned (CS-PLAN-055; `reactivate`/`uncomplete` followed by CS-PLAN-064) |
| Undo a completion (snackbar) | global `UndoSnackbar` queued list (~5 s per entry, UX-015; single tap, never a hold) | `POST /completions/{id}/undo` — targeted correction of the exact completion (WP3; replaced the CS-PLAN-064 `POST /room-tasks/{id}/undo` aggregate) | ✅ exact server-side restore: recurrence, ledger, rewards/streak, cleanliness, checklist (CS-PLAN-064/ADR-011; the CS-PLAN-041 `uncomplete`+conditional-`reactivate` chain was pruned, D5) |

## UC-08 — Daily execution (Today)

**Goal:** one surface answering "what do I do today?" — the core loop for
every role. **Entry points:** the bare root and `/today`; `/dashboard` redirects
here. **Landing (CS-PLAN-070 WP6, CS-PLAN-090 WP-B): every role lands on
Today**; explicit deep links stay usable.

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Personalized daily list (child-aware limits) | TodayView | `GET /room-tasks/filtered-daily` | ✅ CS-PLAN-065 (D4): child eligibility (own-or-unassigned) runs before the availability gate and the child limit; the read order is `nextDue, createdAt, id` and rows embed their canonical `assignees` |
| My tasks ↔ Everyone filter | TodayView | client-side | ✅ (default: My tasks) |
| Overdue block (first, red; one row per chore with its planned date, window end and days since) | TodayView → `TaskRow` (`overdue`) | `GET /room-tasks/overdue` | ✅ (CS-PLAN-090 WP-B) |
| Short household instructions per row | TodayView | definition `instructions` (falls back to `description`) | ✅ CS-PLAN-066 §3.5 (plain escaped text, never v-html) |
| Optional expandable checklist on Today | `TaskRow` → opened row (`TaskRowDetails`) → `TaskChecklist` (execute-only, frozen when completed) | `GET /checklists/task/{id}`, `GET /checklists/progress/{rt}`, `POST /checklists/toggle/{rt}/{item}` | ✅ CS-PLAN-066 §3.5; one opened row on Today and in the task list since CS-PLAN-091 WP-B6 |
| Complete task (celebration: confetti/sound/toast, combo, streak) | TodayView, TaskList, TaskManager — Done is a 600 ms press-and-hold with a short-tap hint and one in-flight request per room task (CS-PLAN-084 WP-E) | `POST /room-tasks/{id}/complete` | ✅ (CS-PLAN-065 D7 removed the dead per-completion energy-counter store write; CS-PLAN-066 reconciles every owning store before the events and re-syncs the titles store before the title-unlocked event fires once per response) |
| Counts of due and overdue work | TaskManager stats bar; Today's overdue heading | `GET /room-tasks/due-today`, `GET /room-tasks/overdue` | ✅ CS-PLAN-066 (D2): the counters read dedicated endpoint-backed projections; a scoped room load or the child-limited daily list can never overwrite them; the coordinated derived refresh (D3) keeps them truthful after mutations. The Dashboard tiles are gone (CS-PLAN-090 WP-B); `/tasks?status=overdue&scope=all` stays as an address |
| Per-row assignee resolution | TodayView | daily AND overdue rows embed canonical `assignees` (D1) | ✅ CS-PLAN-066 (no per-row re-fetch remains) |
| Partial derived-refresh failure | TodayView | read-only retry of the loaded projections | ✅ CS-PLAN-066 (D3): a committed action stays successful; stale slices offer a refresh-only retry |
| Own status (placed energy against the day, weekly goal, streak, Coins) | TodayView → `TodayStatusLine` | `GET /game/state` (optional: a dash until read), the `load` of `GET /room-tasks/filtered-daily` | ✅ CS-PLAN-090 WP-B; the load is the server's since CS-PLAN-091 RF-091-14 |
| Work already possible today (open flex window) | TodayView, collapsed behind one line | `GET /room-tasks/filtered-daily` (`openWindow`) | ✅ BUG-UX-005, CS-PLAN-090 WP-B |
| Next appointments | — | — | ⛔ removed from Today (CS-PLAN-090 D-090-11); the calendar shows upcoming work |
| Checklist toggle while completing | a short tap on Done → `CompletionPicker` (which sub-tasks are done); the opened row → `TaskChecklist` | `POST /room-tasks/{id}/complete`, `POST /checklists/toggle/{rt}/{item}` | ✅ (CS-PLAN-091 WP-B2, WP-B6) |
| Checklist editor (add/edit/delete/reorder items) | `TaskForm` → `SubTaskEditor` (adults and the owner) | `POST /room-tasks`, `PUT /room-tasks/{id}` (`checklistItems`) | ✅ (CS-PLAN-091 WP-B6; the item write routes are pruned) |
| Complete/assign from calendar day | CalendarView | — | ❌ read-only (review W6) |

## UC-09 — Assignment & teamwork

**Goal:** distribute work fairly; recover stuck tasks. **Personas:** Owner +
Members. **Entry points:** the one form; the picker of a short tap on Done.

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Multi-assign via member chips | `TaskForm` → `AssigneeChips` | `PUT /room-tasks/{id}` (`assigneeUserIds`) | ✅ (CS-PLAN-091 WP-B6; the row's chips and `PUT /room-tasks/{id}/assignees` are gone) |
| Single assign / unassign actions | — | `POST /room-tasks/{id}/assign`, `POST …/unassign` | ⛔ pruned (CS-PLAN-055; the form's assignee list is the live path) |
| Bulk assignment (selection bar → one call) | — | `POST /room-tasks/bulk-assign` | ⛔ removed (CS-PLAN-091 WP-B6, D-091-87) |
| Claim overdue public task | — | — | ⛔ retired (CS-PLAN-091 WP-B6): a short tap on Done opens the picker, where anyone records who did the work (ADR-021 §13) |
| Dedicated claimable-tasks query | — | `GET /room-tasks/overdue-claims` | ⛔ pruned (CS-PLAN-055; UI uses `/overdue` + client filter) |

## UC-10 — Planning: availability, energy, preferred days, the suggestion

**Goal:** balance workload by capacity and energy without anyone applying a
plan. **Personas:** all (self service), Owner (every member's settings).
**Entry points:** Settings → *Planning* for the own days, energy and preferred
days; Settings → *Household* → *Planning of the members* for the owner; the
calendar for the suggestion. The weekly plan's page went with the live
suggestion (CS-PLAN-091 WP-C, ADR-022); `#/scheduling` leads to the calendar.

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Edit own weekday availability | Settings → Planning: `SelfAvailabilitySettings` (a failed save is reported at the form) | `GET+PUT /scheduling/preferences` | ✅ changed-days-only saves |
| Owner availability grid (all members) | Settings → Household → *Planning of the members*: `AvailabilitySettings`, which reads the members when it opens | `GET /scheduling/members` | ✅ (moved from the weekly plan's page, CS-PLAN-091 WP-C) |
| Edit another member's availability | `AvailabilitySettings` → shared `AvailabilityEditModal` (owner) | `PUT /scheduling/members/{userId}` | ✅ (CS-PLAN-043 wired the edit path — UX-014 keep decision resolved: endpoint stays) |
| Edit energy budget (weekdays/weekend) | `EnergyBudgetSettings` | `GET+PUT /scheduling/preferences` | ✅ single bulk save |
| Mark preferred days | the star per day in `EnergyBudgetSettings` (`WeekBudgetGrid`); for another member the owner's `AvailabilityEditModal` | `PUT /scheduling/preferences` / `PUT /scheduling/members/{userId}` (`preferredWeekdays`) | ✅ CS-PLAN-091 WP-C (ADR-022 §5): the suggestion fills preferred days first |
| Set a child's daily-task limit | `AvailabilityEditModal` (owner, non-self only) | `PUT /scheduling/members/{userId}` (`maxDailyTasks`) | ✅ CS-PLAN-065 (decision D5): omitted = unchanged, `null` = unlimited, 1-100 = finite bound; the personal endpoints reject the key |
| The suggestion of the next 14 days with the load | CalendarView, week: `CalendarAgenda` — per day the suggested tasks with their window, the overdue work on today, what was done, and the viewer's placed energy against the day's energy; *My tasks* / *Everyone* | `GET /scheduling/suggestion` + `GET /room-tasks/range` | ✅ CS-PLAN-091 WP-C (replaces the capacity calendar `ScheduleCalendar`) |
| Today's suggestion | TodayView: *Suggested for today*, *Also possible today* | `GET /room-tasks/filtered-daily` (`suggestedFor`, `overload`) | ✅ CS-PLAN-091 WP-C |
| Preview and apply a plan | retired with ADR-022 (CS-PLAN-091 WP-B1): `SchedulePlanner`, its proposal rows, capacity grid and consent attestation are gone; the live suggestion replaces them | — (`POST /scheduling/suggestions` and `POST /scheduling/apply` removed) | ⛔ retired (CS-PLAN-091 WP-B1) |
| Absence (enter, see everyone's, delete; owner: household, for someone afterwards, pending requests) | `AbsenceView` (`#/absence`) with `AbsenceOwnerPanel`. An absence writes nothing on the work: tasks stay visible and can be completed; a window inside the absence is neutral for the absent member (ADR-021 §5) | `GET/POST /pauses`, `DELETE /pauses/{id}`, `POST /pauses/{id}/approve\|reject`, `POST /pauses/recognize-illness` | ✅ CS-PLAN-090 WP-A, [ADR-020](../adr/ADR-020-absences-without-requests.md) — every role acknowledged at once, no reason asked or shown, deletion as a cancelled revision; the shortening route is pruned (CS-PLAN-091 RF-091-11), and an acknowledged shortening that is stored keeps governing |
| Occurrence agreements (amend/catch-up/restart) | retired with ADR-021 (CS-PLAN-091 WP-B1); stored agreements stay as history | — (`POST /occurrences/{key}/…` removed) | ⛔ retired (CS-PLAN-091 WP-B1) |

*Last verified: 2026-09-24 (CS-PLAN-073 WP2 fix round).*

## UC-11 — Progress & gamification

**Goal:** make contribution visible and rewarding. **Personas:** all.
**Entry points:** Today, Insights (ranking section), Settings.

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Coin balance | — | `GET /minigames/coins` | ⛔ retired with the WP3 no-Coin arcade cutover (T10/D7d) |
| Game/global state (streak, goal, own Coins) | TodayView status line | `GET /game/state` | ✅ |
| Weekly ranking (opt-in, consent-filtered) | Insights → `RankingPanel`, with an error state of its own; `/leaderboard` redirects there (CS-PLAN-090 WP-E) | `GET /ranking` + `POST /ranking/(consent\|withdraw)`; the unconditional `GET /users` ranking (`getLeaderboard`) and the pruned test-only `GET /game/leaderboard` are gone (CS-PLAN-070 WP4) | ✅ |
| Ranking flag (owner) | Settings → Household → House | `PUT /house` (`rankingEnabled`, default off) | ✅ |
| Leaderboard period filter (week/month/all) | Leaderboard | — | ⛔ removed (CS-PLAN-041): the ranking view has no period parameter; a filter that changes nothing is dishonest UI |
| Medals overview | `RankingPanel` medal cards | `GET /game/medals` | ✅ (store-driven, CS-PLAN-041) |
| Medal evaluation trigger | `RankingPanel` mount (pre-pass, D-090-15) | `POST /game/check-medals` | ✅ (CS-PLAN-041) |
| Title display (single series, no selection) | Settings | `GET /users/{id}/titles`; `PUT …/titles/select` pruned (CS-PLAN-070 WP4 — `currentTitle` always derives from the valid-history maximum) | ✅ |
| Weekly goal progress | HouseholdGoalBar | `GET /game/state` (`weeklyGoal` + personal `myWeeklyGoal`) | ✅ |
| Reward rules configuration (coin/combo values) | — | `GET+PUT /reward-config` | ⛔ HTTP surface pruned (CS-PLAN-055); the WP4 retirement removed the weekly-goal/threshold override keys — only `titlesEnabled` and `arcadeGateEnabled` remain |

## UC-12 — Minigames

**Goal:** play short household-themed games for fun; scores only, never Coins
or chore value (T10/D7d). **Personas:** all (child-relevant).
**Entry points:** the optional Arcade view (hidden with `arcadeVisible` off).

| Action | Where | Endpoint | Status |
|---|---|---|---|
| See which games are open and the neutral progress toward the next one | `ArcadeCatalog` card | `GET /minigames/catalog` | ✅ (CS-PLAN-086 WP-B, ADR-019) |
| Play a game (keyboard, swipe, on-screen pad; pause on hide) and save the score with its play time | `ArcadeCatalog` → `GameShell` | `POST /minigames/{g}/scores` (`score`, `durationMs`; `403 MINIGAME_LOCKED` while locked) | ✅ Crumb Snake (CS-PLAN-086 WP-A), Spot Sprint with tap input on phones (WP-C), Sock Match, untimed, keyboard and tap (WP-D), Tile Breaker with held arrow keys, mouse or finger (WP-E), Dust Runner with the arrow keys, swipes or the on-screen pad (WP-F) |
| Personal best / rank per game | `ArcadeCatalog` card, `GameLeaderboard` | `GET /minigames/{g}/personal-best`, `/rank` | ✅ |
| All-time + weekly board and own recent rounds per game | `GameLeaderboard` | `GET /minigames/{g}/leaderboard(/weekly)`, `/recent` | ✅ |
| Switch the chore unlocks off for the household (owner) | Settings → Arcade (`ArcadeGateSetting`) | `PUT /house/arcade-gate` | ✅ (CS-PLAN-086 WP-B) |
| Set how many finished chores open each game, 0 to 500 (owner) | Settings → Arcade (`ArcadeThresholdSettings`) | `PUT /house/arcade-thresholds` | ✅ (CS-PLAN-090 WP-J, ADR-019) |
| See each person's finished chores since their last reset and let one person start over after a confirmation; highscores stay (owner) | Settings → Arcade (`ArcadeResetList`) | `GET /house/arcade-progress`, `POST /house/members/{userId}/arcade-reset` | ✅ (CS-PLAN-090 WP-J, ADR-019) |
| Memory / 2048 prototypes, boost shop | — | — | ⛔ retired with the WP3 cutover |

## UC-13 — Members, roles & household changes

**Goal:** run the household as a group. **Personas:** Owner (management),
Member (self). **Entry points:** Settings → MemberList (since CS-PLAN-038).

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Member list with roles | MemberList | `GET /users`, `GET /users/current` | ✅ |
| Invite member (email + role) | MemberList | `POST /users/invite` | ✅ |
| Change role (incl. promote to owner) | MemberList | `PUT /users/{id}/role` | Supported; self-role and last-owner guards enforced by `HouseService` and API tests |
| Remove member (styled confirm) | MemberList | `DELETE /users/{id}` | ✅ server cleans up assignments |
| Edit member profile | — | `PUT /users/{id}` | ⛔ pruned (CS-PLAN-055 — route + dead `updateUserProfile` wrapper) |
| Per-member task statistics | — | `GET /users/{id}/stats`, `GET /room-tasks/user/{id}/stats` | ⛔ pruned (CS-PLAN-040 — dead, CR-SVC-31-flawed metric); per-member history → UC-16 |
| Leave house | — | — | ⛔ no backend (FEAT-UX-015) |

## UC-14 — Notifications & reminders

**Goal:** give useful information about agreed chores without engagement
pressure. WP5 provides self-only Settings preferences and one optional shared
daily digest with quiet hours, a bounded delivery window and a membership/day
budget. See [Notifications](notifications.md); polished calm presentation
and sound/animation consumers remain WP6.

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Optional combined daily task/organizational digest | Nextcloud notifications (five-minute job) | background job | WP5 implemented; opt-in only, no missed-slot catch-up or award bells |
| Personal delivery preferences | Settings | `GET/PUT /preferences/me` | WP5 minimal self-only surface, shared-version conflict handling |
| Pick the own colour scheme, kept on every device (every role) | Settings → Personal → Appearance (`AppearanceSettings`) | `PUT /preferences/me/color-scheme`, initial state `color-scheme` | ✅ (CS-PLAN-090 WP-K, FEAT-UX-057) |
| Reminder settings per task | — | — | ⛔ concept exists (`task-model-and-sync.md`), no endpoint, no UI |
| NC-Tasks sync surface | — | `POST /task-sync/*` (6 routes) | ⛔ pruned (CS-PLAN-055 — controller/service/routes deleted; design intent kept marked in [task-model-and-sync.md](task-model-and-sync.md)) |

## UC-15 — Calendar overview & planning horizon

**Goal:** see the schedule across days and weeks at a glance. **Personas:**
all. **Entry points:** `/calendar` route (CalendarView).

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Load visible range (single round-trip) | CalendarView | `GET /room-tasks/range` | ✅ |
| Range navigation, day badges | CalendarView | client state | ✅ |
| Complete / assign from a day | — | — | ❌ read-only (review W6) |

## UC-16 — Insights: stats, fairness & history

**Goal:** answer "how are we doing?" and "who did what when". **Personas:**
all. **Entry points:** `/insights` route (InsightsView, navigation entry). Shipped with
CS-PLAN-040; dense since CS-PLAN-090 WP-E: four key figures, fairness bars, the ranking and
the activity list.

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Four key figures in one row (house health, clean of all rooms, dirty, needing cleaning) | InsightsView `HouseStatsCards` | `GET /house/stats` | ✅ (CS-PLAN-090 WP-E, D-090-52) |
| Fairness as compact bars (completed energy against weekly capacity, with contribution and streak) | InsightsView `MemberFairnessTable` | `GET /users` + client aggregation over activity | ✅ (formula: [energy-and-balancing.md](energy-and-balancing.md)) |
| Activity list "who did what when": one line per completion (time, person, task, coins paid), grouped by the household's day; a tap opens room, effort, shares, *Correct split* and *Undo* | InsightsView `ActivityFeed` | `GET /house/activity` (`coinMinor` per share) | ✅ (CS-PLAN-090 WP-E; absences and date changes join in Release B, D-090-13) |
| Freeze visibility (owned count ❄ + "frozen" state after yesterday's consumption) | Leaderboard streak badge | `GET /users` (`streakFreezes`, `lastFreezeDate`) | ✅ |
| Overload | CalendarView week: an entry that fits no day of its window reads *does not fit*, a day with more than it holds is highlighted; TodayView: the neutral overload line | `GET /scheduling/suggestion` (`overload`, `placed` against `budget`); *Today* reads the same load with `GET /room-tasks/filtered-daily` | ✅ CS-PLAN-091 WP-C (replaces the deferred-first list `OvercapacityWarning`; nothing is deferred by priority) |

---

> **UC-16 addendum (WP7, CS-PLAN-078):** the family reward catalog and
> request lifecycle have a backend (ADR-017; [rewards.md](../api/rewards.md))
> and, since WP8 (CS-PLAN-079, 2026-10-02), the full UI — recorded as
> UC-17 below. The browser contract is pinned by `family-reward-api.spec.ts`
> (HTTP lifecycle/accounting) and `family-rewards.spec.ts` (UI flows).

---

## UC-17 — Reward catalog & reward requests (WP7 backend, WP8 UI)

**Goal:** spend earned Coins on family-agreed rewards through an approval
and delivery flow. **Personas:** all (child-relevant). **Entry points:**
nav **Rewards** (only while both gates are open); **My Requests** from Rewards and,
with either gate closed too, from the personal settings (it left the navigation with
CS-PLAN-090 WP-D, its routes stay); Settings → Household → Rewards (owner).

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Browse active catalog | `Rewards.vue` (`/rewards`) | `GET /rewards?scope=active` | ✅ WP8 — entry only while the household gate AND the personal `catalogVisible` preference are open; a hidden direct entry shows a neutral explanation |
| Owner gate, items, archive | Settings → Rewards (`RewardCatalogEditor.vue`) | `PUT /house/catalog`; `POST /rewards`; `PUT /rewards/{id}` | ✅ WP8 — owner-only, works while browsing is hidden or the gate is off; exact `parseCoinMinor` prices; a 409 keeps the draft |
| Request a reward (frozen-terms confirmation) | `Rewards.vue` confirm modal | `POST /reward-requests` | ✅ WP8 — child request waits for owner approval; adult self-approves; a funding hold disables new requests neutrally |
| Own / owner household request lists | `RewardRequestList.vue` (`/reward-requests`) | `GET /reward-requests?scope=mine\|house&state=` | ✅ WP8 — persistent nav entry independent of both gates; state filter; former members render as placeholders |
| Request detail, audit, actions | `RewardRequestDetails.vue` (`/reward-requests/{id}`) | `GET /reward-requests/{id}`; `…/approve\|reject\|cancel\|fulfill\|correct` | ✅ WP8 — attestation dialogs; correction requires a reason and the original `settlementEntryId`; foreign ids get a generic unavailable state |
| Uncertain-command replay | Requests list/detail banner | same command UUID | ✅ WP8 — in-memory identity, explicit retry only, never a fresh-key resend |
| Available/reserved wallet + hold note | Rewards and Requests banners | `GET /game/state` (`wallet`) | ✅ WP8 — own totals only; no optimistic arithmetic; a hold clears on re-entry |

Contract: [rewards.md](../api/rewards.md) · concept:
[ADR-017](../adr/ADR-017-family-catalog-and-redemption.md) · client
store: [`architecture.md → useRewardsStore`](../engineering/architecture.md).

---

## Cross-cutting conventions (apply to every UC)

- **Role gating:** hide owner-only controls from members instead of letting
  them fail with 403 (UX-004).
- **Destructive actions:** styled confirm modals; typed confirmation only for
  house reset (UX-006).
- **Touch targets ≥ 44 px** for complete/claim/primary actions; icon+text
  labels (UX-007). Done is press-and-hold (`HoldButton`,
  CS-PLAN-084 D-084-05).
- **Feedback:** success/error toasts for every mutation; celebration pipeline
  for gamified successes (`chorestrike:task-completed` event).
- **Every user-visible string** via `t()`; `data-testid` on interactive
  elements; frontend logging via `src/utils/logger.js`.

## Gap index (→ existing backlog IDs)

| UC | Open gaps |
|---|---|
| 01 | — (naming + skip persistence ✅ since CS-PLAN-042; invite step ✅ since CS-PLAN-038) |
| 02 | UX-006 (silent save); role gating ✅ since CS-PLAN-042; insights/fairness view ✅ since CS-PLAN-040 (UC-16) |
| 03/04 | UX-006; room rename/delete, floor reorder, single CRUD surface ✅ since CS-PLAN-042 |
| 05 | ~~definition delete/search/categories~~ ⛔ routes pruned (CS-PLAN-055); global-search surface → FEAT-UX-028 |
| 07 | — (deactivate route pruned CS-PLAN-055; a pause UI would need re-exposing it with a consumer decision) |
| 08 | — (checklist editor ✅ owner-only since CS-PLAN-042) |
| 09 | claim target size (UX-007); bulk assign + create-form chips ✅ since CS-PLAN-042 / CS-PLAN-038 |
| 10 | — (suggestion Apply + merged planner surface + owner member-edit ✅ since CS-PLAN-043; overcapacity warning ✅ since CS-PLAN-040 (UC-16)) |
| 11 | reward-config UI (HTTP surface pruned CS-PLAN-055 — a future config UI needs a re-expose decision) |
| 12 | — (five games, chore unlocks and the owner gate ✅ since CS-PLAN-086; the 2048/Memory prototypes were retired with the WP3 cutover) |
| 13 | profile edit route pruned (CS-PLAN-055; re-needs expose), leave house (FEAT-UX-015); role guards backend-side ✅ since CS-PLAN-045; per-member history ✅ since CS-PLAN-040 (UC-16) |
| 14 | reminder-settings concept → FEAT-UX-024; task-sync surface → ⛔ pruned (CS-PLAN-055, FEAT-UX-021 closed) |
| 15 | complete/assign from a calendar day (W6) |
| 17 | — (essential slice ✅ since CS-PLAN-079/WP8; wider guided tour/help → FEAT-UX-023 remainder; cross-DB/family evidence → CONCEPT-001/WP9) |
| all | documented-but-unbuilt ideas → *Whitespace* below (FEAT-UX-016…031) |

---

## Whitespace (⛔ candidates)

Documented-but-unbuilt intent and review ideas, recorded so they are not lost.
**This section is an index into `backlog.md` (FEAT-UX-*), not a second list.**
No expose-or-prune decision is pre-empted here (neutrality principle of the
endpoint audit). Decision tags: `build-new` · `expose` (endpoint exists, no
surface) · `needs-decision` · `post-MVP`.

**2026-09-17 target-scope update:** CS-PLAN-070 now selects calm notifications and
dated pauses for the reliable core, then an optional family catalog. General
chore approval and Task Pass remain outside that slice. These explicit product
decisions update intent below, not the current implementation/route inventory.

### Group A — documented intent, no surface (7)

| Candidate | Decision tag | Concept-doc source | Backlog |
|---|---|---|---|
| ~~Completed-task history & activity log ("who did what when")~~ ✅ built as UC-16 (`GET /house/activity` + `ActivityFeed`) | `build-new` | [design.md §6 "Streak & History"](design.md) | FEAT-UX-016 (closed 2026-08-21) |
| ~~House insights & fairness view~~ ✅ built as UC-16 (`GET /house/stats` exposed, capacity-normalized fairness) | `expose` + `build-new` | [energy-and-balancing.md](energy-and-balancing.md) (fairness constraints) | FEAT-UX-017 (closed 2026-08-21) |
| General child task-approval queue | Deferred outside CS-PLAN-070; completion remains immediate | [Approved reward/approval distinction](gamification-and-rewards.md) | FEAT-UX-018 |
| Task Pass & support actions (gag mechanic) | `post-MVP` | [gamification-and-rewards.md "Task Pass"](gamification-and-rewards.md) | FEAT-UX-019 |
| ~~Streak-freeze visibility~~ ✅ built as UC-16 (Leaderboard freeze badges; `lastFreezeDate` serialized) | `build-new` | [gamification-and-rewards.md "Streak freezes"](gamification-and-rewards.md) | FEAT-UX-020 (closed 2026-08-21) |
| ~~NC-Tasks sync surface (link/pending/conflict; 6 backend-only routes)~~ ⛔ pruned (CS-PLAN-055 — closed as moot; design intent kept marked in the concept doc) | ~~`needs-decision`~~ resolved-pruned | [task-model-and-sync.md](task-model-and-sync.md) | FEAT-UX-021 (closed 2026-08-29) |
| ~~Overcapacity warning surface~~ ✅ built as UC-16 (`OvercapacityWarning` + calendar markers) | `needs-decision` | [energy-and-balancing.md "Overcapacity Policy"](energy-and-balancing.md) | FEAT-UX-022 (closed 2026-08-21) |

> CS-PLAN-040 resolved four Group A candidates (FEAT-UX-016/017/020/022) and
> pruned the three dead stats endpoints the audit had flagged neutrally.

### Group B — review ideas (9)

| Candidate | Decision tag | Source | Backlog |
|---|---|---|---|
| Onboarding & help layer (tour, glossary, contextual hints) | `build-new` | Usability review 2026-08-20 (UX-007 vocabulary) | FEAT-UX-023 |
| Notification settings (opt-in summary, quiet hours) | Delivered (WP5 backend + WP6 presentation, 2026-10-01) | CS-PLAN-070 D9 | FEAT-UX-024 (closed) |
| Dated member/house absences, without a request since ADR-020 | Delivered (WP2 backend + WP5 digest + WP6 pause UI, 2026-10-01; Absence view, CS-PLAN-090 WP-A) | CS-PLAN-070 D4, D-090-03 | FEAT-UX-025 (closed), FEAT-UX-065 |
| Optional family reward catalog | Delivered (WP7 backend 2026-10-01 + WP8 UI 2026-10-02, CS-PLAN-078/079; UC-17) | [Approved target](gamification-and-rewards.md) | FEAT-UX-026 |
| Print view (weekly plan / calendar) | `post-MVP` | Review whitespace 2026-08-21 | FEAT-UX-027 |
| Global search across tasks | `expose` (`GET /tasks/search`, dead wrapper) | Endpoint audit 2026-08-21 | FEAT-UX-028 |
| Bulk maintenance ops (reschedule overdue, seasonal deactivate) | `post-MVP` | Review whitespace 2026-08-21 | FEAT-UX-029 |
| A11y & child My Day baseline implemented; planning-first quiet defaults and opt-in ranking selected | Baseline delivered in CS-PLAN-066; CS-PLAN-070 D10 delivered by WP4/WP6 | [Product vision](product-vision.md) | FEAT-UX-030 (historical); family evidence owned by CONCEPT-001 |
| Error/empty/offline-state conventions | `build-new` | Review whitespace 2026-08-21 | FEAT-UX-031 |

---

## Appendix A — Route coverage ledger (71 routes)

> Coverage accounting after **CS-PLAN-055**: routes = 71 → 67 production
> (mapped individually in the UC tables above — routes referenced by more than
> one use case (`PUT /house`, `POST /users/invite`,
> `PUT /room-tasks/{id}/assignees`, `GET /room-tasks/overdue`) are counted
> once) + the 3 test-mode harness routes below + `page#index` (app shell,
> excluded). Earlier ledgers covered 110/111 routes before the CS-PLAN-040
> stats prune (−3) and the CS-PLAN-055 prune (−39).
>
> The **authoritative disposition list** for the 39 CS-PLAN-055 deletions is
> [`appinfo/routes.php`](../../appinfo/routes.php) (prune note) + the
> `RouteMatrixTest::testPrunedCS055RoutesAreNotReintroduced` +
> `testRouteCountIsPinned` guards — re-adding any route requires a consumer
> and a deliberate update of those pins.

### Pruned routes (39 — CS-PLAN-055, 2026-08-29)

| Group | Routes (verb + URL) |
|---|---|
| Dead create aliases ×4 (BUG-ROOM-001) | `POST /floor-create`, `POST /room-create`, `POST /floors/create`, `POST /rooms/create` |
| No-consumer reads ×6 | `GET /floors`, `GET /floors/{id}`, `GET /rooms`, `GET /rooms/{id}`, `GET /tasks/{id}`, `GET /room-tasks/{id}` |
| Dead room-task variants ×6 | `GET /room-tasks/due-within-days`, `POST /room-tasks/{id}/assign`, `POST …/unassign`, `POST …/assignees` (add), `DELETE …/assignees` (remove), `GET /room-tasks/overdue-claims` |
| Dead task/user/room wrappers ×7 | `GET /tasks/category/{category}`, `GET /tasks/search`, `GET /tasks/categories`, `GET /tasks/my-private`, `DELETE /tasks/{id}`, `PUT /users/{id}`, `GET /rooms/needing-cleaning` |
| Dead checklist ×2 | `POST /checklists/initialize/{roomTaskId}`, `GET /checklists/complete/{roomTaskId}` |
| task-sync surface (all) | `POST /task-sync/complete`, `…/sync-from-nextcloud`, `GET …/{roomTaskId}/reminders`, `GET …/pending`, `POST …/{completedTaskId}/mark-synced`, `POST …/link` |
| Test-only lifecycle/leaderboard/config ×8 | `PUT /tasks/{id}/complete`, `PUT …/skip`, `PUT …/reschedule`, `GET /tasks/daily`, `POST /room-tasks/{id}/deactivate`, `GET /game/leaderboard`, `GET+PUT /reward-config` |

> Kept deliberately (UC-07 undo re-scoped by CS-PLAN-064 to the ONE aggregate
> `POST /room-tasks/{id}/undo`, since WP3 the targeted `POST /completions/{id}/undo`; the split uncomplete/reactivate pair was pruned), the assignee routes until CS-PLAN-091 WP-B6 pruned
> them (below), and the 3 test harness routes below.
>
> CS-PLAN-040 previously removed the dead stats trio
> (`GET /users/{id}/stats`, `GET /room-tasks/user/{userId}`,
> `GET /room-tasks/user/{userId}/stats`) and CS-PLAN-042 added
> `POST /room-tasks/bulk-assign` (UC-09).
>
> CS-PLAN-091 WP-B6 (2026-11) pruned `PUT /tasks/{id}`,
> `POST /room-tasks/from-template`, `POST /room-tasks/bulk-assign`,
> `GET+PUT /room-tasks/{id}/assignees` and the four checklist-item writes: the
> one form's `POST /room-tasks` and `PUT /room-tasks/{id}` carry them (96
> routes, pinned by `RouteMatrixTest`). Before it, WP-B1 retired the three
> agreement commands, the due-date and *last done* writers and the owner's
> preview and apply (96), and WP-B3 to WP-B5 added the postpone, the date
> correction, the history of one task, the sleep command, the bonus rows, the
> coin factor and the two bonus-period commands (104). After it, WP-B7 retired
> the manual cleanliness delta, the task statistics of a room, the absence
> shortening and the house health refresh (92), and WP-C added the live
> suggestion: 93 routes.

### Test Data API (3 — test mode only)

| Route | Controller | Store wrapper | Status |
|---|---|---|---|
| `GET /test/profiles`, `POST /test/seed/{profile}`, `POST /test/reset` | test#profiles / #seed / #reset | — | test-mode only; consumed by the Playwright E2E harness (`tests/e2e/auth.setup.ts`, `tests/e2e/fixtures/database-fixtures.ts`), never by app code |

---

**Last verified:** 2026-08-29 — CS-PLAN-055 pass (route prune + dead-code sweep,
closes CR-053-14/15 + BUG-ROOM-001): 39 no-consumer/test-only routes deleted
(71 remain — see Appendix A ledger + `RouteMatrixTest` pins), the GET-create
branches in `FloorController`/`RoomController::index` are gone with their
routes, the mirroring dead store-getter/action layer in `src/store/*` was
pruned together with the `store/index.js` barrel, and reward-config/test
consumers were reworked onto test-seed knobs (no conditional surface).
Earlier: 2026-08-24 — CS-PLAN-043 pass (UX-014 + CR-016-03/04/05/06/08/13/14
+ TECH-DRY-001): UC-10 consolidated — SchedulePreview + SchedulingSuggestions
merged into `SchedulePlanner` with per-day Apply (bulk self PUT), owner
member-grid edit wired through the shared `AvailabilityEditModal`
(`PUT /scheduling/members/{userId}` keep decision resolved), suggestions
contract slimmed to `{ taskCount }`, calendar dates locale-aware. Earlier
passes: 2026-08-22 — CS-PLAN-042 pass (UX-004/008/009/011 +
CR-SVC-61): Settings is the sole owner-gated structure surface (floor CRUD +
up/down reorder, inline room add/delete), Dashboard FloorList read-only,
room rename/delete in the RoomDetail header, checklist editor unlocked for
owners on the backend contract (`label`/`sortOrder`/`itemOrders`), bulk
assign via `POST /room-tasks/bulk-assign` with a TaskList selection bar,
wizard house-name step + persistent per-house dismissal + single-member
assignee guard. Earlier passes: 2026-08-22 — CS-PLAN-041 (UX-005/006/013):
medals overview + `check-medals` pre-pass ✅ on the Leaderboard mount,
period filter ⛔ removed (no backend parameter), Dashboard private chip →
TaskManager `?visibility=private` scope ✅, completion undo snackbar
(`uncomplete` + conditional `reactivate`; a single undo aggregate since
CS-PLAN-064, UC-07) ✅, boost catalog ✅
server-authoritative (`BOOST_COSTS` deleted), styled confirms replace native
`confirm()`. 2026-08-21 — CS-PLAN-040 added UC-16 and flipped the stats
routes; CS-PLAN-039 added the route coverage appendix (uniform
dead-store-wrapper annotations) and a Standard-B review corrected the UC-01
invite status, added the live consumers for `due-today` / `range` /
`GET assignees`, and added UC-15 (CalendarView was previously only a gap row
in UC-08).
