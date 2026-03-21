# ChoreStrike – AI Agent Guidelines

> **Central command center for AI agents.** Read this FIRST before any work.

## 🎯 Quick Start

1. **Read this file completely**
2. **Check** `docs/backlog/ACTIVE_TASKS.md` for ongoing work
3. **Update** ACTIVE_TASKS.md when starting/completing tasks
4. **Follow** the Coding→Review workflow below
5. **Commit** after each logical unit of work

---

## 1. Core Principles (KISS & DRY)

- **Language:** Code/docs/commits in **English only**. Chat in German allowed.
- **KISS:** Simple > Clever. Max ~30 lines/method, ~300 lines/file.
- **DRY:** No duplication. Extract after 3rd occurrence (Rule of Three).
- **Single Responsibility:** One class = one job.
- **Clean Architecture:** Frontend → Controller → Service → Mapper/DB. No business logic in controllers.
- **Nextcloud Ecosystem:** Use Nextcloud APIs only. No external services.
- **Logging:** Use structured, privacy-safe logging. Backend must use Nextcloud logging via shared helpers, frontend must use shared logger utilities instead of raw `console.*`.
- **Private Content:** ALL private/business/personal content MUST go in `/private/` folder (gitignored). NEVER commit private data to `docs/` or any public area.

**Details:** See `docs/ARCHITECTURE.md`, `docs/standards/`

---

## 2. Code Quality Standards

| Aspect | Rule | Reference |
|--------|------|-----------|
| **Documentation** | PHPDoc/JSDoc on EVERY public member | `docs/standards/` |
| **Testing** | Unit test per feature, regression test per bug | `docs/TESTING.md` |
| **i18n** | All UI text via `t()` function | `docs/AGENT_GUIDELINES.md` |
| **Error Handling** | Result patterns > exceptions | `docs/ARCHITECTURE.md` |
| **Logging** | Structured logs, no sensitive payloads, no raw production `console.*` | `docs/standards/LOGGING.md` |
| **Security** | Validate ownership, never trust client input | `docs/standards/SECURITY.md` |

---

## 3. Workflow: Coding → Review

### 3.1 Coding Agent Responsibilities

**Before Starting:**
1. Read `docs/backlog/ACTIVE_TASKS.md` to avoid conflicts
2. Add your task to ACTIVE_TASKS.md with status "In Progress"
3. Check related backlog file (`bugs.md`, `phase-X-*.md`)

**During Work:**
- Follow KISS/DRY principles
- Write tests alongside code
- Update ACTIVE_TASKS.md every 30-60 min
- Commit frequently using git MCP tools (see Section 5)

**When Complete:**
1. Run tests: `npm test` (frontend), `composer test` (backend)
2. Update ACTIVE_TASKS.md status to "Review Ready"
3. List all modified files in ACTIVE_TASKS.md
4. Update backlog file (mark task done, move to completed section)
5. Commit using git MCP tools (see Section 5)

### 3.2 Review Agent Responsibilities

**Trigger:** Check ACTIVE_TASKS.md for "Review Ready" tasks

**Review Checklist:**
- [ ] Code follows KISS/DRY principles
- [ ] All public members documented
- [ ] Tests exist and pass
- [ ] No hardcoded strings (i18n compliance)
- [ ] Security: Authorization checks present
- [ ] Architecture: Business logic in services, not controllers
- [ ] No code duplication
- [ ] File/method size limits respected

**Review Actions:**
1. **Minor issues (clear fix):** Fix immediately, commit with `refactor:` or `fix:` prefix
2. **Unclear issues:** Add comment to ACTIVE_TASKS.md, ask user
3. **Major refactor needed:** Move task to backlog with "Needs Refactor" status, notify user

**After Review:**
1. Update ACTIVE_TASKS.md: Move to "Recently Completed"
2. Update backlog: Mark as fully done
3. Commit using git MCP tools (see Section 5)

### 3.3 Parallel Work Protocol

- **Multiple coding agents:** Each works on different task IDs, updates ACTIVE_TASKS.md
- **One review agent:** Processes "Review Ready" queue sequentially
- **Conflict prevention:** Check ACTIVE_TASKS.md before starting, avoid same files

---

## 4. Task Management

### Central Registry
- **`docs/backlog/ACTIVE_TASKS.md`** - Live task tracking (update constantly)
- **`docs/backlog/bugs.md`** - Bug backlog
- **`docs/backlog/phase-N-*.md`** - Feature backlogs by phase
- **`docs/ROADMAP.md`** - High-level timeline (update rarely)

### Task Lifecycle
```
Planned → In Progress → Review Ready → Done
            ↓ (if blocked)
          Blocked → In Progress
            ↓ (if major issues)
          Needs Refactor → Backlog
```

