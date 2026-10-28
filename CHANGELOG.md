# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### App Version 0.8.0-alpha.3 — Trial Feedback Round (candidate, 2026-10-28)

The fixes from the first feedback round of the family trial; the app metadata
moves from `0.8.0-alpha.2` to `0.8.0-alpha.3` so the update is detected. No
schema or migration change: the 26 migrations of the retained baseline stay
unchanged. This section gets its own dated version heading once the
deployment is verified.

- **New tasks keep the context:** *New task* in the Tasks tab takes over the
  active floor, room and person filter; the "mine" and "unassigned" filters
  preselect nobody.
- **Flexible windows fit the rhythm:** a task's flex days are at most half its
  period (four for a weekly task, 15 for a monthly one), and a fixed schedule
  opens at most seven days early. A stored value above the new cap stays valid
  when unrelated fields are edited.
- **Work inside its window:** My Day lists open-window work planned for another
  day under *Also possible today*. Widening a task's flexible window opens its
  already planned occurrences at once, and *Done* before a window opens says
  *Not open yet*.
- **"Last done on" after setup:** an adult can record when a room task was last
  done. An interval task then plans its next occurrence from that day, and the
  room card shows it; the entry is no completion and earns no reward.
- **Unique room names:** creating or renaming a room, also in the setup wizard,
  appends the next free number to a name that already exists.
- **Dialogs keep your input:** a click next to a form dialog no longer closes it
  and its buttons stay in view; Esc, the close button and *Cancel* still close.
- **The real reason for a refusal:** a refused change shows its reason instead
  of "changed in the meantime"; only a truly outdated view still says so.
- **Owners can remove another owner** while at least one owner remains; leaving
  the house yourself is not possible yet.
- **Fixed:** the checklist button in My Day appears only for tasks that have a
  checklist; a direct switch from one room to another reloads the room page;
  clicking a member or weekday chip no longer makes the page jump; bars, tracks
  and icons stay visible in the dark theme.
- **Under the hood:** the toolchain moves to Node 24 / npm 11 with ESLint 10 and
  the current Nextcloud build configuration; expiring old digest entries works
  in bounded batches; applying a weekly plan no longer scans the change list
  per task.
- **Qualification:** gates run on the latest upstream Nextcloud (35.0.2) and on
  the Nextcloud of the current AIO release v14.4.2 (34.0.5, PHP 8.4.27, Redis
  for sessions, cache and locking).

## [0.8.0-alpha.2] - 2026-10-19

### Family-Trial Start

Deployed on 2026-10-19 as an app update on the retained baseline; the family
trial runs on it. The app metadata moves from
`0.8.0-alpha.1` to `0.8.0-alpha.2` so the update is detected. No schema or
migration change: the 26 migrations of the retained baseline are unchanged and
now pinned by a test.

- **Household arcade:** five short games — Spot Sprint, Sock Match, Crumb Snake,
  Tile Breaker and Dust Runner — with a highscore list each. They unlock through
  finished chores (0, 3, 10, 25 and 50; helping counts, undone work does not),
  and the house owner can switch that gate off in the settings. The arcade stays
  optional per member; color, motion and sound follow the personal opt-ins
  (CS-PLAN-086).
- **Overdue means missed:** the calendar marks a day red only when that
  occurrence's window has ended, and a task created, rescheduled, completed,
  undone or re-split inside its open flex window no longer turns red until the
  next list refresh (CR-084-01/03).
- **A reviewed work split is never dropped:** completing a task again while its
  first completion is still being saved is refused with a hint to check the
  work split when the split differs, instead of silently reporting the first
  completion (CR-084-02).
- **Phones:** the Insights fairness table scrolls inside its own box and the
  room page fits the screen; no view scrolls sideways any more (UX-018).

The fixes collected after `0.8.0-alpha.1` below are part of this version.

### Fixed after 0.8.0-alpha.1

