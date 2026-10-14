# CS-PLAN-084: Family-Trial UX Baseline (AiO-Current Runtime, Usability, Mobile)

> **Status:** Active — Standard A `PASS-WITH-FIXES` 2026-10-13 (drafted 2026-10-13; implementation not started)
> **Effort:** L (seven bounded work packages, no schema change)
> **Priority:** P1
> **Depends on:** none (runs before CS-PLAN-080 human evidence collection)
> **Related backlog items:** TECH-RUNTIME-002, UX-017 (scope extended), FEAT-UX-033,
> FEAT-UX-034, FEAT-UX-035, FEAT-UX-036, TECH-TEST-005

## 1. Problem Summary

The first operator installation on the private Nextcloud AiO server (2026-10-07..11)
worked only after several install-time repairs, and the operator's first core-use
round found the UI hard to read and too click-heavy for the intended family trial.
Before real family use (CS-PLAN-080 WP9 human evidence) and before any retained
baseline is adopted, the operator wants a calmer, clearer, mobile-first state as
the starting point. This plan is deliberately a polish/qualification round, not a
redesign.

**Root cause of the first attempt (for the record).** Until 2026-10-05 every gate
ran on NC31/PHP 8.2/MariaDB while production AiO 14.2.0 ran NC34.0.4/PHP 8.4/
PostgreSQL 18 ([CS-PLAN-082](../done/cs-plan-082-current-stable-test-baseline-and-data-preservation.md)
§1). That was corrected before the install, but the install still failed because
the test runtime did not resemble a real installation: an `/apps/chorestrike`
symlink hid the AiO `/custom_apps` chunk path, AiO's webserver 404'd the static
`l10n/de.json`, a never-built stylesheet was registered, fresh-install startup
races produced 403s (gates always ran seeded), and the tested bundle did not
match the commit ([install record](../../testing/install-preparation-2026-10-08.md),
[CS-PLAN-083](../done/cs-plan-083-release-qualification-gates.md) §1). CS-PLAN-083
fixed all of these. Remaining structural gaps:

- The "always test the current release" policy lives only in CS-PLAN-082's
  decision record, not in the standards; `docker/test-runtime.json` is still
  `"status": "candidate"` (verified 2026-10-05). Drift check on 2026-10-13:
  upstream 35.0.1, AiO 14.2.0 → NC 34.0.4, no drift.
- The NC34 "AiO row" is Apache + mod_php + APCu; AiO uses PHP-FPM and Redis
  (distributed cache + file locking). The `custom_apps`-only app path is already
  enforced since CS-PLAN-083 (`docker/test-entrypoint.sh:136-170`); Redis is not
  modeled anywhere.

**Usability findings (inventory 2026-10-13).**

- **Colors:** the dark-mode override selectors in `src/styles/tokens.css:92-93`
  (`[data-theme-dark] :root`, `:root[data-theme-dark]`) never match, and the
  `prefers-color-scheme` fallback follows the OS instead of the Nextcloud theme.
  Pale neon foregrounds (`QuickStats.vue:262-282`, `RoomCard.vue:288-305`) are
  tuned for dark backgrounds and nearly illegible in the light theme; white text
  on `--color-warning` badges (RoomCard/FloorList) and warning/success used as
  text color (TaskListItem) fail contrast. No automated contrast check exists.
- **Dashboard:** of five overview tiles only "Due today" and "Overdue" navigate;
  room cards are `div @click` without keyboard access; quick-clean is 28 px and
  icon-only; room-card counts depend on room tasks the Dashboard never fetches.
- **Navigation:** 11+ flat entries for every role, including children.
- **All tasks (`TaskManager.vue`, 822 lines):** flat unpaged list; seven selects
  plus search always expanded; the "Completed" status filter is always empty
  because only active tasks are fetched (`TaskManager.vue:455`, `activeOnly=true`).
- **Completion:** a single tap completes immediately (no protection against
  accidental taps); the TaskManager Done button has no in-flight guard
  (double submit possible). Failure toasts are generic although the API returns
  a stable `code` (`ControllerErrorFormatter`), which blocks diagnosing RF-080-14.
- **Feedback:** celebration (confetti/sound) is opt-in and stays so; the streak
  milestone branch reads `currentStreak`, which the completion event never carries,
  so it can never fire; no haptic feedback.
