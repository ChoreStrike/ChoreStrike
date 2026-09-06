# Active Tasks Registry

> **Purpose:** Live coordination of work **in progress right now** plus a short
> rolling "Recently Completed" window. This is *not* a history log.
> **Update Rule:** every agent updates this file on start / pause / complete.
> **Housekeeping Rule:** move a completed entry to
> [`archive/YYYY-MM.md`](archive/) once it is older than ~2 weeks or beyond the
> ~10-entry rolling cap. **Open work** (bugs / features / debt / follow-ups)
> lives in [`backlog.md`](backlog.md), **not** here.

## In Progress

### CS-PLAN-064 / WP5 — Completion Ledger and Full Transactional Undo
**Status**: In Progress (activated; implementation not started) · **Started**: 2026-09-06 · **Branch**: master
**Plan**: docs/plans/active/cs-plan-064-wp5-completion-ledger-full-undo.md · **Blockers**: none
**Scope**: WP5 completion/undo critical path; owns CR-WF-02 (umbrella), UX-015, CR-053-20 WP5 undo/cache slice, CR-SVC-15/40 WP5 completion-path slices. Standard A `PASS-WITH-FIXES` 2026-09-06: D1–D10 ratified, D11 (undone rows excluded from day-duplicate/combo/weekly-goal reads) and D12 (task-definition no-lock, ADR-011 rationale) added and ratified, manifest test paths fixed, manual-cleanliness race deferred as CR-SVC-63, plan moved to `active/`.

## Recently Completed (rolling — last ~2 weeks / max 10)

- **CS-PLAN-063 / WP4 — Atomic Task Creation and Canonical Assignment** — Implemented and final-reviewed: atomic `TaskCreationService` (custom/mount/from-template → one `{task, roomTask, assignees}` response, house-lock-first with under-lock membership revalidation and mount-duplicate guard), closed service-owned validation set (+ CR-062-01), `room_task`-lock single/bulk assignment with pinned partial-success semantics, ownership-walk dedup, `POST /tasks` + create-chain pruned, one-request UI (preview, catalog/preset prefill, `canManageTasks` gating), real-DB zero-write + `proc_open` race proofs; Standard B `PASS-WITH-FIXES` (null-name 400 regression fixed with user authorization; i18n + security-log + dead-mock + store-consistency minors fixed inline), whole gate rerun green, CR-WF-03 closed and archived (both halves done), CR-SVC-41 WP4 slice annotated, plan moved to `done/`. (2026-09-06) → [`../plans/done/cs-plan-063-wp4-atomic-task-creation-assignment.md`](../plans/done/cs-plan-063-wp4-atomic-task-creation-assignment.md)
- **CS-PLAN-062 / WP3 — Atomic House Setup and Structure** — Implemented and final-reviewed: first-run gate inside the setup transaction behind the house-row lock, `houseName` folded into the setup command, under-lock membership revalidation, truthful wizard result panel + per-recipient invite contract (D5), a11y modal pass, `emptyhouse` E2E journey (WP3 exit gate); Standard B `PASS-WITH-FIXES` (409 recovery error duplication fixed inline; CR-062-01 + TECH-LINT-007 logged), whole gate rerun green (incl. MySQL apply-race test), CR-WF-03 setup-half/CR-WF-05 exit-gate criteria closed, plan moved to `done/`. (2026-09-05) → [`../plans/done/cs-plan-062-wp3-atomic-house-setup-structure.md`](../plans/done/cs-plan-062-wp3-atomic-house-setup-structure.md)

- **CS-PLAN-061 / WP2 — Membership, Role, Visibility, and Privacy Boundaries** — Invite-only membership (ADR-010), one `TaskAccessPolicy` visibility matrix, service-level actor/owner gates, 403/404 standard, CR-053-16/17/18 closed, frontend auth state + logger redaction; final Standard B `PASS-WITH-FIXES` (mojibake + log hygiene fixed; residual-surface gaps closed and lifecycle-actor/completion-redaction decisions ratified with the user), full gate green, CR-WF-01/CR-053-16/17/18 archived, plan moved to `done/`. (2026-09-04) → [`../plans/done/cs-plan-061-wp2-membership-visibility-privacy.md`](../plans/done/cs-plan-061-wp2-membership-visibility-privacy.md)
- **CS-PLAN-060 / WP1 — Schema, Entity, and Mapper Invariants** — Implemented and reviewed: schema fold, bootstrap race safety, `TransactionExecutor`, canonical visibility/assignee/recurrence, house-scoped `findVisibleByHouse`, mapper minors; Standard B `PASS-WITH-FIXES` (stale E2E consumers + durable docs fixed in review), full gate rerun green, plan moved to `done/`. (2026-09-03) → [`../plans/done/cs-plan-060-wp1-schema-entity-mapper-invariants.md`](../plans/done/cs-plan-060-wp1-schema-entity-mapper-invariants.md)

- **CS-PLAN-059 / WP0** — Backlog restructured into the five CS-PLAN-058 §2.2 sections with nine-field metadata, 46 resolved stubs archived, `backlog:check` gate added, program baseline recorded; Standard B `PASS-WITH-FIXES`, plan moved to `done/`. (2026-09-02) → [`../plans/done/cs-plan-059-wp0-backlog-baseline.md`](../plans/done/cs-plan-059-wp0-backlog-baseline.md)
- **CS-PLAN-058 Draft** — Bottom-up core-workflow masterplan created from the open backlog and Full Review, covering persistence through actionable scheduling, child-friendly execution, and deterministic E2E gates; Standard A review passed (GO), activated into `active/`. (2026-09-02) → [`../plans/active/cs-plan-058-core-workflow-bottom-up-masterplan.md`](../plans/active/cs-plan-058-core-workflow-bottom-up-masterplan.md)
- **Core Task Workflow Full Review** — End-to-end review completed with outcome `BLOCKED` by unresolved security/auth findings; safe timezone/i18n/docs fixes gated and CR-WF-01..06 recorded, but no commit was made. (2026-09-02) → [`archive/CODE_REVIEW_2026-09-02.md`](archive/CODE_REVIEW_2026-09-02.md)
- **Review Workflow Automation and Standards Audit** — Review-driven plan transitions, batched decisions, and automatic scoped commits standardized; four-track Full review clean after fixes, full gate green, historical backlog cleanup retained as TECH-DOCS-003. (2026-09-02) → [`archive/CODE_REVIEW_2026-09-02.md`](archive/CODE_REVIEW_2026-09-02.md)
- **CS-PLAN-057** — Per-House Timezone for Calendar-Day Semantics; Standard B `PASS`, full gate green, plan moved to `done/`. (2026-08-30) → [`../plans/done/cs-plan-057-per-house-timezone.md`](../plans/done/cs-plan-057-per-house-timezone.md)
- **CS-PLAN-056** — Task Lifecycle Visibility Matrix; Standard B `PASS`, full gate green, plan moved to `done/`. (2026-08-30) → [`../plans/done/cs-plan-056-task-lifecycle-visibility.md`](../plans/done/cs-plan-056-task-lifecycle-visibility.md)
- **CS-PLAN-055** — Route Pruning & Dead-Code Sweep U2-A; implementation and Standard B review completed. (2026-08-29) → [`archive/2026-08.md`](archive/2026-08.md)
- **CS-PLAN-054** — P1 Security Quick-Closes; implementation and Standard B review completed. (2026-08-29) → [`archive/2026-08.md`](archive/2026-08.md)

> One-line entries only. Older completed work lives in the tracked
> [`archive/`](archive/).
