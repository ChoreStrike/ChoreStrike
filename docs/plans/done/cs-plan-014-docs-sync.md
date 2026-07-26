# CS-PLAN-014: Documentation-vs-Implementation Sync (P9-DOC-002)

> **Status:** Done (2026-07-26) — all 4 doc phases shipped and reviewed; plan moved `active/ → done/`.
> **Effort:** S–M
> **Priority:** P2
> **Depends on:** none (CS-PLAN-010 already rewrote the core doc bodies; this closes the residual drift)
> **Closes:** `docs/backlog/phase-9-code-quality.md` → **P9-DOC-002**

## 1. Problem Summary

`P9-DOC-002` asked to sync architecture/testing docs with the live app. **CS-PLAN-010 already did the bulk of it** (directory tree, DB schema, `src/services/api.js`, command-derived counts, medal reality, E2E table). A pre-plan audit (2026-07-25) found the remaining drift is now **concentrated in `development.md`** (which CS-PLAN-010 did not touch) plus a few stale leftovers in `architecture.md` / `testing.md`.

The worst offenders actively mislead a reader:

- **`development.md` references `.github/workflows/`** (`lint.yml`, `test.yml`, `build.yml`) — **no `.github/workflows/` exists.** (`.github/` itself exists and holds only Forgejo-compatible issue/PR templates — the `architecture.md` tree entry for it is correct and must stay.) Real CI is `.forgejo/workflows/release-gate.yml` (+ `test.yml`). A reader assumes GitHub Actions.
- **`development.md` says unit tests live in `tests/Unit/`** (capital U) — the real path is `tests/unit/`.
- **`development.md`'s code example uses a fictional `PointsCalculator` class** that does not exist in `lib/`; the real equivalent is `GameLogicService::calculateCoins()`. (`docs/concepts/design.md` also sketches a `PointsCalculator.php`, but that is an MVP-era concept doc — explicitly out of scope here.)
- **`development.md` describes a GitHub-style `develop`-branch PR flow** that does not match the Forgejo setup (default branch is `master`), and its "Getting Help" section says "Open an issue on GitHub" although the repo is Codeberg-hosted.
- **`development.md` points AI agents to `../AGENTS.md`** (line 5) — `docs/AGENTS.md` does not exist; the correct relative path is `../../AGENTS.md`.
- **`architecture.md` labels E2E tests as "Future"** despite 31 live spec files, lists only `img/app.svg` (missing the tracked `img/manifest.webmanifest`), and documents store members that do not exist (`useHouseStore.health` state / `updateHealth()` action, `useRoomsStore.overdueRooms()` getter — the real members are the `houseHealth` getter + `calculateHouseHealth()` action in `src/store/house.js`).
- **`testing.md` lists `multi-user-task-claiming.spec.ts`** (archived/removed) inside a shared E2E table row with the two live specs `overdue-task-claiming.spec.ts` and `multi-user-interactions.spec.ts`.

**Out of scope (already correct):** `src/services/api.js` docs, frontend directory tree, DB schema, API response fields, contract-test section, `.forgejo/workflows/release-gate.yml` documentation. This plan must **not** redo them.

## 2. Strategy

Doc-and-prose-only changes, executed as small independently-reviewable phases per doc file. No `lib/`/`src/`/migration/runtime change. Each phase is verified by a relative-link-integrity grep + a spot-read of the cited source. The acceptance bar is "every concrete claim in these four docs matches the live repo."

## 3. Changes

### `docs/engineering/development.md` (HIGH priority — most drift)
- Replace the `.github/workflows/` CI section with the real `.forgejo/workflows/release-gate.yml` (blocking gate: ESLint + Jest + build + `composer validate` + PHPUnit unit/integration via Docker; Stylelint advisory). Mirror the wording already in `testing.md` lines 180–199 to avoid divergence.
- Fix `tests/Unit/` → `tests/unit/` (every occurrence).
- Replace the fictional `PointsCalculator` example with the real `GameLogicService::calculateCoins()` shape (energy × base rate × tier × personal × early × combo), or drop the fictional example in favor of a pointer to `lib/Service/GameLogicService.php`.
- Align the PR/branch workflow with the Forgejo reality: default branch `master`, conventional-commit messages, no `develop` branch assumed. Cross-reference `docs/standards/ai/skills/git-workflow` + AGENTS.md instead of restating.
- Replace legacy `docker-compose` CLI invocations with `docker compose`.
- Fix the broken agent pointer `../AGENTS.md` → `../../AGENTS.md` (line 5).
- "Getting Help": replace "Open an issue on GitHub" with the Codeberg issue tracker (the clone URL already points to Codeberg).
- **PHP version (decision from plan review 2026-07-26):** `appinfo/info.xml` declares `<php min-version="8.0">` and `composer.json` requires `^8.0` — only `AGENTS.md` claims 8.1+. Keep `PHP 8.0+` in the engineering docs (manifest/composer are the install-time source of truth) and log a backlog item to decide a real 8.1 minimum bump (info.xml + composer.json + AGENTS.md together). Do **not** write 8.1+ here until the manifest agrees.
- Deferred (logged as backlog, not fixed here): remaining example-code drift in `development.md` — the fictional `TaskService::calculateNextDue()` PHPDoc example and the raw-`axios` endpoint example (contradicts the canonical shared `api.js` client enforced by the contract tests).

