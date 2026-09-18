# Code Review: CS-PLAN-070 Standard A (Plan Activation)

**Review Date**: 2026-09-18
**Reviewer**: Main implementation agent (Kilo, skills: review / plan-management / git-workflow)
**Tier**: Standard A — plan review before `new/ → active/`
**Scope**: `docs/plans/new/cs-plan-070-family-rhythm-and-reward-clarification.md` (1243 lines, untracked)
**Base Commit**: `master` @ `1946166618d591eba54bd42d1d338030389e994e`

---

## Scope And Capture

- Branch `master`, HEAD `19461666`, index empty (no staged changes).
- Reviewed scope fingerprint: plan file SHA256
  `A84AC15790AA034E9099D124C38DDE1F15272FD6BAED349ADE640A41FC12D2B8`;
  unstaged-diff fingerprint (20 dirty files) SHA256 (UTF-8 of `git diff`)
  `352486442C8A4B3151DF85EF97D74A0BEB8214D4F18A400265527384FACB01CA`.
- Dirty-tree triage: the 17 modified tracked files exactly match the plan's
  section-5 documentation-round manifest; the three deleted
  `docs/plans/{new,active,onhold}/.gitkeep` are unrelated pre-existing changes
  the plan explicitly excludes — preserved unstaged.
- Owning plan: the target itself; `docs/plans/active/` was empty (no overlap).
  Destination `active/cs-plan-070-…md` did not previously exist.

## Review Verification

Standard A checklist (plan-only plus shared ChoreStrike criteria):

- Template compliance: header + sections 1-9 complete; stable per-package
  ownership (WP0-WP9) with individually validatable exit gates G0-G3.
- Inventory accuracy (all verified against the runtime tree): the six `M`
  migrations exist (`Version1000/1004Date20260228131728/1005/1029/1027/1025`);
  proposed `Version1030-1033` and ADR-013/014/015 reservations are free; all
  `M` services, background jobs, controllers/routes (`house#stats/activity/reset`,
  `room_task#overdue/fromTemplate/filteredDaily/complete`, `game#state`,
  `user#updateRole/titles`, `scheduling#preview/apply`, `minigame#*` incl. the
  boost/coins routes slated for removal), fixtures, referenced test files and
  gate scripts (`test:e2e:gate`, `l10n:check-keys`, `docs:encoding`,
  `backlog:check`, `build`) exist; `docker/compose.test-matrix.yml` correctly
  not present yet.
- Baseline code claims spot-checked correct: `RoomService.php:216-220`
  (delta-only manual adjust), `RoomService.php:193-197`
  (`deleteRoomTaskDependents` deletes completion evidence — RF-070-16 subject),
  `HouseService.php:172-185` (member removal deletes wallet-bearing row),
  `HouseUser.php:194-212` (available-day streak — RF-070-01 subject).
- Backlog ownership: CONCEPT-001 + CR-053-11, CR-SVC-63, CR-SVC-64,
  FEAT-UX-018/019/023/024/025/026 all open; section-6 ownership table maps
  every package without parallel-item creation.
- Rules compliance: fresh-install migration chain edits only (no backfills or
  shims — pre-release rule), thin controllers/services/mappers boundary kept,
  `@NoAdminRequired`-only surface with membership/visibility rechecks under
  lock, closed C4 actor/state matrix, integer minor-unit amounts with overflow
  rejection, `t()` + `l10n/` sync in shared-file rules, at-most-once dispatch
  without payload logging.
- Test-plan adequacy: T0-T12 concrete file assignments (67 C1-C5 scenario rows),
  barrier-based race tests, retirement-with-replacement rule,
  `required-scenarios.json` pinning, two-clean-pass browser gate, DB matrix,
  zero-skip policy.

## Findings And Resolutions

- New findings from this review: **none** (0 block / 0 major / 0 minor / 0 nit).
- Open gates found documented in the plan itself (not new findings):
  Q-070-01/02 activation decisions — resolved in this review (see Decisions).
- Reclassification: RF-070-16/17 `block` → `major` after their contracts were
  selected; only implementation and T0/T4/T6 verification remain.
- Review-owned link repairs after the folder move: `ACTIVE_TASKS.md` (three
  `plans/new/` references) and `backlog.md:68` retargeted to `plans/active/`.

## Decisions

1. Q-070-01 — deletion contract for task/room/floor with valid history
   - Retain minimum history (tombstone) (Recommended): selected.
   - Block deletion when history exists: rejected.
   - Leave undecided: rejected.
   - **Answer: Retain minimum history (tombstone).**
2. Q-070-02 — true Nextcloud account deletion / privacy erasure policy
   - Erase/anonymize on deletion (Recommended): selected.
   - Keep policy undecided: rejected.
   - **Answer: Erase/anonymize on account deletion.**
3. Activation commit scope (uncommitted plan-owned documentation round present)
   - Full plan-owned package (Recommended): selected — plan (moved), 17
     section-5 manifest files, tracking updates and this record; the three
     unrelated `.gitkeep` deletions stay unstaged.
   - Minimal staging: rejected. No automatic commit: rejected.
   - **Answer: Full plan-owned package, `.gitkeep` deletions excluded.**

## Tests and Validation

- `npm run backlog:check` → pass (after the move-related link fixes and this record).
- `npm run docs:encoding` → pass (719 tracked files).
- `git diff --check` → pass (whitespace clean).
- Runtime suites (Jest / PHPUnit / E2E) not required: documentation-only round,
  runtime paths and index unchanged.

## Documentation and Backlog Changes

- Plan integrated and moved `new/ → active/`: header status/depends-on,
  §2 strategy note, technical coverage table, §3.5 reset paragraph, §5 WP0 and
  schema allocations, §6 WP0/G0, §7 risk rows, §8 acceptance criterion,
  §9 activation-decision table resolved, RF-070-16/17 updated, Standard A
  Review Outcome appended.
- `docs/backlog/backlog.md`: CONCEPT-001 status `In Progress`, activation
  narrative, checklist item checked; CR-053-11 plan link retargeted.
- `docs/plans/README.md`: current active plan set to CS-PLAN-070.
- `docs/backlog/ACTIVE_TASKS.md`: activation one-liner added; stale links fixed.
- This review record created.

## Outcome

- **`PASS-WITH-FIXES`** — no findings remain; decision integration and link
  fixes applied; documentation gates green.
- Plan transition: `new/ → active/` performed; CONCEPT-001 `In Progress`.
- Scoped commit: one conventional commit containing the full plan-owned
  documentation package, the moved plan, tracking updates and this record;
  unrelated pre-existing `.gitkeep` deletions excluded (unstaged).
- Next step: WP0 (write/identity foundation incl. tombstone deletion and
  account-erasure plumbing), owned by the main implementation agent.

---

**Review Completed**: 2026-09-18
**Next Review**: final Standard B or Full review after WP0-WP9 (G3)
