# CS-PLAN-050: E2E Long-Tail Integrity Sweep & Docs-Encoding Gate

> **Status:** Done (implemented + Standard-B reviewed 2026-08-26)
> **Effort:** M
> **Priority:** P2
> **Depends on:** none (CS-018 flake-free prerequisite met; CS-PLAN-048 closed the known E2E bugs)
> **Closes:** P9-E2E-002b (P2) · TECH-DOCS-001 (P3, quick-win)

## 1. Problem Summary

**P9-E2E-002b** — the E2E long tail still pads with fixed sleeps and vacuous
assertion patterns. Re-verified against the tree (2026-08-26): **7 specs with
23 `waitForTimeout` calls** (the backlog's "~13 specs / ~27 calls" is stale and
gets corrected with this plan):

| Spec | Calls | Verified state |
|---|---|---|
| `smoke-tests.spec.ts` | 5 (16/33/64/91/103) | Vacuous: `if (isVisible)` nav loop with try/catch + `console.log`; overlaps `fast-core-tests.spec.ts` nearly 1:1 (app loads / nav / console errors / basic elements) |
| `minimal-smoke-tests.spec.ts` | 4 (13/46/71/96) | Vacuous: `try {} catch {}` URL loop, `if (count > 0)` click-anywhere test; `beforeEach` pads 3000 ms |
| `daily-tasks-flow.spec.ts` | 3 (33/44/55) | **Dead UI**: `daily-task-list`/`daily-progress` selectors and Skip/Reschedule buttons do not exist in `src/` (DailyTaskList archived by CS-PLAN-038); every test is `if (count > 0)`-vacuous; superseded by `today-view.spec.ts` |
| `multi-user-interactions.spec.ts` | 6 (54/77/129/159/195/216) | The 8-line floor-expand loop is copy-pasted **5×** (the 6th sleep, line 77, is a standalone single-floor toggle); leaderboard test is conditional (`if isVisible → assert entries, else quick-stats fallback`) |
| `room-management.spec.ts` | 3 (69/84/192) | Line 191 already auto-waits for the floor testid — the 300 ms pad is redundant; 69/84 are blind waits before text assertions |
| `room-detail.spec.ts` | 1 (94) | Pure padding — the modal-visible assertion at line 97 already follows; plus vacuous `if (roomCount === 0) return` early-exits |
| `task-checklist-and-features.spec.ts` | 1 (73) | Checkbox toggle inside `if (count > 0)` with **no state assertion** ("Wait for state change" comments only) |

Same disease, secondary: `fast-core-tests.spec.ts` wraps two tests
(TaskManager / Settings) in try/catch + `console.log` and
`waitForElementWithFallback(...).catch(() => null)` + `if (element)` — they can
fail silently and still pass.

**TECH-DOCS-001** — backlog/archive docs repeatedly accumulated literal `?`
bytes in place of `→`/`≤` (cp1252-misdetection on write; signature: non-cp1252
chars become `?`, half-corrupted files carry mojibake bytes). There is no gate
that catches this class of corruption.

## 2. Strategy

Diagnose-first, per spec: **delete what tests dead or duplicated UI, rewrite
what tests real UI into auto-waiting, assertion-bearing tests** (pre-release:
no BC, no compat shims). Fixed sleeps are replaced by durable-outcome
assertions (`expect(...).toBeVisible()` / `toPass()` polling), duplicated
interaction sequences are extracted into a shared fixture helper, and vacuous
`if (count > 0)` / try-catch patterns are converted into honest assertions or
explicit `test.skip()` with a reason. Bundled quick-win: a deterministic
`npm run docs:encoding` gate (invalid-UTF-8 + mojibake-signature scan, never
flagging legitimate `?` prose) wired into the release gate.

Activation decisions (sign-off required):

- **D1 — `smoke-tests.spec.ts`: delete (recommended)** — its five tests are
  covered deterministically by `fast-core-tests.spec.ts` (app load, nav,
  console errors) plus the rewritten lean smoke spec; **vs** rewriting both
  smoke specs in parallel (duplicate coverage, no added signal).
- **D2 — `daily-tasks-flow.spec.ts`: delete (recommended)** — tests the
  archived DailyTaskList; the real daily surface is covered by
  `today-view.spec.ts`; **vs** rewriting it against TodayView (would duplicate
  `today-view.spec.ts`).
