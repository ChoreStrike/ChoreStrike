# Reward Catalog & Request API (WP7)

> CS-PLAN-070 WP7 / CS-PLAN-078. The family reward catalog (owner-administered
> wishes) and the approved C4 request lifecycle. Concept:
> [gamification-and-rewards.md §3](../concepts/gamification-and-rewards.md) —
> decision record: [ADR-017](../adr/ADR-017-family-catalog-and-redemption.md).

## Household gate

The reward surface is behind a **household gate** (`houses.catalog_enabled`,
owner-controlled, **default off**):

- `GET /house` transports the flag (`catalogEnabled`) for every member.
- While off: the active catalog scope answers the explicit disabled state
  (empty list), new requests are denied (`409`), but reads of existing
  requests and ALL owner processing (approve/reject/cancel/fulfill/correct)
  keep working. Planning/recognition are unaffected.
- The only gate writer is `PUT /house/catalog` below — `PUT /house` never
  touches it, and toggling never resets personal visibility preferences or
  existing requests.

## Money

Exact integer **minor units** (`priceMinor=1250` = 12.50 Coins; 100 minor =
1 Coin). Floats and numeric strings are rejected at the boundary (`400`).
Requests freeze the displayed terms: title, description and price are
immutable once created — live catalog changes never reprice an existing
request.

## Idempotency & versions

Every mutation is a `CommandService` command: the client generates one RFC
4122 v4 `commandId` per logical command and retains it across uncertain
retries — an identical retry replays the stored outcome with zero repeated
effects and returns the **fresh current DTO** (the stored identity is never
stale output). A reused UUID with changed input conflicts (`409`).
Resource mutations additionally carry `expectedVersion` (the DTO's
`version`; the gate uses the house `stateVersion`). The under-lock
authorization seam ([ADR-014](../adr/ADR-014-work-accounting-ledger-and-replay.md))
re-checks fresh identity, visibility and current action authority on
execution AND replay — a demoted owner or departed requester can never
replay an old receipt into an effect.

## Error semantics (house standard)

- `400 VALIDATION_ERROR` — malformed payload, unknown field, non-integer
  price, control characters.
- `404 RESOURCE_NOT_FOUND` — nonexistent/foreign/invisible id
  (anti-enumeration: a foreign member cannot confirm a request exists).
- `403 FORBIDDEN` — visible-resource role denial (e.g. `scope=admin` as
  non-owner, a member acting on a foreign request they can administratively
  see).
- `409 CONFLICT` — stale version, closed transition, displayed-terms drift,
  insufficient funds (`A < P`), aggregate funding hold (`B < R`).

---

## Catalog gate

### PUT /house/catalog (owner only)

Narrow, versioned writer: `{ "catalogEnabled": bool, "expectedVersion": int,
"commandId": "uuid" }`. `expectedVersion` is the house `stateVersion`.
Response: the refreshed house payload (`data.catalogEnabled`,
`data.stateVersion`).

## Catalog

### GET /rewards?scope=&cursor=&limit=

- `scope=active` (default): active (non-archived) items only while the gate
  is enabled; otherwise `{ "catalogEnabled": false, "items": [] }`.
- `scope=admin`: **owner only** (`403` otherwise) — includes archived items
  even while the gate is off (prepare/administer while hidden).
- Bounded stable-cursor paging: `limit` (default 50, max 100), `cursor`
  (items with `id > cursor`, ascending).

**Item DTO (whitelist):** `{ "id", "title", "description", "priceMinor",
"archived", "version" }` — no actor references.

### POST /rewards (owner only)

`{ "title" (1–120 chars), "description"? (≤2000), "priceMinor" (positive
int), "commandId" }`. Control characters are rejected; unknown fields are
rejected. Response: the created item DTO (version `0`).

### PUT /rewards/{id} (owner only)

`{ "title"?, "description"?, "priceMinor"?, "archived"?, "expectedVersion",
"commandId" }` — whitelisted fields only; unknown fields `400`; stale
`expectedVersion` `409`. Archiving keeps the promised terms alive forever
(items are never deleted; the route does not exist). Response: the updated
item DTO.

---

## Requests

### GET /reward-requests?scope=&state=&cursor=&limit=

- `scope=mine` (default): the caller's own requests (newest first).
- `scope=house`: **owner only** — the household administration list.
- `state` (optional): `pending|approved|fulfilled|rejected|cancelled|
  corrected`. Reads stay available while the catalog is hidden or disabled.

**Request DTO:**

