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
- The first retained baseline (`0.8.0-alpha.1`) is adopted (2026-10-17):
  applied migrations are immutable; new ordered steps preserve persisted facts
  by default and need populated-baseline upgrade tests plus fresh/upgrade
  parity on supported providers. No upgrade paths, backfills or shims for
  pre-baseline disposable installs. Canonical scope: project-rules §1; record:
  [deployment](../../../../engineering/deployment.md#first-retained-baseline).

## Before a Structural Change

1. Check the [ADRs](../../../../adr/) for prior decisions.
2. Prefer the existing pattern; don't introduce a parallel abstraction (rule of three).
3. Keep the diff minimal. If breaking, propose a plan in `docs/plans/` first.
