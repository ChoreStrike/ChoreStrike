# Active Tasks Registry

> **Purpose:** Live coordination of work **in progress right now** plus a short
> rolling "Recently Completed" window. This is *not* a history log.
> **Update Rule:** every agent updates this file on start / pause / complete.
> **Housekeeping Rule:** move a completed entry to
> [`archive/YYYY-MM.md`](archive/) once it is older than ~2 weeks or beyond the
> ~10-entry rolling cap. **Open work** (bugs / features / debt / follow-ups)
> lives in [`backlog.md`](backlog.md), **not** here.

## In Progress

<!-- (empty — review workflow automation + standards audit completed 2026-09-02) -->

<!-- When you start work, add an entry here in this shape:
### CS-PLAN-NNN — Title
**Status**: In Progress · **Started**: YYYY-MM-DD · **Branch**: master
**Plan**: docs/plans/active/cs-plan-NNN-….md · **Blockers**: none
**Scope**: one line on what this turn is doing.
-->

## Recently Completed (rolling — last ~2 weeks / max 10)

- **Review Workflow Automation and Standards Audit** — Review-driven plan transitions, batched decisions, and automatic scoped commits standardized; four-track Full review clean after fixes, full gate green, historical backlog cleanup retained as TECH-DOCS-003. (2026-09-02) → [`archive/CODE_REVIEW_2026-09-02.md`](archive/CODE_REVIEW_2026-09-02.md)
- **CS-PLAN-057** — Per-House Timezone for Calendar-Day Semantics; Standard B `PASS`, full gate green, plan moved to `done/`. (2026-08-30) → [`../plans/done/cs-plan-057-per-house-timezone.md`](../plans/done/cs-plan-057-per-house-timezone.md)
- **CS-PLAN-056** — Task Lifecycle Visibility Matrix; Standard B `PASS`, full gate green, plan moved to `done/`. (2026-08-30) → [`../plans/done/cs-plan-056-task-lifecycle-visibility.md`](../plans/done/cs-plan-056-task-lifecycle-visibility.md)
- **CS-PLAN-055** — Route Pruning & Dead-Code Sweep U2-A; implementation and Standard B review completed. (2026-08-29) → [`archive/2026-08.md`](archive/2026-08.md)
- **CS-PLAN-054** — P1 Security Quick-Closes; implementation and Standard B review completed. (2026-08-29) → [`archive/2026-08.md`](archive/2026-08.md)
- **CS-PLAN-053** — Full Bottom-to-Top Review; six review phases completed and follow-ups recorded. (2026-08-27) → [`archive/CODE_REVIEW_2026-08-27.md`](archive/CODE_REVIEW_2026-08-27.md)
- **CS-PLAN-052** — Observability Hardening & Encoding Gate; implementation and Standard B review completed. (2026-08-27) → [`archive/2026-08.md`](archive/2026-08.md)
- **CS-PLAN-051** — E2E Full-Matrix Stabilization; implementation and Standard B review completed. (2026-08-27) → [`archive/2026-08.md`](archive/2026-08.md)
- **CS-PLAN-050** — E2E Long-Tail Integrity Sweep & Docs-Encoding Gate; implementation and Standard B review completed. (2026-08-26) → [`archive/2026-08.md`](archive/2026-08.md)
- **CS-PLAN-049** — RewardConfig Registry & Docs Quick-Wins; implementation and Standard B review completed. (2026-08-26) → [`archive/2026-08.md`](archive/2026-08.md)

> One-line entries only. Older completed work lives in the tracked
> [`archive/`](archive/).
