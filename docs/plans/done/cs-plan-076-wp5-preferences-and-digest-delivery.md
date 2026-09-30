# CS-PLAN-076: WP5 - Preferences and Digest Delivery

> **Status:** Done; final Standard B `PASS-WITH-FIXES` (2026-09-30). RF-076-01..11 resolved, required gates green, durable documentation integrated; WP6 draft and its metadata explicitly excluded from the scoped review commit. The initial blocked attempt is preserved below. Standard A `PASS-WITH-FIXES` (2026-09-29).
> **Effort:** M/L; 5-8 engineering days (parent WP5 allowance)
> **Priority:** P1
> **Depends on:** [WP0 / CS-PLAN-071](../done/cs-plan-071-wp0-write-identity-foundation.md),
> [WP1 / CS-PLAN-072](../done/cs-plan-072-wp1-occurrence-timeline.md),
> [WP2 / CS-PLAN-073](../done/cs-plan-073-wp2-agreements-capacity-pauses.md),
> [WP3 / CS-PLAN-074](../done/cs-plan-074-wp3-work-accounting-and-replay.md) and
> [WP4 / CS-PLAN-075](../done/cs-plan-075-wp4-rhythm-goals-and-ranking.md), delivered.
> Q-075-01 Option A created `member_prefs` early; WP5 amends it in place.
> **Related backlog items:** CONCEPT-001 via
> [CS-PLAN-070](../active/cs-plan-070-family-rhythm-and-reward-clarification.md);
> FEAT-UX-024 (notification settings); FEAT-UX-025 (quiet-digest portion)
> **Planning baseline:** `b5cc55f` (WP4 final review commit), 2026-09-29

This is the implementation sub-plan for parent WP5. The parent D9/D10, C5a-m
and §3.6 delivery rules remain authoritative. Section 10 records the Standard A
decisions; the engineering proposals below are not additional product choices
beyond the parent's approvals.

## 1. Problem Summary

D9/C5 (approved 2026-09-17) require: notifications off by default, at most one
opt-in summary per person/day at a chosen time, house-timezone timing with DST
rules, personal quiet hours, no reminders on free/paused days, no streak
threats, awards quietly in-app, organizational updates only for opted-in
members, and missed slots expiring instead of catching up. The current
notification surface contradicts every part of that contract.

| Area | Baseline (verified 2026-09-29 at `b5cc55f`) | Required change |
|---|---|---|
| Daily job | `DailyNotificationJob.php:37` runs at a 24-hour interval and delegates to `TaskDueReminderService::process` (`:45-47`). | Five-minute due-slot pass driving slot materialization and dispatch (parent §3.6). |
| Individual reminders | `TaskDueReminderService.php:62-111` sends unconditional `task_due_today`/`task_due_tomorrow` bells to assignees via `NotificationService::notifyTaskDue` (`NotificationService.php:230-251`); the only skip is weekday availability (`:204-209`). No opt-in, no daily budget, no quiet hours, no house-time delivery time — contradicts D9, C5c/C5d, RF-070-10. | Retire service + test with the digest replacement; task relevance becomes a dispatch-time occurrence read inside the opt-in task digest, never a queued snapshot. |
| Award bells | `MedalService.php:171-173` dispatches `notifyWeeklyGoalReachedForHouse` (durable `weekly_goal_receipts` claim per closed week) and `:335-340` `notifyMedalAwarded`; `CompletionPostCommitService.php:64-73` dispatches `notifyTitleUnlocked`. | C5e: awards stay quietly in-app — the external dispatch paths are removed (Q-076-02); the weekly receipt remains the once-only celebration marker, not a bell claim. |
| Takeover bell | `CompletionPostCommitService.php:123-143` dispatches `notifyOverdueTakenOver` after a membership re-check; `CompletionService.php:230-240` calls it after commit. | Queue a membership-keyed change inside the completion transaction, not in the post-commit service; deliver only to org-opted-in recipients inside the shared daily summary (C5e/C5m, RF-076-02). |
| Preferences persistence | `member_prefs` exists with ranking-consent columns only (`MemberPreference.php:49-101`, `Version1032Date20260918000000.php:61-69`); the entity doc (`:15-26`) and migration doc (`:44-46`) reserve the WP5 in-place extension. Consent/re-consent also deletes the row (`RankingService.php:156-169`), as do withdraw/disable (`:185-191`, `HouseService.php:372-379`); current migration requires ranking Monday/zone. | Add self-only C5h columns in place; represent inactive ranking fields and update consent in place on every ranking path without deleting delivery preferences (Q-076-03, RF-076-01). |
| Preferences API/UI | No `PreferenceController` (19 controllers in `lib/Controller/`), no `/preferences/me` route (pin 80 in `RouteMatrixTest.php:292-298`), no preferences store, zero notification settings in `src/` (grep-verified); `Settings.vue` has house timezone + ranking toggle only. | `GET/PUT /preferences/me` (parent §3.4) plus a minimal self-only Settings surface (Q-076-01). |
| Digest machinery | None: no `digest_changes`/`digest_slots` tables, no `DigestService`/`DigestScheduleService`/`PreferenceService`. `NotificationService::dispatch` (`:264-292`) catches every throwable and returns void, so a caller cannot distinguish an accepted call from an uncertain one. | Slot/queue tables in the Version1032 in-place amendment; atomic slot claim; adapter return-contract change per parent §3.6 (accepted vs uncertain), keeping domain work committed. |
| Day/zone primitives | `CalendarService.php:43-137` provides DST-tested `getTimeZone`/`dayBounds`/`dayKey`/`dayStartInstant`/`weekStart`; `HouseService::updateHouse` (`:361-371`) bumps `timezone_version` and revises occurrences on zone change. | Reuse for slot resolution; add local-time→instant resolution (spring gap → first valid later instant, autumn fold → first occurrence, C5b); zone change re-resolves future pending slots under the 24-elapsed-hour spacing rule (C5k). |
| Notifier | `ChoreStrikeNotifier::prepare` (`:66-169`) renders six subjects; its class doc (`:20-28`) is stale — registration lives in `Application.php:35-38`, not `info.xml`. | One `digest` subject with visibility-safe content; the six retired subjects and their cases are removed with their callers; stale doc fixed. |
| E2E surface | `release-notifications.spec.ts:99-114` asserts the external weekly-award bell; `playwright.config.ts:22` still carries a dead `time-travel-streak-notification` regex (the spec was retired in WP3). | WP5 replaces the weekly-award assertion with opted-in shared-digest, missed-slot and no-default-delivery proofs (parent §4 replacement table) and cleans the dead regex. |

