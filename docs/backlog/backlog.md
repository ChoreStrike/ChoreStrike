# Backlog — Open Items

> **Single source of truth for everything not yet done.** Bugs, features,
> tech-debt, and review follow-ups are consolidated here (the old `bugs.md` /
> `features.md` / `phase-9-*` / `phase-8-*` files are dissolved).
> **Done work does not live here** — completed items move to
> [`archive/YYYY-MM.md`](archive/) at completion. See [`README.md`](README.md)
> for the workflow and [`docs/standards/ai/rules/project-rules.md` §4](../standards/ai/rules/project-rules.md)
> for the rules.
>
> Priority: **P0** critical · **P1** high · **P2** should-have · **P3** nice-to-have · **P4** future.

---

## Bugs

### [P3] BUG-SEC-001 — Dedicated security reporting address + GPG key
**Status**: Backlog · **Effort**: S · **Source**: CS-001 security review

`SECURITY.md` points reporters to the Codeberg user profile (no private advisory
feature). A dedicated `security@` address + published GPG key would make private,
encrypted reporting frictionless.

- [ ] Dedicated security contact published in `SECURITY.md` + Codeberg profile.
- [ ] GPG public key published; fingerprint referenced in `SECURITY.md`.
- [ ] `SECURITY.md` "maintainer note" removed once the channel exists.

---

### [P3] BUG-USER-001 — `GET /api/v1/users/{id}/stats` has no frontend consumer (dead surface)
**Status**: Backlog · **Effort**: S · **Source**: CS-007 review (2026-07-23)

`user#stats` (`UserController::stats()`) is registered + functional (the
`getTeamSparks()`→`getCoins()` bug was fixed in CS-007) but no Pinia store calls
it; stats are sourced from `/game/state` + `/house/stats`. The separate
`UserStatsController` (8 public methods) is **entirely unrouted** = dead code.

- [ ] Decide: wire a frontend consumer (profile/detail view) **or** remove the
      dead route + method + the unrouted `UserStatsController`.

---

### [P3] BUG-TEMPLATE-001 — `TemplateService::bulkImport` silently swallows exceptions
**Status**: Open · **Effort**: XS · **Source**: CS-022 review (2026-07-30)

`TemplateService::bulkImport()` (`lib/Service/TemplateService.php`) catches
`\Exception` with the comment "Log error and continue" but never logs — violates
project-rules §3 ("Never swallow exceptions silently"). Pre-existing, not
introduced by CS-022.

- [ ] Add an `AppLogger` warning/error for each skipped template ID in the catch block.

---

### [P3] BUG-ROOM-001 — Re-evaluate the `RoomController::index` POST→GET rewrite workaround
**Status**: Deferred (low) · **Effort**: S · **Source**: CS-020 (deferred AC)

The body-sniffing POST→GET rewrite workaround was left as harmless dead code
after the Docker env got correct `mod_rewrite` routing (`docker/apache-nextcloud.conf`).
Confirm whether production shapes still need it; if not, remove it.

- [ ] Confirm production routing; remove the workaround if no longer needed.

---

## Features

### [P2] FEAT-NOTIFY-001 — Task-Due Push Reminders
**Status**: Planned · **Effort**: S · **Source**: User request 2026-07-27

Extend `DailyNotificationJob` / `NotificationService` to send "task X is due
today / tomorrow" via the Nextcloud bell + mobile push (no CalDAV needed — the
NC notification system already delivers to mobile).

- [ ] New subjects `task_due_today`, `task_due_tomorrow`; localized rich params.
- [ ] `DailyNotificationJob` (or dedicated job) queries due-today/tomorrow per user.
- [ ] Respect per-category notification preferences; no duplicate on completed tasks.
- [ ] Unit tests for job + notifier rendering.

---

## Tech Debt / Code Quality

### [P2] P9-E2E-002b — E2E long-tail `waitForTimeout` cleanup
**Status**: Backlog · **Effort**: M–L · **Source**: carved out of CS-015

