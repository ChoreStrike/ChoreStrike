---
name: architecture
description: ChoreStrike architecture boundaries, layering, and Nextcloud app patterns
---

# Skill: Architecture

**Load when:** Design decisions, layer changes, refactors, adding controllers/services/mappers, or proposing alternatives.

**Canonical references:**
- [`project-rules.md`](../../rules/project-rules.md) §1–2
- [`architecture.md`](../../../../engineering/architecture.md)
- [ADR index](../../../../adr/)

---

## Layering (never violate)

```
Controllers (thin) → Services (business logic) → Mappers/Entities (persistence)
                              ↓
                        Nextcloud DB abstraction (IDBConnection / IQueryBuilder)
```

- Controllers: validate + delegate + shape response. **No business logic, no SQL.**
- Services: own business rules, authorization, and mutation coordination.
- Mappers/entities: persistence only.
- Frontend (`src/`): talks to the backend only over the documented API; never reaches into DB.

## Boundaries

- Stay inside the **Nextcloud app ecosystem**. No external services without explicit approval.
- Own DB tables (`oc_chorestrike_*`), namespaced routes (`/apps/chorestrike/`), Nextcloud filesystem/auth/l10n abstractions.
- Inject dependencies via Nextcloud DI; keep methods ~30 lines, files ~300 lines.

## Schema & Migrations

- Schema changes ship as a migration in `lib/Migration/`.
- Until explicit retained-baseline adoption, optimize the existing chain for
  fresh installs only; no speculative upgrade paths, backfills or shims.
- After adoption, applied migrations are immutable; new ordered steps preserve
  persisted facts by default and need populated-baseline upgrade tests plus
  fresh/upgrade parity on supported providers. Approval of this future boundary
  does not adopt it. Canonical scope: project-rules §1; operator procedure:
  [deployment checklist](../../../../engineering/deployment.md#first-retained-baseline).

## Before a Structural Change

1. Check the [ADRs](../../../../adr/) for prior decisions.
2. Prefer the existing pattern; don't introduce a parallel abstraction (rule of three).
3. Keep the diff minimal. If breaking, propose a plan in `docs/plans/` first.
