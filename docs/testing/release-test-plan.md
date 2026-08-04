# ChoreStrike — Manual Release Test Plan

> **Status:** Authoritative manual test plan and the **single source of truth**
> for pre-release manual validation. Supersedes the former `manual-test-checklist.md`,
> `alpha-test-guide.md`, `family-test-setup.md`, and `critical-tests.md` (archived
> 2026-07 under `archive/2026-07/testing-docs/`).
> **Scope:** manual, human-judgement testing only. Items already covered reliably
> by automation are marked **Automated** with a pointer and need **not** be
> re-executed manually unless called out by a stage protocol.
> **Rule (release gate):** **a release tag (alpha / beta / stable) requires a
> completed Execution & Sign-off record.** See
> [`../release/release-strategy.md`](../release/release-strategy.md) exit criteria
> and [`../engineering/deployment.md`](../engineering/deployment.md) pre-release step.

---

## 1. How to Use This Plan

### 1.1 What is manual-only here

Automated coverage (PHPUnit unit/integration + Jest + Playwright E2E) already
guards the deterministic happy/sad paths. The manual plan focuses on what
machines cannot judge:

- Exploratory UX and "feel".
- Mobile / responsive layout and touch behaviour on real devices.
- Performance feel under realistic load.
- Multi-user social flows on a real Nextcloud instance.
- Upgrade / migration smoke on a personal instance.
- Cross-browser and edge-case behaviour that is expensive to automate.

If an item is reliably covered by an E2E spec, it is marked **Automated** with
the spec name. The manual tester may spot-check it but is not required to.

### 1.2 Stage map

| Section | v0.8 Alpha | v0.9 Beta | v1.0 Stable |
|---|---|---|---|
| 3. First-run & house setup | full | full | full |
| 4. Floors & rooms | full | full | full |
| 5. Task lifecycle | full | full | full |
| 6. Task calendar & daily views | full | full | full |
| 7. Visibility & roles | full | full + new-feature focus | full |
| 8. Gamification | full | full | full |
| 9. Minigames & boosts | full | full | full |
| 10. Overdue claiming & teamwork | full | full | full |
| 11. Scheduling (member availability & energy) | full | full | full |
| 12. Multi-user on one instance | full (Docker family) | full + real instance | full |
| 13. Notifications | smoke | full | full |
| 14. Mobile / responsive | smoke | full | full |
| 15. Performance & stress feel | smoke | full | full |
| 16. Upgrade / migration smoke | — | smoke | **required** (personal instance) |
| 17. Error handling & edge cases | spot-check | full | full |

**Legend:** *full* = every row executed; *smoke* = the bolded/representative rows;
*spot-check* = a few representative rows; *—* = not required for that stage.

### 1.3 Test environments

Run the relevant stage protocols against these environments (translated from the
former 4-phase alpha guide):

| Environment | Purpose | DB | Accounts |
|---|---|---|---|
| **Docker — empty** | First-run / scratch flow from a clean install | empty | `admin` |
| **Docker — `family` profile** | Multi-user, role, and realistic-data flows | seeded | `mom`(owner), `dad`(owner), `teen`(child), `kid`(child), `grandma`(member), `friend`(member) |
| **Personal Nextcloud instance** | Real-world feel + upgrade/migration smoke | your data (**back up first**) | your real accounts |

Docker bring-up commands live in
[`../engineering/testing.md`](../engineering/testing.md) and
[`../engineering/deployment.md`](../engineering/deployment.md). To seed the
family profile in the test container:

```powershell
docker compose -f docker-compose.test.yml down -v
$env:TEST_DB_PROFILE='family'; docker compose -f docker-compose.test.yml up --build
# or, against a running container:
docker exec chorestrike-test php occ chorestrike:test-data:seed --profile=family
```

---

## 2. Automated Coverage Pointers

