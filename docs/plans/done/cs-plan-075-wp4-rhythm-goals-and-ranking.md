# CS-PLAN-075: WP4 - Rhythm, Goals and Ranking

> **Status:** Done (final Standard B `PASS-WITH-FIXES` 2026-09-29, fix round
> in-review; implementation round 1 complete 2026-09-27) — Standard A
> `PASS-WITH-FIXES` 2026-09-27
> **Effort:** M/L; 5-9 engineering days (4-7 WP4 allowance plus 1-2 for consent)
> **Priority:** P1
> **Depends on:** [WP0 / CS-PLAN-071](../done/cs-plan-071-wp0-write-identity-foundation.md),
> [WP1 / CS-PLAN-072](../done/cs-plan-072-wp1-occurrence-timeline.md),
> [WP2 / CS-PLAN-073](../done/cs-plan-073-wp2-agreements-capacity-pauses.md) and
> [WP3 / CS-PLAN-074](../done/cs-plan-074-wp3-work-accounting-and-replay.md), delivered.
> The parent's WP5-first sequencing is superseded by Q-075-01 Option A.
> **Related backlog items:** CONCEPT-001 via
> [CS-PLAN-070](../done/cs-plan-070-family-rhythm-and-reward-clarification.md);
> CR-053-11 (freeze-removal and leaderboard-tiebreaker remainders)
> **Planning baseline:** `b3ff6da` (WP3 final review commit), 2026-09-27

This is the implementation sub-plan for parent WP4. The parent D3/D5/D7/D10,
C1f, C2g, C3d/C3g and C5i/C5l rules remain authoritative. Section 10 records
the Standard A decisions; the engineering proposals below are not additional
product choices beyond the parent's approvals.

## Implementation Round 1 (2026-09-27)

Delivered against the §5 manifest with these recorded deviations (all
Q-075-consistent, to be verified by the Standard B review):

- **Generation watermark** (Q-075-01 A refinement): consent rows are
  deletable, so the monotonic per-house generation counter lives on the
  house row (`houses.ranking_generation_seq`, added in the same
  `Version1032` amendment, allocated under the house lock) instead of a
  max-row heuristic — a withdrawn generation can never be reused.
- **Nextcloud Entity setter semantics**: `MemberPreference` defaults are
  neutral (`version 0`, `generation 0`, `savedZone ''`) because the base
  Entity only marks a field updated when the value CHANGES — realistic
  defaults would silently drop columns from the INSERT.
- **`ranking-consent.spec.ts` is the new serial-pool E2E** (owner enables,
  member joins pending, withdraws) because the read-only pool's invited
  admin cannot flip the owner flag and the gate rejects runtime skips;
  `leaderboard.spec.ts` pins the disabled/opt-in surface.
- **`family-rhythm.spec.ts` gained the WP4 read-contract pin**: after a
  receipt-contract completion, `GET /game/state` carries the rhythm
  projection (confirmed == current, `pending` true — the just-fulfilled
  occurrence's window is still open, so the day stays pending-neutral per
  C1f) and the personal weekly slice (onTime counts the valid work, the
  week stays provisional); the house block pins the fixed 80% target.
- **`fast-core-tests.spec.ts` "Settings page loads"** still asserted the
  retired `change-title-button` — replaced with the absence pin
  (`toHaveCount(0)`), consistent with `title-selection.spec.ts`.
- **Environment note (not a code change):** the test container was
  recreated without `TEST_DB_PROFILE=family`, so the entrypoint's family
  user set was missing/lowercase-named and the gate's auth setup failed
  with mass skips; users were recreated with the canonical
  passwords/display names per `docker/README.md` and the gate re-run —
  no production code involved.
- **HouseService::updateHouse carries the flag** (single house-update
  authority incl. the consent wipe on disable) instead of a separate
  RankingService mutation; the owner gate stays in the controller and
  service.
- **CompletionUndoStateEquality** C3g proof restructured: titles derive
  from real valid history, so the seeded stale marker reconciles at the
  completion (not the undo); the undo snapshot-equality excludes exactly
  the re-derived rhythm facts + reconciled marker.

Gates at round end (all green): Docker PHPUnit unit 983 OK / integration
475 OK (zero skip, incl. N `RankingConsentTest`), Jest 1032 passed,
`npm run lint` 0 problems + stylelint + php-cs-fixer + l10n (1000 keys)
+ `docs:encoding` + `backlog:check` + `git diff --check` + webpack build,
and the full `run-e2e-gate` — all three pools (chromium /
chromium-serial-db / chromium-scheduling-owner) PASSED with zero
skipped/failed/flaky required scenarios. Durable docs: ADR-015,
ADR-004 annotation, api/README (ranking trio, titles, state contract,
`PUT /house` flag), release-test-plan §8, ui-use-cases UC-11,
gamification skill §4/5/7/8, architecture.md size inventory
(`RewardConfigService` 328→164 below the guideline, `TitlePicker`
retired, N `RankingService` 437 accepted, `HouseService` 489→512,
`Settings.vue` 928→906, `Leaderboard.vue` 496) + store tree, testing.md
E2E map, docs/README.md index, backlog CR-053-11 disposition.

## 1. Problem Summary

WP3 delivered the Q-074-01 Option A correction-closure slice: a minimum
valid-history rhythm evaluator (`RhythmService`), the due-occurrence weekly
goal (`WeeklyGoalService`), retained rhythm recognition
(`MedalService`/`TitleCatalog` 3/6/12 + weekly milestone) and the coupled
legacy-effect retirement (freeze/restock/at-risk services and jobs, snapshot
undo). What remains is the WP4 full build-out: the read/award contract around
those evaluators, consent-filtered ranking, and the removal of every retired
mechanic that still has live consumers or misleading surfaces.

