# Architecture Decision Records (ADR)

This directory contains Architecture Decision Records for ChoreStrike.

## What is an ADR?

An Architecture Decision Record (ADR) captures an important architectural decision made along with its context and consequences.

## Format

Each ADR should follow this structure:

```markdown
# ADR-XXX: Title

**Status**: [Proposed | Accepted | Deprecated | Superseded]  
**Date**: YYYY-MM-DD  
**Deciders**: [Names or roles]

## Context

What is the issue we're seeing that is motivating this decision or change?

## Decision

What is the change that we're proposing and/or doing?

## Consequences

What becomes easier or more difficult to do because of this change?

### Positive
- Benefit 1
- Benefit 2

### Negative
- Drawback 1
- Drawback 2

### Neutral
- Other consideration 1

## Alternatives Considered

What other options were considered and why were they rejected?

## References

- Link to related discussions
- Link to relevant documentation
```

## Naming Convention

Files should be named: `ADR-XXX-short-title.md`

Example: `ADR-001-use-vue3-for-frontend.md`

## Current ADRs

1. [ADR-001: Use Vue 3 Composition API](ADR-001-vue3-composition-api.md) - **Accepted** (2026-02-24)
2. [ADR-002: Pinia for State Management](ADR-002-pinia-state-management.md) - **Accepted** (2026-02-24)
3. [ADR-003: Task Scope System (Beyond Rooms)](ADR-003-task-scope-system.md) - **Accepted, annotated 2026-09** (2026-02-24; the five-value scope model never shipped — the current model is the `room`/`floor`/`house` hierarchy per the in-file annotation and ADR-008)
4. [ADR-004: Multi-User Gamification Architecture](ADR-004-multi-user-gamification.md) - **Accepted** (2026-02-24; currency model superseded by the Coins model — see in-file note)
5. [ADR-005: Smart Scheduling](ADR-005-smart-scheduling.md) - **Superseded** (cluster deleted in CS-PLAN-025)
6. [ADR-006: Service-Layer Authorization (Defense in Depth)](ADR-006-service-layer-authorization.md) - **Accepted** (2026-08-12)
7. [ADR-007: Concurrency-Safe Task Completion](ADR-007-concurrency-safe-task-completion.md) - **Accepted** (2026-08-12)
8. [ADR-008: Room-Type Template Materialization (No Floor/House Scope Columns)](ADR-008-room-type-template-materialization.md) - **Accepted** (2026-08-13)
9. [ADR-009: Per-House Timezone for Calendar-Day Semantics](ADR-009-per-house-timezone.md) - **Accepted** (2026-08-30)
10. [ADR-010: Invite-Only House Membership and the Unified Task-Visibility Matrix](ADR-010-invite-only-membership-visibility-matrix.md) - **Accepted** (2026-09-03)
11. [ADR-011: Transactional Completion and Full Undo (Effect Record)](ADR-011-transactional-completion-and-undo.md) - **Accepted** (2026-09-06)
12. [ADR-012: Actionable Household Scheduling — Preview/Apply Aggregate](ADR-012-actionable-household-scheduling.md) - **Accepted** (2026-09-10)
13. [ADR-013: Occurrence Agreements and Corrections (Durable Recurrence Timeline)](ADR-013-occurrence-agreements-and-corrections.md) - **Accepted** (2026-09-20)
14. [ADR-014: Work Accounting, Ledger and Replay (Minor Units)](ADR-014-work-accounting-ledger-and-replay.md) - **Accepted** (2026-09-27; supersedes ADR-004's currency model — coins become exact minor units on an immutable ledger, corrections close via exact wallet-union deltas)
15. [ADR-015: Consent-Filtered Household Ranking](ADR-015-ranking-consent-and-privacy.md) - **Accepted** (2026-09-27; opt-in per membership, next-Monday activation generations, exact-rational on-time ratios over public agreed occurrences, server-side filter)
16. [ADR-016: Opt-in Daily Digest as the Only Notification Path](ADR-016-opt-in-digest-notifications.md) - **Accepted** (2026-09-29; one `digest` subject, slot-identity daily budget, at-most-once claim, quiet hours gate only, self-only versioned preferences, supersedes the per-event bell model)
17. [ADR-017: Family Reward Catalog and Requested Redemption (C4)](ADR-017-family-catalog-and-redemption.md) - **Accepted** (2026-10-01; owner-gated catalog with frozen terms, reserve/approve/settle/refund lifecycle with exactly-once money effects, wallet-id identity provenance, derived orthogonal funding hold)
18. [ADR-018: Repository Hosting and Contributor Identity](ADR-018-repository-hosting-and-contributor-identity.md) - **Accepted** (2026-10-16; GitHub organization `ChoreStrike` on `master`, one identity across the rewritten history from the global git config, no identity literals in tracked files, provenance wording allowed, retired orphan root, pre-migration SHAs resolved through a tracked commit map, Docker-free CI automatic and heavy gates manual)

---

**Note**: ADRs are immutable once accepted. If a decision changes, create a new ADR that supersedes the old one.
