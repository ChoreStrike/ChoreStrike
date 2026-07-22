# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Role Title System** (CS-PLAN-006): server-authoritative progression overlay on top of the existing gamification stats. Three title packs × three tiers (9 titles), each themed around a different progression signal — Home Helpers (`contributionScore`), Team Energy (`longestStreak`), Cozy Home (`coins`). New `lib/Titles/TitleCatalog.php` + `lib/Service/TitleService.php`; `selected_title_pack` + `unlocked_titles` columns on `chorestrike_house_users` (`Version1025`, with a `postSchemaChange` backfill so existing users aren't re-celebrated on deploy); configurable thresholds via `RewardConfigService` (`titles_enabled` flag + 9 `title_threshold_*` keys); `TitleBadge`/`TitlePicker` Vue components on Settings + Leaderboard; title-unlock celebration + Nextcloud notification; `GET /users/{id}/titles` + `PUT /users/{id}/titles/select` API. Closes `ROLE-TITLE-SYSTEM-001` / `GAMIFICATION-CONCEPTS-001`.

### Changed
- Gamification simplification Phase 0 + Phase 1 core (CS-PLAN-002): removed the Sparks and Monsters dead systems; live task completion path was already secure, the unrouted duplicate accepting a client-supplied user id was removed as dead code; `claimOverdueTask` now persists claim fields (centralized in `RoomTask::markClaimed`); completing another member's overdue task now records a takeover (original assignee streak unaffected). See `docs/plans/active/cs-plan-002-gamification-simplification.md` for full scope and remaining work.
- `GameLogicService::handleTaskCompletion` now tracks the all-time `longestStreak` from the effective (post-freeze) streak (CS-PLAN-006: the team-energy title signal was previously never advanced).

### Removed
- API endpoint `GET /api/v1/minigames/sparks` and the Sparks currency from the frontend (DB columns retained harmlessly).
- Monsters logic (`GameLogicService::calculateMonsterCount`/`getMonsterType`, `RoomMapper::updateMonsterCount`, `Room::hasMonsters`/`addMonster`/`removeMonster`) and monster UI (`RoomCard` toggle/display, `QuickStats` monsters stat, `RoomDetail` MonsterSprite). The `MonsterSprite.vue`, `MonsterCollection.vue`, and `GameStats.vue` components are archived under `archive/2026-06/gamification-monsters/`.
- Dead unrouted `RoomController::completeTask()` (the live completion path `RoomTaskController::complete()` was already secure).

### Fixed (CS-PLAN-006 review pass)
- Title-unlock notifications no longer drop when two packs unlock the same tier rank — the notification object id is now pack-qualified (`{pack}.{tier}`), so each unlock survives Nextcloud's `(app, user, objectType, objectId)` dedup.
- The title picker now mirrors the server-authoritative unlocked set instead of recomputing `signal >= threshold` from a separately-fetched (and optimistically mutated) stats object, so checkmarks can no longer disagree with the server's `currentTitle`.
- Title state (`currentTitle`/`catalog`/`unlockedTitles`) is now cleared on logout/house-switch — the titles store `reset()` is wired into `resetAllStores()`.
- Title threshold config now rejects non-strictly-ascending values within a pack (throws before any write) so `getCurrentTitle()`'s "highest qualifying tier" semantics can never break silently.
- The ever-grown `unlockedTitles` cache is no longer exposed in the generic user payload (`GET /users`, active user, leaderboard); the `GET /users/{id}/titles` endpoint is now the sole source of the live-derived unlocked-title list.

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
