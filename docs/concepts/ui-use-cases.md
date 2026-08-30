# UI Use Cases & GUI Element Inventory

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
  [`../backlog/CODE_REVIEW_2026-08-27.md`](../backlog/CODE_REVIEW_2026-08-27.md) L3-0).
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

**Personas:** *Owner* (adult household admin) · *Member* (e.g. child).

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
| 01 | First-run setup & house creation | Dashboard → SetupWizard |
| 02 | House administration | Settings, Dashboard |
| 03 | Structure: floors | FloorList (Dashboard), Settings |
| 04 | Structure: rooms | FloorList, RoomDetail |
| 05 | Task definitions (catalog) | TaskManager |
| 06 | Task creation (materialize room tasks) | RoomDetail, TaskManager |
| 07 | Task lifecycle maintenance | RoomDetail, TaskManager, TaskList |
| 08 | Daily execution ("My Day") | TodayView, TaskList |
| 09 | Assignment & teamwork | TaskList, TodayView |
| 10 | Planning: availability, energy, suggestions | SchedulingView |
| 11 | Progress & gamification | Dashboard, Leaderboard, Settings |
| 12 | Minigames & boosts | ArcadeSection (Dashboard) |
| 13 | Members, roles & household changes | Settings → MemberList |
| 14 | Notifications & reminders | (passive — no surface yet) |
| 15 | Calendar overview & planning horizon | CalendarView |

---

## UC-01 — First-run setup & house creation

**Goal:** new household is playable within minutes. **Personas:** Owner.
**Entry points:** app open with empty house → SetupWizard (auto).

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Auto-create house on first open | Dashboard mount | `GET /house` | ✅ |
| Guided wizard: house name, home type, floor/room counts, cleaning level | SetupWizard step 1 | `GET /setup/catalog` | ✅ |
| Room list editing (rename, type, person per room, add/remove) | SetupWizard step 2 | client state | ✅ |
| Preview (floors/rooms/≈tasks) + apply | SetupWizard step 3 | `POST /setup/apply` | ✅ |
| "Start empty" skip | SetupWizard | localStorage flag (per house) | ✅ (persistent since CS-PLAN-042; manual entry point stays) |
| House naming step | SetupWizard step 1 → apply | `POST /setup/apply` + `PUT /house` | ✅ (CS-PLAN-042) |
| Per-room assignee dropdown | SetupWizard step 2 | client state | ✅ (hidden while the house has a single member, CS-PLAN-042) |
| "Invite your family" final step (step 4, after apply) | SetupWizard | `POST /users/invite` | ✅ (since CS-PLAN-038) |

## UC-02 — House administration

**Goal:** rename, monitor, reset the household. **Personas:** Owner (member
visibility should be read-only). **Entry points:** Settings; Dashboard bars.

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Rename house | Settings | `PUT /house` | ⚠️ silent save, no toast (UX-006) |
| House health bar | Dashboard `HouseHealthBar` | `GET /house/health` | ✅ |
| Household weekly goal bar | Dashboard `HouseholdGoalBar` | client state | ✅ |
| House statistics | InsightsView `HouseStatsCards` | `GET /house/stats` | ✅ (live since CS-PLAN-040; wrapper renamed to `fetchInsights`) |
| Reset house (typed `RESET` confirm) | Settings danger zone | `POST /house/reset` | ✅ server owner-gated |
| Role-gate owner controls in Settings | Settings template (`v-if` owner role) | — | ✅ (CS-PLAN-042: house/floors/danger/members sections hidden for members — no 403 paths) |

## UC-03 — Structure: floors

**Goal:** model the home's storeys. **Personas:** Owner. **Entry points:**
Settings (sole CRUD+reorder surface); Dashboard `FloorList` (read-only).