| Area | Baseline (verified 2026-09-27 at `b3ff6da`) | Required change |
|---|---|---|
| Rhythm projection | `RhythmService.php:48-243` re-derives current/longest streaks from valid history (C1f hold/new-run in `confirmDay` :223-235); persists only the streak pair on `HouseUser`; called from `TimelineJob`, the completion transaction and `MedalService`. | Expose the confirmed/pending rhythm state the parent §3.4 read contract promises (confirmed/current/best rhythm, pending indicator); keep all minting out of GET reads. |
| Weekly goal | `WeeklyGoalService.php:54-126` counts due occurrences in the planned week (not distinct task IDs); `HouseService.php:519-533` is a thin delegate; `weekly_goal_receipts` claims one household bell per closed week via `MedalService.php:135-185`. RF-070-06's distinct-task computation no longer exists. | Expose eligible/onTime/neutral counts and provisional labeling per parent §3.4; pin realistic 80% scenarios (the test-seeded 1% target is retired, below). |
| Recognition catalog | `MedalService.php:39-46` keeps `rhythm_3/6/12` + `weekly_milestone` with corrected reconciliation — conformant. `TitleCatalog.php:62-93` still ships three packs: `home_helpers` (signal `contributionMinor`, thresholds 5000/25000/100000) and `team_energy` (signal `longestStreak` 7/30/90) contradict C3d ("no separate effort/wallet title series"); `family_rhythm` qualifies on `currentStreak` (:82-92), so an ordinary later streak break strips an earned title — contradicting D7c retention (medals correctly qualify on `longestStreak`, `MedalService.php:98-108`). Thresholds are config-overridable (`TitleCatalog.php:26-28`, `RewardConfigService.php:215-248`). | One retained 3/6/12 rhythm series qualifying on the valid-history maximum (`longestStreak`); fixed thresholds; retire the two extra packs and their config keys. |
| Title storage | `Version1025:50-62` owns `selected_title_pack` + `unlocked_titles`; `TitleService.php:103-112` `selectPack`, `:129-138` `detectUnlock` celebration cache; routes `user#titles`/`user#selectTitle` live (`appinfo/routes.php:103-104`); `UserController.php:369-370,438`; `TitlePicker.vue` (431 lines) mounted in `Settings.vue:317,357`. | Parent assigns the Version1025 pack-storage retirement to WP4 once consumers are replaced; the surface transition is Q-075-02. |
| Ranking | None: no `RankingService`/`RankingController`/route. `src/views/Leaderboard.vue:203-218` ranks **all** members client-side by `contributionMinor` (`src/store/user.js:53-56`) — an unconditional all-member raw-contribution ranking live today, contradicting D10b/C5i. `houses.ranking_enabled` exists frozen false (`House.php:79-83`, `Version1000:45-46`). No consent persistence exists (`member_prefs` is the WP5-reserved `Version1032`). | Consent-filtered ranking per C5i/C5l on real persisted consent — Q-075-01 settles where that consent lives and when. The unconditional contribution ranking is cut over in this package. |
| Retired-mechanic leftovers | `Task.php:126,189,285-297` still declares `taskTier`/`teamSparkMultiplier`; `TaskService.php:51,188-268` and `TaskController.php:145-161` still run the owner-only tier writes (obsolete Coin-multiplier authority, RF-070-07 residue); columns owned by `Version1008:90-91`/`Version1027:48-56`. `Medal.php:71-80` still labels retired medal keys. `HouseUser.php:154` `calculateStreakWithAbsence` keeps the RF-070-01 availability-streak helper alive outside the rhythm evaluator. Frontend: `useToasts.js:35-42,56-60` + `useCelebration.js:33-35,59-61` freeze/combo handlers with live tests, `sound.js:13` combo sound, `tokens.css:18` multiplier wording, `Leaderboard.vue:94,244-246,493-502` freeze indicators, `MedalBadge.vue:22-31` retired labels, `info.xml:13-16` stale Coins/combos/streak-protection/leaderboards marketing. Stale comments: `HouseUserMapper.php:271-272`, `Wallet.php:22`, `TaskDueReminderService.php:22`. | Remove every retired-mechanic consumer/field/label with its tests in the same change; never leave a live control or promise for a mechanic that no longer exists. |
| Reward config | `RewardConfigService.php:45-49` registry retains `weekly_goal_target_percent` and dynamic `title_threshold_{pack}_{tier}`; `TestDataService.php:44-59` seeds a 1% weekly target. | Parent: the 10/80/3-6-12 first-slice policy is fixed, not an owner setting; remove the obsolete overrides and test realistic 80% scenarios. |

