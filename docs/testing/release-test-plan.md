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
| 9. Minigames (play-only) | smoke | full | full |
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
docker compose -f docker/compose.test.yml down -v
$env:TEST_DB_PROFILE='family'; docker compose -f docker/compose.test.yml up --build
# or, against a running container:
docker exec chorestrike-test php occ chorestrike:test-data:seed --profile=family
```

---

## 2. Automated Coverage Pointers

Before manual testing, confirm the automated gate is green (it is the first line
of defence — see [`../engineering/testing.md`](../engineering/testing.md) for the
full inventory and how to run it). Representative automated coverage (Playwright
E2E spec basenames under `tests/e2e/`, plus PHPUnit suites):

- Core CRUD / scratch flow: `gui-scratch-flow`, `minimal-smoke-tests`, `fast-core-tests`, `core-functionality`, `3-floor-house`
- Floors/rooms: `room-management`, `room-detail`
- Tasks: `task-manager`, `task-completion`, `task-completion-full-flow`, `task-checklist-and-features`, `today-view`, `insights-flow` (history correction, WP3 §3.6)
- Visibility: `private-tasks`, `admin-only-tasks`
- Gamification: `leaderboard`, `title-selection`, `teamwork-rewards`
- Overdue/teamwork: `overdue-task-claiming`, `teamwork-rewards`, `multi-user-interactions`
- Scheduling/calendar: `scheduling`, `availability-settings-flow`, `flex-window-scheduling`, `calendar`
- Dashboard/nav: `dashboard`, `dashboard-navigation`
- Minigames: `snake-game`
- Settings: `settings`
- Errors/validation: `error-handling`, `validation`
- Release regression suite (CS-PLAN-024, `release-*`): task lifecycle
  (`release-task-lifecycle`), visibility/roles (`release-visibility-roles`),
  task templates (`release-task-templates`), setup wizard
  (`release-setup-wizard`), notifications (`release-notifications`).

> **Drift rule:** if an E2E spec comes to cover a manual item reliably, mark the
> manual row **Automated** (with the spec pointer) instead of removing the row —
> the row still documents the expected behaviour for exploratory spot-checks.

### 2.1 Tagging convention (the `Automated?` column)

Every row carries exactly one tag in the `Automated?` column (CS-PLAN-024
audit). The tag is the executable form of the drift rule above:

| Tag | Meaning |
|-----|---------|
| **Automated** `spec-basename` | A Playwright spec under `tests/e2e/` reliably covers the row. The manual tester may spot-check but is not required to re-execute it. |
| **partial** `spec-basename` *(gap note)* | A spec covers part of the row (e.g. the happy path but not the denial branch). The gap note records what is still manual. |
| **PHPUnit** `TestClass::method` | Covered by a backend unit/integration test (no UI), so the manual tester validates only the UX surface. |
| **—** | Genuinely manual (exploratory / mobile / social / perf / upgrade-smoke). No automation is planned. |

A row is never *removed* when it becomes automated — it is re-tagged so the
expected behaviour stays documented for exploratory spot-checks.

---

## 3. First-Run & House Setup

Route surface: `page#index`, `house#get/update/stats/health/reset`, `setup#catalog/apply`.

