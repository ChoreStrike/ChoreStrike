# ADR-020: Absences Without Requests

- **Status:** Accepted (2026-11-10)
- **Deciders:** Operator decisions D-090-03, D-090-17, D-090-21 and D-090-22; source-project positions D-090-07 to D-090-09 (CS-PLAN-090)
- **Amends:** the *Pauses* part of [ADR-013](ADR-013-occurrence-agreements-and-corrections.md#wp2-agreements-pauses-and-capacity-cs-plan-073-delivered-2026-09-24) (child requests, owner decisions, shortening as the only way to end a pause)

## Context

ADR-013 made a pause a small approval process. A child sent a request that had
no effect until an owner decided it; an adult's pause counted at once; every
pause carried a reason code; and the only way to end one early was to shorten
it, which clamps to today and therefore could not remove a pause that had not
started. Members saw only their own entries.

The family trial ended with the complaint that the rules ask for more than a
family needs. The confirmed [simplified-core target picture](../concepts/simplified-core.md)
answers with one sentence: an absence is a period, nothing more. Release A
(CS-PLAN-090) delivers that on the existing `pauses` table, without a table or
column change, because the installation is a retained baseline.

Two properties of the stored data had to be respected:

- What an absence has already excused is a fact other records rest on
  (suspension evidence, rhythm, the weekly goal). Ending an absence must not
  rewrite the past.
- The baseline already holds `cancelled` rows. The departure rule (Q-073-03)
  stamps a departing member's pending requests `cancelled` in place; the
  projection read acknowledged revisions only, so such a row never mattered.

## Decision

1. **Every role is acknowledged directly (D-090-03).** `pause.request` appends
   an `acknowledged` revision for an owner, a member and a child alike. No
   command creates a `requested` revision any more, and a shortening by its
   subject is acknowledged directly as well.
2. **Deleting is a new command, `pause.delete`.** `DELETE /api/v1/pauses/{id}`
   runs under the house lock with `commandId` and `expectedRevision`. The
   subject deletes an own personal absence; the owner deletes any absence,
   a household one included. Everyone else gets 403, a stale revision 409.
   The command appends a `cancelled` revision that keeps the range and
   re-applies the effects. No row is updated or removed.
3. **A cancelled revision governs like a shortening does (D-090-07).** Within
   a pause group the governing revision of a date is the acknowledged or
   cancelled revision with the latest next-local-day boundary at or before
   that date (the day after the decision day in the pause's saved zone). A
   governing cancelled revision means no coverage. So an absence that has not
   started disappears, a running one ends today, and today and earlier stay
   excused by the revision that recognized them. A date before every boundary
   stays with the group's first acknowledged revision; a group without one
   covers nothing.
4. **One reading for every cancelled revision (D-090-21).** The rule does not
   ask why a revision is cancelled. A pending first request that a departure
   cancelled covers nothing, as before. A pending *shortening* that a
   departure cancelled now ends its group's coverage from the local day after
   the departure. This shows only when the member rejoins with the retained
   wallet, and it matches Q-073-03 ("rejoin requires a new pause"). There is
   no marker column and no second rule.
5. **Only what has not ended can be deleted (D-090-22).** Pending, upcoming
   and running absences can be deleted; deleting a request that was still
   pending withdraws it. An absence that has ended has nothing left to free:
   it stays in the list and the command answers 409. Whether it has ended is
   read from the revision that governs today, so a pending shortening does
   not hide it. A child's shortening that an owner declined before this
   decision leaves the absence as it stood: the group stands for its latest
   acknowledged revision, so the absence stays listed and deletable. The list
   tells each viewer through `deletable` whether that viewer may delete an
   entry now, by the same rule the command applies.
6. **Everyone sees the absences; nobody is asked for a reason (D-090-08).**
   The viewer list leaves out groups that stand for a cancelled revision and
   keeps its field whitelist: the stored reason and the retrospective marker
   stay self/owner data. The interface neither asks for nor shows a reason.
   The server still accepts the optional `reasonCode` (default `other`) and
   stored reasons are not rewritten.
7. **What existed before stays decidable (D-090-09, D-090-17).** `approve` and
   `reject` remain for requests stored before this decision. The owner's
   entry for someone else afterwards (`pause.recognize-illness`) stays, under
   neutral wording, until Release B removes misses: until then it is the only
   way to excuse a miss that has already expired.
8. **Digest.** Deleting someone else's absence queues one organizational
   digest change for the subject; deleting a household absence queues it for
   every other member, as any pause decision does. Deleting one's own queues
   nothing.

## Consequences

- A child's absence takes effect at once, where a request did nothing. The
  shared list makes it visible to everyone and the owner can delete it.
- The projection, the persisted suspension facts, capacity, the planner and
  the digest all read coverage through `PauseProjectionService`, so the one
  rule change reaches them together. The pause fingerprint already covers the
  state, so a deletion turns selection tokens and plan previews stale like
  any other pause change.
- Stored pause rows are untouched by the update. The only reread is the one
  of item 4.
- `PUT /api/v1/pauses/{id}` (shorten) has no client any more and stays until
  Release B reworks the surface.
- `cancelled` now has two origins, a departure and a deletion. Code that needs
  to tell them apart would need a new fact; nothing does today.
- The audit event `house.command.committed` lists `pause.delete`.

## Alternatives Considered

- **Deleting rows.** Rejected: it would erase the evidence behind excuses
  that stay, and it breaks the append-only revision history.
- **Shortening to "yesterday" for a future absence.** Rejected: a revision
  whose end lies before its start is not a period, and the clamp to today
  exists to protect recognized excuses.
- **A marker that separates departure cancellations from deletions.** Rejected
  by the operator (D-090-21): it needs a column on a retained baseline for a
  case that shows only after a rejoin and that the departure rule already
  answers.

## Related

- Plan: [CS-PLAN-090](../plans/active/cs-plan-090-simplified-core-release-a.md)
- Timeline and pauses: [ADR-013](ADR-013-occurrence-agreements-and-corrections.md)
- Digest: [ADR-016](ADR-016-opt-in-digest-notifications.md)
- Concept: [`simplified-core.md`](../concepts/simplified-core.md),
  [`calm-core-ux.md`](../concepts/calm-core-ux.md)
- API: [`docs/api/README.md`](../api/README.md) (Pauses)
- Code: `lib/Service/{PauseService,PauseProjectionService}.php`,
  `lib/Controller/PauseController.php`, `src/views/AbsenceView.vue`