Durable requirements: [C3 accounting/recognition](../../concepts/gamification-and-rewards.md#approved-accounting-contract-c3-2026-09-17),
[C5 delivery/consent](../../concepts/task-model-and-sync.md#approved-delivery-contract-c5-2026-09-17),
[calm UX/evidence](../../concepts/ui-use-cases.md#approved-calm-ux-and-evidence-c5-2026-09-17),
[ADR-013](../../adr/ADR-013-occurrence-agreements-and-corrections.md) and
[ADR-014](../../adr/ADR-014-work-accounting-ledger-and-replay.md).
The parent's WP4 manifest row predates WP3: `RhythmService`/`WeeklyGoalService`
are existing files here (M), and its `Version1023` removal entry is stale —
`Version1022` is already a no-op placeholder (:32-38) and `Version1023` is the
**live** weekly-goal receipts table (:30-38) consumed by `MedalService`; neither
is removed. The parent annotation ships with the drafting-round pointer update.

## 2. Strategy

Extend the WP3 valid-history evaluators; never reintroduce snapshot,
availability-based or read-triggered award math. Ranking ships only on real
persisted consent (Q-075-01) — no mock contract, no client-side-only flag.
Each retirement lands together with its consumers, tests, schema chain and
l10n keys in one change; no compatibility aliases or dual authorities.

| Ownership | Deliverable / boundary |
|---|---|
| WP4 / this plan | Rhythm/goal read contract and pending exposure; consent-filtered ranking backend + minimal client cutover of the unconditional leaderboard; recognition single-series cutover and pack-storage retirement; retired-mechanic removal (tier fields, freeze/combo leftovers, config overrides, stale marketing); T5 remainder pins, T8 ranking backend portion, T0 schema/erasure growth, WP4-owned browser replacements. |
| WP3 / delivered | Actual shares, wallet ledger, targeted correction, room replay, minimum rhythm/weekly/recognition evaluators and their T5 core. Reuse them; do not reopen Q-074-01/02 or rebuild the evaluators. |
| WP5 / later | Digest delivery, quiet hours, task/org notification opt-ins, sound/motion preferences and the non-ranking `member_prefs` columns. WP4 touches none of the digest machinery; Q-075-01 A creates `member_prefs` early, and WP5 amends it in place (fresh-install chain policy, Version1030 precedent). |
| WP6 / later | Full My Day/calm-UX redesign, accessible ranking presentation polish and optional-area work. WP4 delivers only the minimal client changes needed to stop contradicting D10b/C5i/C3d today; no visual redesign. |
| WP7-9 / later | Catalog/redemption, combined cross-DB gates, family evidence. The frozen `catalog_enabled` flag stays untouched. |

CONCEPT-001 owns the work. CR-053-11's freeze/restock correctness remainders
(b/c/d) are resolved here by removal-with-replacement per its 2026-09-16
family-rhythm annotation — the services/jobs already retired in WP3, this
package removes the remaining consumers/columns and pins the replacement
coverage — and its leaderboard-tiebreaker remainder (e) is addressed for the
chore ranking by exact-ratio ties (C5i); the independent Snake leaderboard
ordering remainder stays open with CR-053-11, which is **not** closed by this
plan. Parent findings RF-070-01/05/06/09/15 rhythm/recognition portions are
addressed here, not automatically closed in their entirety; RF-070-03 stays
parent-owned outside this package.

## 3. Backend / Frontend Changes

### 3.1 Rhythm and Goal Read Contract

- `RhythmService` stays the single evaluator. Extend its per-member result
  with the pending-hold indicator already tracked in `confirmDay`
  (`RhythmService.php:223-235`) so reads can show "confirmed streak, day
  pending" without re-implementing C1f. Persist or derive the indicator in one
  place only; the completion transaction and `TimelineJob` remain the writers.
- `GameController::state()` (`:63-97`) gains the parent §3.4 fields on the
  caller's own entry: confirmed/current/best rhythm, pending indicator, and
  the current week's eligible/onTime/neutral counts with its provisional flag
  (from `WeeklyGoalService`, personal slice computed from the same occurrence
  facts — never a second recurrence implementation). The existing house
  `weeklyGoal` block gains eligible/onTime/neutral/provisional fields.
  Foreign members keep exposing only streak pair + title, no wallet.
- **GET purity:** on an already initialized house, `state()` and `medals()`
  perform no award writes (verified baseline: award minting lives in
  `TimelineJob` closed-week reconciliation
  and the explicit POST `check-medals`). Pin this with a test asserting zero
  writes on both GETs; do not move minting into any read to make a UI panel
  fresher.
- `TitleService::enrichWithCurrentTitle` continues to feed user list/game
  state; its source changes per §3.3.

### 3.2 Consent-Filtered Ranking (Q-075-01 Option A)

New `RankingService` + `RankingController`; routes under
`/apps/chorestrike/api/v1`:

| Route | Contract |
|---|---|
| `GET /ranking` | House flag state, the caller's consent state (none/pending/effective week), and the visible entries for the current house week: consented members only, each with on-time ratio of personal agreed occurrences on household-public tasks (public-at-agreement flag), exact rational comparison, equal ratios tied, zero-denominator members shown neutral/unranked, week labeled provisional while it or any window is open. Consented-but-not-yet-effective members appear as pending without rank (C5l). No Coins, speed, contribution or private-task data. |
| `POST /ranking/consent` | The current person only — including children (C5i: children may decline or leave themselves); creates a new consent generation effective next Monday 00:00 in the house timezone, saved with that zone. Never retroactive. |
| `POST /ranking/withdraw` | The current person only; removes them from the visible ranking immediately and invalidates the generation. Re-consent starts a fresh generation at the next full week. |
| House settings (existing route) | Owner-only `rankingEnabled` flag on the existing house update path; disabling removes all visible participation immediately and invalidates every consent generation; re-enabling starts everyone unconsented. Default stays off; `catalog_enabled` is not touched (WP7). |

- Score facts come from the same occurrence/completion/selected-share reads as
  rhythm/goal evaluation (obligated, active-disposition, non-suspended,
  planned in the current Monday-Sunday house week, valid `CLASS_AGREED`
  on-time completion covering the member). Compare ratios by
  cross-multiplication; no floats.
- Departure deletes/invalidates consent with the membership (parent §3.1:
  consent belongs to active membership); rejoin starts quiet/unconsented
  (C5h). Account erasure purges consent rows (Q-070-02). Role changes never
  gate personal consent.
- Create `member_prefs` via the reserved `Version1032` with only the
  ranking-consent columns (unique active-membership reference, version,
  consent generation, effective Monday date, saved zone, timestamps); WP5
  amends it in place for digest / quiet / sound columns (Q-075-01 Option A).

### 3.3 Recognition Cutover (C3d/D7c, Q-075-02 Option A)

- `TitleCatalog` shrinks to the single retained `family_rhythm` series at
  3/6/12. Its qualification signal becomes `longestStreak` — the
  valid-history maximum maintained by `RhythmService` — so an earned title
  survives ordinary later streak breaks (D7c) and shrinks only when a genuine
  correction lowers the valid-history maximum (C3g), exactly like the medals.
  Fixed thresholds; the `title_threshold_{pack}_{tier}` config keys and
  `RewardConfigService::getTitleThreshold` are removed with their consumers.
- Retire `home_helpers` and `team_energy` (effort/wallet-series titles are
  explicitly not part of the first slice, C3d) with all labels, l10n keys,
  tests and DTO branches in the same change.
- `Medal.php:71-80` drops the retired `team_anchor`/`flow_keeper`/
  `room_guardian`/`team_player`/`streak_keeper` labels; `MedalBadge.vue:22-31`
  follows. Existing tombstoned rows stay hidden (`MedalService.php:278-280`).
- Q-075-02 Option A retires `selected_title_pack`, `selectPack` and
  `TitlePicker.vue` now — `currentTitle` is the highest qualifying retained
  tier — and keeps a slimmed single-series unlocked-tier marker on
  `HouseUser` as the celebration/no-repeat evidence (C3g), with
  `Version1025` amended in place.

### 3.4 Retired-Mechanic Consumer Cutover

- Remove `Task.taskTier`/`teamSparkMultiplier` (entity :126,189,285-297),
  the `TASK_TIERS` closed set and tier writes (`TaskService.php:51,188-268`,
  `TaskController.php:145-161`) and the columns from `Version1008:90-91` /
  `Version1027:48-56` in the fresh-install chain; fixtures, DTOs and tests
  move in the same change. No replacement field: total effort lives on the
  occurrence, payout on the frozen completion rate.
- Remove `HouseUser::calculateStreakWithAbsence` (`HouseUser.php:154`) and
  any remaining caller/test: availability-based streak continuity is
  superseded by the obligation-based evaluator (RF-070-01).
- Frontend removals with their specs: `useToasts.js:35-42,56-60` and
  `useCelebration.js:33-35,59-61` freeze/combo handlers, `sound.js:13` combo
  sound, `Leaderboard.vue:94,244-246,493-502` freeze indicators,
  `tokens.css:18` multiplier wording. Completion celebration keeps the
  retained confirmation/title path only.
- `RewardConfigService` sheds `weekly_goal_target_percent` and title-threshold
  overrides (fixed 80% / 3/6/12 policy); `TestDataService.php:44-59` drops the
  1% target seed and any freeze-era seed comments. `energy_max_per_task` is
  out of scope (not a WP4 retirement).
- `appinfo/info.xml:13-16` description loses the Coins/combos/streak-
  protection/leaderboards marketing; jobs block (:70-73) already matches.
  Fix the three stale comments (`HouseUserMapper.php:271-272`,
  `Wallet.php:22`, `TaskDueReminderService.php:22`) only where the file is
  otherwise touched; `TaskDueReminderService` itself is WP5's removal.

### 3.5 Minimal Client Cutover

- `src/store/user.js:53-56` `getLeaderboard` contribution ranking is removed.
  `Leaderboard.vue` stops rendering the unconditional all-member ranking: with
  ranking disabled it shows the opt-in/closed state; with ranking enabled it
  renders the consent-filtered server result (entries, ties, pending,
  provisional, neutral) from a new minimal ranking store slice. Full
  presentation redesign stays WP6.
- `Settings.vue` loses the multi-pack picker per Q-075-02 A;
  `TitleBadge.vue` keeps rendering the derived title. `src/constants/titles.js`
  reflects the one retained series in the same change.
- Store reconciliation: ranking/consent mutations refresh only the authorized
  ranking slice; a 409/disabled state preserves the draft. Reset the ranking
  slice on identity/house switch like every other store.
- New/removed UI text updates `l10n/en.json`, `l10n/de.json` and
  `l10n/templates/chorestrike.pot` in the same change (current baseline 1003
  keys); retired keys are removed, not orphaned.

### 3.6 Privacy, Departure and Erasure

- Ranking reads never expose non-consenting members, foreign consent states,
  private-task-derived facts or wallet data; anti-enumeration 404/403 per the
  shared formatter. Consent/withdraw are self-only mutations rechecked
  service-side under the house lock with `expectedVersion`/command identity
  where mutating.
- Extend `AccountErasureService` and the member-removal transaction to the new
  consent persistence (Q-075-01 Option A): purge on account deletion, delete
  on ordinary departure, no same-UID rebinding, no consent restore on rejoin.
- Extend the test-only full reset for any new table; production reset behavior
  is unchanged.

## 4. Tests

Required future tests, not results of this documentation round. Parent
scenario IDs refer to its ordered C1/C2/C3/C5 tables. T5's core exited with
WP3; this package owns the T5 remainder pins for the read contract and the
T8 ranking backend and consent persistence (non-ranking preferences exit with WP5, Jest
presentation with WP6).

| Group | Required evidence | Concrete files (brace lists enumerate files) |
|---|---|---|
| Rhythm/goal read contract / C1.6-8, C2.7 remainder | Pending-hold exposure, confirmed/current/best fields, eligible/onTime/neutral counts, provisional weeks, zero-work neutrality, GET purity (no award writes on `state`/`medals`). | M `tests/unit/Service/{RhythmService,WeeklyGoalService}Test.php`, `tests/integration/Service/RhythmProjectionTest.php`; M `tests/unit/Controller/GameControllerTest.php`, `tests/integration/Controller/GameControllerIntegrationTest.php`. |
| Ranking backend / T8; C5.12-15 | Current-week public-task ratio, exact ties, zero denominators neutral, next-Monday activation in house zone, pending-without-rank, withdrawal/disable/rejoin generations, no private-task/Coin leakage, departure/erasure invalidation. | N `tests/unit/Service/RankingServiceTest.php`, `tests/unit/Controller/RankingControllerTest.php`; N `tests/integration/Service/RankingConsentTest.php`. |
| Recognition cutover / C3.8-9 remainder | Single retained 3/6/12 series on the valid-history maximum; ordinary streak break retains an earned title; correction below threshold revokes; no config threshold override; retired pack keys rejected/absent; no repeat celebration. | M `tests/unit/{Titles/TitleCatalogTest,Service/TitleServiceTest}.php`, `tests/integration/Service/TitleServiceIntegrationTest.php`; M `tests/unit/Service/{MedalService,RewardConfigService}Test.php`; M `tests/unit/Db/Entity/{Medal,HouseUser}Test.php`. |
| Retirement negative pins | No task tier/multiplier field, route or column; no availability-streak helper; no freeze/combo handler, sound, label or marketing text; seeded family config uses the fixed 80% target. | M `tests/unit/Db/Entity/TaskTest.php`, `tests/unit/Controller/TaskControllerTest.php`, `tests/unit/Service/{TaskService,TestDataService}Test.php`; M `tests/unit/Migration/FreshInstallMigrationSmokeTest.php`; M `tests/unit/composables/{useToasts,useCelebration}.spec.js`, `tests/unit/components/MedalBadge.spec.js`, `tests/unit/views/Leaderboard.spec.js`, `tests/unit/store/{user,game}.spec.js`. |
| T0 growth | Fresh-install schema includes the consent table (Q-075-01 A) and excludes retired columns; erasure/departure sweep the new rows; same-UID non-rebinding. | M `tests/integration/Db/FamilyRhythmSchemaTest.php`, `tests/integration/Service/{AccountErasure,HouseMemberRemovalIntegration}Test.php`. |
| Minimal client / T8/T9-adjacent | Leaderboard gated/opt-in rendering, ties/neutral/provisional labels, consent+withdraw flows, identity-switch reset, single-series title display. | M `tests/unit/views/Leaderboard.spec.js`, `tests/unit/store/user.spec.js`; M or N ranking store spec per the chosen slice; M `tests/unit/components/TitleBadge.spec.js`, `tests/unit/views/Settings.spec.js`; R `tests/unit/components/TitlePicker.spec.js` only with its replacement coverage (Q-075-02 A). |
| Browser replacements (WP4 portion) | `title-selection.spec.ts` rewritten for retained 3/6/12 recognition after correction/spending (no three-pack picker if Q-075-02 A); `leaderboard.spec.ts` ranking assumptions replaced by opt-in/next-week/tie/zero-denominator scenarios; pending/neutral rhythm pins added to `family-rhythm.spec.ts` (WP4 half of the `time-travel-streak-notification.spec.ts` replacement; the quiet-digest half stays WP5); ranking-related assertions in `multi-user-interactions.spec.ts`/`insights-flow.spec.ts` adjusted to the consent model. | M `tests/e2e/{title-selection,leaderboard,family-rhythm,multi-user-interactions,insights-flow}.spec.ts`; M `tests/e2e/required-scenarios.json`, `scripts/run-e2e-gate.js`, `playwright.config.ts` in the same change. |

Use injected clocks (UTC, Europe/Berlin DST and a house-zone change across a
week boundary for the next-Monday activation). Consent/withdraw/departure
races use two connections with barriers; a sequential double-call proves only
idempotency. Ranking denominator facts are asserted from fresh connections
against occurrence/share evidence, not cached projections alone.

**Implementation gates:** `npm test -- --runInBand`, `npm run test:noise`,
`npm run lint`, `npm run build`, `npm run l10n:check-keys`,
`npm run backlog:check`, `npm run docs:encoding`, `git diff --check`;
Docker PHPUnit unit and integration with `--fail-on-skipped`, plus
`composer cs:check`, using the [canonical Docker workflow](../../standards/ai/skills/docker-dev/SKILL.md).
Run `npm run test:e2e:gate`, not a substituted passing subset. Keep
`tests/e2e/required-scenarios.json`, `scripts/run-e2e-gate.js` and
`playwright.config.ts` aligned when scenarios retire or change. No
new/unapproved skips, retries or test-noise suppression.

## 5. File Manifest

`M` = modify existing; `N` = create after activation; `R` = retire with
replacement coverage under the gitignored `archive/YYYY-MM/cs-plan-075-retired/`.
Section 4 test paths are part of this manifest. Recheck collisions at
activation; keep edits limited to actual contract consumers.

| Package | Files |
|---|---|
| Schema | M `lib/Migration/{Version1008Date20260228190000,Version1025Date20260719000000,Version1027Date20260725000000}.php` (tier/title-pack column retirement in the fresh-install chain); Q-075-01 A: N `lib/Migration/Version1032Date20260918000000.php` with the ranking-consent columns only (Version1033 stays reserved for WP7). |
| Rhythm/goal reads | M `lib/Service/{RhythmService,WeeklyGoalService,HouseService,GameLogicService}.php`; M `lib/Db/{HouseUser,HouseUserMapper,House,HouseMapper}.php` (pending exposure, read DTOs, `ranking_enabled` wiring); M `lib/Controller/GameController.php`. |
| Ranking (Q-075-01 A) | N `lib/Service/RankingService.php`, `lib/Controller/RankingController.php`; N `lib/Db/{MemberPreference,MemberPreferenceMapper}.php` (consent columns only); M `lib/Controller/{HouseController,UserController}.php` (house flag, departure hook); M `appinfo/routes.php`, `tests/unit/RouteMatrixTest.php`. |
| Recognition | M `lib/Titles/TitleCatalog.php` (single retained series, valid-history-maximum signal); M `lib/Service/{TitleService,MedalService,RewardConfigService,CompletionPostCommitService}.php`; M `lib/Db/{Medal,MedalMapper}.php`; M `lib/Controller/UserController.php` (titles payload; `selectTitle` removal per Q-075-02 A). |
| Retirements | M `lib/Db/Task.php`, `lib/Service/{TaskService,TestDataService}.php`, `lib/Controller/TaskController.php`; M `lib/Db/HouseUser.php` (helper removal); M `appinfo/info.xml` (description); M `lib/AppInfo/Application.php` only if a binding disappears. R: none of the WP3-retired services return; no migration file is deleted (the stale parent `Version1022/1023` removal entry is corrected, not executed). |
| Client | M `src/views/{Leaderboard,Settings}.vue`; M `src/store/{user,game}.js` plus N ranking slice file if a separate store is cleaner (`src/store/ranking.js`); M `src/components/Profile/TitleBadge.vue`; R `src/components/Profile/TitlePicker.vue` (Q-075-02 A); M `src/components/Common/MedalBadge.vue`; M `src/composables/{useToasts,useCelebration}.js`; M `src/utils/sound.js`; M `src/styles/tokens.css`; M `src/constants/titles.js`. |
| Fixtures / gates / l10n | M `tests/fixtures/{FamilyTestData,TestDataFixtures}.php`, `tests/integration/IntegrationTestCase.php` only if the reset inventory changes; M `tests/e2e/required-scenarios.json`, `scripts/run-e2e-gate.js`, `playwright.config.ts`, `tests/e2e/fixtures/*` as scenarios require; M `l10n/{en,de}.json`, `l10n/templates/chorestrike.pot`. |
| Durable documentation | M `docs/api/README.md` (ranking routes, game-state fields, removed tier/select-title surface), `docs/engineering/{architecture,testing}.md` (incl. re-measured size inventory), `docs/concepts/{gamification-and-rewards,ui-use-cases,task-model-and-sync}.md`, `docs/standards/ai/skills/gamification/SKILL.md`, `docs/adr/ADR-004-multi-user-gamification.md` (mark superseded recognition/ranking rules), `docs/testing/release-test-plan.md`; Q-075-01 A: N `docs/adr/ADR-015-consent-and-digest-delivery.md` with ranking consent accepted and digest delivery explicitly pending (ADR-014 WP7-pending precedent); M `docs/adr/README.md`, `docs/README.md`. |

The **drafting round** changes only this new plan, the parent WP4 pointer,
`docs/plans/README.md`, `docs/README.md`, `docs/backlog/backlog.md`
(CONCEPT-001/CR-053-11 annotations) and `docs/backlog/ACTIVE_TASKS.md`.
Preserve the pre-existing `docs/plans/{new,active,onhold}/.gitkeep` deletions
without staging or restoring them.

## 6. Implementation Order

One implementation owner coordinates shared files. Internal read-only lookups
may assist; synthesis and writes remain with the main agent. This plan neither
starts Agent Manager sessions nor creates a branch.

| Step | Work and exit evidence | Effort |
|---|---|---|
| 0. Activate the contract | Standard A resolves Q-075-01/02, freezes the consent schema/actor rules and the title-surface transition, updates parent ownership/estimates and required test assignments, then activates. Drafting is not this review. | Review prerequisite |
| 1. Lock the baseline | Recheck every citation in §1 at the then-current HEAD; inventory all tier/title/freeze/combo consumers and the ranking-adjacent E2E assertions; pin known-wrong outcomes (unconditional contribution ranking, break-revoked title, 1% seeded target) before replacement. | 0.5-1 day |
| 2. Recognition cutover | Single retained series on the valid-history maximum, config/label/pack-storage retirement per Q-075-02, `Task` tier-field and availability-streak removal, fixtures and negative pins together. | 1-2 days |
| 3. Rhythm/goal read contract | Pending exposure, §3.1 DTO fields, GET-purity pins, weekly counts/provisional labels. | 1 day |
| 4. Ranking backend + minimal client | Consent lifecycle, ranking service/controller/route, house flag, departure/erasure sweeps, leaderboard cutover, store reconciliation, l10n. | 1.5-2.5 days |
| 5. Closure | Browser replacements and gate discovery, durable docs, full gates, final review package. | 1 day |
| Consent slice (Q-075-01 A selected) | `member_prefs` consent columns via Version1032, lifecycle/invalidation, T0/T8 consent persistence evidence, ADR-015 consent portion. | +1-2 days (committed scope) |

Steps 2-4 are development checkpoints, not independently releasable surfaces;
no half-retired mechanic or dual ranking authority is a deliverable. Budget
about six focused hours per engineering day including tests/docs/one
correction pass. Re-estimate after the activation decisions rather than
silently absorbing added scope.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| Ranking leaks private/non-consenting data | High | Consent filter and public-task filter inside the service, anti-enumeration reads, dedicated privacy tests; no client-side filtering of a full member list. |
| Consent without real persistence (parent's former WP5-first rule) | High | Q-075-01 A creates the reserved table early with WP5 amending in place; no mock flag. |
| Earned title lost on an ordinary streak break | High | Valid-history-maximum signal plus retention/correction pins (D7c/C3g); the current `currentStreak` qualification is called out in §1 as a defect to fix, not to preserve. |
| Retired mechanics left half-wired | Medium | Consumer inventory at step 1; each removal ships with callers, schema, fixtures, l10n and negative pins in one change; no aliases. |
| Leaderboard gap between backend cutover and WP6 redesign | Medium | Minimal but complete opt-in/gated rendering in WP4; never a broken or unconditional all-member ranking left live. |
| Celebration repeats after correction | Medium | Single-series unlocked marker with first-earned semantics; undo/redo no-repeat pins (C3g). |
| Version1023 drift executed literally | Low | The live weekly-goal receipts migration is kept; the parent's stale removal entry is corrected in the parent annotation, not executed. |

## 8. Acceptance Criteria

- [x] Q-075-01/02 resolved and recorded; exact package scope, estimates and manifest accepted through Standard A (2026-09-27).
- [x] Reads expose confirmed/current/best rhythm, pending state and eligible/onTime/neutral/provisional weekly fields; no GET read mints, revokes or reconciles awards (GET-purity pin on both `state()` and `medals()`, `GameControllerIntegrationTest`).
- [x] Ranking shows only consenting members' current-week public-task on-time ratios with exact ties, neutral zero denominators, next-Monday activation and immediate withdrawal/disable removal (Q-075-01 A); the unconditional contribution ranking is gone.
- [x] One retained 3/6/12 title series qualifies on the valid-history maximum; earned titles survive ordinary streak breaks and spending, and only genuine correction revokes them; no config threshold override remains.
- [x] `selected_title_pack`/multi-pack surface and the `Task` tier/multiplier fields, availability-streak helper, freeze/combo client handlers, retired medal labels, stale marketing and obsolete config overrides are removed with their consumers and negative pins.
- [x] Consent/departure/erasure lifecycle proven: delete on leave, purge on account deletion (RF-075-02 membership-keyed pin), no rejoin restore, no same-UID rebinding (Q-075-01 A).
- [x] Every section 4 required test/gate passes without new/unapproved skips; real consent/departure races use two connections.
- [x] All plan-owned findings resolved or explicitly user-rescoped; no required dependent test deferred while marking this plan done.
- [x] Durable behavior integrated into concepts, ADR-004 (and ADR-015 under Q-075-01 A), API, engineering/testing and the gamification skill; the plan is not the only description.
- [x] CR-053-11 updated for its freeze-removal/tiebreaker portions without whole-item closure; CONCEPT-001 and remaining parent findings stay accurately open.
- [x] Final Standard B or Full review passes, performs the eligible lifecycle move, and updates parent/index/backlog/`ACTIVE_TASKS.md` accurately.

## 9. Review Findings (Plan-Owned)

Final Standard B (2026-09-29) — all found and resolved in the same review's
fix round (user-selected recommended options; none rescoped to the backlog):

- **RF-075-01 · major · resolved** — Settings/`rankingEnabled`: no owner UI
  existed for the ranking flag while the Leaderboard disabled-state copy and
  the E2E spec comment promised "the settings"; ranking was enableable only
  via direct API. Fix: minimal owner-only toggle in the Settings house card
  (`ranking-toggle`/`save-house-ranking`) through the new
  `houseStore.updateRankingEnabled()` on the shared `PUT /house` path, with
  EN/DE/POT l10n, house/settings store+view specs.
- **RF-075-02 · major · resolved** — missing §4 test evidence: (a) the
  erasure consent purge had no pin (mock injected, zero expectations; no
  same-UID non-rebinding proof) →
  `AccountErasureServiceTest::testCurrentMemberErasure_PurgesRankingConsentByMembershipId`
  asserts the purge keyed by the MEMBERSHIP id; (b) the new `ranking` store
  had no direct spec (the view spec fully mocks it) → N
  `tests/unit/store/ranking.spec.js` (view apply, consent/withdraw refresh,
  error paths, reset).
- **RF-075-03 · minor · resolved** — dead wrappers
  `RankingService::handleMembershipRemoved`/`purgeForErasure` (zero
  production callers; HouseService/AccountErasureService use the mapper
  under their transactions) deleted with their two wrapper-only unit tests;
  real-path coverage stays in `RankingConsentTest` (departure, disable) and
  the new erasure pin.
- **RF-075-04 · minor · resolved** — the valid-CLASS_AGREED on-time
  predicate existed 4× (RhythmService, WeeklyGoalService ×2, RankingService)
  → extracted to `CompletedTask::isAgreedOnTime()` (half-open window,
  behavior-neutral) with all four call sites de-duplicated.
- **RF-075-05 · nit · resolved** — stale `docker-dev` SKILL.md note still
  documented the retired family-profile 1%-target appconfig seed; updated to
  the `seedClosedWeeklyGoal` deterministic closed-week behavior.

## 10. Activation Decisions

Resolved through the Standard A question batch; recommendation first in the
original option list below.

| ID | Decision and options | State |
|---|---|---|
| Q-075-01 | **Ranking consent persistence vs the parent's WP5-first sequencing.** A (recommended): pull the minimal ranking-consent slice into WP4 — create the reserved `Version1032` `member_prefs` table now with only the consent columns (unique active-membership reference, version, consent generation, effective next-Monday date, saved zone, timestamps); WP5 amends it in place for digest/quiet/sound columns per the fresh-install chain policy (Version1030 in-place-extension precedent); record consent as accepted and digest delivery as explicitly pending in ADR-015 (ADR-014 WP7-pending precedent); parent WP4 dependency note and estimate updated (4-7 → 5-9 days). B: WP4 excludes ranking — rhythm/goal/recognition/retirements only (3-5 days) with the unconditional leaderboard hidden behind the frozen `ranking_enabled` flag as its minimal client measure; ranking backend + consent move to WP5 or a later WP4b and the parent's WP4 row/T8 are rescoped accordingly. C: honor the recorded spine literally — defer WP4 and implement WP5 first. No option permits a client-side-only or mock consent flag. | **A selected 2026-09-27** via batched structured question; implement the minimal consent slice with WP4 and amend the parent sequence. |
| Q-075-02 | **Title-surface transition for the single retained series.** A (recommended): retire the pack surface in WP4 — `selected_title_pack`, the `selectPack` route and `TitlePicker.vue` are removed with replacements (pull-forward of the parent's WP6 picker removal); `currentTitle` is the highest qualifying retained tier; a slimmed single-series unlocked-tier marker on `HouseUser` keeps first-earned celebration identity (C3g no-repeat); `Version1025` amended in place; `Settings.vue`, `src/constants/titles.js` and title reads adapt in the same change. B: retire the extra packs/config but keep a minimal selection column and the single-series picker until WP6. C: defer the Version1025 retirement to WP6 entirely — leaves the multi-pack config-overridable surface live against C3d and is recorded only for completeness. | **A selected 2026-09-27** via batched structured question; retire the selection surface in WP4. |

All other rules are parent-derived engineering proposals: one rhythm evaluator,
valid-history recognition, exact ratio ranking on real consent, calm defaults
and existing privacy boundaries. Any further material question found during
Standard A joins the same batch before transition.

## Draft Validation

Draft checks (2026-09-27): `npm run backlog:check`, `npm run docs:encoding`
and `git diff --check` passed. The unchanged document checkers also passed
with Git's tracked-plus-untracked inventory supplied in-process (29
link-scoped files; 792 encoding-scoped paths), and the new plan passed its
standalone `git diff --no-index --check` whitespace check. No staging or
checker edits were needed. Runtime/file/test inventory (section 1 citations,
service/migration/test existence, migration chain head Version1031 with
1032/1033 reserved, ADR-015 reservation free, l10n baseline 1003 keys) and a
drafting consistency pass informed the manifest; they are not a Standard A
verdict. Runtime and index remain unchanged. No runtime suites, family trial,
activation or commit were part of that documentation round. The subsequent
Standard A plan review resolved Q-075-01/02.

## Review Outcome (Standard A, 2026-09-27)

- **Scope/base:** this previously untracked WP4 plan and its drafting pointers
  in the parent, `docs/README.md`, `docs/plans/README.md`, `backlog.md` and
  `ACTIVE_TASKS.md`; `master` at `b3ff6dab8e290d2d2c73690b4d143c2c3aa2b977`,
  empty index. Captured plan SHA256:
  `6B5E0383C7744D989F4619D7C3661F949F5B58514C4AC2B5FE079D521915D13D`;
  drafting pointer binary diff SHA1:
  `ea51a4b51a4137625d8f605063045d7891e1ab6b`.
- **Outcome:** `PASS-WITH-FIXES`. Baseline references, parent D3/D5/D7/D10,
  C1/C2/C3/C5, WP3 handoff, dependencies, test ownership, migration reservation
  and absence of a competing active child checked. Manifest nits fixed: the
  nonexistent component-level `Leaderboard.spec.js` entry now points to the
  existing view-level spec, and archived `TitlePicker.vue` is classified R;
  initialized-house scope clarified for the GET-purity pin.
  No open plan-owned findings.
- **Decisions:** Q-075-01 A (Version1032 minimal consent in WP4, WP5 extends in
  place; revised 5-9 days) and Q-075-02 A (retire pack picker, route and column
  in WP4) selected by the user in one structured batch. Neither is a runtime
  implementation result.
- **Gates:** documentation checks and scoped diff whitespace check rerun after
  finalization (see review record). No PHP/JS source changed in this round;
  runtime/E2E implementation gates remain required by section 4 at delivery.
- **Transition:** `new/` to `active/`; CONCEPT-001 remains In Progress and
  CR-053-11 remains open for its independent Snake ordering remainder.

## Review Outcome (Final Standard B, 2026-09-29)

- **Scope/base:** the complete uncommitted WP4 increment on `master` at
  `a477c292fcf3122fca96e590e29869b54d34cf23` — 103 tracked paths (102 M /
  1 D `TitlePicker.vue`) + 11 untracked (ADR-015, RankingController/Service,
  MemberPreference/Mapper, Version1032, ranking store, ranking E2E + three
  ranking test files). Index empty at capture (empty-blob hash
  `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391`); pre-review unstaged binary
  diff SHA1 `cd204cafba4e2eba303180a3bf68aff1c812a9ff`; pre-review untracked
  SHA1s recorded in the review record. Post-fix untracked manifest: the same
  11 + N `tests/unit/store/ranking.spec.js` (per-file hashes in the review
  record).
- **Outcome:** `PASS-WITH-FIXES`. The whole plan was verified against the
  §5 manifest, §4 test matrix (with mapping notes: the read-contract pins
  landed in `GameControllerIntegrationTest` incl. the GET-purity proof for
  both reads; `RhythmProjectionTest`/`FamilyRhythmSchemaTest` stayed
  unmodified because their WP3 scope was already conformant — the §4 fresh
  schema pins live in `FreshInstallMigrationSmokeTest`), all acceptance
  criteria, and the durable-doc gate (ADR-015 + ADR-004 annotation,
  api/README ranking trio/state contract/titles, engineering size inventory
  re-measured, testing E2E map, concepts, gamification skill,
  release-test-plan §8, docs/README index, CR-053-11 annotation without
  closure). Five findings (2 major, 2 minor, 1 nit) were resolved in the
  in-review fix round — see §9.
- **Decisions (batched, recommended options selected):** add the minimal
  owner ranking toggle now; deliver both missing §4 pins in the fix round;
  apply all three minor cleanups in-round; accept the mtime-verified E2E
  gate run `run-NSOUkr` (2026-09-27 22:19-22:45, all three pools exit 0,
  zero violations, 91+126+39 tests, ranking-consent in the serial pool; no
  reviewed file changed after 22:19:19) — the fix round's later changes are
  unit/integration-covered and behavior-neutral on every E2E-asserted
  surface (new Settings section, store action addition, predicate
  extraction, dead-code removal, l10n additions).
- **Gates (re-run by this review on the post-fix tree):** Docker PHPUnit
  unit 982 OK / 3125 assertions and integration 475 OK / 2468 assertions,
  both `--fail-on-skipped` with zero skips; `composer cs:check` 0 of 365;
  Jest 1049 passed / 96 suites incl. `test:noise`; `npm run lint`
  (src + e2e) 0 problems; stylelint clean; webpack build green;
  `l10n:check-keys` 1005 keys exact EN/DE/POT (canonical regeneration,
  localizationGate green); `backlog:check` 28 files; `docs:encoding` 792
  files; `git diff --check` clean.
- **Transition:** `active/` to `done/`. CR-053-11 stays open for its
  independent Snake ordering remainder; CONCEPT-001 stays In Progress via
  the parent; the parent WP4/child pointers, `docs/plans/README.md`,
  `docs/README.md`, `backlog.md` links and `ACTIVE_TASKS.md` updated.
