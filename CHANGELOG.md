# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Initial project setup
- MIT License
- Project documentation (README, CONTRIBUTING, CONCEPT, ARCHITECTURE, API, DEVELOPMENT)
- Agent guidelines for AI-assisted development
- Nextcloud app structure (appinfo, routes, controllers)
- Basic Vue.js 3 frontend with routing
- Build configuration (webpack, babel, eslint, stylelint)
- Placeholder views for Dashboard, Tasks, Rooms, Leaderboard, Settings
- Reusable modal component for shared dialog UI
- Reusable room-task form component for adding tasks to rooms
- Router regression test covering room detail route resolution

### Changed
- Implemented full Task Manager view with room-based task operations
- Implemented full Leaderboard view with ranking and medal summaries
- Implemented full Settings view for house configuration, including floor and room management
- Aligned frontend dev-tool dependencies with Nextcloud webpack/eslint peer requirements
- Added Jest configuration for jsdom and Vue single-file component mocks
- Applied targeted lint cleanup on newly changed frontend view/component files
- Applied scoped lint auto-fixes for Pinia store modules (formatting and style consistency)
- Added JSDoc parameter typing/descriptions for store actions in rooms/tasks/user modules

### Deprecated
- Nothing yet

### Removed
- Nothing yet

### Fixed
- Fixed dashboard room click navigation to open the room detail route correctly
- Fixed room task completion user ID lookup to use the user store instead of game store
- Fixed Babel config usage to load Nextcloud shared Babel config correctly

### Security
- Nothing yet

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
