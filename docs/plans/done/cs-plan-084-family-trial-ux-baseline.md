# CS-PLAN-084: Family-Trial UX Baseline (AiO-Current Runtime, Usability, Mobile)

> **Status:** Done — final Full review `PASS-WITH-FIXES` 2026-10-17 (round 12: deployment candidate `0.8.0-alpha.1`, both rows and the provider matrix requalified; the operator's real-device run user-rescoped to CS-PLAN-080, four minor review findings user-rescoped to `backlog.md` CR-084-01..04). History: Standard A `PASS-WITH-FIXES` 2026-10-13; rounds 1–3 committed (WP-A, WP-G part 1, RF-084-01, WP-B), rounds 4–7 committed (WP-E, WP-F, WP-C, WP-D); round 8 committed after interim Standard B `PASS-WITH-FIXES` (WP-G part 2: phone projects, full gate on both rows); round 9 committed after interim Standard B `PASS` (D-084-09 AIO v14.3.0 / PHP 8.4.26 / Redis 8.10.2 promotion, both rows requalified); round 10 committed after interim Standard B `PASS` (D-084-10 inventory `qualified`, release-discovery fix); round 11 committed after interim Standard B `PASS-WITH-FIXES` (D-084-11 NC 35.0.2, checklist duplicate fix)
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
- **D-084-09 AIO drift (operator, 2026-10-15):** AIO v14.3.0 (NC 34.0.4, PHP
  8.4.26, Redis 8.10.2) is promoted into the inventory and the AIO row is
  requalified (backend + full browser gate) inside this plan, before the
  hand-back to CS-PLAN-080.
- **D-084-10 Inventory status (operator, 2026-10-15):** promote
  `docker/test-runtime.json` to `qualified` now. Because the status is part of
  every selection hash, all four images are rebuilt and both rows rerun backend,
  full browser and package gates under the qualified hash (CS-PLAN-082 tied the
  flip to a rebuild cycle; round 9 should have carried it).
- **D-084-11 Upstream NC 35.0.2 (operator, 2026-10-15):** promote the primary
  row to Nextcloud 35.0.2 now (previous patch 35.0.1), rebuild the primary
  images and rerun the canonical gates and the package smoke; the AIO row
  (still 34.0.4 in AIO v14.3.0) keeps its qualified hash.
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
new:      src/utils/haptics.js, src/utils/errorToast.js
new:      tests/unit/components/{HoldButton,TaskFilterBar}.spec.js, tests/unit/utils/{haptics,errorToast}.spec.js,
          tests/unit/helpers/hold.js
new:      tests/integration/Service/SuccessorOwnershipTest.php, tests/integration/Db/RoomTaskListReadTest.php
new:      tests/e2e/mobile-core.spec.ts, tests/e2e/fixtures/hold-to-confirm.ts
modified: src/styles/tokens.css, src/styles/mobile.css
modified: tests/e2e/{dashboard-navigation,workflow-request-budgets,family-core-ux}.spec.ts,
          tests/unit/views/{Dashboard,TaskManager}.spec.js, tests/unit/components/{QuickStats,RoomCard,FloorList}.spec.js
modified: lib/Service/RoomTaskService.php, tests/integration/Service/SuccessorOwnershipTest.php (RF-084-02),
          lib/Db/RoomTaskMapper.php (WP-D: list reads never return tombstones), docs/api/README.md
modified: src/views/Dashboard.vue, TodayView.vue, TaskManager.vue, RoomDetail.vue
modified: src/components/Dashboard/{QuickStats,RoomCard,FloorList,HouseHealthBar}.vue
modified: src/components/Tasks/{TaskList,TaskListItem}.vue, src/utils/taskDisplay.js
modified: src/components/Settings/AvailabilitySettings.vue (UX-017)
modified: src/composables/useCelebration.js, src/store/roomTasks.js,
          src/components/Common/UndoSnackbar.vue, src/views/InsightsView.vue
modified: tests/e2e/{overdue-task-claiming,task-completion,task-completion-full-flow,today-view,…}.spec.ts,
          tests/e2e/fixtures/completion-api.ts
modified: playwright.config.ts, package.json (axe devDependency)
modified: scripts/run-e2e-gate.js (phone projects, multi-project manifest row), tests/e2e/required-scenarios.json,
          tests/e2e/fixtures/database-fixtures.ts (reset-owning phone projects),
          tests/unit/scripts/{browserGate,testTarget}.spec.js, .forgejo/workflows/release-gate.yml (webkit),
          tests/e2e/3-floor-house.spec.ts (scope-independent row lookup), tests/unit/scripts/databaseFixtures.spec.js
modified: docker/test-runtime.json, docker compose/entrypoint for the AiO row, docker/Dockerfile.test,
          docker/compose.test.yml, docker/README.md, README.md (round 9 runtime promotion),
          tests/unit/scripts/{testRuntime,testRuntimeStage}.spec.js, docs/engineering/development.md,
          scripts/test-runtime.js (round 10: highest stable release discovery),
          .forgejo/workflows/full-matrix.yml, src/store/checklist.js + tests/unit/store/checklist.spec.js (round 11)
          docs/standards/ai/skills/docker-dev/SKILL.md
modified: l10n/ (POT/EN/DE)
modified: docs/standards/ai/rules/project-rules.md (+ npm run agents:sync output)
modified: docs/engineering/testing.md, docs/engineering/deployment.md,
          docs/concepts/calm-core-ux.md, docs/concepts/ui-use-cases.md,
          docs/testing/release-test-plan.md, docs/standards/guidelines/testing.md,
          docs/standards/ai/skills/testing/SKILL.md
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

- [x] Standards state the AiO-current rule; AiO row (exact AiO NC version, Redis, `custom_apps`) and current upstream row both pass backend + browser gates; inventory promoted
- [x] No color-contrast violations (axe) on My Day, Dashboard, Tasks, Room detail in light and dark Nextcloud themes; dark tokens follow the Nextcloud theme, not the OS (round 3: 0 violations in light + dark across 9 views; `color-contrast.spec.ts` in every full gate since)
- [x] Every dashboard overview tile navigates to its filtered destination; room cards keyboard-operable; quick-clean ≥ 44 px with label (round 6; phone coverage in `mobile-core.spec.ts`, round 8)
- [x] All-tasks list paged (20), filters collapsible, "Completed" filter loads finished tasks on demand (default view stays one request)
- [x] Done and Quick clean require a 600 ms hold (pointer and keyboard); early release sends no request and shows the hold hint; Claim keeps its dialog; no double submit (round 4; phone holds in round 8)
- [x] Subtle completion feedback always on (reduced-motion respected); haptics on supporting devices; dead streak-milestone branch removed; failure toasts show the API code (round 5)
- [x] Successor ownership after takeover, handover and reassignment proven by integration tests and the mobile E2E set (`SuccessorOwnershipTest`, round 1; `mobile-core.spec.ts`, round 8)
- [x] Mobile Chromium + WebKit projects run the `@mobile` core set without skips
- [x] ~~Operator real-device checklist recorded (Android + iPhone)~~ — **user-rescoped 2026-10-17** to [CS-PLAN-080](../active/cs-plan-080-wp9-cross-db-and-family-evidence.md): it needs the deployed instance and runs at the start of the family trial; TECH-TEST-005 keeps that remainder (checklist written: release-test-plan §14.1)
- [x] Build gate green (`npm test` + lint + backend tests on both rows) (round 12)
- [x] Required tests ran without new or unapproved skips (round 12: `--fail-on-skipped` backend suites, gate runner fails on any skip)
- [x] All implementation decisions and review questions resolved and recorded (D-084-01..11, round-12 operator decisions)
- [x] All plan-owned review findings (§9) resolved or user-rescoped to `backlog.md` (RF-084-01..06 resolved; RF-084-07 rescoped to CR-084-01..04)
- [x] Owning backlog items updated or archived according to their actual completion state; RF-080-16 resolution recorded in CS-PLAN-080 (UX-017, FEAT-UX-033..036, TECH-RUNTIME-002 archived; TECH-TEST-005 stays open for the real-device run)
- [x] Durable concepts/decisions integrated (`calm-core-ux.md`, `ui-use-cases.md`, `testing.md`, `deployment.md`, project rules) — verified 2026-10-17: hold-to-confirm, feedback and error-code toasts in `calm-core-ux.md`; `/tasks` presets, paging and tile targets in `ui-use-cases.md`; the overdue boundary in `task-model-and-sync.md` and `docs/api/README.md`; phone projects and the AIO row in `testing.md`; Runtime Currency in `deployment.md` and project rules; the contrast check in `design.md`
- [x] Final Standard B or Full review is `PASS` or `PASS-WITH-FIXES` (final Full `PASS-WITH-FIXES` 2026-10-17)
- [x] `docs/backlog/ACTIVE_TASKS.md` updated

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
- [x] **RF-084-02 minor (interim Standard B 2026-10-14; resolved round 6):** `RoomTaskService::getRoomTaskStats`
  (RoomDetail stats card) counts overdue/due-today with the entity's day rule, not the
  occurrence authority, so a past planned day inside an open flex window or a
  pause-suspended miss can count as overdue there while the rows say due. Align the
  stats with `statusFor` (one batched selection) in WP-C, which reworks the room and
  dashboard cards, with a regression. **Resolution:** the stats count the list-row
  `status` (one `occurrenceSelectionsForHouse` + `statusFor` per active task);
  integration regression `SuccessorOwnershipTest::testRoomStats_FollowTheListRowBoundary`
  (today / missed / open flex window), negative control fails on the old code.
- [x] **RF-084-03 minor (interim Standard B 2026-10-14, round 4, pre-existing; resolved round 5):** a failed
  RoomCard quick clean (`roomsStore.updateRoomCleanliness`) only logs; design.md §5
  requires an error toast on every mutation failure path. Add the toast (with the
  API code) through WP-F's error-toast helper, with a Jest regression.
  **Resolution:** "Could not clean the room (<code>)" via `showErrorWithReference`;
  RoomCard Jest regression (toast text, button re-enabled).
- [x] **RF-084-04 major (final Full review 2026-10-17, architecture track; resolved round 12):**
  the RoomDetail header's *Overdue* count (`src/views/RoomDetail.vue` `overdueCount`)
  still compared `nextDue` with now, so a task due today counted — and turned the
  stat red — from midnight while the same page's rows, RoomCard and the room stats
  said due (the D-084-08 defect in one remaining view). **Resolution:** the count
  uses the shared `taskDisplay.isOverdue`; Jest regression (due / late / status
  fallback / inactive rows → 1), negative control returns 3 on the old code.
- [x] **RF-084-05 minor (final Full review, correctness track; resolved round 12):** a
  `HoldButton` that became disabled or busy mid-hold (Quick clean reaching its cap,
  the same task completing from another surface) receives no release event and its
  timer still emitted `confirm`. **Resolution:** the disabled/busy watchers abort a
  running hold and the timer re-checks both before confirming; Jest regression for
  both props, negative control fails on the old component.
- [x] **RF-084-06 minor (final Full review, performance/quality track; resolved round 12):**
  the at-least-one-day clamp made "Overdue by 1 days" reachable. **Resolution:** a
  singular key "Overdue by 1 day" (DE "Überfällig seit 1 Tag"), following the
  project's manual singular-branch idiom (the frontend key extractor has no `n()`);
  l10n artifacts regenerated (1289 keys); Jest regression including the clamped
  same-day case.
- [x] **RF-084-07 minor ×4 (final Full review; user-rescoped to `backlog.md` 2026-10-17):**
  not safely fixable in the pre-deployment round and none blocks the family trial —
  the calendar's per-day overdue rule ignores an open flex window (CR-084-01); a
  joined in-flight completion drops the second call's allocation (CR-084-02);
  single-row payloads serialize the entity status instead of the occurrence status
  (CR-084-03); `HoldButton.vue` exceeds the file-size guidance (CR-084-04).

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

### Round 3 — WP-B colors and contrast (2026-10-14)

- **Root cause of the hard-to-read UI:** since Nextcloud 32 (identical on NC34
  AIO and NC35) `--color-success|warning|error|info` are pale *tints*
  (`#D8F3DA` light / `#11321A` dark); text uses `--color-<state>-text`,
  borders/fills/icons `--color-element-<state>`. The app — qualified on NC31
  until 2026-10-05 — painted 111 text/border/fill/badge declarations with the
  tints (e.g. the room cleanliness value at 1.01:1, white badge text on pale
  tints). `--color-primary` is theme-independent (2.4:1 as dark-theme text).
  The `--cs-*` dark overrides keyed on `:root[data-theme-dark]`, which never
  matches (Nextcloud sets `data-theme-*` on `<body>`), and the fallback followed
  the OS even under an explicit light theme.
- **Fix:** status text → `-text` (54), borders → `element-` (32), badges keep
  the tint with `-text` text, fills/dots/markers → `element-`, danger buttons
  soft-style (tint + `-text` + `element-` border; white on the dark-theme error
  element is 3:1), accent text/borders `--color-primary` → `--color-primary-element`
  (62); neon hex/rgba in QuickStats, RoomCard, FloorList, HouseHealthBar,
  HouseholdGoalBar, AvailabilitySettings (original UX-017), FlexWindowDisplay and
  TaskListItem replaced by the semantic pairs; `tokens.css` dark overrides on
  `body[data-theme-dark|dark-highcontrast]` and `body[data-theme-default]` +
  OS dark.
- **Tests:** `tests/e2e/color-contrast.spec.ts` (axe 4.13.0 `color-contrast`,
  WCAG AA, `#content` of My Day, Dashboard, Tasks, Room, Leaderboard, Insights,
  Scheduling, Calendar, Settings in the light and dark Nextcloud themes;
  waits for finite transitions). Baseline before the fix: light 3 and dark 4
  violations (one dark finding was a mid-transition sample of the active
  navigation entry — settled colors measured 6.7:1 via a temporary probe spec,
  deleted). After the fix both themes 0. `theme-consistency.spec.js` gains static
  guards (no tint as text/border/fill, no white on tints, no `--color-primary`
  text/borders, body-keyed dark selectors); negative control against HEAD
  finds 12/3 offenders in RoomDetail/TaskChecklist.
- **Dependency:** `@axe-core/playwright` 4.13.0 (2026-08-11) devDependency,
  exact pin; `package-lock.json` regenerated lock-only with the pinned
  `node:20.19.0` builder (npm 10.8.2): only `@axe-core/playwright` +
  `axe-core` 4.13.0 added. The local Node 24 tree received both pure-JS
  packages via `npm pack` (integrity identical to the lock) because the host
  npm 11 refused an unrelated pre-existing optional-peer conflict
  (`@nextcloud/vue` ↔ pinned `@nextcloud/dialogs ~7.2.0`); peer validation was
  not bypassed. ESLint `n/no-unpublished-import` is off for `tests/e2e` only.
- **Docs:** design guideline "UI colors in the app" table (NC34+ pairs,
  selectors, guards); focus-outline guidance → `--color-primary-element`.
- **Gates:** Jest 119 / 1713; ESLint, Stylelint green; bundle rebuilt.
  Browser focused runs: chromium + scheduling-owner 46/47, serial 45/46 — the
  two failures were transport closures before assertions (TECH-TRANSPORT-001,
  folded in; the scheduling run overlapped a reseeding pool) and both specs pass
  isolated in their pools (11/11, 10/10). Color contrast 2/2 themes green.

### Round 4 — WP-E hold-to-confirm and in-flight guard (2026-10-14)

- **HoldButton** (`src/components/Common/HoldButton.vue`): 600 ms pointer or
  Enter/Space hold with a filling ring (`--hold-ms`), static pressed state under
  `prefers-reduced-motion`; early release, leave, > 10 px move, `pointercancel`
  (scroll) and blur abort without a request; a short tap or an untracked click
  (assistive activation) shows the inline "Press and hold to confirm" hint
  (`role="status"`, also the button's `aria-describedby`); auto-repeat ignored;
  context menu suppressed; `data-hold-state` exposes idle/holding/confirmed.
  A confirmed press stays confirmed while `busy` (disabling the focused button
  blurs it) and settles after 1 s when a dialog swallowed the release.
- **Used by** TodayView (both sections, row markup otherwise unchanged),
  TaskListItem (TaskManager + RoomDetail) and RoomCard quick clean (now 44 px,
  `aria-label`, no card navigation on press). Claim, Undo, dialog confirms and
  destructive actions unchanged (D-084-05).
- **Shared in-flight guard:** `roomTasks.completingIds` + `isCompleting(id)`;
  `completeRoomTask` joins a concurrent call for the same room task instead of
  sending a second request (TaskManager/RoomDetail had no guard); `reset()`
  releases the marks and a stale settle never clears a newer request's mark.
  Quick clean keeps its local single-flight guard.
- **Defects found while testing (fixed in-round):** (1) disabling the focused
  busy button blurred it and reset the state — RoomDetail daily tasks stayed
  rendered, so the helper never saw `confirmed`; (2) a participation dialog
  opening over the button fired `pointerleave` and ended the confirmed state
  within a frame. Jest regressions for both.
- **Tests:** `HoldButton.spec.js` (17), store join/failure/reset (3), TodayView
  busy, TaskListItem plain click + busy, RoomCard hold/plain click/single
  flight; view specs confirm through `tests/unit/helpers/hold.js`. E2E: shared
  `tests/e2e/fixtures/hold-to-confirm.ts` (`holdToConfirm`, releases on
  `data-hold-state`, repeats a press the component aborted — a late layout
  shift under a stationary mouse — at most three times);
  `completeWithOptionalDialog` and 13 completion clicks migrated without
  weakening assertions; family-core-ux keyboard test holds Enter; the 390 px
  touch test proves a short tap sends no request and shows the hint before the
  held completion. Pre-existing Jest warning fixed (TaskList spec wrote the
  read-only `userId` getter).
- **Docs:** calm-core-ux "Completion needs a deliberate press", design guideline
  feedback table (+ stale undo route corrected to `POST /completions/{id}/undo`),
  testing guideline hold helpers, ui-use-cases completion/quick-clean/undo rows.
  New string "Press and hold to confirm" (DE "Zum Bestätigen gedrückt halten").
- **Gates (canonical NC35 stack):** Jest 120 / 1738; ESLint, Stylelint,
  `l10n:check-keys` (1281), `backlog:check`, `docs:encoding`, `git diff --check`
  green; bundle rebuilt. Browser on the final bundle, no retries:
  chromium-serial-db (task-completion-full-flow, calm-core-ui, today-view,
  core-household-journey, dashboard, family-core-ux, workflow-state-reconciliation,
  family-rhythm) 47/47; chromium (core-functionality, task-completion,
  dashboard-navigation, room-detail, task-manager, overdue-task-claiming,
  color-contrast light+dark) 42/42 (both incl. 3 setup). Earlier attempts in this
  round failed on defects (1)/(2) and once on a layout-shift abort, all fixed
  above; one attempt also showed a `fetch failed` seed closure
  (TECH-TRANSPORT-001). A browser proof of quick clean follows with WP-C's
  dashboard rework; the mobile projects with WP-G part 2.

### Round 5 — WP-F feedback and diagnosable errors (2026-10-14)

- **Always-on subtle feedback (D-084-03):** `src/utils/haptics.js`
  (`pulseCompletion`, one 20 ms pulse, feature-detected, never throws; no-op on
  iOS Safari) fires from the `chorestrike:task-completed` handler independent of
  the effect opt-ins; the UndoSnackbar entry carries a decorative check mark with
  a 320 ms pop (`prefers-reduced-motion`: static). Confetti and sound stay opt-in.
- **Dead streak branch removed:** `useCelebration` no longer reads
  `currentStreak`; a completion plays the plain `complete` profile behind the
  opt-ins; medal/title/weekly-goal milestone effects unchanged.
- **Diagnosable failure toasts:** `src/utils/errorToast.js`
  (`errorReference`: stable `[A-Z0-9_]` API code, else `HTTP <status>`, else
  `NETWORK` for an ApiError without response; free text never echoed;
  `showErrorWithReference` with the translated `{message} ({reference})`
  pattern; `escape: false` only because the message is already escaped by the
  caller's `t()` and `showError` decodes once — the message must never be raw
  text). Used by completion
  (TodayView, TaskManager, RoomDetail — the claim path included), the
  UndoSnackbar undo, Insights correction/undo/load, the My Day checklist toggle
  and the new Quick clean failure toast (RF-084-03, "Could not clean the room").
  Other toasts migrate when touched (design.md §5).
- **Tests:** haptics (4), errorToast (5), useCelebration (no milestone branch,
  pulse on completions only), code-appending regressions in TodayView,
  TaskManager, RoomDetail, UndoSnackbar (+ check mark), InsightsView, RoomCard;
  TaskManager spec's l10n mock now substitutes placeholders. E2E: the 390 px
  touch test records `navigator.vibrate` (exactly one pulse for the completion,
  none for the short tap); today-view asserts the check mark.
- **Docs:** calm-core-ux feedback section (always-on feedback, no streak
  milestones, failure references), design guideline §5 toast convention.
  New strings: `{message} ({reference})`, "Could not clean the room"
  (DE "Der Raum konnte nicht geputzt werden").
- **Gates:** Jest 122 / 1756, `test:noise` clean; ESLint, Stylelint,
  `l10n:check-keys` (1283), `backlog:check`, `docs:encoding`, `git diff --check`
  green; bundle rebuilt. Browser, no retries: chromium 42/42 (core-functionality,
  task-completion, dashboard-navigation, room-detail, task-manager,
  overdue-task-claiming, color-contrast light+dark, insights-flow);
  chromium-serial-db 46 passed / 1 failed — the calm-core-ui free-day test hit a
  seed-fixture target preflight failure before any assertion
  (TECH-TRANSPORT-001, folded in); calm-core-ui then 9/9 isolated.

### Round 6 — WP-C clickable dashboard + RF-084-02 (2026-10-14)

- **QuickStats:** every tile acts. Due Today → My Day (link); Overdue →
  `/tasks?status=overdue&scope=all` (link; the counts are household-wide);
  Total rooms / Clean rooms / Avg cleanliness → in-page **buttons** that emit
  `show-rooms`. *Implementation decision:* the plan said "every tile is a
  RouterLink"; a hash route (`#/dashboard#floor-list`) would need a global
  router `scrollBehavior` that assumes window scrolling, while Nextcloud scrolls
  an inner container — so in-page targets are buttons (semantically correct)
  and the Dashboard expands every floor and focuses the floor list
  (`tabindex="-1"`), which scrolls it in any container. Tiles ≥ 44 px with a
  focus ring; the Refresh action also re-reads the house room tasks.
- **RoomCard:** the room name is a `RouterLink` stretched over the card
  (`::after`), so a click anywhere opens the room and keyboard users Tab to one
  real link; Quick clean (HoldButton) sits above it (`z-index`); the card's
  `div @click` + router push are gone (no nested interactive controls).
- **Dashboard:** `fetchHouseRoomTasks()` joins the parallel initialization
  (one house-wide `GET /room-tasks`), so room-card counts no longer depend on
  another view having loaded the room.
- **TaskManager:** `?status=` presets `active` / `overdue` / `due-today`;
  unknown values keep the default; documented as the `/tasks` router contract
  in `ui-use-cases.md` with `scope` and `visibility`.
- **RF-084-02 (backend):** see §9 — stats count the list-row status.
- **Tests:** Jest QuickStats (Overdue target + query, room tiles are buttons
  emitting `show-rooms`), RoomCard (one real link, no pseudo-button), Dashboard
  (one house read, expand + focus), TaskManager (`?status=` preset, unknown
  ignored); FloorList/RoomCard router mocks gained `RouterLink` (no console
  noise). E2E: request budget — Dashboard incl. revealed room cards issues ONE
  house-wide room-task read; dashboard-navigation — the formerly vacuous
  "navigate to room detail" test (floors collapsed → `if` skipped everything)
  now reveals rooms via the tile and clicks the card, plus keyboard Enter on the
  room link, Due Today → My Day and the `/tasks?status=overdue&scope=all`
  contract; family-core-ux (seeded) — Overdue tile → overdue-only household
  list, Avg cleanliness tile → focused floor list, Quick clean short tap = hint
  and no PUT, hold = one PUT and Kids Bedroom 40% → 50%, 44 px target.
  PHPUnit integration `testRoomStats_FollowTheListRowBoundary`.
- **Gates:** Jest 122 / 1760, `test:noise` clean; ESLint, Stylelint,
  `l10n:check-keys` (1283), `composer cs:check` (0/413), `docs:encoding` green;
  bundle rebuilt. PHPUnit (canonical NC35): unit 1385, integration 611 (full
  suites). Browser, no retries: chromium 40/40 (dashboard-navigation,
  workflow-request-budgets, core-functionality, task-completion, room-detail,
  task-manager, color-contrast light+dark, multi-user-interactions);
  chromium-serial-db 28 passed / 1 failed — room-management hit a seed POST
  `fetch failed` before any assertion (TECH-TRANSPORT-001, folded in), then
  10/10 isolated (family-core-ux, dashboard, task-completion-full-flow,
  core-household-journey in the 28).

### Round 7 — WP-D paged, compact all-tasks list (2026-10-14)

- **TaskFilterBar** (`src/components/Tasks/TaskFilterBar.vue`, executes RF-080-16):
  stateless v-model over one filter object; scope buttons always visible; the
  person/floor/room/status/priority/difficulty/visibility/search panel sits
  behind a *Filters* toggle (`aria-expanded`/`aria-controls`, "Filters (n
  active)", scope not counted) that starts collapsed at ≤ 768 px; coupled
  resets kept (scope clears person, person selects *Everyone*, floor clears
  room); every control now has a `<label for>`. `TaskManager.vue` 831 → 693
  lines; the bar is 364 lines, ~200 of them its moved styles.
- **Paging (D-084-02):** client-side pages of 20 after the shared list order
  (`compareTaskItems` in `taskDisplay.js`, also used by TaskList; undated rows
  last instead of the former `NaN` comparison); `NcButton` pager with
  "Page {page} of {pages}" (`aria-live`), hidden for one page; `?page=` is
  mirrored with `router.replace`, dropped on page 1, read on entry and on
  back/forward, clamped to the last page; any filter change returns to page 1;
  Next/Previous bring the list start into view. Compact rows (`compact` prop:
  description only when expanded, tighter spacing). The completed row's `opacity: 0.6` was
  dropped (contrast).
- **Completed filter (D-084-07):** selecting it calls
  `roomTasks.fetchInactiveHouseRoomTasks()` (one generation-guarded
  `activeOnly=false` read; only inactive rows land in `inactiveRoomTasks`; the
  active set and `houseLoaded` stay untouched); `finishedRoomTasks` merges rows
  that became inactive locally; delete and `reset()` clear them; a failed read
  toasts "Could not load finished tasks (<reference>)".
- **Defect found and fixed:** `RoomTaskMapper::findByHouse/findByRoomId/
  findByScopeId` filtered only `is_active`, so `activeOnly=false` also listed
  tombstoned (deleted) room tasks — the *Completed* filter would have shown
  deleted tasks, and the RoomDetail stats (`findByRoomId(…, false)`) already
  counted them in `totalTasks`/`completionRate`. The list reads now always
  exclude `deleted_at IS NOT NULL`. Regression `RoomTaskListReadTest` (house,
  room and endpoint reads, room stats); negative control: both tests fail on
  the old mapper.
- **Tests:** Jest TaskFilterBar (5: wide/phone default, toggle, active count,
  coupled resets, labels), TaskManager (+6: 20/page + compact + query, single
  page, `?page=` entry/clamp/invalid, reset on filter change, on-demand
  completed read once, failure toast), store (+4: inactive read params/isolation,
  superseded + failure, merge/dedupe + reset, delete), `compareTaskItems`.
  E2E: family-core-ux phone test (21+ household tasks → 20 rows, collapsed
  panel, Next → `?page=2`, reload keeps page 2, no horizontal overflow, the
  default view issues only active reads, *Completed* shows the finished one-time
  task and never the deleted one with exactly one `activeOnly=false` read, axe
  color-contrast on the completed rows); the 390/1440 px filter test asserts
  the collapsed/expanded default; the TaskManager request budget now pins
  `activeOnly=true`.
- **Docs:** `ui-use-cases.md` `/tasks` contract (`page`, collapsible panel,
  on-demand *Completed*), API `activeOnly` semantics, FEAT-UX-034 progress,
  RF-080-16 closed in CS-PLAN-080. New strings: "Filters", "Filters ({count}
  active)", "Page {page} of {pages}", "Task pages", "Could not load finished
  tasks" (DE copy added).
- **Gates:** Jest 123 / 1777 (after the review fix), `test:noise` clean; ESLint, Stylelint,
  `l10n:check-keys` (1288), `composer cs:check` (0/414) green; bundle rebuilt.
  PHPUnit (canonical NC35): unit 1385, integration 613 (full suites). Browser,
  no retries: chromium 47/47 (task-manager, core-functionality,
  workflow-request-budgets, dashboard-navigation, overdue-task-claiming,
  private-tasks, color-contrast light+dark, room-detail, setup);
  chromium-serial-db 17/17 (family-core-ux incl. the new test, dashboard).

### Round 8 — WP-G part 2: phone projects and full gate (2026-10-14/15)

- **Phone projects:** `mobile-chromium` (Pixel 7) and `mobile-webkit` (iPhone 14)
  in `playwright.config.ts`, serial, reset-owning (`database-fixtures.ts`), each
  selecting only `tests/e2e/mobile-core.spec.ts`; every other project ignores it.
  The iPhone descriptor's iOS 16 user agent is redirected by Nextcloud to
  `/unsupported`; the project sends a current iOS agent (Safari 26, frozen
  `iPhone OS 18_6`), documented in `testing.md`.
- **`@mobile` core set** (7 scenarios, family seed, every person signed in on the
  project's device, no horizontal overflow): My Day short tap = hint and no
  request, hold completes once, undo restores (child); claim confirm in one
  dialog with claim fields and the assignee kept; owner hands one occurrence to
  the child, the child holds Done, the successor is due tomorrow with the
  default owner and leaves the child's day; reassignment kid → teen through the
  detail chips (former loses, new sees it on My Day); 20-row pages with the
  filter panel collapsed, page 2, overdue filter; Overdue / Due today / Average
  cleanliness tiles and a room card; Settings through the phone navigation.
- **Gate runner:** a manifest row may name several device projects
  (`project: [...]`, owners must match exactly; each run is checked against a
  row narrowed to that project; tests counted once). `GATE_PROJECTS` runs the
  phone projects after the three Chromium pools; self-test and Jest cover a
  missing device project, an extra pool and per-run narrowing. CI installs
  WebKit. `required-scenarios.json` regenerated: besides the phone set it gained
  eight scenarios rounds 1–7 had added without updating the inventory (the full
  gate would have failed on the mismatch); nothing removed.
- **Defect in a test assumption (fixed):** `3-floor-house` "Atomic Journey"
  picked the first assignee chip and looked for the row under the default *My
  tasks* scope; once the parallel pool has made admin a member, the chip is
  Admin and the row is filtered out. The test now switches to *Everyone* and
  searches for the row (client-side, the request counts are unchanged).
- **Docs:** `testing.md` (gate execution, phone core set), testing guideline
  (pools incl. phone projects), testing skill, release-test-plan §14 row and
  §14.1 operator real-device checklist D1–D10 (Android + iPhone, child, light
  + dark, screen reader), record template; TECH-TEST-005 progress.
- **Gates:** Jest 123 / 1778, `test:noise` clean; ESLint, `l10n:check-keys`
  (1288), `backlog:check`, `docs:encoding`, `git diff --check`, gate self-test
  green. Canonical NC35: PHPUnit unit 1385, integration 613 (skips forbidden);
  full browser gate `run-fVpMiR` failed — serial-db 4 failures: the 3-floor
  assumption above and three seed `fetch failed` closures before any assertion
  (TECH-TRANSPORT-001), 4 dependent wizard skips; after the fix full gate
  **`run-sA3H52` PASSED** (chromium 95, serial-db 171, scheduling-owner 39,
  mobile-chromium 10, mobile-webkit 10 incl. setup; first attempt, no skips).
  AIO row `cs082-cs084m-aio` (NC 34.0.4.1 / PHP 8.4.25 / PostgreSQL 18.6 /
  Redis 8.10.1 for sessions, cache, locking; inventory `c22ee177…`): unit 1385,
  integration 613, full browser gate **`run-FDzLEB` PASSED** with the same
  counts.
- **Open in WP-G:** package build + smoke on both rows (needs a clean committed
  HEAD), inventory promotion `candidate → qualified`, the operator's real-device
  run. **New AIO drift (2026-10-15):** AIO v14.3.0 keeps NC 34.0.4 but ships PHP
  8.4.26 and Redis 8.10.2 (`test:runtime:check`: `aio-bundle`, `aio-php`,
  `aio-redis`); the AIO row above qualified the 14.2.0 components — promoted
  and requalified in round 9 (D-084-09).

### Round 9 — D-084-09 AIO v14.3.0 runtime promotion (2026-10-15)

- **Inventory:** `docker/test-runtime.json` PHP 8.4.25 → **8.4.26**
  (`php:8.4.26-apache`, index `901b0dbc…`, amd64 `6114a2bd…`), Redis 8.10.1 →
  **8.10.2** (`3811787…`, amd64 `2d3814be…`), AIO 14.2.0 → **14.3.0** (NC stays
  34.0.4; APCu 5.1.28, phpredis 6.3.0, PostgreSQL 18.6 unchanged),
  `verifiedAt` 2026-10-15, status stays `candidate`. PHP is one inventory field,
  so both rows moved: `Dockerfile.test` defaults (image + inventory hash
  `f18cab24…`), `compose.test.yml` runtime PHP, pinned Jest hashes (primary
  `f18cab24…`, AIO `c82c8b9c…`), drift fixtures moved one patch ahead, current
  runtime statements in README, docker README, development/deployment/testing
  docs and the docker-dev skill. The reviewed `lcobucci/clock` limitation is the
  same failure on PHP 8.4.26 (AIO ships that combination itself); historical
  records keep 8.4.25. The package builder's PHP pin is a separate reproducible
  build input and stays unchanged.
- **Images:** `cs082-runtime-35.0.1-local` (`36c54c37…`) and
  `cs082-runtime-34.0.4-local --runtime=aio` (`684b395d…`) rebuilt;
  `verify-image` `frozen-image-identity-match` on both, AIO with exactly the
  `lcobucci/clock … php-8.4.26` upstream limitation.
- **Gates:** canonical stack recreated (`canonical-runtime-match`, PHP 8.4.26):
  PHPUnit unit 1385, integration 613; full browser gate **`run-w3lY5x` PASSED**
  (95 / 171 / 39 / 10 / 10, first attempt). AIO row `cs082-cs084n-aio`
  (NC 34.0.4.1 / PHP 8.4.26 / PostgreSQL 18.6 / Redis 8.10.2 for sessions,
  cache, locking; inventory `c82c8b9c…`): unit 1385, integration 613, full
  browser gate **`run-3CDnAc` PASSED** (same counts). `test:runtime:check`
  `current`, no drift. Jest 123 / 1780, ESLint, `test:noise` clean. The
  superseded AIO stack `cs082-cs084m-aio` is stopped (owned, volumes kept).
- **Package (after the round 9 commit):** `test:package:smoke` on clean HEAD
  `b0542c8` → `package-qualified` (run `mv9kbmol`, archive SHA-256 prefix
  `ff8b66baf11acd85`): rows `primary@35.0.1` and `aio@34.0.4` on PHP 8.4.26,
  each cold first-bootstrap setup 1/1 and owner verify 6/6 (EN/DE, 390/1440 px),
  owned smoke resources removed.
- **Still open in WP-G:** inventory promotion `candidate → qualified` (the status
  is part of every selection hash — see the decision below), the operator's
  real-device run.

### Round 10 — D-084-10 inventory `qualified` + release discovery fix (2026-10-15)

- **Inventory status** `candidate → qualified` (`docker/test-runtime.json`);
  selection hashes primary `b5d9c074…`, AIO `84b3d7c2…` (previous patches
  `0f294f46…` / `a1962e2f…`); `Dockerfile.test` default and Jest pins follow.
  Tests that asserted `candidate` now assert the reported qualification; the
  "never upgrades a candidate" proof runs on an explicit candidate copy.
  `testing.md` records the status semantics (part of every hash, changes only
  together with a rebuild cycle).
- **All four images rebuilt** — `cs082-runtime-35.0.1-local` (`d6b50b1b…`),
  `-35.0.0-local --patch=previous` (`608e6877…`), `-34.0.4-local --runtime=aio`
  (`fecb12a5…`), `-34.0.3-local --runtime=aio --patch=previous` (`776b36fa…`):
  `verify-image` `frozen-image-identity-match`, qualification `qualified`;
  both AIO images carry exactly the reviewed `lcobucci/clock … php-8.4.26`
  limitation.
- **Gates under the qualified hashes:** canonical recreated
  (`canonical-runtime-match`, `qualified`): unit 1385, integration 613, full
  browser gate **`run-kHjKwd` PASSED** (95 / 171 / 39 / 10 / 10). AIO row
  `cs082-cs084q-aio` (NC 34.0.4.1 / PHP 8.4.26 / Redis 8.10.2): unit 1385,
  integration 613, full browser gate **`run-H3C9LH` PASSED** (same counts).
  Jest 123 / 1781, ESLint, `test:noise`, docs/backlog checks green.
- **Defect found and fixed (drift discovery):** `test:runtime:check` read
  GitHub `releases/latest`, which is the most recently *published* release.
  On 2026-10-15 Nextcloud published 35.0.2, 34.0.5 and then 33.0.10, so the
  check reported upstream "33.0.10" (and AIO also publishes `helm-chart-*`
  releases). Discovery now takes the highest stable `vX.Y.Z` of the release
  list (`highestStableRelease`), fail-closed without a stable entry;
  regression with today's order, helm-chart tags, `35.0.10 > 35.0.9`,
  drafts/prereleases; negative control: 14 runtime tests fail on the old code.
- **Real upstream drift:** Nextcloud **35.0.2** (2026-10-15) — the primary row
  is 35.0.1; AIO v14.3.0 still ships 34.0.4, so the AIO row stays current.

### Round 11 — D-084-11 Nextcloud 35.0.2 + checklist duplicate fix (2026-10-15/16)

- **Package (round 10 HEAD):** `test:package:smoke` on `b7eb07f` →
  `package-qualified` (run `mv9sdj70`, archive prefix `85a3cf4d345c4072`),
  `primary@35.0.1` and `aio@34.0.4`, setup 1/1 + verify 6/6 each.
- **Primary row → NC 35.0.2:** inventory `nextcloud` 35.0.2 (internal
  35.0.2.2, `ba900af9…`), notifications v35.0.2 (`6490bd9c…`), viewer v35.0.2
  (`3509a539…`, unchanged revision); previous patch 35.0.1 with its pins;
  status stays `qualified`. Hashes: primary `60d56d39…` / previous `4439a56c…`;
  the AIO selections (`84b3d7c2…` / `a1962e2f…`) are unchanged, so the AIO
  images and qualification stay bound. `Dockerfile.test` defaults, compose
  identity, CI image names (`cs082-runtime-35.0.2-local`), Jest pins and
  current-runtime docs follow. Images `cs082-runtime-35.0.2-local`
  (`035da186…`) and `-35.0.1-local --patch=previous` (`61979707…`) verified;
  the reviewed notifications stale-root-lock warning is the same on v35.0.2.
  The canonical volumes were moved with the official populated upgrade path
  (preservation mode, `35.0.1.1 → 35.0.2.2`, "Update successful", no reseed)
  through a one-off scratch override — the canonical compose has no upgrade
  variable — then recreated normally (`canonical-runtime-match`, 35.0.2.2).
- **Defect found and fixed (checklist duplicates):** the gate rendered one
  checklist item twice (`checklist-item-850`, strict-mode violation).
  `checklistStore.addItem` appended the created item blindly; when the row
  expansion's `fetchTaskItems` already delivered it first, it was listed twice.
  `addItem` now replaces an item with the same id. Jest regression (concurrent
  read lands first); negative control fails on the old store.
- **Transport closures (TECH-TRANSPORT-001):** canonical `run-02sTE9` (8 seed
  `fetch failed`, plus three timing/assertion failures — the checklist
  duplicate above, a 5 s settings hydration wait and a today-view row state)
  and `run-Tiu5Sv` (2 seed closures + an insights retry state that appeared
  after its 5 s expectation), AIO `run-jbuGNm` (1 Playwright GET
  `socket hang up`). None of the aborted requests reached Apache (access-log
  correlation); no restart/OOM/fatal. With operator approval Docker Desktop was
  restarted (labsynapse containers returned via `unless-stopped`; ChoreStrike
  stacks restarted unchanged and re-verified). After the restart both gates
  passed cleanly — consistent with a degraded long-running port forwarder;
  recorded in TECH-TRANSPORT-001, not claimed as proven.
- **Gates:** canonical NC 35.0.2: PHPUnit unit 1385, integration 613; full
  browser gate **`run-L6HInO` PASSED** (95 / 171 / 39 / 10 / 10, first attempt).
  AIO row (hash unchanged, new bundle): full browser gate **`run-Vq2pkA`
  PASSED** (same counts). Jest 123 / 1782, ESLint, `test:noise`,
  `l10n:check-keys` (1288) green; `test:runtime:check` **`current`**
  (upstream 35.0.2, AIO 14.3.0 / NC 34.0.4, no drift).
- **Package (round 11 HEAD):** `test:package:smoke` on `c5b890f` →
  `package-qualified` (run `mvah706l`, archive prefix `811715f0d8748be3`),
  rows `primary@35.0.2` and `aio@34.0.4`, setup 1/1 + verify 6/6 each — the
  current hand-over candidate for CS-PLAN-080.
- **Cleanup (operator request, 2026-10-16):** the owned AIO stacks
  `cs082-cs084m-aio`, `-cs084n-aio` and `-cs084q-aio` (3 containers, 3 volumes,
  1 network each, label-verified) were removed; evidence stays in the gitignored
  `build/` reports. The canonical stack keeps running.
- **Still open:** the operator's real-device run (release-test-plan §14.1),
  backlog closure and the final review.

### Round 12 — pre-deployment candidate 0.8.0-alpha.1 (2026-10-17)

- **Operator decisions (2026-10-17):** app version `0.8.0-alpha.1` (first `v0.8`
  alpha-stage version; Nextcloud only detects an update and phones only load the
  new scripts after a version change, and Nextcloud refuses later downgrades); a
  final Full review; this candidate's full gate counts as CS-PLAN-080 RF-080-15's
  full-gate requalification; this deployment is the first retained-baseline
  adoption (recorded in CS-PLAN-080); the real-device checklist moves to
  CS-PLAN-080; the four minor review findings go to `backlog.md`; a second
  docs-only commit records the package evidence. The round ran on branch
  `chore/pre-deployment-0.8.0-alpha.1` in a separate worktree from `master`
  `993e563`, leaving the `feat/arcade-minigames` checkout untouched.
- **Release metadata:** `appinfo/info.xml`, `package.json`/lock, README status,
  `docker/README.md` and the roadmap note move to `0.8.0-alpha.1` (valid per the
  Nextcloud app-info `semver` schema); CHANGELOG section "App Version
  0.8.0-alpha.1 — Family-Trial Candidate". No schema or migration change.
- **Gate tooling (backlog, archived):** TECH-TEST-004 deterministic package-smoke
  clock; TECH-DOCS-006 backlog links resolved against the paths git can see, now
  also from subdirectories; TECH-DOCS-004 Latin-1/C1, cp1252, `§`, emoji and BOM
  mojibake signatures — the rescan repaired 49 real corruptions in nine files;
  TECH-PKG-001 one owner-smoke case matrix in `scripts/package-target.js`.
- **Final-review fixes:** RF-084-04..06 (§9) plus the tooling nits; RF-084-07
  rescoped to CR-084-01..04.
- **Gates (worktree, after all fixes):** Jest 124 suites / 1807 tests; ESLint,
  Stylelint, `test:noise`, `l10n:check-keys` (1289), `docs:encoding` (993),
  `backlog:check` + self-test, `agents:check`, e2e-gate and package-smoke
  self-tests, `composer cs:check` (0/414), production build;
  `test:runtime:check` `current` (upstream 35.0.2, AIO 14.3.0 / NC 34.0.4),
  inventory `qualified`, both images `frozen-image-identity-match`. Staged rows
  from the worktree (fresh owned projects, family seed, AJAX cron off):
  primary `cs082-pd17-pgsql` (NC 35.0.2.2 / PHP 8.4.26 / PostgreSQL 18.6, APCu) —
  PHPUnit unit 1385, integration 613 (`--fail-on-skipped`), full browser gate
  **`run-nKi6vJ` PASSED** (95 / 171 / 39 / 10 / 10, first attempt); AIO row
  `cs082-pd17-aio` (NC 34.0.4.1 / PHP 8.4.26 / PostgreSQL 18.6 / Redis 8.10.2 for
  sessions, cache, locking) — unit 1385, integration 613, full browser gate
  **`run-p5CXyQ` PASSED** (same counts); provider suites on NC 35.0.2 —
  `cs082-pd17-mysql` (MariaDB 11.8.6) and `cs082-pd17-sqlite` (SQLite 3.46.1):
  unit 1385, integration 613 each, `--fail-on-skipped`, so all three supported
  providers pass the candidate. No TECH-TRANSPORT-001 closure occurred in this
  round.
- **Package:** the package smoke runs on the committed candidate (clean HEAD)
  and is recorded in the follow-up evidence commit.

## Review Outcome

### Standard A — 2026-10-13 — `PASS-WITH-FIXES` (`new/ → active/`)

- **Scope:** this plan file plus its drafting package (backlog items
  TECH-RUNTIME-002, UX-017, FEAT-UX-033..036, TECH-TEST-005; `ACTIVE_TASKS.md`,
  plans README, CS-PLAN-080 RF-080-16 sequencing note). Base `master`/`1951927`,
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

- **Scope:** round 1 (WP-A + WP-G part 1) working tree at `2dece85`, index empty,
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

- **Scope:** RF-084-01 round at `7b4b2eb`, index empty, 20 modified tracked paths
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

### Interim Standard B — 2026-10-14 — `PASS-WITH-FIXES` (round 3 WP-B, plan stays active)

- **Scope:** WP-B round at `173cf3a`, index empty, 58 modified tracked paths
  plus untracked `tests/e2e/color-contrast.spec.ts` (SHA256 prefix
  `a8591095f98ed32d` at capture; the tracked-diff hash was not recorded).
  [Record](../../backlog/archive/CODE_REVIEW_2026-10-14.md#round-3).
- **Checked:** every `element-`/`primary-element` background carries no text or
  the matching `*-text`; no rule paints text with its own background token
  (scripted scan over all `src/**/*.{vue,css}` rules); no `--color-primary-element`
  surface with white text; no logic, strings or API changes; ESLint override
  scoped to `tests/e2e`; exact-pinned dev dependency, lock regenerated with the
  pinned Node 20 builder.
- **Fixed in-round (1):** B3-01 four tint chips/badges (MemberList role badge,
  AssigneeChips selected, FlexWindowDisplay days badge + optimal status) paired
  `--color-primary-light` with `--color-primary-element` text (≈4.7:1 in dark)
  → Nextcloud's `--color-primary-element-light-text` pair (≈8:1).
- **Gates after fix:** Stylelint green, CSS guard Jest 9/9, bundle rebuilt,
  color-contrast light + dark 0 violations; round gates above stand.

### Interim Standard B — 2026-10-14 — `PASS-WITH-FIXES` (round 4 WP-E, plan stays active)

- **Scope:** WP-E round at `2702a93`, index empty, 28 modified tracked paths
  (unstaged diff SHA256 prefix `48d3ea3d1aa4a3bf` at capture) plus 4 untracked
  files (HoldButton, its spec, the Jest and E2E hold helpers).
  [Record](../../backlog/archive/CODE_REVIEW_2026-10-14.md#round-4).
- **Checked:** D-084-05 scope (Done + Quick clean only; claim, undo, dialog
  confirms, destructive actions unchanged); no native activation bypass
  (Enter/Space prevented, untracked click → hint); in-flight join keeps the
  command-UUID idempotency; reset ownership; strings via `t()` with DE copy;
  no logging/API/auth change; E2E migration keeps every assertion.
- **Fixed in-round (2):** E4-01 hint clipped by the room card (now above the
  button); E4-02 `completeWithOptionalDialog` dialog race and over-broad
  response matcher (now the next `/complete` request registered before the hold).
- **Routed (1):** RF-084-03 quick-clean failure without toast → §9, WP-F.
- **Gates after fixes:** Jest 120 / 1738; lint/stylelint/l10n/backlog/docs
  checks green; bundle rebuilt; browser serial 33/33 + 17/17, chromium 42/42,
  no retries.

### Interim Standard B — 2026-10-14 — `PASS-WITH-FIXES` (round 5 WP-F, plan stays active)

- **Scope:** WP-F round at `3461295`, index empty, 24 modified tracked paths
  (unstaged diff SHA256 prefix `65bc77886396eb42` at capture) plus 4 untracked
  files (errorToast, haptics and their specs).
  [Record](../../backlog/archive/CODE_REVIEW_2026-10-14.md#round-5).
- **Checked:** D-084-03 (confetti/sound still opt-in, haptics + check mark always
  on, reduced motion static); reference never echoes free text; all call sites
  pass `t()` output; no API/auth/logging change; removed streak branch was
  unreachable.
- **Fixed in-round (1):** E5-01 escaping rationale corrected — `showError`
  decodes once, so the message must be `t()` output (comment, JSDoc, design.md).
- **Resolved:** RF-084-03 (Quick clean failure toast).
- **Gates after fix:** Jest 122 / 1756; docs/diff checks green; browser results
  of the round stand (fix is comment/doc only).

### Interim Standard B — 2026-10-14 — `PASS-WITH-FIXES` (round 6 WP-C, plan stays active)

- **Scope:** WP-C + RF-084-02 round at `3610b9f`, index empty, 19 modified
  tracked paths (unstaged diff SHA256 prefix `43f1383d57671c18` at capture), no
  untracked files. [Record](../../backlog/archive/CODE_REVIEW_2026-10-14.md#round-6).
- **Checked:** stats keep the visibility filter and only read; no nested
  interactive controls in the room link; tile targets match the household-wide
  counts; `?status=` whitelist; request budget pinned; the button-vs-link
  deviation recorded with its reason.
- **Fixed in-round (2):** C6-01 misplaced docblock in `Dashboard.vue`; C6-11
  sampled-count assertion in family-core-ux replaced by "no non-overdue row".
- **Resolved:** RF-084-02.
- **Gates after fixes:** Dashboard Jest 17/17, bundle rebuilt, family-core-ux
  12/12; round gates above stand.

### Interim Standard B — 2026-10-14 — `PASS-WITH-FIXES` (round 7 WP-D, plan stays active)

- **Scope:** WP-D round at `43f719f`, index empty, 20 modified tracked paths
  (unstaged diff SHA256 prefix `44c34281f7cbd353` at capture) plus 3 untracked
  files (TaskFilterBar, its spec, RoomTaskListReadTest).
  [Record](../../backlog/archive/CODE_REVIEW_2026-10-14.md#round-7).
- **Checked:** D-084-02 (20 per page, collapsible filters, no grouping/infinite
  scroll) and D-084-07 (default view one active-only read, Completed on demand);
  mapper change only narrows reads, visibility filter unchanged; Completed read
  isolated from the active set and projections; no swallowed errors; strings via
  `t()` with DE copy; RF-080-16 extraction stateless.
- **Fixed in-round (2):** D7-01 compact rows made descriptions unreachable →
  shown when expanded (Jest regression); D7-02 E2E paging assertions assumed ≤ 40
  household tasks → derived from the total.
- **Resolved:** RF-080-16 (CS-PLAN-080).
- **Gates after fixes:** Jest 123 / 1777, lint, build; browser family-core-ux
  13/13, chromium 26/26; round gates above stand.

### Interim Standard B — 2026-10-15 — `PASS-WITH-FIXES` (round 8 WP-G part 2, plan stays active)

- **Scope:** round 8 at `8aa3240`, index empty, 15 modified tracked paths
  (unstaged diff SHA256 prefix `c214b28e00bbda1d` at capture) plus untracked
  `tests/e2e/mobile-core.spec.ts` (`9605af61e6ba1cfe`).
  [Record](../../backlog/archive/CODE_REVIEW_2026-10-15.md).
- **Checked:** multi-project manifest rows stay exact (missing device project or
  extra pool rejected, per-run narrowing, tests counted once); phone projects
  select only the phone set and run serially after the Chromium pools; inventory
  only gained identities; the 3-floor fix keeps its request assertions; no
  product code, API, auth, logging or string change.
- **Fixed in-round (2):** R8-01 pool-guard Jest regression for both phone
  projects; R8-02 handover-test comment overstated the UI evidence.
- **Decision recorded:** D-084-09 (AIO v14.3.0 requalification in this plan).
- **Gates after fixes:** Jest 123 / 1780, lint, gate self-test, fixture Jest 9/9,
  phone handover 4/4; round gates above stand (both full browser gates passed).

### Interim Standard B — 2026-10-15 — `PASS` (round 9 D-084-09 runtime promotion, plan stays active)

- **Scope:** round 9 at `2563236`, index empty, 13 modified tracked paths
  (unstaged diff SHA256 prefix `48a48523813a93f5` at capture), no untracked
  files. [Record](../../backlog/archive/CODE_REVIEW_2026-10-15.md#round-9).
- **Checked:** registry digests and selection hashes consistent across
  inventory, `Dockerfile.test`, compose identity and Jest pins; both rows rebuilt
  because PHP is one inventory field; the AIO upstream limitation unchanged in
  kind; drift fixtures still exercise every label; historical records and the
  separate package-builder pin intentionally untouched.
- **Findings:** none.
- **Gates:** round 9 gates above (both full browser gates and backend suites on
  PHP 8.4.26, drift check `current`, Jest 123 / 1780).

### Interim Standard B — 2026-10-15 — `PASS` (round 10 D-084-10, plan stays active)

- **Scope:** round 10 at `b0542c8`, index empty, 9 modified tracked paths
  (unstaged diff SHA256 prefix `78631c3aeac982d4` at capture), no untracked
  files. [Record](../../backlog/archive/CODE_REVIEW_2026-10-15.md#round-10).
- **Checked:** every hash consumer follows the qualified inventory (images,
  `Dockerfile.test`, Jest pins, running stacks); discovery fails closed and
  compares versions numerically; tooling never upgrades a candidate.
- **Findings:** none open (the discovery defect was fixed in-round with a
  regression and negative control).
- **Gates:** round 10 gates above.

### Interim Standard B — 2026-10-16 — `PASS-WITH-FIXES` (round 11 D-084-11, plan stays active)

- **Scope:** round 11 at `b7eb07f`, index empty, 18 modified tracked paths
  (unstaged diff SHA256 prefix `184ff33d30dbb752` at capture), no untracked
  files. [Record](../../backlog/archive/CODE_REVIEW_2026-10-15.md#round-11).
- **Checked:** AIO selections unchanged (images and qualification stay bound);
  primary pins/hashes consistent across inventory, Dockerfile, compose, CI and
  Jest; canonical volumes upgraded, not reset; `addItem` upsert with negative
  control; transport evidence recorded without retries or weakened assertions.
- **Fixed in-round (1):** R11-01 compose passes `TEST_RUNTIME_UPGRADE_FROM`
  (empty default) and `testing.md` documents the promotion upgrade procedure.
- **Gates after fix:** runtime suite 106/106, canonical recreated
  (`canonical-runtime-match`); round 11 gates above stand.

### Full (final) — 2026-10-17 — `PASS-WITH-FIXES` (`active/ → done/`)

- **Scope:** the whole implementation `2dece85..c5b890f` plus the uncommitted
  round 12 (26 paths at capture, unstaged fingerprint `07901f63…`), four parallel
  read-only tracks. [Record](../../backlog/archive/CODE_REVIEW_2026-10-17.md).
- **Fixed in-round:** RF-084-04 (major, RoomDetail overdue count), RF-084-05
  (hold confirmed on a disabled/busy button), RF-084-06 ("1 days"), and three
  tooling/doc nits; each with a regression and, for the code fixes, a negative
  control.
- **Rescoped (user):** CR-084-01..04 to `backlog.md`; the real-device run to
  CS-PLAN-080. Seven further nits accepted without change (record).
- **Security:** no findings.
- **Gates after fixes:** round 12 above.
