# CS-PLAN-077: WP6 - Calm Core UI

> **Status:** Done — final Standard B `PASS-WITH-FIXES` (2026-10-01); activated
> via Standard A `PASS-WITH-FIXES` (2026-09-30). This file recreates the
> 2026-09-30 drafting-round plan, which was excluded from the WP5 review commit
> and lost uncommitted; Q numbering and scope follow the backlog/parent
> annotations of that draft (Q-077-02 help slice, Q-077-03 pause placement).
> **Effort:** M/L; 5-8 engineering days (parent WP6 allowance)
> **Priority:** P1
> **Depends on:** [WP0 / CS-PLAN-071](../done/cs-plan-071-wp0-write-identity-foundation.md),
> [WP1 / CS-PLAN-072](../done/cs-plan-072-wp1-occurrence-timeline.md),
> [WP2 / CS-PLAN-073](../done/cs-plan-073-wp2-agreements-capacity-pauses.md),
> [WP3 / CS-PLAN-074](../done/cs-plan-074-wp3-work-accounting-and-replay.md),
> [WP4 / CS-PLAN-075](../done/cs-plan-075-wp4-rhythm-goals-and-ranking.md) and
> [WP5 / CS-PLAN-076](../done/cs-plan-076-wp5-preferences-and-digest-delivery.md), all delivered.
> **Related backlog items:** CONCEPT-001 via
> [CS-PLAN-070](../done/cs-plan-070-family-rhythm-and-reward-clarification.md);
> FEAT-UX-023 (essential help slice); FEAT-UX-024 (presentation remainder);
> FEAT-UX-025 (pause UI remainder)
> **Planning baseline:** `fd70cd7` (WP5 final review commit), 2026-09-30

This is the implementation sub-plan for parent WP6. The parent D7/D10, C5f-m
and §3.6 UI-delivery rules remain authoritative. Section 10 collects the
activation questions for Standard A; the engineering proposals below are not
additional product choices beyond the parent's approvals.

## 1. Problem Summary

C5f/C5g (approved 2026-09-17) require: all ages land on My Day with today's
agreements, next appointments, one catch-up card per chore, explicit overload
clarification and a small confirmed/provisional/neutral progress line; free
days show a neutral empty state; arcade/catalog/rankings stay outside the
primary area; feedback is a short accessible confirmation with undo, no
default sound/confetti/modal, reduced motion always wins; preferences are
self-only and survive devices without leaking across accounts on shared
devices. WP0-WP5 delivered every backend contract this package consumes, but
the presentation layer still contradicts most of that contract.

| Area | Baseline (verified 2026-09-30 at `fd70cd7`) | Required change |
|---|---|---|
| Landing | `src/router.js:11-15` lands everyone on Dashboard; `landingRouteForRole` (`src/router.js:76-86`, applied `App.vue:137-149`) sends only children to `/today`, and only on a bare root visit. No route guards exist. | My Day becomes the post-setup landing for all ages (C5f); Dashboard remains reachable as a secondary household overview (Q-077-01). |
| My Day content | `TodayView.vue` has Mine/Everyone filter, overdue (`:58-115`) and due-today (`:117-177`) sections, empty state (`:179-182`), participation dialog (`:185-191`). **Missing:** one catch-up card per chore with cover semantics, next appointments, overload state, streak/weekly-goal progress line (no reference to either store). | Add the C5f content set with pending/neutral states; free days render a neutral empty state, not an engagement prompt. |
| Pressure language | Dashboard tagline `t('chorestrike', 'Defend your Home Base. Together.')` (`Dashboard.vue:36`); RF-070-11 cites it as the military framing. | Remove/replace with neutral copy (D10a: no military language). |
| Celebration | `useCelebration.js:29-53` fires confetti + `playSound('complete')` on every completion, plus medal/title/weekly-goal effects; `sound.js` is unconditional (module-local mute only, `:8,:34-37,:90-92`); `confetti.js` honors `prefers-reduced-motion` (`:9-15`) but not the preference store. No award modal exists; `useToasts.js:35-45` shows textual award toasts. | Default = quiet confirmation + undo; sound/confetti only behind personal opt-in (`soundEnabled`/`animationEnabled`), reduced motion always overrides (C5g); quiet text toasts stay in-app (Q-077-04). |
| Preferences UI | WP5 shipped an inline self-only Notifications section in `Settings.vue:36-151` (digest toggles/time, quiet hours, sound/animation). `src/components/Settings/NotificationPreferences.vue` does not exist; `arcadeVisible`/`catalogVisible` persist in `src/store/preferences.js:30-33` with **no consumer** anywhere in `src/`. | Full `NotificationPreferences.vue` component; optional-area toggles with the arcade consumer hiding `ArcadeSection` (catalog consumer arrives with WP8, Q-077-05). |
| Pause UI | Backend delivered (WP2): `GET/POST /api/v1/pauses`, `recognize-illness`, `{id}/approve|reject`, `PUT {id}` (`appinfo/routes.php:139-145`; `PauseService` request/approve/reject/shorten lifecycle with `commandId`+`expectedRevision`). Frontend: **no pause functions in `src/services/api.js`, no `src/store/pauses.js`, no pause UI** — only a read-only `Paused` planner label (`SchedulePlanner.vue:412`). | `pauses` store + `PauseSettings.vue`: personal request/cancel (shorten), child pending states, owner approval/house pauses/retrospective illness; placement per Q-077-03. Neutral wording, no streak threats. |
| Agreements UI | Occurrence agreement endpoints delivered (WP1/WP2): `POST /api/v1/occurrences/{id}/amend|catch-up|restart` (`appinfo/routes.php:135-137`). **No consumer in `src/`** (grep-verified). `ParticipationDialog.vue` (completion + attribution correction) exists at `src/components/Common/`, not the parent manifest's `Tasks/` path. | Minimal owner entry points for amend/catch-up/restart from task details/planner with consent semantics and 409 draft preservation; reuse `Common/ParticipationDialog.vue` unchanged in location. |
| Ranking presentation | `Leaderboard.vue` already implements the WP4 consent flow: disabled state (`:18-25`), pending-until-Monday (`:30-40`), join/withdraw, provisional badge (`:65-74`), ties (`:94,:110-112`), neutral zero state (`:91-93,:106-107`), pending list (`:124-133`). | WP6 owns the T8 presentation remainder: keyboard/ARIA accessibility pass, optional-area navigation placement, Jest presentation pins; no Coin/speed data anywhere. |
| Arcade remnants | WP3 retired BoostShop/2048/Memory; `ArcadeSection.vue` is Snake-only. **Stale:** `SnakeLeaderboard.vue:63-64` "Boosts used" label + CSS `:323-326` + l10n keys (`l10n/en.json:99`); dead `release-boost-shop` regex at `playwright.config.ts:29` and stale spec/config comments. | Remove the label/keys/regex with pinned no-Coin replacements (T10 WP6 portion, RF-070-13 remainder). |
| Shared-device reset | Only `user` and `preferences` stores reset on identity change (`user.js:311-327`); ten other stores define `reset()` with no production caller (`checklist.js:289`, `game.js:257`, `house.js:283`, `minigame.js:252`, `ranking.js:116`, `rooms.js:343`, `roomTasks.js:1139`, `scheduling.js:552`, `setup.js:81`, `tasks.js:100`, `titles.js:79`). | Central reset of every house-scoped store on identity/house switch before loading another identity (C5h; no cross-account leakage on shared devices). |
| Help/onboarding | No help UI exists; closest: SetupWizard account hint (`SetupWizard.vue:232`) and unused role-copy keys. UX-007 vocabulary drift unresolved. | Essential slice only (Q-077-02): help entry points on Dashboard/My Day/Scheduling, UX-007 glossary, rhythm/pause hints; never teach removed combo/freeze/boost mechanics; tour/catalog help stays WP8. |
| Wallet prominence | Dashboard shows `Your Coins (Available)` (`Dashboard.vue:52-55`) in the primary area. | Coins leave the primary landing; recognition-first (D7a/D10a), wallet stays reachable outside My Day. |