Before manual testing, confirm the automated gate is green (it is the first line
of defence — see [`../engineering/testing.md`](../engineering/testing.md) for the
full inventory and how to run it). Representative automated coverage (Playwright
E2E spec basenames under `tests/e2e/`, plus PHPUnit suites):

- Core CRUD / scratch flow: `gui-scratch-flow`, `smoke-tests`, `core-functionality`, `3-floor-house`
- Floors/rooms: `room-management`, `room-detail`
- Tasks: `task-manager`, `task-completion`, `task-completion-full-flow`, `task-checklist-and-features`, `daily-tasks-flow`
- Visibility: `private-tasks`, `admin-only-tasks`
- Gamification: `leaderboard`, `title-selection`, `teamwork-rewards`
- Overdue/teamwork: `overdue-task-claiming`, `teamwork-rewards`, `multi-user-interactions`
- Scheduling/calendar: `scheduling`, `availability-settings-flow`, `flex-window-scheduling`, `calendar`
- Dashboard/nav: `dashboard`, `dashboard-navigation`
- Minigames: `snake-game`
- Settings: `settings`
- Errors/validation: `error-handling`, `validation`

> **Drift rule:** if an E2E spec comes to cover a manual item reliably, mark the
> manual row **Automated** (with the spec pointer) instead of removing the row —
> the row still documents the expected behaviour for exploratory spot-checks.

---

## 3. First-Run & House Setup

Route surface: `page#index`, `house#get/update/stats/health/reset`.

| Check | Steps | Expected | Automated? |
|---|---|---|---|
| App install & enable | Enable `chorestrike` via `occ` / app list | App appears in launcher, main page loads, no errors in `nextcloud.log` | — |
| First-run house bootstrap | Open the app for the first time as a new user | A house is created automatically and the Dashboard renders | `gui-scratch-flow` |
| House name update | Edit the house name in Settings | Name persists after reload | — |
| House health | Complete/skip a few tasks, reopen Dashboard | House health bar reflects recent activity | `dashboard` |
| House reset (owner only) | Trigger house reset as owner, then as a non-owner | Owner succeeds; non-owner is denied (403) | — |

---

## 4. Floors & Rooms

Route surface: `floor#*`, `room#*`.

| Check | Steps | Expected | Automated? |
|---|---|---|---|
| Create floor | Add a floor with a name | Floor appears in the floor list | `3-floor-house` |
| Edit floor | Rename a floor | Name updates and persists | — |
| Delete empty floor | Delete a floor without rooms | Floor removed | — |
| Delete floor with rooms | Delete a floor that contains rooms | Floor and its rooms are removed | — |
| Reorder floors | Move a floor up/down | Order persists after reload | — |
| Create room | Add a room to a floor | Room appears under the floor | `room-management` |
| Edit room | Rename a room | Name updates | — |
| Delete room (with tasks) | Delete a room that has tasks | Room and its room-tasks removed | — |
| Room cleanliness | Complete tasks in a room | Room cleanliness percentage updates | `room-detail` |
| Room detail navigation | Open a room from the Dashboard / floor list | Room detail view loads and is navigable | `room-detail` |

---

## 5. Task Lifecycle

Route surface: `task#*`, `room_task#*`, `checklist#*`.

| Check | Steps | Expected | Automated? |
|---|---|---|---|
| Create task | Add a task (set energy, priority, tier, frequency) | Task appears in the task list / room | `task-manager` |
| Complete task | Mark a room-task complete | Status changes, coins awarded, celebration fires | `task-completion` |
| Uncomplete task | Revert a completion | Status restored, coins adjusted consistently | `task-completion-full-flow` |
| Skip task | Skip a task | Task skipped without an unfair penalty | — |
| Reschedule task | Change the due date | Due date updates | — |
| Recurrence | Complete a recurring (daily/weekly/monthly) task | A new instance is generated per frequency | `daily-tasks-flow` |
| Assignees | Assign / unassign members; multi-assign | Assignee set persists; multi-assign works | `task-manager` |
| Deactivate / reactivate | Deactivate then reactivate a room-task | Task hides from active lists, then returns | — |
| Checklist items | Add / toggle / reorder / delete checklist items on a task | Progress tracked; room-task checklist progress updates | `task-checklist-and-features` |
| Task templates | Create from / import a task template | Task created with template defaults | `task-manager` |

