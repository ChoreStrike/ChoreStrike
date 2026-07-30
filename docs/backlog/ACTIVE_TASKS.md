# Active Tasks Registry

> **Purpose:** Live coordination of work **in progress right now** plus a short
> rolling "Recently Completed" window. This is *not* a history log.
> **Update Rule:** every agent updates this file on start / pause / complete.
> **Housekeeping Rule:** move a completed entry to
> [`archive/YYYY-MM.md`](archive/) once it is older than ~2 weeks or beyond the
> ~10-entry rolling cap. **Open work** (bugs / features / debt / follow-ups)
> lives in [`backlog.md`](backlog.md), **not** here.

## In Progress

_(none)_

<!-- When you start work, add an entry here in this shape:
### CS-PLAN-NNN — Title
**Status**: In Progress · **Started**: YYYY-MM-DD · **Branch**: master
**Plan**: docs/plans/active/cs-plan-NNN-…md · **Blockers**: none
**Scope**: one line on what this turn is doing.
-->

## Recently Completed (rolling — last ~2 weeks / max 10)

> One-line entries only — full scope lives in the linked plan. Older completed
> work → [`archive/`](archive/) (e.g. [`archive/2026-07.md`](archive/2026-07.md)).

- **CS-PLAN-022** — Test Coverage Expansion (2026-07-30, Done) → `../plans/active/cs-plan-022-test-coverage-expansion.md`
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

- **CS-PLAN-017** — Manual Release Test Plan (P1) — sequenced behind CS-015b; see [`backlog.md`](backlog.md) → Release.