- **D3 — `fast-core-tests.spec.ts` de-vacuousing: in scope (recommended)** —
  only the two try/catch tests, same smoke-layer disease; **vs** strict
  P9-E2E-002b letter (no `waitForTimeout` there) and a separate backlog item.
- **D4 — `docs:encoding` wiring: blocking step in the release gate
  (recommended)** — cheap and deterministic, fits the existing
  frontend job; **vs** local-only manual gate (relies on discipline).

## 3. Backend / Frontend Changes

- **Backend** (`lib/`): none.
- **Frontend** (`src/`): none expected. If a rewritten assertion needs a
  stable hook that only exists as a CSS class (e.g. `.floor-content`), prefer
  the existing verified testids (`dashboard-view`, `room-card-*`,
  `task-manager-view`, `task-checklist`, `checklist-item-*`, `quick-stats`,
  `house-health`, `floor-{name}`, `expand-floor-*` — all verified live:
  `Dashboard.vue:7/48/55`, `RoomCard.vue:10`, `TaskManager.vue:7`,
  `TaskChecklist.vue:7/26`, `FloorList.vue:46`, `Settings.vue:98/111`).
  The dashboard floor-toggle button carries no testid — reuse its aria-label
  hook (`getByRole('button', { name: 'Expand floor' })`, `FloorList.vue:70`)
  plus the `.floor-section`/`.floor-content` classes, as the existing local
  helper already does. Only add a `data-testid` if a
  gap is proven during implementation (then update the Jest spec of that
  component if its DOM shape changes — not expected).
- **E2E fixtures**: new shared helper `tests/e2e/fixtures/dashboard-floors.ts`
  exporting `expandAllFloors(page)` (the 6× duplicated loop, auto-waiting)
  and `expandUntilRoomVisible(page, locator)` (moved from
  `room-management.spec.ts`, which keeps importing it).
- **Tooling**: `scripts/check-docs-encoding.js` (patterned on
  `check-test-noise.js` / `check-l10n-keys.js`), `package.json` script
  `docs:encoding`, one blocking step in
  `.forgejo/workflows/release-gate.yml` (frontend job). Scan scope: all
  git-tracked text files — invalid-UTF-8 detection everywhere plus the
  mojibake-signature scan; bare `?` prose is never flagged. Known tree state
  at drafting: exactly one live hit — `room-management.spec.ts:22/27/121`
  carries em-dash mojibake (repaired during steps 3/4b while the file is in
  scope anyway). Docs that cite the signatures (this plan, `testing.md`) use
  escape notation (`\u00E2\u20AC…` / byte hex), never literal mojibake bytes.

## 4. Tests

- **Unit (PHPUnit)**: unaffected; Docker unit suite must stay at its baseline
  (885, [24 pre-existing integration skips stay out of unit]).
- **Jest**: unaffected (no `src/` change expected); baseline 842 green.
- **E2E (Playwright, Docker, `--retries=0`)**:
  - Baseline re-run of the affected projects **before** edits
    (`chromium`, `chromium-serial-db`) — record green state.
  - After each spec migration: standalone run of that spec (3× for the two
    specs that had structural changes: `multi-user-interactions`,
    `room-management`).
  - Final: full blocking-gate shape (`chromium` + `chromium-serial-db`) plus
    one full-matrix run (all projects) `--retries=0`.
  - New assertions must verify durable outcomes: leaderboard entries ≥ 1
    (unconditional), checklist checkbox state after toggle, floor list order
    after reorder, modal visibility instead of sleep-then-hope.
- **Encoding gate**: `npm run docs:encoding` green on the tree after the
  steps 3/4b comment repair (the only known live hit today); self-test plants
  mojibake bytes and an invalid UTF-8 sequence into a temp file
  programmatically (Buffer writes, never copy-pasted literal bytes) → script
  must fail with file + byte offset.

## 5. File Manifest

