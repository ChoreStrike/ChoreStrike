# CS-PLAN-006: Role Title System

> **Status:** Plan (not started)
> **Effort:** M
> **Priority:** P2
> **Depends on:** CS-PLAN-004 (build gate). Independent of CS-PLAN-005 (different code paths).
> **Related:** `docs/backlog/ACTIVE_TASKS.md` (entry `ROLE-TITLE-SYSTEM-001`, Planned 2026-03-07), `docs/concepts/GAMIFICATION_AND_REWARDS.md`, `docs/plans/done/cs-plan-002-gamification-simplification.md` (delivered the stat substrate this plan builds on), `docs/plans/template.md`

## 1. Problem Summary

The original 2026-03-07 backlog entry `ROLE-TITLE-SYSTEM-001` planned "3 title packs" as a progression layer on top of the existing gamification. **CS-PLAN-002 then shipped the entire stat substrate** the titles would build on — `coins`, `currentStreak`/`longestStreak`, `contributionScore`, `totalSparksEarned`, `streakFreezes`, medals, weekly goal — but never closed the title-system backlog item. The original `GAMIFICATION-CONCEPTS-001` analysis explicitly lists "Role Title Systems (3 planned packs completely missing)" as a top gap.

The current situation:
- The backend has rich progression signals (8 numeric stats per `HouseUser`, 4 medal types, weekly goal, streak freezes).
- The frontend has **no progression display** beyond raw numbers — no "title", no level-up moment, no identity reward for sustained participation. This is the single biggest "feel" gap vs the design concept.
- The 2026-03-07 backlog entry is vague on criteria, unlock rules, and which pack is active at once. Three years of project evolution (coins rename, sparks removal, monster removal) mean the original "Spark Starter → Flow Keeper → Team Anchor" labels need rewriting.

This plan delivers the title system as a **progression-overlay**: no new core loop, no new resource. It reads existing stats, computes unlocked titles server-side, exposes one "active title" per user, and renders it in profile + leaderboard. The user picks which pack to display; titles within a pack auto-unlock by stats.

## 2. Strategy

Three packs × three tiers = 9 titles. Each pack is themed around a different progression signal so players with different playstyles can find a track that matches what they actually do:

| Pack | Theme | Primary signal | Tiers |
|------|-------|----------------|-------|
| **A — Home Helpers** | Consistency / breadth | `contributionScore` (total energy spent) | Helper → Organizer → Care Captain |
| **B — Team Energy** | Streak / dependability | `longestStreak` | Spark Starter → Flow Keeper → Team Anchor |
| **C — Cozy Home** | Coins / household contribution | `totalSparksEarned` (yes, the column is renamed but the field kept its old name) | Cozy Buddy → Room Ranger → Home Guardian |

Each tier has a configurable threshold (via `RewardConfigService`, so owners can tune difficulty without a code change). The user picks **one active pack** at a time; within that pack, the highest unlocked tier is shown. Switching packs is free (not a purchase) — the title is identity, not a consumable.

Guiding principles:

1. **Server-authoritative unlocks.** The client never computes "am I eligible"; it only displays what the server returns. Unlock criteria live in one PHP class so they can be tested and tuned centrally.
2. **No new core loop.** No new resource, no new currency, no new completion path. Titles react to existing stats only — this is what keeps the plan at M instead of L.
3. **Reuse `RewardConfigService`** for thresholds — same pattern as `weekly_goal_target_percent` (default + clamp + getter/setter + `getAllConfig()`/`updateConfig()` wiring from CS-PLAN-002 Phase 3).
4. **Reuse the celebration layer** from CS-PLAN-002 Phase 2 for title-up moments: emit `chorestrike:title-unlocked` on the `@nextcloud/event-bus`; `useCelebration` already handles confetti + sound.
5. **Out of scope, explicit:** "Title transition animations" (original P7 backlog item) — keep it to a CSS fade-in via the existing motion tokens; no dedicated animation work. Title-pack *creation UI* for owners — packs are code-defined (constant array), not admin-editable. Profile-page redesign — only the title badge is added.

