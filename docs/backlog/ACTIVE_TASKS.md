# Active Tasks Registry

> **Purpose:** Live coordination of work **in progress right now** plus a short
> rolling "Recently Completed" window. This is *not* a history log.
> **Update Rule:** every agent updates this file on start / pause / complete.
> **Housekeeping Rule:** move a completed entry to
> [`archive/YYYY-MM.md`](archive/) once it is older than ~2 weeks or beyond the
> ~10-entry rolling cap. **Open work** (bugs / features / debt / follow-ups)
> lives in [`backlog.md`](backlog.md), **not** here.

## In Progress

<!-- When you start work, add an entry here in this shape:
### CS-PLAN-NNN — Title
**Status**: In Progress · **Started**: YYYY-MM-DD · **Branch**: master
**Plan**: docs/plans/active/cs-plan-NNN-…md · **Blockers**: none
**Scope**: one line on what this turn is doing.
-->

## Recently Completed (rolling — last ~2 weeks / max 10)

> One-line entries only — full scope lives in the linked plan. Older completed
> work → [`archive/`](archive/) (e.g. [`archive/2026-07.md`](archive/2026-07.md)).

- **CS-PLAN-024** — Release E2E Regression Suite (companion to CS-017): 6 `release-*` Playwright specs (task-lifecycle, visibility-roles, reward-config, task-templates, boost-shop, notifications) lifting the mechanically-repeatable `release-test-plan.md` rows out of the manual pass; `release-test-plan.md` §2.1 tagging convention added + row markers updated; all 11 spec tests green in `chromium-serial-db` (`--retries=0`). Absorbed CS-022 Phase 5; closed TECH-TEST-002. Standard-B review passed (3 findings fixed inline: §3 heading restored, admin-gate row wording, anchored spec regexes). Plan ready for `active/ → done/` (manual move). (2026-08-12, Done) → [`../plans/active/cs-plan-024-release-e2e-regression-suite.md`](../plans/active/cs-plan-024-release-e2e-regression-suite.md)
- **CS-PLAN-026** — Fix compiler- & lint-warnings (project sweep): aligned stylelint deps (config 3.x / stylelint 17) → 0 errors; added `.php-cs-fixer.dist.php` + `composer cs:fix` (249 files, tabs) → `cs:check` clean; PHP floor 8.2 everywhere; punycode DEP0040 suppressed (NODE_OPTIONS preload); browserslist refreshed; `.npmrc` for pre-existing eslint peer ERESOLVE; stylelint+cs:check promoted to blocking in CI + docs. Gate green: unit 690 / integ 150 / Jest 677 / lint+stylelint+cs:check 0. Deferred: TECH-LINT-004 (26 use-logical warnings), TECH-LINT-005 (api.js↔mock console.error noise). (2026-08-12, Done) → [`../plans/active/cs-plan-026-fix-compiler-warnings.md`](../plans/active/cs-plan-026-fix-compiler-warnings.md)
- **Plan housekeeping** — moved CS-017/022/023/025 from `active/` → `done/` (all complete + reviewed); integrated CS-025's durable decisions into permanent docs: [ADR-006](../adr/ADR-006-service-layer-authorization.md) (service-layer authorization) + [ADR-007](../adr/ADR-007-concurrency-safe-task-completion.md) (locking + post-commit notifications), with a cross-ref in `security.md`; CS-022 deferred Phase 5 (E2E) absorbed into CS-024 (no duplicate plan). Open follow-ups stay in `backlog.md` (CR-SVC-53 P1 + CR-SVC-54..58). (2026-08-12, Done)
- **Full Review — Active Plans (CS-017/022/023/025)** (4 parallel reviewer subagents + Docker gate re-verification; gate green: unit 690 / integration 150 [24 skips] / Jest 677 / `ConcurrentCompletionTest` 2/2 / lint clean; 1 auto-fix `setSparksEarned→setCoinsEarned`; 1 new [P1] CR-SVC-53 stale-`RoomTask`-after-`lockForUpdate` + CR-SVC-54..58 logged; E2E not re-run) (2026-08-11, Done) → [`CODE_REVIEW_2026-08-11.md`](CODE_REVIEW_2026-08-11.md)
- **CS-PLAN-025** — reopened items resolved: [P0] CR-SVC-04 (`findForUpdate` now a true `SELECT … FOR UPDATE` / self-assigning no-op on SQLite + real-DB two-process concurrency test that fails on the old code) and [P1] CR-SVC-45/46/47 (dead `completed_tasks` columns dropped from the migration chain, sync-out `nextcloud_task_id` stamp restored, fresh-install owner bootstrap at the five owner-only endpoints); gate green — plan ready for `active/ → done/` (manual move) (2026-08-10, Done) → [`../plans/done/cs-plan-025-service-security-concurrency-cleanup.md`](../plans/done/cs-plan-025-service-security-concurrency-cleanup.md)
- **Full Review — `lib/Service`** (Deep Slice, 4 parallel reviewer subagents; 3 minor auto-fixes applied, gate green; 2 P0 + 6 P1 + P2/P3 follow-ups logged as CR-SVC-*) (2026-08-04, Done) → [`archive/CODE_REVIEW_2026-08-04.md`](archive/CODE_REVIEW_2026-08-04.md)
- **CS-PLAN-023** — Time-Travel Testing in Docker (injectable-time job services, `occ chorestrike:trigger-jobs --as-of`, overdue/at-risk family seed, 2 E2E specs; also fixed route-shadowing + notifier-registration + seed-fixture defects, see [`archive/2026-08.md`](archive/2026-08.md)) (2026-08-04, Done) → `../plans/done/cs-plan-023-time-travel-testing.md`

