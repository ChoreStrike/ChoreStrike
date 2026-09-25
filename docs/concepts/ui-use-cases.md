# UI Use Cases & GUI Element Inventory

## Current Workflow (WP9, September 2026)

This matrix supersedes the August endpoint audit below for shipped core
behavior. The older audit remains historical context, not a promise that
its pruned routes or superseded write bodies are available.

| Surface | Current behavior and authority |
|---|---|
| First owner and setup | First eligible bootstrap joins the owner; Settings/setup wizard writes the complete house/floor/room/template aggregate once. Later users require invitations. |
| Invitations and roles | Owners manage membership; members manage visible tasks; children execute tasks and their own availability but do not manage definitions. |
| Task creation | TaskManager or a room opens the floor/room/assignee form; `POST /room-tasks` atomically creates/mounts the aggregate. Room templates use `POST /room-tasks/from-template` with an explicit catalog level. |
| Room task editing | Due date and assignees are the narrow occurrence edit; definition edits remain a separate task form. Both forms expose member-load errors and retry without discarding input. |
| Scheduling | Owner preview is read-only, one `POST /scheduling/apply` persists the selected rows, and My Day/Calendar reconcile from canonical stores. |
| Completion and undo | One complete receipt updates wallet/contribution/streak/room/checklist projections before events. The queued undo restores the recorded inverse, including after reload; medals and sent notifications are not revoked. |
| Child/mobile | Bare-root child entry lands on My Day. Focused member/child mobile tests retain 44px controls, role gating and checklist execution. |
| Localization | Real Nextcloud DE and EN rendering is tested in `family-core-ux.spec.ts`; source/catalog ownership and artifacts are enforced globally. |
| Arcade | Snake is the accepted score/reward game. The retained 2048/Memory component prototypes do not expand the backend game whitelist. |

Contracts: [API](../api/README.md), [task/undo model](task-model-and-sync.md),
[testing and deterministic gate](../engineering/testing.md).

## Approved Calm UX and Evidence C5 (2026-09-17)

Target behavior, not the shipped inventory above or the historical audit below.
The [clarification plan](../plans/new/cs-plan-070-family-rhythm-and-reward-clarification.md)
records thirteen explicit C5 answers; exact files/APIs/l10n and evidence execution
remain implementation work.

| Surface | Approved target |
|---|---|
| Entry after setup | My Day for adults and children: today's personal agreements and next appointments. Keep planning, household and open requests reachable, without making arcade/catalog/rankings the primary area. |
| Catch-up/overload | Separate catch-up without stacks of identical cards; show overload as a clarification need, not a demand to clear all accumulated work. |
| Progress | Small confirmed personal streak/shared weekly progress line, with pending/provisional and neutral states distinguished. A free day is a neutral empty view, not an invitation to manufacture work. |
| Completion | Short visible confirmation with undo access, no default sound/confetti or interrupting award modal. Playful effects require personal opt-in. |
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
| 01 | First-run setup & house creation | Dashboard → SetupWizard |
| 02 | House administration | Settings, Dashboard |
| 03 | Structure: floors | FloorList (Dashboard), Settings |
| 04 | Structure: rooms | FloorList, RoomDetail |
| 05 | Task definitions (catalog) | TaskManager |
| 06 | Task creation (materialize room tasks) | RoomDetail, TaskManager |
| 07 | Task lifecycle maintenance | RoomDetail, TaskManager, TaskList |
| 08 | Daily execution ("My Day") | TodayView, TaskList |
| 09 | Assignment & teamwork | TaskList, TodayView |
| 10 | Planning: availability, energy, task plans | SchedulingView |
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
| House naming step | SetupWizard step 1 → apply | `POST /setup/apply` + `PUT /house` | ⚠️ two-step save; a failed name update is logged but setup still reports success (workflow review 2026-09-02) |
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
| Due date at creation | TaskEditForm | `POST /room-tasks` (`nextDue`) | ⚠️ backend field exists, but TaskEditForm does not expose or send it (workflow review 2026-09-02) |
| Partial-success feedback on chained creation | toasts | — | ✅ |

