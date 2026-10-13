# Code Review: CS-PLAN-084 Family-Trial UX Baseline — Standard A

- **Review Date**: 2026-10-13
- **Reviewer**: Claude Code
- **Mode**: Standard A (plan review, no subagents)
- **Scope**: [CS-PLAN-084](../../plans/active/cs-plan-084-family-trial-ux-baseline.md) and its drafting package (backlog TECH-RUNTIME-002, UX-017 scope extension, FEAT-UX-033..036, TECH-TEST-005; `ACTIVE_TASKS.md`; plans README; CS-PLAN-080 RF-080-16 sequencing note)
- **Owning plan**: CS-PLAN-084 (`new/ → active/`)
- **Base Commit**: `ee73299` (branch `master`, index empty apart from the intent-to-add plan path)

## Executive Summary

**Outcome: PASS-WITH-FIXES.** The plan answers the operator's 2026-10-13
pre-family-trial request (AiO-current testing, readable colors, clickable
dashboard, paged task list, hold-to-confirm, feedback, lifecycle and mobile
test confidence) as seven bounded work packages without schema or API changes.
Seven plan defects were fixed in-round; three decisions were resolved in one
batched operator question. No open findings remain.

## Captured Fingerprint

- Plan file SHA256 prefix at capture: `d785318fa06ae765`.
- Unstaged tracked diff SHA256 prefix at capture: `e91f6de5c32a6253`
  (`ACTIVE_TASKS.md`, `backlog.md`, plans README, CS-PLAN-080).
- No untracked files; staged diff empty.

## Findings

| ID | Severity | Area | Issue | Status |
|----|----------|------|-------|--------|
| A-01 | minor | §1 / WP-A | Claimed the AiO `custom_apps` path was not a gate condition; `docker/test-entrypoint.sh:136-170` already enforces it (CS-PLAN-083) | fixed |
| A-02 | major | WP-F | "Make the streak milestone fire" contradicted `gamification-and-rewards.md` (7/30/100 milestones never implemented; recognition runs through medals/titles) | fixed → dead-branch removal |
| A-03 | minor | WP-E | A short tap on a hold button would silently do nothing for children | fixed → inline hold hint |
| A-04 | minor | WP-C | New Dashboard room-task read had no request-budget guard | fixed → budget assertion |
| A-05 | minor | WP-B | Nextcloud body theme attribute names assumed, not verified on NC34/NC35 | fixed → verification step |
| A-06 | minor | WP-A | Residual AiO differences (PHP-FPM, web server) unstated | fixed → explicit non-goal + `testing.md` |
| A-07 | nit | §4 | `l10n:check-keys` missing from the full gate list | fixed |
| A-08 | question | WP-E | Claim already has a confirmation dialog; hold would double-confirm | D-084-05 |
| A-09 | question | WP-D | "Completed" filter fix left undecided | D-084-07 |
| A-10 | question | WP-C | Child "More" navigation was an agent default | D-084-06 |

## Decisions (operator, 2026-10-13)

- D-084-05: 600 ms hold for Done and Quick clean; Claim keeps its dialog without hold (recommended).
- D-084-06: navigation unchanged for all roles (operator chose against the recommended child "More" group).
- D-084-07: Completed filter loads finished tasks on demand (recommended).
- Earlier drafting decisions D-084-01..04 (existing forwarding flows only, paged list, subtle always-on feedback, AiO-near row) were confirmed before the review.

## Gates

- `npm run backlog:check` — green (34 scoped files link-checked).
- `npm run docs:encoding` — green.
- `git diff --check` — clean.
- Runtime code unchanged; no Jest/backend/browser run required for this documentation-only package.

## Lifecycle

Plan moved `docs/plans/new/ → docs/plans/active/`; owning backlog items set to
`In Progress`; `ACTIVE_TASKS.md` and plans README updated. No deployment,
migration, retained-baseline adoption or production access.

---

# Code Review: CS-PLAN-084 Round 1 (WP-A + WP-G part 1) — Interim Standard B

- **Review Date**: 2026-10-13/14
- **Reviewer**: Claude Code
- **Mode**: Standard B (interim; plan stays `active/`)
- **Scope**: working tree at `305b62a` (index empty): AIO-current runtime rule and AIO component drift check, Redis AIO row (Dockerfile/entrypoint/inventory/stage runner + tests), successor-ownership integration tests, agreed-handover takeover fix (`CompletionService`), claim confirm without second dialog (TaskList/TaskManager/RoomDetail), deterministic `HouseUserMapper` map order, docs/rules/skills
- **Owning plan**: [CS-PLAN-084](../../plans/active/cs-plan-084-family-trial-ux-baseline.md)

## Captured Fingerprint

- Unstaged diff (26 tracked paths) SHA256 prefix `8ed59da905f118b7`; untracked `tests/integration/Service/SuccessorOwnershipTest.php` SHA256 prefix `084c7e10d4d4a7c5`.

## Findings

| ID | Severity | Area | Issue | Status |
|----|----------|------|-------|--------|
| B-01 | minor | `src/components/Tasks/TaskList.vue` confirmClaim | Unresolved actor identity made a confirmed claim a silent no-op | fixed (falls back to participation path; Jest regression) |
| RF-084-01 | major (pre-existing) | `lib/Db/RoomTask.php:156`, `src/utils/taskDisplay.js:35` | Midnight `next_due` makes a task overdue on its own due day (status, claim button, takeover recording) | routed to plan §9; decision D-084-08 (next round) |

## Verified Non-Findings

- Claim allocation cannot impersonate: it names only the actor; the server still enforces self-participation, visibility and the private-task 404.
- Entrypoint Redis inputs are validated before any mutation; provisioning tests isolate `PHP_INI_DIR` and clear an inherited `REDIS_HOST`.
- `aioComponents` and the stage Redis verification fail closed on unparsable sources, mutable images, foreign projects, unhealthy or foreign peers.

## Gates

Jest 119 / 1704; ESLint, Stylelint, `l10n:check-keys`, `composer cs:check`, docs encoding, backlog check, `agents:check`, `git diff --check` green; `test:runtime:check` live `current`. AIO row (NC 34.0.4, Redis 8.10.1, PostgreSQL 18.6): unit 1379, integration 607 (skips forbidden; first run's provider-order failure fixed in round). Canonical (rebuilt, `canonical-runtime-match`): unit 1379, integration 607. Browser (Chromium, canonical): claiming 9/9, completion/task-manager/room/family-core 33/33.

## Outcome

**PASS-WITH-FIXES (interim).** One finding fixed, one pre-existing plan-owned finding routed with an operator decision. Plan stays active.