### `docs/engineering/architecture.md` (MEDIUM)
- **Keep** the `.github/` tree entry — it is accurate (`ISSUE_TEMPLATE/` + `pull_request_template.md` are tracked). Only `development.md`'s `.github/workflows/` claim is wrong.
- Change `### E2E Tests (Future)` → present-tense; state E2E is live (Playwright, 3 browser projects) and cross-link `testing.md` for counts (no hard number to avoid re-drift).
- Note `img/manifest.webmanifest` is part of `img/` (PWA bootstrap, CS-PLAN-002 Phase 5).
- Minor store drift: `useHouseStore` has no `health` state / `updateHealth()` action — describe the real `houseHealth` getter and `calculateHouseHealth()` action (`src/store/house.js`); drop the non-existent `overdueRooms` getter from `useRoomsStore`.
- PHP version: keep `PHP 8.0+` per the decision above (matches info.xml/composer.json).

### `docs/engineering/testing.md` (MEDIUM)
- E2E table (line ~310): remove only the archived `multi-user-task-claiming.spec.ts` name from the shared row; keep the row for the live `overdue-task-claiming.spec.ts` + `multi-user-interactions.spec.ts` and adjust the row description.
- Spec counts (lines ~375/~388) are already command-derived — only drop the stale parenthetical example counts ("32 specs", "42 specs at the time of writing"); do not introduce a new counting command.
- Convert remaining legacy `docker-compose` CLI invocations (e.g. line ~85) to `docker compose`, but **keep** the `docker-compose-plugin` apt package name (line ~126) and the explicit "legacy docker-compose" fallback note (lines ~24–25) unless decided otherwise.

### `docs/api/README.md` (LOW / verify)
- Audit confirmed endpoint paths + camelCase response fields are correct. Only optional touch: annotate `assigned_to_user_id` as legacy-retained-but-unused in the room-task payload (matches `architecture.md`). Skip if it would create churn.

### Backlog
- Mark `phase-9-code-quality.md` **P9-DOC-002 → Done (CS-PLAN-014)**.
- Add backlog item: decide PHP minimum-version alignment (`info.xml`/`composer.json` 8.0 vs `AGENTS.md` 8.1+) — Source: Review of CS-PLAN-014.
- Add backlog item: `development.md` example-code audit (fictional `TaskService::calculateNextDue`, raw-axios endpoint example) — Source: Review of CS-PLAN-014.

## 4. Tests

Docs-only plan — no unit/integration/E2E tests. Verification:
- Relative-link-integrity grep across the 4 touched docs (0 broken links, mirroring CS-PLAN-009/010 method).
- Spot-read every cited source path to confirm the claim now matches (e.g. `ls tests/unit`, `glob lib/Migration/Version10*`, read `GameLogicService::calculateCoins`).
- `npm run lint` unaffected (no `src/` change); `php -l` unaffected.

## 5. File Manifest

```
modified: docs/engineering/development.md
modified: docs/engineering/architecture.md
modified: docs/engineering/testing.md
modified: docs/api/README.md            (optional, low)
modified: docs/backlog/phase-9-code-quality.md   (P9-DOC-002 -> Done)
new:      docs/plans/active/cs-plan-014-docs-sync.md  (manual new/ -> active/ move)
```

## 6. Implementation Order

1. **Phase 1 — `development.md` (HIGH):** `.github/workflows` → `.forgejo`, `tests/Unit` → `tests/unit`, replace fictional `PointsCalculator`, fix PR/branch flow + "GitHub" help link, fix `../AGENTS.md` link, `docker compose`, keep PHP 8.0+ (per decision).
2. **Phase 2 — `architecture.md` (MEDIUM):** keep `.github/` entry, fix "E2E Future", manifest note, store drift, keep PHP 8.0+.
3. **Phase 3 — `testing.md` (MEDIUM):** remove dead spec row, command-derived counts, `docker compose`.
4. **Phase 4 — `api/README.md` (optional LOW):** legacy-field annotation.
5. **Phase 5 — Backlog + verify:** mark P9-DOC-002 Done; link-integrity grep + source spot-checks.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Introduce a new broken cross-link while editing | Medium | Run the full-tree relative-link scan (same as CS-PLAN-009 pass: 0 broken links) after every phase. |
| Re-stating a count that drifts again | Medium | Use command-derived phrasing (`--list \| wc -l`), never hardcode counts. |
| Over-editing into churn | Low | Minimal-diff per file; touch only the cited drift lines, no reflow/formatting passes. |
| `api/README.md` optional touch creates scope creep | Low | Keep it to a one-line legacy annotation or skip entirely. |
| PHP 8.0 vs 8.1 source-of-truth conflict (info.xml/composer.json vs AGENTS.md) | Medium | Docs follow the install-time manifest (8.0+); the alignment decision is a separate backlog item, not part of this plan. |

