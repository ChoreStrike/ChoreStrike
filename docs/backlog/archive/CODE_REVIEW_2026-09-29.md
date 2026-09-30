# Code Review Archive — 2026-09-29

## CS-PLAN-075 / WP4 — Final Standard B: PASS-WITH-FIXES

- **Review Date:** 2026-09-29
- **Tier:** Standard B (final-review attempt), no subagents
- **Mode:** Full-package final review (changed-file set + §5 manifest + §8
  acceptance criteria + §4 test matrix + durable-doc gate + implementation
  history)
- **Outcome:** `PASS-WITH-FIXES` (all findings resolved in the in-review fix
  round; gates re-run green)
- **Owning Plan:** [CS-PLAN-075](../../plans/done/cs-plan-075-wp4-rhythm-goals-and-ranking.md)
  (moved `active/` → `done/` with this review)
- **Base:** `master` at `a477c292fcf3122fca96e590e29869b54d34cf23`

## Capture

- Branch `master`; index **empty** at capture (staged diff hash
  `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391`).
- Pre-review unstaged binary diff (`git diff --output` + `git hash-object`)
  SHA1 **`cd204cafba4e2eba303180a3bf68aff1c812a9ff`** — 103 tracked paths
  (102 M / 1 D `src/components/Profile/TitlePicker.vue`).
- Pre-review untracked (11) SHA1s:
  `b9d59a59` ADR-015, `37997959` RankingController.php,
  `d3923b69` MemberPreference.php, `27fe45ec` MemberPreferenceMapper.php,
  `a847eb68` Version1032Date20260918000000.php, `fe69f434`
  RankingService.php, `717fa18e` src/store/ranking.js, `be4fae97`
  tests/e2e/ranking-consent.spec.ts, `792b5cfa`
  tests/integration/Service/RankingConsentTest.php, `c1070c1f`
  tests/unit/Controller/RankingControllerTest.php, `2d7fb5e9`
  tests/unit/Service/RankingServiceTest.php.
- Post-fix state (fix round + finalization edits): unstaged diff SHA1
  **`2439c28c6179b8ac3e21d7c977885996849bf228`**; 13 untracked paths (the
  11 above with RankingService.php now `62e194da`, RankingServiceTest.php
  now `abd95ac2`, plus `3c40f60e` the moved plan in `done/` and `00798d1b`
  the new tests/unit/store/ranking.spec.js).
- Pre-existing `.gitkeep` deletions in `docs/plans/{new,active,onhold}/`
  preserved unstaged per the plan's §5 instruction.

## Verified

- **Backend ranking slice:** thin `RankingController` (`@NoAdminRequired`,
  membership gate, `ControllerErrorFormatter::safeMessage` fallback,
  structured exception logging); `RankingService` with house-first lock
  order for consent/withdraw, monotonic generation watermark on the locked
  house row, exact cross-multiplied ranking with standard competition ties,
  neutral zero denominators, consent-filtered facts computed server-side
  only (non-consenting members and private-task facts never enter the
  payload); `MemberPreference`/`MemberPreferenceMapper` fully
  parameterized with unique `(house, membership)` identity and
  `insertOrGet` race handling; `Version1032` creates `member_prefs`
  (consent columns only) + the house watermark column (Q-075-01 A exact).
- **Read contract:** `state()` carries the rhythm projection
  (confirmed/current/best/pending via `evaluateForMembers`) and the personal
  `myWeeklyGoal` slice; house `weeklyGoal` gains
  eligible/onTime/neutral/provisional; GET purity pinned for **both**
  `state()` and `medals()` (`GameControllerIntegrationTest`, medal-count
  proof on an initialized house).
- **Lifecycle sweeps:** departure deletes consent inside the member-removal
  transaction; erasure purges it; disable wipes every generation via
  `updateHouse` (single house-update authority); re-enabling starts
  unconsented — integration-proven through the real service paths.
- **Recognition cutover (Q-075-02 A):** single `family_rhythm` series on
  `longestStreak` (valid-history maximum, D7c), fixed 3/6/12 thresholds,
  `selected_title_pack`/`selectPack`/`TitlePicker.vue` retired with
  Version1025 amended in place, retired medal labels/icons gone from
  `Medal.php` + `MedalBadge.vue`, `RewardConfigService` reduced to
  energy-max + titles flag (all threshold/target overrides deleted).