- **CS-PLAN-022** — Test Coverage Expansion (Phases 1–4 + 6 done; **Phase 5 E2E deferred → absorbed into [CS-PLAN-024](../plans/new/cs-plan-024-release-e2e-regression-suite.md)** to avoid a duplicate E2E plan) (2026-07-30, Done) → `../plans/done/cs-plan-022-test-coverage-expansion.md`
- **CS-PLAN-017** — Manual Release Test Plan (consolidate 4 stale testing docs → authoritative stage-mapped plan + sign-off record; release gate wired) (2026-07-30, Done) → `../plans/done/cs-plan-017-manual-release-test-plan.md`
- **CS-PLAN-021 D2** — Calendar server-side future-occurrence projection (2026-07-29, Done) → `../plans/done/cs-plan-021-task-calendar-view.md` §9
- **CS-PLAN-021** — Task Calendar View (week / rolling 30-day) (2026-07-29, Done) → `../plans/done/cs-plan-021-task-calendar-view.md`
- **CS-PLAN-020** — Release-Quality Gate Cluster (OCS envelope, controller test gate, E2E in release-gate) (2026-07-29, Done) → `../plans/done/cs-plan-020-release-quality-gate-cluster.md`
- **CS-PLAN-019** — Test-Endpoint Security Hardening (`@PublicPage` removal + `isTestMode()` tightening) (2026-07-29, Done) → `../plans/done/cs-plan-019-test-endpoint-security-hardening.md`
- **CS-PLAN-018** — Full Quality Sweep (test, lint & build audit) (2026-07-29, Done) → `../plans/done/cs-plan-018-full-quality-sweep.md`
- **Agent-Rules Hardening** — Docker-availability misdiagnosis + no-completion-without-tests + pre-release-compat rules (2026-07-28, Done)
- **CS-PLAN-016 (re-review)** — Smart Scheduling UI, Standard-A re-review fixes applied (2026-07-28, Done) → `../plans/done/cs-plan-016-scheduling-ui.md`
- **CS-PLAN-015b** — E2E Stabilization Phase 2 (per-user storageState, seed-race containment, acceptance) (2026-07-28, Done) → `../plans/done/cs-plan-015b-e2e-phase2.md`

## Planned (awaiting activation — drafts in `docs/plans/new/`)

> The `new/ → active/` move is **manual** (project-rules §7). These register intent only.

- _(none — CS-024 activated and completed.)_
