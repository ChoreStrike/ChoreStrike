# ADR-014: Work Accounting, Ledger and Replay (Minor Units)

**Status**: Accepted
**Date**: 2026-09-27
**Deciders**: ChoreStrike maintainers (ratified in CS-PLAN-070 WP3)

> **Scope:** WP3 work accounting only. The parent's reserved family-catalog
> **redemption** workflows (reservations, wish handling, fulfillment — WP7)
> are explicitly **pending** and out of scope here; they will extend this
> ledger with their own ADR when implemented.

> **Supersedes (economy):** ADR-004's currency model. Coins are no longer
> integer mints with combo multipliers; wallet value is **exact minor units**
> (100 energy minor = 1 energy, 100 coin minor = 1 Coin, 1000 coin minor per
> frozen energy unit at the fixed 10-Coins rate) booked through an immutable
> ledger. Combo/team-spark multipliers and the boost economy are retired.

## Context

Until WP3 the "reward" of a completed task was a bundle of mutable game
outputs computed from the **live** task definition at read time: integer
coins, a combo multiplier, streak freezes, team sparks, boosts and a Coin
column in the UI. Corrections (the targeted completion undo from ADR-011)
reversed that bundle through stored effect snapshots. This had structural
defects:

- **Mutable-history payouts.** A completed task displayed rewards derived from
  today's definition, not from what was actually agreed and done. Editing the
  energy cost rewrote history.
- **Snapshot reversal drift.** Undo restored JSON effect snapshots; every new
  reward dimension added a new reversal path and a new drift surface, and the
  snapshot was unverifiable against the ledger.
- **No correction closure.** Only the newest completion could be undone;
  crediting the wrong person had no repair path that conserved totals.
- **Room state drift.** Cleanliness and monster effects were applied
  incrementally and clamped, so observed deltas diverged from requested ones
  and undo could not reproduce the pre-completion state.
- **Family-fairness records derived from mutable caches.** Streaks and weekly
  goals were cached snapshots corrected by ad-hoc increment/decrement rules,
  which contradicted each other after partial corrections.

The parent contract (CS-PLAN-070) requires honest work accounting: what was
done and by whom, what it earned under the rates frozen at completion time,
and how later corrections account for it without rewriting history.

## Decision

Work becomes the **immutable fact**; rewards become **deterministic minor-unit
derivations** of that fact.

### Schema (Version1030)

- `completed_tasks` freezes the work evidence at completion:
  `schedule_rev_id`, `occurrence_ordinal`, `work_date`, `work_zone`,
  `occurrence_due`, `total_energy`, `rate_minor` (1000 coin minor per energy
  unit), `completion_version` and `allocation_revision`, plus the immutable
  `completed_at` and `undone_at` markers.
- `work_shares` records one row per participant and allocation revision —
  `wallet_id`, `share_pct`, `energy_minor` and `coin_minor` — so every selected
  revision is auditable (parent D6). The revision records its actor.
- `completion_revs` is the append-only audit chain (completion, attribution,
  undo) with revision counters, actor house-user id, action and bounded
  `reason_code`. A departed (not erased) actor id stays immutable; the read
  model renders a localized placeholder.
- `wallet_entries` is the immutable ledger: one row per wallet, source kind
  (completion / completion_undo / attribution / manual), source id and
  revision, signed `delta_minor`, bounded reason code and actor. The internal
  actor-wallet key permits erasure to anonymize actor references even after
  ordinary departure; the key and actor id are cleared on account erasure.
  The unique `(wallet, source-kind, source-id, source-revision)` key is the
  idempotency identity of the ledger.
- `room_tasks.claimed_source_completion_id` identifies the exact completion
  that established a takeover claim, so undo cannot clear a newer claim by
  the same member.
- `room_events` is the journaled room truth: `baseline` (creation), requested
  `delta` effects from completions, `manual_delta` and absolute `reset` kinds
  (plus `last_done` since CS-PLAN-088 D-088-20: a recorded day that stamps
  `last_cleaned` only, the newest entry per room task recorded after the
  latest reset counting, whatever day it names),
  each with a stable command UUID, `(effective_at, id)` ordering and the saved
  absolute `baseline_*` values for resets. Room cache columns become derived
  projections replayed from the journal.
- `commands` gives every mutation a client-generated UUID, actor and idempotent
  replay seam (CR-SVC-64).

### Money and rates

- One wallet per member (wallet binding created under lock; departed members
  retain their wallet and its balance).
- Exact integer math in minor units end to end (`E * P` energy minor,
  `10 * E * P` coin minor); PHP and JavaScript operate inside the signed
  `Number.MAX_SAFE_INTEGER` envelope, enforced at every accumulation boundary.