~13 specs still carry ~27 `waitForTimeout` calls + residual text/positional
selectors (`multi-user-interactions`, `smoke-tests`, `minimal-smoke-tests`,
`daily-tasks-flow`, `room-management`, + 1-offs). Migrate to
`getByTestId`/`getByRole` + auto-waiting. Prerequisite met: CS-018 proved the
suite flake-free (3× full-matrix `--retries=0`).

---

### [P2] P9-DOC-005 — `development.md` example-code audit
**Status**: Open · **Effort**: S · **Source**: CS-014 review (2026-07-26)

Two deferred example-code drifts: (1) a fictional
`TaskService::calculateNextDue(Task): \DateTime` in the coding-standards example
(no such method — scheduling is owned by `SchedulingService`/`TaskSchedulerService`
/`SmartSchedulerService`/`lib/Service/Scheduling/`); (2) the "new API endpoint"
example shows raw `axios.post('/apps/ChoreStrike/...')`, contradicting the
canonical `src/services/api.js` client enforced by `apiContract.spec.js`.

- [ ] Replace/drop the fictional `calculateNextDue` example; rewrite the endpoint
      example to use the shared `api` client.

---

### [P2] P9-CONFIG-001 — Align PHP minimum version (8.0 vs 8.1)
**Status**: Open · **Effort**: XS · **Source**: CS-014 review (2026-07-26)

`info.xml` declares `<php min-version="8.0" max-version="8.3"/>`, `composer.json`
requires `^8.0`, but `AGENTS.md` says "PHP 8.1+". Decide the real minimum, then
align all three (CI pins PHP 8.2 for `composer validate`).

---

### [P4] P9-CONFIG-002 — Node `punycode` DEP0040 deprecation (informational)
**Status**: Tracked no-action (external) · **Effort**: 0 · **Source**: CS-018 baseline

Jest emits a `punycode` deprecation (Node DEP0040), surfaced transitively by a
dependency — no first-party import, does not affect results. Re-surface only if
it becomes an error in a future Node major.

---

## Review Follow-ups — CS-016 (Scheduling UI)

> From [`archive/CODE_REVIEW_2026-07-28.md`](archive/CODE_REVIEW_2026-07-28.md).
> CR-016-11 is **resolved** (CS-018); the rest are open.

### [P2] CR-016-01 — Regenerate `l10n/templates/chorestrike.pot`
**Effort**: S — `.pot` not regenerated (~90 scheduling keys missing). Decide on
an `l10n:extract` tool; `chorestrike.pot` must contain every `t()` string;
`l10n:check-keys` stays green.

### [P2] CR-016-02 — Batch the scheduling save endpoints
**Effort**: M — `AvailabilitySettings.saveAvailability` (≤14 calls) +
`EnergyBudgetSettings` (7) save per-day sequentially. Add a bulk `PUT` for a
full week; call once; loading flicker gone.

### [P2] CR-016-10 — Regression test for `getConsumedEnergyForUserOnDate` SQL fix
**Effort**: S — mock-based mapper test cannot catch SQL errors; needs a real-DB
mapper/integration test (0 with no completions; >0 seeded). Unblock the
integration harness first (`AvailabilityApiTest` `Class OC not found`).

### [P2] CR-016-12 — Non-owner availability editing surface
**Effort**: M — the member-availability grid is owner-only and the edit button
only renders on the current user's card, so non-owners have **no UI to toggle
their own weekday availability** (only energy budget). Self-availability section
driven by `getPreferences` + self-service `updateAvailability`; E2E for the
non-owner path.

### [P3] CR-016-03 — Split oversized scheduling components
**Effort**: M — `AvailabilitySettings.vue` (694) + `EnergyBudgetSettings.vue`
(584) exceed the 300-line guideline (>2×). Extract edit modal + budget analysis.

