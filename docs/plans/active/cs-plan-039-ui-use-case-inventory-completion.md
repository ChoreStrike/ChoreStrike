# CS-PLAN-039: UI Use-Case Inventory — Completion & Gap Registration

> **Status:** Plan (not started)
> **Effort:** S
> **Priority:** P2
> **Depends on:** none — builds on the endpoint audit 2026-08-21 and the
> Standard-B review of `docs/concepts/ui-use-cases.md` (2026-08-21)
> **Related issue:** Review of `docs/concepts/ui-use-cases.md` (2026-08-21)

## 1. Problem Summary

`docs/concepts/ui-use-cases.md` is the working reference for "what to build
next". The Standard-B review (2026-08-21) already fixed the factual errors
inline (UC-01 wizard invite ✅, missing live consumers for `due-today` /
`range` / `GET assignees`, new UC-15 for CalendarView), but the document and
the backlog remain incomplete:

1. **~12 routes appear nowhere in the inventory** (index/show/alias routes,
   `userTasks`, `roomStats`, `POST/DELETE /assignees`, `checklists/complete`,
   test routes) — the "all 111 routes mapped" claim is not auditable.
2. **❌/⚠️ rows annotate dead store wrappers inconsistently** (`fetchHouseStats`
   noted; `updateRoomName`/`deleteRoom`, `fetchRoomsNeedingCleaning`,
   `fetchUserTasks`/`fetchRoomTaskStats`/`fetchUserTaskStats` not noted).
3. **`backlog.md` UX-011 is stale** — still says "no invite step (see
   UX-003)" although wizard step 4 shipped with CS-PLAN-038.
4. **Concept docs describe features with no surface and no inventory entry:**
   completed-task history (design.md §6), task approval workflow for children
   (design.md §5 + gamification open decision), Task Pass & support actions
   (gamification doc), streak-freeze visibility (shipped mechanic, no UI),
   NC-Tasks sync UX (task-model-and-sync.md, 6 backend-only routes),
   overcapacity warning (energy-and-balancing.md policy), house insights /
   fairness view (dead `GET /house/stats`, fairness-normalized-by-capacity
   requirement).
5. **The review's whitespace ideas are unrecorded** (help layer, notification
   settings, vacation mode, real-world reward shop, print view, global search,
   bulk maintenance, a11y/kids conventions, error-state conventions). Backlog
   discipline requires open items to live in `backlog.md` — unrecorded ideas
   are lost ideas.

## 2. Strategy

Docs-only plan in three parts:

1. **Complete the inventory** — a route appendix covering every route not
   listed individually (each verified against `src/`), a consistent
   dead-store-wrapper annotation pass, and the UX-011 backlog text fix.
2. **Add a "Whitespace (⛔ candidates)" section** to `ui-use-cases.md`
   recording documented-but-unbuilt intent (Group A) and new ideas (Group B)
   with decision tags and concept-doc sources — **without** pre-empting
   expose-or-prune decisions (neutrality principle of the audit).
3. **Register every candidate in `backlog.md`** as prioritized `FEAT-UX-*`
   items in a new themed section, so follow-up implementation plans can be
   activated directly from the backlog.

No code changes; no expose/prune decisions made here.

## 3. Backend / Frontend Changes

None — docs-only. Affected documents see file manifest (§5).

## 4. Tests

No code/test gate affected (docs-only, per DOC-STD-001 precedent).
Verification instead:

- **Route coverage check:** every one of the 111 routes in
  `appinfo/routes.php` is accounted for exactly once in
  `ui-use-cases.md` (individual row, appendix row, or explicit
  exclusion note — `page#index`) — grep/count cross-check.
- **Link integrity:** relative links in the changed docs resolve
  (grep for broken targets).
- **Backlog format:** new items follow the `README.md` item format
  (Status / Effort / Source + acceptance criteria).

## 5. File Manifest

```
new:      docs/plans/new/cs-plan-039-ui-use-case-inventory-completion.md (this plan)
modified: docs/concepts/ui-use-cases.md
modified: docs/backlog/backlog.md
modified: docs/backlog/ACTIVE_TASKS.md
```

## 6. Implementation Order

