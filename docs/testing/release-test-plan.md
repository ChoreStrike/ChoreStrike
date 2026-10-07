# ChoreStrike — Manual Release Test Plan

> **Status:** Authoritative manual test plan and the **single source of truth**
> for pre-release manual validation. Supersedes the former `manual-test-checklist.md`,
> `alpha-test-guide.md`, `family-test-setup.md`, and `critical-tests.md` (archived
> 2026-07 under `archive/2026-07/testing-docs/`).
> **Scope:** manual, human-judgement testing plus the operator recovery evidence in §16. Items already covered reliably
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
- Rewards: `family-reward-api` (WP7 HTTP contract), `family-rewards` (WP8 UI flows)
- Overdue/teamwork: `overdue-task-claiming`, `teamwork-rewards`, `multi-user-interactions`
- Scheduling/calendar: `scheduling`, `availability-settings-flow`, `flex-window-scheduling`, `calendar`
- Dashboard/nav: `dashboard`, `dashboard-navigation`
- Minigames: `snake-game`
- Settings: `settings`
- Errors/validation: `error-handling`, `validation`
- Release regression suite (CS-PLAN-024, `release-*`): task lifecycle
  (`release-task-lifecycle`), visibility/roles (`release-visibility-roles`),
  task templates (`release-task-templates`), setup wizard
  (`release-setup-wizard`); the notifications row is covered by the WP5
  digest release spec (`quiet-digest`, CS-PLAN-070 WP5).

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

## 8. Gamification (WP3 minor-unit economy, WP4 rhythm recognition)

Route surface: `completion#show/undo/attribute`, `room_task#complete`, `game#state/medals/checkMedals`, `house#health`, `ranking#index/consent/withdraw`, `user#titles`.

> Currency is **Coins in exact minor units** (100 coin minor = 1 Coin) on an
> immutable ledger (WP3 / ADR-014). The legacy tier/combo/early multipliers,
> streak freezes, teamwork bonus and integer coin mints are retired — do not
> test for them. WP4: recognition is the single fixed 3/6/12 rhythm series
> (valid-history maximum, no pack selection) and the ranking is opt-in,
> consent-filtered ([ADR-015](../adr/ADR-015-ranking-consent-and-privacy.md)).

| Check | Steps | Expected | Automated? |
|---|---|---|---|
| Earn wallet value | Complete a task with a recorded split | Exact minor-unit deltas post per participant (`coinMinor = 10 × E × P` at the frozen rate); the cached wallet balance and contribution update; the caller's own totals ride the response | `task-completion`, PHPUnit `WalletServiceTest` |
| Exact minor-unit display | View wallet/fairness readouts | Minor units render with exact trimmed decimals; no Coin column or integer mint anywhere | `insights-flow`, Jest `minorUnits.spec.js` |
| Conservation under correction | Complete, attribute a different split, then undo | Every reversal keys the share rows' wallets (departed members included); the completion pays out exactly the frozen total across its lifetime | PHPUnit `CompletionCorrectionTest`, `HouseMemberRemovalIntegrationTest` |
| Rhythm streaks | Complete agreed planned days; undo one | Streaks re-derive from the valid planned-day history (pending days are neutral); a correction shrinks the record to the corrected valid-history maximum (C3g) | PHPUnit `RhythmProjectionTest`, `RhythmServiceTest` |
| Weekly goal | Complete due tasks across the week; undo one | `completed/(due∪completed)` percent and the achieved flag re-evaluate from the valid set (fixed 80% target — no config override) | PHPUnit `RhythmProjectionTest`, `WeeklyGoalServiceTest` |
| Medals | Trigger the live rhythm medal | `rhythm_3/6/12` and the weekly milestone award correctly via `awardMedalIfNew()`; retired activity/combo types stay gone | PHPUnit `MedalServiceTest` |
| Titles | Cross a rhythm rung | Title badge shows the server-derived current title (valid-history maximum signal); no selection surface exists | `title-selection` |
| Ranking opt-in | Open **Leaderboard** in nav with the flag off | Opt-in/closed state; no contribution ranking, no freeze indicators | `leaderboard` |
| Ranking consent lifecycle | Owner enables the flag; member joins, withdraws | Join starts NEXT Monday (pending, unranked); withdraw removes immediately; non-consenting members never appear | `ranking-consent`, PHPUnit `RankingConsentTest` |
| House health | Drive overall completion up/down | House health bar reflects aggregate activity | `dashboard` |