| Action | Where | Endpoint | Status |
|---|---|---|---|
| List floors with rooms | FloorList | `GET /floors/with-rooms` | ✅ |
| Add floor (modal: name) | Settings | `POST /floors` | ✅ (single surface since CS-PLAN-042; FloorList is read-only) |
| Rename floor (modal) | Settings | `PUT /floors/{id}` | ✅ (styled confirm/toasts; CS-PLAN-041/042) |
| Delete floor (styled confirm) | Settings | `DELETE /floors/{id}` | ✅ (CS-PLAN-041) |
| Reorder floors (up/down buttons) | Settings floor rows | `POST /floors/reorder` | ✅ (CS-PLAN-042) |
| Expand/collapse, per-floor stats | FloorList, Settings room list | client state | ✅ |

## UC-04 — Structure: rooms

**Goal:** model rooms and their state. **Personas:** Owner. **Entry points:**
Settings (create/delete), RoomDetail (rename/delete), FloorList (cards,
read-only).

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Add room (inline name input per floor row) | Settings structure section | `POST /rooms` | ✅ (CS-PLAN-042; icon defaults, the old FloorList icon-picker modal was removed with the duplicate surface) |
| Rename room (header action) | RoomDetail | `PUT /rooms/{id}` | ✅ (CS-PLAN-042) |
| Delete room (styled confirm) | RoomDetail header; Settings floor room list | `DELETE /rooms/{id}` | ✅ (CS-PLAN-042) |
| Cleanliness change (+10 on task completion) | RoomCard | `PUT /rooms/{id}/cleanliness` | ✅ |
| "Rooms needing cleaning" list | — | `GET /rooms/needing-cleaning` | ⛔ pruned (CS-PLAN-055 — route + dead `fetchRoomsNeedingCleaning` wrapper gone) |
| Open room detail | RoomCard click → RoomDetail | `GET /room-tasks?roomId=` | ✅ |
| Task stats card (total/active/overdue/due-today/completion) | RoomDetail stats card | `GET /room-tasks/room/{roomId}/stats` | ✅ (live since CS-PLAN-040; cross-house roomId → 404) |

## UC-05 — Task definitions (catalog)

**Goal:** manage reusable task definitions. **Personas:** Owner. **Entry
points:** TaskManager.

| Action | Where | Endpoint | Status |
|---|---|---|---|
| List definitions | TaskManager | `GET /tasks` | ✅ |
| Create definition (+ materialize room task, see UC-06) | TaskManager → TaskEditForm | `POST /tasks` | ✅ (since CS-PLAN-038) |
| Edit definition | TaskManager → TaskEditForm | `PUT /tasks/{id}` | ✅ |
| Delete definition | — | `DELETE /tasks/{id}` | ⛔ pruned (CS-PLAN-055) |
| Search definitions | — | `GET /tasks/search` | ⛔ pruned (CS-PLAN-055) |
| Browse by category / categories | — | `GET /tasks/category/{c}`, `GET /tasks/categories` | ⛔ pruned (CS-PLAN-055) |
| Private (personal) task list | Dashboard chip → TaskManager visibility filter | `GET /tasks` + client-side `?visibility=private` scope | ✅ (CS-PLAN-041; `GET /tasks/my-private` was pruned in CS-PLAN-055 — the client-side filter is the only path) |
| Task-level complete/skip/reschedule/daily | — | legacy `PUT /tasks/{id}/…`, `GET /tasks/daily` | ⛔ pruned (CS-PLAN-055; RoomTask equivalents are the live completion path; `PUT /tasks/{id}/uncomplete` stays as the undo primitive) |

## UC-06 — Task creation (materialize room tasks)

