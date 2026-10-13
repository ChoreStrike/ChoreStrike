# Coding Conventions

> Practical style rules for ChoreStrike. Keep it simple and readable — KISS & DRY over clever.
> Canonical rules: [`../ai/rules/project-rules.md`](../ai/rules/project-rules.md).

---

## General

- **Language:** Code, comments, docs, and commit messages are **English only**. Chat with the user may be German.
- **Size:** Aim for ~30 lines per method, ~300 lines per file. Split when it hurts.
- **Minimal diffs:** No unrelated renames, reordering, or formatting churn in feature commits.
- **DRY:** Extract duplication after the rule of three — not before.
- **Naming:** Meaningful, consistent names. No single-letter names except short loop indices.

## PHP (Backend)

- Follow the **Nextcloud coding standard** (`nextcloud/coding-standard` — a PSR-12
  superset: **tabs**, same-line braces, ordered imports). Enforced by
  `.php-cs-fixer.dist.php`: run `composer cs:check` before committing,
  `composer cs:fix` auto-applies. Use strict types where practical.
- Use **type hints** and **return types** on all methods.
- Document every public member with **PHPDoc**.
- **Thin controllers:** routes/controllers only validate input, call a service, and return a `JSONResponse`. No business logic in controllers.
- **Services** hold business logic; **mappers/entities** (`lib/Db`) handle persistence.
- Use the **Nextcloud DB abstraction** (`IDBConnection`, `IQueryBuilder`) with **named parameters** — never concatenate SQL.
- Throw domain-specific exceptions; translate them to error responses at the controller boundary.
- Inject dependencies via constructor (Nextcloud DI). No `new` for services in controllers.

```php
public function create(int $floorId, string $name): JSONResponse {
	// validate -> service -> response
}
```

## Vue 3 (Frontend)

- Use the **Composition API** with `<script setup>`.
- **Pinia** for state; **Vue Router** for routing; **@nextcloud/vue** for shared components.
- ES6+ syntax. Prefer `const` over `let`. Destructure with care.
- Add **JSDoc** to non-trivial composables and helpers.
- **No `v-html`** with user-provided content (XSS).
- Keep components small and composable; colocate scoped styles.
- Route application network access through Pinia stores (`src/store/*.js`) and the shared `src/services/api.js` client, which owns the `@nextcloud/axios` integration and response normalization.

## CSS

- **BEM-ish** naming where it helps; reuse **CSS variables** for theming.
- Scoped styles in Vue components; keep the palette in [`design.md`](./design.md).
- Respect light/dark mode via Nextcloud CSS variables where possible.

## Localization

- Every user-visible string goes through `t()` (Nextcloud l10n helper). No hardcoded EN/DE copy in UI.
- Update `l10n/templates/chorestrike.pot`, `l10n/en.json`, `l10n/de.json` in the same change. See [`i18n.md`](./i18n.md).

## Logging

- Backend: `AppLogger`. Frontend: `src/utils/logger.js`. **No raw `console.*`** in production code.
- Structured, short, stable messages; never log secrets, tokens, or full sensitive payloads. See [`logging.md`](./logging.md).

## Git Commits

Conventional commits:

```
<type>(<scope>): <description>

[optional body]

[optional footer]
```

Types: `feat`, `fix`, `docs`, `refactor`, `test`, `i18n`, `perf`, `chore`, `build`, `ci`. The scope is optional, lowercase, and names the domain or gate area (`tasks`, `gates`, `release`, `security`, …).

- One logical change per commit. Reference the issue/plan where relevant (`Closes #123`).
- Never commit secrets, private content, or build artifacts.

## Review Hooks

- New feature → unit test. Bug fix → regression test. See [`testing.md`](./testing.md).
- New endpoint → authorization + ownership check. See [`security.md`](./security.md).
