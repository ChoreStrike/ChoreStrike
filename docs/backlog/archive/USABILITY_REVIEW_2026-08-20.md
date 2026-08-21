# Usability & UI Review — ChoreStrike (2026-08-20)

> **Type:** Usability / UX / UI workflow review (not a code-correctness review).
> **Scope:** all 7 views, ~30 components, 13 Pinia stores in `src/`; endpoint
> inventory of `appinfo/routes.php` + 17 controllers mapped against actual UI
> usage. Static analysis; no live click-through.
> **Target personas:** adult household admin (owner) and child (member).
> **Findings:** actionable items logged in [`../backlog.md`](../backlog.md) →
> section *Review Follow-ups — Usability & UI Review (2026-08-20)* (UX-001 …
> UX-014). This file is the full record.

---

## 1. Workflow Documentation (as implemented)

### W1 — First run / house creation

```
open app → GET /house (auto-creates house, no naming) → Dashboard mount
  └─ floors empty → SetupWizard opens automatically
       Step 1 "Quick Start": home type (house/apartment) · floors 1–6 ·
              basement toggle · rooms/floor 1–6 · cleaning-level cards
       Step 2 "Room List":   rename room · change type (auto-renames) ·
              assign person per room · add/remove rooms · extra rooms
              ("Household & Co.", "Hallway & Stairs")
       Step 3 "Preview":     floor/room/≈task counts → "Let's go!"
              → POST /setup/apply (creates floors + rooms + tasks from templates)
       alt: "Start empty" → dismisses wizard for this session only
```

**Assessment: good.** Three steps, visual level cards, honest preview with task
estimates — friendly for parents, skippable for tinkerers. Gaps:

- No house-name step (rename only later in Settings; default name shown on Dashboard).
- Per-room "assign person" dropdown lists house members — at first run that is
  exactly one person (the owner), because **inviting members has no UI** (UX-003).
- "Start empty" is session-scoped (`wizardDismissed` is component state,
  `Dashboard.vue:97`) → the wizard re-opens on every reload while the house is
  empty (UX-011).
- No "invite your family" step at the end — the single biggest missed onboarding
  beat for a *family* app (UX-003).

### W2 — Structure management (floors / rooms)

```
Dashboard → FloorList card:
  Add Floor (modal: name) · rename (click name or ✎, inline input) ·
  delete (native confirm()) · expand/collapse · per-floor stats
  Add Room (modal: name + icon select with live preview) → RoomCard grid
  RoomCard click → RoomDetail
Settings → duplicate surface: Add/Edit/Delete floor modals
```

**Assessment: mixed.** Inline rename and the icon-preview room modal are nice.
Problems:

- **Room rename/delete has no UI anywhere** (`updateRoomName` / `deleteRoom`
  store actions exist, no button) — a mistyped room name is unfixable without
  deleting the whole floor. Backend endpoints ready (UX-004).
- **Floor reorder has no UI** — `POST /floors/reorder` + `reorderFloors` store
  action exist, but `FloorList.vue` has no drag&drop and no up/down control
  (UX-004).
- Floor management exists **twice** (Dashboard FloorList + Settings) with
  different modals — two code paths, inconsistent UX (UX-004).
- Members see owner-only controls (Add Floor, house rename, Reset) that fail
  server-side with 403 instead of being hidden — Settings has no role gating
  in the template (UX-004).
- Destructive actions use native `confirm()` while the app elsewhere uses
  styled modals (incl. a typed-`RESET` confirmation for house reset — good)
  (UX-006).

### W3 — Task creation (three inconsistent paths)

