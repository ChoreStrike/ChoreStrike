# Internationalization and Content Policy

## Internationalization Scope

ChoreStrike is multilingual by default.

### Initial language targets

- English
- German

### Requirements

- All user-facing text must go through Nextcloud translation APIs.
- No hardcoded UI strings in components or controllers.
- New features require translation key definitions as part of done criteria.
- Role titles must support at least 3 localized naming packs per supported language in MVP.
- Custom role-title packs are post-MVP and require dedicated pack-management APIs.

## Content Tone

- Motivating and respectful.
- Family-friendly and non-aggressive.
- Clear language over slang.

## Brand and Naming Policy

To avoid legal and community friction:

- Do not use third-party brand names in product concept docs, UI copy, or examples unless explicitly required for technical integration.
- Prefer generic descriptions such as "similar household apps" when discussing inspirations.
- Keep examples implementation-neutral.

## Documentation Language Rule

All project documentation is maintained in English.

## Open Decisions

- Policy for translated marketing naming versus canonical English feature names.
- Whether glossary terms should be centrally versioned in `docs/standards/`.
