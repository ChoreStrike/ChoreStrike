# ADR-015: Consent-filtered household ranking

- **Status:** Accepted (2026-09-27)
- **Deciders:** Household product decision CS-PLAN-070 WP4 (C5i/C5l/D10b, Q-075-01 A, Q-075-02 A)
- **Supersedes:** the informal "leaderboard from the raw member list" behavior described before the WP3/WP4 cutovers (the pruned test-only `GET /game/leaderboard` and the `getLeaderboard` store getter)

## Context

The pre-WP4 Leaderboard ranked **every** member by their contribution
unconditionally. After the WP3 cutover (money = private wallet, recognition
= valid-history rhythm), a persistent contribution ranking had no honest
meaning — and it exposed work and wallet-adjacent facts of people who never
asked to be compared. Comparison between household members is socially
sensitive: it can shame slower members and reveal private-task behavior.

The household product goal (CS-PLAN-070) kept ONE recognition surface — a
weekly "who got their agreed share done on time" view — but only under
explicit, per-person consent.

## Decision

1. **Opt-in per membership (Q-075-01 A).** The ranking shows exactly the
   members who gave consent, and only what they consented to. Consent is a
   first-class, self-only mutation (children included — titles and ranking
   are identity, not admin): `POST /ranking/consent` /
   `POST /ranking/withdraw`. A house owner can enable or disable the flag
   (`rankingEnabled`, default off) but can never rank someone who did not
   consent.

2. **Consent activates at the next full week, never retroactively.** A new
   consent creates a generation effective next Monday 00:00 in the house
   timezone, saved with that zone. Until then the member appears as
   *pending without a rank* (C5l). Withdrawal removes visible participation
   immediately and invalidates the generation.

3. **Generations are monotonic per house.** The watermark lives on the
   house row (`ranking_generation_seq`, allocated under the house lock) —
   consent rows are deleted on withdrawal/departure/erasure/disable, so a
   max-row heuristic cannot survive. A withdrawn generation is never
   reused.

4. **Consent belongs to the active membership.** Departure deletes the
   consent with the membership row; account erasure purges it; a rejoin
   starts quiet — no same-UID rebinding of consent facts (Q-070-02).
   Disabling the house flag deletes every consent row; re-enabling starts
   everyone unconsented.

5. **One visible metric: the on-time ratio.** Per member, the ratio of
   valid CLASS_AGREED on-time completions to eligible obligations on
   **household-public** occurrences (the public-at-agreement flag frozen on
   the row — later task reclassification never rewrites history). The
   comparison is exact-rational (cross-multiplication of integer counts, no
   floats); equal ratios tie (standard competition ranking); members with a
   zero denominator render neutral and unranked; the week is labeled
   provisional while any window is open. No Coins, speed, contribution or
   private-task data flows into the payload.

6. **The server is the sole ranking authority.** The filter lives in
   `RankingService`, never client-side; reads expose non-consenting members
   and foreign consent states to no one (not even the owner).

## Consequences

- The client Leaderboard renders the server view (entries, ties, pending,
  provisional) or the opt-in/closed state; it never computes a ranking.
- The scoring facts reuse the same occurrence/completion/share reads as the
  rhythm/goal evaluation — no second recurrence implementation.
- A non-consenting member loses nothing else: medals, rhythm and wallet are
  untouched by consent.
- Storage: `chorestrike_member_prefs` (one live row per consenting
  membership, unique per house + membership) plus the house
  `ranking_generation_seq` watermark (Version1032).
- Withdrawal is honest both ways: joining is always "next Monday", leaving
  is always immediate.

## References

- Plan: `docs/plans/done/cs-plan-070-family-rhythm-and-reward-clarification.md` (WP4)
- API contract: [docs/api/README.md](../api/README.md) — "Ranking" section
- Supersession chain: [ADR-011](ADR-011-transactional-completion-and-undo.md),
  [ADR-014](ADR-014-work-accounting-ledger-and-replay.md) (completion/ledger facts the ranking reads)