| Path | Entry | Form | Result |
|---|---|---|---|
| A | RoomDetail → *Add Task* (default) | `TaskTemplatePicker`: level pills, template list with *Available / Not in this level* badges, per-template **Adjust** (name, frequency, minutes, energy 1–5, priority 1–3) | `POST /room-tasks/from-template` — task appears in the room ✅ |
| B | RoomDetail → *Add Task* → tab *Custom task* | `TaskForm`: dropdown of **existing global task definitions**, due date, assignee as **free-text userId input** | `POST /room-tasks` — works, but requires a pre-existing definition; free-text assignee is developer UX |
| C | TaskManager → *New Task* | `TaskEditForm`: 15 fields (name, description, energy ★1–5, priority, minutes, category, frequency, dynamic/static + time + weekdays, flex days, scope room/floor/house, visibility) | `POST /tasks` — creates **only a task definition**. No RoomTask instance, no room picker → **the task appears in no list** (TaskManager lists room tasks). It only resurfaces later as an entry in Path B's dropdown |

**Assessment: the weakest part of the app (UX-001).** Path A is genuinely good —
template-first with level filtering is exactly right for parents and kids.
Path C is a trap: a user fills a 15-field form, presses *Create Task*, and the
task *vanishes* (verified: `TaskService::createTask` → `TaskMapper::create`
only; no materialization). Path B's assignee free-text field is a usability
dead end and invites typos. Additional inconsistency: RoomDetail's *edit*
persist **only the due date** — `handleUpdateTask` calls
`updateRoomTaskDueDate`; changing the task or assignee in that form is silently
ignored (UX-005).

### W4 — Assigning tasks

```
TaskList row → chevron "Show details" → member chips (multi-select)
  → PUT /room-tasks/{id}/assignees (optimistic, revert on error)
Overdue public task assigned to someone else → 24px ⊕ claim button
  → OverdueClaimDialog → complete
```

**Assessment: works, but hidden (UX-008).** Multi-assign chips with optimistic
updates are solid; the *Teamwork bonus* hint is a nice motivational touch. But
assignment lives **only** inside an expanded row — not in any create form, not
in the calendar, no bulk-assign despite the ready backend (`CR-SVC-61`). The
claim button is 24×24 px — hard target for kids (UX-007).

### W5 — Completing tasks & rewards

```
○ complete button → POST /room-tasks/{id}/complete
  → rewards payload → confetti + sound + toast
  → events: task-completed (combo sound), streak milestone (7/30/100),
    title-unlocked, weekly-goal-reached, streak-freeze-used
```

**Assessment: the strongest part (keep!).** Celebration feedback, streak
milestones, energy bookkeeping into scheduling, weekly-goal bar — this is the
game loop working end-to-end. Gaps:

- **No undo.** A misclicked completion cannot be reverted
  (`uncomplete`/`reactivate` endpoints exist without UI) (UX-006).
- Checklist is toggle-only; the full editor UI (`TaskChecklist.vue`
  add/edit/delete/reorder) is permanently locked with `can-edit="false"`
  (`TaskList.vue:176`) — items can only ever originate from templates (UX-009).

### W6 — Daily orientation ("what do I do today?")

- Dashboard `QuickStats` shows counts (Due Today / Overdue) — the cards are
  **not clickable**; only a generic *View All Tasks* button exists.
- The dedicated daily list (`DailyTaskList.vue`, with progress bar and
  celebrations) is **orphaned** — mounted nowhere (cf. `CR-016-09`).
- The child-aware `GET /room-tasks/filtered-daily` endpoint has **no UI**.
- Calendar view is read-only (no complete/assign from a day).

**Assessment: critical persona gap (UX-002).** A child (or a parent in a hurry)
has no single "these are my tasks today — check them off" screen. The user must
navigate room-by-room or use the adult-oriented Task Manager filters.

### W7 — Gamification surfaces

- Dashboard: `HouseHealthBar`, `HouseholdGoalBar`, `ArcadeSection` (3 minigames,
  coin balance, personal bests), `BoostShop` (Snake boosts).
- Leaderboard: podium + table, streak badges, "You" highlight — but the period
  filter (**This Week / Month / All Time**) has **no effect** (no watcher, no
  refetch) and the medals section is a hardcoded empty array (UX-005).
