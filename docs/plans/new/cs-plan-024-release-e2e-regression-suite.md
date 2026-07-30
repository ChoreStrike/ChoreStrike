# CS-PLAN-024: Release E2E Regression Suite (companion to CS-017)

> **Status:** Plan (not started) — drafted 2026-07-30 from the manual-test review
> **Effort:** L (grown over releases)
> **Priority:** P3
> **Backlog:** [`backlog.md`](../../backlog/backlog.md) → TECH-TEST-002
> **Relates:** CS-017 (release test plan), CS-022 (test coverage), CS-023 (time-travel — notifications)

## 1. Problem Summary

CS-017 is deliberately **manual** (exploratory UX, mobile/responsive feel,
multi-user social flows on a real instance, upgrade smoke). But many checklist
items in `docs/testing/release-test-plan.md` are mechanically repeatable (full
task lifecycle, overdue-claim, visibility filtering, medals/leaderboards). Those
should be automated E2E so the per-release manual pass shrinks to the genuinely
non-automatable items. The anti-rot rule already exists in CS-017 §7
("if E2E covers it reliably, the manual item is removed or marked automated"),
but it is not yet operationalized.

## 2. Strategy

1. **Audit** `release-test-plan.md` → tag every item `automated` / `manual` /
   `partial` with a pointer to the covering spec where one exists.
2. **Lift partial → full Playwright specs**, one release at a time:
   - Full task lifecycle (create / complete / skip / reschedule / recurrence / checklist).
   - Overdue-claim + teamwork.
   - Visibility & roles (private / admin-only, child filtering).
   - Gamification (medals, leaderboards, house health).
3. **Notifications** are blocked on CS-023's `occ` job trigger.
4. **Update** `release-test-plan.md` "covered by E2E?" markers as each spec
   lands — this *is* the CS-017 §7 anti-rot rule, made executable.

The manual plan keeps: exploratory UX, performance feel, real mobile/responsive,
multi-user social dynamics, upgrade/migration smoke on the personal instance.

## 3. Backend / Frontend Changes

- **Backend** (`lib/`): none (unless a test-only hook is needed; prefer existing
  `test-endpoint` surface — already security-hardened in CS-019).
- **Frontend** (`src/`): none.
- **Cross-layer contracts**: none.
- **Tests**: the bulk of the work is here.

## 4. Tests

- **E2E (Playwright):** new specs under `tests/e2e/`, reusing the `family`
  profile. Each new spec maps to a `release-test-plan.md` section.
- **No new unit/integration** beyond what CS-022 already covers.

## 5. File Manifest

```
new:      tests/e2e/release-task-lifecycle.spec.ts
new:      tests/e2e/release-overdue-teamwork.spec.ts
new:      tests/e2e/release-visibility-roles.spec.ts
new:      tests/e2e/release-gamification.spec.ts       (later phase)
new:      tests/e2e/release-notifications.spec.ts      (after CS-023)
modified: docs/testing/release-test-plan.md            (markers updated as specs land)
```

## 6. Implementation Order (one release at a time)

1. **Phase 1 — Audit + tag:** go through `release-test-plan.md`, assign tags.
2. **Phase 2 — Task lifecycle** spec (highest value, most repeated manual step).
3. **Phase 3 — Overdue/teamwork + visibility/roles.**
4. **Phase 4 — Gamification** (medals/leaderboards).
5. **Phase 5 — Notifications** (after CS-023's trigger command exists).

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Suite grows flaky like the pre-CS-015b state | Medium | Reuse per-user `storageState` + `getByTestId` discipline from CS-015b; keep `--retries=0` green |
| Duplication with CS-022 specs | Medium | Audit before writing; extend an existing spec rather than fork when the scope overlaps |
| Manual plan markers rot again | Medium | CI/CD lint (or a Jest check) that no `partial` item lacks a spec pointer |

## 8. Acceptance Criteria

- [ ] Every `automated` marker in `release-test-plan.md` points to a green spec.
- [ ] Manual-plan sections are restricted to exploratory / mobile / social / perf / upgrade-smoke items.
- [ ] New specs run green in the default E2E matrix (`--retries=0`).
- [ ] `release-test-plan.md` documents the tagging convention (automated/manual/partial + spec pointer).

---

**Review:** proposed `/plan-review` after creation (mandatory for plans before `new/` → `active/`).
