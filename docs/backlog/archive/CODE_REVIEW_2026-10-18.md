# Code Review Record — 2026-10-18

## Final Standard B — CS-PLAN-070 parent closure and consolidation into CS-PLAN-080

**Outcome:** `PASS-WITH-FIXES` · **Transition:** CS-PLAN-070 `active/ → done/`; CS-PLAN-080 stays `active/`

### Scope

| Item | Value |
|---|---|
| Request | User, 2026-10-18: check whether CS-PLAN-070 and CS-PLAN-080 can be closed, otherwise consolidate them into one plan |
| Owning plan | `docs/plans/done/cs-plan-070-family-rhythm-and-reward-clarification.md` (was `active/`); consolidation target `docs/plans/active/cs-plan-080-wp9-cross-db-and-family-evidence.md` |
| Reviewed | the parent's whole record (manifest, §5 removal list, acceptance criteria, §9 findings, WP9 evidence reconciliation) against the tree, plus the durable docs it names |
| Base | `master` `55532c7`; separate worktree on `docs/consolidate-wp9-plans`, clean at capture (empty index, no unstaged or untracked paths) |
| Preserved unrelated changes | the main checkout on `feat/arcade-minigames` (CS-PLAN-086 WP-F in progress in another session) — untouched |
| Tier | Standard B (documentation-only closure; no runtime, schema, l10n or test change) |

### Closure Assessment

Neither plan could close as it stood. Both waited on the same human gates:
the operator's real-device checklist (release-test-plan §14.1, TECH-TEST-005),
HUMAN-01 adult/child comprehension, HUMAN-02 14 days of voluntary use, and the
final review. The release test plan records every scenario as `Not performed`,
and the user confirmed that no unrecorded results exist. The parent's technical
scope (WP0-WP8 via CS-PLAN-071..079, WP9 technical gates) was complete apart
from the pending whole-item verdicts.

### Verification

- All 31 tests cited by the parent's WP9 evidence reconciliation exist under
  their named classes.
- The parent's §5 removal list is absent from the tree: freeze/restock services
  and jobs, at-risk and due reminders, BoostShop/2048/Memory, TitlePicker, the
  boost-shop browser spec.
- No combo, multiplier or Coin-minting path is reachable in `lib/` (the one dead
  combo read is RF-080-17). The dashboard tagline is calm, and `HouseService` delegates the
  weekly goal to `WeeklyGoalService`.
- ADR-004 and ADR-011 mark their superseded rules; ADR-012 carries the WP1/WP2
  amendments; ADR-013..017 and `calm-core-ux.md` hold the delivered contracts.

### Findings

| ID | Sev | Area | Issue | Status |
|----|-----|------|-------|--------|
| — | minor | `docs/concepts/{energy-and-balancing,gamification-and-rewards,task-model-and-sync,ui-use-cases,product-vision}.md` | Status labels and lines still said "Not Yet Implemented", "target only" or "remain open" for behavior WP1-WP8 delivered (e.g. per-assignee capacity, unenforced overrides, no catalog API, snapshot undo) | Fixed — corrected to the delivering packages and ADRs; approved rules unchanged |
| — | minor | ADR-009, ADR-011, four concept docs | Seven links still pointed to `plans/new/cs-plan-070-…` | Fixed — all 30 links to the parent now point to `done/` |
| — | nit | `docs/README.md` | The CS-PLAN-080 line claimed "no baseline adoption", although `0.8.0-alpha.1` was adopted on 2026-10-17 | Fixed |
| — | nit | `archive/2026-09.md`, CS-PLAN-071/074 | Three older broken links (archive-relative paths, review-record anchor slugs) | Fixed |
| RF-080-17 | minor | `lib/Db/CompletedTaskMapper.php` `findRecentByUserId` | The former combo-window read has no production caller since the WP3 combo retirement; only its unit pin calls it | User-rescoped to CS-PLAN-080 §9 (next code round, Docker PHPUnit unit gate) |

### Decisions (batched, 2026-10-18)

1. Consolidate into CS-PLAN-080: the parent closes after this review, and its G3
   family evidence and the CONCEPT-001 closure move there unchanged. Alternatives
   not chosen: keep the parent as the consolidated plan; close both by moving the
   human evidence to the backlog.
2. Work in a separate worktree, make one scoped commit and merge it into `master`
   locally (no push).
3. No real-device, comprehension or observation results exist yet.
4. The dead combo-window read becomes RF-080-17 in CS-PLAN-080. Alternatives not
   chosen: remove it now (shared test stack in use by another session); backlog
   item.

### Gates

`npm run docs:encoding`, `npm run backlog:check`, `git diff --check`, a link and anchor check of every changed Markdown file (one false positive: a quoted dead link inside a code span in `CODE_REVIEW_2026-09-16.md`), `npm test -- --runInBand` (130 suites / 1885 tests) and `npm run lint` are green. No PHP, JS, l10n or test file changed, so the Docker backend suites, build and browser gates were not rerun; their last full run is the `0.8.0-alpha.1` candidate qualification.

### Transition and Tracking

- CS-PLAN-070 → `done/`, with RF-070-01..17 verdicts and the acceptance criteria
  updated (family evidence user-rescoped to CS-PLAN-080).
- CS-PLAN-080: consolidation note, ownership of G3 and CONCEPT-001, RF-080-17,
  acceptance criteria and implementation order wording.
- CONCEPT-001 stays In Progress, now owned by CS-PLAN-080. `ACTIVE_TASKS.md`,
  `archive/2026-10.md`, `docs/plans/README.md` and `docs/README.md` updated.