## 3. Backend / Frontend Changes

### Phase 1 — Schema & title catalog (S)

- **`lib/Migration/Version1025Date20260719000000.php`** (new):
  - Add `selected_title_pack VARCHAR(20) NULL` to `chorestrike_house_users` (null = no pack chosen; default).
  - Add `unlocked_titles TEXT NULL` to `chorestrike_house_users` (cache of `{pack}.{tier}` strings the user has ever unlocked — kept for the "title history" tooltip; recomputable from stats, so safe to clear). Stored as a JSON-encoded string; the entity decodes/encodes in `getUnlockedTitles()`/`setUnlockedTitles()`. Using `text` (not a native `json` column type) for portability across SQLite/MySQL/PostgreSQL via Nextcloud's DB abstraction.
  - No data back-fill (new feature, no prior state).
- **`lib/Db/HouseUser.php`**:
  - Add `protected ?string $selectedTitlePack = null;` + `protected array $unlockedTitles = [];` + `addType()` calls.
  - `jsonSerialize()`: emit `selectedTitlePack`, `unlockedTitles`, and a computed `currentTitle` (string|null) — the title object is assembled by the service, not the entity, so the entity emits only the raw state.
- **`lib/Titles/TitleCatalog.php`** (new):
  - Pure constant class — no DB. Defines `PACKS` as:
    ```php
    public const PACKS = [
        'home_helpers' => [
            'labelKey' => 'titles.pack.home_helpers',
            'signal' => 'contributionScore',
            'tiers' => [
                ['key' => 'helper',       'labelKey' => 'titles.home_helpers.helper',        'threshold' => 50],
                ['key' => 'organizer',    'labelKey' => 'titles.home_helpers.organizer',     'threshold' => 250],
                ['key' => 'care_captain', 'labelKey' => 'titles.home_helpers.care_captain',  'threshold' => 1000],
            ],
        ],
        'team_energy' => [
            'labelKey' => 'titles.pack.team_energy',
            'signal' => 'longestStreak',
            'tiers' => [
                ['key' => 'spark_starter', 'labelKey' => 'titles.team_energy.spark_starter', 'threshold' => 7],
                ['key' => 'flow_keeper',   'labelKey' => 'titles.team_energy.flow_keeper',   'threshold' => 30],
                ['key' => 'team_anchor',   'labelKey' => 'titles.team_energy.team_anchor',   'threshold' => 90],
            ],
        ],
        'cozy_home' => [
            'labelKey' => 'titles.pack.cozy_home',
            'signal' => 'totalSparksEarned',
            'tiers' => [
                ['key' => 'cozy_buddy',    'labelKey' => 'titles.cozy_home.cozy_buddy',     'threshold' => 500],
                ['key' => 'room_ranger',   'labelKey' => 'titles.cozy_home.room_ranger',    'threshold' => 2500],
                ['key' => 'home_guardian', 'labelKey' => 'titles.cozy_home.home_guardian',  'threshold' => 10000],
            ],
        ],
    ];
    ```
  - Thresholds above are *defaults*; `RewardConfigService` overrides (Phase 2) can tune them per-house.

### Phase 2 — Title service & config (M)

- **`lib/Service/TitleService.php`** (new):
  - `getCurrentTitle(HouseUser $user): ?array` — returns the highest-unlocked tier within the user's selected pack (or null if no pack selected / no tier unlocked).
  - `getUnlockedTitles(HouseUser $user): array` — returns every `{pack}.{tier}` the user has unlocked across all packs (drives the "title history" tooltip + the celebration check).
  - `selectPack(HouseUser $user, string $packKey): void` — validates `$packKey` against the catalog, sets `selectedTitlePack`, persists via mapper.
  - `detectUnlock(HouseUser $user, string $oldSignalValue, string $newSignalValue): ?array` — called from `GameLogicService::handleTaskCompletion()` after stats update; returns the newly-unlocked title (or null) so the caller can emit a notification + celebration event. Centralizes the "did I just cross a threshold?" check.
  - **Idempotent** — unlocking the same title twice is a no-op (cache hit on `unlockedTitles`).