- Server `GET /game/leaderboard`, `GET /game/medals`, `POST /game/check-medals`
  are unused; `gameStore.initializeGame()` is only called by an
  `initializeStores()` that nothing invokes → **medal celebrations are
  unreachable** in the running app (UX-005).
- Boost prices are **duplicated client-side** (`BOOST_COSTS` in
  `store/minigame.js`) while the authoritative `GET /minigames/boosts` catalog
  is never fetched — drift/time bomb (UX-013).
- Titles: Settings → *Change title* → `TitlePicker`; unlocks celebrated. Good.

### W8 — Scheduling & energy

`SchedulingView` stacks six sections: self availability → owner overview →
energy budget → preview → capacity calendar → suggestions.

**Assessment: overloaded page with redundant editors (UX-014).** Owners see two
parallel editors for their own availability (self section + own-card edit in
the owner grid); the owner grid is read-only for everyone else (the
`PUT /scheduling/members/{userId}` endpoint is unused) — acceptable as a
self-service policy but the UI implies management. *Load Preview* and
*Refresh Suggestions* both hit the same endpoint (cf. `CR-016-05` merge), and
suggestions are text-only — no *apply* action connects them to actual
assignment.

### W9 — Members & settings

Settings offers: title picker, house rename (silent save, no toast), floor
management (duplicate of Dashboard), Danger Zone (typed `RESET` — good).

**Missing entirely (UX-003):** member management. `POST /users/invite`,
`DELETE /users/{id}`, `PUT /users/{id}/role` (incl. promote-to-owner),
`PUT /users/{id}` are implemented in backend **and** as store actions — with
**zero buttons**. There is also no *leave house* capability (no backend at
all). The Leaderboard empty state even advertises *"Invite family members to
start competing!"* — an invitation with no call to action.

---

## 2. Heuristic evaluation vs. stated goals

**Goals:** easy for adults *and* kids · not overloaded · colorful · all
necessary functions.

| Goal | Verdict | Evidence |
|---|---|---|
| Easy for adults | ⚠️ mostly | Good wizard, template picker, celebrations; broken task-creation paths (W3), hidden assignment (W4) |
| Easy for kids | ❌ weakest | No today-view (W6), 24–28 px touch targets vs. the 44 px the app's own `mobile.css` demands, icon-only buttons with `title` tooltips, abstract terms (*Admin-Only*, *Dynamic/Static*, *Scope*, ★-energy without explanation), no role-based simplification although a child-filtered endpoint exists |
| Not overloaded | ⚠️ split | Dashboard & RoomDetail are clean; SchedulingView (6 stacked sections) and TaskEditForm (15 fields) are admin-panel territory |
| Colorful / appealing | ✅ good | Health bar, goal bar, tier/rank/celebration tokens, gradients, confetti; **but** many hardcoded colors bypass the `--cs-*` tokens and NC vars (RoomDetail stats, FloorList action buttons, TaskList badge fallbacks) → dark-mode & consistency drift (UX-010) |
| All necessary functions | ❌ | Backend is ahead of the UI by a wide margin: ~35 of 111 endpoints have no UI consumer, including whole features (members CRUD, NC-Tasks sync, medal evaluation, reward config, checklists write-path, floor reorder, room rename/delete, today/child lists) |

**Solid foundations worth keeping:** consistent `t()` i18n + `data-testid`
hooks everywhere; skeleton loaders and empty states in all core lists;
reduced-motion + dark-mode tokens in `tokens.css`; typed-confirmation for
destructive reset; optimistic assignee updates with rollback.

---

## 3. Prioritized recommendations

### P1 — fix the broken core
1. **Unify task creation (UX-001):** make every create path end in a room task.
   In `TaskEditForm`: scope *room* → room picker (floors→rooms), *floor* →
   floor picker, *house* → confirm-all; on submit materialize via
   `POST /room-tasks` (or a scope-aware backend call). Replace Path B's
   free-text assignee with the member chips. Keep the template picker as the
   default in rooms.