- **Retirements:** `Task` tier/multiplier fields, writes, closed set and
  columns (Version1008/1027 fresh-install chain),
  `calculateStreakWithAbsence`, freeze/combo handlers/sounds/indicators,
  multiplier wording, stale comments, info.xml marketing — each with
  negative pins (RouteMatrix retired-route/ranking-trio test,
  FreshInstallMigrationSmokeTest column absence set, E2E absence pins).
- **Client cutover:** `getLeaderboard` contribution getter deleted;
  `Leaderboard.vue` renders the server view (opt-in/closed state,
  participation panel, ties/neutral/provisional, pending) with all-new
  `t()` strings; `ranking` store with identity-switch reset; l10n EN/DE/POT
  canonical (1005 keys, localizationGate idempotent-regeneration green).
- **Durable docs:** ADR-015 (consent contract), ADR-004 annotation,
  api/README (ranking trio, `PUT /house` flag, state contract, pruned
  select-title), engineering size inventory re-measured (RankingService 437
  added, RewardConfigService 164 removed from inventory, TitlePicker
  retired), testing E2E map, concepts + gamification skill, release-test-plan
  §8, docs/README index, CR-053-11 annotated without closure.

## Findings and in-review fixes (RF-075-01..05, all resolved)

| ID | Severity | File/area | Issue | Resolution |
|---|---|---|---|---|
| RF-075-01 | major | `src/views/Settings.vue`, `Leaderboard.vue:23`, `ranking-consent.spec.ts:22` | No owner UI for `rankingEnabled` existed — the disabled-state copy and the E2E comment promised "the settings", but ranking was enableable only via direct API call | Minimal owner-only toggle in the Settings house card (`ranking-toggle`/`save-house-ranking`) via new `houseStore.updateRankingEnabled()` on the shared `PUT /house` path + EN/DE/POT l10n + house/settings store+view specs (user-selected recommended option) |
| RF-075-02 | major | `tests/unit/Service/AccountErasureServiceTest.php`, `tests/unit/store/` | §4-required evidence missing: erasure consent purge had no pin (mock with zero expectations, no same-UID non-rebinding proof); new `ranking` store had no direct spec (view spec fully mocks it) | N `testCurrentMemberErasure_PurgesRankingConsentByMembershipId` (purge keyed by membership id, never the UID — recreated same-UID accounts get a fresh membership and cannot rebind); N `tests/unit/store/ranking.spec.js` (12 tests: view apply, consent/withdraw refresh, error paths, reset) |
| RF-075-03 | minor | `lib/Service/RankingService.php:199,207` | Dead public wrappers `handleMembershipRemoved`/`purgeForErasure` — zero production callers (HouseService/AccountErasureService use the mapper inside their transactions); only wrapper-only unit tests called them | Both methods and the two wrapper tests deleted; real-path coverage stays in `RankingConsentTest` (departure/disable) and the new erasure pin |
| RF-075-04 | minor | `RhythmService.php:246`, `WeeklyGoalService.php:115,204`, `RankingService.php:462` | Valid-CLASS_AGREED on-time predicate duplicated 4× (rule of three exceeded) | Extracted to `CompletedTask::isAgreedOnTime(?start, ?end)` (half-open window, behavior-neutral); all four call sites de-duplicated, RankingService private helper deleted, orphaned import removed |
| RF-075-05 | nit | `docs/standards/ai/skills/docker-dev/SKILL.md` | Stale family-profile note still documented the retired 1%-target appconfig seed (CS-PLAN-055 era) | Rewritten to the current `seedClosedWeeklyGoal` deterministic closed-week behavior |

## Batched decisions (structured question, recommendation selected each)

1. **RF-075-01:** add the minimal owner toggle now (vs. reword copy and defer
   to WP6) — **toggle added**.
2. **RF-075-02:** deliver both pins in the fix round (vs. park either) —
   **both delivered**.
3. **RF-075-03/04/05:** fix all three in-round (vs. partial) — **all fixed**.
4. **E2E evidence:** accept the mtime-verified gate run `run-NSOUkr`
   (2026-09-27; all three pools — chromium 91, chromium-serial-db 126,
   chromium-scheduling-owner 39 — `processExit 0`, `violations: []`, zero
   failed/flaky/skipped, `ranking-consent` lifecycle in the serial pool;
   newest reviewed-scope mtime 22:19:19 predates the pool runs) —
   **accepted**. The fix round postdates the run; its changes are
   unit/integration-covered and behavior-neutral on every E2E-asserted
   surface (new Settings section no E2E asserts, additive store action,
   predicate extraction, dead-code removal, l10n additions).

## Gates (re-run by this review on the post-fix tree)

- Docker PHPUnit **unit 982 tests / 3125 assertions** and **integration 475
  tests / 2468 assertions**, both `--fail-on-skipped`, zero skips.
