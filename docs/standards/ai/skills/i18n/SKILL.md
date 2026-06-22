---
name: i18n
description: Adding and changing user-facing strings and translation artifacts
---

# Skill: Internationalization (i18n)

**Load when:** Adding or changing any user-visible text.

**Canonical references:**
- [`i18n.md`](../../../guidelines/i18n.md)
- [`../../I18N_WORKFLOW.md`](../../../../I18N_WORKFLOW.md)

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

## Frontend / Backend

- **Frontend:** use the Nextcloud l10n helper in views, components, composables, and stores. Localize error/success messages that reach users.
- **Backend:** use the app localization service for translated response messages. Don't use translated strings as the only debugging signal in logs.
