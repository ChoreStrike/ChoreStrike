# Coding Standards & Conventions

This directory contains detailed coding standards and conventions for ChoreStrike.

## Overview

All code in ChoreStrike must follow these standards to ensure consistency, maintainability, and quality.

## Core Documents

- **PHP Coding Standard** - PSR-12 compliance, Nextcloud conventions
- **JavaScript/Vue Coding Standard** - ES6+, Vue 3 Composition API
- **CSS/SCSS Standard** - BEM naming, CSS variables
- **Testing Standard** - Unit test requirements, naming conventions
- **Git Commit Standard** - Conventional commits format
- **Documentation Standard** - PHPDoc, JSDoc requirements
- **Logging Standard** - Structured backend/frontend logging, privacy guardrails, Nextcloud log levels
- **External Asset Licensing Standard** - Third-party icons, fonts, and illustrations
- **Review Playbook** - Reusable review packages, automation strategy, and handoff workflow

## External Asset Licensing Checklist

Before adding external assets (for example icon packs), verify and document the following:

- License allows commercial use.
- License allows redistribution in open source repositories.
- License allows redistribution via Nextcloud app packaging/store delivery.
- Attribution requirements are explicit and implemented where required.
- Modification rights are clear (color changes, cropping, composition).
- No trademarked or protected brand elements are included.
- Proof of license is stored in project docs.

Use this policy document for the decision log and evidence:

- [EXTERNAL_ASSET_LICENSING.md](./EXTERNAL_ASSET_LICENSING.md)

## Quick Reference

### PHP
- Follow PSR-12
- Use type hints
- Document all public members with PHPDoc
- Max 30 lines per method, 300 lines per file
- Use Nextcloud DB abstraction

### JavaScript/Vue
- Use ES6+ syntax
- Vue 3 Composition API
- Meaningful variable names
- JSDoc for complex functions
- Prefer const over let

### CSS
- BEM naming where applicable
- CSS variables for theming
- Scoped styles in Vue components

### Testing
- Every feature needs unit tests
- Every bug fix needs regression test
- BDD-style naming: `MethodName_Scenario_ExpectedResult`

### Git Commits
```
<type>: <description>

[optional body]

[optional footer]
```

Types: `feat`, `fix`, `docs`, `refactor`, `test`, `i18n`, `perf`, `chore`

## See Also

- [AGENTS.md](../../AGENTS.md) - AI development guidelines
- [DEVELOPMENT.md](../DEVELOPMENT.md) - Development workflow
- [ARCHITECTURE.md](../ARCHITECTURE.md) - System architecture
- [REVIEW_PLAYBOOK.md](../REVIEW_PLAYBOOK.md) - Reusable review workflow and checklist

---

**Remember**: Readable code is better than clever code. KISS & DRY.
