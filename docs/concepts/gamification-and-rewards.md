# Gamification and Rewards

## Goal

Keep the game layer fun and motivating while preserving a supportive family tone.

## Core Loop

1. Task becomes due.
2. Visual room state reflects pending work.
3. User completes task in ChoreStrike or task client.
4. System awards positive rewards and updates cooperative progress.

## Reward Language Policy

- Avoid military reward terms.
- Use friendly terms that fit family collaboration.
- Use **Coins** as the reward currency (shipped). Tasks carry a tier
  (Bronze/Silver/Gold) multiplier and each member has a personal coin multiplier
  for fairness. Global defaults are read through `RewardConfigService`;
  member balancing uses the persisted member multiplier. The former
  reward-config HTTP routes were pruned, not shipped as an admin API.

## Parent Contribution Balancing

Parents or primary caretakers often complete more chores.
The system should allow configurable balancing so high-load members receive reduced competitive reward currency while still receiving recognition.

### Suggested mechanism

- Separate "contribution score" from "reward currency".
- Contribution score reflects actual completed energy.
- For MVP, do not apply role-based multipliers to coins.
- If balancing is needed, compare contribution score and coins in separate views.

## Cooperative Features

- household shared progress bar
- team streaks
- weekly cooperative goals
- positive celebration events on completion

## Implemented Reward Mechanics

> These are the shipped, config-driven reward tokens (keys live in
> `RewardConfigService`; defaults shown). They are policy, not hardcoded constants.

