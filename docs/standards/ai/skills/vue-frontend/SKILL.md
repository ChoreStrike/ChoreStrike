---
name: vue-frontend
description: Writing and reviewing ChoreStrike Vue 3 + Pinia frontend code
---

# Skill: Vue Frontend

**Load when:** Writing or reviewing code in `src/` (views, components, stores, services).

**Canonical references:**
- [`coding-conventions.md`](../../../guidelines/coding-conventions.md)
- [`design.md`](../../../guidelines/design.md)
- [`i18n.md`](../../../guidelines/i18n.md)

---

## Stack

- **Vue 3** with `<script setup>` (Composition API), **Pinia** stores, **Vue Router**, **@nextcloud/vue** components, **Webpack 5**.

## Must-Haves for Any Change

- User-visible text → `t()` from the Nextcloud l10n helper. No hardcoded EN/DE copy.
- Network access → `@nextcloud/axios` via the Pinia stores (`src/store/*.js`). Don't scatter axios calls across components.
- Logging → `src/utils/logger.js`. **No `console.*`** in production code.
- **No `v-html`** with user-provided content.
- Prefer `@nextcloud/vue` components over hand-rolled UI to stay consistent and accessible.

## Patterns

- Small, composable components. Colocate scoped styles. Reuse CSS variables / theme tokens from [`design.md`](../../../guidelines/design.md).
- Keep Pinia stores focused (one concern each). Components consume stores, services, composables.
- Handle loading + error + empty states explicitly; never leave a view silently broken.

## Cross-Layer Contracts

When task/room payload shapes change, keep these in sync in the same change:
- backend payload → Pinia store (`src/store/*`) → component (`src/components/*`) → Playwright selectors.

## Verification

```bash
npm test            # Jest unit (components, stores, l10n consistency)
npm run lint        # ESLint (js + vue)
```
