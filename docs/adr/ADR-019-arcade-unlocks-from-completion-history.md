# ADR-019: Arcade Unlocks from Completion History

- **Status:** Accepted (2026-10-17), amended 2026-11-14
- **Deciders:** Operator decisions D-086-01, D-086-02, D-086-05 and D-086-06 (CS-PLAN-086);
  the amendment: D-090-63, D-090-64 and D-090-66 (CS-PLAN-090)
- **Amends:** CS-PLAN-070 D7d (separate arcade) and extends its D11 first slice (Snake only)

> **Amendment (2026-11-14, CS-PLAN-090 WP-J):** the thresholds became an owner
> setting (decision 4), an owner can reset one person's progress (decision 8),
> so an unlock lasts until such a reset (decision 1), and two settings are
> stored, neither of them a fact (decision 3). Decision 2 names the count
> method correctly.

## Context

CS-PLAN-070 separated the arcade from chore value on purpose. D7d keeps "an
optional, separate arcade with its own highscores" that neither mints nor
spends Coins and forbids another family currency; D11 limited the first slice
to Snake; RF-070-09 removed the mixed arcade/chore value of the old boost
shop. The arcade is optional per member (`arcadeVisible`) and stays outside
My Day and the Dashboard (`calm-core-ux.md`).

The operator now wants a small household-themed game collection in the
spirit of learning apps: members finish chores and thereby unlock short games
with per-game highscores (CS-PLAN-086). That couples arcade **access** to
chore history, which D7d did not allow. Three properties had to hold:

- The coupling must not turn games into a currency, a reward or a daily
  obligation, and it must not open a path to farm or game the work ledger.
- It must follow corrections: the ADR-014 ledger can undo a completion or
  re-attribute it to other people, and a derived state must not keep credit
  for work that no longer counts.
- The `0.8.0-alpha.1` installation is a protected retained baseline; a new
  schema step would be the first migration on it and needs a populated
  upgrade proof that does not exist yet.

## Decision

1. **Access is a milestone of valid history (D-086-01 = B, D-090-63).** A game
   unlocks once the member's count of finished chores reaches the game's
   threshold. The count runs over the whole history, or since the member's
   last reset (decision 8). There is no daily opening, no play streak and no
   expiry: an unlock lasts until an owner resets the member.
2. **The count is the ledger's attribution.** A chore counts for every member
   holding a share in the completion's **selected** allocation revision while
   the completion is **valid** (`undone_at IS NULL`) — the attribution that
   `WorkShareMapper::countValidSelectedByWallet()` counts. Helpers count; an undone
   completion and a share revised away do not. Each completion counts once per
   member (`COUNT(DISTINCT completion_id)`).
3. **No unlock state is stored (D-090-64).** The lock state is derived on
   every read (`ArcadeAccessService`); there are no unlock rows, tokens or
   balances to migrate or replay. Because the state is derived, an undo can
   re-lock a game until the count is reached again. Two settings are stored,
   and neither is a fact: the owner's thresholds (decision 4) and a member's
   start point (decision 8). An instance that never stored them reads the
   defaults, so they need no schema step and no migration.
4. **Thresholds are an owner setting over a constant default (D-086-06,
   D-090-66).** `ArcadeCatalog` holds the default finished-chore threshold
   per game next to its score rules: Spot Sprint 0, Sock Match 3, Crumb Snake
   10, Tile Breaker 25, Dust Runner 50. An owner overrides them with a whole
   number from 0 to 500 per game through `PUT /api/v1/house/arcade-thresholds`,
   which names every game, is owner-only (checked in the controller and the
   service), audited, and last write wins. The values are the typed
   `arcade_thresholds` array in app config (one house per instance); a game
   without a stored value reads its default. A threshold can be 1, so the
   progress copy ("Unlocks at 10 finished chores · 7 to go") has a singular
   form.
5. **The server enforces the lock.** A score for a locked game is a 403
   `MINIGAME_LOCKED`; the check runs inside the score transaction under the
   house-row lock, with the current thresholds and the count since the start
   point. Reads stay open, so a locked game's leaderboard remains readable.
6. **Owners can switch the gate off (D-086-02).** The household flag is the
   typed `arcade_gate_enabled` key in the `RewardConfigService` registry
   (default on). There is one house per instance, so app config needs no
   schema step. `PUT /api/v1/house/arcade-gate` is its only HTTP writer:
   owner-only (checked in the controller and the service), boolean-validated,
   idempotent by value, last write wins. Switched off, every game is open for
   every member. The flag travels in every house payload as
   `arcadeGateEnabled` and in the catalog payload as `gateEnabled`.
7. **The economy separation of D7d stays.** The arcade never mints, spends or
   displays Coins, wallet balances, contribution, medals or household ranking,
   and there is no cross-game score. The wallet id is read only as the share
   attribution key.
8. **An owner resets one member for all games at once (D-090-63, D-090-66).**
   `POST /api/v1/house/members/{userId}/arcade-reset` is owner-only, audited
   and asked for after a confirmation in the client. The member's start point
   becomes the highest committed completion id at that moment, and from then
   on only completions above it count, so locked games unlock anew and an undo
   or a correction of earlier work changes nothing. The reset holds the
   house-row lock like the score save, so the two never interleave. A chore
   whose transaction overlaps the reset may land on either side of the start
   point, and none counts twice. Highscores stay (D-089-05). The start point
   and the time of the reset live in the member's per-user app config
   (`IUserConfig`, the lazy `arcade_reset` key) and are read past its cache.
   Nextcloud deletes them with the account; account erasure also deletes them
   explicitly, and the test-data reset clears them for everyone. The owner
   reads each member's count since their last reset and its time through
   `GET /api/v1/house/arcade-progress`; the activity list already shows the
   same work in another form.

## Consequences

- Members see progress toward the next game as a neutral line on its card;
  there are no countdowns, notifications or nudges. Locked games keep their
  leaderboards, so a member who re-locks a game after an undo still sees the
  board.
- A completion, an undo or a re-attribution changes arcade access on the next
  catalog read without any write to arcade state. Erasure, the retained-data
  boundary and the ADR-014 replay are unaffected.
- The read cost is one grouped count per catalog read and per score save,
  plus one uncached read of the member's start point. The owner's progress
  list stays one grouped count, with each wallet's own lower bound, and one
  start-point read for all members. The count has no time bound and grows
  with the history; the existing share/completion indexes carry it today,
  and a cache would be a later, measured optimisation.
- A reset only moves the start point: it writes no completion, share,
  wallet or score row, and a departed or erased member's history is
  unaffected.
- A newly invited member starts with the games whose threshold is 0 (by
  default Spot Sprint only).
  Owners who do not want the coupling switch the gate off; members who do not
  want the arcade hide it.
- Adding a game updates the catalog in both places (server `ArcadeCatalog`,
  client registry); both test suites pin the id list.

## Related

- Plan: [CS-PLAN-086](../plans/done/cs-plan-086-household-arcade-and-chore-unlocks.md);
  amendment: [CS-PLAN-090](../plans/done/cs-plan-090-simplified-core-release-a.md) WP-J
- Ledger attribution: [ADR-014](ADR-014-work-accounting-ledger-and-replay.md)
- Concept: [`gamification-and-rewards.md`](../concepts/gamification-and-rewards.md),
  [`calm-core-ux.md`](../concepts/calm-core-ux.md)
- API: [`docs/api/README.md`](../api/README.md) (Minigames, House)