```json
{
  "id": 5, "itemId": 1,
  "requester": { "houseUserId": 7, "displayName": "Alice", "isFormerMember": false },
  "title": "Zoo day", "description": "Popcorn included", "priceMinor": 700,
  "state": "pending", "version": 0,
  "createdAt": "2026-10-01T10:00:00+00:00", "updatedAt": "…",
  "allowedActions": ["cancel"],
  "fundingHold": false,
  "wallet": { "bookedMinor": 3000, "reservedMinor": 700, "availableMinor": 2300, "fundingHold": false }
}
```

- `requester` — the authorized requester reference; a departed/erased actor
  renders as the placeholder `{ houseUserId: null, displayName: null,
  isFormerMember: true }` (history must not recreate access or expose
  identifiers).
- `allowedActions` — current-role hints for the VIEWER (never authorization):
  `approve`/`reject` (owner on a child's pending), `cancel` (requester or
  owner on unfulfilled), `fulfill` (owner, or an adult self), `correct`
  (owner on fulfilled).
- `fundingHold` — the requester wallet's derived hold (`booked <
  reserved`), part of the request's administrability.
- `wallet` — the caller's OWN `B/R/A` totals, only on own requests; owners
  acting for another person receive no target wallet totals.

### GET /reward-requests/{id}

One request plus `audit` — the authorized event history (`revision`,
`action`, `oldState`, `newState`, `actor` reference/placeholder,
`oldRoleRevision`, `newRoleRevision`, `reasonCode`, `financialEntryId`,
`createdAt`). Requester or owner only; foreign members get `404`.

### POST /reward-requests

`{ "itemId", "itemVersion", "priceMinor", "commandId" }` — the displayed
terms are validated against the live unarchived item under lock and frozen;
drift is `409`, never a different silently reserved price. Requires the
enabled gate, coverage `A >= P` (`409` otherwise) and reserves exactly `P`
(`R += P`). Role decides the initial state: child self → `pending`; adult
or owner self → `approved` with current-role self-approval provenance.

### POST /reward-requests/{id}/approve

`{ "commandId", "expectedVersion" }`. Owner approves a current child's
pending request; a current **adult requester may explicitly self-approve**.
An owner never approves a different adult's pending request (`403`). No
`B/R` change; the approval records the requester's current role revision.

### POST /reward-requests/{id}/reject

`{ "commandId", "expectedVersion", "reasonCode"? }`. Current owner rejects
the current child's pending request. `R -= P` only — no booked credit.

### POST /reward-requests/{id}/cancel

`{ "commandId", "expectedVersion", "reasonCode"? }`. Requester (including a
child) or current owner cancels an unfulfilled request — possible even
underfunded. `R -= P` only.

### POST /reward-requests/{id}/fulfill

`{ "commandId", "expectedVersion", "delivered": true }` — the explicit
delivery confirmation is mandatory. Current owner for a child; an adult
requester or current owner for an adult. Requires the current approval and
aggregate coverage `B >= R`; `B -= P` and `R -= P` together through one
`reward_settlement` ledger entry keyed by request id + effect revision. The
stored `settlementEntryId` rides back in the DTO.

### POST /reward-requests/{id}/correct

`{ "commandId", "expectedVersion", "notDelivered": true, "reasonCode",
"settlementEntryId" }`. Owner-attested false fulfillment: the server
verifies the supplied reference against the ORIGINAL settlement entry
(wallet, source, exact `-P` debit) and refunds exactly that amount once
through a `reward_refund` entry — never the current item price and never a
client value. Terminal; no new reservation.

`reasonCode` values are a closed set: `not_needed`, `changed_mind`,
`unavailable`, `duplicate`, `not_delivered`, `other` (bounded codes, never
personal free text).

## Lifecycle guarantees (C4)

- **Funding hold is orthogonal.** `fundingHold` (`B < R` per wallet) blocks
  ALL unfulfilled settlements and new requests for that wallet, but
  approval/rejection/cancellation stay possible; a funding collapse after
  approval never expires or reopens the request.
- **Role changes.** Becoming a child moves every unfulfilled request to
  pending and clears the approval provenance (fresh owner approval
  required); other role transitions preserve valid approvals and only
  extend the audit trail. A no-op role write invalidates nothing.
- **Departure.** Ordinary departure cancels every unfulfilled request and
  releases its reservation before access ends; booked value and terminal
  history are preserved for the retained wallet.
- **Erasure.** Authorized erasure purges the person's requests/events/
  wishes, removes queued digest references from ALL recipients and
  anonymizes their actor references on other retained records (wallet-id
  provenance keeps working after departure).
- **Digest.** Request effects queue identity-only `reward_request` digest
  rows for the active requester and current owners (minus the actor) when
  their organizational opt-in is already enabled — see
  [notifications.md](../engineering/notifications.md).