- The rate is frozen at completion time (`rate_minor` on the completion);
  later rate changes never rewrite history.
- A completion pays out exactly the frozen total; attribution corrections move
  value between wallets with **exact union deltas** — the union is keyed by
  **wallet id from the share rows**, so a departed participant's retained
  wallet is always part of the reversal (no payout beyond the frozen total).
- Undo reverses the **latest selected allocation revision** once; repeating a
  command UUID replays the stored outcome without a second effect.

### Corrections

- `PUT /completions/{id}/attribution` (receipt-protected: command UUID,
  `expectedVersion`, non-empty participants, explicit `allocationReviewed`
  confirmation of any deviation from the recorded allocation, bounded optional
  `reasonCode`) rewrites the selected allocation. Only the completer or a
  house owner may correct; participation alone is no authority.
- Attribution never touches the frozen work facts (time, zone, occurrence,
  total energy) and never writes room events; personal recognition recomputes
  from the corrected valid history.
- Undo of an attributed completion reverses the corrected revision, so a
  completion always pays out exactly once.

### Recognition and projections

- Streaks recompute from the valid planned-day history (the rhythm evaluator
  reads occurrence completion validity, never cached deltas); a correction may
  shrink the longest record only to the corrected valid-history maximum.
- The weekly goal counts agreed due occurrences by their planned house-local
  week, with timely valid work inside each window. Late catch-up cannot move
  an opportunity into the week of completion; recognized pauses are neutral.
  Open weeks remain provisional. The timeline job revisits saved planned
  weeks after their windows close and claims a unique per-house/per-week
  notification receipt before attempting the household bell, so retries and
  later corrections do not dispatch a duplicate.
- The client renders minor units with exact, trimmed decimal formatting (no
  Coin column; the leaderboard/fairness contribution reads contribution minor).

### Client contract

- Authorized completion/history payloads carry frozen work facts, the
  selected allocation, a viewer-scoped `canCorrect` flag and the caller's own
  wallet totals. Viewers without task visibility receive only the redacted
  household event, never its frozen effort or work-share identities.
- The activity feed exposes authorized older-completion correction and undo.
  The participation form reuses the recorded split; the corrector need not
  participate. A 409 keeps the draft, shows the current allocation and requires
  explicit review before submitting against its newer version. An uncertain
  response retries the original receipt UUID and payload first.
- No combo/celebration flags, no boost surfaces, no Coin integers anywhere.

## Consequences

### Positive
- History is honest: frozen facts, exact derivations, an append-only audit and
  ledger chain that can be reconciled to the cent at any time.
- Corrections close: any completion can be corrected by authority, totals are
  conserved by construction, and replayed commands are stable no-ops.
- Recognition records derive from the same valid history the UI shows — no
  contradictory cache corrections.
- Client races stay safe: receipt + version checks make stale submissions
  conflict instead of silently crediting another target.

### Negative
- The completion coordinator grows (transaction choreography, lock order,
  command seam, three correction aggregates) — accepted as the one visible
  ordered aggregate with documented collaborators.
- Every new reward dimension must be modeled as derived evidence plus a
  correction path up front; snapshot-style reversal is no longer available.

### Neutral
- Boosts, combo multipliers and streak freezes are retired with the legacy
  economy; the minigames stay play-only.
- Wallet balance display never falls back to summing the ledger at read time;
  the cached balance is maintained transactionally and the journal remains the
  audit trail.

## Alternatives Considered

- **Keep the effect-snapshot reversal (ADR-011 style) and add attribution on
  top.** Rejected: snapshots drift from derived reads, cannot express
  cross-wallet moves, and every new dimension would double the reversal paths.
- **Mutable reward rows with in-place updates.** Rejected: rewrites history,
  breaks auditability and cannot conserve totals under concurrency.
- **Float/decimal currency.** Rejected: exact integer minor units keep PHP and
  JS arithmetic identical and overflow-checkable inside the safe-integer
  envelope.

## References

- CS-PLAN-070 (parent work-accounting contract, sections 3.4-3.7, C1-C3)
- `docs/plans/done/cs-plan-071-wp0-write-identity-foundation.md` (ledger schema)
- `docs/plans/done/cs-plan-074-wp3-work-accounting-and-replay.md`
  (implementation plan and §4 evidence matrix)
- ADR-011 (transactional completion and undo — the receipt seam it introduced
  is retained; the snapshot reversal it described is superseded by this ADR)
- ADR-013 (occurrence agreements — the occurrence identity attributed work
  freezes)