---

## 6. Task Calendar & Daily Views

Route surface: `/calendar` view, `room_task#range/dueToday/dueWithinDays`.

| Check | Steps | Expected | Automated? |
|---|---|---|---|
| Calendar renders | Open **Calendar** in nav | Week/rolling-30-day grid renders with member/task rows | `calendar` |
| Week navigation | Move to next/previous week | Range label and grid update | `calendar` |
| Recurring occurrences | View a recurring task across weeks | Future occurrences projected server-side | `calendar` |
| Daily list | Open the daily task list | Today's due tasks shown per member | `daily-tasks-flow` |

---

## 7. Visibility & Roles

Route surface: `task#myPrivate`, `visibility_type` (`public`/`private`/`admin-only`), `user#invite/remove/updateRole/update`, `reward_config#*`.

> No group-assignment UI exists (archived in CS-011). Membership & roles are
> managed via invite/remove/role.

| Check | Steps | Expected | Automated? |
|---|---|---|---|
| Private task | Create a `private` task as a member | Visible only to its owner; hidden from others | `private-tasks` |
| Admin-only task | Create an `admin-only` task | Visible only to house owners | `admin-only-tasks` |
| Child filtering | Log in as a `child` role user | Child sees only age-/role-appropriate tasks | `multi-user-interactions` |
| Invite member | Owner invites a Nextcloud user | Invite succeeds; user joins the house | — |
| Remove member | Owner removes a member | Member loses house access | — |
| Change role | Owner changes a member's role (`owner`/`member`/`child`) | Role change persists and affects visibility | — |
| Owner-only config | Open reward configuration as owner vs. non-owner | Owner can read/edit; non-owner is denied | `settings` |

---

## 8. Gamification

Route surface: `game#state/leaderboard/medals/checkMedals`, `house#health`, `user#titles/selectTitle`.

> Currency is **coins** (the legacy "sparks" wording is retired — do not use it).

| Check | Steps | Expected | Automated? |
|---|---|---|---|
| Earn coins | Complete a task | Coins added to the user total; `coins_earned` recorded | `task-completion` |
| Coin tier / early bonus | Complete a gold/early task | Higher payout (tier × early multiplier) applied | PHPUnit `GameLogicServiceTest` |
| Combo | Complete several tasks within the combo window | Combo indicator shows; combo bonus (×2, configurable) applied | `teamwork-rewards` |
| Combo break | Wait past the window, then complete a task | Combo resets | — |
| Streak & freeze | Maintain a daily streak; simulate a missed day | Streak grows; a streak freeze is consumed instead of resetting | PHPUnit `GameLogicServiceTest` |
| Medals | Trigger each live medal type | `team_anchor`, `streak_keeper`, `flow_keeper`, `room_guardian`, `team_player` award correctly | PHPUnit `GameLogicServiceTest` |
| Titles | Unlock and select a title | Title badge updates; selection persists | `title-selection` |
| Leaderboard | Open **Leaderboard** in nav | Members ranked by contribution/coins; streak view available | `leaderboard` |
| House health | Drive overall completion up/down | House health bar reflects aggregate activity | `dashboard` |

---

## 9. Minigames & Boosts

Route surface: `minigame#*`. Components: Snake, 2048, Memory, BoostShop (Dashboard arcade section).

| Check | Steps | Expected | Automated? |
|---|---|---|---|
| Play a minigame | Open the arcade on the Dashboard; play Snake/2048/Memory | Game is playable; score saved | `snake-game` |
| Personal best / rank | Play repeatedly | Personal best and weekly leaderboard update | — |
| Boost shop | Browse and purchase a boost with coins | Coins deducted; boost applied | — |
| Coin spend balance | Spend coins, then complete a task | Earn/spend ledger stays consistent | — |

