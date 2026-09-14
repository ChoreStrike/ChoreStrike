---
name: i18n
description: Adding and changing user-facing strings and translation artifacts
---

# Skill: Internationalization (i18n)

**Load when:** Adding or changing any user-visible text.

**Canonical references:**
- [`i18n.md`](../../../guidelines/i18n.md) — rules + artifact workflow (POT/JSON)

---

## Rules

- Every user-visible string uses the Nextcloud `t()` l10n helper. No hardcoded EN/DE copy in UI flows.
- Keep copy short, clear, age-appropriate, and consistent across views. No sarcasm, blame, or punitive tone.
- Keep machine-readable API fields separate from translated display messages.
- Don't trust client-sent text to be localized or safe.

## Files to Update in the Same Change

- `l10n/templates/chorestrike.pot`
- `l10n/en.json`
- `l10n/de.json`
- Any other active localization assets for the touched area.

## Verification

```bash
npm run l10n:check-keys
# relevant Jest l10n-consistency tests
npm test
```

`l10n:check-keys` checks global literals and finite catalog/map owners,
exact POT/EN/DE key sets and source-relative named/printf placeholders.
Unclassified dynamic keys, missing POT, dead keys and empty German fail.
Use `node scripts/check-l10n-keys.js --write` for deterministic regeneration,
review its diff and supply real German copy for new keys; then rerun the
read-only gate. The canonical guideline defines the exact exclusions and
the real-browser DE/EN proof.

## Frontend / Backend

- **Frontend:** use the Nextcloud l10n helper in views, components, composables, and stores. Localize error/success messages that reach users.
- **Backend:** use the app localization service for translated response messages. Don't use translated strings as the only debugging signal in logs.