- **Mobile tests:** no Playwright mobile project; ~10 tests override a 390 px
  viewport; takeover, undo and recurrence never run at phone size.

**Lifecycle test gaps.** Complete/undo/pause/recurrence dates are well covered.
Not covered: *who* owns the successor occurrence after takeover, handover and
reassignment; the claim *confirm* UI path (only Cancel is exercised); the old
assignee losing the task after reassignment. There is no rotation feature; the
successor always returns to the revision's default members — this plan tests the
existing behavior and adds no rotation (operator decision 2026-10-13).

## 2. Strategy

Seven work packages, each independently reviewable, all inside the existing
architecture and schema (no migration, no new backend endpoint except where
noted). Frontend-heavy; backend touches are limited to tests and the runtime row.

### Decisions (operator, 2026-10-13, all recommended options)

- **D-084-01 Forwarding scope:** test existing takeover, one-occurrence handover
  and reassignment end to end, including successor ownership. No rotation feature.
- **D-084-02 All-tasks list:** client-side pages of 20, compact rows, filters
  behind a "Filters" toggle (collapsed by default on phones). No grouping, no
  infinite scroll. Server paging is not introduced because filtering is
  client-side and household task counts are small; revisit above ~500 tasks.
- **D-084-03 Feedback defaults:** always-on subtle feedback (hold-progress ring,
  check-mark state transition, short vibration where `navigator.vibrate` exists —
  Android; iOS Safari has no web vibration). Confetti and sound stay per-person
  opt-in as decided in CS-PLAN-077. `prefers-reduced-motion` replaces animations
  with static state changes.
- **D-084-04 AiO fidelity:** standards rule plus an AiO-near test row (exact AiO
  Nextcloud version, Redis cache/locking, the existing `/custom_apps`-only app
  path); weekly drift check stays report-only. No separate staging AiO instance.
  PHP-FPM and the AiO web server stay unmodeled (Apache row) and are documented
  as the remaining residual difference in `testing.md`.

### Standard A decisions (operator, 2026-10-13)

- **D-084-05 Hold-to-confirm:** 600 ms hold with a filling ring for *Done* and
  *Quick clean*. Keyboard: hold Enter/Space. Releasing early cancels with no
  request. *Claim overdue* keeps its existing OverdueClaimDialog (it already
  guards accidental taps and explains the takeover) and gets no hold.
  Destructive actions keep their existing ConfirmDialog; *Undo* stays a single
  tap (it is the safety net); dialog confirm buttons stay single-click.
- **D-084-06 Navigation:** unchanged for every role (operator decision); no
  "More" group. Ease of use comes from clickable overview tiles, the paged list
  and hold-to-confirm instead.
- **D-084-08 Overdue boundary (interim review 2026-10-13):** a task is overdue
  only after its due day has ended in the house time zone (RF-084-01).
- **D-084-07 Completed filter:** kept; selecting it loads inactive (finished
  one-time or deactivated) tasks on demand with one additional house-wide read
  (`activeOnly=false`); the default view keeps the single active-only request.

## 3. Backend / Frontend Changes

### WP-A — AiO-current runtime policy and row (TECH-RUNTIME-002)

- `docs/standards/ai/rules/project-rules.md` §2 Test Environment: one rule — the
  gate always includes the latest upstream stable *and* the exact Nextcloud
  version of the operator's current AiO release; a deployment requires a green
  AiO row for the target version; drift findings are promoted promptly.
  Detail in `docs/engineering/testing.md` (+ `release` skill checklist).
- `docker/`: AiO row gains Redis (`memcache.distributed` + `memcache.locking`,
  internal network only) and keeps the existing alias-free `custom_apps` check;
  inventory promoted from `candidate` after the row passes. No change to
  production.

### WP-B — Theme-correct colors and contrast (UX-017, scope extended)

- `src/styles/tokens.css`: dark overrides keyed to Nextcloud's body theme
  attributes (`body[data-themes*="dark"]`, legacy `body[data-theme-dark]`), OS
  fallback only for the `default` theme; new semantic tokens for status
  foreground/background pairs (ok/due/overdue/info) built from Nextcloud
  variables (`--color-success-text`, `--color-warning-text`, `--color-error-text`,
  `--color-main-text` …). Verify the exact body theme attribute names on both
  runtime rows (NC34/NC35) before relying on them; the light/dark axe checks
  are the regression.