---

## 10. Overdue Claiming & Teamwork

Route surface: `room_task#overdueClaims/filteredDaily`, teamwork completion fields.

| Check | Steps | Expected | Automated? |
|---|---|---|---|
| Claim overdue task | Open the overdue-claim dialog; claim an overdue task | Task assigned to claimer; completed fairly | `overdue-task-claiming` |
| Teamwork completion | Two members complete a task together | Team bonus applied; participants credited | `teamwork-rewards` |
| Combo with user | Trigger a combo that involves another member | `combo_with_user_id` recorded; bonus fair | `teamwork-rewards` |

---

## 11. Scheduling (Member Availability & Energy)

Route surface: `scheduling#*`. View: **Scheduling** in nav — five sections:
Availability, Energy Budget, Preview, Calendar, Suggestions.

> The unauthenticated `smart-schedule#*` routes were removed (CS-016). The UI
> consumes `scheduling#*` only.

| Check | Steps | Expected | Automated? |
|---|---|---|---|
| View availability | Open Scheduling → Availability section | Member cards with weekday grid render | `scheduling` |
| Edit own availability | Toggle weekdays on your card → Save | Availability persists after reload | `availability-settings-flow` |
| Energy budget | Adjust an energy value for a day → Save | Energy value persists after reload | `flex-window-scheduling` |
| Schedule calendar | Open the Schedule Calendar section | Week grid with member rows/capacity renders | `scheduling` |
| Preview & suggestions | Load Preview then Suggestions | Preview/suggestions render or show a correct empty state | `scheduling` |
| Owner edits member availability | Owner edits another member's availability | Allowed; a member editing someone else is denied | `scheduling` |

---

## 12. Multi-User on One Instance

| Check | Steps | Expected | Automated? |
|---|---|---|---|
| Concurrent completion | Two users complete different tasks at once | Independent scoring; no data corruption | `multi-user-interactions` |
| Shared house state | One user's completion is visible to others after reload | House/room/leaderboard state consistent across users | `multi-user-interactions` |
| Cross-house isolation | A user in house A cannot see/edit house B data | Access denied (403); no cross-house leak | PHPUnit auth/integration suites |
| Real-instance onboarding (beta+) | Onboard a second real user on the personal instance | They can join and participate without developer help | — |

---

## 13. Notifications

Route surface: background jobs (`DailyNotificationJob`, `StreakFreezeJob`,
`StreakRestockJob` — thin delegates over `StreakAtRiskService` /
`StreakFreezeService` / `StreakRestockService` since CS-PLAN-023) +
`ChoreStrikeNotifier`. Time-dependent flows are testable without waiting via
`occ chorestrike:trigger-jobs --as-of` (see `docs/engineering/testing.md` §
Time-Travel Testing).

| Check | Steps | Expected | Automated? |
|---|---|---|---|
| Daily reminder | Trigger/await the daily notification job | A localized task reminder is delivered via Nextcloud notifications | `time-travel-streak-notification` (at-risk reminder via `trigger-jobs --as-of`, CS-PLAN-023) |
| Streak freeze/restock | Consume then restock a streak freeze via the jobs | Freeze consumed on a miss; restocked per job schedule | `time-travel-streak-notification` (freeze/reset) + `TriggerJobsIntegrationTest` (restock covered by unit/integration) |
| Weekly goal | Reach the house weekly goal | Weekly-goal-reached notification fires once per week (de-duped) | — |

---

## 14. Mobile / Responsive

| Check | Steps | Expected | Automated? |
|---|---|---|---|
| Phone portrait | Open the app on a phone-width viewport | Layout adapts; navigation and content usable | — |
| Phone landscape | Rotate to landscape | Layout adjusts without overflow | — |
| Tablet | Open on a tablet-width viewport | Optimal, non-stretched layout | — |
| Touch interactions | Tap, scroll, drag-to-reorder, open modals | Touch targets work; no missed taps or broken gestures | — |