- **`lib/Service/RewardConfigService.php`** — extend with `title_thresholds_*` keys (one per tier, 9 total), defaults matching the catalog, all clamped to `>= 0` (int). Reuse the existing `getAllConfig()`/`updateConfig()` plumbing. (Pattern is identical to the `weekly_goal_target_percent` key from CS-PLAN-002 Phase 3.)
- **`lib/Service/GameLogicService.php`** — in `handleTaskCompletion()`, after the existing stat updates (coins, streak, contribution score), call `TitleService::detectUnlock()` for the three signals. On a new unlock, dispatch `NotificationService::notifyTitleUnlocked()` (new method, best-effort) and write the new title into `$gameResult['newTitle']` so the frontend can fire the celebration.
- **`lib/Service/NotificationService.php`** — new method `notifyTitleUnlocked(HouseUser $user, array $title): void` (best-effort, mirrors the existing `notifyMedalAwarded()`). New `ChoreStrikeNotifier::prepare()` branch for subject `title_unlocked`.
- **`lib/Controller/UserController.php`** (or a new `TitleController.php` — preference: extend `UserController` to avoid route sprawl):
  - `GET /api/v1/users/{id}/titles` — returns the user's current title + all unlocked titles + the catalog (so the frontend can render the picker without a separate call).
  - `PUT /api/v1/users/{id}/titles/select` — body `{pack}`; validates + delegates to `TitleService::selectPack()`.

### Phase 3 — Frontend (M)

- **`src/store/user.js`** (or a new `src/store/titles.js` — preference: dedicated store, the user store is already large):
  - State: `currentTitle`, `unlockedTitles`, `availablePacks`.
  - Actions: `fetchTitles()`, `selectPack(packKey)`.
  - Getter: `displayTitle` → the i18n'd label for `currentTitle`, or `null`.
- **`src/components/Profile/TitleBadge.vue`** (new):
  - Renders the current title with the tier color from `tokens.css` (bronze/silver/gold from CS-PLAN-002 Phase 5a).
  - Tooltip shows the title history (`unlockedTitles`).
- **`src/components/Profile/TitlePicker.vue`** (new):
  - Modal with the 3 packs; each pack shows its 3 tiers + the user's progress bar toward the next tier (e.g. "Helper ✓ — Organizer 250/1000").
  - "Set as my title" button per pack → calls `selectPack()`.
- **`src/views/Leaderboard.vue`** — show the `displayTitle` next to each user's name (compact form: `{displayName} · {title}` or just the title under the name).
- **`src/views/Settings.vue`** (or wherever the profile lives) — embed `<TitleBadge>` next to the username + a "Change title" button that opens `<TitlePicker>`.
- **`src/composables/useCelebration.js`** — subscribe to a new `chorestrike:title-unlocked` event (mirrors the existing `chorestrike:medal-awarded` subscription); fire confetti + the "milestone" SFX (already in `src/utils/sound.js`).
- **`src/store/roomTasks.js`** — after a successful task completion, if the response includes `newTitle`, emit `chorestrike:title-unlocked`. This mirrors the existing event-emit pattern in the same `completeRoomTask` action (which already emits `chorestrike:task-completed` and `chorestrike:streak-freeze-used`). The celebration composable (`useCelebration.js`) subscribes to this event the same way it subscribes to `chorestrike:medal-awarded` (which is emitted from `src/store/game.js`).

### Phase 4 — Tests & docs (S)

