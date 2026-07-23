# ChoreStrike i18n Workflow (POT + JSON)

This document defines the repeatable localization routine for Phase 6.

## 1) Source String Rules

- Frontend strings must use `t('chorestrike', '...')`.
- Backend strings must use `$this->l->t('...')`.
- Reuse existing keys whenever possible.
- Use named placeholders (for example `{name}`, `{count}`) instead of concatenated strings.

## 2) Update POT Template

1. Open `l10n/templates/chorestrike.pot`.
2. Add any new `msgid` entries introduced by code changes.
3. Keep one entry per unique source key.
4. Keep placeholders exactly as used in source strings.

## 3) Update Locale JSON Files

1. Add each new key to both files:
   - `l10n/de.json`
   - `l10n/en.json`
2. Keep key names byte-identical between `de.json` and `en.json`.
3. Keep placeholder tokens identical between languages.

## 4) Run Key Synchronization Check

```bash
npm run l10n:check-keys
```

Expected result:

```text
l10n key check passed: de.json and en.json are synchronized.
```

## 5) Validation Checklist (PR Gate)

- [ ] All new user-facing strings are wrapped in `t('chorestrike', ...)` or `$this->l->t(...)`.
- [ ] New keys exist in `l10n/templates/chorestrike.pot`.
- [ ] New keys exist in both `l10n/de.json` and `l10n/en.json`.
- [ ] Placeholder sets are identical in DE and EN values.
- [ ] `npm run l10n:check-keys` passes.
- [ ] Relevant unit tests for localized UI/API behavior are updated.

## 6) Commit Scope

Keep localization commits focused:

1. Source localization changes
2. `chorestrike.pot` updates
3. `de.json` + `en.json` updates
4. i18n-related test updates
5. Workflow/documentation updates
