# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.1] - 2026-06-22

### Changed
- Renamed project attribution from "ChoreWars Contributors" to "ChoreStrike Contributors" in `LICENSE`, `package.json`, and `CONTRIBUTING.md`.
- Aligned `package.json` version to `0.1.1` to match `appinfo/info.xml`.
- Standardized repository URLs to the canonical Codeberg host in `README.md`, `SECURITY.md`, `docs/DEVELOPMENT.md`, and `docs/TESTING.md`.
- Updated the vulnerability reporting policy to use Codeberg instead of GitHub Security Advisories.
- Consolidated deployment documentation into a single `docs/DEPLOYMENT.md`.
- Relocated `docs/CONCEPT.md` to `docs/concepts/DESIGN.md` and refreshed its header.
- Refreshed the stale directory tree in `docs/ARCHITECTURE.md`.

### Removed
- Untracked the committed `vendor/` directory (1249 files); `vendor/` is now gitignored while `composer.lock` stays tracked. Run `composer install` after a fresh checkout.
- Untracked the Nextcloud sync-client artifact `.sync-exclude.lst`.
- Removed the empty `img/emojis/` and `img/icons/` directories.

### Fixed
- Removed the incorrect `<logging/>` app type from `appinfo/info.xml`.

> Repository cleanup only — no application code changes. All removed files are preserved under `archive/2026-06/`.

## [1.0.0] - 2026-03-05

### Added

#### Core Features
- **House Management**: Automatic house creation, multi-user sharing, house name customization
- **Floor System**: Multi-floor organization with custom names, sorting, and deletion
- **Room Management**: Rooms within floors, cleanliness tracking, monster spawning for overdue tasks
- **Task System**: Full task lifecycle with frequency, difficulty, priority, and checklists
- **Task Templates**: Pre-defined templates for common household tasks by category
- **Smart Scheduling**: AI-powered task suggestions based on availability and energy budget

#### Gamification
- **Sparks System**: Earn sparks for completing tasks based on difficulty
- **Combo System**: Multiplier bonuses for completing tasks quickly
- **Monster Invasion**: Cute dust monsters spawn in neglected rooms
- **Achievements**: Unlockable badges for milestones and accomplishments
- **Medals**: Weekly recognition for different play styles (Tank, Scout, etc.)
- **Leaderboards**: Competition by sparks, streaks, and tasks completed

#### Collaboration
- **Multi-User Support**: Share house with family/roommates
- **Role System**: Owner, Member, Child roles with permission levels
- **Task Assignment**: Assign tasks to specific users or leave open
- **Task Approval**: Parents can review children's completed tasks

#### Technical
- **Full i18n Support**: English and German translations
- **REST API**: Complete API for all operations
- **Database Migrations**: 15 migrations for schema evolution
- **Unit Tests**: 274+ unit tests with 948+ assertions
- **E2E Tests**: Playwright-based end-to-end tests
- **Security Audit**: Complete security review passed

### Changed
- Renamed from "ChoreWars" to "ChoreStrike"
- Upgraded to Vue 3 Composition API
- Migrated from Vuex to Pinia for state management
- Refactored controllers to use service layer (Clean Architecture)

### Security
- All endpoints use `@NoAdminRequired` (authenticated access only)
- All database queries use prepared statements
- Input validation on all endpoints
- House membership checks on all data access
- No XSS vulnerabilities (no `v-html` usage)

## [0.1.0] - 2026-02-24

### Added
- Initial project scaffolding
- Basic documentation structure
- Development environment setup

---

**Legend:**
- `Added` for new features
- `Changed` for changes in existing functionality
- `Deprecated` for soon-to-be removed features
- `Removed` for now removed features
- `Fixed` for any bug fixes
- `Security` in case of vulnerabilities