- **No log warning on every notification poll:** ChoreStrike rejects other
  apps' notifications with Nextcloud's `UnknownNotificationException` instead
  of a plain `InvalidArgumentException`, which Nextcloud logs as deprecated on
  every poll (and as an error from Nextcloud 39). Reported by the first
  production deployment.
- **New app icon:** the broom mascot of the brand mark replaces the old house
  icon — a white glyph in the Nextcloud header, a dark one in notifications,
  and the color mascot when ChoreStrike is installed as a web app (CS-PLAN-087).
- **Icons load from the real app folder:** the notification icon and the web-app
  manifest no longer point to `/apps/chorestrike/`, which answers 404 where
  apps live under `custom_apps` (AIO). The app's own manifest is now actually
  used — it was never linked before, so a home-screen install got Nextcloud's
  generic manifest.
- **Every navigation entry has an icon** (five had none on Nextcloud 34/35).
- **My Day on phones:** a task's name and details keep the full card width
  above the *Done* and *Checklist* buttons instead of shrinking to a few
  characters per line.
- **Dialogs cover the whole page:** the setup wizard and the app's dialogs
  now lie above Nextcloud's navigation toggle, which used to draw over them.
- **Contact through GitHub only:** questions go to GitHub Discussions,
  security reports to GitHub private vulnerability reporting and conduct
  reports to *Report content*; the project mailbox is no longer published.

## [0.8.0-alpha.1] - 2026-10-17

### Family-Trial Candidate

Deployed on 2026-10-17 and adopted as the first retained baseline. First
version of the `v0.8` alpha stage
([release strategy](docs/release/release-strategy.md)); the app metadata moves
from `0.1.1` to `0.8.0-alpha.1` so a deployed update is detected and phones
load the new scripts. No schema or migration change. The `v0.8.0-alpha.1` tag
follows only after the completed manual alpha record.

- **Completion needs a deliberate press:** *Done* and *Quick clean* complete
  after a 600 ms press-and-hold (pointer, touch or held Enter/Space) with a
  filling ring; a short tap shows a hint and sends nothing. Every completion
  entry point is guarded against double submission. *Claim overdue* keeps its
  confirmation dialog, *Undo* stays one tap.
- **Calmer feedback, diagnosable failures:** a check mark and, on Android, a
  short vibration confirm every completion (confetti and sound stay opt-in;
  reduced motion respected). Failure toasts name the API error code, e.g.
  "Could not complete the task (CONFLICT)".
- **Readable in both themes:** colors follow the Nextcloud light/dark theme
  (not only the device setting); badges and status text meet WCAG AA contrast.
- **Fewer clicks:** every dashboard tile opens its filtered destination, room
  cards are keyboard-operable links, and *All tasks* shows pages of 20 compact
  rows with filters behind a *Filters* toggle; *Completed* loads finished
  tasks on demand.
- **Fixed:** the dashboard's house-health bar shows its fill and icons again,
  with room between the house name and the percentage; Nextcloud's navigation
  toggle no longer covers the start of view headings; a task is overdue only
  after its due day has ended in the house time zone (no red status, claim
  button or takeover record from midnight); an agreed handover is no longer
  recorded as an overdue takeover; claiming an overdue task completes without
  a second dialog; deleted tasks no longer appear in room task lists or
  inflate room statistics; a checklist item could be listed twice after
  adding it.
- **Qualification:** gates run on the latest upstream Nextcloud (35.0.2) and on
  the exact Nextcloud of the operator's AIO release (34.0.4, with Redis for
  sessions, cache and locking like AIO), including phone-sized Chromium and
  WebKit browser projects.

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

### Earlier Development Notes

The notes below preserve intermediate development history; mechanics and paths
may have been superseded by the current summary. The app version was `0.1.1`
until `0.8.0-alpha.1` (2026-10-17); older headings are not a claim of current
stable-release status.

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