---

## 8a. Reward Catalog & Requests (WP7 backend + WP8 UI)

Route surface: `reward_catalog#*`, `reward_request#*`, `house#catalog`.
Views: **Rewards** in nav (only while both gates are open) and **My
Requests** in nav (persistent). Contract:
[rewards.md](../api/rewards.md) ([ADR-017](../adr/ADR-017-family-catalog-and-redemption.md)).

| Check | Steps | Expected | Automated? |
|---|---|---|---|
| Gate independence | Owner enables the household gate; a member toggles the personal catalog visibility | Browsing renders only while BOTH gates are open; either opt-out hides the entry and items but never My Requests or the owner Settings editor | `family-rewards` |
| Owner administration | Settings → Rewards: toggle the gate; add/edit/archive items with prices like `12` and `12,50` | Exact hundredths parsing (no float math); rejected: `1.234,56`, `12,5.5`, `0`, negatives, exponents; a 409 keeps the draft; members/children never see the editor | `family-rewards` |
| Child request flow | Child requests a reward; owner approves; owner confirms delivery | Request reserves first; approval spends nothing; delivery debits exactly once; the child cannot self-fulfill; totals/state reload correctly | `family-rewards` |
| Adult flow and correction | Adult requests (explicit self-approval), cancels one; owner attests a false fulfillment | Cancellation releases only the reservation; the correction refunds the ORIGINAL settlement entry exactly once and stays terminal | `family-rewards` |
| Funding hold | Create `B < R` (undo/attribution after spending) | New requests and deliveries blocked neutrally (no expiry, no pressure copy); approve/reject/cancel stay possible; coverage restoration refreshes the UI | `family-rewards` |
| Privacy and identity | Member opens a foreign request id; a second identity signs in on the same device | Generic unavailable state (no existence leak); the identity switch clears wishes, drafts, scopes and unresolved commands | `family-rewards` |
| Uncertain outcome | Lose a command response mid-click; use the banner retry | Same-key replay applies the effect exactly once; no duplicate submit while unresolved; reload never auto-submits | `family-rewards` |

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

### 12.1 Family Acceptance Evidence (C5j / WP9)

