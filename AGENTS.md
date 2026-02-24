# ChoreWars – AI Development Guidelines & Best Practices

> This document serves as the single source of truth for AI agents and developers working on ChoreWars.

## 1. Core Principles
- **Security First:** Data and system security are the absolute highest priority. Always ensure during development and code review that no sensitive data is exposed, no unauthorized access is possible, and all Nextcloud security guidelines are strictly followed.
- **Language:** ALL code, comments, commit messages, and documentation must be in **English only**. Chatting with the user in German is allowed.
- **Naming & Legal Safety:** Avoid third-party brand names in product copy and concept docs unless explicitly required for technical integration.
- **Tone:** Prefer family-friendly, non-aggressive terminology for rewards and progression systems.
- **KISS & DRY:** Keep it simple, stupid. Don't repeat yourself.
- **No Premature Abstraction:** Apply the Rule of Three before extracting abstractions.
- **Single Responsibility Principle:** Each class/module has ONE job. 
- **Prefer Simple Code:** Readable code is always better than clever code.
- **Nextcloud Ecosystem:** Follow Nextcloud App API standards. No external services.

## 2. Architecture & Design
- **Clean Architecture:** Maintain strict layer separation (Frontend Vue -> API -> Controller -> Service -> Mapper/DB). Dependencies should flow inward toward the domain logic.
- **Domain Independence:** The core domain/business logic must have ZERO external framework dependencies where possible, or abstract them heavily.
- **Thin APIs/Controllers:** Never put business logic in the API or routing layer. Controllers only parse input, call services, and return output.
- **Composition over Inheritance:** Prefer composition (e.g., capability interfaces/traits in PHP, composables in Vue) to share behaviors rather than deep inheritance trees.

## 3. Code Quality
- **Documentation:** Add documentation comments (PHPDoc/JSDoc) to EVERY public member (class, interface, method, property, etc.).
- **File Structure:** Keep files focused. One main concept/type per file.
- **Size Limits:** Aim for max ~30 lines per method, ~300 lines per file to prevent God-classes.
- **Error Handling:** Use Result-patterns or explicit return types for expected domain failures rather than throwing raw exceptions where feasible. Throw exceptions only for truly exceptional states.
- **No Magic Strings:** Use constants or enums (PHP 8.1+ Enums).
- **Internationalization:** All UI text must be routed through the `t()` function. No hardcoded strings.

## 4. Testing
- **Unit Tests:** Every new feature needs unit tests (PHPUnit for backend, Jest/Vue Test Utils for frontend).
- **Regression Tests:** Every bug fix needs a regression test.
- **Naming:** Use clear test naming conventions (e.g., `MethodName_Scenario_ExpectedResult` or equivalent BDD style).

## 5. AI Agent Behaviors
- **Always Read First:** Read `AGENTS.md` and architecture docs before proposing changes.
- **Priority Hierarchy:** 1. Technical correctness, 2. KISS/DRY, 3. Performance/Other.
- **Auto-Fix & Auto-Commit:** Solve clear bugs and simple refactoring automatically. After completing a logical package or bugfix, automatically execute `git add` and `git commit` with a meaningful message. Update docs and backlog accordingly.
- **Batch Questions:** Do not guess on ambiguities. Collect multiple issues and present them at the end of the session with 2-3 options and a recommendation.
- **Rule Derivation:** During reviews, if recurring issues are found, proactively suggest adding new rules to `AGENTS.md` or `.windsurfrules`.
- **When Unsure:** Ask the user rather than guessing. Prefer minimal changes over large refactors unless explicitly requested.

## 6. Project Documentation Structure
- `AGENTS.md` (Root) - Central AI instructions
- `docs/adr/` - Architecture Decision Records (why a decision was made)
- `docs/standards/` - Coding conventions, testing standards, etc.
- `docs/backlog/` - Central backlog tracking