---

## 15. Performance & Stress Feel

| Check | Steps | Expected | Automated? |
|---|---|---|---|
| Many tasks | Seed/load a house with 100+ tasks | App stays responsive; lists render without long freezes | — |
| Many users | House with 10+ members | Member lists, leaderboard, scheduling render promptly | — |
| Long session | Use the app for ~1 hour | No obvious memory leak or degradation | — |
| API responsiveness | Watch network timings during normal use | API responses feel snappy (< ~500 ms typical) | — |

---

## 16. Upgrade / Migration Smoke (Personal Instance)

> **Back up the instance first.** Run on the personal Nextcloud instance, not
> Docker throwaway data (real upgrade path matters here). Required for **stable**.

| Check | Steps | Expected | Automated? |
|---|---|---|---|
| Upgrade install | Replace the app version on a previously-installed instance; run `occ upgrade` | App enables; migrations apply with no errors | — |
| Data preserved | Open existing floors/rooms/tasks/history after upgrade | All prior data intact and correctly typed | — |
| Schema fresh-install parity | (Separately) install fresh and compare schema | Fresh install and upgrade reach the same schema state | PHPUnit migration suite |
| Rollback | Disable, restore the previous version, re-enable | App returns to the prior working state | — |

---

## 17. Error Handling & Edge Cases

| Check | Steps | Expected | Automated? |
|---|---|---|---|
| Input validation | Submit empty names, invalid dates, very long strings | Friendly validation errors; no 500 / no stack trace | `validation` |
| Emoji / unicode | Use emoji and unicode in names | Saved and displayed correctly | — |
| Auth denial UX | Trigger a forbidden action as a non-owner | Clean 403; no internal details leaked | `error-handling`, PHPUnit |
| Network drop | Lose connectivity mid-save | Graceful error; no silent data loss | — |
| Session expiry | Let the session expire mid-task | Login redirect; no broken state | — |
| Concurrent edit | Two users edit the same resource simultaneously | No corruption; last-write or clear conflict handling | — |

---

## 18. Execution & Sign-off Record (template)

> **Release gate:** copy this block per release candidate, fill it in, and keep
> the result until the release is tagged. **A release tag requires a completed
> record.** Completed records are moved to
> [`../backlog/archive/`](../backlog/archive/) (resolution month) so this
> document stays bounded to the blank template.

```markdown
## Release record — <version> (<stage>)

- **Version / tag:** vX.Y.Z
- **Stage:** alpha | beta | stable
- **Date:** YYYY-MM-DD
- **Tester(s):**
- **Environments used:** Docker-empty / Docker-family / personal instance
- **Automated gate status:** green (link or note) | issues:

### Stage-protocol sections executed
- [ ] 3 First-run & house setup
- [ ] 4 Floors & rooms
- [ ] 5 Task lifecycle
- [ ] 6 Task calendar & daily views
- [ ] 7 Visibility & roles
- [ ] 8 Gamification
- [ ] 9 Minigames & boosts
- [ ] 10 Overdue claiming & teamwork
- [ ] 11 Scheduling
- [ ] 12 Multi-user on one instance
- [ ] 13 Notifications
- [ ] 14 Mobile / responsive
- [ ] 15 Performance & stress feel
- [ ] 16 Upgrade / migration smoke   (stable: required)
- [ ] 17 Error handling & edge cases

### Result
- **Outcome:** PASS | PASS-WITH-ACCEPTED-ISSUES | FAIL
- **Blocking issues found:** (link each to `../backlog/backlog.md`)
- **Accepted non-blocking issues:** (link each)
- **Decision:** promote | hold | defer
- **Sign-off:** <name / date>
```

---

## 19. Browser Compatibility (record per release)

| Browser | Version | Result | Notes |
|---|---|---|---|
| Chrome | latest | | |
| Firefox | latest | | |
| Safari | latest | | |
| Edge | latest | | |