**Goal:** every create path ends in a visible room task. **Personas:** Owner.
**Entry points:** RoomDetail → *Add Task* (default), TaskManager → *New Task*.

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Create from cleaning template (level pills, adjust name/frequency/minutes/energy/priority) | RoomDetail → `TaskTemplatePicker` | `POST /room-tasks/from-template` | ✅ |
| Custom task with cascading room picker | TaskEditForm → `RoomPicker` | `POST /tasks` → `POST /room-tasks` | ✅ unified chain (`createTaskWithRoomTask`) |
| Assignees at creation (member chips) | TaskEditForm → `AssigneeChips` | `PUT /room-tasks/{id}/assignees` | ✅ |
| Due date at creation | TaskEditForm | `POST /room-tasks` (`nextDue`) | ✅ |
| Partial-success feedback on chained creation | toasts | — | ✅ |

## UC-07 — Task lifecycle maintenance

**Goal:** keep room tasks correct over time. **Personas:** Owner. **Entry
points:** RoomDetail (slim modal), TaskManager, TaskList.

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Edit due date | RoomDetail → `RoomTaskEditModal` | `PUT /room-tasks/{id}/due-date` | ✅ |
| Edit assignees | RoomTaskEditModal, TaskList expanded row | `PUT /room-tasks/{id}/assignees` | ✅ |
| Delete room task | RoomDetail, TaskManager | `DELETE /room-tasks/{id}` | ✅ |
| Deactivate (pause) task | — | `POST /room-tasks/{id}/deactivate` | ⛔ pruned (CS-PLAN-055; `reactivate` stays for the undo path) |
| Reactivate task | UndoSnackbar (via store) | `POST /room-tasks/{id}/reactivate` | ✅ (undo of one-time completions, CS-PLAN-041) |
| Undo a completion (snackbar) | global `UndoSnackbar` (~5 s) | `PUT /tasks/{roomTaskId}/uncomplete` (+ reactivate for deactivated tasks) | ✅ (CS-PLAN-041; rewards/streaks are not reverted — accepted pre-release) |

## UC-08 — Daily execution ("My Day")

**Goal:** one surface answering "what do I do today?" — the core loop for
Member/child. **Entry points:** `/today` route; Dashboard stat cards link in.

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Personalized daily list (child-aware limits) | TodayView | `GET /room-tasks/filtered-daily` | ✅ |
| My tasks ↔ Everyone filter | TodayView | client-side | ✅ (default: My tasks) |
| Overdue section | TodayView | `GET /room-tasks/overdue` | ✅ |
| Complete task (celebration: confetti/sound/toast, combo, streak) | TodayView, TaskList, RoomDetail, TaskManager | `POST /room-tasks/{id}/complete` | ✅ |
| Stat-card counts + deep links (Due Today / Overdue) | QuickStats | `GET /room-tasks/due-today`, `GET /room-tasks/overdue` | ✅ (Overdue links `?scope=all`) |
| Per-row assignee resolution | TodayView | `GET /room-tasks/{id}/assignees` | ✅ |
| Checklist toggle while completing | TaskList → `TaskChecklist` | `POST /checklists/toggle/{rt}/{item}`, `GET /checklists/task/{id}`, `GET /checklists/progress/{rt}` | ✅ |
| Checklist editor (add/edit/delete/reorder items) | TaskList → `TaskChecklist` (owner-only `can-edit`) | `POST /checklists/task/{id}`, `PUT+DELETE /checklists/item/{id}`, `POST /checklists/reorder` | ✅ (unlocked for owners since CS-PLAN-042; store speaks the backend contract: `label`/`sortOrder`, `itemOrders` map, `{items, stats}` progress envelope) |
| Complete/assign from calendar day | CalendarView | — | ❌ read-only (review W6) |

## UC-09 — Assignment & teamwork