- Replace hardcoded hex/rgba in QuickStats, RoomCard, FloorList, TaskListItem,
  HouseHealthBar, AvailabilitySettings, Calendar/Schedule components with
  tokens. Badge text never white-on-warning.

### WP-C — Clickable dashboard (FEAT-UX-033)

- `QuickStats.vue`: every tile is a `RouterLink` to its filtered destination
  (Total rooms → Dashboard floor list anchor; Clean rooms / Avg cleanliness →
  floor list; Due today → My Day; Overdue → Tasks with overdue filter); same
  44 px targets and focus ring.
- `RoomCard.vue`: semantic link/button with keyboard activation; quick-clean
  44 px with `aria-label`; Dashboard loads the room tasks it renders through the
  existing deduplicated `fetchHouseRoomTasks()` (one house-wide request, asserted
  in `workflow-request-budgets.spec.ts`).
- `TaskManager` accepts `?status=` / `?scope=` query presets (shared by tiles).

### WP-D — Paged, compact all-tasks list (FEAT-UX-034)

- Extract `components/Tasks/TaskFilterBar.vue` (executes RF-080-16 of
  CS-PLAN-080) with a collapsible filter panel and an active-filter count.
- Client-side pagination (20/page, `NcButton` pager, page resets on filter
  change, page in query string); compact row variant.
- "Completed" filter loads inactive tasks on demand per D-084-07 (store support
  for an inactive read without replacing the active list), with a regression
  test and a request-budget assertion that the default view stays one request.

### WP-E — Hold-to-confirm and double-submit guard (FEAT-UX-035)

- New `components/Common/HoldButton.vue` (600 ms; pointer + keyboard hold, progress ring,
  `aria-describedby` hint "Press and hold", cancel on release/leave/scroll,
  reduced-motion static fill; a short tap shows an inline "Press and hold"
  hint instead of doing nothing silently) — one component, used by the Done
  buttons (TodayView both sections, TaskListItem) and RoomCard quick-clean.
  The claim entry keeps tap + OverdueClaimDialog (D-084-05).
- In-flight guard for every completion entry point (shared `completingIds`).
- TodayView duplicated row markup consolidated only as far as needed to use the
  shared button (no broader refactor).

### WP-F — Feedback and diagnosable errors (FEAT-UX-036)

- `useCelebration` / completion event: subtle completion feedback per D-084-03;
  `src/utils/haptics.js` (feature-detected, no-op elsewhere).
- Remove the dead streak-milestone branch in `useCelebration.js` (reads
  `currentStreak`, which the completion event never carries; 7/30/100-day
  streak milestones are a not-implemented earlier idea per
  `gamification-and-rewards.md` §"Earlier Streak Milestone Ideas"). Rhythm
  recognition keeps celebrating through the existing medal/title events.