### Agent Updates Required
- **Start task:** Add to ACTIVE_TASKS.md, update backlog status
- **Progress:** Update ACTIVE_TASKS.md notes/timestamp
- **Complete:** Update both ACTIVE_TASKS.md and backlog
- **Block:** Update ACTIVE_TASKS.md with blocker details

---

## 5. Auto-Commit & Auto-Fix Rules

### Auto-Commit (Always)
After completing a logical unit, use git MCP tools:

**Preferred Method (git MCP):**
1. `git_add` - Stage files: `mcp4_git_add(repo_path, files)`
2. `git_commit` - Commit: `mcp4_git_commit(repo_path, message)`

**Example:**
```
mcp4_git_add(repo_path: "/path/to/repo", files: ["src/file.vue", "lib/Service.php"])
mcp4_git_commit(repo_path: "/path/to/repo", message: "feat: add new feature")
```

**Available git MCP Tools:**
- `git_add` - Stage files
- `git_commit` - Create commit
- `git_status` - Check working tree status
- `git_log` - View commit history
- `git_diff` - Show differences
- `git_branch` - List/create branches
- `git_checkout` - Switch branches
- `git_show` - Show commit contents

**Why git MCP over shell:**
- Structured output (no parsing needed)
- Better error handling
- Consistent cross-platform behavior
- Safer operations with validation

### Running Tests
Use shell commands via `run_command` tool for tests:

**Frontend:** `npm test` or `npx playwright test`
**Backend:** `composer test` or `php vendor/bin/phpunit`

**Example:**
```
run_command(CommandLine: "npm test", Cwd: "/path/to/repo")
```

### Next Tasks (After Commit)
After each completed task/commit:
- Provide a prioritized list of the next tasks
- Provide a concrete proposal for the next steps

**Commit Types:** `feat`, `fix`, `docs`, `refactor`, `test`, `i18n`, `perf`, `chore`

### Auto-Fix (Coding Agent)
Fix immediately without asking:
- Obvious bugs (typos, wrong variable names)
- Missing documentation comments
- Code style violations (PSR-12, ESLint)
- Missing i18n wrapping
- Simple refactoring (extract method, rename)

### Auto-Fix (Review Agent)
Fix immediately during review:
- Documentation gaps
- Code style issues
- Simple KISS/DRY violations (< 10 lines change)
- Missing test cases (if straightforward)

### Ask User First
- Unclear requirements
- Design decisions (UX, architecture)
- Breaking changes
- Security-critical changes
- Large refactors (> 50 lines)

---

## 6. Priority Hierarchy

When conflicts arise, prioritize in this order:
1. **Security** (authorization, input validation)
2. **Technical Correctness** (bugs, logic errors)
3. **KISS/DRY** (simplicity, no duplication)
4. **Testing** (coverage, regression tests)
5. **Performance** (only if measurable impact)
6. **Other** (style, naming, etc.)

---

## 7. Documentation Structure

```
ChoreStrike/
├── AGENTS.md                    ← YOU ARE HERE (central command)
├── .windsurfrules               ← Quick reference (synced with this)
├── private/                     ← PRIVATE ONLY (gitignored, never commit!)
│   └── *.md                    ← Business plans, personal notes, etc.
├── docs/                        ← PUBLIC documentation only
│   ├── ROADMAP.md              ← High-level timeline
│   ├── ARCHITECTURE.md         ← System design details
│   ├── AGENT_GUIDELINES.md     ← Detailed examples & patterns
│   ├── TESTING.md              ← Testing strategies
│   ├── backlog/
│   │   ├── ACTIVE_TASKS.md     ← LIVE task tracking (update often!)
│   │   ├── bugs.md             ← Bug backlog
│   │   └── phase-*.md          ← Feature backlogs
│   ├── adr/                    ← Architecture decisions (why?)
│   └── standards/              ← Coding standards, security, etc.
```

**Update Frequency:**
- `ACTIVE_TASKS.md`: Every task start/update/complete
- Backlog files: On task status change
- `AGENTS.md`, `.windsurfrules`: Only when adding new rules
- `ROADMAP.md`: Only on phase completion

---

## 8. Quick Reference

| Situation | Action |
|-----------|--------|
| Starting work | Update ACTIVE_TASKS.md, read backlog |
| Unsure about requirement | Batch questions, ask at end |
| Found bug during review | Fix if clear, else ask user |
| Code duplication found | Refactor immediately (DRY) |
| Missing tests | Add tests before marking done |
| Security issue | Fix immediately, add regression test |
| Large refactor needed | Add to backlog, notify user |
| Recurring issue in reviews | Propose new rule for AGENTS.md |
| Private/business content | Move to `/private/` folder immediately |

---

**Last Updated:** 2026-02-28  
**Version:** 2.0 (Streamlined)