```
deleted:  tests/e2e/smoke-tests.spec.ts                      (D1)
deleted:  tests/e2e/daily-tasks-flow.spec.ts                 (D2)
modified: tests/e2e/minimal-smoke-tests.spec.ts              (rewrite: lean auto-waiting smoke)
modified: tests/e2e/fast-core-tests.spec.ts                  (D3: de-vacuous 2 try/catch tests)
modified: tests/e2e/multi-user-interactions.spec.ts          (helper + auto-wait + de-conditionalize)
modified: tests/e2e/room-management.spec.ts                  (drop 3 sleeps; import shared helper; repair 3 mojibake comments)
modified: tests/e2e/room-detail.spec.ts                      (drop pad; honest skips)
modified: tests/e2e/task-checklist-and-features.spec.ts      (assert toggle outcome)
new:      tests/e2e/fixtures/dashboard-floors.ts             (expandAllFloors + expandUntilRoomVisible)
new:      scripts/check-docs-encoding.js
modified: package.json                                       ("docs:encoding" script)
modified: .forgejo/workflows/release-gate.yml                (D4: blocking encoding step)
modified: docs/standards/guidelines/testing.md               (E2E auto-waiting rule + encoding gate)
modified: docs/backlog/backlog.md                            (close P9-E2E-002b + TECH-DOCS-001)
modified: docs/backlog/archive/2026-08.md                    (completion records)
modified: docs/backlog/ACTIVE_TASKS.md
```

## 6. Implementation Order

1. **Baseline**: re-run affected Playwright projects (`chromium`,
   `chromium-serial-db`) `--retries=0`; record green state (stash-verify any
   failure as pre-existing before touching specs).
2. **D1/D2 deletions** (after sign-off): delete `smoke-tests.spec.ts` and
   `daily-tasks-flow.spec.ts`; grep-verify no other spec/fixture imports them
   and no unique selector coverage is lost (map each deleted test to its
   surviving equivalent in the plan record).
3. **Helper extraction**: create `fixtures/dashboard-floors.ts` with
   `expandAllFloors` / `expandUntilRoomVisible`; rewire `room-management.spec.ts`
   onto it (behavior-identical, auto-waiting).
4. **Migrations**, one spec per commit-sized step, standalone green after each:
   a. `multi-user-interactions.spec.ts` (helper, drop 6 sleeps, unconditional
      leaderboard assertion),
   b. `room-management.spec.ts` (3 sleeps → existing testid assertions),
   c. `room-detail.spec.ts` (drop pad; replace silent early-returns with
      `test.skip()` + reason — seed guarantees rooms, so skips should never fire),
   d. `task-checklist-and-features.spec.ts` (assert checkbox state change).
5. **Smoke layer** (D3): rewrite `minimal-smoke-tests.spec.ts` as the single
   lean smoke spec (load, shell visible, title, nav to tasks/settings, console
   error listener attached **before** `goto`); de-vacuous the two
   `fast-core-tests.spec.ts` try/catch tests.
6. **Encoding gate** (D4): `scripts/check-docs-encoding.js` + `docs:encoding`
   script + release-gate step + self-test (planted bytes via Buffer; signatures
   cited only as escapes/byte hex, never literal mojibake in docs); document
   the gate in `testing.md` alongside the zero-noise-gate section
   (`l10n:check-keys` lives in `i18n.md`).
7. **Full gate**: lint (incl. TS on `tests/e2e`), Jest, stylelint, webpack
   build, Docker PHPUnit unit + integration, full Playwright matrix
   `--retries=0`.
8. **Docs & backlog**: close P9-E2E-002b + TECH-DOCS-001 in `backlog.md` →
   `archive/2026-08.md`; correct the stale "~13 specs / ~27 calls" estimate in
   the archived record note; update `ACTIVE_TASKS.md`; testing.md carries the
   durable "no `waitForTimeout`, no vacuous conditionals" E2E convention.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Deleting smoke specs loses real coverage | Low | Coverage map (deleted test → surviving equivalent) recorded in the plan record; rewritten lean smoke spec keeps the load/title/console assertions deterministically |