**Goal:** distribute work fairly; recover stuck tasks. **Personas:** Owner +
Members. **Entry points:** TaskList expanded row; TodayView claim button.

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Multi-assign via member chips (optimistic + rollback) | TaskList expanded row | `PUT /room-tasks/{id}/assignees` | ✅ |
| Single assign / unassign actions | — | `POST /room-tasks/{id}/assign`, `POST …/unassign` | ⛔ pruned (CS-PLAN-055; `PUT /room-tasks/{id}/assignees` set-assignees remains the live single-edit path) |
| Bulk assignment (selection bar → one call) | TaskList selection → member dropdown | `POST /room-tasks/bulk-assign` | ✅ (CS-PLAN-042, CR-SVC-61 closed; per-item isolation, partial-success toast) |
| Claim overdue public task (dialog → complete) | TaskList → `OverdueClaimDialog` | `GET /room-tasks/overdue` + complete | ✅ (claim target ≤24 px, UX-007) |
| Dedicated claimable-tasks query | — | `GET /room-tasks/overdue-claims` | ⛔ pruned (CS-PLAN-055; UI uses `/overdue` + client filter) |

## UC-10 — Planning: availability, energy, suggestions

**Goal:** balance workload by capacity and energy. **Personas:** all (self
service), Owner (overview). **Entry points:** SchedulingView.

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Edit own weekday availability | `SelfAvailabilitySettings` | `GET+PUT /scheduling/preferences` | ✅ changed-days-only saves |
| Owner availability grid (all members) | `AvailabilitySettings` | `GET /scheduling/members` | ✅ |
| Edit another member's availability | `AvailabilitySettings` → shared `AvailabilityEditModal` (owner) | `PUT /scheduling/members/{userId}` | ✅ (CS-PLAN-043 wired the edit path — UX-014 keep decision resolved: endpoint stays) |
| Edit energy budget (weekdays/weekend) | `EnergyBudgetSettings` | `GET+PUT /scheduling/preferences` | ✅ single bulk save |
| Load preview / capacity calendar | `SchedulePlanner`, `ScheduleCalendar` | client + suggestions | ✅ |
| Generate suggestions | `SchedulePlanner` | `POST /scheduling/suggestions` (`{ taskCount }`) | ✅ merged surface — the former Preview/Suggestions twin buttons are one component (CR-016-05) |
| Apply a suggestion to own week | `SchedulePlanner` per-day Apply button | `PUT /scheduling/preferences` (bulk) | ✅ CS-PLAN-043 — applies personal share of the suggested day's energy; real task assignments remain a manual step |

*Last verified: 2026-08-24 (CS-PLAN-043).*

## UC-11 — Progress & gamification

**Goal:** make contribution visible and rewarding. **Personas:** all.
**Entry points:** Dashboard, Leaderboard, Settings.

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Coin balance | ArcadeSection | `GET /minigames/coins` | ✅ |
| Game/global state (streaks, goal) | Dashboard | `GET /game/state` | ✅ |
| Leaderboard (podium, streak badges, "You") | Leaderboard | `GET /users` (store `getLeaderboard`); `GET /game/leaderboard` pruned (CS-PLAN-055 — it was test-only) | ✅ |
| Leaderboard period filter (week/month/all) | Leaderboard | — | ⛔ removed (CS-PLAN-041): `getLeaderboard` has no period parameter; a filter that changes nothing is dishonest UI |
| Medals overview | Leaderboard medal cards | `GET /game/medals` | ✅ (store-driven, CS-PLAN-041) |
| Medal evaluation trigger | Leaderboard mount (pre-pass) | `POST /game/check-medals` | ✅ (CS-PLAN-041) |
| Unlock & select title | Settings → `TitlePicker` | `GET+PUT /users/{id}/titles(/select)` | ✅ |
| Weekly goal progress | HouseholdGoalBar | client state | ✅ |
| Reward rules configuration (coin/combo values) | — | `GET+PUT /reward-config` | ⛔ HTTP surface pruned (CS-PLAN-055); `RewardConfigService` stays as the internal store (appconfig) |

## UC-12 — Minigames & boosts

