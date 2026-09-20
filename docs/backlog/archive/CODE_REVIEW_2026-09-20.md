# Code Review: CS-PLAN-072 WP1 — Standard A (Plan Activation, Occurrence Timeline)

**Review Date**: 2026-09-20
**Reviewer**: Main agent (Kilo, skills: review / plan-management / git-workflow)
**Tier**: Standard A (plan) — single plan file in `docs/plans/new/`, destination-free
**Scope**: `docs/plans/new/cs-plan-072-wp1-occurrence-timeline.md` (untracked draft), its owning parent CS-PLAN-070 (active) and the WP0 dependency CS-PLAN-071 (done)
**Base Commit**: `master` @ `9239d68d0068686a247621f9db628ba2d9c7c3ed`

---

## Scope And Capture

- Branch `master`, HEAD `9239d68`, index empty (no staged changes) at capture.
- Pre-review unstaged-diff fingerprint (SHA-1 `git hash-object` over `git diff`):
  `06e222da8f03853420b469487a33ee8f138007c1`. The unstaged set was the
  plan-072 drafting round (`docs/README.md`, `docs/backlog/ACTIVE_TASKS.md`,
  `docs/backlog/archive/2026-09.md`, `docs/backlog/backlog.md`,
  `docs/plans/README.md`, parent plan WP1 row/status) plus the known
  pre-existing `.gitkeep` deletions, which remain excluded from the commit.
- Sorted untracked paths with hashes at capture:

```text
00e5b96a8318f95aa9ec56073ee8654175e0e94d  docs/plans/new/cs-plan-072-wp1-occurrence-timeline.md
```

  (SHA256 `59434F394B44508DAB944E86F0BC078E6F384AD0584C800CB23A17DEB271A47A`.)

## Owning Plan

- Standard A target resolution: exactly one regular, non-symlink `*.md` in
  `docs/plans/new/` (`cs-plan-072-wp1-occurrence-timeline.md`); destination
  `docs/plans/active/cs-plan-072-wp1-occurrence-timeline.md` did not exist.
- Owning parent: CS-PLAN-070 (active). No overlap conflict: the established
  parent/child sub-plan pattern (as with CS-PLAN-071/WP0); `onhold/` empty.

## Verification (all checks passed, zero findings)

- §1 writer/read surfaces confirmed at runtime: `RoomTask::markCompleted()`
  (`lib/Db/RoomTask.php:240`), `calculateNextStaticDue()` (`:338`),
  `projectOccurrences()` (`:605`), `TaskCreationService::create()` (`:122`) /
  `createFromTemplate()` (`:176`), `HouseSetupService::applySetup()` (`:109`),
  `TaskService::updateTask()` (`:164`), `HouseService::updateHouse()` (`:315`),
  `RoomTaskService::setAssignees()` (`:302`) / `updateDueDate()` (`:368`) /
  `bulkAssignTasks()` (`:529`) / `getRangeTasks()` (`:634`),
  `SchedulePlanService::apply()` (`:125`), `CompletionService::complete()`
  (`:132`); `RoomTaskReadService`, `ChecklistService`,
  `CompletionEffectService`, `CompletionPostCommitService`, `CommandService`,
  `AccountErasureService`, `TestDataService`, `TaskAccessPolicy`,
  `CalendarService`, `lib/Db/TransactionExecutor` present.
- Runtime behavior claims confirmed: per-user day-duplicate guard
  (`lib/Service/CompletionService.php:185-194`), command replay returns the
  stored result without invoking the callback
  (`lib/Service/CommandService.php:122-144` — validating the plan's
  authorization-on-replay requirement), `MAX_PROJECTION_OCCURRENCES = 1000`
  (`lib/Db/RoomTask.php:52`), checklist progress keyed
  `(room_task_id, checklist_item_id)` (Version1005), daily reads ordered by
  `next_due` (`RoomTaskReadService`).
- Migration chain ends at `Version1030Date20260918000000`; `Version1031` free
  and the plan's filename identical to the parent's frozen manifest entry.
  `Version1005`/`Version1029` exist as claimed.
- ADR-009/ADR-011/ADR-012 present; ADR-013 free as reserved by the parent.
- Concept anchor `#approved-timeline-contract-c1-2026-09-17` resolves
  (`docs/concepts/task-model-and-sync.md:126`).
- All §4 `M` test files and the entire §5 modified-file manifest exist
  (backend entities/mappers/controllers, `TriggerJobs`, `info.xml`,
  `routes.php`, stores/views/components, integration fixtures, E2E
  fixtures/scenarios/gate/config, l10n artifacts, engineering/API/testing docs).
- Parent contracts present: §3.1-3.5, `schedule_revs`/`occurrences`/
  `occ_members`/`agreements`/`pauses` tables, seven-day horizon, "a timeline
  job, not a GET", T1 with "same target replay" and its file mapping (child
  test table is a superset), §3.4 `commandId` on the complete route, and
  open RF-070-02/04/08/16/17 matching the child's declared coverage.
- WP0 dependency CS-PLAN-071 delivered in `done/`; the child's WP1 command
  adoption correctly advances WP0's provisional D6 schedule per parent T1.

## Decisions (batched structured questions, answered by the user)

| ID | Question | Answer |
|---|---|---|
| Q-072-01 | WP1/WP2 agreement-writer boundary: Option A (minimal common agreement writer + `agreements` persistence + necessary C2 deadline/authorization/capacity checks into WP1) vs Option B (single WP1+WP2 integration boundary) | **Option A** |
| Estimate | Revised WP1 effort to freeze in both plans | **7-10 days** (WP2 reduced to 5-7) |

§10's four technical allocations (completion transport/command adoption in
WP1 for T1; natural-key job materialization without a fabricated actor;
minimal frontend caller migration before WP6; restore losing
recurrence/checklist snapshot authority before WP3's monetary cutover) were
ratified as parent-derived — no new product consent claimed, consistent with
the CS-PLAN-071 D1-D11 precedent.

## Outcome

**PASS** — no findings. All verification tracks green; no safe fixes were
required (edits were decision application and activation metadata only).

- Plan updated: header status/effort, §2 WP2 boundary note, §3.1 `agreements`
  migration scope, §3.4 writer cutover, §5 frozen manifest note, §6 step 1,
  §8 first acceptance criterion checked, §9 findings state, §10 resolution,
  `## Review Outcome` appended.
- Transition: `docs/plans/new/ → docs/plans/active/` (2026-09-20).
- Parent CS-PLAN-070: header status, WP1 row (link/scope/estimate) and WP2 row
  (scope/dependency/estimate) updated.
- Tracking: `docs/plans/README.md`, `docs/README.md`, CONCEPT-001 narrative in
  `docs/backlog/backlog.md`, `docs/backlog/ACTIVE_TASKS.md` (In Progress +
  one-line completion entry, draft link repaired) — links retargeted for the
  folder move. The plan's previously uncommitted drafting round is landed with
  this commit per the CS-PLAN-070 activation precedent; unrelated pre-existing
  `.gitkeep` deletions remain unstaged.

## Gates

`npm run backlog:check` · `npm run docs:encoding` · `git diff --check` — all
green post-edit (documentation-only review; no PHP/JS/E2E scope changed).