## 8. Acceptance Criteria

- [ ] `development.md` no longer references `.github/workflows/`, `tests/Unit`, `PointsCalculator`, `develop`-branch PR flow, "Open an issue on GitHub", or the legacy `docker-compose` CLI; `../AGENTS.md` link fixed; PHP version matches the manifest decision (8.0+ unless the backlog bump lands first).
- [ ] `architecture.md` **retains** its correct `.github/` entry, E2E is present-tense (no "Future"), `img/manifest.webmanifest` noted, store drift corrected (`houseHealth` getter / `calculateHouseHealth()`, no `overdueRooms`), PHP 8.0+.
- [ ] `testing.md` has no `multi-user-task-claiming.spec.ts` name (shared row kept for the two live specs), no stale parenthetical spec counts, legacy CLI converted (apt package name + legacy fallback note preserved).
- [ ] Every concrete claim **in the sections touched by this plan** matches the live repo (verified by source spot-read); the deferred `development.md` example drift has backlog items.
- [ ] Relative-link-integrity grep = 0 broken links.
- [ ] `phase-9-code-quality.md` P9-DOC-002 marked Done.
- [ ] Build gate unaffected (no `lib/`/`src/` change) — `npm run lint` still 0 errors.
- [ ] `docs/backlog/ACTIVE_TASKS.md` updated.

## Review Outcome

**2026-07-26 — Plan review (Standard Type A), pre-activation.** All factual claims
verified against the live repo. Corrections applied directly to this plan:

- **Block fixed:** the plan instructed removing the `.github/` tree entry from
  `architecture.md` — but `.github/` exists (tracked `ISSUE_TEMPLATE/` +
  `pull_request_template.md`) and the entry is accurate. Only
  `development.md`'s `.github/workflows/` claim is drift. Entry now kept.
- **Major fixed:** the `PHP 8.0+ → 8.1+` premise was wrong — `info.xml`
  (`min-version="8.0"`) and `composer.json` (`^8.0`) declare 8.0; only
  `AGENTS.md` says 8.1+. Docs now stay at 8.0+ (manifest is source of truth);
  alignment is a new backlog item.
- **Major fixed:** acceptance criterion "every concrete claim matches" was
  broader than the listed changes (development.md also has a fictional
  `TaskService::calculateNextDue` example and a raw-axios example contradicting
  the canonical `api.js` client). Criterion narrowed to touched sections;
  remainder deferred to a backlog item.
- **Minor fixed:** `testing.md` — the archived spec shares its E2E table row
  with two live specs (edit the row, don't delete it); spec counts are already
  command-derived (only drop parentheticals); blanket `docker-compose` replace
  would break the `docker-compose-plugin` apt package name.
- **Added to scope:** `development.md` broken `../AGENTS.md` link (line 5) and
  "Open an issue on GitHub" (repo is Codeberg-hosted).
- **Verified correct as claimed:** `tests/unit/` case, `PointsCalculator`
  fictional in `lib/`, `GameLogicService::calculateCoins()` real, `master`
  default branch, 31 E2E spec files, `multi-user-task-claiming.spec.ts`
  archived, `img/manifest.webmanifest` tracked, store drift real,
  `testing.md` lines 180–199 CI wording exists for mirroring.

**Verdict:** approved for `new/ → active/` after these corrections (applied
above). No blockers remain.

## Review Outcome (post-implementation, Standard Type B, 2026-07-26)

All four doc phases shipped and verified (`/review uncommitted`, docs-only,
no subagents). Link-integrity grep = 0 broken links across the 4 touched docs;
every factual claim spot-read against source (`.forgejo` CI files, `tests/unit/`
casing, `GameLogicService::calculateCoins()` signature + formula, `master`
default branch, `info.xml`/`composer.json` PHP 8.0, `houseHealth`/`calculateHouseHealth()`,
no `overdueRooms`, 31 live E2E specs, archived spec not tracked).

**1 WARNING found and fixed:** the initial `api/README.md` note labelled
`assigned_to_user_id` "legacy-retained-but-unused / always `null`". Source trace
proved this false — the field is still set (`RoomTaskController` POST body +
`RoomTaskService::assignToUser()` → `RoomTaskMapper`), returned
(`TaskController`, `RoomTask::jsonSerialize()`), and merged into the
multi-assignee set (`TaskAssigneeMapper`). Note reworded to describe it
accurately as the legacy single-assignee field retained alongside the
multi-assignee table; the same correction propagated to the `phase-9` Completed
bullet and `ACTIVE_TASKS.md`. (This mirrors the pre-activation review's PHP-8.0
pattern: a plan premise that did not survive source verification.)

**Deferred (unchanged, logged):** P9-DOC-005 (development.md example-code
audit) and P9-CONFIG-001 (PHP minimum-version alignment). Build gate
unaffected — `npm run lint` 0 errors (no `lib/`/`src/` change). Not committed
— awaiting approval.