Durable requirements: [C5 delivery contract](../../concepts/task-model-and-sync.md#approved-delivery-contract-c5-2026-09-17),
[calm UX/evidence](../../concepts/ui-use-cases.md#approved-calm-ux-and-evidence-c5-2026-09-17),
[ADR-009](../../adr/ADR-009-per-house-timezone.md),
[ADR-013](../../adr/ADR-013-occurrence-agreements-and-corrections.md),
[ADR-015](../../adr/ADR-015-ranking-consent-and-privacy.md).
The parent's WP5 removal entry is stale: `StreakAtRiskService` and its test were
already retired in WP3 (Q-074-01 Option A); only `TaskDueReminderService`
remains. The parent annotation ships with the drafting-round pointer update
(WP4 Version1022/1023 precedent).

## 2. Strategy

One notification choke point (`NotificationService`) becomes digest-only:
every app-originated Nextcloud notification is the at-most-one daily opt-in
summary; awards, titles and in-app state stay in-app. Preferences are self-only
and server-side per active membership (C5h); the house timezone governs all
timing (C5a); task relevance is computed at dispatch from the WP1 occurrence
timeline (never queued, never copied from yesterday); organizational changes
queue transactionally with their source action and coalesce per slot (C5m).
No external worker or queue service: the existing Nextcloud cron drives a
five-minute job, and lateness is absorbed by the 60-minute same-day window,
never compensated by next-day catch-up (C5c).

| Ownership | Deliverable / boundary |
|---|---|
| WP5 / this plan | Non-ranking preference persistence + self-only API; DST-aware slot schedule; coalesced organizational queue; one bounded at-most-once digest attempt; removal of pressure notifications (task reminders, award bells, takeover bell) with their E2E replacements; minimal Settings surface per Q-076-01; T7 and the non-ranking persistence portions of T8; T0 schema/erasure growth. |
| WP0-4 / delivered | House-first locking and command identity (WP0), occurrence timeline and relevance facts (WP1), pauses/agreements as queue sources (WP2), wallet/recognition receipts (WP3), ranking consent + the `member_prefs` base table (WP4). Reuse; do not reopen Q-070-01/02, Q-072-01, Q-073-01..03, Q-074-01/02 or Q-075-01/02. |
| WP6 / later | Full `NotificationPreferences.vue` component, calm-UX redesign, sound/animation preference CONSUMERS (gating in `sound.js`/`confetti.js`), optional-area presentation. WP5 persists those columns and ships only the minimal surface needed to set them; no visual redesign. |
| WP7-9 / later | Reward-request organizational sources, combined cross-DB gates, family evidence (C5j). The queue contract is designed so WP7 adds sources without schema change. |

CONCEPT-001 owns the work. FEAT-UX-024's backend checklist items (per-user
preference storage, membership-gated endpoints, jobs respecting them) and its
settings-surface item are addressed here per Q-076-01; FEAT-UX-025's
quiet-digest integration portion is addressed here; neither item is closed by
this plan. RF-070-10 (no per-recipient budget, activity-pressure messaging) is
resolved by removal-with-replacement: the services it cites are retired, not
repaired.

## 3. Backend / Frontend Changes

### 3.1 Preference Persistence and Self-Only API (C5h)

- Extend `member_prefs` **in place** in `Version1032Date20260918000000`
  (fresh-install chain policy, Version1030 precedent; Version1033 stays
  reserved for WP7). New typed columns, all with neutral defaults:
  `task_digest_enabled` bool false, `org_digest_enabled` bool false,
  `digest_time` string(5) default `'18:00'` (`HH:mm`, house-local),
  `quiet_start`/`quiet_end` string(5) nullable (range may cross midnight),
  `sound_enabled` bool false, `animation_enabled` bool false,
  `arcade_visible` bool true, `catalog_visible` bool true. The existing
  `version` column doubles as the preference revision stamped onto slots.
  Change `ranking_consent_generation` default to 0; allow null
  `ranking_effective_monday` and `ranking_saved_zone` for an unconsented
  preference row. Ranking reads filter generation > 0 before calling the
  effective-date/zone methods. A first preferences save can create a row
  without granting ranking consent (RF-076-01). Optional-area columns are
  persistence only; their presentation consumers
  arrive in WP6.
- **Consent/delivery decoupling (Q-076-03 Option A):** ranking consent and
  re-consent update the existing row (or insert if absent), instead of
  deleting/recreating it; withdraw and house ranking-disable reset only the
  consent columns
  (`ranking_consent_generation` → 0, effective Monday/zone cleared) instead of
  deleting the row; the row is deleted only on membership removal and account
  erasure. Generation monotonicity is unaffected — the watermark lives on
  `houses.ranking_generation_seq` (WP4 generation-watermark design).
  Every change to that row increments its shared `version` under the house
  lock; stale preference drafts return 409 rather than silently overwriting
  another category's choice. `RankingService::consent`/`withdraw`, the
  `HouseService` disable path and their tests adapt in the same change.
- New `PreferenceService`: self-only read/update under the house-first lock;
  validates `HH:mm` shape, cross-midnight quiet ranges, digest time required
  when a category is enabled, and rejects unknown fields before side effects.
  `expectedVersion` + command identity on update; 409 preserves the draft.
  There is deliberately no route or service path for an owner to read or write
  another member's preferences (C5h).
- New `PreferenceController`: `GET /api/v1/preferences/me` (versioned own
  settings incl. effective ranking start, per parent §3.4) and
  `PUT /api/v1/preferences/me`. Thin controller, shared error formatter
  (400/403/404/409), `@NoAdminRequired` + membership assertion. Route count
  pin moves 80 → 82 with the consumers in the same change.
- Leave/rejoin resets: rejoin starts quiet/off because the row dies with the
  membership (existing `HouseService.php:230` deletion); opt-outs take effect
  immediately for unsent notices (3.4).

### 3.2 DST-Aware Slot Schedule (C5a/C5b/C5k)

- New `digest_slots` table (Version1032 in-place amendment): `id`, `house_id`,
  `house_user_id` (active-membership key, matching `member_prefs`), `local_day`
  (ISO date), `zone`, `pref_revision`, `scheduled_utc`, `window_end_utc`,
  `status` (`pending`/`dispatching`/`sent`/`skipped`/`uncertain`), dispatch
  token + dispatch time, timestamps. Unique `(house_id, house_user_id,
  local_day)` — the daily budget identity, independent of opt-in toggles.
- New `DigestScheduleService`: resolves a member's local `digest_time` in the
  house zone to UTC — a nonexistent time resolves to the first valid later
  instant, a repeated time to its first occurrence (C5b). `window_end_utc` is
  the earlier of `scheduled + 60 elapsed minutes` and local day-end (C5c).
  Slots are materialized idempotently (unique key + `insertOrGet` precedent)
  for opted-in members covering today/tomorrow; a `pref_revision` mismatch
  re-resolves pending slots. Opt-in never backfills: the first slot is the
  next still-open scheduled instant (today if its window has not closed,
  otherwise tomorrow); no slots are created while both categories are off.
- Quiet hours never shift a slot; they gate dispatch (3.4).
- Zone change (`HouseService::updateHouse` hook, extending the existing
  `:361-371` path in the same house-first transaction): pending future slots
  re-resolve in the new zone; a dispatch (including an uncertain one) blocks
  any further zone-switched dispatch for at least 24 elapsed hours, derived
  from retained slot history (C5k). Slot rows are retained across opt-out and
  preference re-creation so this spacing survives; they are deleted only with
  the membership (3.7).

### 3.3 Organizational Queue (C5m)

- New `digest_changes` table (Version1032 in-place amendment): `id`,
  `house_id`, recipient `house_user_id`, `object_type`, `object_id`,
  `object_revision`, `category` (`org`; `task` is a reserved value — task
  relevance is dispatch-computed, never queued), `change_time` (UTC),
  `target_slot` (house-local day), disposition (`queued`/`consumed`/`expired`),
  timestamps. Unique `(house_user_id, object_type, object_id, target_slot)` —
  coalescing keeps the latest relevant state per object per slot. No rendered
  payloads, no free text.
- Queue writers, each inside its existing source transaction: overdue takeover
  (`CompletionService` completion command transaction, replacing the
  `CompletionPostCommitService` bell; use the original recipient's active
  membership ID and immutable completion identity, never the captured task
  name), agreement recordings (handover/date/share amendments in
  `AgreementService`), pause decisions
  affecting a member (`PauseService` acknowledge/reject/cancel/retrospective
  recognition). Rows carry only recipients authorized at write time; rights
  and relevance are rechecked at dispatch (3.4). Retry/replay cannot enqueue
  twice; a rolled-back completion leaves no queued change. WP7 adds reward-request
  sources without schema change.
- Cutoff semantics: changes after a slot's resolved time belong to the next
  slot, even when the worker is late; a missed slot permanently expires its
  queued rows (`expired`), never delivering them later; in-app state is
  unaffected. Unchanged objects are never repeated.

### 3.4 Dispatch: One Bounded Attempt (C5c/C5e, D9)

- `DailyNotificationJob` is reconfigured from 24 hours to a five-minute
  interval and delegates to `DigestService::processDueSlots($now)`;
  `TriggerJobs` type `daily-notification` triggers the same pass. The job
  never extends the 60-minute window to compensate for cron lateness; the
  Nextcloud-cron prerequisite is documented, not engineered around.
- New `DigestService`: (a) materializes due/upcoming slots via
  `DigestScheduleService`; (b) claims one due slot atomically
  (conditional status update under the house-first lock; the unique day key
  makes concurrent workers idempotent); (c) rechecks BEFORE the final claim:
  membership active, category opt-ins still enabled, currently outside quiet
  hours (cross-midnight ranges), relevance — task content computed NOW from
  the occurrence reads (today's planned occurrences + explicitly agreed
  catch-up for today; nothing on free or recognized-paused days; no backlog,
  offers or future work), org content from queued rows (drop rows the
  recipient may no longer see); (d) if both categories are empty, marks the
  slot `skipped` — no empty summary is ever sent.
- One daily budget across both categories: a member with both enabled receives
  ONE combined summary (C5e). Off/on toggles create no new allowance — the
  slot identity is the retained `(member, local_day)` row.
- At-most-once contract (parent §3.6): persist the claim
  (`dispatching` + token) and commit BEFORE calling Nextcloud; never hold a DB
  transaction across `IManager::notify`. `NotificationService::dispatch` gains
  an explicit outcome (accepted) versus an uncertain exception instead of
  catch-and-log-only, so `DigestService` can mark `sent` only on acceptance
  and `uncertain` otherwise — an uncertain or crashed post-claim result
  consumes the slot and is NEVER resent. Pre-claim failures may retry within
  the window. Uncertain diagnostics are bounded and payload-free.
- The digest notification uses one `digest` subject with object type `digest`
  and objectId `{houseId}:{houseUserId}:{localDay}` (Nextcloud's
  `(app,user,objectType,objectId)` dedup key reinforces the day budget without
  being relied upon as the guarantee). Message content is category/count-based
  with a link to My Day; the notifier resolves no object references that could
  leak hidden task names or foreign request details (parent §3.6).
- Opt-out or departure committed before the claim wins; a call already in
  flight is not recallable app state (parent §3.6).

### 3.5 Notification Cutover and Removals (C5e, D9, RF-070-10)

- **R** `lib/Service/TaskDueReminderService.php` with
  `tests/unit/Service/TaskDueReminderServiceTest.php`, archived under
  `archive/2026-09/cs-plan-076-retired/`; `DailyNotificationJob` and
  `TriggerJobs` lose the injection. (`StreakAtRiskService` already retired in
  WP3 — parent annotation only.)
- **Q-076-02 (selected):** remove the award bell paths in the same change:
  `MedalService` drops `notifyWeeklyGoalReachedForHouse` /
  `notifyMedalAwarded` dispatch (the `weekly_goal_receipts` claim stays as the
  once-only in-app celebration marker; closed-week reconciliation logic is
  untouched), `CompletionPostCommitService` drops the title-unlock dispatch
  and drops the takeover dispatch (its queue write moves into the completion
  transaction), and `NotificationService` /
  `ChoreStrikeNotifier` shed the `medal_awarded`, `weekly_goal_reached`,
  `title_unlocked`, `overdue_taken_over`, `task_due_today`,
  `task_due_tomorrow` subjects and cases. Awards remain visible in-app
  (existing celebration/medal surfaces); nothing external is sent by default.
- `ChoreStrikeNotifier` keeps `prepare` with the single `digest` case; the
  stale class doc is corrected (registration in `Application.php`).
- `Application.php` changes only if a binding disappears; `appinfo/info.xml`
  updates its job/description comments to match the digest pass.
- Browser scenarios are rewritten, not blindly removed: see §4 — the
  weekly-award bell assertion is replaced by opted-in shared-digest,
  missed-slot and no-default-delivery proofs in the same change.

### 3.6 Minimal Client Surface (Q-076-01 Option A)

- New `src/store/preferences.js`: fetch/save own preferences, version-aware
  update with 409 draft preservation, reset on identity/house switch like
  every other store; `src/services/api.js` gains the two endpoints.
- `Settings.vue` gains a plain self-only "Notifications" section (no new
  component — the full `NotificationPreferences.vue` redesign stays WP6):
  task-digest and org-digest toggles, digest time input, quiet start/end
  inputs pre-filled with the 20:00/08:00 suggestion when enabling (C5c),
  sound/animation toggles. The section reads/writes only `/preferences/me`;
  house feature enablement stays owner-side and untouched.
- Sound/animation preferences are persisted only; their consumers
  (`sound.js`/`confetti.js` gating) are WP6 scope and unchanged here.
- All new/removed UI text updates `l10n/en.json`, `l10n/de.json` and
  `l10n/templates/chorestrike.pot` in the same change (baseline 1005 keys);
  retired keys are removed, not orphaned.

### 3.7 Privacy, Departure and Erasure

- Preferences routes are self-only; anti-enumeration 404/403 via the shared
  formatter; service-side membership re-checks on every mutation. No foreign
  preference, consent or digest data is exposed anywhere.
- Departure: the existing `member_prefs` row deletion is joined by
  `digest_changes` and `digest_slots` deletion for the membership inside the
  `HouseService::removeHouseMember` transaction; rejoin starts quiet/off
  (C5h). Ranking disable/withdraw reset consent columns only (Q-076-03) —
  delivery preferences survive.
- Erasure: `AccountErasureService` extends its per-membership sweep to the
  two digest tables (purge; the `member_prefs` purge exists since WP4);
  same-UID non-rebinding is pinned for the new tables.
- The test-only full reset inventory adds both tables; production reset
  behavior is unchanged. Uncertain-slot diagnostics log no payloads.

## 4. Tests

Required future tests, not results of this documentation round. Parent
scenario IDs refer to its ordered C5 table. This package owns T7 and the
non-ranking preference persistence portions of T8 (ranking consent/computation
exited with WP4 per Q-075-01 A; Jest presentation exits with WP6).

| Group | Required evidence | Concrete files (brace lists enumerate files) |
|---|---|---|
| Preferences backend / T8 non-ranking; C5.10, C5.12 portions | Self-only read/update validation matrix (HH:mm, cross-midnight quiet, digest time required, unknown fields, expectedVersion 409); owner cannot read/write foreign prefs; children included; leave/rejoin reset; preference row with no ranking consent, consent/re-consent/withdraw/disable preserving delivery prefs and incrementing the shared version (Q-076-03, RF-076-01). | N `tests/unit/Service/PreferenceServiceTest.php`, `tests/unit/Controller/PreferenceControllerTest.php`; M `tests/unit/RouteMatrixTest.php`, `tests/integration/Service/{RankingConsentTest,HouseMemberRemovalIntegrationTest}.php`, `tests/unit/Service/RankingServiceTest.php`. |
| Slot schedule / T7; C5.1-3, C5.15 | Gap/fold resolution in `Europe/Berlin` (+ UTC control), 60-minute/window-end/midnight cutoff, next-instant opt-in without backfill, pref-revision re-resolution, zone-change re-resolution with 24-hour spacing across a date/week boundary. Injected clock always; never the host clock. | N `tests/unit/Service/DigestScheduleServiceTest.php`; M `tests/unit/Service/CalendarServiceTest.php`, `tests/integration/Service/TimezoneDayBucketingTest.php`. |
| Queue + dispatch / T7; C5.4-7, C5.11 | Both categories one budget, queue cutoff/coalescing/expiry, dispatch-time relevance (completed/paused/free-day/invisible work vanishes; no backlog/future/optional injection), opt-out/departure before claim wins, quiet-hours suppression, `skipped` on empty, uncertain post-claim consumes the slot with no resend, pre-claim retry inside the window. | N `tests/unit/Service/DigestServiceTest.php`, `tests/integration/Service/DigestDeliveryTest.php`. |
| Concurrency / T7 | Two-connection claims of one slot (one dispatch), concurrent workers materializing the same slots (unique-key idempotency), claim versus zone change, claim versus preference toggle — barriers, not sleeps; a sequential double-call proves only idempotency. | N `tests/integration/Service/DigestConcurrencyTest.php`. |
| Adapter/notifier/job/command cutover | `dispatch` accepted-vs-uncertain outcome contract; single `digest` subject rendering and visibility-safe content; five-minute job delegation; `TriggerJobs` `daily-notification` → digest pass; retired subjects rejected. | M `tests/unit/Service/NotificationServiceTest.php`, `tests/unit/Notification/ChoreStrikeNotifierTest.php`, `tests/unit/BackgroundJob/DailyNotificationJobTest.php`, `tests/unit/Command/TriggerJobsTest.php`, `tests/integration/Command/TriggerJobsIntegrationTest.php`. |
| Award-bell retirement negative pins (Q-076-02) | No medal/weekly-goal/title/overdue/task-due dispatch path or subject remains; weekly receipt still claims the in-app celebration once; takeover queues under the completion lock, rollback writes nothing and receipt replay creates nothing new (RF-076-02). | M `tests/unit/Service/{MedalService,CompletionPostCommitService,NotificationService,CompletionService}Test.php`, `tests/integration/Service/{RhythmProjectionTest,CompletionCorrectionTest}.php` (adjust dispatch expectations); N `tests/integration/Service/DigestDeliveryTest.php` (transaction/replay pins). |
| T0 growth | Fresh-install schema includes the extended `member_prefs` + both digest tables; departure deletes, erasure purges (membership-keyed), same-UID non-rebinding. | M `tests/integration/Db/FamilyRhythmSchemaTest.php`, `tests/unit/Migration/FreshInstallMigrationSmokeTest.php`, `tests/integration/Service/AccountErasureTest.php`. |
| Minimal client / T8 persistence portion | Preferences store fetch/save/409-draft/identity-switch reset; Settings section renders own values, saves, pre-fills the quiet suggestion on enable. | N `tests/unit/store/preferences.spec.js`; M `tests/unit/views/Settings.spec.js`, `tests/unit/store/user.spec.js` (reset wiring). |
| Browser replacements (WP5 portion) | `release-notifications.spec.ts` replaced: opted-in shared digest arrives exactly once, missed slot expires (no catch-up), default (no opt-in) delivers nothing, quiet hours suppress — pinned in new `quiet-digest.spec.ts`; dead `time-travel-streak-notification` regex removed. | N `tests/e2e/quiet-digest.spec.ts`; R `tests/e2e/release-notifications.spec.ts` with its replacement wired; M `tests/e2e/required-scenarios.json`, `scripts/run-e2e-gate.js`, `playwright.config.ts` in the same change. |

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
replacement coverage under the gitignored `archive/2026-09/cs-plan-076-retired/`.
Section 4 test paths are part of this manifest. Recheck collisions at
activation; keep edits limited to actual contract consumers.

| Package | Files |
|---|---|
| Schema | M `lib/Migration/Version1032Date20260918000000.php` (in-place: `member_prefs` delivery columns + `digest_slots` + `digest_changes`; Version1033 stays reserved for WP7). |
| Preferences | N `lib/Service/PreferenceService.php`, `lib/Controller/PreferenceController.php`; M `lib/Db/{MemberPreference,MemberPreferenceMapper}.php` (delivery columns; nullable inactive ranking state and shared revision); M `lib/Service/{RankingService,HouseService}.php` (consent/re-consent/withdraw/disable decoupling per Q-076-03); M `appinfo/routes.php`. |
| Digest | N `lib/Db/{DigestSlot,DigestSlotMapper,DigestChange,DigestChangeMapper}.php`; N `lib/Service/{DigestScheduleService,DigestService}.php`; M `lib/BackgroundJob/DailyNotificationJob.php` (five-minute digest pass), `lib/Command/TriggerJobs.php`, `lib/Service/{CalendarService,HouseService}.php` (local-time resolution helper; zone-change slot re-resolution). |
| Notification cutover | M `lib/Service/{NotificationService,CompletionPostCommitService,CompletionService,MedalService}.php`; M `lib/Notification/ChoreStrikeNotifier.php`; M `lib/AppInfo/Application.php` only if a binding disappears; M `appinfo/info.xml` (comments). R `lib/Service/TaskDueReminderService.php`. |
| Queue sources | M `lib/Service/{CompletionService,AgreementService,PauseService}.php` (transactional queue writes); `CompletionPostCommitService` only removes its old bell. |
| Client | N `src/store/preferences.js`; M `src/services/api.js`, `src/views/Settings.vue`, `src/store/user.js` (reset wiring only). |
| Fixtures / gates / l10n | M `tests/fixtures/{FamilyTestData,TestDataFixtures}.php`, `tests/integration/IntegrationTestCase.php` (reset inventory); M `tests/e2e/required-scenarios.json`, `scripts/run-e2e-gate.js`, `playwright.config.ts`, `tests/e2e/fixtures/*` as scenarios require; M `l10n/{en,de}.json`, `l10n/templates/chorestrike.pot`. |
| Durable documentation | M `docs/api/README.md` (`/preferences/me`, digest behavior, removed notification surface), `docs/engineering/{architecture,testing}.md` (incl. re-measured size inventory), `docs/concepts/{task-model-and-sync,ui-use-cases,gamification-and-rewards}.md`, `docs/standards/ai/skills/gamification/SKILL.md`, `docs/adr/ADR-009-per-house-timezone.md` (annotate the delivery timing slice), `docs/testing/release-test-plan.md`; N `docs/adr/ADR-016-quiet-digest-delivery.md` (Q-076-04 Option A; ADR-015 stays consent-only) with M `docs/adr/README.md`, `docs/README.md`. |

The **preceding drafting round** changed only the then-new plan, the parent WP5 pointer and
staleness annotations, `docs/plans/README.md`, `docs/README.md`,
`docs/backlog/backlog.md` (CONCEPT-001/FEAT-UX-024 annotations) and
`docs/backlog/ACTIVE_TASKS.md`. Preserve the pre-existing
`docs/plans/{new,active,onhold}/.gitkeep` deletions without staging or
restoring them.

### Implementation and Evidence Mapping (2026-09-30)

No required behavior or test was waived. The concrete implementation uses
`docs/adr/ADR-016-opt-in-digest-notifications.md` for ADR-016; section 10's
original filename remains the historical activation choice. Durable API,
engineering and concept descriptions also live in their `notifications.md`
pages, now linked from the canonical entry points named in the manifest.
The reset inventory lives in `TestDataService` and `IntegrationTestCase`;
the two proposed fixture classes needed no additional digest seed state.

Section 4 evidence is mapped to the actual seams:

- Calendar helper behavior is tested through the real `CalendarService`
  injected into `DigestScheduleServiceTest` (UTC, Berlin gap/fold, midnight
  clamp and spacing); `TimezoneDayBucketingTest` adds real calendar pins.
- `DigestConcurrencyTest` now has six two-connection, pipe-barrier proofs:
  simultaneous dispatch, crash-after-claim, materialization, opt-out,
  digest-time change and timezone change. No sleeps or newly skipped tests.
- `DigestDeliveryTest` has real-DB expiry/coalescing, source-transaction
  sabotage/rollback, receipt replay and current-policy negative evidence.
  `TriggerJobsIntegrationTest` uses a real source identity instead of a mock
  organizational reference; it proves the actual notification adapter.
- The nonexistent proposed `CompletionPostCommitServiceTest` is not a
  missing behavior waiver: cutover/negative pins reside in
  `MedalServiceTest`, `NotificationServiceTest`, `ChoreStrikeNotifierTest`
  and the completion/job tests. Existing `RhythmProjectionTest` and
  `CompletionCorrectionTest` stay green without a dispatch expectation
  change; the weekly receipt's in-app semantics remain pinned.
- Existing schema, ranking, membership-removal and erasure suites cover
  the shared version, inactive consent and membership-keyed cleanup.
  Preferences store/Settings tests and the existing user-reset wiring
  remain green. `quiet-digest` now proves both real categories in one bell,
  silent defaults, replay suppression and quiet/missed-window suppression.

## 6. Implementation Order

One implementation owner coordinates shared files. Internal read-only lookups
may assist; synthesis and writes remain with the main agent. This plan neither
starts Agent Manager sessions nor creates a branch.

| Step | Work and exit evidence | Effort |
|---|---|---|
| 0. Activate the contract | Standard A resolved Q-076-01..04 plus RF-076-01/02, froze the preference schema/actor rules, award-bell transition and transactional takeover queue, updated ownership and tests, then activated. Drafting was not this review. | Completed 2026-09-29 |
| 1. Lock the baseline | Recheck every citation in §1 at the then-current HEAD; inventory all dispatch callers/subjects and the notification E2E assertions; pin known-wrong outcomes (unconditional task reminders, award bells, takeover bell) before replacement. | 0.5-1 day |
| 2. Preferences slice | Version1032 in-place columns and nullable inactive ranking fields, entity/mapper, all-path consent/delivery decoupling (Q-076-03, RF-076-01), `PreferenceService`/`PreferenceController`/routes, store + minimal Settings surface, validation/lifecycle tests, l10n. | 1.5-2 days |
| 3. Schedule + queue | Slot table/`DigestScheduleService` (gap/fold, windows, re-resolution, zone change), `digest_changes` + three transactional queue writers (takeover inside completion command), cutoff/coalescing/expiry and rollback/replay tests. | 1.5-2 days |
| 4. Dispatch + cutover | `DigestService` claim/recheck/at-most-once, adapter outcome contract, job/`TriggerJobs` reconfiguration, award-bell and task-reminder retirement with negative pins, concurrency tests. | 1.5-2 days |
| 5. Closure | Browser replacements and gate discovery, durable docs incl. ADR-016, full gates, final review package. | 1 day |

Steps 2-4 are development checkpoints, not independently releasable surfaces;
no half-retired notification path or dual delivery authority is a deliverable.
Budget about six focused hours per engineering day including tests/docs/one
correction pass. Re-estimate after the activation decisions rather than
silently absorbing added scope.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| Duplicate or repeated digest sends | High | Day-key slot identity, atomic conditional claim, persist-before-notify, uncertain-consumes-slot rule; two-connection claim/worker races with barriers; off/on toggle pins. |
| Notification timeout mistaken for a safe retry | High | Parent §3.6 at-most-once doctrine: uncertain post-claim results are never resent; bounded payload-free diagnostics; no exactly-once promise. |
| Cron lateness treated as an engineering problem | Medium | 60-minute same-day window with permanent skip; Nextcloud-cron prerequisite documented; no next-day catch-up, no external worker. |
| Award-bell removal regresses celebration visibility | Medium | Weekly receipt stays the once-only in-app celebration marker; existing medal/title surfaces pinned; E2E rewrite asserts the in-app path, not a bell. |
| Ranking withdraw/disable wipes delivery preferences | Medium | Q-076-03 consent-column reset with lifecycle tests (withdraw, disable, re-consent, leave, erasure); watermark monotonicity unchanged. |
| Queue leaks private or stale content | High | No rendered payloads in `digest_changes`; recipients fixed at write, rights/visibility rechecked at dispatch; drop-on-deny tests; membership-keyed sweeps. |
| Consent/delivery coupling reintroduces a mock contract | Low | Real persistence in the WP4-created table per Q-075-01 A; no client-side-only flags; T0 fresh-install schema pins. |
| Scope creep into WP6 presentation | Medium | Q-076-01 minimal-surface guard: plain Settings section + persistence only; sound/animation consumers and the component redesign stay WP6. |
| SQLite slot-claim serialization gap | Medium | Portable unique day key + conditional claim, shared house-first lock order and WP5 two-connection proofs in the canonical MariaDB test environment; the parent's WP9 explicitly owns the isolated SQLite/PostgreSQL verification matrix. No SQLite run is claimed by this WP5 review. |

## 8. Acceptance Criteria

- [x] Q-076-01..04 and RF-076-02 transaction choice resolved and recorded; package scope, estimate and manifest accepted through Standard A (2026-09-29).
- [x] Preferences are self-only and server-side: task/org opt-ins, digest time, quiet range and sound/animation/optional-area choices persist per active membership with validation, `expectedVersion` conflict handling and no owner access to foreign preferences (C5h); leave/rejoin resets to quiet/off.
- [x] Slots resolve in the house timezone with DST gap/fold rules, a 60-minute same-day window and quiet-hours gating; a zone change re-resolves future slots and enforces the 24-elapsed-hour spacing (C5a/b/c/k).
- [x] At most one combined summary per person/day across both categories; task relevance is computed at dispatch from occurrences; organizational changes queue transactionally, coalesce per slot and expire with a missed slot; opt-out/departure before the claim wins (C5d/e/m).
- [x] At-most-once dispatch holds under concurrent workers, retries, toggles and crashes: an uncertain post-claim result consumes the slot and is never resent; pre-claim failures retry only inside the window.
- [x] Pressure notifications are gone: `TaskDueReminderService` retired, award/title/takeover bells removed (Q-076-02) with the weekly receipt kept as the in-app celebration marker and takeover routed to the queue; negative pins prove no retired subject or dispatch path remains (RF-070-10).
- [x] Ranking withdraw/disable invalidate consent without deleting delivery preferences (Q-076-03); departure deletes and account erasure purges all preference/digest rows membership-keyed, with same-UID non-rebinding.
- [x] Ranking first-consent and re-consent also preserve delivery settings; inactive rows cannot enter rankings; all preference/consent writes advance the shared version (RF-076-01).
- [x] Takeover queue insertion commits or rolls back with the completion, and command replay does not enqueue again (RF-076-02).
- [x] Every section 4 required test/gate passes without new/unapproved skips; real claim/worker/zone races use two connections.
- [x] All plan-owned findings resolved or explicitly user-rescoped; no required dependent test deferred while marking this plan done.
- [x] Durable behavior integrated into concepts, ADR-016, API, engineering/testing and the gamification skill; the plan is not the only description.
- [x] FEAT-UX-024 backend/minimal-surface items and the FEAT-UX-025 quiet-digest portion updated for their real completion state without whole-item closure; CONCEPT-001 stays accurately open.
- [x] Final Standard B or Full review passes, performs the eligible lifecycle move, and updates parent/index/backlog/`ACTIVE_TASKS.md` accurately.

## 9. Review Findings (Plan-Owned)

- [x] **RF-076-01 major · §3.1 / `RankingService::consent` / Version1032:** draft only changed withdraw/disable, but consent/re-consent also deletes the preference row, and required ranking Monday/zone fields prevent a preference-only row. Resolved in this plan review: Q-076-03 A extends to first consent/re-consent in-place, nullable inactive fields, generation 0, filtered ranking reads, shared version increments and lifecycle tests. Runtime work remains required by §4/§8.
- [x] **RF-076-02 major · §3.3 / `CompletionPostCommitService`:** draft named the post-commit service as a transactional takeover queue writer, although it runs after completion commit. Resolved in this plan review: user chose the completion command's house-first transaction as queue owner, with rollback/replay coverage; post-commit service removes the old bell. Runtime work remains required by §4/§8.
- [x] **RF-076-03 major · `DigestService::countRelevantTasks`:** resolved 2026-09-30: completed/covered, differently appointed and currently invisible work is excluded; recognized day-level pauses also suppress catch-up from another planned day. Unit and real-policy integration regressions green.
- [x] **RF-076-04 major · digest claims/materialization/expiry:** resolved 2026-09-30: shared house-first locking, fresh slot re-read, current revision/zone re-resolution and schedule/window/day checks; expiry cannot overwrite a concurrent claim. Unit and six two-connection integration proofs green.
- [x] **RF-076-05 major · `DigestScheduleService`:** resolved 2026-09-30: retained different-zone dispatch history gates both new and revised slots; ordinary same-zone days keep their chosen time. Schedule regressions green.
- [x] **RF-076-06 major · queue cutoff:** resolved 2026-09-30: current preferences determine cutoff even without a materialized slot. Before/at-cutoff regressions and real combined browser digest green.
- [x] **RF-076-07 major · required test matrix:** resolved 2026-09-30: pipe-barrier worker/claim/materialization/zone/toggle tests and real takeover rollback/replay pins added; takeover rows carry immutable completion identity. Full backend and three-pool browser gates green, no required test rescoped.
- [x] **RF-076-08 major · dispatch source rechecks:** resolved with explicit user authorization 2026-09-30: current source facts and the existing visibility policy gate counts; negative unit and real-policy integration evidence green. Only sanitized evidence is recorded here.
- [x] **RF-076-09 major · partial preference updates:** resolved with explicit user authorization 2026-09-30: resulting settings are validated under lock against stored/default values before writes. Partial-bound and enabled-empty-time no-write regressions green.
- [x] **RF-076-10 minor · durable documentation/test mapping:** resolved 2026-09-30: API/testing/concept/gamification entry points, notification docs and changed-file size inventory repaired; actual filename and equivalent evidence seams are recorded above. Lifecycle/backlog closeout remains blocked by RF-076-11.
- [x] **RF-076-11 block · finalization scope drift:** resolved on resumed finalization 2026-09-30: the user explicitly requested WP5 closure with the new plan excluded. A fresh stable capture verifies unchanged runtime/test fingerprints; WP6 draft and shared WP6 metadata are excluded through scoped staging, without reverting them. The earlier preserve-and-stop decision and blocked attempt remain historical evidence below.

### Standard B Decisions (2026-09-30)

- Q-076-B1: user selected **Fix in this review** instead of the recommended
  blocked-record/dedicated-round option. Behavior corrections and the required
  missing regression/concurrency evidence are authorized; no test is rescoped.
- Q-076-B2: **Enforce rechecks (Recommended)** selected explicitly for current
  organizational source state and the existing recipient visibility policy.
- Q-076-B3: **Validate merged state (Recommended)** selected explicitly for
  partial preference updates against the stored/default values under lock.
- Q-076-B4: **Preserve and stop (Recommended)** selected for concurrent WP6
  documentation; the alternative was a user-authorized expanded-scope
  recapture. No expanded review, WP6 activation, move or commit is authorized.
- Q-076-B5: subsequent explicit user instruction (2026-09-30) resumes WP5
  closure and the scoped commit, while leaving the new WP6 plan outside scope.
  It supersedes B4's stop for WP5 only; no WP6 review/activation is authorized.

## 10. Activation Decisions

Resolved in the 2026-09-29 Standard A structured question batch. Everything
else in this plan is a parent-derived engineering proposal.

| ID | Decision and options | State |
|---|---|---|
| Q-076-01 | **Client surface for preferences in WP5.** A: backend plus a minimal self-only Settings section and `src/store/preferences.js`; WP6 owns the polished component, calm presentation and sound/animation consumers. B: backend-only, API-seeded E2E and unusable UI until WP6. C: pull full WP6 component into WP5. | **A selected** — real opt-in UI and browser evidence without WP6 redesign. |
| Q-076-02 | **Award-bell transition.** A: remove medal/weekly-goal/title external dispatch and subjects with negative pins; retain weekly receipt as in-app celebration marker and rewrite browser proof. B: digest awards, changing C5e. C: defer removal, leaving nonconforming bells. | **A selected** — in-app-only awards. |
| Q-076-03 | **Shared preference row lifecycle (RF-076-01).** A: inactive nullable Monday/zone plus generation 0; first consent/re-consent updates existing row, withdrawal/disable clear ranking fields, all row writes increment shared version, only departure/erasure delete. B: separate delivery table, deviating from parent's in-place design. C: permit ranking changes to lose delivery settings. | **A selected** — independent delivery preferences, monotonic consent watermark, 409 on concurrent changes. |
| Q-076-04 | **Digest ADR placement.** A: new `docs/adr/ADR-016-quiet-digest-delivery.md` (ADR-015 remains consent-only; WP7 takes next free number). B: extend/rename delivered ADR-015. | **A selected** — separate durable digest decision. |
| RF-076-02 | **Takeover queue transaction.** A: write membership-keyed change in `CompletionService`'s completion transaction; `CompletionPostCommitService` only removes the bell. B: queue after commit, risking loss. | **A selected** — source action and queue row commit atomically; rollback/replay pinned. |

## Draft Validation

Draft checks (2026-09-29, before this review): `npm run backlog:check` (28 scoped files
link-checked), `npm run docs:encoding` (803 tracked files), `git diff --check`
and the standalone `git diff --no-index --check` whitespace check for the new
plan all passed with the drafting-round pointer updates included; the three
pre-existing `.gitkeep` deletions stayed excluded. Runtime/file/test inventory
(section 1 citations, service/migration/test existence, migration chain head
Version1032 with in-place amendment reserved and Version1033 reserved for WP7,
ADR-016 free, route pin 80, l10n baseline 1005 keys) and a drafting consistency
pass informed the manifest; they are not a Standard A verdict. Runtime and
index remain unchanged. No runtime suites, family trial, activation or commit
were part of the preceding documentation round.

## Review Outcome

- **2026-09-30 · Resumed final Standard B · PASS-WITH-FIXES.** Fresh capture:
  `master` HEAD `ef417f2feb643dc7220558a80ab314802aa40e91`, empty index;
  full unstaged fingerprint `2004c01dacaabee8f56a048528087daaaffc0e1f`.
  Verified runtime/test tracked diff remains
  `c580f4a139cdacac40b90875aec89b53f03c470b`, with all 17 untracked
  runtime/test hashes matching the passing review. No runtime changes were
  needed on resumption; full unit/integration/Jest/style/l10n evidence and
  E2E `run-xpEKYP` remain valid. Documentation gates rerun after closeout.
- Q-076-B5 resolves RF-076-11 by an explicit WP5-only recapture/exclusion,
  not by treating the WP6 draft as reviewed. RF-076-01..11 and all section-8
  criteria are complete. Parent/index/backlog/registry reflect WP5 delivery;
  partially owned backlog items and the parent remain open for later packages.
  This plan moves `active/` to `done/` with the one scoped review commit.

- **2026-09-30 · Standard B · BLOCKED (finalization scope drift).** Initial
  scope: 70 tracked WP5 paths plus 21 untracked paths (three pre-existing
  `.gitkeep` deletions excluded), explicit owner this plan; `master` HEAD
  `ef417f2feb643dc7220558a80ab314802aa40e91`, empty index. Initial full
  working-tree unstaged binary-diff Git-object fingerprint:
  `7b18334166e6451e8ed89a261317562a325330f1`; staged fingerprint
  `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391`. The review record contains the
  exact initial path/hash manifest and verified post-fix source fingerprint.
- RF-076-03..10 resolved through authorized fixes, regression evidence and
  durable-doc integration. Gates: Docker unit **1028/3287**, integration
  **493/2645**, both `--fail-on-skipped`; PHP style **0-of-377**; Jest
  **1062/97**; lint, test-noise and **1014** localization keys green;
  production build, backlog/encoding/whitespace checks also run in this
  review. Full E2E gate **`run-xpEKYP` passed all three pools**, with zero
  failed/skipped/flaky required scenarios. Interrupted earlier browser runs
  and the initial invalid-source integration fixture were not green evidence.
- Q-076-B1..04 answered through structured questions. Concurrent WP6
  documentation prevented a stable finalization package; the user chose
  preservation rather than scope expansion. RF-076-11 remains open,
  acceptance/lifecycle/backlog closeout is deliberately not certified,
  the plan remains `active/`, and no files were staged or committed.

- **2026-09-29 · Standard A · PASS-WITH-FIXES.** Scope: this WP5 plan and
  parent/backlog ownership, reviewed at `master` HEAD
  `b5cc55fa2e768265d01ee646e85c93a8e88536a9` (index empty, staged binary
  diff hash `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391`; unstaged binary
  diff hash `871015765dd7c7c9e64c680f41a6eebc370009aa`; untracked plan hash
  `99550fee377f9927ba67f5e12854d980b9814b7e`; `.gitkeep` deletions excluded).
- **Findings fixed in the design:** RF-076-01 consent/re-consent and nullable
  inactive ranking schema; RF-076-02 transaction ownership of takeover queue.
  Their runtime/test criteria remain unchecked. Q-076-01..04 and RF-076-02
  transaction choice answered with recommended A options via one structured batch;
  section 10 records each choice. The estimate remains 5-8 engineering days;
  re-estimate at implementation checkpoints if the enlarged test seam demands it.
- **Gate:** documentation/backlog link, encoding and whitespace checks run
  during activation (results in the review record); no runtime code or required future tests changed by this
  plan review. CONCEPT-001/FEAT-UX-024/FEAT-UX-025 remain open. The parent plan
  stays active; this child moves `new/` → `active/` for implementation.