1. Register the plan in `ACTIVE_TASKS.md` → *In Progress*.
2. **Route appendix** in `ui-use-cases.md`: verify each unlisted route against
   `src/` (live consumer / dead store wrapper / unused / alias / test-only)
   and record it:
   - `GET /room-tasks/user/{userId}` (dead wrapper `fetchUserTasks`)
    - `GET /room-tasks/room/{roomId}/stats` (dead wrapper `fetchRoomTaskStats`)
   - `POST|DELETE /room-tasks/{id}/assignees` (add/remove variants; note that
     `GET /room-tasks/{id}/assignees` is **live** via TodayView)
   - `GET /checklists/complete/{roomTaskId}` (no wrapper, no UI)
   - `GET /tasks/{id}`, `GET /floors/{id}`, `GET /rooms/{id}`,
     `GET /floors`, `GET /rooms`, `GET /room-tasks`, `GET /room-tasks/{id}`
   - aliases `POST /floor-create`, `POST /room-create`; explicit
     `POST /floors/create`, `POST /rooms/create`
   - Test Data API (3 routes, test-mode only) — note `page#index` exclusion.
3. **Annotation pass:** uniform "(store wrapper … dead/live)" notes on all
   ❌/⚠️ rows (`updateRoomName`/`deleteRoom`, `fetchRoomsNeedingCleaning`,
    `fetchUserTasks`/`fetchRoomTaskStats`/`fetchUserTaskStats`).
4. **Fix stale UX-011** text in `backlog.md` (invite step exists since
   CS-PLAN-038; remainder is naming + skip persistence).
5. **Whitespace section** in `ui-use-cases.md` — each entry with decision tag
   (`build-new` / `expose` / `needs-decision` / `post-MVP`) and concept-doc
   source link:
   - **Group A — documented intent, no surface (7):** history & activity log;
     house insights & fairness view; task-approval workflow for children;
     Task Pass & support actions; streak-freeze visibility; NC-Tasks sync
     surface; overcapacity warning.
   - **Group B — review ideas (9):** onboarding/help layer; notification
     settings; vacation/house-pause mode; real-world reward shop; print view;
     global search; bulk maintenance ops; a11y & kids-mode conventions (incl.
     mobile-first); error/empty/offline-state conventions.
6. **Backlog registration:** new themed section
   `## Review Follow-ups — UI Use-Case Review (2026-08-21)` with
   **FEAT-UX-016 … FEAT-UX-031** (mapping below), each with
   `Source: Review of docs/concepts/ui-use-cases.md (2026-08-21)` and 2–3
   acceptance criteria:

   | ID | Pri | Candidate |
   |---|---|---|
   | FEAT-UX-016 | P2 | History & activity log (who did what when; feeds off dead user-stats endpoints) |
   | FEAT-UX-017 | P2 | House insights & fairness view (`GET /house/stats`; energy normalized by capacity; contribution-vs-coins split) |
   | FEAT-UX-018 | P3 | Task-approval workflow for children (needs-review state, owner queue, backend gating) |
   | FEAT-UX-019 | P4 | Task Pass & support actions (gag mechanic, post-MVP) |
   | FEAT-UX-020 | P3 | Streak-freeze visibility (inventory + consumption surface) |
   | FEAT-UX-021 | P3 | NC-Tasks sync surface (link/pending/conflict; depends on task-sync expose-or-prune decision) |
   | FEAT-UX-022 | P3 | Overcapacity warning surface (verify first — may already exist in SchedulingView; close with note if so) |
   | FEAT-UX-023 | P3 | Onboarding & help layer (tour, glossary, contextual hints; cf. UX-007) |
   | FEAT-UX-024 | P3 | Notification settings (digest toggle, quiet hours; complements UC-14 reminder ⛔) |
   | FEAT-UX-025 | P4 | Vacation / house-pause mode |
   | FEAT-UX-026 | P4 | Real-world reward shop (coins → owner-defined rewards) |
   | FEAT-UX-027 | P4 | Print view (weekly plan / calendar) |
   | FEAT-UX-028 | P3 | Global search (`GET /tasks/search` exists, no UI) |
   | FEAT-UX-029 | P4 | Bulk maintenance ops (reschedule overdue, seasonal deactivate) |
   | FEAT-UX-030 | P3 | A11y & kids-mode conventions (keyboard/SR/reduced-motion; open question: simplified child surface) |
   | FEAT-UX-031 | P3 | Error/empty/offline-state conventions |

7. Update the **gap index** of `ui-use-cases.md` where the new rows change
   open gaps; refresh the *Last verified* footer.
