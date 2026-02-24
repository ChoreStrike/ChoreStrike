# Gamification and Rewards

## Goal

Keep the game layer fun and motivating while preserving a supportive family tone.

## Core Loop

1. Task becomes due.
2. Visual room state reflects pending work.
3. User completes task in ChoreWars or task client.
4. System awards positive rewards and updates cooperative progress.

## Reward Language Policy

- Avoid military reward terms.
- Use friendly terms that fit family collaboration.
- Use Team Sparks as the default reward currency name.

## Parent Contribution Balancing

Parents or primary caretakers often complete more chores.
The system should allow configurable balancing so high-load members receive reduced competitive reward currency while still receiving recognition.

### Suggested mechanism

- Separate "contribution score" from "reward currency".
- Contribution score reflects actual completed energy.
- For MVP, do not apply role-based multipliers to Team Sparks.
- If balancing is needed, compare contribution score and Team Sparks in separate views.

## Cooperative Features

- household shared progress bar
- team streaks
- weekly cooperative goals
- positive celebration events on completion

## Role Title Systems (Localized)

Role names must feel friendly and motivating.
Use localized title packs so each household can choose a style.
Each pack includes 3 progressive titles (low/medium/high contribution).

MVP scope: provide 3 built-in family-friendly packs.

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
- Receiver gets a reward boost for that task completion (default: 2x Team Sparks).
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