## UC-07 — Task lifecycle maintenance

**Goal:** keep room tasks correct over time. **Personas:** Owner. **Entry
points:** RoomDetail (slim modal), TaskManager, TaskList.

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Edit due date | RoomDetail → `RoomTaskEditModal` | `PUT /room-tasks/{id}/due-date` | ✅ |
| Edit assignees | RoomTaskEditModal, TaskList expanded row | `PUT /room-tasks/{id}/assignees` | ✅ |
| Delete room task | RoomDetail, TaskManager | `DELETE /room-tasks/{id}` | ✅ |
| Deactivate (pause) task | — | `POST /room-tasks/{id}/deactivate` | ⛔ pruned (CS-PLAN-055; `reactivate`/`uncomplete` followed by CS-PLAN-064) |
| Undo a completion (snackbar) | global `UndoSnackbar` queued list (~5 s per entry, UX-015) | `POST /room-tasks/{id}/undo` — ONE inverse aggregate | ✅ exact server-side restore: recurrence, ledger, rewards/streak, cleanliness, checklist (CS-PLAN-064/ADR-011; the CS-PLAN-041 `uncomplete`+conditional-`reactivate` chain was pruned, D5) |

## UC-08 — Daily execution ("My Day")

**Goal:** one surface answering "what do I do today?" — the core loop for
Member/child. **Entry points:** `/today` route; Dashboard stat cards link in;
**role-based landing (CS-PLAN-066 D4): a child's initial bare-root visit opens
My Day automatically** (explicit deep links and later Dashboard visits stay
usable; owners/members keep the Dashboard default).

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Personalized daily list (child-aware limits) | TodayView | `GET /room-tasks/filtered-daily` | ✅ CS-PLAN-065 (D4): child eligibility (own-or-unassigned) runs before the availability gate and the child limit; the read order is `nextDue, createdAt, id` and rows embed their canonical `assignees` |
| My tasks ↔ Everyone filter | TodayView | client-side | ✅ (default: My tasks) |
| Overdue section | TodayView | `GET /room-tasks/overdue` | ✅ |
| Short household instructions per row | TodayView | definition `instructions` (falls back to `description`) | ✅ CS-PLAN-066 §3.5 (plain escaped text, never v-html) |
| Optional expandable checklist in My Day | TodayView → `TaskChecklist` (execute-only: `can-edit=false`, frozen when completed) | `GET /checklists/task/{id}`, `GET /checklists/progress/{rt}`, `POST /checklists/toggle/{rt}/{item}` | ✅ CS-PLAN-066 §3.5 (progress refreshes after the server-side occurrence reset on completion; checklist-less rows hide the toggle after the one-shot empty discovery — final-review a11y fix 2026-09-12) |
| Complete task (celebration: confetti/sound/toast, combo, streak) | TodayView, TaskList, RoomDetail, TaskManager | `POST /room-tasks/{id}/complete` | ✅ (CS-PLAN-065 D7 removed the dead per-completion energy-counter store write; CS-PLAN-066 reconciles every owning store before the events and re-syncs the titles store before the title-unlocked event fires once per response) |
| Stat-card counts + deep links (Due Today / Overdue) | QuickStats | `GET /room-tasks/due-today`, `GET /room-tasks/overdue` | ✅ CS-PLAN-066 (D2): the counters read dedicated endpoint-backed projections loaded on the cold Dashboard; a scoped room load or the child-limited daily list can never overwrite them; the coordinated derived refresh (D3) keeps them truthful after mutations |
| Per-row assignee resolution | TodayView | daily AND overdue rows embed canonical `assignees` (D1) | ✅ CS-PLAN-066 (no per-row re-fetch remains) |
| Partial derived-refresh failure | TodayView | read-only retry of the loaded projections | ✅ CS-PLAN-066 (D3): a committed action stays successful; stale slices offer a refresh-only retry |
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

