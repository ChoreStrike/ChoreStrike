---
name: gamification
description: ChoreStrike gamification domain model — minor-unit wallet, work splits, rhythm recognition, medals, energy, streaks, and fairness rules
---

# Skill: Gamification Domain

**Load when:** Implementing or modifying reward mechanics, work splits, wallet
postings, corrections, rhythm recognition, medals, streaks, energy balancing,
or role titles.

**Canonical references:**
- [`gamification-and-rewards.md`](../../../../concepts/gamification-and-rewards.md)
- [`energy-and-balancing.md`](../../../../concepts/energy-and-balancing.md)
- [`task-model-and-sync.md`](../../../../concepts/task-model-and-sync.md) § Completion and undo model
- [ADR-014: Work Accounting, Ledger and Replay](../../../../adr/ADR-014-work-accounting-ledger-and-replay.md)
- [ADR-004: Multi-User Gamification](../../../../adr/ADR-004-multi-user-gamification.md) (historical)

**Current versus intended behavior:** WP3 (2026-09-27) retired the legacy
economy — integer coin mints, tier/personal/early multipliers, combos,
streak freezes, teamwork bonuses and the boost/arcade economy are gone.
Rewards are exact minor-unit derivations of frozen work evidence on an
immutable ledger. Do not re-introduce multipliers or snapshot reversal;
describe the WP3 model as the shipped contract.

---

## Core Systems

### 1. Wallet (exact minor units, immutable ledger)
- One wallet per member (`chorestrike_wallets`, bound under lock; departed
  members retain wallet + balance; never re-bind erased facts).
- Value is exact integer minor units: **100 energy minor = 1 energy,
  100 coin minor = 1 Coin, 1000 coin minor per frozen energy unit** (the
  10-Coin rate frozen at completion time as `rate_minor`).
- `chorestrike_wallet_entries` is the immutable ledger: one row per wallet,
  source kind (`completion` / `completion_undo` / `attribution` / `manual`),
  source id + revision, signed `delta_minor`, bounded reason, actor. The
  unique `(wallet, source-kind, source-id, source-revision)` key is the
  idempotency identity — every command commits exactly once.
- Cached `booked/reserved/available` + funding-hold watermark are maintained
  transactionally, never re-summed at read; safe-integer accumulation is
  enforced at every boundary.

### 2. Work splits (the attributed fact)
- The completer records the actual participants: 1–100 unique eligible house
  members, positive whole percents summing to exactly 100; the completer must
  take part (self-participation, Q-074-02).
- Exact math: `energyMinor = totalEnergy × sharePct`, `coinMinor = 10 ×
  totalEnergy × sharePct`. No floating point anywhere.
- A later attribution (`PUT /completions/{id}/attribution`, completer or
  owner authority + explicit `allocationReviewed` deviation confirmation)
  rewrites the selected allocation. The reversal union is keyed by **wallet id
  from the share rows** — departed participants' retained wallets always
  reverse, so a completion pays out exactly the frozen total.

### 3. Corrections (C3e/C3f closure)
- `POST /completions/{id}/undo` reverses the **latest selected allocation
  revision** exactly once (occurrence release, exact negative wallet deltas,
  room replay, recognition recompute). Idempotent; a stale version is a 409.
- Attribution never rewrites the frozen work facts (time, zone, occurrence,
  total energy, rate), the room journal or the audit chain
  (`completion_revs` is append-only; a departed-not-erased actor renders as
  the localized 'Removed member' placeholder).

### 4. Rhythm recognition (valid-history derivations)
- `currentStreak`/`longestStreak` recompute from the valid planned-day
  history inside every aggregate: a day is fulfilled / pending-neutral /
  expired-miss from occurrence validity + window; a correction shrinks the
  longest record only to the corrected valid-history maximum (C3g).
  `RhythmService` evaluations also expose the pending-hold indicator.
- The weekly goal evaluates the union of due and validly completed room tasks
  per house-local week at the FIXED 80% target (WP4: no config override);
  house progress carries explicit eligible/onTime/neutral counts, and the
  caller's personal slice derives from the same occurrence facts.

### 5. Weekly Medals (recognition)
- The old activity/combo catalog (`team_anchor`, `flow_keeper`,
  `room_guardian`, `team_player`, `streak_keeper`) retired with the wallet
  and WP4 label cutovers. `MedalService` carries the retained catalog — the
  rhythm tiers (`rhythm_3/6/12` from the valid planned-day history) plus the
  household weekly milestone — awarded read-triggered via `awardMedalIfNew()`
  (race-safe, retained in-app once per user+type+week). WP5 removed external
  medal/title/weekly-goal bells; the weekly receipt is the in-app celebration
  marker only. Notifications use the self-opted-in shared daily digest
  ([pipeline](../../../../engineering/notifications.md), ADR-016), never
  recognition pressure.