2. **Ship a "Today" view (UX-002):** revive `filtered-daily`/`DailyTaskList`
   behind a proper route — personalized list, big checkboxes, immediate coin /
   streak feedback; make the Dashboard *Due Today* / *Overdue* stat cards links
   into it (pre-filtered).
3. **Members section in Settings (UX-003):** invite by email, member list with
   role, remove, promote-to-owner; add *leave house* (needs backend). Add an
   invite step at the end of the setup wizard.

### P2 — close the credibility gaps
4. Room rename/delete in RoomDetail header; floor reorder (drag or ↑/↓);
   de-duplicate floor management (keep Dashboard, link from Settings) (UX-004).
5. Remove or repair dead controls: Leaderboard period filter (re-fetch or
   delete), medals (wire `GET /game/medals` + `check-medals`), QuickStats
   private filter (event is emitted but nobody listens), RoomDetail edit form
   (persist what it shows) (UX-005).
6. Undo for completion (5 s snackbar → `uncomplete`/`reactivate`); replace
   native `confirm()` with styled dialogs; success toasts for Settings saves
   (UX-006).
7. Kid sizing: ≥44 px touch targets for complete/claim/actions, icon+text (or
   aria-labelled larger) buttons, one priority vocabulary across all surfaces
   (UX-007).

### P3 — polish & alignment
8. Assignment in create forms + bulk-assign (UX-008); unlock the checklist
   editor or remove it (UX-009); color-token sweep + refresh stale
   `mobile.css` selectors (UX-010); wizard skip persistence + house-name step
   (UX-011); Modal ESC/focus-trap/aria (UX-012); boost catalog from server
   (UX-013); scheduling consolidation + suggestion *Apply* (UX-014).
9. **Decision item:** ~35 unused endpoints — either expose (stats, NC-Tasks
   sync, reward config UI) or prune; carrying both halves is the most expensive
   option. Related existing items: `BUG-USER-001`, `CR-SVC-61`, `CR-016-09`,
   `CR-016-05`, `CR-016-03`.

---

## 4. Full findings index (logged in backlog.md)

| ID | Pri | Summary |
|---|---|---|
| UX-001 | P1 | Task creation: 3 inconsistent paths; TaskManager creates invisible definitions; free-text assignee |
| UX-002 | P1 | No "Today / my tasks" surface; stat cards not clickable; DailyTaskList orphaned |
| UX-003 | P1 | Member management (invite/remove/role/promote/leave) has no UI |
| UX-004 | P2 | Room rename/delete & floor reorder UI-less; floor mgmt duplicated; member-visible owner controls |
| UX-005 | P2 | Dead/misleading controls: leaderboard filter, medals placeholder, private-filter event, RoomDetail edit |
| UX-006 | P2 | No completion undo; native confirms; silent Settings saves |
| UX-007 | P2 | Touch targets below 44 px; icon-only buttons; inconsistent priority vocabulary; abstract terms |
| UX-008 | P3 | Assignment hidden behind expand; absent from create forms; no bulk-assign |
| UX-009 | P3 | Checklist editor implemented but permanently locked |
| UX-010 | P3 | Hardcoded colors bypass tokens; stale mobile.css selectors |
| UX-011 | P3 | Wizard skip session-only; no house-name step; no invite step |
| UX-012 | P3 | Modal a11y (ESC, focus trap, aria); unlabelled calendar nav |
| UX-013 | P3 | Boost prices duplicated client-side; server catalog unused |
| UX-014 | P3 | SchedulingView redundant editors; duplicate preview/suggestions; no apply action |

*Record closed 2026-08-20. Reviewer: Kilo (main agent), with two read-only
explore subagents for endpoint mapping and secondary-view inventory; all
load-bearing claims (invisible task definitions, missing floor drag&drop, dead
filters, unbound events) verified by direct file reads.*