- **PHPUnit:**
  - `tests/unit/Titles/TitleCatalogTest.php` — validates the catalog shape (3 packs × 3 tiers, unique keys, valid signal names, non-zero default thresholds).
  - `tests/unit/Service/TitleServiceTest.php` — covers: no pack selected → null title; pack selected but below tier-1 threshold → null title; tier-1/tier-2/tier-3 progression; `detectUnlock()` returns the right title on threshold cross + null when no cross + idempotency on re-cross.
  - `tests/unit/Service/RewardConfigServiceTest.php` — extend with the 9 new threshold keys.
  - `tests/unit/Service/GameLogicServiceTest.php` — extend with 2 cases: completion that unlocks a title fires `NotificationService::notifyTitleUnlocked()` + sets `newTitle` in the result; completion that doesn't cross a threshold fires nothing.
- **Jest:**
  - `tests/unit/store/titles.spec.js` — covers state, actions (mocked API), and the `displayTitle` getter (i18n resolution).
  - `tests/unit/components/TitleBadge.spec.js` + `TitlePicker.spec.js` — render tests + the select-pack interaction.
  - Extend `Leaderboard.spec.js` to assert the title appears next to the username.
- **Playwright:**
  - `tests/e2e/title-selection.spec.ts` — user opens picker, selects a pack, sees the badge update in settings + leaderboard.
  - (Optional, can be deferred) `tests/e2e/title-unlock.spec.ts` — requires grinding stats to cross a threshold; expensive, prefer a unit-level integration test instead.
- **l10n:** 12 new keys per language (3 pack labels + 9 tier labels). Update `en.json`, `de.json`, regenerate `chorestrike.pot`. Run `npm run l10n:check-keys`.
- **Docs:** update `docs/concepts/GAMIFICATION_AND_REWARDS.md` (mark title-system "shipped"), `docs/ARCHITECTURE.md` (new `lib/Titles/` namespace + TitleService in the service diagram), `docs/API.md` (2 new endpoints). Close the `ROLE-TITLE-SYSTEM-001` and `GAMIFICATION-CONCEPTS-001` backlog entries.

### Cross-layer contracts affected

- `HouseUser` JSON payload: **additive only** (`selectedTitlePack`, `unlockedTitles`, `currentTitle`). No existing field changes shape.
- `GameLogicService::handleTaskCompletion()` return value: **additive only** (optional `newTitle` key).
- New routes: `GET /api/v1/users/{id}/titles`, `PUT /api/v1/users/{id}/titles/select`.
- New event-bus topic: `chorestrike:title-unlocked` (mirrors the existing `chorestrike:medal-awarded`).
- New notification subject: `title_unlocked` (mirrors `medal_awarded`).

## 4. Tests

- **Unit (PHPUnit):** 4 new/extended test files (see Phase 4). Target: PHPUnit stays green at 429+/429+ (post-CS-PLAN-004) plus the new cases.
- **Integration (PHPUnit, requires CS-PLAN-004 Docker):** `tests/integration/Service/TitleServiceIntegrationTest.php` — title unlock end-to-end against a real DB (seed a user, bump stats via `GameLogicService`, assert the title is unlocked + persisted).
- **Jest:** 3 new + 1 extended spec file (see Phase 4).
- **Playwright:** 1 new E2E spec (title selection); the unlock path is covered by integration test instead (cheaper).
- **No regression:** existing PHPUnit 429+/429+ and Jest 523+/523+ must stay green.

## 5. File Manifest