## UC-10 — Planning: availability, energy, task plans

**Goal:** balance workload by capacity and energy, and let the owner confirm
a real household schedule. **Personas:** all (self service), Owner (plan
preview/apply). **Entry points:** SchedulingView.

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Edit own weekday availability | `SelfAvailabilitySettings` | `GET+PUT /scheduling/preferences` | ✅ changed-days-only saves |
| Owner availability grid (all members) | `AvailabilitySettings` | `GET /scheduling/members` | ✅ |
| Edit another member's availability | `AvailabilitySettings` → shared `AvailabilityEditModal` (owner) | `PUT /scheduling/members/{userId}` | ✅ (CS-PLAN-043 wired the edit path — UX-014 keep decision resolved: endpoint stays) |
| Edit energy budget (weekdays/weekend) | `EnergyBudgetSettings` | `GET+PUT /scheduling/preferences` | ✅ single bulk save |
| Set a child's daily-task limit | `AvailabilityEditModal` (owner, non-self only) | `PUT /scheduling/members/{userId}` (`maxDailyTasks`) | ✅ CS-PLAN-065 (decision D5): omitted = unchanged, `null` = unlimited, 1-100 = finite bound; the personal endpoints reject the key |
| Capacity calendar (members × days) | `ScheduleCalendar` | `GET /scheduling/members` + client getters | ✅ |
| Preview a real-task plan | `SchedulePlanner` (owner only) | `POST /scheduling/suggestions` (`startDate` + selected `roomTaskIds` + optional `overrides`) | ✅ CS-PLAN-065 — deterministic proposal over real room tasks with per-row before/proposed, warnings and capacity report; the retired `{ taskCount }` advice contract is rejected |
| Adjust a proposal | `SchedulePlanner` per-row person/day selects (from the preview's `legalDates`) | edit marks the preview stale → re-preview | ✅ CS-PLAN-065 — confirmation of an edited/stale preview is disabled |
| Exact-share labels on a proposal | `SchedulePlanner` change rows (`proposed.shares`) | preview response | ✅ CS-PLAN-073 WP2 — whole-percent wallet-order shares (e.g. 34/33/33) rendered per change row |
| Neutral/overload capacity clarifications | `SchedulePlanner` capacity grid | preview `capacity` report | ✅ CS-PLAN-073 WP2 — `Paused`, `Away` and `{planned}/{budget} — over capacity` labels; existing overload stays visible |
| Consent attestation before apply | `SchedulePlanner` consent checkbox (`planner-consent`) | rides `POST /scheduling/apply` as `consent: true` | ✅ CS-PLAN-073 WP2 — apply is disabled until the owner attests the agreement; a fresh preview requires a fresh attestation; the attestation survives a 409 retry with the retained draft and command UUID |
| Apply the confirmed plan | `SchedulePlanner` Apply button (owner only) | `POST /scheduling/apply` (exact input + `previewVersion` + `commandId` + `consent`) | ✅ CS-PLAN-065/073 — one atomic CommandService batch; a stale confirmation (incl. a local-midnight crossing) is a clean 409 with zero writes; identical retries replay the receipt; Calendar and My Day refresh from the reconciled stores |
| Pause lifecycle (request/decide/shorten/illness) | **no dedicated UI yet (WP6)** — frozen HTTP contract | `POST/PUT /pauses*` | ✅ API CS-PLAN-073 WP2 — personal requests (child → pending), owner house pauses/approvals/illness recognition, acknowledged-only shortening; UI surfaces stay WP5/6 |
| Occurrence agreements (amend/catch-up/restart) | **no dedicated UI yet (WP6)** — frozen HTTP contract | `POST /occurrences/{key}/{amend,catch-up,restart}` | ✅ API CS-PLAN-073 WP2 — owner-recorded, receipt-identity commands with version/token gates and persisted neutral releases |

*Last verified: 2026-09-24 (CS-PLAN-073 WP2 fix round).*

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
| Play Snake and save score | `SnakeGame` | `POST /minigames/snake/scores` | Supported |
| Play Memory / 2048 prototypes | `MemoryGame`, `Game2048` | shared score-save call uses unsupported game IDs | Playable prototypes; no supported score/reward persistence |
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
| Change role (incl. promote to owner) | MemberList | `PUT /users/{id}/role` | Supported; self-role and last-owner guards enforced by `HouseService` and API tests |
| Remove member (styled confirm) | MemberList | `DELETE /users/{id}` | ✅ server cleans up assignments |
| Edit member profile | — | `PUT /users/{id}` | ⛔ pruned (CS-PLAN-055 — route + dead `updateUserProfile` wrapper) |
| Per-member task statistics | — | `GET /users/{id}/stats`, `GET /room-tasks/user/{id}/stats` | ⛔ pruned (CS-PLAN-040 — dead, CR-SVC-31-flawed metric); per-member history → UC-16 |
| Leave house | — | — | ⛔ no backend (FEAT-UX-015) |

## UC-14 — Notifications & reminders

**Goal:** give useful information about agreed chores without engagement
pressure. Currently server jobs only, with no ChoreStrike settings surface,
digest aggregation, quiet hours or per-recipient notification budget.

| Action | Where | Endpoint | Status |
|---|---|---|---|
| Individual due-today/tomorrow and streak-at-risk reminders | Nextcloud notifications (job) | background job | Implemented; same-day dedup is not a digest or a delivery-time guarantee |
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
| 12 | Unsupported Memory/2048 score-save integration; reward/arcade disposition in CS-PLAN-070 |
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
| Notification settings (opt-in summary, quiet hours) | Selected for the reliable core; not implemented | CS-PLAN-070 D9 | FEAT-UX-024 |
| Dated member/house pauses, with child approval | Selected for the reliable core; not implemented | CS-PLAN-070 D4 | FEAT-UX-025 |
| Optional family reward catalog | Selected after the reliable core; not implemented | [Approved target](gamification-and-rewards.md) | FEAT-UX-026 |
| Print view (weekly plan / calendar) | `post-MVP` | Review whitespace 2026-08-21 | FEAT-UX-027 |
| Global search across tasks | `expose` (`GET /tasks/search`, dead wrapper) | Endpoint audit 2026-08-21 | FEAT-UX-028 |
| Bulk maintenance ops (reschedule overdue, seasonal deactivate) | `post-MVP` | Review whitespace 2026-08-21 | FEAT-UX-029 |
| A11y & child My Day baseline implemented; planning-first quiet defaults and opt-in ranking selected | Baseline delivered in CS-PLAN-066; new target in CS-PLAN-070 D10, not implemented | [Product vision](product-vision.md) | FEAT-UX-030 (historical); new scope owned by CONCEPT-001 |
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
> `POST /room-tasks/{id}/undo`; the split uncomplete/reactivate pair was pruned), `GET+PUT /room-tasks/{id}/assignees` (production:
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
(`uncomplete` + conditional `reactivate`; a single undo aggregate since
CS-PLAN-064, UC-07) ✅, boost catalog ✅
server-authoritative (`BOOST_COSTS` deleted), styled confirms replace native
`confirm()`. 2026-08-21 — CS-PLAN-040 added UC-16 and flipped the stats
routes; CS-PLAN-039 added the route coverage appendix (uniform
dead-store-wrapper annotations) and a Standard-B review corrected the UC-01
invite status, added the live consumers for `due-today` / `range` /
`GET assignees`, and added UC-15 (CalendarView was previously only a gap row
in UC-08).
