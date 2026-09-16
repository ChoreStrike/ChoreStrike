# Active Tasks Registry

> **Purpose:** Live coordination of work **in progress right now** plus a short
> rolling "Recently Completed" window. This is *not* a history log.
> **Update Rule:** every agent updates this file on start / pause / complete.
> **Housekeeping Rule:** move a completed entry to
> [`archive/YYYY-MM.md`](archive/) once it is older than ~2 weeks or beyond the
> ~10-entry rolling cap. **Open work** (bugs / features / debt / follow-ups)
> lives in [`backlog.md`](backlog.md), **not** here.

## In Progress

(none — no session holds the Docker test environment; CS-PLAN-058 closed
below, no browser-reset ownership active)

## Recently Completed (rolling — last ~2 weeks / max 10)

- **Backlog & ADR housekeeping** — consolidated ACTIVE_TASKS narrative into [`archive/2026-09.md`](archive/2026-09.md) (11 → 5 per-plan entries), repaired 35 dead tracked Markdown links (ADR-006/007 plan links and the monthly archives' retired cs-plan-017..047 links → completion records / plan-name code spans; stale `plans/active` pointer → `done/`; CODE_REVIEW_2026-09-11 owning-plan link de-linked verbatim-preserving), corrected stale CR-SVC-21/22/50 status claims in ADR-006/007, aligned ADR-004 README status, restored missing backlog.md entry separator, refreshed archive/README listing; backlog:check, docs:encoding, `git diff --check` green. (2026-09-16)

- **CS-PLAN-058 — program closed:** final Full review `PASS-WITH-FIXES` (2026-09-14 evening, RF-058-01..22 fixed/rescoped, unit 941 / integration 361 / Jest 1056 non-visual gates green) + delegated D-FR-04 browser-gate pair delivered the same night (RF-058-23 test-only capped-bell-window dismiss fix with 35-stale-row proof, clean-volume recreation, `run-RX1FBW` + `run-GPxhOi` — 2 consecutive passes, 252 executions each, zero skips/retries/flakes), and the masterplan moved `active/ → done/` on 2026-09-16. RELEASE-002 program prerequisite met (its independent blockers remain). [Plan](../plans/done/cs-plan-058-core-workflow-bottom-up-masterplan.md) · [Review record](archive/CODE_REVIEW_2026-09-14.md).

- **CS-PLAN-068 / WP9 — final Standard B `PASS-WITH-FIXES` (2026-09-14):** D8/D9/D10 approved; all findings resolved, clean install, 920 unit / 361 integration (zero skips) / 1054 Jest tests, 940 localization keys and two 252-execution browser passes green; plan moved to `done/`, owning items archived. [Plan](../plans/done/cs-plan-068-wp9-deterministic-coverage-release-closure.md) · [Review record](archive/CODE_REVIEW_2026-09-14.md).

- **CS-PLAN-067 / WP8 — batching & decomposition** (draft + Standard A `PASS` 2026-09-12, implementation + final Standard B `PASS-WITH-FIXES` 2026-09-13): house-wide selector reads in ONE request, TaskList/completion/medal decomposition behind the unchanged transaction boundary, frozen query/request budgets; Jest 939 / unit 920 / integration 320, cs:check clean; plan moved to `done/`, CR-WF-06/CR-SVC-40/41 archived. [Plan](../plans/done/cs-plan-067-wp8-batching-decomposition-performance.md) · [Review record](archive/CODE_REVIEW_2026-09-13.md).

- **CS-PLAN-066 / WP7 — canonical client state & family UX** (draft + Standard A `PASS-WITH-FIXES` 2026-09-11, implementation + final Standard B `PASS-WITH-FIXES` 2026-09-12): enriched execution rows, canonical user-ID assignee arrays, reset-safe stores with scoped generations, child My Day surface, 99-key core l10n slice; unit 916 / integration 315 / Jest 895+, serial pools 108+10; plan moved to `done/`, CR-WF-04/08 + CR-053-20 + BUG-API-001 archived. [Plan](../plans/done/cs-plan-066-wp7-canonical-client-state-family-ux.md).

- **CS-PLAN-065 / WP6 — actionable scheduling through My Day** (draft + Standard A `PASS` 2026-09-10, implementation + final Standard B `PASS` 2026-09-11): `SchedulePlanService` preview/apply aggregate (content-fingerprint previewVersion, ADR-012 lock order, stale-409 recovery), owner-only plan surface with child limits, Calendar/My Day reconciliation without reload; CR-WF-07 + CR-055-01 archived, plan moved to `done/`. [Plan](../plans/done/cs-plan-065-wp6-actionable-scheduling.md).

> One-line entries only. Older completed work lives in the tracked
> [`archive/`](archive/).
