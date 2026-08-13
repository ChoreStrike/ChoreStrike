# CS-PLAN-024: Release E2E Regression Suite (companion to CS-017)

> **Status:** Implemented (all phases) — 2026-08-12 · ready for `active/ → done/` (manual move)
> **Effort:** L (grown over releases)
> **Priority:** P3
> **Backlog:** [`backlog.md`](../../backlog/backlog.md) → TECH-TEST-002
> **Relates:** CS-017 (release test plan), CS-022 (test coverage), CS-023 (time-travel — notifications)
>
> **Absorbs CS-022 Phase 5 (2026-08-12):** CS-022 deferred its Phase 5 E2E specs
> (`reward-config`, `task-templates`, `boost-shop`, `notifications`) to "a follow-up
> plan". This plan is that follow-up home — adding the economy-config / template /
> boost-shop specs here instead of a separate plan (DRY; this plan already carries an
> anti-duplication-with-CS-022 rule in §7). `notifications` (was "blocked on CS-023")
> is unblocked — CS-023's `occ chorestrike:trigger-jobs` landed 2026-08-04.

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
   `partial` with a pointer to the covering spec where one exists. The
   `Automated?` column with spec pointers already exists (CS-017 heritage) —
   this step verifies those markers and introduces the `partial` tag for rows
   whose coverage is incomplete.
2. **Lift partial → full Playwright specs**, one release at a time —
   **extend-first**: most rows already point at an existing spec
   (`task-completion-full-flow`, `overdue-task-claiming`, `private-tasks`,
   `multi-user-interactions`, `teamwork-rewards`, …); extend that spec instead
   of forking, and create a new `release-*` file only where no existing home
   covers the row:
   - Full task lifecycle (create / complete / skip / reschedule / recurrence / checklist).
   - Overdue-claim + teamwork.
   - Visibility & roles (private / admin-only, child filtering).
   - Gamification (medals, leaderboards, house health).
   - **Reward economy (absorbed from CS-022 Phase 5):** reward-config (owner edits
     coin/multiplier/medal-threshold config; non-owner → 403), task-templates (create +
     apply-to-room; CRUD), boost-shop (purchase: coins decrease + boost active;
     insufficient-coins rejection).
3. **Notifications** — CS-023's `occ chorestrike:trigger-jobs --as-of` now exists, so
   the job-driven notification regression spec is unblocked. Note the trigger split:
   title/medal unlock and weekly-goal notifications fire **synchronously on task
   completion** (`RoomService` / `GameLogicService`, no job needed); only the
   daily/streak reminders need `trigger-jobs --as-of`. Scope: title unlock + daily
   reminder (open the bell, assert text + dismiss) plus the weekly-goal notification —
   the last unmarked row in release-test-plan §13 (de-duped via
   `HouseMapper::claimWeeklyGoalNotification`).
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

- **E2E (Playwright):** new or extended specs under `tests/e2e/`, reusing the
  `family` profile. Each spec maps to a `release-test-plan.md` section.
  Seed-mutating specs (task completion, coin spend, boost purchase, notification
  re-seed) are registered in `SEED_DEPENDENT_SPECS` (`chromium-serial-db`
  project) per the CS-015b/CS-023 convention.
- **No new unit/integration** beyond what CS-022 already covers.

## 5. File Manifest

> The `new:` entries are **tentative** — the Phase 1 audit decides per row
> whether an existing spec is extended (preferred, extend-first rule §2) or a
> new file is created. CS-022 Phase 5 planned the economy specs without the
> `release-` prefix; the prefix aligns them with this companion suite.

```
new:      tests/e2e/release-task-lifecycle.spec.ts
new:      tests/e2e/release-overdue-teamwork.spec.ts
new:      tests/e2e/release-visibility-roles.spec.ts
new:      tests/e2e/release-gamification.spec.ts       (later phase)
new:      tests/e2e/release-notifications.spec.ts      (CS-023 trigger-jobs now available)
new:      tests/e2e/release-reward-config.spec.ts      (absorbed from CS-022 Phase 5)
new:      tests/e2e/release-task-templates.spec.ts     (absorbed from CS-022 Phase 5)
new:      tests/e2e/release-boost-shop.spec.ts         (absorbed from CS-022 Phase 5)
modified: playwright.config.ts                         (SEED_DEPENDENT_SPECS entries for seed-mutating specs)
modified: docs/testing/release-test-plan.md            (markers updated as specs land)
```

