# Code Review Record — 2026-10-17

## Full Review (final) — CS-PLAN-084 family-trial UX baseline and deployment candidate `0.8.0-alpha.1`

**Outcome:** `PASS-WITH-FIXES` · **Transition:** `active/ → done/`

### Scope

| Item | Value |
|---|---|
| Owning plan | `docs/plans/done/cs-plan-084-family-trial-ux-baseline.md` (was `active/`) |
| Reviewed implementation | `2dece85..c5b890f` — all eleven CS-PLAN-084 implementation rounds (lib, src, tests, scripts, docker, CI), plus the plan's manifest, acceptance criteria, durable-doc integration and evidence history |
| Reviewed round 12 | uncommitted pre-deployment round on `chore/pre-deployment-0.8.0-alpha.1` (separate worktree from `master` `993e563`): version bump, CHANGELOG, TECH-TEST-004 / TECH-DOCS-006 / TECH-DOCS-004 / TECH-PKG-001, mojibake repairs |
| Index at capture | empty |
| Unstaged diff fingerprint at capture | `07901f63df5a37b1f700bba676e26b2e9bdc1ecfccdecbbd3395f17e8a87fd51` (26 paths, no untracked files) |
| Preserved unrelated changes | the main checkout on `feat/arcade-minigames` (CS-PLAN-086 draft, TECH-DOCS-005 work) — untouched; this review ran in its own worktree |
| Tracks | four read-only reviewers in parallel: correctness, architecture, security/auth/data flow, performance/quality |

### Findings

| ID | Sev | Track | Area | Issue | Status |
|----|-----|-------|------|-------|--------|
| RF-084-04 | major | architecture | `src/views/RoomDetail.vue` `overdueCount` | Header *Overdue* count still used `nextDue < now`: a task due today counted (red) from midnight, unlike the rows, RoomCard and room stats (D-084-08) | Fixed — shared `isOverdue`; regression; negative control 3 ≠ 1 |
| RF-084-05 | minor | correctness | `HoldButton.vue` hold timer | A button disabled or made busy mid-hold still emitted `confirm` | Fixed — watchers abort, timer re-checks; regression for both props; negative control fails |
| RF-084-06 | minor | quality | `taskDisplay.dueText` | "Overdue by 1 days" reachable through the one-day clamp | Fixed — singular key, DE copy, l10n regenerated (1289), regression |
| — | nit | correctness | `scripts/check-backlog.js` | `git ls-files` ran in the process cwd without `-z`: subdirectory runs and quoted non-ASCII names broke the index (and, pre-existing, the plan-ID lookup) | Fixed — all listings run at the repo root with NUL separation; verified from `docs/plans` |
| — | nit | correctness | `scripts/check-docs-encoding.js` | Emoji (`U+00F0 U+0178`) and embedded-BOM mojibake not flagged | Fixed — signature + self-test fixture; tree green |
| — | nit | correctness | `docs/release/roadmap.md` | Stale "app version is `0.1.1`" | Fixed |
| CR-084-01 | minor | correctness | `CalendarView.isOverdue` | Per-day rule ignores an open flex window | User-rescoped to `backlog.md` |
| CR-084-02 | minor | correctness | `roomTasksStore.completeRoomTask` | A joined in-flight completion drops the second call's allocation | User-rescoped to `backlog.md` |
| CR-084-03 | minor | architecture | `RoomTask::getStatus` vs `statusFor` | Single-row payloads serialize the entity status | User-rescoped to `backlog.md` |
| CR-084-04 | minor | architecture | `HoldButton.vue` | Above the ~300-line guidance | User-rescoped to `backlog.md` |

Accepted without change (nits, recorded): the claim-allocation completion block
exists in two views (within the rule of three); Dashboard expands all floors in
the view; `CompletionService` evaluates the agreed-completer check before the
cheap `late` test; the on-demand *Completed* read returns the whole house list
and re-reads on re-entry (D-084-07 accepted one extra read); room stats repeat
three primary-key lookups; `TaskList` re-sorts an already sorted page;
`dueClass`/`dueText` compute the day offset twice. None affects behavior or the
request budgets.

The security track found nothing: visibility stays server-enforced
(`visibleRoomTasks`), the takeover rule is stricter than before, client presets
are allow-listed and only filter, error toasts show only a validated code,
there is no `v-html`, the Redis test seam is validated and confined to
`docker/`, and the tooling shells out with fixed argument arrays. Coverage note:
the security and architecture reviewers did not read the test patch in detail;
the correctness reviewer covered tests and tooling.

### Decisions recorded (operator, 2026-10-17)

App version `0.8.0-alpha.1`; final Full review; this candidate's full gate
counts as CS-PLAN-080 RF-080-15's full-gate requalification; this deployment is
also the first retained-baseline adoption (recorded in CS-PLAN-080); a second
docs-only commit records the package evidence; the real-device checklist moves
to CS-PLAN-080; the four minor findings go to `backlog.md`.

### Gates (after all fixes)

| Gate | Result |
|---|---|
| Jest | 124 suites / 1807 tests |
| ESLint, Stylelint, `test:noise` | clean |
| `l10n:check-keys` | 1289 keys, POT/EN/DE exact |
| `docs:encoding`, `backlog:check` (+ self-test), `agents:check` | green |
| e2e-gate and package-smoke self-tests | passed |
| `composer cs:check` | 0 of 414 files |
| `test:runtime:check` / images | `current` (upstream 35.0.2, AIO 14.3.0 / NC 34.0.4); inventory `qualified`; both images `frozen-image-identity-match` |
| Primary row `cs082-pd17-pgsql` (NC 35.0.2.2, PHP 8.4.26, PostgreSQL 18.6) | unit 1385, integration 613; browser gate `run-nKi6vJ` PASSED 95 / 171 / 39 / 10 / 10 |
| AIO row `cs082-pd17-aio` (NC 34.0.4.1, PHP 8.4.26, PostgreSQL 18.6, Redis 8.10.2) | unit 1385, integration 613; browser gate `run-p5CXyQ` PASSED 95 / 171 / 39 / 10 / 10 |
| MariaDB 11.8.6 / SQLite 3.46.1 (NC 35.0.2) | unit 1385, integration 613 each |

All backend suites ran with `--fail-on-skipped`; both browser gates passed on
the first attempt with zero skips, failures or flakes. The package smoke needs
a clean committed HEAD and follows in the evidence commit.

### Outcome

All required tracks completed; every finding is fixed or user-rescoped, and the
plan's acceptance criteria, required tests and durable-doc integration are
complete. Plan moved to `done/`; UX-017, FEAT-UX-033..036 and TECH-RUNTIME-002
archived; TECH-TEST-005 stays open for the real-device run under CS-PLAN-080.
No deployment, adoption, tag or push.
