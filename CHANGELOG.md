# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Repository Migration (2026-10-16)

- Moved the project to the GitHub organization
  [ChoreStrike/ChoreStrike](https://github.com/ChoreStrike/ChoreStrike) from
  the previous Codeberg host, and consolidated the whole history onto one
  contributor identity. Original author and committer dates are preserved and
  the working tree is unchanged (identical `HEAD` tree object).
- **Every commit hash changed.** Earlier qualification evidence names
  pre-migration commits; resolve those through
  [`docs/release/history-migration-2026-10-commit-map.tsv`](docs/release/history-migration-2026-10-commit-map.tsv).
- The `v0.1.0` tag was deleted. It pointed outside the main line, at a disjoint
  pre-rename (`ChoreWars`) prototype root that is not an ancestor of any
  released code. No tags currently exist.
- CI moved to GitHub Actions: Docker-free checks run on every push, the full
  gate and the browser matrices are manual, and the weekly runtime-drift check
  stays scheduled. The binding quality gate remains the local Docker run.
- Security reports can now use GitHub private vulnerability reporting next to
  the documented mailbox.
- Decision record: [ADR-018](docs/adr/ADR-018-repository-hosting-and-contributor-identity.md).

### Current Pre-Release Summary (2026-10-07)

- Delivered family-rhythm WP0-WP8: occurrence-based planning, agreements and
  pauses, exact shared-work accounting, targeted corrections, rhythm recognition,
  quiet opt-in daily digests, all-role My Day and the optional reward catalog
  with requests, reservations, approval, fulfillment and cancellation.
- Retired personal/teamwork multipliers, streak freezes, the boost shop and
  game-earned Coins. Snake is scores-only; recognition uses the rhythm series
  rather than selectable title packs.
- Qualified Nextcloud 35/PHP 8.4 with a named Nextcloud 34 AIO compatibility row;
  real MariaDB, PostgreSQL and SQLite gates and consecutive browser gates pass.
- Family comprehension/14-day evidence, actual deployment and explicit retained-
  baseline adoption remain pending. No stable release or upgrade support for
  earlier disposable development installations is claimed.

### Earlier Unreleased Development Notes

The notes below preserve intermediate development history; mechanics and paths
may have been superseded by the current summary. The installed app version
remains `0.1.1`; older headings are not a claim of current stable-release status.

### Added
- **Role Title System** (CS-PLAN-006): server-authoritative progression overlay on top of the existing gamification stats. Three title packs × three tiers (9 titles), each themed around a different progression signal — Home Helpers (`contributionScore`), Team Energy (`longestStreak`), Cozy Home (`coins`). New `lib/Titles/TitleCatalog.php` + `lib/Service/TitleService.php`; `selected_title_pack` + `unlocked_titles` columns on `chorestrike_house_users` (`Version1025`, with a `postSchemaChange` backfill so existing users aren't re-celebrated on deploy); configurable thresholds via `RewardConfigService` (`titles_enabled` flag + 9 `title_threshold_*` keys); `TitleBadge`/`TitlePicker` Vue components on Settings + Leaderboard; title-unlock celebration + Nextcloud notification; `GET /users/{id}/titles` + `PUT /users/{id}/titles/select` API. Closes `ROLE-TITLE-SYSTEM-001` / `GAMIFICATION-CONCEPTS-001`.

### Changed
- Gamification simplification Phase 0 + Phase 1 core (CS-PLAN-002): removed the Sparks and Monsters dead systems; live task completion path was already secure, the unrouted duplicate accepting a client-supplied user id was removed as dead code; `claimOverdueTask` now persists claim fields (centralized in `RoomTask::markClaimed`); completing another member's overdue task now records a takeover (original assignee streak unaffected). See `docs/plans/active/cs-plan-002-gamification-simplification.md` for full scope and remaining work.
- `GameLogicService::handleTaskCompletion` now tracks the all-time `longestStreak` from the effective (post-freeze) streak (CS-PLAN-006: the team-energy title signal was previously never advanced).
- Repository & documentation restructure (CS-PLAN-009): docs/repository-structure cleanup with no application-code, build, or runtime-behavior change. Removed the stale duplicate root `MANUAL_TEST_CHECKLIST.md` (its unique content referenced the removed Monsters feature and obsolete scripts) and the redundant `phpunit.xml.dist` (`composer.json`/CI use `phpunit.xml`). Archived historical backlog snapshots (`CODE_REVIEW_2026-03-{07,11,14}.md`, `ALPHA_TEST_RESULTS_2026-03-22.md`) to `archive/` and relocated shipped/obsolete phase files (`phase-5/6` → `docs/plans/done/`, `phase-11` → `docs/plans/done/`, obsolete `phase-10` → `archive/2026-07/`). Renamed the 20 non-standard `CAPITALS.md` docs to kebab-case under thematic subfolders (`docs/engineering/`, `docs/api/README.md`, `docs/release/`, `docs/operations/`, `docs/testing/`, `docs/concepts/`, `docs/standards/i18n-workflow.md`) and swept all live references. Hardened the Playwright `outputDir`. Only `README.md`/`LICENSE`/`CHANGELOG.md`/`AGENTS.md`/`CONTRIBUTING.md`/`CODE_OF_CONDUCT.md`/`SECURITY.md` remain CAPITALS at root; only `README.md` in `docs/`. Historical records (`docs/plans/done/`, the `Recently Completed` section of `ACTIVE_TASKS.md`, prior `CHANGELOG` entries, backlog phase task descriptions) intentionally retain old names as frozen history.

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