The canonical [family acceptance protocol](../concepts/ui-use-cases.md#family-acceptance-protocol)
defines this gate. The following is its execution record, not a competing
protocol and not evidence of participation. **Current status (2026-10-07):
coordination authorized, guardian consent and minimum adult/child voluntary
participation confirmed; execution not started. HUMAN-01 and HUMAN-02 remain open.**
Automation cannot replace them; authorization does not start the 14-day clock.

Start only after technical readiness and separately authorized coordination:
voluntary adult participation, guardian consent for the child and agreed
collection/retention limits. Present neutral scenarios, not click instructions;
record requested operating help as assistance, never unaided success. Use
synthetic cases for pause, catch-up, overload, agreed handover and reward
decisions. Do not provoke illness, missed chores, spending or notifications.
Withdrawal is permitted, with no activity target or minimum chore count.

Only anonymous adult/child role results and consent-confirmed status belong in
the public record. No names, ages, accounts, household details, medical reasons,
raw quotations, recordings or raw notes. Necessary sensitive material requires
separate authorization and belongs only in gitignored `/private/`, within the
agreed retention limits. Do not add telemetry or engagement/pressure scores.

**Start Checklist**

- [x] Coordination authorized and guardian consent confirmed (2026-10-07);
  record confirmation status only, not personal details or raw statements.
- [x] Minimum adult/child voluntary participation confirmed (2026-10-07).
- [ ] Before execution, explain that participants may decline any scenario or
  stop without a penalty; confirm that they understand the withdrawal option.
- [ ] Record the actual trial candidate/runtime and applicable technical evidence.
  CS-PLAN-082 supplies renewed runtime qualification, not proof of what is
  installed for this trial. Do not run fixture resets against a trial instance.
- [ ] Confirm the trial environment and data boundary. Use synthetic cases for
  comprehension; before real retained data, separately complete the operator's
  [baseline adoption](../engineering/deployment.md#first-retained-baseline).
  The disposable canonical test stack is not a retained family instance.
- [ ] Confirm the participants understand that only sanitized role-level results
  are retained in public Git history. This round collects no sensitive raw notes,
  screenshots, recordings or telemetry; any later private collection needs
  separately agreed scope and retention limits.

The operator presents the scenarios below without demonstrating controls or
giving navigation hints, records the actual outcome for each role, and supplies
only sanitized results. Check each participant independently; where several
participants share a role, retain separate anonymous outcomes within that role
without names, accounts or persistent participant identifiers. Requested help
is valid evidence, not a failure to hide.
After comprehension checks, record the actual everyday-use start date; do not
backdate it to the authorization date or prefill a successful end date.

**Session Record (Authorization Recorded; Execution Pending)**

| Field | Recorded evidence |
|---|---|
| Candidate | Not selected for the trial; record revision plus uncommitted runtime/fixture identity and actual image/runtime versions |
| Technical readiness | Historical WP9 gates and renewed [CS-PLAN-082 evidence](../plans/done/cs-plan-082-current-stable-test-baseline-and-data-preservation.md); applicability to the actual trial candidate pending |
| Authorization / guardian consent | Confirmed 2026-10-07; repository results must be anonymous |
| Voluntary participation | Adult / child minimum confirmed 2026-10-07; no operating success inferred |
| Roles | Adult / child, anonymous; no participant identifiers |
| Comprehension date | Not performed |
| Voluntary observation period | Not started; actual start/end dates and 14 observed calendar days |
| Final applicability | Not evaluated; identify any build changes and affected retests |

Use `unaided`, `uncertain`, `assisted`, `unable` or `not performed`, plus a short
sanitized issue reference; never infer success from absence of feedback.

| Neutral scenario prompt | Adult result | Child result |
|---|---|---|
| Find the next agreed chore and explain its planned date/window. | Not performed | Not performed |
| Explain confirmed/pending rhythm, the weekly milestone and a neutral free/paused day. | Not performed | Not performed |
| Disable reminders and optional areas; continue basic planning. | Not performed | Not performed |
| Use synthetic pause/catch-up and an owner-recorded agreed handover; explain overload without extra offers. | Not performed | Not performed |
| Explain effort-only Coins, fixed request terms, approval/delivery/cancellation and existing requests with browsing hidden. | Not performed | Not performed |

For each of the 14 days, record only the date, whether voluntary observation
was available (neutral/no-chore days are valid), the candidate identity if it
changed, and sanitized comprehension/pressure/organizing findings or `none
reported`. Do not publish a household activity diary or task counts. Conclude
with anonymous role-level feedback on organizing effort, perceived pressure,
optional-feature choices and comprehension; no numeric engagement target.

**Observation Record (Unstarted)**

`Pending` is not `none reported` and does not count as an observed day. Fill
actual dates and availability honestly, including gaps or withdrawal; elapsed
time alone does not establish 14 days of evidence. Reference the session
candidate unless it changes. Do not copy task histories into this table.

| Day | Actual date | Voluntary observation available | Candidate change | Sanitized findings |
|---|---|---|---|---|
| 1 | Pending | Pending | Pending | Pending |
| 2 | Pending | Pending | Pending | Pending |
| 3 | Pending | Pending | Pending | Pending |
| 4 | Pending | Pending | Pending | Pending |
| 5 | Pending | Pending | Pending | Pending |
| 6 | Pending | Pending | Pending | Pending |
| 7 | Pending | Pending | Pending | Pending |
| 8 | Pending | Pending | Pending | Pending |
| 9 | Pending | Pending | Pending | Pending |
| 10 | Pending | Pending | Pending | Pending |
| 11 | Pending | Pending | Pending | Pending |
| 12 | Pending | Pending | Pending | Pending |
| 13 | Pending | Pending | Pending | Pending |
| 14 | Pending | Pending | Pending | Pending |

**Final Evaluation (Pending)**

| Anonymous role | Organizing effort feedback | Perceived pressure feedback | Optional-feature choices / comprehension |
|---|---|---|---|
| Adult | Not collected | Not collected | Not collected |
| Child | Not collected | Not collected | Not collected |

Final candidate applicability, finding resolution/retests and HUMAN-01/HUMAN-02
verdicts remain pending. Do not turn missing feedback into a passing result.

Comprehension failures or pressure problems become plan-owned findings in
[CS-PLAN-080](../plans/active/cs-plan-080-wp9-cross-db-and-family-evidence.md).
Resolve their causes and repeat affected checks; behavior changes require
reassessing observation representativeness and renewing affected technical
gates. Missing testers/consent, incomplete duration or unresolved findings keep
WP9/G3/CONCEPT-001 open. This template does not authorize baseline adoption.

### 12.2 Automated Family Evidence Boundaries

The following required browser scenarios supplement, never replace, section
12.1. They run in the reset-owning `chromium-serial-db` pool through
`npm run test:e2e:gate`; their identities are pinned in the discovery inventory.

| Contract | Required automated evidence | Boundary |
|---|---|---|
| Neutral empty day | `calm-core-ui.spec.ts`: `a free day stays neutral without catch-up, extra offers or invented success` | An unassigned-today member sees the empty state, no invented completion or extra work; not a human pressure evaluation. |
| Fixed versus relative recurrence | `family-rhythm.spec.ts`: `fixed and relative schedules explain and preserve their different next dates after late work` | Real form explanations plus late weekly completions: the fixed weekday stays anchored, the relative successor follows the work day. |
| Shared device | `workflow-state-reconciliation.spec.ts`: `same-device logout and child login discard the prior wallet, undo queue and optional preferences` | Fresh owned Nextcloud sessions in one browser context; a live undo entry precedes logout. This proves full login navigation, not arbitrary in-flight identity switching inside a surviving document. |
| Free/paused task reminders | `quiet-digest.spec.ts`: `opted-in free and paused days suppress task reminders while due work still delivers` | Otherwise planned work on an explicitly unavailable weekday and a recognized personal pause produce no task bell; an eligible member receives one in the same real job pass. Organizational digests are disabled for this task-only proof. |
| Keyboard core | `family-core-ux.spec.ts`: `child: My Day hides manage controls and executes the checklist read-only (D4)` | Actual Tab traversal, Enter expansion/completion and Space checkbox execution, followed by the real completion receipt and undo confirmation; no programmatic focus shortcut. |
| Reduced motion | `calm-core-ui.spec.ts`: `reduced motion overrides an explicit animation opt-in on a real completion` | Personal opt-in remains enabled. Canvas insertion observation verifies suppression, then the same completion after undo and a live OS-preference change supplies the positive animation control. |

For WP9 acceptance, run the entire three-pool gate twice consecutively on the
same runtime/build/fixture/discovery candidate. Focused spec passes diagnose
repairs but cannot replace that pair. Keep raw reports/traces under ignored
`build/e2e-gate/`; publish only sanitized run identities, counts and outcomes.

---

## 13. Notifications

Route surface: the five-minute digest pass (`DailyNotificationJob` →
`DigestService`, CS-PLAN-070 WP5 / ADR-016) + `ChoreStrikeNotifier`.
Time-dependent flows are testable without waiting via
`occ chorestrike:trigger-jobs daily-notification --as-of="YYYY-MM-DD HH:MM"`
(see `docs/engineering/testing.md` § Time-Travel Testing).

| Check | Steps | Expected | Automated? |
|---|---|---|---|
| Silent by default | Load Settings as any member; trigger the digest pass | The notifications section renders self-only; nothing is dispatched while the digest is off | `quiet-digest` + `PreferenceServiceTest` |
| Daily summary | Opt in (Settings or `PUT /preferences/me`), create due work, trigger the pass at the slot time | Exactly ONE combined `digest` bell; a replay delivers nothing further (at-most-once) | `quiet-digest`, `TriggerJobsIntegrationTest`, `DigestServiceTest` |
| Quiet hours | Set a quiet range covering the dispatch instant, trigger the pass | No bell during quiet hours; a missed window expires without a late burst | `quiet-digest`, `DigestDeliveryTest` |
| Schedule correctness | Set digest times around DST gap/fold (Europe/Berlin) | Gap → first valid later instant; fold → first occurrence | `DigestScheduleServiceTest`, `TimezoneDayBucketingTest` |
| Opt-out/departure | Toggle off or remove the member before the claim | The slot is skipped/consumed; no bell after opt-out | `DigestDeliveryTest`, `HouseMemberRemovalIntegrationTest` |

The retired per-event rows (daily task reminder, streak freeze/restock,
weekly-goal bell) were removed with the WP5 cutover — pressure
notifications no longer exist (ADR-016).

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

## 16. Upgrade & Recovery

### Personal-Instance Upgrade Smoke

> **Explicit target/access authorization and a consistent backup are required.**
> Personal-instance validation is RELEASE-002 work, not authorized by a synthetic
> test. Required for stable; use the actually adopted retained baseline, not an
> earlier disposable installation. Adoption procedure:
> [deployment.md](../engineering/deployment.md#first-retained-baseline).

| Check | Steps | Expected | Automated? |
|---|---|---|---|
| Upgrade install | Replace the app version on a previously-installed instance; run `occ upgrade` | App enables; migrations apply with no errors | — |
| Data preserved | Open existing floors/rooms/tasks/history after upgrade | All prior data intact and correctly typed | — |
| Schema fresh-install parity | (Separately) install fresh and compare schema | Fresh install and upgrade reach the same schema state | PHPUnit migration suite |
| Rollback | Under operator-approved quiescence restore matched code/config/whole DB/applicable data; compare app and unrelated state | Prior recovery point restored within the approved window; other-app rollback acknowledged; code downgrade alone is not recovery | — |

### Synthetic Whole-Instance Recovery (H-T10)

Use an explicitly owned disposable environment, synthetic accounts/data only,
and no overlapping integration/browser/background mutation jobs. Follow the
[whole-instance procedure](../engineering/deployment.md#rollback), with isolated
target volumes/network and no external delivery. Raw dumps, config and manifests
stay gitignored/private; public evidence contains sanitized commands, target
identity, assertions and actual outcomes only.

Required record:

- Candidate Git/build identity, actual app/NC/PHP/provider versions and ordered migration-file hash inventory (private raw manifest; sanitized summary).
- Owned source/restore target identities, isolated volume scope, writer quiescence and consistent recovery point; included code/config/DB/applicable data.
- Non-empty synthetic structure/definitions/room-task assignments/checklists; recurrence/occurrences/agreements/pauses; completion history/shares/corrections/journal; membership/preferences and wallet/ledger/reservation/reward facts, with IDs and relations compared before/after.
- Synthetic unrelated Nextcloud/core sentinel state and a data-file sentinel; assert their restoration as well, not app tables only.
- Deliberate post-backup changes proven different, followed by exact recovered snapshot equality and representative authoritative reads; app enablement/migration inventory unchanged.
- Actual elapsed recovery time, command exit statuses, result and cleanup limited to owned resources. A passing rehearsal proves neither upgrade compatibility nor real-server validation/adoption.

### H-T10 Record: `cs081-recovery-e6d385fad062` (2026-10-04)

> **Runtime note:** the H-T10 records below predate the 2026-10-06 CS-PLAN-082
> canonical promotion; their NC31 / PHP 8.2 / MariaDB 10.6 runtime rows are the
> retired canonical image, kept verbatim as dated evidence.

**Result: PASS**, including teardown. Synthetic instance only; this is not a
release sign-off, real-server validation, upgrade test or retained-baseline adoption.

| Evidence | Actual result |
|---|---|
| Candidate | `master` at `56697d273b0324d2b7d1a172d57569129438fc42` plus the bounded H6 working-tree fix/fixture; captured runtime/build archive SHA-256 `4bb54ad42098d9677b06907a04633ae7102d5fcbfbecf808ab171c130d7f5913` |
| Runtime | Installed ChoreStrike `0.1.1`; Nextcloud `31.0.14`; PHP `8.2.34`; MariaDB `10.6.25` (test image `795f1662233b5012f584f30aae5eedbeaabf0b394598e74afc8570fd113f0067`) |
| Migration identity | All 26 ordered applied identifiers match migration-file identifiers; matching migration/build file hashes retained privately; no new migration or schema edit |
| Ownership/isolation | Unique `cs081-recovery-e6d385fad062-{db,source,restored}` containers and internal-only network; no published ports, cron/web writers, external deliveries or integration/browser jobs; no canonical-stack/other-container mutation |
| Capture | Runtime whitelist includes built JS/CSS and PHP dependencies; full Nextcloud code/config/applicable data captured under maintenance mode, with no active writer; complete consistent DB dump, never app tables only |
| Non-empty retained facts | Structure/task/assignment/checklist rows, recurrence/occurrences/members, amendment and pause, real completion plus corrected two-person allocation, room journal, member preferences, command receipts, wallet ledger and funded reward reservation/event history |
| Deliberate divergence | House/wallet rows, unrelated core preference, system config and data sentinel changed after capture; an extra code sentinel added. DB digest changed; attempting verification in the divergent state is rejected (expected exit 255) |
| Restore assertions | Whole-DB deterministic row dump SHA-256 equals before; every captured regular-file SHA-256 validates before bootstrap; all 30 ChoreStrike table count/hash snapshots equal; extra post-backup code sentinel absent |
| Authoritative reads | Corrected two-person completion and reward request/event history readable; unrelated Nextcloud preference and config/data sentinels restored; `occ status` installed, maintenance on, no DB upgrade needed before reopening |
| Failure-path checks | Disabled ownership flag rejects seeding (exit 255); upstream manifest-pipeline failure rejects capture (exit 1, `pipefail`); cleanup self-test continues after the first injected removal failure and retains its error |
| Timing | Recovery/divergence/verification interval 29.50 seconds; full preparation/capture/rehearsal 70.62 seconds (teardown follows); no production RTO promise |
| Cleanup | All three owned containers and their anonymous volumes plus the owned network removed successfully; zero cleanup failures; PASS evidence finalized only after cleanup |

Sanitized command sequence (the runner supplies unique owned names and test-only
credentials; its private operation log records redacted identities/exit codes):

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/run-recovery-rehearsal.ps1 -SelfTest
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/run-recovery-rehearsal.ps1
```

The runner uses `docker create/start/exec` against the existing canonical **image**,
not its DB or mounted source. It installs a separate synthetic Nextcloud, seeds
through `tests/recovery/fixture.php`, executes `occ maintenance:mode --on`, hashes
files with `bash -o pipefail -ec`, captures `tar` + `mysqldump --single-transaction`,
proves divergence, stops the source, replaces the **owned** DB, and extracts the
matched archive into a new target. It does not run the canonical seed entrypoint
after restore. PHP/service reads verify retained facts before maintenance is
turned off. Windows archive capture and container-local evidence avoid Docker
bind-directory enumeration failures seen during harness development.

Earlier harness-development attempts failed visibly (archive/CLI fixture/metadata
errors); they are not passing recovery evidence. The final guarded runner passes
the negative controls as well as the positive restore. OCC's optional-PCNTL
interrupt advisory is retained in the private log; JSON/version records are
parsed separately, not hidden or presented as an app defect. Raw instance
code/config/data, DB dump, manifests and diagnostics remain gitignored/private.

### H-T10 Reverification: `cs081-recovery-592eb59f90a0` (2026-10-04)

**Result: PASS**, after the production build completed and the final integration
process/helpers exited, before the exclusive full browser gate started.
Candidate `master` at `46d37a29ce705fd187ceb43aacaa2d694dfff512` plus the
reviewed H6 working tree; runtime whitelist archive SHA-256
`d4f4436a916aba940c10f6a77e66846d0027061432c1788932d1cf085e5935c3`.
Runtime remains ChoreStrike `0.1.1`, Nextcloud `31.0.14`, PHP `8.2.34`, MariaDB
`10.6.25`; the same test image and all 26 migration identities match.

Both runner self-tests pass (authenticated TCP retry/exhaustion and independent
cleanup failure propagation). The actual isolated rehearsal repeats all 30
app-table snapshots, corrected history/reservation/core/config/file sentinel
reads, whole-DB row equality and every captured file hash. Deliberate divergence
and the ownership/manifest negative controls are rejected as intended. Recovery
and verification take 28.48 seconds; total preparation/capture/rehearsal 74.34
seconds, followed by successful removal of all three owned containers/anonymous
volumes and the owned internal network (zero cleanup failures). Raw evidence
remains private. No production restore, upgrade proof, WP9 portability evidence,
external publication or retained-baseline adoption is implied.

### H-T10 Reverification: `cs081-recovery-673681ab35b7` (2026-10-04)

**Result: PASS**, run by the interim Standard B review after its authorized
log-sanitization repair: the runner's private `commands.log` now also redacts
the synthetic root password embedded in dump/restore shell strings
(`Get-SanitizedArguments`, covered by a dedicated self-test; the executed
rehearsal log contains zero unredacted occurrences). Candidate `master` at
`172129ca49b7c7435e0567b838a70a0977e44e78` plus the reviewed H6 working tree;
runtime whitelist archive SHA-256
`0d79f16a87781a801ffc49d4cbc0fc8c593660ef4c0fd38e9d8f71d677a3324a`; runner
content SHA-256
`770093eebc9304e2377f523c78a586075822fb2489c17c7ab7c397d13933af1f`.
Runtime remains ChoreStrike `0.1.1`, Nextcloud `31.0.14`, PHP `8.2.34`, MariaDB
`10.6.25`; same test image, all 26 migration identities match. All three
self-tests, the 30 app-table snapshots, corrected history/reservation and
core/config/file sentinel reads, whole-DB row equality, captured-file hash
equality, deliberate divergence and the ownership/manifest negative controls
pass; recovery 28.12 seconds, total 69.98 seconds, complete owned teardown with
zero cleanup failures. Same synthetic-only scope: no production restore,
upgrade proof, WP9 portability evidence, publication or adoption implied.

---

### H-T10 Final-Candidate Reverification: `cs081-recovery-2f8aff4b3ee8` (2026-10-05)

**Result: PASS**, after the final Full review's bounded reset-identity repair
and a fresh production build. Candidate `master` at
`2d0313f08c7be6dbc5a54cccfd620fa1a22f2ca5` plus the authorized final repair tree;
runtime whitelist archive SHA-256
`4b1576b5cd0ef6d9b15a93c0abe5c75ebdc1e78d93e24a9a3312995e993d7624`.
The unchanged runner SHA-256 is
`770093eebc9304e2377f523c78a586075822fb2489c17c7ab7c397d13933af1f`.
Runtime is ChoreStrike `0.1.1`, Nextcloud `31.0.14`, PHP `8.2.34`, MariaDB
`10.6.25`; image SHA-256
`795f1662233b5012f584f30aae5eedbeaabf0b394598e74afc8570fd113f0067`,
26 applied migration identities. All three runner self-tests, 30 app snapshots,
corrected history/reservation and unrelated core/config/file sentinels, whole-DB
row equality, captured-file hash equality, deliberate divergence and negative
controls pass. Recovery 32.76 seconds, total preparation/capture/rehearsal 97.58
seconds; complete removal of owned containers/volumes/network, zero cleanup
failures. Integration and browser jobs did not overlap this isolated rehearsal.
Earlier named records remain historical evidence, not substitutes for changed
runtime bytes. No production restore, schema upgrade, WP9 provider matrix,
human-use evidence, publication or retained-baseline adoption is implied.

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