```
Phase 1 (schema + catalog)
  new:      lib/Migration/Version1025Date20260719000000.php    # selected_title_pack + unlocked_titles (TEXT, JSON-encoded)
  modified: lib/Db/HouseUser.php                                # new fields + jsonSerialize
  new:      lib/Titles/TitleCatalog.php                         # constant pack/tier definitions

Phase 2 (service + config)
  new:      lib/Service/TitleService.php                        # getCurrentTitle / getUnlockedTitles / selectPack / detectUnlock
  modified: lib/Service/RewardConfigService.php                 # 9 title_threshold_* keys
  modified: lib/Service/GameLogicService.php                    # detectUnlock call after stat update
  modified: lib/Service/NotificationService.php                 # notifyTitleUnlocked()
  modified: lib/Notification/ChoreStrikeNotifier.php            # title_unlocked subject
  modified: lib/Controller/UserController.php                   # GET /titles + PUT /titles/select
  modified: appinfo/routes.php                                  # 2 new routes

Phase 3 (frontend)
  new:      src/store/titles.js                                 # Pinia store
  new:      src/components/Profile/TitleBadge.vue
  new:      src/components/Profile/TitlePicker.vue
  modified: src/views/Leaderboard.vue                           # show displayTitle
  modified: src/views/Settings.vue                              # embed TitleBadge + picker
  modified: src/composables/useCelebration.js                   # chorestrike:title-unlocked subscription
  modified: src/store/roomTasks.js                              # emit chorestrike:title-unlocked on completion
  new:      src/constants/titles.js                             # pack/tier key mirrors (for the picker UI)

Phase 4 (tests + docs)
  new:      tests/unit/Titles/TitleCatalogTest.php
  new:      tests/unit/Service/TitleServiceTest.php
  new:      tests/integration/Service/TitleServiceIntegrationTest.php
  modified: tests/unit/Service/RewardConfigServiceTest.php
  modified: tests/unit/Service/GameLogicServiceTest.php
  new:      tests/unit/store/titles.spec.js
  new:      tests/unit/components/TitleBadge.spec.js
  new:      tests/unit/components/TitlePicker.spec.js
  modified: tests/unit/views/Leaderboard.spec.js
  new:      tests/e2e/title-selection.spec.ts
  modified: l10n/en.json + l10n/de.json + l10n/templates/chorestrike.pot
  modified: docs/concepts/GAMIFICATION_AND_REWARDS.md           # mark title-system shipped
  modified: docs/ARCHITECTURE.md                                # lib/Titles/ + TitleService
  modified: docs/API.md                                         # 2 new endpoints
  modified: docs/backlog/ACTIVE_TASKS.md                        # close ROLE-TITLE-SYSTEM-001 + GAMIFICATION-CONCEPTS-001
```

## 6. Implementation Order

1. **Phase 1 — Schema & catalog.** Single commit. Verify migration runs clean in Docker (from CS-PLAN-004). Catalog is pure constants — no test interaction yet.
2. **Phase 2 — Service + config + controller.** Single commit. PHPUnit unit suites green. The `GameLogicService` change is the riskiest part — keep the `detectUnlock()` call behind a feature flag (`RewardConfigService::titlesEnabled()`, default true) so it can be disabled without a redeploy if the celebration spam is too aggressive.
3. **Phase 3 — Frontend.** Single commit. Jest green. The title picker is the largest new component — build it against the catalog constants, not against hardcoded strings, so a catalog change in Phase 1 doesn't require a frontend rewrite.
4. **Phase 4 — Tests & docs.** Integration test + E2E + l10n + docs. Single commit. Close the two backlog entries.

Each phase leaves the build gate green.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Threshold defaults (50/250/1000 etc.) are wrong for real households and titles unlock either instantly or never. | High | Defaults are explicitly *guesses*; expose them via `RewardConfigService` so the first beta households can tune without a code change. Add a one-line note in `GAMIFICATION_AND_REWARDS.md` documenting how to tune. |
| `detectUnlock()` fires on every completion and re-awards the same title, spamming notifications. | Medium | `unlockedTitles` cache is checked first; `detectUnlock()` returns null if the title is already in the cache. Idempotency is unit-tested. |
| Adding columns to `chorestrike_house_users` collides with an in-flight CS-PLAN-005 migration on the same table. | Low | CS-PLAN-005 modifies `chorestrike_tasks`; this plan modifies `chorestrike_house_users`. No collision. Migration version numbers are sequenced (`Version1024` for 005, `Version1025` for 006). |
| The "title history" tooltip shows stale unlocked titles after a stat reset (house reset). | Low | `TitleService::getUnlockedTitles()` is recomputed from current stats on every read; the `unlocked_titles` column is a denormalized cache, not a source of truth. Reset clears both. |
| Pack labels feel off (e.g. "Spark Starter" after the Sparks rename in CS-PLAN-002). | Medium | Pack B's first tier is renamed in the catalog — original was "Spark Starter", this plan uses the same label *key* (`titles.team_energy.spark_starter`) but the i18n value can be re-translated without a code change. German translations are fresh anyway. |
| Title-picker modal is unreachable for child users (role-based access). | Low | The picker is available to all roles — titles are identity, not admin. Only `selectPack` mutates state; the GET is read-only. No `requireHouseOwner()` on these endpoints. |
| `GameLogicService` constructor signature grows (new `TitleService` dependency) and breaks the 38 existing unit tests. | Medium | Inject `TitleService` as `?TitleService $titleService = null` (same pattern as `?AppLogger` elsewhere); the production container wires it, tests that don't care about titles get null. |
| The `chorestrike:title-unlocked` event fires in jsdom during unrelated Jest tests and breaks them. | Low | `useCelebration` already mocks cleanly in jsdom (CS-PLAN-002 Phase 2 set this up); add a test-only `setCelebrationEnabled(false)` if any spec breaks. |
| Phase 4 integration test can't run because CS-PLAN-004 Docker repair is incomplete. | Medium | This plan **depends on CS-PLAN-004**; Phase 1–3 (schema + backend unit + frontend Jest) can proceed, Phase 4 integration is blocked. |