- Failure toasts append the API `code` as a short reference ("Could not complete
  the task (CONFLICT)") via one helper; never raw messages or stack data.

### WP-G — Lifecycle and mobile verification (TECH-TEST-005)

See §4. No production code except test helpers.

### Cross-layer contracts

No API payload, schema or migration change. Query presets for `/tasks` become a
documented router contract (`docs/concepts/ui-use-cases.md`).

## 4. Tests

- **Jest:** HoldButton (pointer hold completes, early release cancels, keyboard
  hold, reduced motion, disabled while in flight); TaskFilterBar + pagination
  (page size, reset on filter change, query round trip, completed filter);
  QuickStats/RoomCard navigation targets and keyboard activation; haptics feature detection; completion celebration without the
  removed streak branch; error code toast helper; token/theme selector regression for `tokens.css`.
- **PHPUnit integration (Docker):** successor occurrence members after
  (a) overdue takeover + completion, (b) one-occurrence handover + completion,
  (c) reassignment with an open occurrence; old assignee no longer listed;
  runs on the canonical row and the AiO row (Redis on).
- **Playwright:**
  - New `mobile-chromium` (Pixel 7) and `mobile-webkit` (iPhone 14) projects
    running a curated `@mobile` core set: My Day hold-complete + undo, claim
    confirm, handover → child completes → successor back to default owner,
    reassignment visibility, paged task list + filters, dashboard tile
    navigation, settings reachability, no horizontal overflow.
  - Shared `holdToConfirm(locator)` helper; existing completion specs migrated
    to it (no assertion weakened).
  - Claim confirm path in `overdue-task-claiming.spec.ts` (claim fields + old
    assignee view).
  - Dashboard request budget added to `workflow-request-budgets.spec.ts`.
  - Contrast: `@axe-core/playwright` color-contrast rule on My Day, Dashboard,
    Tasks and Room detail in light and dark Nextcloud themes (new devDependency,
    local only, no external service).
- **Manual (operator):** short real-device checklist (Android + iPhone, light/dark,
  child account) in `docs/testing/release-test-plan.md`; recorded, not a gate
  substitute.
- Full gate: `npm test`, `npm run lint`, `npm run l10n:check-keys`, backend unit/integration on canonical
  and AiO rows, full browser gate incl. mobile projects, package build + smoke.

## 5. File Manifest

```
new:      src/components/Common/HoldButton.vue
new:      src/components/Tasks/TaskFilterBar.vue
new:      src/utils/haptics.js
new:      tests/unit/components/HoldButton.spec.js (+ TaskFilterBar, haptics specs)
new:      tests/integration/Service/SuccessorOwnershipTest.php
new:      tests/e2e/mobile-core.spec.ts, tests/e2e/fixtures/hold-to-confirm.ts
modified: src/styles/tokens.css, src/styles/mobile.css
modified: src/router.js (only if query presets need route meta)
modified: src/views/Dashboard.vue, TodayView.vue, TaskManager.vue, RoomDetail.vue
modified: src/components/Dashboard/{QuickStats,RoomCard,FloorList,HouseHealthBar}.vue
modified: src/components/Tasks/{TaskList,TaskListItem}.vue
modified: src/components/Settings/AvailabilitySettings.vue (UX-017)
modified: src/composables/useCelebration.js, src/store/roomTasks.js
modified: tests/e2e/{overdue-task-claiming,task-completion,task-completion-full-flow,today-view,…}.spec.ts
modified: playwright.config.ts, package.json (axe devDependency)
modified: docker/test-runtime.json, docker compose/entrypoint for the AiO row
modified: l10n/ (POT/EN/DE)
modified: docs/standards/ai/rules/project-rules.md (+ npm run agents:sync output)
modified: docs/engineering/testing.md, docs/engineering/deployment.md,
          docs/concepts/calm-core-ux.md, docs/concepts/ui-use-cases.md,
          docs/testing/release-test-plan.md
```

Exact file names may shift during implementation; the manifest is updated in
the same round.

## 6. Implementation Order

1. **WP-A** runtime rule + AiO row (Redis, `custom_apps`), backend suite green on
   both rows; promote inventory. Gives every later step the right runtime.
2. **WP-G (part 1)** successor-ownership integration tests and claim-confirm E2E
   against the current UI — establishes lifecycle truth before UI changes.
3. **WP-B** tokens/contrast + axe checks (light/dark).
4. **WP-E** HoldButton + in-flight guards + `holdToConfirm` migration of specs.
5. **WP-F** feedback, streak fix, error-code toasts.
6. **WP-C** dashboard links.
7. **WP-D** TaskFilterBar extraction + pagination + completed filter.
8. **WP-G (part 2)** mobile projects + `@mobile` core set; full gate; package
   build/smoke on both rows; operator real-device checklist.
9. Docs integration, Standard B/Full review, then hand back to CS-PLAN-080 for
   deployment and family evidence.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Hold-to-confirm breaks many existing E2E specs | High | One shared `holdToConfirm` helper; migrate in WP-E's own round; assertions unchanged |
| Hold gesture conflicts with scrolling on phones | Medium | Cancel on pointer move > 10 px / `pointercancel`; mobile-webkit + mobile-chromium coverage |
| Hold is hard with screen readers | Medium | Keyboard hold + `aria-describedby`; VoiceOver/TalkBack double-tap-hold works; noted in concept doc |
| Scope creep into a redesign | Medium | Each WP bounded to listed files; new ideas go to backlog |
| Redis row exposes cache/lock assumptions | Low–Medium | That is the purpose; findings fixed in-plan or routed per §4 |
| TECH-TRANSPORT-001 flakes mask results | Medium | Failed attempts recorded, never relabelled; attribution stays with TECH-TRANSPORT-001 |
| Contrast checks flag Nextcloud core components | Low | Scope axe to app container; core issues documented, not suppressed |
| Existing production household | — | No schema/migration change; no reset; deployment follows `deployment.md` with backup + operator confirmation |

## 8. Acceptance Criteria

- [ ] Standards state the AiO-current rule; AiO row (exact AiO NC version, Redis, `custom_apps`) and current upstream row both pass backend + browser gates; inventory promoted
- [ ] No color-contrast violations (axe) on My Day, Dashboard, Tasks, Room detail in light and dark Nextcloud themes; dark tokens follow the Nextcloud theme, not the OS
- [ ] Every dashboard overview tile navigates to its filtered destination; room cards keyboard-operable; quick-clean ≥ 44 px with label
- [ ] All-tasks list paged (20), filters collapsible, "Completed" filter loads finished tasks on demand (default view stays one request)
- [ ] Done and Quick clean require a 600 ms hold (pointer and keyboard); early release sends no request and shows the hold hint; Claim keeps its dialog; no double submit
- [ ] Subtle completion feedback always on (reduced-motion respected); haptics on supporting devices; dead streak-milestone branch removed; failure toasts show the API code
- [ ] Successor ownership after takeover, handover and reassignment proven by integration tests and the mobile E2E set
- [ ] Mobile Chromium + WebKit projects run the `@mobile` core set without skips
- [ ] Operator real-device checklist recorded (Android + iPhone)
- [ ] Build gate green (`npm test` + lint + backend tests on both rows)
- [ ] Required tests ran without new or unapproved skips
- [ ] All implementation decisions and review questions resolved and recorded
- [ ] All plan-owned review findings (§9) resolved or user-rescoped to `backlog.md`
- [ ] Owning backlog items updated or archived according to their actual completion state; RF-080-16 resolution recorded in CS-PLAN-080
- [ ] Durable concepts/decisions integrated (`calm-core-ux.md`, `ui-use-cases.md`, `testing.md`, `deployment.md`, project rules)
- [ ] Final Standard B or Full review is `PASS` or `PASS-WITH-FIXES`
- [ ] `docs/backlog/ACTIVE_TASKS.md` updated

## 9. Review Findings (plan-owned)

- [x] **RF-084-01 major (interim Standard B 2026-10-13, pre-existing; resolved round 2, 2026-10-14):** `RoomTask::isOverdue()`
  (`lib/Db/RoomTask.php:156`) and `taskDisplay.isOverdue` compare the timestamp `next_due`
  with now; projected `next_due` values are midnight (all 13 active canonical rows
  `00:00:00`), so a task due today is `overdue` from midnight: red status
  (`getStatus`), claim button for others, and a same-day non-assignee completion
  recorded as a takeover with a digest change. Operator decision D-084-08: overdue
  starts only after the due day ends in the house time zone, uniformly for status,
  claim affordance and takeover recording; fix with unit/integration/E2E regressions
  in the next round (WP-G scope). **Resolution:** implemented through the existing
  occurrence authority (a missed `late` occurrence = window ended in the house
  timezone; identical to D-084-08 without flex days, and an open flex window stays
  due) — `RoomTask::isOverdue($tz)` is day-based, `RoomTaskReadService::statusFor`
  drives the list/detail `status`, takeover recording keys on the completed
  occurrence's `late` classification, and the client `isOverdue`/`dueClass`/
  `dueText`/overdue filter share one rule. Evidence: Implementation Evidence round 2.
- [ ] **RF-084-02 minor (interim Standard B 2026-10-14):** `RoomTaskService::getRoomTaskStats`
  (RoomDetail stats card) counts overdue/due-today with the entity's day rule, not the
  occurrence authority, so a past planned day inside an open flex window or a
  pause-suspended miss can count as overdue there while the rows say due. Align the
  stats with `statusFor` (one batched selection) in WP-C, which reworks the room and
  dashboard cards, with a regression.

## Boundaries

- Owned by this plan: the UI/runtime/test work above. CS-PLAN-080 keeps RF-080-14
  (production completion failure — WP-F's error codes and WP-G's claim regressions
  provide the evidence path, not the closure), RF-080-15 (renewed full gate and
  operator retest — satisfied by this plan's full gate only if the operator agrees)
  and all human/family evidence. RF-080-16 is *executed* by WP-D and closed in
  CS-PLAN-080 when delivered.
- Not in scope: navigation restructuring (D-084-06), PHP-FPM/AiO web-server emulation, rotation/round-robin assignment, new reward features, server-side
  paging, a staging AiO instance, deployment or retained-baseline adoption (those
  follow `deployment.md` after this plan, with backup and operator confirmation).

## Implementation Evidence

### Round 1 — WP-A + WP-G part 1 (2026-10-13)

- **WP-A runtime currency:** project rule "Runtime Currency (Nextcloud AIO)" added
  (`project-rules.md` §2, adapters re-synced); `deployment.md` pre-deployment
  runtime checklist; `testing.md` "AIO-near runtime row"; `release`/`docker-dev`
  skills. `test:runtime:check` now parses the AIO release's Nextcloud, Redis and
  PostgreSQL container sources and reports `aio-php`/`aio-apcu`/`aio-phpredis`/
  `aio-redis`/`aio-postgres` drift (fail-closed on unparsable sources). The first
  live check found real AIO drift: APCu 5.1.28 (AIO) vs 5.1.24 (inventory);
  Redis was not modeled. Inventory now pins APCu 5.1.28, phpredis 6.3.0 and
  `cache.redis` = `redis:8.10.1-alpine@sha256:ef216d85…` (AIO 14.2.0). Live
  check after the update: `current`, all five components equal.
- **AIO row with Redis:** `Dockerfile.test` installs phpredis; the entrypoint
  (with `REDIS_HOST`) writes the AIO `redis-session.ini` equivalent before Apache
  starts and imports `memcache.distributed`/`memcache.locking` = Redis;
  `test-runtime-stage.js ... aio` adds the pinned Redis companion and `verify`
  proves a Nextcloud cache round trip, Redis session handler and the owned,
  pinned, healthy Redis peer. Selection hashes: primary `4f98b19e…`, AIO
  `c22ee177…`. `verify-image --runtime=aio` passes (NC 34.0.4.1, PHP 8.4.25,
  APCu 5.1.28, phpredis 6.3.0; the known `lcobucci/clock` limitation unchanged).
- **WP-G successor ownership** (`SuccessorOwnershipTest`, 3 tests / 42
  assertions, children as viewers because adults see every house task):
  overdue takeover and one-occurrence handover return the next occurrence to the
  default assignee; reassignment moves the open and every following occurrence
  and removes the task from the former child's day.
- **Defect found and fixed (regression above):** completing an *agreed handover*
  on a task due today (already `isOverdue()` by timestamp) was recorded as an
  overdue **takeover** (`was_claimed`, claim fields) and queued a "taken over"
  digest change for the default assignee. `CompletionService` now records a
  takeover only when the completer is not obligated by the occurrence's concrete
  agreement.
- **Claim flow (RF-080-14 path):** confirming the overdue-claim dialog used to
  open the participation dialog pre-filled with the original assignee — a third
  step a child cannot resolve. The confirmed claim now completes directly with
  the reviewed sole-participant allocation (TaskList → TaskManager/RoomDetail);
  Jest regressions in `TaskList.spec.js`, `TaskManager.spec.js`,
  `RoomDetail.spec.js`; E2E `overdue-task-claiming.spec.ts` gains a real UI
  confirm test (claim fields, no participation dialog, undo entry, next
  occurrence not overdue). This validates the path flagged in RF-080-14; it does
  not prove the production incident's cause.
- **Provider-order defect found on the AIO row and fixed:**
  `HouseUserMapper::findByHouseIdAndNextcloudUserIds` returned PostgreSQL plan
  order (BatchQueryRegressionTest failed once on the AIO row); the query now
  orders by `id`; the test passed three reruns and the full suite rerun.
- **Gates (round 1):** Jest 119 suites / 1703 tests; ESLint, Stylelint,
  `l10n:check-keys` (1280 keys), `composer cs:check` (0/413), docs encoding,
  backlog check, `agents:check`, `git diff --check` green. `test:runtime:check`
  live `current`. AIO row `cs082-cs084-aio` (NC 34.0.4 / PHP 8.4.25 /
  PostgreSQL 18.6 / Redis 8.10.1, sessions+cache+locking on Redis): unit
  1379/5017, integration 607/5094 with skips forbidden (first integration run
  1 failure → fixed above, rerun green). Rebuilt canonical stack
  (`canonical-runtime-match`, NC 35.0.1, APCu 5.1.28, phpredis 6.3.0): unit
  1379/5017, integration 607/5115. Browser (canonical, Chromium):
  `overdue-task-claiming` 9/9 incl. setup; task-manager, task-completion,
  task-completion-full-flow, room-detail, family-core-ux 33/33, no retries.
  The full browser gate and the AIO-row browser gate follow in WP-G part 2.

### Round 2 — RF-084-01 overdue boundary (2026-10-14)

- **Backend:** `RoomTask::isOverdue(?tz)` = due day before today in the house
  zone (was `next_due < now`, i.e. overdue from the due day's midnight);
  `getStatus`, room stats and the task detail pass the house zone;
  `RoomTaskReadService::statusFor()` makes the list (`RoomTaskController`
  serializer) and detail `status` follow the occurrence classification (`late`
  → `overdue`; open flex window → `due_today`); `CompletionService` records a
  takeover only for a `late` occurrence completed by a non-assignee who is not
  obligated by its agreement.
- **Frontend:** one `isOverdue(roomTask)` (occurrence classification, status
  fallback) drives the row badge, claim button and TaskManager overdue filter;
  `dueClass`/`dueText` count house-local days (no more "Overdue by 0 days"
  arithmetic on instants; "No due date" for missing dates).
- **Tests:** RoomTaskTest (UTC, Berlin, New York, Auckland), RoomTaskReadServiceTest
  `statusFor`; SuccessorOwnershipTest +3 (same-day sibling completion is no
  takeover and queues no digest notice; agreed catch-up handover of a missed
  occurrence is no takeover; list status today/missed/open flex window);
  taskDisplay/TaskList/TaskListItem Jest regressions; E2E "work due today is never
  overdue" plus house-clock-based probe dates (fixes the probes' local-midnight
  edge).
- **Docs:** API `GET /room-tasks` status semantics, task-model "Overdue
  boundary", UI use case claim row.
- **Gates (round 2, canonical NC35 stack):** Jest 119 / 1709; PHPUnit unit
  1385, integration 610 (skips forbidden); ESLint, `composer cs:check` (0/413),
  `l10n:check-keys`, docs encoding green. Browser (Chromium + serial-db, no
  retries): today-view, time-travel-overdue, dashboard, dashboard-navigation,
  task-manager, room-detail, calendar, family-core-ux, family-rhythm,
  release-task-lifecycle, task-completion(-full-flow), overdue-task-claiming —
  75/75. The AIO-row rerun follows with the WP-G part 2 full gate.

## Review Outcome

### Standard A — 2026-10-13 — `PASS-WITH-FIXES` (`new/ → active/`)

- **Scope:** this plan file plus its drafting package (backlog items
  TECH-RUNTIME-002, UX-017, FEAT-UX-033..036, TECH-TEST-005; `ACTIVE_TASKS.md`,
  plans README, CS-PLAN-080 RF-080-16 sequencing note). Base `master`/`ee73299`,
  index empty apart from the intent-to-add plan path; captured plan SHA256
  prefix `d785318fa06ae765`, unstaged diff SHA256 prefix `e91f6de5c32a6253`.
  [Record](../../backlog/archive/CODE_REVIEW_2026-10-13.md).
- **Verified against the code:** `tokens.css:92-93` selectors, TaskManager
  `activeOnly` load (`TaskManager.vue:455`), generic completion toasts despite
  API `code`, OverdueClaimDialog confirmation, alias-free `custom_apps`
  provisioning, completion event payload, request-budget spec, takeover/handover
  ownership in ADR-011/ADR-013, drift check (`current`: upstream 35.0.1, AiO
  14.2.0 → NC 34.0.4).
- **Fixed in the plan (7):** A-01 stale claim that the `custom_apps` path was
  unmodeled (already enforced since CS-PLAN-083); A-02 WP-F "make streak
  milestone fire" contradicted the gamification concept (milestones never
  implemented) → dead branch removal; A-03 hold without short-tap feedback
  would be a silent no-op for children → hold hint; A-04 new Dashboard read had
  no request-budget guard → budget assertion; A-05 Nextcloud theme attribute
  names unverified → verification on both rows; A-06 residual AiO differences
  (PHP-FPM, web server) unstated → explicit non-goal + `testing.md`; A-07 l10n
  key check missing from the full gate → added.
- **Decisions (batched, operator 2026-10-13):** D-084-05 claim keeps its dialog
  without hold, 600 ms hold for Done/Quick clean (recommended); D-084-06
  navigation unchanged (operator chose against the recommended child "More"
  group); D-084-07 Completed filter loads finished tasks on demand (recommended).
- **Plan-only checks:** stable WP ownership with backlog IDs; no overlap with
  CS-PLAN-070/080 beyond the recorded RF-080-14/15/16 boundaries; no schema or
  migration change (data-retention boundary respected; existing production
  facts untouched); risks named; acceptance criteria and test plan complete.
- **Gates:** `backlog:check` green, `docs:encoding` green, `git diff --check`
  clean. No runtime code changed.
- **Open plan-owned findings:** none.

### Interim Standard B — 2026-10-13 — `PASS-WITH-FIXES` (plan stays active)

- **Scope:** round 1 (WP-A + WP-G part 1) working tree at `305b62a`, index empty,
  26 modified tracked paths (unstaged diff SHA256 prefix `8ed59da905f118b7` at
  capture) plus untracked `tests/integration/Service/SuccessorOwnershipTest.php`
  (`084c7e10d4d4a7c5`). [Record](../../backlog/archive/CODE_REVIEW_2026-10-13.md).
- **Checked:** architecture (claim/takeover rule stays in `CompletionService`;
  mapper ordering in the mapper; views only route the reviewed allocation),
  authorization (claim allocation names only the actor; server still enforces
  self-participation, visibility and private-task 404), no new user strings,
  logging unchanged, runtime scripts fail closed on unparsable AIO sources and
  foreign/unpinned Redis, entrypoint validates `REDIS_HOST`/`REDIS_PORT` before
  any mutation, provisioning tests isolate `PHP_INI_DIR`/`REDIS_HOST`.
- **Fixed in-round (1):** confirming a claim with an unresolved actor identity was
  a silent no-op; it now falls back to the participation-dialog path (Jest
  regression).
- **Routed (1):** RF-084-01 pre-existing midnight overdue boundary → §9, decision
  D-084-08, next round.
- **Gates after fixes:** TaskList spec 32/32 + ESLint on changed files; full gates
  of round 1 above (backend on canonical and AIO rows, focused browser 42/42,
  lint/style/l10n/cs/docs/backlog/agents checks); full Jest rerun after the fix
  119 suites / 1704 tests.

### Interim Standard B — 2026-10-14 — `PASS-WITH-FIXES` (round 2, plan stays active)

- **Scope:** RF-084-01 round at `eb75142`, index empty, 20 modified tracked paths
  (unstaged diff SHA256 prefix `8ec569f4ce9161b5` at capture), no untracked files.
  [Record](../../backlog/archive/CODE_REVIEW_2026-10-14.md).
- **Checked:** status/claim/takeover follow one rule (occurrence `late`, house-zone
  day bucket fallback); controller only delegates to `statusFor`; no new strings
  (`No due date` reused); null due dates, inactive rows, voluntary/flex/missed
  classifications covered; zones east and west of UTC tested.
- **Fixed in-round (2):** Dashboard `RoomCard` still counted overdue by instant
  (work due today counted as overdue) and the TaskManager "Due today" filter used
  its own day rule (an open flex window was badged "today" but filtered out) — both
  now use `isOverdue`/`dueClass` (RoomCard Jest regression replaces a placeholder).
- **Routed (1):** RF-084-02 minor (room stats card day rule vs occurrence authority
  for flex/paused rows) → §9, WP-C.
- **Gates after fixes:** Jest 119 / 1709; ESLint on changed files; rebuilt bundle.
  Browser rerun (dashboard, dashboard-navigation, task-manager, today-view,
  time-travel-overdue, overdue-task-claiming, room-detail) first attempt 35 passed /
  2 failed: running the parallel `chromium` and seeding `chromium-serial-db`
  projects concurrently raced the family reseed (`House with id … does not exist`
  in the seed endpoint, then a cascading missing private-filter fixture) — an
  invocation conflict, not a product defect; the two specs then passed isolated
  in their pool 9/9. Earlier round-2 gates (unit 1385, integration 610, browser
  75/75 with the official pool separation) stand.