**Goal:** spend coins, play, compete. **Personas:** all (child-relevant).
**Entry points:** ArcadeSection on Dashboard.

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Play Snake / Memory / 2048, save score | `SnakeGame`, `MemoryGame`, `Game2048` | `POST /minigames/{g}/scores` | ✅ (save via `endGame`) |
| Personal best / rank / recent scores | ArcadeSection, `SnakeLeaderboard` | `GET /minigames/{g}/personal-best`, `/rank`, `/recent` | ✅ |
| All-time + weekly game leaderboard | SnakeLeaderboard | `GET /minigames/{g}/leaderboard(/weekly)` | ✅ |
| Buy boost (coins) | `BoostShop` | `POST /minigames/boosts/{id}/purchase` | ✅ |
| Authoritative boost catalog (price/effect) | `BoostShop` / minigame store | `GET /minigames/boosts` | ✅ prices live server-side only (`BOOST_COSTS` deleted, UX-013) |

## UC-13 — Members, roles & household changes

**Goal:** run the household as a group. **Personas:** Owner (management),
Member (self). **Entry points:** Settings → MemberList (since CS-PLAN-038).

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Member list with roles | MemberList | `GET /users`, `GET /users/current` | ✅ |
| Invite member (email + role) | MemberList | `POST /users/invite` | ✅ |
| Change role (incl. promote to owner) | MemberList | `PUT /users/{id}/role` | ⚠️ self/last-owner guard is frontend-only (BUG-SEC-002) |
| Remove member (styled confirm) | MemberList | `DELETE /users/{id}` | ✅ server cleans up assignments |
| Edit member profile | — | `PUT /users/{id}` | ⛔ pruned (CS-PLAN-055 — route + dead `updateUserProfile` wrapper) |
| Per-member task statistics | — | `GET /users/{id}/stats`, `GET /room-tasks/user/{id}/stats` | ⛔ pruned (CS-PLAN-040 — dead, CR-SVC-31-flawed metric); per-member history → UC-16 |
| Leave house | — | — | ⛔ no backend (FEAT-UX-015) |

## UC-14 — Notifications & reminders

**Goal:** pull users back at the right moment. Currently **passive** (server
jobs only, no settings surface).

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Daily digest + due-today/tomorrow reminders | Nextcloud notifications (job) | background job | ✅ passive |
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
all. **Entry points:** `/insights` route (InsightsView, nav entry between
Leaderboard and Scheduling); RoomDetail stats card; Leaderboard freeze
badges. Shipped with CS-PLAN-040.