Durable requirements: [calm UX/evidence](../../concepts/ui-use-cases.md#approved-calm-ux-and-evidence-c5-2026-09-17),
[C5 delivery contract](../../concepts/task-model-and-sync.md#approved-delivery-contract-c5-2026-09-17),
[ADR-013](../../adr/ADR-013-occurrence-agreements-and-corrections.md),
[ADR-015](../../adr/ADR-015-ranking-consent-and-privacy.md),
[ADR-016](../../adr/ADR-016-opt-in-digest-notifications.md).
Parent manifest drift already annotated: `src/store/preferences.js` was
delivered by WP5, `ParticipationDialog.vue` and `src/utils/minorUnits.js` by
WP3, and this package adds `src/store/pauses.js`, which the parent WP6 row
does not list (parent line 998 annotation, 2026-09-30).

## 2. Strategy

One frontend cutover, no backend contract changes: consume the delivered
WP1-WP5 read models and command endpoints, move the landing to My Day, gate
every playful effect behind self-only persisted opt-ins with reduced-motion as
hard override, give pauses and occurrence agreements their missing UI, and
close the no-Coin arcade remnants. Presentation consumes server results and
versions; no second recurrence/streak/ranking implementation in stores
(parent §3.1). All mutations keep command identity, `expectedVersion` /
`expectedRevision` and 409 preserved-draft semantics already established by
WP2/WP3/WP5.

| Ownership | Deliverable / boundary |
|---|---|
| WP6 / this plan | All-age My Day landing/content; calm feedback gating; full `NotificationPreferences.vue` + optional-area toggles with arcade consumer; pause UI on the WP2 API; occurrence agreement entry points; accessible ranking presentation (T8 remainder); no-Coin UI closure (T10 remainder); shared-device store reset; essential help slice; EN/DE/POT artifacts; T9/T10 Jest and browser replacements. |
| WP0-5 / delivered | Occurrence timeline and reads (WP1), agreements/capacity/pause APIs (WP2), exact shares/undo/attribution, `Common/ParticipationDialog.vue`, `minorUnits.js`, no-Coin arcade backend (WP3), ranking backend + consent flow base (WP4), preferences persistence + minimal Settings + quiet digest (WP5). Reuse; do not reopen Q-070-01/02, Q-072-01, Q-073-01..03, Q-074-01/02, Q-075-01/02 or Q-076-01..04. |
| WP7-9 / later | Reward catalog backend/UI (the `catalogVisible` consumer lands with WP8), full FEAT-UX-023 tour/glossary remainder, cross-DB gates, family evidence (C5j). |

CONCEPT-001 owns the work. FEAT-UX-024 closes its last open checkbox (full
preference component + consumers) here; FEAT-UX-025 its pause-UI remainder;
FEAT-UX-023 only the essential slice per Q-077-02 — no whole-item closure from
hints. RF-070-11 (military greeting, calm/ranking choice) and the RF-070-13
arcade remnants are resolved by removal-with-replacement, not repair.

## 3. Backend / Frontend Changes

No new backend endpoints. `src/services/api.js` gains typed functions for the
delivered pause and occurrence-agreement routes only.

### 3.1 All-Age My Day Landing (C5f, D10a, RF-070-11)

- `router.js`: after setup, every role lands on My Day; remove the
  child-only/bare-root special case in `landingRouteForRole`. Dashboard stays
  reachable as a secondary household overview (Q-077-01 Option A); no route
  guards are added beyond the existing in-component owner checks.
- `TodayView.vue` gains, consuming delivered reads only:
  - **Catch-up**: one card per recurring chore from the existing overdue
    selection (`classification: 'late'`), showing the attributed planned date
    and window; completing current work covers the miss without relabeling it
    on time (C1c/C1d wording).
  - **Next appointments**: upcoming agreed occurrences from the existing
    range/filtered-daily reads; no unsolicited backlog or optional work.
  - **Overload**: explicit clarification state when planned + outstanding
    exceeds the member's day commitment (capacity facts from the scheduling
    read surface), inviting owner replanning — neutral, never threatening.
  - **Progress line**: small confirmed streak + shared weekly-goal progress
    with pending/neutral states (provisional while windows are open, C1e/C1f);
    no raw-activity metrics, no Coins on My Day.
  - **Free days**: neutral empty state, no engagement prompt.
- Remove the military tagline (`Dashboard.vue:36`); Dashboard keeps household
  panels outside the primary landing. Exact `minorUnits.js` formatting (WP3)
  is the sole amount rendering; no floats anywhere in new UI.

### 3.2 Calm Feedback and Preference-Gated Celebration (C5g)

- `utils/sound.js`: gate all playback on `preferences.soundEnabled` (default
  off); keep the module mute as an additional local kill-switch.
- `utils/confetti.js` and `useCelebration.js`: confetti/animation only when
  `preferences.animationEnabled` is on; `prefers-reduced-motion` always
  overrides even an explicit opt-in (existing `:9-15` check becomes the hard
  floor). Default completion feedback is the existing accessible confirmation
  with undo via `UndoSnackbar.vue` — no sound, confetti or modal.
- Quiet textual award toasts (`useToasts.js`) remain in-app (Q-077-04);
  nothing external is ever sent (WP5 contract).
- Errors, focus order and status changes remain understandable without
  sound/motion; no celebratory interruption of task flow.

### 3.3 NotificationPreferences and Optional Areas (C5h, FEAT-UX-024)

- New `src/components/Settings/NotificationPreferences.vue`: the full self-only
  component — task/org digest toggles, digest time, quiet start/end with the
  20:00-08:00 suggestion pre-filled when enabling (C5c), sound/animation
  toggles, optional-area toggles; consumes only `GET/PUT /preferences/me`
  through the delivered store, surfacing the 409 preserved-draft conflict
  state. `Settings.vue` delegates to the component (its WP5 inline section is
  replaced, not duplicated).
- Optional-area consumers: `arcadeVisible=false` hides `ArcadeSection` and its
  navigation entry; `catalogVisible` persists now and its consumer arrives
  with the WP8 catalog surface (Q-077-05). Owners cannot set anyone else's
  preferences — no such API exists and none is added.

### 3.4 Pause UI (FEAT-UX-025 remainder, D4/C2)

- New `src/store/pauses.js`: list (`GET /pauses`), personal request,
  cancel/shorten (`PUT /pauses/{id}`), owner approve/reject, house pause,
  retrospective illness recognition — each with fresh `commandId` and
  `expectedRevision`; 409 preserves the draft; reset with the central
  identity reset (3.8). Viewer-whitelisted fields only; pending child
  requests are visibly pending and change nothing (C2e).
- New `PauseSettings.vue` (placement per Q-077-03, recommended: a self-only
  Settings section plus an owner house-pause control in the owner House
  section): adults manage own pauses directly; children request and see
  pending/decided state; owners manage house pauses, decide child requests
  and record retrospective illness. Copy states pauses are neutral — no
  streak loss, no fake completion, no catch-up pressure.
- Shortening/revoking surfaces the next-local-day effectiveness rule (C2j);
  no retrospective edits are offered in UI.

### 3.5 Occurrence Agreement Entry Points (C2, parent WP6 row)

- Minimal UI for the delivered commands from task details/planner context:
  date/window amendment and handover recording (`POST /occurrences/{id}/amend`),
  agreed catch-up (`.../catch-up`) and capacity-fitting restart after a pause
  (`.../restart`). Owner-recorded with affected people named; old/new state
  visible; unfulfilled-occurrence and before-deadline preconditions explained
  in copy; `commandId` + `expectedVersion`; a 409 keeps the entered draft.
- Completion and attribution correction keep using
  `Common/ParticipationDialog.vue` (WP3); this plan does not move or reshape
  that component beyond consumer integration.

### 3.6 Accessible Ranking Presentation (T8 WP6 portion)

- `Leaderboard.vue` already implements the WP4 consent flow; WP6 finishes the
  presentation contract: keyboard/ARIA pass (focus order, live-region
  announcements for week/provisional changes), opt-in area reachable only
  through secondary navigation, pending-until-Monday, ties and neutral zero
  states pinned in Jest. No Coin/speed/contribution columns; private tasks
  never contribute; withdrawal works without owner approval, children
  included (C5i).

### 3.7 No-Coin UI Closure (D7d/D11, RF-070-13 remainder)

- Remove the `SnakeLeaderboard.vue:63-64` "Boosts used" label + CSS
  (`:323-326`) and its l10n keys; remove the dead `release-boost-shop` regex
  (`playwright.config.ts:29`) and stale spec/config comments. Snake remains
  scores-only; no boost/coin string, route hint or wallet reference survives
  in `src/` arcade surfaces (negative pins in T10).

### 3.8 Shared-Device Store Reset (C5h)

- Centralize identity/house-switch reset: one orchestration (in
  `store/user.js` reset path or a dedicated composable) resets every
  house-scoped store — user, preferences, ranking, game, minigame, house,
  rooms, roomTasks, tasks, checklist, scheduling, setup, titles and the new
  pauses store — before loading another identity, so a shared device never
  shows a previous login's data. Existing generation guards stay as the
  in-flight read protection; this adds the missing caller, not a new
  mechanism per store.

### 3.9 Essential Help Slice (FEAT-UX-023, Q-077-02)

- Help entry points on Dashboard, My Day and Scheduling opening the same
  concise help/glossary surface: UX-007 vocabulary unification (priority
  labels, Admin-Only, Scope), rhythm/window, pause and contribution
  explanations. Copy reflects only current mechanics — never removed
  combo/freeze/boost concepts; it explains real Nextcloud accounts for
  children without implying local child profiles or a guardian-consent model
  (D11). Tour, catalog help and whole-item closure stay with WP8.

### 3.10 Localization

- Every new/removed UI string updates `l10n/en.json`, `l10n/de.json` and
  `l10n/templates/chorestrike.pot` in the same change (baseline 1014 keys);
  retired keys are removed, not orphaned. All user-visible text uses `t()`.

## 4. Tests

Required future tests, not results of this documentation round. Parent
scenario IDs refer to its ordered C5/C1 tables. This package owns T9, the
Jest/presentation remainder of T8 and the UI/E2E removal proofs of T10
(parent test-ownership table and its incremental-exit note).

| Group | Required evidence | Concrete files (brace lists enumerate files) |
|---|---|---|
| My Day / T9; C5.8 | All-role landing; catch-up card per chore with cover semantics; next appointments; overload state; confirmed/provisional/neutral progress line; neutral free-day empty state; no Coins/arcade/catalog/ranking on My Day. | M `tests/unit/components/TodayView.spec.js`, `tests/unit/views/Dashboard.spec.js`; M `tests/unit/store/{roomTasks,game}.spec.js`. |
| Calm feedback / T9; C5.9 | Default: no sound/confetti on completion; opt-in enables; reduced-motion overrides an explicit opt-in; undo reachable; award toasts quiet/in-app; errors understandable without sound/motion. | M `tests/unit/components/UndoSnackbar.spec.js`; M `tests/unit/composables/useCelebration.spec.js`; M `tests/unit/utils/{sound,confetti}.spec.js` (or N where absent). |
| Preferences / T8 remainder; C5.10 | Full component renders own values, validates, saves, pre-fills quiet suggestion on enable, preserves draft on 409; optional-area toggle hides arcade; identity switch loads the correct person's settings (shared device). | N `tests/unit/components/{NotificationPreferences,PauseSettings}.spec.js`; M `tests/unit/views/Settings.spec.js`, `tests/unit/store/{preferences,user}.spec.js`. |
| Pause UI / T9 | Request/pending/approve/reject/shorten flows incl. child pending and owner house/illness paths; next-day shortening copy; 409 draft retention; viewer-whitelisted fields never render foreign pending requests. | N `tests/unit/store/pauses.spec.js`, `tests/unit/components/PauseSettings.spec.js`. |
| Agreements UI / T9 | Amend/catch-up/restart entry points send command identity + version; precondition copy; 409 keeps draft; participation dialog consumers unchanged. | M `tests/unit/components/{TaskList,TaskListDetails,SchedulePlanner,ParticipationDialog}.spec.js`, `tests/unit/store/scheduling.spec.js`. |
| Ranking presentation / T8 remainder; C5.12-14 | Keyboard/ARIA behavior, pending/ties/neutral pins, no Coin/speed/private data, withdraw without owner; navigation placement. | M `tests/unit/views/Leaderboard.spec.js`. |
| Store reset / T9; C5.10 | One identity switch resets every house-scoped store; no stale cross-account render. | M `tests/unit/store/user.spec.js`; N `tests/unit/composables/useStoreReset.spec.js` (or folded into `user.spec.js`). |
| No-Coin closure / T10 | No boost/coin surface in arcade components; Snake score never touches wallet/contribution; removed label/keys absent. | N `tests/unit/components/{ArcadeSection,SnakeGame}.spec.js`, `tests/unit/store/minigame.spec.js`; M `tests/unit/components/SnakeLeaderboard.spec.js`, `tests/e2e/snake-game.spec.ts`. (Parent lists the three N files as M — manifest drift: they do not exist at baseline.) |
| Insights surfaces / T9 | ActivityFeed/MemberFairnessTable keep frozen multi-person work/history without foreign Coin/freeze stats; correction session intact. | M `tests/unit/components/{ActivityFeed,MemberFairnessTable}.spec.js`. |
| Browser replacements | New calm-core scenarios: all-role My Day landing, catch-up card, neutral free day, default-quiet completion + reduced-motion override, pause request/approve/shorten, optional-area toggle hides arcade, shared-device second identity sees no leakage, help entry points. Existing WP4-delivered rewrites (`title-selection`, `leaderboard`, `multi-user-interactions`, `error-handling`, `insights-flow`, `quiet-digest`, `family-rhythm`) keep passing; the missing `teamwork-rewards.spec.ts` multiplier replacement is verified against `task-completion-full-flow.spec.ts` coverage before any new spec is authored. | N `tests/e2e/calm-core-ui.spec.ts` (or split by pool convention); M `tests/e2e/required-scenarios.json`, `scripts/run-e2e-gate.js`, `playwright.config.ts` (dead regex removal) in the same change. |

**Implementation gates:** `npm test -- --runInBand`, `npm run test:noise`,
`npm run lint`, `npm run stylelint`, `npm run build`,
`npm run l10n:check-keys`, `npm run backlog:check`, `npm run docs:encoding`,
`git diff --check`; Docker PHPUnit unit and integration with
`--fail-on-skipped` plus `composer cs:check` (the runtime graph must stay
green even though this package changes no backend contract), using the
[canonical Docker workflow](../../standards/ai/skills/docker-dev/SKILL.md).
Run `npm run test:e2e:gate`, not a substituted subset; keep
`required-scenarios.json`, `run-e2e-gate.js` and `playwright.config.ts`
aligned. No new/unapproved skips, retries or test-noise suppression.

## 5. File Manifest

`M` = modify existing; `N` = create after activation; `R` = retire with
replacement coverage under the gitignored `archive/2026-09/cs-plan-077-retired/`.
Section 4 test paths are part of this manifest. Recheck collisions at
activation; keep edits limited to actual contract consumers.

| Package | Files |
|---|---|
| Routing/landing | M `src/router.js`, `src/App.vue`, `src/views/TodayView.vue`, `src/views/Dashboard.vue` (tagline removal, wallet/arcade leave primary area). |
| Calm feedback | M `src/utils/sound.js`, `src/utils/confetti.js`, `src/composables/useCelebration.js`, `src/composables/useToasts.js`, `src/components/Common/UndoSnackbar.vue` only if its contract changes. |
| Preferences UI | N `src/components/Settings/NotificationPreferences.vue`; M `src/views/Settings.vue`, `src/components/Dashboard/ArcadeSection.vue` (visibility consumer), `src/App.vue` (nav gating). |
| Pause + agreements | N `src/store/pauses.js`, `src/components/Settings/PauseSettings.vue` (placement per Q-077-03); M `src/services/api.js` (pause + occurrence agreement functions), `src/components/Tasks/{TaskListDetails,TaskListItem}.vue`, `src/components/Scheduling/SchedulePlanner.vue` (entry points only). |
| Ranking/insights | M `src/views/Leaderboard.vue`, `src/components/Insights/{ActivityFeed,MemberFairnessTable}.vue` (presentation pins only). |
| Reset | M `src/store/user.js` (central reset orchestration); N `src/composables/useStoreReset.js` if not folded into `user.js`; M remaining stores only where a `reset()` gap exists. |
| Help slice | N `src/components/Common/HelpEntry.vue`, `src/components/Common/HelpGlossary.vue` (names may consolidate); M `src/views/{TodayView,Dashboard,SchedulingView}.vue` (entry points), `src/components/Setup/SetupWizard.vue` (account/role copy alignment only). |
| No-Coin closure | M `src/components/Minigames/SnakeLeaderboard.vue`, `playwright.config.ts`; R none (label/keys removed in place). |
| Fixtures / gates / l10n | M `tests/e2e/required-scenarios.json`, `scripts/run-e2e-gate.js`, `tests/e2e/fixtures/*` as scenarios require; M `l10n/{en,de}.json`, `l10n/templates/chorestrike.pot`. |
| Durable documentation | M `docs/concepts/ui-use-cases.md` (calm-UX surface as built), `docs/engineering/frontend.md` (or nearest existing frontend doc; incl. re-measured size inventory), `docs/api/README.md` (only if payload descriptions drift), `docs/testing/release-test-plan.md`, `docs/standards/ai/skills/gamification/SKILL.md` (presentation notes). No new ADR is expected; all decisions derive from approved C/D contracts. |

The **preceding drafting round** changed only this plan, the parent WP6
pointer/staleness annotations, `docs/plans/README.md`, `docs/README.md`,
`docs/backlog/backlog.md` (WP6-slice annotations) and
`docs/backlog/ACTIVE_TASKS.md`; those metadata edits were excluded from the
scoped WP5 review commit and remained uncommitted in the working tree
(RF-077-01 corrected the earlier "already shipped" claim); they land with this
activation commit. Preserve the pre-existing
`docs/plans/{new,active,onhold}/.gitkeep` deletions without staging or
restoring them.

## 6. Implementation Order

One implementation owner coordinates shared files. Internal read-only lookups
may assist; synthesis and writes remain with the main agent. This plan neither
starts Agent Manager sessions nor creates a branch.

| Step | Work and exit evidence | Effort |
|---|---|---|
| 0. Activate the contract | Standard A resolves Q-077-01..05, freezes placement/scope choices, updates ownership and tests, then activates. Drafting is not that review. | Done 2026-09-30: `PASS-WITH-FIXES`, Q-077-01..05 all Option A. |
| 1. Lock the baseline | Recheck every §1 citation at the then-current HEAD; inventory all celebration/notification consumers and E2E assertions; pin known-wrong outcomes (unconditional confetti/sound, military tagline, boosts label, child-only landing) before replacement. | 0.5-1 day |
| 2. Client foundation | `api.js` pause/agreement functions, `pauses` store, central store reset with tests. | 1 day |
| 3. My Day cutover | All-role landing, catch-up/appointments/overload/progress content, neutral free day, tagline removal, Jest + browser pins. | 1.5-2 days |
| 4. Calm feedback + preferences UI | Sound/confetti gating with reduced-motion override, `NotificationPreferences.vue`, optional-area arcade consumer, Settings delegation. | 1-1.5 days |
| 5. Pause UI + agreement entry points | `PauseSettings.vue` per Q-077-03, amend/catch-up/restart UI with 409 drafts. | 1-1.5 days |
| 6. Closure | Ranking accessibility pass, help slice, no-Coin remnants, E2E replacements + gate discovery, l10n, durable docs, full gates, final review package. | 1 day |

Steps 2-5 are development checkpoints, not independently releasable surfaces;
no half-gated celebration path or dual landing is a deliverable. Budget about
six focused hours per engineering day including tests/docs/one correction
pass. Re-estimate after the activation decisions rather than silently
absorbing added scope.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| Landing switch breaks existing browser flows | High | Pin current Dashboard-landing assumptions in step 1; update required scenarios and fixtures in the same change as the router change; three-pool gate green before closure. |
| Celebration gating regresses feedback visibility | Medium | Default confirmation + undo remains and is pinned; award toasts stay quiet in-app (Q-077-04); reduced-motion override tested both ways. |
| Pause UI blurs child/owner authority | High | UI mirrors the delivered service roles exactly (child=request/pending, owner=decide/house/illness); negative pins that children never see approve/house controls; viewer-whitelist rendering tests. |
| Shared-device leakage between logins | High | Central reset covers every house-scoped store with an explicit enumeration test; E2E second-identity scenario proves no stale render. |
| Scope creep into WP8 catalog/onboarding | Medium | Q-077-02/Q-077-05 guards: essential help slice only; catalog toggle persists without a consumer; no tour, no catalog UI. |
| My Day re-implements backend rhythm logic | Medium | Stores consume occurrence/goal/rhythm reads and versions only (parent §3.1); any computed state in UI is a finding, not a shortcut. |
| l10n key drift (1014-key baseline) | Medium | `l10n:check-keys` in every gate run; retired keys removed in the same change. |
| Agreement UI invites retrospective edits | Medium | Copy and preconditions come from C2: unfulfilled occurrences, before deadline, old/new state; expired/missed history is never editable in UI. |

## 8. Acceptance Criteria

- [x] Q-077-01..05 resolved and recorded; package scope, estimate and manifest accepted through Standard A.
- [x] After setup every role lands on My Day; it shows today's agreements, one catch-up card per chore, next appointments, explicit overload clarification and a confirmed/provisional/neutral progress line; free days render a neutral empty state; no Coins/arcade/catalog/ranking in the primary area (C5f, D10a).
- [x] No military/pressure language remains; the Dashboard tagline is replaced with neutral copy (RF-070-11).
- [x] Completion feedback defaults to an accessible confirmation with undo; sound and confetti/animation play only behind the personal opt-ins and `prefers-reduced-motion` always wins; no award modal; award toasts stay quiet and in-app (C5g).
- [x] `NotificationPreferences.vue` is the full self-only preference surface with 409 preserved-draft handling; optional-area toggles persist and the arcade consumer hides/shows its surface; no owner can read or write another member's preferences (C5h, FEAT-UX-024 remainder).
- [x] Pause UI covers personal request/cancel, child pending visibility, owner approval/rejection, house pauses and retrospective illness on the delivered WP2 API with neutral wording and next-day shortening semantics (FEAT-UX-025 remainder).
- [x] Occurrence amend/catch-up/restart entry points exist with command identity, version conflicts preserving drafts and C2 precondition copy.
- [x] Ranking presentation is keyboard/ARIA accessible with pending/ties/neutral pins and no Coin/speed/private-task data (T8 remainder).
- [x] No boost/Coin UI remnant survives: SnakeLeaderboard label, dead gate regex and stale comments removed; Snake stays scores-only (T10 remainder, RF-070-13 remainder).
- [x] One identity/house switch centrally resets every house-scoped store; a second identity on a shared device sees none of the previous login's data (C5h).
- [x] Essential help slice shipped per Q-077-02 with current-mechanics copy only; FEAT-UX-023/024/025 updated for their real completion state without premature whole-item closure; CONCEPT-001 stays accurately open.
- [x] Every section 4 required test/gate passes without new/unapproved skips; EN/DE/POT artifacts synchronized (1014-key baseline plus this change).
- [x] All plan-owned findings resolved or explicitly user-rescoped; no required dependent test deferred while marking this plan done.
- [x] Durable behavior integrated into concepts/engineering/testing docs; the plan is not the only description.
- [x] Final Standard B or Full review passes, performs the eligible lifecycle move, and updates parent/index/backlog/`ACTIVE_TASKS.md` accurately.

## 9. Review Findings (Plan-Owned)

- [x] RF-077-01 · minor · §5 draft-metadata provenance · the plan claimed the WP6 drafting-round metadata "already shipped with the WP5 review round" while it was excluded from the scoped WP5 commit and uncommitted — sentence corrected; the metadata lands with the activation commit (resolved in the 2026-09-30 Standard A round).
- [x] RF-077-02 · minor · §4 test manifest · `tests/unit/composables/useCelebration.spec.js` was marked N although the file exists at baseline — corrected to M (resolved in the 2026-09-30 Standard A round).
- [x] RF-077-03 · minor · §3.5/§5 manifest · the occurrence-agreement entry points were delivered in the **task-details context only** (`TaskListDetails.vue` + `scheduling` store actions); `SchedulePlanner.vue` stayed planning-only because its preview rows carry definition-level identity (task + weekday override), not occurrence keys — the delivered reads expose occurrence identity only on the daily/overdue/room-task surfaces, so a planner entry would have duplicated identity plumbing for a second owner surface. The `SchedulePlanner.vue` manifest line ("entry points only") is therefore unused; the delivered entry point covers the commands (amend/catch-up/restart with consent + 409 draft preservation, unit-pinned). **Resolved 2026-10-01 (final Standard B, user decision):** accepted as documented; revisit when the planner gains occurrence-row identity.
- [x] RF-077-04 · minor · §3.7/§5 manifest · the essential help slice was delivered as ONE always-reachable navigation-level glossary entry (`HelpEntry.vue` + `HelpGlossary.vue`) instead of per-view entry points on Dashboard/My Day/Scheduling — one permanent entry beat three scattered ones for the essential slice; per-view contextual entry points remain with the WP8 tour work (recorded in FEAT-UX-023). **Resolved 2026-10-01 (final Standard B, user decision):** accepted as documented.
- [x] RF-077-05 · minor · §3.1 · the My Day overdue section testid was renamed `today-section-overdue` → `today-section-catchup` with the C5f content set (catch-up is the plan's own term; C1c cards keep the attributed planned date). The unit spec was updated in the same change; no E2E spec referenced the old testid (grep-verified). **Resolved 2026-10-01 (final Standard B, user decision):** accepted as documented.
- [x] RF-077-06 · minor · §5 manifest · the arcade moved OFF the Dashboard into its own gated route (`/arcade` + `ArcadeView.vue`, nav entry hidden while `arcadeVisible` is off) instead of staying a gated Dashboard section — a stronger C5f isolation (arcade/catalog/ranking fully outside the primary AND secondary landing surfaces). `ArcadeSection.vue` remains the visibility consumer inside `ArcadeView`; `snake-game.spec.ts` and the gate manifest were updated in the same change. **Resolved 2026-10-01 (final Standard B, user decision):** accepted as documented.
- [x] RF-077-07 · major · HelpGlossary/PauseSettings i18n · the glossary term strings and the `Acknowledged`/`Declined` state labels were passed to `t()` through dynamic map lookups whitelisted in `check-l10n-keys.js` DYNAMIC — the checker never extracted the map values, so 20 glossary strings and 2 state labels were absent from POT/EN/DE and rendered untranslated outside English (§3.10 violation). **Resolved 2026-10-01 (final Standard B fix round):** literal `t()` keys in `HelpGlossary.vue` (computed terms) and `PauseSettings.vue` (label methods), both DYNAMIC whitelist rows removed, l10n artifacts regenerated (`--write`, 1108 keys) with full German copy supplied; `l10n:check-keys` green.
- [x] RF-077-08 · minor · NotificationPreferences save payload · a cleared digest-time field sent `''` instead of `null` — a parity regression against the replaced WP5 inline form. **Resolved 2026-10-01 (final Standard B fix round):** `digestTime || null` restored.
- [x] RF-077-09 · minor · `tests/e2e/zzdebug-dash.spec.ts` · untracked throwaway debug spec from the gate-debugging round (no assertions, one ESLint warning, would have run inside the gate). **Resolved 2026-10-01 (final Standard B, user decision):** deleted.

## 10. Activation Decisions

Open questions for the Standard A structured question batch. Everything else
in this plan is a parent-derived engineering proposal.

| ID | Decision and options | State |
|---|---|---|
| Q-077-01 | **My Day landing and Dashboard disposition.** A: all roles land on My Day; Dashboard stays reachable as the secondary household overview with wallet/arcade outside the primary area. B: merge Dashboard panels into My Day and remove the Dashboard view. C: keep Dashboard as the adult landing (contradicts C5f). | Resolved 2026-09-30 (Standard A): **Option A**. |
| Q-077-02 | **FEAT-UX-023 scope in WP6.** A: essential slice only — help entry points on Dashboard/My Day/Scheduling, UX-007 glossary, rhythm/pause hints; tour/catalog help and whole-item closure stay WP8. B: pull the full tour/glossary scope into WP6. C: defer all help work to WP8. | Resolved 2026-09-30 (Standard A): **Option A** (matches the backlog annotation). |
| Q-077-03 | **Pause UI placement.** A: self-only `PauseSettings.vue` section in Settings plus owner house-pause/request controls in the owner House section. B: placement in SchedulingView next to availability. C: separate top-level view/route. | Resolved 2026-09-30 (Standard A): **Option A** (matches the backlog annotation). |
| Q-077-04 | **Award feedback form.** A: keep quiet textual in-app toasts for medals/titles/weekly goal; sound/confetti strictly opt-in with reduced-motion override; no modal. B: remove award toasts too, in-app badges only. C: keep current effects default-on until opt-out (contradicts C5g). | Resolved 2026-09-30 (Standard A): **Option A**. |
| Q-077-05 | **Catalog-visibility toggle before WP8.** A: render both optional-area toggles now; `arcadeVisible` consumed immediately, `catalogVisible` persists and its consumer ships with the WP8 catalog. B: render only the arcade toggle; add the catalog toggle with WP8. | Resolved 2026-09-30 (Standard A): **Option A**. |

## Draft Validation

Draft checks (recreated 2026-09-30, before Standard A): runtime and index
remain unchanged by this plan; the frontend/backend baseline citations in §1
were verified read-only at `fd70cd7`. `npm run backlog:check`,
`npm run docs:encoding`, `git diff --check` and the standalone whitespace
check for this new file are the applicable documentation gates and must pass
with the metadata updates included. No runtime suites, family trial,
activation or commit are part of this documentation round. None of the future
section 4 tests is claimed passing from this evidence.

## Implementation Record (2026-09-30)

The implementation round completed the same day against the planning baseline
`fd70cd7` (frontend-only change set — no PHP file touched). Delivered per §6:

1. **Baseline lock** — §1 citations re-verified at HEAD; the bare-root
   Dashboard-landing inventory (29 spec files) drove the E2E landing update.
2. **Client foundation** — `services/api.js` pause + occurrence-agreement
   functions; `store/pauses.js` (fresh command UUID per attempt, displayed
   `expectedRevision`, 409 draft preservation, generation-guarded reads);
   `composables/useStoreReset.js` central identity reset wired into the
   App.vue retry path (store DAG stays acyclic — no store imports the
   composable).
3. **My Day cutover** — route-level `/` → `today` redirect for every role
   (`/dashboard` explicit secondary path); App.vue one-shot child landing
   removed; TodayView gained the C5f content set (catch-up cards with the
   occurrence block's planned date/window + C1d cover hint, next agreed
   appointments from the delivered range read, neutral overload
   clarification, calm progress line from the game-state rhythm slice,
   neutral free-day state); Dashboard tagline neutralized.
4. **Calm feedback** — `sound.js`/`confetti.js` module opt-in gates
   (default off); `useCelebration` re-syncs the gates from the preference
   store on every event; reduced motion always overrides; `main.js` fetches
   the personal preferences at bootstrap; full `NotificationPreferences.vue`
   (delivery-vs-attention NcNoteCard, digest/quiet fields, sound/animation
   opt-ins, arcade + catalog optional-area toggles per Q-077-05 A) with the
   Settings surface delegating to it; arcade consumer = gated `/arcade`
   route + nav entry (`ArcadeView.vue`).
5. **Pause + agreements UI** — `PauseSettings.vue` (self-only request list,
   request form, early end) + owner house administration in the House
   section (house pause, pending request decisions with displayed
   revision, retrospective illness); owner agreement entry points in
   `TaskListDetails.vue` (amend/catch-up/restart with consent attestation,
   409 draft preservation) via three typed scheduling-store actions.
6. **Closure** — Leaderboard live-region/roles a11y pass; help slice
   (`HelpEntry.vue` + `HelpGlossary.vue`); `SnakeLeaderboard` boosts label
   removed; dead `release-boost-shop` regex removed; new
   `calm-core-ui.spec.ts` (serial pool) + gate manifest + scenario
   inventory; E2E landing updates across 31 spec files; l10n regenerated
   (1090 keys, full EN/DE/POT coverage); durable docs
   (`docs/concepts/calm-core-ux.md`, testing table, gamification skill
   celebration contract); backlog FEAT-UX-023/024/025 updated.

Scope consolidations are recorded as RF-077-03..06 in §9. Gate evidence at
round end: Jest 1118 passed (103 suites), ESLint clean, stylelint clean,
webpack build success, `l10n:check-keys` 1090/1090, `backlog:check` green,
`docs:encoding` green (824 files), `git diff --check` clean, gate
`--self-test` passed and the three-pool discovery check green (46 required
specs, exactly one pool each). The browser gate run and the Docker backend
suite (backend untouched — last green at the WP5 final review) belong to the
final review round.

**Final gate evidence (2026-10-01, run-1qZgwd):** the complete sequential
three-pool browser gate PASSED — chromium 92, chromium-serial-db 132 and
chromium-scheduling-owner 39 scenarios, all `expected`, zero skipped /
failed / flaky, zero violations per pool. The run surfaced and fixed four
test-infrastructure defects (documented as part of this round): two
bare-root landings missed by the bulk update (dashboard-navigation,
browser-aware-navigation helper, test-house fixture), the RoomDetail
post-delete `router.push('/')` that now landed on My Day (changed to the
named `dashboard` route), and locale-coupled selectors/expectations
(floor-expand role name, planner 'Paused'/'over capacity' text, scheduling
headings/toast/unplaced phrase, NcCheckboxRadioSwitch `.check()`
interception) — profile users render in their own Nextcloud language
(dad=de), so the affected pins were switched to testids, state classes and
locale-independent values. The FloorList toggle gained a stable
`floor-expand-toggle` testid for the helper.

## Review Outcome

**Standard A — 2026-09-30: `PASS-WITH-FIXES`.** Scope: this plan file plus the
uncommitted WP6 drafting-round metadata package (parent WP6 pointer/staleness
annotations, `docs/plans/README.md`, `docs/README.md`, `backlog.md` WP6-slice
annotations, `ACTIVE_TASKS.md`), preserved uncommitted by the RF-076-11 user
decision after the scoped WP5 review commit. Base: `fd70cd7` (`master`, index
empty; the only untracked review path was this plan file). All §1 baseline
citations spot-verified exact at base (router/landing, tagline, wallet,
celebration/sound/confetti gating, Settings inline section, preference keys
without consumers, occurrence-agreement and pause routes, planner label,
Leaderboard consent states, all eleven store `reset()` sites, SnakeLeaderboard
remnants incl. `playwright.config.ts:29` and `l10n/en.json:99`, 1014-key l10n
baseline, N paths absent / M paths present incl. all eighteen §4 M-files).
Plan-only checks: stable per-step ownership, coherent single-package scope, no
overlap with the only active plan (the parent), risks named, acceptance
criteria complete, test plan adequate, every work item owned by
CONCEPT-001/FEAT-UX-023/024/025.

Findings: RF-077-01 (minor, §5 provenance claim wrong — fixed) and RF-077-02
(minor, §4 N/M manifest error — fixed); both resolved in-round, none open.
Decisions (batched structured question, recommendations first, all selected):
Q-077-01..05 all **Option A** — My Day landing with Dashboard secondary;
essential help slice; PauseSettings in Settings + owner House area; quiet
in-app award toasts with opt-in effects; both optional-area toggles now.
Gates: `npm run backlog:check`, `npm run docs:encoding`, `git diff --check`,
plan standalone whitespace — run green on the reviewed package; runtime tree
untouched (no runtime suites apply to a documentation-only round). The plan
moved `new/ → active/` with this review; the drafting-round metadata and the
activation record land with the activation commit; pre-existing
`docs/plans/{new,active,onhold}/.gitkeep` deletions stay unstaged.

**Standard B (final) — 2026-10-01: `PASS-WITH-FIXES`.** Scope: the complete
uncommitted WP6 implementation package (79 modified tracked files + the
untracked new files — components, stores, specs, l10n, durable docs) against
this plan's full manifest, acceptance criteria and §4 test evidence. Base:
`2f1abd5` (`master`, index empty; unstaged-diff sha1
`8e05ee65c9f6acbddf178e2ae143073595635de5`; untracked paths fingerprinted
per-file SHA256 in the review record). Reviewed: routing/landing cutover,
calm-feedback gating with the reduced-motion hard floor, full
`NotificationPreferences.vue` + optional-area consumers, pause store/UI with
owner administration, occurrence agreement entry points, fourteen-store
identity reset, help slice, no-Coin closure, Leaderboard a11y, Jest/E2E/gate
config, l10n artifacts and durable docs.

Findings: RF-077-07 (major, i18n — dynamic `t()` map values invisible to the
l10n key check; 20 glossary strings + `Acknowledged`/`Declined` absent from
POT/EN/DE — fixed with literal keys, DYNAMIC rows removed, artifacts
regenerated to 1108 keys with full German copy), RF-077-08 (minor — digestTime
`''` vs `null` parity — fixed), RF-077-09 (minor — zzdebug spec — deleted).
Decisions (batched structured question, recommendations first, all selected):
zzdebug deletion; RF-077-03..06 resolved as accepted documented scope
consolidations; full gate rerun for browser evidence; FEAT-UX-024 + FEAT-UX-025
closed to `archive/2026-10.md` (FEAT-UX-023 keeps its WP8 remainder,
CONCEPT-001 stays In Progress). Gates rerun green on the reviewed tree: Jest
1118/103 suites, test:noise, ESLint/stylelint zero-warning, webpack build,
`l10n:check-keys` 1108/1108, `backlog:check`, `docs:encoding` (824 files),
`git diff --check`; Docker PHPUnit unit 1028 + integration 493 with
`--fail-on-skipped` (zero skips), `composer cs:check` 0-of-377; full
three-pool browser gate `run-jYP3nz` PASSED — chromium 91, serial-db 132,
scheduling-owner 39, all expected, zero skipped/failed/flaky/violations (the
first rerun attempt failed environmentally — `TEST_DB_PROFILE` defaulted to
`minimal` after a volume reset; recreated with `family` per the canonical
workflow, not a product defect). All 15 acceptance criteria verified; the plan
moved `active/ → done/`; parent WP6 row, both READMEs, `ui-use-cases.md`,
backlog/archive and `ACTIVE_TASKS.md` updated; review record
[CODE_REVIEW_2026-10-01](../../backlog/archive/CODE_REVIEW_2026-10-01.md).
