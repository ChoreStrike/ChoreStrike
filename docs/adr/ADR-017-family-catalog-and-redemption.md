# ADR-017: Family Reward Catalog and Requested Redemption (C4)

- **Status:** Accepted (2026-10-01)
- **Deciders:** Household product decision CS-PLAN-070 WP7 (C4, C3.10/C3.11; CS-PLAN-078)
- **Implements:** the approved catalog lifecycle in [gamification-and-rewards.md §3](../concepts/gamification-and-rewards.md) on top of [ADR-014](ADR-014-work-accounting-ledger-and-replay.md)'s wallet/ledger

## Context

The work-accounting cutover (WP3/WP4) left one deliberate gap: a household
could earn exact minor units but never SPEND them on an agreed reward — the
boost shop and Coin-era reward surfaces were retired. The approved product
decision C4 fills that gap with a family **reward catalog**: owners
administer wish items; members request them; fulfillment debits the
requester's wallet exactly once. Two forces shape the design:

- **Family money is a promise, not a balance check.** A request reserves
  real value (`reserved_minor`) at creation; approval and delivery are
  separate, attested steps; the funding state of a wallet can collapse at
  any moment (legitimate negative credits). Any design that lets delivery
  or cancellation depend on "still enough money" in more than the aggregate
  sense creates deadlocks and unfair states.
- **It is a family surface.** Children request, owners decide; roles
  change; people leave and accounts get erased. Every piece of personal
  data the flow creates must survive (or disappear) under the same privacy
  rules as the rest of the app, and the audit trail must stay legible for
  the household without becoming a personal-data liability.

## Decision

1. **The household gate is owner-controlled and default off.** One narrow,
   versioned writer (`PUT /house/catalog`, house `stateVersion` contract);
   the shared house-settings writer never touches the flag. While off, the
   active catalog is an explicit empty state and new requests are denied —
   but reads, owner processing and existing requests keep working, and
   personal visibility preferences are never reset by a toggle.

2. **Catalog items are promises, archived never deleted.** Items carry a
   plain-text title/description (family-authored CONTENT, escaped at
   presentation), a positive `price_minor`, an archive flag and an
   optimistic version. Once a request exists, terms are frozen into the
   request — repricing an item never reprices history, and a request is
   only created against the exact displayed terms under lock (drift is
   `409`, never a silently reserved different price).

3. **One request lifecycle with exactly three money moments.** Reserve at
   creation (`A >= P`, `R += P`, no ledger row), settle only at separately
   confirmed delivery (`B -= P` and `R -= P` together, one
   `reward_settlement` ledger entry keyed by request id + effect revision),
   refund only an owner-attested false fulfillment (the ORIGINAL debit,
   verified against the server's stored entry, exactly once via
   `reward_refund`). Rejection/cancellation release the reservation only.
   Every effect is one `CommandService` command with a WP7 under-lock
   authorization guard that re-checks fresh identity, visibility and
   current action authority on execution AND replay.

4. **Funding hold is derived, orthogonal state.** `fundingHold =
   booked < reserved` per wallet — never stored on the request. It blocks
   ALL unfulfilled settlements and new requests for that wallet while
   approval/rejection/cancellation stay possible; time, archive and gate
   disablement never expire or reopen a request. A hold that appears after
   an approval defers fulfillment; it never invalidates the approval.

5. **Roles govern authority, provenance makes it traceable.** Children
   request (owner approves/fulfills); adults request and explicitly
   self-approve/self-fulfill; owners act for anyone on the administrative
   path and are the only correction authority. Becoming a child rebases
   every unfulfilled request to pending and clears approval provenance;
   other role transitions preserve approvals and only extend the audit
   trail; a no-op role write invalidates nothing (`role_revision` on the
   membership advances only on real changes).

6. **Identity is the wallet; the audit trail is identity rows.** Requests
   and events reference the requester/approver by wallet id + membership id
   (nullable), never a raw UID/display-name snapshot. The append-only
   `request_events` trail (unique request + revision) is retained until
   authorized privacy erasure, which purges the person's requests/events/
   wishes, removes queued digest references from ALL recipients and
   anonymizes their references on other retained records — wallet-id
   provenance keeps working after departure.

7. **Digest participation follows the C5 model.** Request effects queue
   identity-only `reward_request` digest rows for the active requester and
   current owners (minus the actor) when their organizational opt-in is
   already enabled — no backfill, no repeated pending-pressure rows.

## Consequences

### Positive
- Spending is a household decision with a complete, replay-safe audit
  trail; money effects are exactly-once and reconcile by construction
  (`B` equals the ledger sum, `R` equals the unfulfilled request sum).
- The catalog can be hidden, repriced and archived freely without ever
  invalidating agreed promises.
- Departure, role changes and erasure compose with the existing WP2–WP5
  invariants instead of inventing parallel rules.

### Negative
- Three money moments mean three command surfaces per request — more
  payload discipline for clients (mitigated by the shared receipt/version
  contract and the WP8 store).
- The owner carries the correction authority burden for children's
  requests (no child self-fulfillment) — a deliberate family-safety trade.

### Neutral
- The reward UI (WP8) is a later package; the browser contract is pinned
  by the API specs in the meantime.

## Alternatives Considered

- **Immediate settlement on request (spend-on-click).** Rejected: it
  removes the household decision step C4 exists for, and refunds for
  undelivered wishes would become the common case instead of the exception.
- **Funding hold stored per request.** Rejected: derivable state (`B < R`)
  stored twice invites drift; the hold is a wallet fact, not a request fact.
- **Owner-only catalog administration through the shared house-settings
  writer.** Rejected: the gate needs its own optimistic-version contract;
  folding it into `PUT /house` would force unrelated settings saves to
  carry the version.

## References

- [rewards.md](../api/rewards.md) — the endpoint contract
- [gamification-and-rewards.md §3](../concepts/gamification-and-rewards.md) — the approved lifecycle
- [ADR-014](ADR-014-work-accounting-ledger-and-replay.md) — wallet, ledger and replay
- CS-PLAN-078 (WP7 implementation plan)
