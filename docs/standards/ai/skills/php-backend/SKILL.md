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
- Authorization — two membership primitives in `AuthorizationService`, both throwing `ForbiddenException`:
  - `ensureHouseMember($houseId)` — **auto-creates** membership only after the canonical `getOrCreateHouse` instance bootstrap; this is the standard controller preamble.
  - `assertHouseMember($houseId)` — pure read-only gate for target-derived/existing house IDs where auto-join is not intended and for owner checks inside `AuthorizationService`.
  - `requireHouseOwner($houseId)` for owner-only ops; `assertUserInHouse($houseId, $userId)` to guard endpoints that take an arbitrary target userId.
- Input validation at the controller boundary → return `{ success: false, error: { code, message } }` with a stable code and HTTP status.
- DB access via `IQueryBuilder` with `createNamedParameter()`. **Never** string-concatenate SQL.
- Log failures once, at the boundary, with structured context (`AppLogger`).
- No stack traces / internal details in responses.

## Patterns

- Constructor injection via Nextcloud DI. No `new Service(...)` in controllers.
- Throw domain exceptions in services; catch + translate in the controller.
- Schema changes → a migration in `lib/Migration/` (`Version<seq>Date<UTC>.php`). Pre-release (until declared productive, project-rules §1): **fresh-install correctness only** — no upgrade paths, backfills, or legacy shims.

## Verification

```bash
# Unit (Docker) — services, mappers, entities
docker compose -f docker/compose.test.yml exec -T nextcloud-test \
  vendor/bin/phpunit --configuration phpunit.unit.xml
# Integration (Docker) — controllers, API
docker compose -f docker/compose.test.yml exec -T nextcloud-test \
  vendor/bin/phpunit --configuration phpunit.integration.xml
```