8. **Completion:** append `## Review Outcome` to this plan, add the
   one-liner to `ACTIVE_TASKS.md` → *Recently Completed*, recommend the
   `new/ → done/` move (knowledge integration is satisfied by the changes
   living in permanent docs: `docs/concepts/` + `docs/backlog/`).

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Backlog bloat from 16 new items | medium | honest priority tags; P4 items explicitly "future / needs decision"; whitespace section is an index into the backlog IDs, not a second list |
| Route appendix misclassifies a consumer | low | grep each route in `src/` before classifying; ambiguous cases stay neutrally worded |
| Pre-empting expose-or-prune decisions | medium | neutral phrasing per the audit's ground rule; decision tag `needs-decision` where open |
| FEAT-UX-022 already implemented | low | verify SchedulingView first; close with a note instead of building |
| Stale-text fixes drift again | low | *Last verified* footer + gap index updated in the same change |

## 8. Acceptance Criteria

- [ ] All 111 routes in `appinfo/routes.php` are accounted for exactly
      once in `ui-use-cases.md` (individual row, appendix row with
      status, or explicit `page#index` exclusion note).
- [ ] ❌/⚠️ rows annotate dead store wrappers uniformly.
- [ ] `backlog.md` UX-011 text reflects the shipped wizard invite step.
- [ ] Whitespace section records Group A (7) + Group B (9) with decision tags
      and concept-doc sources.
- [ ] `backlog.md` contains the new themed section with FEAT-UX-016…031 in
      README item format.
- [ ] Route coverage check + link-integrity grep clean; build gate unaffected
      (docs-only change).
- [ ] `docs/backlog/ACTIVE_TASKS.md` updated.
- [ ] Durable outcomes integrated into permanent docs (this plan's changes
      live in `docs/concepts/` and `docs/backlog/` — plans are temporary).

## 9. Review Outcome

**Implemented 2026-08-21 (docs-only).** All steps of §6 executed:

1. **Route appendix** added to `ui-use-cases.md` as *Appendix A — Route
   coverage ledger*: 19 routes in 5 themed groups (4 create aliases/duplicates,
   6 index/show reads, 5 unused room-task variants incl. `due-within-days`
   which the plan's list had missed, 1 checklist complete-read, 3 test-only
   routes), each with controller, store wrapper, and consumer status verified
   by grep against `src/`. `GET /room-tasks` (index) is accounted via its
   existing UC-04 row (preamble note) to keep single accounting.
2. **Annotation pass**: uniform `(store wrapper … dead)` notes applied to all
   ❌/⚠️ rows — beyond the plan's list also `reorderFloors`, `deleteTask`,
   `searchTasks`, `fetchTasksByCategory`/`fetchTaskCategories`,
   `fetchMyPrivateTasks`, `deactivateRoomTask`/`reactivateRoomTask`,
   `assignRoomTask`/`unassignRoomTask` (dead chain via unconsumed
   `bulkAssignTasks`), `updateUserProfile`, and the UC-13 stats row
   (`fetchUserTaskStats` dead / no wrapper for `/users/{id}/stats`).
3. **UX-011 fixed** in `backlog.md`: invite step marked shipped (CS-PLAN-038,
   wizard step 4); remainder is naming + skip persistence.
4. **Whitespace section** added: Group A (7) + Group B (9), each with decision
   tag and concept-doc source link; neutral wording preserved.
5. **FEAT-UX-016…031** registered in a new `backlog.md` themed section
   (`Review Follow-ups — UI Use-Case Review (2026-08-21)`), README item format,
   each with Source line and 2–3 acceptance criteria.
6. **Gap index** extended (UC-02/05/10/13/14 rows + an "all" pointer row);
   *Last verified* footer refreshed.

**Verification:** scripted route cross-check — all **111** routes parsed from
`appinfo/routes.php`; 96 match the doc directly, 15 are covered by shorthand
cells (`PUT /tasks/{id}/…`, `POST …/unassign`, `titles(/select)`,
`POST /task-sync/*`, `leaderboard(/weekly)`, `/rank`, `/recent`) — every route
accounted for exactly once (91 UC rows + 19 appendix routes + `page#index`
exclusion). Relative links in both changed docs resolve (grep-checked).
Build gate unaffected (docs-only, per DOC-STD-001 precedent).

**Recommendation:** move this plan `active/ → done/` (knowledge integration is
satisfied: the durable content lives in `docs/concepts/ui-use-cases.md` and
`docs/backlog/backlog.md`).