## 6. Implementation Order (one release at a time)

1. **Phase 1 — Audit + tag:** go through `release-test-plan.md`, assign tags.
2. **Phase 2 — Task lifecycle** spec (highest value, most repeated manual step).
3. **Phase 3 — Overdue/teamwork + visibility/roles.**
4. **Phase 4 — Gamification + reward economy** (medals/leaderboards, plus reward-config /
   task-templates / boost-shop — absorbed from CS-022 Phase 5).
5. **Phase 5 — Notifications** (CS-023's `trigger-jobs` command now exists).

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Suite grows flaky like the pre-CS-015b state | Medium | Reuse per-user `storageState` + `getByTestId` discipline from CS-015b; keep `--retries=0` green |
| Duplication with the existing E2E suite | High | Extend-first rule (§2): the Phase 1 audit decides per row; a new `release-*` file only where no existing spec covers the row. (CS-022 Phase 5 produced no specs — it was absorbed here — so there is no CS-022 suite to collide with.) |
| Manual plan markers rot again | Medium | Optional final step (out of scope until cheap): a check that every `automated` marker resolves to an existing spec file — decide CI lint vs Jest check if implemented. `partial` rows legitimately lack a full pointer and are not the check target. |

## 8. Acceptance Criteria

- [x] Every `automated` marker in `release-test-plan.md` points to a green spec.
- [x] Every row that is not genuinely exploratory / mobile / social / perf /
      upgrade-smoke is marked `automated` (spec pointer) or `partial` (gap note);
      rows are kept and marked per the document's drift rule, not removed.
- [x] New specs run green in the default E2E matrix (`--retries=0`); seed-mutating
      specs are registered in `SEED_DEPENDENT_SPECS` (`chromium-serial-db`).
- [x] `release-test-plan.md` documents the tagging convention
      (automated/manual/partial + spec pointer), extending the existing
      `Automated?` column documentation.

### Implementation outcome (2026-08-12)

All five phases landed in one pass. Six `release-*` specs were created (not
eight): per the extend-first rule (§2) + review finding #2, the overdue/teamwork
rows kept their existing spec pointers (`overdue-task-claiming`,
`teamwork-rewards`) and the gamification rows kept theirs (`leaderboard`,
`title-selection`, PHPUnit `GameLogicServiceTest`) — no duplicative
`release-overdue-teamwork` / `release-gamification` files. Verification:
`npx playwright test <6 files> --project=chromium-serial-db --retries=0` →
**11/11 spec tests green, 0 skipped, 0 failed** (~56 s).

---

## Review Outcome

### Standard-A Plan Review — 2026-08-12

**Scope:** `docs/plans/new/cs-plan-024-release-e2e-regression-suite.md` (full plan).
**Tier:** Standard A (plan review before `new/` → `active/`).
**Verification:** all factual claims checked first-hand against the current tree
(backlog TECH-TEST-002 + absorption note, CS-022 Phase 5 deferral + absence of the
four specs in `tests/e2e/`, CS-023 `trigger-jobs --as-of` availability, CS-017 §7
anti-rot rule, `release-test-plan.md` `Automated?` column + drift rule,
`playwright.config.ts` projects/`SEED_DEPENDENT_SPECS`, notification trigger paths
in `RoomService`/`GameLogicService`/`HouseMapper`).

**Findings & fixes (7 fixed inline):**

| # | Sev | Issue | Status |
|---|-----|-------|--------|
| 1 | major | AC #2 ("manual sections restricted to …") contradicted the drift rule of `release-test-plan.md` — covered rows are marked `Automated`, not removed | **fixed** |
| 2 | major | Manifest proposed 8 new `release-*` specs although most target rows already point at existing specs; no extend-first strategy | **fixed** (§2 rule + §5 tentative note) |
| 3 | minor | Risk row "Duplication with CS-022 specs" stale — CS-022 Phase 5 produced no specs (absorbed here) | **fixed** |
| 4 | minor | Rot mitigation vague, logically inverted (`partial` items lack a full pointer by definition) and unbudgeted (not in manifest/AC) | **fixed** (check semantics corrected → every `automated` marker resolves; marked optional) |
| 5 | minor | Phase 1 framed as greenfield tagging although the `Automated?` column + spec pointers already exist (CS-017 heritage) | **fixed** |
| 6 | minor | Notifications phase conflated synchronous (title/medal/weekly-goal, fired on completion) and job-driven notifications; weekly-goal row (last unmarked §13 row) unaddressed | **fixed** (trigger split documented, weekly goal in scope) |
| 7 | minor | No mention of the `chromium-serial-db` / `SEED_DEPENDENT_SPECS` convention for seed-mutating specs | **fixed** (§4 + manifest + AC) |

**Verified correct (no action needed):** TECH-TEST-002 link + absorption note in
`backlog.md`; CS-022 Phase 5 deferral claim; `trigger-jobs --as-of` existence;
CS-017 §7 anti-rot rule; `family` profile; no overlap with any active plan
(ACTIVE_TASKS "In Progress" empty); §3 "no `lib/`/`src/` changes" consistent with
the CS-019-hardened test-endpoint surface; phases individually validatable.

**Verdict:** No blocks. 2 majors + 5 minors fixed inline. Plan is ready for
`new/` → `active/` promotion (manual move by the user).

### Standard-B Code Review — 2026-08-12

**Scope:** uncommitted CS-024 implementation — six `release-*` specs,
`playwright.config.ts` (`SEED_DEPENDENT_SPECS`), `docs/testing/release-test-plan.md`
(§2 pointer list + §2.1 tagging convention + row markers), backlog/ACTIVE_TASKS
updates, plan moves (CS-024 `new/ → active/`, CS-026 `active/ → done/`).
**Tier:** Standard B (code review; tests + docs only, no `lib/`/`src/` changes).
**Verification:** every route/verb and response shape the specs assert was
checked first-hand against the backend (`appinfo/routes.php`; Task / RoomTask /
User / Template / Minigame / RewardConfig controllers; `MinigameService` boost
costs + score bounds + coin award; `RewardConfigService` keys;
`NotificationService` object type `weekly_goal`; `RoomService` weekly-goal
reward + post-commit dispatch; `HouseUser`/`RoomTask` `jsonSerialize`), the
`family` seed roles (`TestDataService`: mom=owner, teen=child, friend=member),
the CSRF-primed `createUserContext` pattern, and the `chromium-serial-db`
registration (all six specs mutate seed state; `--list` resolves 11/11 tests to
the serial pool and 0 to the parallel pools after the anchoring fix).

**Findings & fixes (3 fixed inline):**

| # | Sev | Issue | Status |
|---|-----|-------|--------|
| 1 | major | `release-test-plan.md`: the §2.1 insertion dropped the `## 3. First-Run & House Setup` heading — the §3 table orphaned, numbering inconsistent with the §18 sign-off checklist | **fixed** (heading restored) |
| 2 | minor | `release-test-plan.md` §7 row said "Owner-only config / Owner can read/edit" although `reward_config#update` is `@AdminRequired` (Nextcloud server admin) — the spec verifies admin vs. non-admin | **fixed** (row renamed "Admin-only config (global)" with the accurate contract; spec header comment updated) |
| 3 | minor | `playwright.config.ts`: the six new `SEED_DEPENDENT_SPECS` regexes were unanchored although the config documents the `[/\\]…$` anchoring convention (substring-collision incident) | **fixed** (anchored; `--list` re-verified) |

**Verified correct (no action needed):** all endpoint contracts (params + JSON
shapes, incl. `DataResponse` rendered at the JSON root); authorization negative
branches (403 role change by member, 403 config update by non-admin, 400
insufficient-coins purchase); `oc_appconfig` restore in `finally` (global
config survives the re-seed); extend-first rule honoured (no duplicative
overdue/gamification specs); 11-test count matches the implementation outcome;
license/JSDoc headers, tab style, no `console.*`, no i18n surface, no
workarounds.

**Quality gate:** `npm run lint:fix` clean; Jest 677/677 green. No PHP or style
changes → no Docker/stylelint run required. E2E suite not re-run (green run
documented in the implementation outcome; config change re-verified via
`--list`).

**Backlog:** none — all findings fixed inline. Plan remains ready for
`active/ → done/` (manual move).