| Check | Steps | Expected | Automated? |
|---|---|---|---|
| App install & enable | Enable `chorestrike` via `occ` / app list | App appears in launcher, main page loads, no errors in `nextcloud.log` | — |
| First-run house bootstrap | Open the app for the first time as a new user | A house is created automatically, the Dashboard renders, and the setup wizard opens (house has no floors) | `gui-scratch-flow` |
| Setup wizard — quick start | Pick home type, floors, basement, rooms/floor, Putzteufel/Putzmuffel; advance | A generated, editable room list (name, room type, person) appears | `release-setup-wizard` (gate only), `SetupWizard` Jest spec |
| Setup wizard — apply | Apply the wizard | Floors, rooms (with room type), tasks from the leveled catalog, and per-room assignments are created in one transaction | `HouseSetupIntegrationTest`, `release-setup-wizard` (gate only) |
| Setup wizard — skip | Click „Leer starten" | Wizard closes, empty house remains | `SetupWizard` Jest spec |
| Wizard first-run gate | Open a house that already has floors | The wizard does not auto-open and `setup/apply` returns 409 | `release-setup-wizard` |
| House name update | Edit the house name in Settings | Name persists after reload | — |
| House health | Complete/skip a few tasks, reopen Dashboard | House health bar reflects recent activity | `dashboard` |
| House reset (owner only) | Trigger house reset as owner, then as a non-owner | Owner succeeds; non-owner is denied (403) | partial `settings` (happy-path UI only; 403 branch still manual) |

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
| Complete task | Mark a room-task complete with the actual-work split | Work facts freeze (occurrence, work date/zone, total energy, rate); exact minor-unit wallet deltas post to the immutable ledger; the room journal records the requested effect (WP3 / ADR-014) | `task-completion`, `task-completion-full-flow`, `today-view` |
| Undo completion | Use the snackbar undo action after completion | The targeted completion undo reverses the latest selected allocation exactly once: occurrence release, exact negative wallet deltas, room replay (the correction time never becomes `last_cleaned`), recognition recompute; idempotent, a second completion keeps the first entry's undo (UX-015 queue) | `release-task-lifecycle`, `task-completion`, `task-completion-full-flow`, `today-view` |
| Correct the split (attribution) | Open a live completion from the Insights history, adjust the recorded participants | The reused participation form pre-fills the recorded split; the receipt-protected PUT re-accounts with exact wallet-union deltas (departed participants' retained wallets included) and the frozen work facts stay byte-identical (WP3 §3.4/§3.6) | `insights-flow` (owner attribution), `CompletionCorrectionTest`, `HouseMemberRemovalIntegrationTest` |
| Replay a command | Retry the same completion/undo/attribution with the same command UUID | The stored outcome replays with zero repeated effects; a changed payload under the same UUID conflicts (CR-SVC-64) | PHPUnit `CommandServiceTest`, `CompletionConcurrencyTest`, `RoomReplayConcurrencyTest` |
| Reschedule task | Change the due date | Due date updates | `release-task-lifecycle` |
| Recurrence | Complete a recurring (daily/weekly/monthly) task | A new instance is generated per frequency | `family-rhythm` (occurrence receipt/replay/409 contract, CS-PLAN-072 WP1 / ADR-013); partial `today-view` (daily recurrence: completion shifts the next due out of today; weekly/monthly still manual) |
| Assignees | Assign / unassign members; multi-assign | Assignee set persists; multi-assign works | `task-manager` |
| Reactivate on undo | Undo a completion that deactivated a one-time room-task | The undo aggregate restores the recorded `isActive` before-state (no separate reactivate step; `release-task-lifecycle` posts the once-restoration via the undo response) | `release-task-lifecycle`, `task-completion-full-flow` |
| Checklist items | Add / toggle / reorder / delete checklist items on a task | Progress tracked; room-task checklist progress updates | `task-checklist-and-features` |
| Task templates (per-room picker) | Open a room's add-task flow, pick a template (filtered by room type + house-wide), adjust overrides, add | A room task is created from the template; custom-task path still works | `release-task-templates`, `TaskTemplatePicker` Jest spec |

---

## 6. Task Calendar & Daily Views

Route surface: `/calendar` view, `room_task#range/dueToday/dueWithinDays`.

| Check | Steps | Expected | Automated? |
|---|---|---|---|
| Calendar renders | Open **Calendar** in nav | Week/rolling-30-day grid renders with member/task rows | `calendar` |
| Week navigation | Move to next/previous week | Range label and grid update | `calendar` |
| Recurring occurrences | View a recurring task across weeks | Future occurrences projected server-side from the durable timeline (CS-PLAN-072 WP1 / ADR-013): fixed cadence enumerates calendar dates, interval rules own one pending anchored on the real completion work date | `calendar` |
| Daily list | Open the daily task list | Today's due tasks shown per member | `today-view` |

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
| Change role | Owner changes a member's role (`owner`/`member`/`child`) | Role change persists and affects visibility | `release-visibility-roles` |
| Admin-only config (global) | Read the reward configuration as a member; edit it as a Nextcloud server admin vs. a non-admin | Any member can read; the server admin can edit; a non-admin is denied (403) | `release-reward-config` |

---

## 8. Gamification (WP3 minor-unit economy)

Route surface: `completion#show/undo/attribute`, `room_task#complete`, `game#state/medals/checkMedals`, `house#health`, `user#titles/selectTitle`.

> Currency is **Coins in exact minor units** (100 coin minor = 1 Coin) on an
> immutable ledger (WP3 / ADR-014). The legacy tier/combo/early multipliers,
> streak freezes, teamwork bonus and integer coin mints are retired — do not
> test for them.

| Check | Steps | Expected | Automated? |
|---|---|---|---|
| Earn wallet value | Complete a task with a recorded split | Exact minor-unit deltas post per participant (`coinMinor = 10 × E × P` at the frozen rate); the cached wallet balance and contribution update; the caller's own totals ride the response | `task-completion`, PHPUnit `WalletServiceTest` |
| Exact minor-unit display | View wallet/fairness/leaderboard readouts | Minor units render with exact trimmed decimals; no Coin column or integer mint anywhere | `leaderboard`, `insights-flow`, Jest `minorUnits.spec.js` |
| Conservation under correction | Complete, attribute a different split, then undo | Every reversal keys the share rows' wallets (departed members included); the completion pays out exactly the frozen total across its lifetime | PHPUnit `CompletionCorrectionTest`, `HouseMemberRemovalIntegrationTest` |
| Rhythm streaks | Complete agreed planned days; undo one | Streaks re-derive from the valid planned-day history (pending days are neutral); a correction shrinks the record to the corrected valid-history maximum (C3g) | PHPUnit `RhythmProjectionTest`, `RhythmServiceTest` |
| Weekly goal | Complete due tasks across the week; undo one | `completed/(due∪completed)` percent and the achieved flag re-evaluate from the valid set (80% target) | PHPUnit `RhythmProjectionTest`, `WeeklyGoalServiceTest` |
| Medals | Trigger the live rhythm medal | `streak_keeper` (and further wallet-qualified rhythm awards) award correctly via `awardMedalIfNew()` | PHPUnit `MedalServiceTest` |
| Titles | Unlock and select a title | Title badge updates (`contributionMinor` / `longestStreak` signals); selection persists | `title-selection` |
| Leaderboard | Open **Leaderboard** in nav | Members ranked by contribution minor; streak view available | `leaderboard` |
| House health | Drive overall completion up/down | House health bar reflects aggregate activity | `dashboard` |

---

## 9. Minigames (play-only)

Route surface: `minigame#*`. Components: Snake, 2048, Memory (arcade section).

| Check | Steps | Expected | Automated? |
|---|---|---|---|
| Play a minigame | Open the arcade on the Dashboard; play Snake/2048/Memory | Game is playable; score saved | `snake-game` |
| Personal best / rank | Play repeatedly | Personal best and weekly leaderboard update | — |

---

## 10. Overdue Claiming & Shared Work

Route surface: `room_task#overdueClaims/filteredDaily`, the completion work-split
fields (`participants`/`allocationReviewed`).

| Check | Steps | Expected | Automated? |
|---|---|---|---|
| Claim overdue task | Open the overdue-claim dialog; claim an overdue task | Task assigned to claimer; completed fairly | `overdue-task-claiming` |
| Shared completion | Two members record the actual work split on one completion | Both wallets receive their exact minor-unit shares; the allocation revision is auditable (WP3 — no team bonus dimension) | `task-completion-full-flow`, `today-view`, `insights-flow` |
| Split correction | A completer/owner corrects the recorded split afterwards | Exact union deltas re-account every named wallet; frozen work facts unchanged | `insights-flow`, PHPUnit `CompletionCorrectionTest` |

---

## 11. Scheduling (Member Availability & Energy)

Route surface: `scheduling#*`. View: **Scheduling** in nav — role-dependent
sections: My Availability + Energy Budget, Preview, Calendar, Suggestions for
everyone; the owner-only Member Availability grid renders for owners only
(CS-032).

> The unauthenticated `smart-schedule#*` routes were removed (CS-016). The UI
> consumes `scheduling#*` only.

| Check | Steps | Expected | Automated? |
|---|---|---|---|
| My Availability (any role) | Open Scheduling → My Availability → toggle a weekday → Save → reload | Section with 7 toggles renders for non-owners too; value persists | `self-availability-member` |
| View availability (owner) | Open Scheduling → Member Availability section | Owner sees member cards with weekday grid; non-owners see no grid and no "No Members Found" state | `scheduling`, `self-availability-member` |
| Edit own availability | Toggle weekdays on your card → Save | Availability persists after reload | `availability-settings-flow` |
| Energy budget | Adjust an energy value for a day → Save | Energy value persists after reload | `flex-window-scheduling` |
| Schedule calendar | Open the Schedule Calendar section | Week grid with member rows/capacity renders | `scheduling` |
| Preview & suggestions | Load Preview then Suggestions | Preview/suggestions render or show a correct empty state | `scheduling` |
| Owner edits member availability | Owner edits another member's availability | Allowed; a member editing someone else is denied | `scheduling` |
| Planner exact shares (WP2) | Owner previews a plan with a joint proposal | Change rows show the exact whole-percent share labels (e.g. 34/33/33) | `scheduling-apply-flow`, SchedulePlanner spec |
| Planner consent attestation (WP2) | Owner previews → Apply stays disabled until the consent checkbox is checked → apply | Apply payload carries `consent: true`; a fresh preview requires a fresh attestation; the attestation and draft survive a 409 retry | `scheduling-apply-flow`, scheduling store spec |
| Neutral/overload clarification (WP2) | A member is paused (API) or a day is over budget | Capacity grid shows `Paused`/`{planned}/{budget} — over capacity`; paused rows are never silently dropped | `family-rhythm` (WP2 describe), SchedulePlanner spec |
| Local-midnight staleness (WP2) | Preview before local midnight, apply after | Clean 409, zero writes; malformed input stays 400 | PHPUnit `SchedulePlanAggregateTest` (injected clock) |
| Agreement commands (WP2, API) | Owner amends/catches up/restarts an occurrence via `/occurrences/{key}/*` | Receipt identity (same UUID + changed input conflicts); stale token/version → 409 zero writes; handover persists the neutral release; catch-up keeps date+zone | PHPUnit `OccurrenceTimelineTest`, `RoleGuardApiTest`, `family-rhythm` (WP2 describe) |
| Pause lifecycle (WP2, API) | Personal request (child pending / adult acknowledged), owner house pause, approve/reject, shorten acknowledged, owner illness recognition | Only recognized pauses have effects; overlapping pauses keep union provenance; shortening a pending request conflicts; member/child denials are 403; foreign illness subject is 404 | PHPUnit `PauseServiceTest`, `PauseAgreementTest`, `PauseAgreementConcurrencyTest`, `RoleGuardApiTest` |
| Shortening precedence (WP2 fix round) | Acknowledged multi-day pause, shortened to today (full persisted revision history) | Superseded future dates actually resume duty; today/past stay recognized in the saved zone; pending/rejected child shortenings change nothing; job replay is inert | PHPUnit `PauseProjectionServiceTest`, `PauseAgreementTest` |
| Recognition eligibility (WP2 fix round) | Ordinary acknowledgment whose range reaches over an expired miss; request before expiry approved after | The expired miss is NOT neutralized (decision time = approval, not request); the explicit retrospective-illness marker neutralizes it without fabricating a completion | PHPUnit `PauseProjectionServiceTest`, `PauseAgreementTest` |
| Joint suspension & resumption (WP2 fix round) | One obligated partner of a 50/50 arrangement pauses | The whole occurrence suspends (retained snapshot, zero live capacity for both); an explicit feasible 100% agreement with consent before the deadline resumes it; over-budget/after-deadline/no-consent proposals write nothing | PHPUnit `PauseProjectionServiceTest`, `PauseAgreementTest` |
| Concrete-arrangement validation (WP2 fix round) | Handover A→B, then date-only amend / catch-up without participants | Capacity/consent follow B (the actual rows), never the released revision default A: A's pause does not block, B's pause does | PHPUnit `AgreementServiceTest`, `OccurrenceTimelineTest` |
| Pause-list privacy (WP2 fix round) | `GET /pauses` as owner / subject / unrelated adult / unrelated child | Personal reason + illness marker are self/owner-only in every state; unrelated viewers receive the neutral whitelist facts only; no surrogate row id on the wire | PHPUnit `PauseServiceTest`, `RoleGuardApiTest` (real wire) |
| Virtual/persisted parity (WP2 fix round) | Stopped cron: read the agreed key before materialization, then run the job pass | The virtual twin derives suspension from the saved revision identically to the materialized row (same key/state/provenance/appointment); the GET writes nothing | PHPUnit `PauseProjectionServiceTest`, `OccurrenceTimelineTest` |

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
| Weekly goal | Reach the house weekly goal | Weekly-goal-reached notification fires once per week (de-duped) | `release-notifications` |

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
- [ ] 9 Minigames (play-only)
- [ ] 10 Overdue claiming & shared work
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