- **Frontend celebration contract (WP6, C5g):** sound and confetti are
  personal opt-ins (default off), synced from the preference store into
  the effect module gates on every celebration event;
  `prefers-reduced-motion` always overrides an animation opt-in. The
  default completion feedback stays the accessible undo confirmation —
  see [calm-core-ux](../../../../concepts/calm-core-ux.md).

### 6. Energy Model
- Each task costs 1–5 energy (~5 min per unit).
- Members define daily energy budgets and presence days.
- Owner plan placement checks capacity/availability (`DailyCapacityService`,
  exact minor-unit shares, frozen occurrence energies — a definition edit
  never re-prices recorded work). Completion never consults the evaluator;
  over-limit real work stays recordable.

### 7. Role Titles (single series)
- One retained series: **Family Rhythm**, signal `longestStreak`
  (the valid-history maximum), fixed 3/6/12 thresholds — the effort/wallet
  packs and the config-overridable thresholds retired with WP4 (C3d/Q-075-02 A).
  `currentTitle` is always the highest qualifying tier; `unlocked_titles`
  stays as the first-earned celebration marker (no pack selection).
- Server-authoritative: unlocks derive live from stats, never from the cache.

### 8. Consent-filtered ranking (WP4, ADR-015)
- Opt-in per membership (`ranking#index/consent/withdraw`), owner-only house
  flag default off; consent activates the NEXT Monday (saved with the house
  zone, never retroactive) and withdrawal is immediate.
- Visible entries: effective consents only, on-time ratio over public
  agreed occurrences, exact-rational ties, neutral zero-denominators,
  pending consents unranked. Consent belongs to the membership (departure/
  erasure/delete-on-disable; the house generation watermark survives).

## Backend Services

| Service | Responsibility |
|---------|---------------|
| `CompletionService` | The completion/undo/attribution transaction aggregate (lock-order owner; collaborators: WalletService, RoomReplayService, RhythmService, EffortAllocationService) |
| `CommandService` | Command log: execute-or-replay with the unique (house, UUID) receipt row committed in-transaction |
| `WalletService` | Wallet binding, immutable ledger postings, cached balances, funding-hold |
| `RoomReplayService` | Room journal fold (baseline/delta/manual/reset, clamps, `(effective_at, id)` order); the room cache is a derived projection |
| `RhythmService` | Streak derivation from the valid planned-day history (neutral pending days; corrected-record shrinking; pending-hold indicator) |
| `WeeklyGoalService` | Due∪completed per-house-local-week evaluation + the personal member slice |
| `MedalService` | Rhythm medal evaluation and race-safe awards |
| `RankingService` | Consent lifecycle + exact-rational weekly ranking (ADR-015) |
| `EffortAllocationService` | Exact minor-unit share math |
| `GameLogicService` | Viewer-safe game-state serialization only (legacy reward orchestration retired) |

## Persistence

| Entity/Mapper | Stores |
|---------------|--------|
| `Wallet` / `WalletMapper` | Cached balances + funding hold per member wallet |
| `WalletEntry` / `WalletEntryMapper` | Immutable value ledger (idempotency-unique) |
| `CompletedTask` / `CompletedTaskMapper` | Frozen work evidence + completion versions |
| `WorkShare` / `WorkShareMapper` | Allocation revisions per completion |
| `CompletionRevision` / `CompletionRevisionMapper` | Append-only audit chain |
| `RoomEvent` / `RoomEventMapper` | Room journal (replay authority) |
| `HouseUser` / `HouseUserMapper` | Membership, contributionMinor, streak records, single-series unlocked-title marker |
| `Medal` / `MedalMapper` | Awarded rhythm medals (user, type, week) |
| `MemberPreference` / `MemberPreferenceMapper` | Ranking-consent row per membership (generation, saved Monday + zone) |

## Design Rules

- **Frozen facts, derived rewards.** Work evidence and rates are frozen at
  completion; every reward is an exact derivation; corrections move value, they
  never rewrite history.
- **Totals are conserved.** The reversal union always includes every wallet
  the share rows name (departed members included) — a completion pays out
  exactly the frozen total.
- **Target: no punishment loops.** Pending days are neutral, not misses;
  expired windows become misses only after their deadline.
- **Cooperative > competitive:** shared progress is preferred; capacity-normalized
  fairness is a separate view.
- **Family-friendly language:** no military/aggressive terms.
- **No floating point:** integer minor units end to end; the signed
  safe-integer envelope is enforced.

## When Modifying Gamification

1. Check ADR-014 and the concept docs for the locked WP3 contract.
2. Route every mutation through the `CompletionService` aggregate and the
   `CommandService` receipt seam — never post ledger/journal effects outside
   it.
3. Keep exact minor-unit math in `EffortAllocationService`; enforce the
   safe-integer envelope at every accumulation.
4. Add regression tests for corrections: departed-wallet reversal, latest-
   revision undo, deviation-review gating, corrected-record shrinking.
5. Verify fairness: corrections re-derive recognition from the valid history —
   no cached increment/decrement shortcuts.