### [P3] CR-016-04 — DRY scheduling constants & helpers
**Effort**: S — `weekDays`, `ENERGY_MIN/MAX`, `isMemberAvailable`/`getMemberEnergy`,
template defs duplicated across 5 components → shared
`src/constants/scheduling.js` + helpers.

### [P3] CR-016-05 — Merge SchedulePreview + SchedulingSuggestions
**Effort**: M — near-identical suggestion-loading + display logic → one canonical
component (or shared composable).

### [P3] CR-016-06 — Locale-aware date formatting in ScheduleCalendar
**Effort**: S — `weekRange` uses bare `toLocaleDateString()` (system-locale
dependent). Use Nextcloud's date formatter or explicit translated names.

### [P3] CR-016-07 — Archive orphaned SmartScheduler surface
**Effort**: M — after the 7 `smart-schedule#*` routes were removed (security),
`SmartSchedulerController` + `SmartSchedulerService` + their tests exercise a
route-less surface (dead code). Decide archive-vs-keep.

### [P3] CR-016-09 — `DailyTaskList.vue` tech debt (pre-existing)
**Effort**: S — bypasses the API service (raw `axios`), dead ternary, empty
`rescheduleTask` stub, missing `data-testid`s. Route through store/API; fix stubs.

### [P3] CR-016-13 — Scheduling dead code + oversized suggestion payload
**Effort**: S — (a) `SchedulingService::updateMemberAvailability(int…)` no longer
called (dead, kept alive by its test); (b) store action `updateMemberAvailability`
has no UI consumer; (c) `getSuggestions` sends the full task array though backend
only uses `count($tasks)` → send `{ taskCount }`.

### [P3] CR-016-14 — AvailabilitySettings save guard + unreachable loading states
**Effort**: S — (a) no `hasChanges` guard (Save with no edits fires ≤14 calls);
(b) per-component `loading` blocks are unreachable dead UI (components mount only
inside the view's `v-else`, loading=false).

### [P4] CR-016-08 — Convert `SchedulingView` to `<script setup>`
**Effort**: S — uses Options API while all 5 children use `<script setup>`.

---

## Release / Rollout

> Stage path: `v0.8.x` alpha → `v0.9.x` beta → `v1.0.0` stable (manual install).
> App Store submission is explicitly deferred until stable quality is proven.

### [P1] RELEASE-001 — Manual release test plan (CS-PLAN-017)
**Status**: Planned (drafted in [`../plans/new/`](../plans/)) · **Effort**: M

User requirement: **no release without an existing + executed manual test plan.**
The four stale testing docs (`manual-test-checklist.md`, `alpha-test-guide.md`,
`family-test-setup.md`, `critical-tests.md`) must be consolidated into one
authoritative, English, stage-mapped `docs/testing/release-test-plan.md` with a
per-release execution/sign-off record, wired as a gate into `release-strategy.md`
exit criteria. Sequenced behind CS-015b.

### [P2] RELEASE-002 — Beta deployment + real-world alpha usage
**Status**: Pending — deploy to a personal Nextcloud instance; verify no impact
on other apps; real-world `v0.8.x` alpha usage; (optional) private tester
onboarding for `v0.9.x` beta with structured feedback intake.

### [P2] RELEASE-003 — v1.0.0 stable artifacts
**Status**: Pending — release notes, app screenshots, `v1.0.0` stable
manual-install release, App Store readiness evaluation (post-stable only).

### Milestone exit criteria (not yet satisfied)
- **`v0.9.x` Beta**: private tester onboarding; reproducible core flows across
  setups; documented install/update/rollback for testers; known-issues list;
  feedback intake operational; no open P0 / no unaccepted P1.
- **`v1.0.0` Stable**: documented supported workflows; release notes + known
  issues + install/upgrade guides; lint clean for supported paths; targeted
  automated + manual validation; release decision independent of App Store.

> Done release-prep work (security audit, CI release gate, integration/E2E
> stabilization, deployment checklist) is recorded in [`archive/`](archive/).
