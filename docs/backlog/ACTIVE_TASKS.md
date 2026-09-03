# Active Tasks Registry

> **Purpose:** Live coordination of work **in progress right now** plus a short
> rolling "Recently Completed" window. This is *not* a history log.
> **Update Rule:** every agent updates this file on start / pause / complete.
> **Housekeeping Rule:** move a completed entry to
> [`archive/YYYY-MM.md`](archive/) once it is older than ~2 weeks or beyond the
> ~10-entry rolling cap. **Open work** (bugs / features / debt / follow-ups)
> lives in [`backlog.md`](backlog.md), **not** here.

## In Progress

### CS-PLAN-060 / WP1 — Schema, Entity, and Mapper Invariants
**Status**: Standard A passed (GO-WITH-CHANGES applied), activation pending · **Started**: 2026-09-02 · **Branch**: master
**Plan**: docs/plans/new/cs-plan-060-wp1-schema-entity-mapper-invariants.md · **Blockers**: none
**Scope**: WP1 plan drafted from CS-PLAN-058 §3/WP1 plus verified live-tree persistence facts; Standard A review verified all references and ratified D1–D11 (remnant-column fold D9, frontend contract-key deviation D10, unlinked-visible scoping D11).

<!-- When you start work, add an entry here in this shape:
### CS-PLAN-NNN — Title
**Status**: In Progress · **Started**: YYYY-MM-DD · **Branch**: master
**Plan**: docs/plans/active/cs-plan-NNN-….md · **Blockers**: none
**Scope**: one line on what this turn is doing.
-->

## Recently Completed (rolling — last ~2 weeks / max 10)

- **CS-PLAN-059 / WP0** — Backlog restructured into the five CS-PLAN-058 §2.2 sections with nine-field metadata, 46 resolved stubs archived, `backlog:check` gate added, program baseline recorded; Standard B `PASS-WITH-FIXES`, plan moved to `done/`. (2026-09-02) → [`../plans/done/cs-plan-059-wp0-backlog-baseline.md`](../plans/done/cs-plan-059-wp0-backlog-baseline.md)
- **CS-PLAN-058 Draft** — Bottom-up core-workflow masterplan created from the open backlog and Full Review, covering persistence through actionable scheduling, child-friendly execution, and deterministic E2E gates; Standard A review passed (GO), activated into `active/`. (2026-09-02) → [`../plans/active/cs-plan-058-core-workflow-bottom-up-masterplan.md`](../plans/active/cs-plan-058-core-workflow-bottom-up-masterplan.md)
- **Core Task Workflow Full Review** — End-to-end review completed with outcome `BLOCKED` by unresolved security/auth findings; safe timezone/i18n/docs fixes gated and CR-WF-01..06 recorded, but no commit was made. (2026-09-02) → [`archive/CODE_REVIEW_2026-09-02.md`](archive/CODE_REVIEW_2026-09-02.md)
- **Review Workflow Automation and Standards Audit** — Review-driven plan transitions, batched decisions, and automatic scoped commits standardized; four-track Full review clean after fixes, full gate green, historical backlog cleanup retained as TECH-DOCS-003. (2026-09-02) → [`archive/CODE_REVIEW_2026-09-02.md`](archive/CODE_REVIEW_2026-09-02.md)
- **CS-PLAN-057** — Per-House Timezone for Calendar-Day Semantics; Standard B `PASS`, full gate green, plan moved to `done/`. (2026-08-30) → [`../plans/done/cs-plan-057-per-house-timezone.md`](../plans/done/cs-plan-057-per-house-timezone.md)
- **CS-PLAN-056** — Task Lifecycle Visibility Matrix; Standard B `PASS`, full gate green, plan moved to `done/`. (2026-08-30) → [`../plans/done/cs-plan-056-task-lifecycle-visibility.md`](../plans/done/cs-plan-056-task-lifecycle-visibility.md)
- **CS-PLAN-055** — Route Pruning & Dead-Code Sweep U2-A; implementation and Standard B review completed. (2026-08-29) → [`archive/2026-08.md`](archive/2026-08.md)
- **CS-PLAN-054** — P1 Security Quick-Closes; implementation and Standard B review completed. (2026-08-29) → [`archive/2026-08.md`](archive/2026-08.md)
- **CS-PLAN-053** — Full Bottom-to-Top Review; six review phases completed and follow-ups recorded. (2026-08-27) → [`archive/CODE_REVIEW_2026-08-27.md`](archive/CODE_REVIEW_2026-08-27.md)
- **CS-PLAN-052** — Observability Hardening & Encoding Gate; implementation and Standard B review completed. (2026-08-27) → [`archive/2026-08.md`](archive/2026-08.md)

> One-line entries only. Older completed work lives in the tracked
> [`archive/`](archive/).