## 8. Acceptance Criteria

- [ ] `lib/Migration/Version1025Date20260719000000.php` adds `selected_title_pack` (VARCHAR) + `unlocked_titles` (TEXT, JSON-encoded in entity) to `chorestrike_house_users` and runs clean against empty + seeded DBs.
- [ ] `lib/Titles/TitleCatalog.php` defines 3 packs × 3 tiers with unique keys, valid signal names (`contributionScore`/`longestStreak`/`totalSparksEarned`), and non-zero default thresholds.
- [ ] `TitleService::getCurrentTitle()` returns the highest unlocked tier in the user's selected pack (or null); unit-tested for all 4 branches (no pack, pack but no tier, tier 1, tier 3).
- [ ] `TitleService::detectUnlock()` is idempotent (re-crossing a threshold returns null the second time) and unit-tested.
- [ ] `RewardConfigService` exposes 9 `title_threshold_*` keys with working getters/setters + `getAllConfig()`/`updateConfig()` integration.
- [ ] `GameLogicService::handleTaskCompletion()` calls `detectUnlock()` after stat updates and dispatches `notifyTitleUnlocked()` + sets `newTitle` in the result on a real unlock; behind a `titlesEnabled` feature flag.
- [ ] New routes `GET /api/v1/users/{id}/titles` + `PUT /api/v1/users/{id}/titles/select` work end-to-end; `selectPack` validates against the catalog.
- [ ] Frontend `TitleBadge` renders the current title with tier color; `TitlePicker` shows all 3 packs with progress bars; both are Jest-tested.
- [ ] `Leaderboard.vue` shows the display title next to each username.
- [ ] `useCelebration` fires on `chorestrike:title-unlocked` (confetti + milestone SFX); honours `prefers-reduced-motion`.
- [ ] 12 new l10n keys (3 pack + 9 tier labels) in `en.json`, `de.json`, regenerated `chorestrike.pot`; `npm run l10n:check-keys` passes.
- [ ] `docs/concepts/GAMIFICATION_AND_REWARDS.md` marks the title system shipped; `docs/ARCHITECTURE.md` shows `lib/Titles/` + `TitleService`; `docs/API.md` documents the 2 new endpoints.
- [ ] `docs/backlog/ACTIVE_TASKS.md` closes `ROLE-TITLE-SYSTEM-001` and `GAMIFICATION-CONCEPTS-001` (the parts this plan delivers).
- [ ] Build gate green: `npm test` + `npm run lint` + `vendor\bin\phpunit.bat --configuration phpunit.unit.xml` (429+/429+) + integration suite (after CS-PLAN-004) + new E2E spec.
- [ ] No raw exception messages in API responses; no `console.*` in production code; every new user-visible string uses `t()`.