- `composer cs:check`: 0 of 365 files fixable (one unused-import violation
  found and fixed in-round).
- Jest **1049 passed / 96 suites** (`--runInBand`) incl. the new
  ranking-store spec and expanded house/Settings specs; `npm run
  test:noise` green.
- `npm run lint` (src + e2e TypeScript) 0 problems (14 indentation errors
  found around the Settings house card and fixed via scoped `eslint --fix`);
  stylelint clean.
- `npm run l10n:check-keys`: **1005 keys**, POT/EN/DE exact coverage and
  placeholders, canonical regeneration (localizationGate idempotency green
  after restoring the German translations through the canonical `--write`
  path).
- `npm run backlog:check` (28 files), `npm run docs:encoding` (792 files),
  `git diff --check` clean; webpack production build green.

## Outcome and transition

`PASS-WITH-FIXES`. All §8 acceptance criteria verified and checked;
§9 Review Findings RF-075-01..05 recorded as resolved in the owning plan
(now in `done/`); CR-053-11 remains open for its independent Snake ordering
remainder with the WP4 portions annotated resolved; CONCEPT-001 remains In
Progress via the parent for WP5+; parent child-pointer, `docs/plans/README.md`,
`docs/README.md`, `backlog.md` links and `ACTIVE_TASKS.md` updated. The
complete WP4 implementation package (round 1 + the in-review fix round) lands
with this review's scoped commit; unrelated pre-existing `.gitkeep` deletions
left unstaged.

## CS-PLAN-076 / WP5 — Standard A: PASS-WITH-FIXES

- **Date / tier / mode:** 2026-09-29; Standard A, Deep Slice plan review; no subagents.
- **Scope / owner:** [CS-PLAN-076](../../plans/done/cs-plan-076-wp5-preferences-and-digest-delivery.md), child of active CS-PLAN-070. Reviewed plan feasibility, parent C5/D9/D10 ownership, code-baseline citations, risks, tests, manifest and backlog links. No runtime changes.
- **Capture:** `master` HEAD `b5cc55fa2e768265d01ee646e85c93a8e88536a9`; index empty (staged binary diff SHA1 `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391`), unstaged binary diff SHA1 `871015765dd7c7c9e64c680f41a6eebc370009aa`; sole untracked plan SHA1 `99550fee377f9927ba67f5e12854d980b9814b7e`. Draft scope: this plan plus five draft tracking/link documents (`docs/README.md`, `docs/plans/README.md`, `docs/backlog/{ACTIVE_TASKS,backlog}.md`, parent CS-PLAN-070); three unrelated `.gitkeep` deletions excluded.

| Finding | Severity | Evidence | Resolution |
|---|---|---|---|
| RF-076-01 | major | `RankingService.php:156-169` deletes the shared row during consent/re-consent; `Version1032Date20260918000000.php:65-67` requires ranking Monday/zone, making a preference-only row impossible. Draft §3.1 addressed withdraw/disable only. | Q-076-03 A includes in-place consent/re-consent, nullable inactive Monday/zone, generation 0, ranking-filtered reads and shared revision increments; lifecycle/conflict tests added to §4. Plan-level fixed; runtime pending. |
| RF-076-02 | major | `CompletionService.php:230-240` invokes `CompletionPostCommitService.php:62,123-143` after commit; draft §3.3 assigned it a transactional queue write. | User selected queue insertion in the completion command transaction; §3/§4/manifest require rollback/replay pins; post-commit bell removed. Plan-level fixed; runtime pending. |

**Batched decisions:** Q-076-01 A (minimal self-only Settings UI), Q-076-02 A (awards only in-app, remove bells), Q-076-03 A (shared-row in-place lifecycle including consent/re-consent), Q-076-04 A (new ADR-016) and RF-076-02 A (transactional takeover queue) were explicitly selected together through the structured question tool and recorded in plan §10. No unanswered decisions or open plan-design findings remain.

**Validation:** `npm run backlog:check` green (29 scoped files, after staging the newly tracked plan); `npm run docs:encoding` green (804 tracked files); `git diff --cached --check` and `git diff --check` green. No runtime code changed; runtime tests remain implementation obligations in plan §4, not claimed passing in this review. Outcome `PASS-WITH-FIXES`: child moved `new/` → `active/`, parent/indices/backlog/tracking updated, FEAT-UX-024 WP5 slice In Progress, CONCEPT-001 and FEAT-UX-025 still open. Unrelated `.gitkeep` deletions excluded from the scoped commit.
