---
name: php-backend
description: Writing and reviewing ChoreStrike PHP backend code (controllers, services, mappers, migrations)
---

# Skill: PHP Backend

**Load when:** Writing or reviewing PHP in `lib/` (controllers, services, mappers, entities, migrations).

**Canonical references:**
- [`project-rules.md`](../../rules/project-rules.md) §2 — architecture & authorization
- [`coding-conventions.md`](../../../guidelines/coding-conventions.md)
- [`security.md`](../../../guidelines/security.md)

---

## Layer Discipline

```
Controller (thin) → Service (logic) → Mapper/Entity (persistence)
```

- **Controllers** (`lib/Controller`): validate input → call the owning domain service → return `JSONResponse`. Thin bootstrap/authorization support calls are allowed; no SQL or domain rules.
- **Services** (`lib/Service`): business logic and authorization decisions. The only place mutations are coordinated.
- **Mappers/Entities** (`lib/Db`): persistence. Entities map table rows; mappers run queries.

## Must-Haves for Any Change

- `@NoAdminRequired` annotation (never `@PublicPage`).
- Authorization via `AuthorizationService` (denials throw `ForbiddenException` → 403; canonical detail and the 403-vs-404 rule: [`security.md`](../../../guidelines/security.md#authorization)):
  - `assertHouseMember($houseId)` — **standard controller preamble**: pure read-only gate, never auto-joins.
  - `ensureHouseMember($houseId)` — **bootstrap-only** (ADR-010 D1): creates a membership only while the house has zero members; only the five designated bootstrap endpoints may call it.
  - `requireHouseOwner($houseId)` for owner-only ops; `assertCanManageTasks($houseUser)` for task-domain mutations (children → 403); `assertUserInHouse($houseId, $userId)` for endpoints taking an arbitrary target userId.
  - Enforce mutation authorization **inside the service** too (ADR-006 defense in depth).
- Input validation at the controller boundary → return `{ success: false, error: { code, message } }` with a stable code and HTTP status.
- DB access via `IQueryBuilder` with `createNamedParameter()`. **Never** string-concatenate SQL.
- Log failures once, at the boundary, with structured context (`AppLogger`).
- No stack traces / internal details in responses.

## Patterns

- Constructor injection via Nextcloud DI. No `new Service(...)` in controllers.
- Throw domain exceptions in services; catch + translate in the controller.
- Schema changes → `lib/Migration/` (`Version<seq>Date<UTC>.php`). Before explicit retained-baseline adoption: fresh-install correctness only, no speculative upgrade paths/backfills/shims. After adoption: applied files are immutable; new ordered migrations must preserve facts, pass populated-baseline upgrades and fresh/upgrade parity on supported providers. See project-rules §1 and the [adoption checklist](../../../../engineering/deployment.md#first-retained-baseline); approval of the future policy is not adoption.

## Verification

`composer cs:check`, then the backend unit + integration suites in Docker — commands live only in the [`docker-dev`](../docker-dev/SKILL.md#essential-commands) skill.
