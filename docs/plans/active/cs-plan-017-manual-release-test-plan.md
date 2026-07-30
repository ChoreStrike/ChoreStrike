# CS-PLAN-017: Manual Release Test Plan — Consolidate, Refresh & Gate

> **Status:** Plan (not started) — drafted 2026-07-27 from the test-pipeline review
> **Effort:** M
> **Priority:** P1 (user requirement 2026-07-27: **no release without an existing + executed manual test plan**)
> **Depends on:** CS-015b ✅ (landed 2026-07-28; dependency satisfied) and CS-016 ✅ (re-review done 2026-07-28; scheduling UI shipped). Scheduling is therefore written **in full** (no longer conditional).
> **Relates to:** `docs/backlog/backlog.md` → Release section (**RELEASE-001**, this plan's own tracker — moved to `archive/2026-07.md` on completion); `docs/release/release-strategy.md` exit criteria.

## 1. Problem Summary

A manual test plan must exist and be executed before anything is released. Today's state does not satisfy that:

- **`docs/testing/manual-test-checklist.md`** (v1.0.0, 2026-03-05) is **stale**: it tests archived features (a "User Management" section for the group-assignment UI removed in CS-011, a "Smart Scheduling Tests" section for the UI archived in CS-PLAN-003) and uses pre-rename "sparks" wording. It is generic — not tied to the release stages (v0.8 alpha / v0.9 beta / v1.0 stable), has no execution or sign-off record, and nothing references it as a gate.
- **`docs/testing/alpha-test-guide.md`** is German-only (project rule: docs are English) and Docker-centric; overlaps the checklist.
- **`docs/testing/family-test-setup.md`** overlaps again (manual scenarios + checklist sections); `docs/testing/critical-tests.md` lists automated critical tests, not manual ones.
- **`docs/engineering/deployment.md`** has only a *post-deployment* smoke list — no pre-release manual test step.
- **`docs/release/release-strategy.md`** defines stage exit criteria by policy but never names the manual test plan as one.

Net: four overlapping documents, none authoritative, none current, none gating.

## 2. Strategy

One authoritative, English, stage-aware manual release test plan **plus** an execution record per release. Shrink what automation already covers: the manual plan focuses on what E2E/unit tests cannot judge (exploratory UX, mobile/responsive behavior, performance feel, multi-user social flows on a real instance, upgrade/migration smoke on the personal server) and marks mechanically-covered items as "automated" with a pointer instead of a manual step.

1. **Phase 1 — Consolidate.** Create `docs/testing/release-test-plan.md` as the single source; merge the still-valid substance of the four existing docs; archive the superseded files per the archive policy; fix all references to them (`docs/README.md` + the `architecture.md` doc-tree comment).
2. **Phase 2 — Refresh to current features.** Coins (not sparks), visibility types (private/admin-only), teamwork rewards, titles, medals, minigames + boosts, checklist, overdue claiming, and **scheduling** (CS-016 shipped 2026-07-28 — written in full, no longer conditional). Every item verified against a live route/view.
3. **Phase 3 — Execution record.** Per-release sign-off template (version, stage, date, tester, result, issues → `backlog.md` links) lives in the plan; rule: a release tag requires a completed record. (Completed records move to `archive/` per release so the doc stays bounded.)
4. **Phase 4 — Wire the gate.** Reference the plan + record from `release-strategy.md` exit criteria and `deployment.md` as a *pre-release* step; index from `docs/README.md`.

## 3. Changes

Docs only. No `lib/`/`src/` change.

- **D1:** build `release-test-plan.md` fresh (preferred — clean structure) vs. rework `manual-test-checklist.md` in place. Default: fresh file, checklist archived.
- New `docs/testing/release-test-plan.md`:
  - Stage map: which sections run for alpha / beta / stable (alpha = full, beta = full + new-feature focus, stable = full + upgrade smoke).
  - Sections: first-run & house setup; floors/rooms; task lifecycle (create/complete/skip/reschedule/recurrence/checklist); visibility & roles (private/admin-only, child filtering, owner-only config); gamification (coins, combo, medals, titles, leaderboards, house health); minigames & boosts; overdue claiming & teamwork; multi-user on one instance; notifications; mobile/responsive; performance & stress feel; upgrade/migration smoke (personal instance); error handling & edge cases.
  - Every item: steps + expected result + a "covered by E2E?" marker where applicable.
  - Execution record template (per release).
- Merged into the new doc: valid scenarios from `family-test-setup.md` (5 family scenarios), the 4-phase alpha approach from `alpha-test-guide.md` (translated, as the stage protocols), relevant pointers from `critical-tests.md`.
- Archived (per policy, `archive/2026-07/`): `manual-test-checklist.md`, `alpha-test-guide.md`, `family-test-setup.md` (after merge), `critical-tests.md` (if fully merged; otherwise keep with a pointer header).
- Modified: `docs/release/release-strategy.md` (exit criteria name the plan + record), `docs/engineering/deployment.md` (pre-release step before the post-deployment list), `docs/engineering/architecture.md` (testing-doc tree comment → new plan), `docs/README.md` (index entry → new plan).

## 4. Tests

Doc-only. Validation:
- Relative-link-integrity grep across touched docs.
- Every checklist item maps to a live feature — verified against `src/router.js` / routed views / `appinfo/routes.php` (no phantom sections, same dead-UI check as CS-015).
- English-only grep on the new/merged content.
- Standard-A review of the resulting doc set.

## 5. File Manifest

```
new:      docs/testing/release-test-plan.md              (authoritative plan + execution record)
archived: archive/2026-07/testing-docs/manual-test-checklist.md
archived: archive/2026-07/testing-docs/alpha-test-guide.md      (after merge + translation)
archived: archive/2026-07/testing-docs/family-test-setup.md     (after merge)
archived: archive/2026-07/testing-docs/critical-tests.md        (only if fully merged)
modified: docs/release/release-strategy.md               (exit criteria gate on plan + record)
modified: docs/engineering/deployment.md                 (pre-release manual test step)
modified: docs/engineering/architecture.md               (testing-doc tree comment update)
modified: docs/README.md                                 (index entry → new plan)
modified: docs/backlog/backlog.md                        (RELEASE-001 → archive/2026-07.md on completion)
modified: docs/backlog/ACTIVE_TASKS.md
```

## 6. Implementation Order

1. Phase 1 — consolidate + archive (structure stands, old files moved).
2. Phase 2 — refresh content against live features; English-only.
3. Phase 3 — execution record template + release-tag rule.
4. Phase 4 — wire gate references (release-strategy, deployment, P8, README); review.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Scope creep into actually *executing* the alpha/beta tests | Medium | This plan creates the plan + record; execution happens per release and is tracked under the `backlog.md` Release section (RELEASE-002/-003). |
| Duplication with automated E2E grows stale again | Medium | Rule inside the doc: if E2E covers it reliably, the manual item is removed or marked "automated" with a spec pointer. (CS-016 shipped — its scheduling section is folded into this plan's checklist.) |
| Archiving the old docs breaks inbound links | Low | Link-integrity grep before/after; update `docs/README.md` + the `architecture.md` doc-tree comment in the same change. |

## 8. Acceptance Criteria

- [x] Single authoritative English release test plan exists at `docs/testing/release-test-plan.md`, stage-mapped (alpha/beta/stable).
- [x] No references to archived or renamed features (no User Management section, no "sparks"); every section maps to a live route/view.
- [x] Execution/sign-off record template included; the doc states that a release tag requires a completed record.
- [x] `release-strategy.md` exit criteria + `deployment.md` pre-release steps reference the plan; `architecture.md` doc-tree comment updated.
- [x] Superseded testing docs archived per the archive policy; link-integrity grep clean (incl. `architecture.md` + `README.md` references).
- [x] `docs/backlog/ACTIVE_TASKS.md` updated; **RELEASE-001** moved from `backlog.md` to `archive/2026-07.md` on completion.

---

## Review Outcome

**Reviewed:** 2026-07-30 · **Tier:** Standard A (plan, before `new/` → `active/`) · **Result:** APPROVED for activation (stale references corrected; no scope change).

**Verified accurate against the repo:**
- All four superseded testing docs exist under `docs/testing/`.
- `release-strategy.md` defines stage exit criteria but never names a manual test plan → the proposed gate is a real gap.
- `deployment.md` carries only a post-deployment smoke list; no pre-release manual step.
- No overlap with any active plan; **RELEASE-001** in `backlog.md` is this plan's own tracker.

**Fixed inline (staleness — files/concepts dissolved into `backlog.md`):**
- `phase-8-release-preparation.md` / `P8-INTERNAL-TEST` and `bugs.md` no longer exist → re-pointed to `backlog.md` Release section (RELEASE-001).
- "Depends on CS-015b" / "applies once CS-016 ships" were stale → both **Done** (2026-07-28); scheduling is written in full.
- Added `docs/engineering/architecture.md` to the manifest + Phase 1 (its doc-tree comment lists the four to-be-archived filenames and would otherwise rot).
- Clarified execution record: template lives in the doc; completed records move to `archive/` to stay bounded.

**Open note (discretionary, no change required):**
- If per-release records accumulate, a `docs/testing/release-records/` directory is a future option; the in-doc template is acceptable for now (KISS).

---

**Post-implementation review:** 2026-07-30 · **Tier:** Standard B (docs-only change set) · **Result:** PASS — all AC verified, no blockers.

**Verified against the live codebase:**
- All 26 E2E spec basenames cited in §2 exist under `tests/e2e/`.
- Every "Route surface" entry exists in `appinfo/routes.php` (`scheduling#*`, `room_task#range/dueToday/dueWithinDays/overdueClaims/filteredDaily`, `task#myPrivate`, `game#*`, `minigame#*` incl. boosts, `user#selectTitle`).
- §13 jobs/notifier exist (`DailyNotificationJob`, `StreakFreezeJob`, `StreakRestockJob`, `ChoreStrikeNotifier`); §8 medal types match `GameLogicService`; §7 visibility values match `Task::VISIBILITY_*`.
- Link-integrity grep: no live doc references the four archived filenames (only immutable done-plans CS-009/CS-016, historical). No "sparks" in `docs/testing/`; English-only confirmed.
- Archive policy honored: four docs + explanatory `README.md` under `archive/2026-07/testing-docs/` (gitignored); tracked copies removed.

**Nit (not fixed, no action required):** §7 "Route surface" line lists the `visibility_type` enum alongside real routes — it is a task field, not a route. Harmless; meaning stays clear.