| Mechanic | Behaviour | Config key (default) |
|----------|-----------|----------------------|
| **Coins** (renewable currency) | `energy_cost × coin_base_per_energy × tier_multiplier × personal_multiplier × early_multiplier`; **×combo_multiplier on personal combo** (`GameLogicService::calculateCoins`). | `coin_base_per_energy` (10), `tier_{bronze,silver,gold}_multiplier` (1.0/1.5/2.0), `early_bonus_multiplier` (1.5), `early_bonus_threshold_days` (21), `combo_multiplier` (2.0) |
| **Contribution score** | Net credited work (the task's `energy_cost`); undo subtracts its recorded delta, with no passive penalty; leaderboard + `home_helpers` title signal. | — |
| **Personal combo** | Rapid consecutive completions by the same user double coins (configurable multiplier). *Not* the cooperative ×1.5 multi-user concept from the foundational design — see Decision Point B (CS-PLAN-010). | `combo_multiplier` (2.0) |
| **Streak freezes** | Protect a streak on a missed available day; consumed by completion and `StreakFreezeJob`; restocked weekly by `StreakRestockJob`. Job bodies live in `StreakFreezeService` / `StreakRestockService` (thin job delegates; triggerable with an injected date via `occ chorestrike:trigger-jobs`). | `streak_freezes_per_week` (1), `streak_freeze_cap` (2) |
| **Combo bonus freeze** | A combo reaching the threshold awards one bonus freeze (respecting the cap). | `combo_bonus_freeze_threshold` (5) |
| **Teamwork bonus** | Multi-assignee task completion grants a shared bonus, capped as a % of the base reward; disabled per house if needed. | `teamwork_bonus_enabled` (true), `teamwork_bonus_max_pct` (50) |
| **Weekly household goal** | Cooperative progress target; on first achievement each week the household is notified. | `weekly_goal_target_percent` (80) |
| **Role titles** | Progression overlay (3 packs × 3 tiers); thresholds come from the server config/catalog. | `titles_enabled` (true), `title_threshold_{pack}_{tier}` (catalog defaults) |

### Weekly Medals

> **Award reality (CS-PLAN-012, updated by CS-PLAN-067 WP8):** the medal enum
> (`lib/Db/Medal.php`) catalogues `team_anchor`, `flow_keeper`, `room_guardian`,
> `team_player`, `streak_keeper`. **All five are awarded** in the live
> `MedalService::checkAndAwardMedals()` flow via `awardMedalIfNew()`
> (idempotent, race-safe, notifies once per user+type+week — the cluster was
> extracted out of `GameLogicService` in WP8):
> - `team_anchor` — 10+ task completions in the week
> - `streak_keeper` — 7+ day streak
> - `flow_keeper` — 4+ distinct active days in the week
> - `room_guardian` — 5+ completions in a single room (concentration)
> - `team_player` — 3+ combo bonuses in the week (`comboCount` aggregation)
>
> Integration coverage: `GameControllerIntegrationTest` pins the award from
> real persisted completions plus the repeat-read idempotence.

## Role Title Systems (Localized)

> **Status: Shipped (CS-PLAN-006).** Three title packs are live as a
> progression-overlay on top of the existing stats. Titles react to existing
> progression signals only — no new core loop, currency, or completion path.

### Arcade Coin Sink (shipped, CS-PLAN-068 §3.6 documentation)

Coins are also spent on Snake boosts in the household arcade. The retained
2048/Memory component prototypes do not expand the server's `snake` score
whitelist. Prices live **server-side only** (`MinigameService::BOOST_COSTS`
via `GET /minigames/boosts`); the shop disables purchases without the
authoritative catalog instead of guessing, the debit is an atomic
`POST /minigames/boosts/{id}/purchase` with the exact catalog price, and
score saves are capped (score ≤ 5000, 100 saves per member and
house-local day). The awarded coins for a game are server-computed
(`floor(score / 10)`), never client-supplied. See the Minigames section in
[`../api/README.md`](../api/README.md).

Role names must feel friendly and motivating.
Use localized title packs so each household can choose a style.
Each pack includes 3 progressive titles (low/medium/high contribution).

MVP scope: provide 3 built-in family-friendly packs.

Implementation (`lib/Titles/TitleCatalog.php` + `lib/Service/TitleService.php`):

| Pack | Signal | Tier thresholds (defaults) |
|------|--------|----------------------------|
| Home Helpers | `contributionScore` | 50 / 250 / 1000 |
| Team Energy | `longestStreak` | 7 / 30 / 90 |
| Cozy Home | `coins` | 500 / 2500 / 10000 |

- Server-authoritative unlocks: the client only displays what the server returns.
- Each pack's signal is an **actively-maintained** stat: `contributionScore`
  and `coins` are incremented on every task completion, and `longestStreak`
  is tracked from the effective (post-freeze) streak in
  `GameLogicService::handleTaskCompletion`.
- Thresholds use `RewardConfigService` keys (`title_threshold_{pack}_{tier}`).
  There is no live reward-config HTTP API; API consumers must not rely on
  the retired configuration routes.
- The user picks one active pack; within that pack the highest unlocked tier
  is shown. Switching packs is free (identity, not a consumable).
- Unlock moments reuse the celebration layer (`chorestrike:title-unlocked`
  event → confetti + milestone SFX) and fire a best-effort Nextcloud
  notification. The whole subsystem can be disabled via the
  `titles_enabled` config flag.

### Pack A: Home Helpers (Neutral, Family-Friendly)

- EN: Helper, Organizer, Care Captain
- DE: Helfer, Organisator, Fürsorge-Captain

### Pack B: Team Energy (Cooperative, Positive)

- EN: Spark Starter, Flow Keeper, Team Anchor
- DE: Funkenstarter, Flow-Hüter, Team-Anker

### Pack C: Cozy Home (Warm, Inviting)

- EN: Cozy Buddy, Room Ranger, Home Guardian
- DE: Wohlfühl-Buddy, Raum-Ranger, Zuhause-Hüter

### Post-MVP Extension

- Additional built-in packs can be added after MVP.
- Custom title packs are planned for post-MVP once stable pack management APIs exist.
- All future packs must follow family-friendly, non-aggressive naming policy.

## Streaks and Rewards

Streaks are important motivation mechanics.

### Personal streak rule

- A streak day counts if the member completes at least one eligible task on an available day.
- Absence days must never break a streak and must not count as missed days.
- Streak checks must use configured presence days from scheduling settings.

### Streak milestone rewards

- Milestones can grant playful one-time tools.
- Example: after an X-day streak, the member receives one "Task Pass".

### Task Pass (gag mechanic)

- User can move one assigned task to another available member.
- Receiver gets a reward boost for that task completion (default: 2x coins).
- System should cap abuse with configurable limits (for example weekly usage cap).

## Gamification Flexibility

Gamification should stay intentionally open for future ideas.

- Keep core loop stable (tasks, completion, positive reward).
- Treat reward formulas and event rules as configurable policy, not hardcoded constants.
- Allow feature flags for experimental mechanics.
- Document each new mechanic with fairness and tone checks.

## Constraints

- No punishment-only loops.
- No hard lose state.
- Keep UI text neutral and inclusive.

## Open Decisions

- Whether children can validate completed tasks for each other.
- Default streak milestone thresholds (for example 3/7/14 days).
- Default Task Pass limits per week.