| De-conditionalized assertions expose real product bugs (e.g. leaderboard-view fallback) | Medium | Diagnose-first: fix small bugs inline; anything larger gets a backlog item with the failing assertion pinned as a skipped-with-reason test — never re-add silent conditionals |
| Floor-expansion rewrite races the serial pool (concurrent reseed) | Medium | Specs keep their `chromium-serial-db` registration; auto-wait assertions + `toPass()` polling replace sleeps; 3× standalone runs required |
| `waitForLoadState('networkidle')` flakiness on slow CI | Low | Existing accepted idiom (config `navigationTimeout` covers it); not in scope to change |
| Encoding script false positives on legitimate prose (incl. signature citations in the gate's own docs) | Medium | Script flags only invalid UTF-8 byte sequences and known mojibake signatures (`\u00E2\u20AC…` lead-byte / `\u00C3…` umlaut class); never a bare `?`; gate docs cite signatures as escapes/byte hex only; self-tested both directions |
| Full-matrix runtime grows | Low | Blocking gate stays chromium-shaped (existing CI decision); full matrix is a one-off verification run, not a new gate |

## 8. Acceptance Criteria

- [x] `grep -r "waitForTimeout(" tests/e2e` returns **0 call sites** (docblock
      mentions in fixtures are acceptable).
- [x] No vacuous patterns (`if (count > 0)`, try/catch-swallowed assertions)
      remain in the swept specs; every test asserts a durable outcome or skips
      explicitly with a reason.
- [x] Dead specs deleted with a recorded coverage map; no spec references
      selectors that are absent from `src/`.
- [x] `expandAllFloors` / `expandUntilRoomVisible` exist once, shared.
- [x] `npm run docs:encoding` exists, is green on the tree (after the
      steps 3/4b mojibake repair), catches a planted mojibake file, and runs
      blocking in `release-gate.yml`.
- [x] Playwright full matrix green `--retries=0`; structural-change specs 3×
      standalone green.
- [x] Build gate green: `npm test` (Jest 842 baseline), `npm run lint` +
      `npm run stylelint` 0 errors, webpack build, Docker PHPUnit unit 885 /
      integration 200 [24 pre-existing skips].
- [x] Durable conventions integrated into
      `docs/standards/guidelines/testing.md` (auto-waiting rule, encoding
      gate); plan is not the only place they live.
- [x] `docs/backlog/backlog.md` + `ACTIVE_TASKS.md` updated; P9-E2E-002b and
      TECH-DOCS-001 moved to `archive/2026-08.md`.

## Review Outcome

**Standard-B review 2026-08-26: PASS.** Reviewed the full uncommitted
CS-PLAN-050 diff (7 swept specs, 2 deletions, shared fixture, encoding gate
script + CI wiring, docs/backlog updates) against the tree:

- All selector/testid claims verified live in `src/` (`settings-title` /
  `change-title-button` render unconditionally for members, `add-task-picker`,
  `leaderboard-view`/`-entry`, `nav-leaderboard`, checklist editor selectors,
  `.meta-item.assigned`, `Expand floor` aria-label hook); `cleanliness-value`
  carries 2 source sites (RoomCard + RoomDetail header stat).
- 0 `waitForTimeout(` call sites; vacuous patterns survive only as docblock
  history mentions. UTF-8 validator + mojibake signatures reviewed byte-level
  (overlong/surrogate/out-of-range rejection, git-style NUL binary heuristic,
  ENOENT skip for staged deletions, Buffer-planted self-test).
- 3 auto-fixes: (1) `room-detail.spec.ts` cleanliness test — the `.first()`
  strict-mode fix could read a dashboard room card if the detail view had not
  mounted; now asserts `room-detail-view` visible and scopes the locator into
  it, inaccurate "overview/stats card" comment corrected; (2)
  `docs/testing/release-test-plan.md` — 4 stale references to the deleted
  specs re-pointed per the coverage map (`smoke-tests` →
  `minimal-smoke-tests`/`fast-core-tests`, `daily-tasks-flow` → `today-view`,
  recurrence row downgraded to `partial` — the deleted spec never tested
  weekly/monthly recurrence); (3) `release-task-lifecycle.spec.ts` docblock
  recurrence pointer re-pointed to `today-view`.
- Noted (no action, KISS): the checklist toggle test still runs the
  `beforeEach` default-page navigation although it acts as `mom` via its own
  context — harmless runtime overhead.
- Manifest deviation, justified: `docs/engineering/testing.md` spec-coverage
  table updated too (it listed both deleted specs).
- E2E evidence per implementation record: structural specs 3× standalone
  green, full matrix twice with all swept specs green in every browser; the
  6 pre-existing failures in untouched specs are logged as **BUG-E2E-004**.
- Gate re-verified after the fixes: `npm run lint:fix` clean, `npm test`
  (Jest 842), `npm run docs:encoding` green on all tracked files +
  `--self-test` 4/4. No PHP touched → no Docker backend run.
