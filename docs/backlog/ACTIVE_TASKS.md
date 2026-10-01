# Active Tasks Registry

> **Purpose:** Live coordination of work **in progress right now** plus a short
> rolling "Recently Completed" window. This is *not* a history log.
> **Update Rule:** every agent updates this file on start / pause / complete.
> **Housekeeping Rule:** move a completed entry to
> [`archive/YYYY-MM.md`](archive/) once it is older than ~2 weeks or beyond the
> ~10-entry rolling cap. **Open work** (bugs / features / debt / follow-ups)
> lives in [`backlog.md`](backlog.md), **not** here.

## In Progress

- **CS-PLAN-070 / parent - family rhythm and reward clarification OPEN** (WP0-WP6 delivered; CONCEPT-001 remains In Progress for WP7-WP9 and family evidence). [Parent](../plans/active/cs-plan-070-family-rhythm-and-reward-clarification.md).
- **CS-PLAN-078 / WP7 catalog and request backend ACTIVE, implementation not started** (activated 2026-10-01 after Standard A `PASS`; next: §6 step 2 fresh chain/entities, catalog stays default-off). [Plan](../plans/active/cs-plan-078-wp7-catalog-and-request-backend.md).

## Recently Completed (rolling - last ~2 weeks / max 10)

- **CS-PLAN-078 / WP7 draft created and activated** (2026-10-01): catalog/request backend plan — exact reservations/settlements/refunds, current-role lifecycle, privacy/erasure, digest integration, T6/race/rollback/browser contract mapping; the same day's Standard A verified every baseline citation and manifest path with zero findings and activated the plan (`PASS`); documentation gates only, no runtime changes yet. [Plan](../plans/active/cs-plan-078-wp7-catalog-and-request-backend.md) · [Review](archive/CODE_REVIEW_2026-10-01.md).
- **CS-PLAN-077 / WP6 calm core UI delivered** (2026-10-01): final Standard B `PASS-WITH-FIXES`; RF-077-03..06 accepted as documented scope consolidations, RF-077-07..09 fixed in-round (l10n extraction gap closed — 1108 keys with full German, digestTime parity, zzdebug spec deleted); FEAT-UX-024/025 closed to the monthly archive, FEAT-UX-023 keeps its WP8 remainder; full browser gate rerun green on the reviewed tree; plan moved to `done/`. [Plan](../plans/done/cs-plan-077-wp6-calm-core-ui.md) · [Review](archive/CODE_REVIEW_2026-10-01.md).
- **CS-PLAN-077 / WP6 draft created and activated** (2026-09-30): calm-core-UI sub-plan — all-age My Day landing/content, preference-gated celebration with reduced-motion override, full `NotificationPreferences.vue` + optional-area toggles/arcade consumer, pause UI on the delivered WP2 API, occurrence agreement entry points, shared-device store reset, no-Coin UI remnants, essential help slice; the same day's Standard A resolved Q-077-01..05 (all Option A) and activated the plan. The original uncommitted draft was lost with the WP5 scoped commit and was recreated at baseline `fd70cd7` (2026-09-30); documentation gates only, no runtime implementation in the drafting round. [Plan](../plans/done/cs-plan-077-wp6-calm-core-ui.md) · [Review](archive/CODE_REVIEW_2026-09-30.md).

- **CS-PLAN-076 / WP5 delivered** (2026-09-30): final Standard B `PASS-WITH-FIXES`, RF-076-01..11 resolved; self-only preferences, quiet at-most-once digest and transactional source queue; unit 1028, integration 493, Jest 1062 and complete E2E `run-xpEKYP` green; plan moved to `done/`, WP6 draft/metadata excluded from the scoped commit. [Plan](../plans/done/cs-plan-076-wp5-preferences-and-digest-delivery.md) · [Review](archive/CODE_REVIEW_2026-09-30.md).
- **CS-PLAN-075 / WP4 delivered** (2026-09-29): final Standard B `PASS-WITH-FIXES`, RF-075-01..05 resolved; rhythm/goals/ranking, ADR-015, consent persistence and single-series recognition; plan in `done/`, CONCEPT-001 remains open. [Plan](../plans/done/cs-plan-075-wp4-rhythm-goals-and-ranking.md) · [Review](archive/CODE_REVIEW_2026-09-29.md).
- **CS-PLAN-074 / WP3 delivered** (2026-09-27): final Standard B re-review `PASS-WITH-FIXES`, RF-074-WP3-01..10 resolved; exact work shares/wallet ledger, targeted corrections, room journal/replay, ADR-014 and no-Coin arcade; CR-SVC-63 archived, plan in `done/`. [Plan](../plans/done/cs-plan-074-wp3-work-accounting-and-replay.md) · [Review](archive/CODE_REVIEW_2026-09-27.md).
- **CS-PLAN-073 / WP2 delivered** (2026-09-25): final Standard B `PASS-WITH-FIXES`, RF-01..28 resolved; agreements/capacity/pauses, planner cutover and durable contracts; CR-SVC-64 archived, plan in `done/`, FEAT-UX-025 remains partially open. [Plan](../plans/done/cs-plan-073-wp2-agreements-capacity-pauses.md) · [Review](archive/CODE_REVIEW_2026-09-25.md).
- **CS-PLAN-072 / WP1 delivered** (2026-09-23): final Standard B round 2 `PASS-WITH-FIXES`; occurrence authority, recurrence revisions, selector/receipt/checklist seams and ADR-013; six-collaborator split plus required evidence verified, plan in `done/`. [Plan](../plans/done/cs-plan-072-wp1-occurrence-timeline.md) · [Review](archive/CODE_REVIEW_2026-09-23.md).
- **CS-PLAN-071 / WP0 delivered** (2026-09-19): final Standard B `PASS-WITH-FIXES`; shared house-first locks, commands/wallet foundation, tombstone retention and account erasure; RF-071-01..03 resolved, plan in `done/`. [Plan](../plans/done/cs-plan-071-wp0-write-identity-foundation.md) · [Review](archive/CODE_REVIEW_2026-09-19.md).

> One-line entries only. Older and interim history lives in the tracked
> [`archive/`](archive/).