| Action | Where | Endpoint | Status |
|---|---|---|---|
| House overview cards (rooms clean/dirty/needing cleaning, house health) | InsightsView `HouseStatsCards` | `GET /house/stats` | ✅ |
| Fairness table (contribution + coins separate, load normalized by weekly capacity) | InsightsView `MemberFairnessTable` | `GET /users` + client aggregation over activity | ✅ (formula: [energy-and-balancing.md](energy-and-balancing.md)) |
| Activity feed "who did what when" (actor, task, room, combo, rewards) | InsightsView `ActivityFeed` | `GET /house/activity` | ✅ |
| Freeze visibility (owned count ❄ + "frozen" state after yesterday's consumption) | Leaderboard streak badge | `GET /users` (`streakFreezes`, `lastFreezeDate`) | ✅ |
| Overcapacity warning (deferred-first list) | SchedulingView `OvercapacityWarning` + ScheduleCalendar markers | client getters (`plannedEnergyForDay` / `overcapacityDays`) | ✅ |

---

## Cross-cutting conventions (apply to every UC)

- **Role gating:** hide owner-only controls from members instead of letting
  them fail with 403 (UX-004).
- **Destructive actions:** styled confirm modals; typed confirmation only for
  house reset (UX-006).
- **Touch targets ≥ 44 px** for complete/claim/primary actions; icon+text
  labels (UX-007).
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
| 12 | — |
| 13 | profile edit route pruned (CS-PLAN-055; re-needs expose), leave house (FEAT-UX-015); role guards backend-side ✅ since CS-PLAN-045; per-member history ✅ since CS-PLAN-040 (UC-16) |
| 14 | reminder-settings concept → FEAT-UX-024; task-sync surface → ⛔ pruned (CS-PLAN-055, FEAT-UX-021 closed) |
| 15 | complete/assign from a calendar day (W6) |
| all | documented-but-unbuilt ideas → *Whitespace* below (FEAT-UX-016…031) |

---

## Whitespace (⛔ candidates)

Documented-but-unbuilt intent and review ideas, recorded so they are not lost.
**This section is an index into `backlog.md` (FEAT-UX-*), not a second list.**
No expose-or-prune decision is pre-empted here (neutrality principle of the
endpoint audit). Decision tags: `build-new` · `expose` (endpoint exists, no
surface) · `needs-decision` · `post-MVP`.

### Group A — documented intent, no surface (7)

| Candidate | Decision tag | Concept-doc source | Backlog |
|---|---|---|---|
| ~~Completed-task history & activity log ("who did what when")~~ ✅ built as UC-16 (`GET /house/activity` + `ActivityFeed`) | `build-new` | [design.md §6 "Streak & History"](design.md) | FEAT-UX-016 (closed 2026-08-21) |
| ~~House insights & fairness view~~ ✅ built as UC-16 (`GET /house/stats` exposed, capacity-normalized fairness) | `expose` + `build-new` | [energy-and-balancing.md](energy-and-balancing.md) (fairness constraints) | FEAT-UX-017 (closed 2026-08-21) |
| Task-approval workflow for children (needs-review state, owner queue) | `needs-decision` | [design.md §5](design.md) + [gamification-and-rewards.md](gamification-and-rewards.md) (open decisions) | FEAT-UX-018 |
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
| Notification settings (digest toggle, quiet hours) | `build-new` | UC-14 reminders are passive-only | FEAT-UX-024 |
| Vacation / house-pause mode | `post-MVP` | Review whitespace 2026-08-21 | FEAT-UX-025 |
| Real-world reward shop (coins → owner-defined rewards) | `post-MVP` | [gamification-and-rewards.md](gamification-and-rewards.md) (reward language policy) | FEAT-UX-026 |
| Print view (weekly plan / calendar) | `post-MVP` | Review whitespace 2026-08-21 | FEAT-UX-027 |
| Global search across tasks | `expose` (`GET /tasks/search`, dead wrapper) | Endpoint audit 2026-08-21 | FEAT-UX-028 |
| Bulk maintenance ops (reschedule overdue, seasonal deactivate) | `post-MVP` | Review whitespace 2026-08-21 | FEAT-UX-029 |
| A11y & kids-mode conventions (keyboard/SR/reduced-motion, mobile-first; simplified child surface = open question) | `build-new` | Usability review 2026-08-20 (UX-007, UX-010) | FEAT-UX-030 |
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

> Kept deliberately: `PUT /tasks/{id}/uncomplete` + `POST /room-tasks/{id}/reactivate`
> (undo path, UC-07), `GET+PUT /room-tasks/{id}/assignees` (production:
> TodayView per-row resolution + editor/bulk assignment), and the 3 test
> harness routes below.
>
> CS-PLAN-040 previously removed the dead stats trio
> (`GET /users/{id}/stats`, `GET /room-tasks/user/{userId}`,
> `GET /room-tasks/user/{userId}/stats`) and CS-PLAN-042 added
> `POST /room-tasks/bulk-assign` (UC-09).

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
(`uncomplete` + conditional `reactivate`) ✅, boost catalog ✅
server-authoritative (`BOOST_COSTS` deleted), styled confirms replace native
`confirm()`. 2026-08-21 — CS-PLAN-040 added UC-16 and flipped the stats
routes; CS-PLAN-039 added the route coverage appendix (uniform
dead-store-wrapper annotations) and a Standard-B review corrected the UC-01
invite status, added the live consumers for `due-today` / `range` /
`GET assignees`, and added UC-15 (CalendarView was previously only a gap row
in UC-08).
