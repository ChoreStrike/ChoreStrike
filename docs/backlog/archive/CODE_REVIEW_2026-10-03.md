# Plan Activation: CS-PLAN-080 WP9 Cross-Database Verification and Family Evidence — Standard A

**Review Date**: 2026-10-03
**Reviewer**: Kilo (main agent, no subagents — Standard A tier)
**Mode**: Standard A (plan review before `new/` → `active/`)
**Scope**: `docs/plans/new/cs-plan-080-wp9-cross-db-and-family-evidence.md` only (documentation round; runtime tree untouched)
**Base Commit**: `821195d853c43a2fc32e22b4bf9ea4f0fe2f0f7b` (`master`; index empty at capture — staged-diff sha1 `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391`; unstaged-diff sha1 `fc62c603a31d55e4de8b006ff9c0a43012219852` covering the draft-support tracking package plus the unrelated pre-existing `.gitkeep` deletions, preserved unstaged; untracked plan SHA256 `10C41B27785AB2EEFA5A6E3B8E2751B6259F804B8855BEAC2B2B3E45F61EE9F1`)

---

## Executive Summary

### Outcome
- PASS

### What Was Reviewed
- The complete WP9 child plan: problem summary with verified drafting baseline, strategy and scope/non-goals, isolated database matrix, portable integration/concurrency proofs, fixture and browser-gate reconciliation, family evidence and durable knowledge, required coverage matrix (DB-01..05, UI-01..04, HUMAN-01/02), runtime gate procedure, human protocol/evaluation, file manifest, implementation order, risks, acceptance criteria.
- Eligibility: sole regular non-symlink `*.md` in `docs/plans/new/` (`LinkType` empty — regular file); destination `active/` did not exist; no ambiguity.

### What Was Fixed Immediately
- Nothing — zero findings.

### Main Risks Remaining
1. None blocking. Implementation-phase risks (shared-environment reset, non-MySQL skips masquerading as portability, SQLite toy-lock tests, stale candidates, family-evidence privacy, silent parent closure, inferred RF-070 completion, obsolete-build observation) are named in plan §7 with mitigations.

---

## Verification Performed

- **Baseline citations exact against the runtime:** `docker/compose.test.yml` uses MariaDB 10.6 with fixed `container_name`s and host port 8080; `docker/test-entrypoint.sh` uses `mysqladmin`/`mysql` readiness probes and hardcodes `maintenance:install --database='mysql'` (a compose DB substitution alone cannot produce a valid PostgreSQL or SQLite installation); `docker/Dockerfile.test` installs `pdo_mysql` and `pdo_pgsql` but not `pdo_sqlite` (the plan correctly requires verifying actual SQLite driver availability); `docker/compose.test-matrix.yml` does not exist; `HouseMutationConcurrencyTest.php:448-497` holds only an isolated toy-schema SQLite write-acquisition proof (not a full migration/service proof); `RewardConcurrencyTest.php:30-34` calls `markTestSkipped` on non-MySQL providers; `scripts/run-e2e-gate.js` runs exactly `chromium`, `chromium-serial-db` and `chromium-scheduling-owner` with `--workers=1 --retries=0 --forbid-only` and rejects missing scenarios, skips, failures, flaky and interrupted results; `docs/concepts/ui-use-cases.md` `### Family Acceptance Protocol` (anchor `#family-acceptance-protocol` matches the plan link) contains the C5j rules; parent CS-PLAN-070 RF-070-01..17 include open and partially delivered items.
- **Manifest:** every `N` path absent (`docker/compose.test-matrix.yml`, `tests/integration/Db/SupportedDatabaseSchemaTest.php`); every `M` path present (entrypoint/Dockerfile, `IntegrationTestCase.php`, `TestDataService.php`, `TriggerJobs.php`, fixture files, all nine named E2E specs, `required-scenarios.json`, `playwright.config.ts`, `run-e2e-gate.js`, the four durable-doc paths); `phpunit.integration.xml` exists and PHPUnit `^9.5` supports `--fail-on-skipped`; `occ chorestrike:test-data:seed --profile=family` exists (`lib/Command/TestDataSeed.php`); `npm run stylelint|test:noise|docs:encoding|backlog:check|l10n:check-keys` and `composer cs:check` all exist; ADR-013..017 and `docs/testing/release-test-plan.md` exist.
- **Parent alignment:** CS-PLAN-070 authorizes exactly this WP9 slice (WP9 row with the 4-6-engineering-day + 14-calendar-day allowance matching the plan header, T12 mapping row, G3 final-evidence gate); the plan's UI-01..04 rows map the same delivered specs and correctly treat the parent's 2026-09-18 N/M labels as historical; no overlap with any other active plan (the parent is the only one).
- **Plan-only checks:** nine template sections complete; implementation order with per-step ownership and estimates; coherent single-slice scope with explicit non-goals; risks with likelihoods and mitigations; no pre-baked workaround stacking (provider-specific production workarounds and blanket non-MySQL exclusions are explicitly forbidden); twelve complete acceptance criteria; concrete test plan (coverage matrix with primary ownership, runtime gate procedure with exact compose/phpunit/E2E commands, human protocol with consent, anonymity and synthetic-case rules).

## Findings

| Severity | File:line | Issue | Status |
|---|---|---|---|
| — | — | none | — |

No open findings to route (plan-owned, fold-in, or backlog).

## Decisions

None required — the parent authorizes exactly this WP9 slice and the C5j consent/anonymity rules were user-approved 2026-09-17; no behavior, architecture, migration or security question arose. Family participation remains gated on separate authorization and guardian consent, as the plan itself records.

## Gates (documentation-only round)

- `npm run backlog:check` — green after the activation edits
- `npm run docs:encoding` — green after the activation edits
- `git diff --check` — clean
- Runtime gates (Jest, lint, build, Docker PHPUnit, browser gate) belong to implementation steps 2-6 of the plan and were intentionally not run (no runtime file changed).

## Plan Lifecycle

- Plan moved `new/` → `active/` with header status (`Active`, Standard A `PASS` 2026-10-03, implementation not started) and an appended `## Review Outcome` (baseline, fingerprint, verification, gates).
- CONCEPT-001: stays In Progress for WP9; CS-PLAN-080 recorded as the WP9 owner; no whole-item closure.
- Parent CS-PLAN-070 header (Next child) and WP9 row updated; `docs/README.md`, `docs/plans/README.md`, `docs/backlog/backlog.md` and `ACTIVE_TASKS.md` updated.
- Unrelated pre-existing `.gitkeep` deletions preserved unstaged, per the prior reviews' handling.

---

# Plan Activation: CS-PLAN-081 Pre-WP9 Hardening and Retained-Data Readiness — Standard A

**Review Date**: 2026-10-03
**Reviewer**: Kilo (main agent, no subagents — Standard A tier)
**Mode**: Standard A (plan review before `new/` → `active/`)
**Scope**: `docs/plans/new/cs-plan-081-pre-wp9-hardening-and-retained-data-readiness.md` plus its declared uncommitted drafting package (doc/plan indices, backlog/live registry, monthly archive rollover, parent/WP9 sequencing notes); runtime tree untouched
**Base Commit**: `76e282ef774d63c802a537a489d42c781abe62c6` (`master`; index empty at capture — tree `5b868e3`; unstaged binary-diff SHA256 `E182140A555BD74DFB3095DF853AB9DC0B1D33D3B2DBB9F9EE8077EEEF10E617` covering the drafting package plus the unrelated pre-existing `.gitkeep` deletions, preserved unstaged; untracked plan SHA256 `CC506A10DE4A63AC19813CA1011E5A87AF5AF5146CD226E9318063847FB4C090`)

## Executive Summary

### Outcome
- PASS-WITH-FIXES

### What Was Reviewed
- The complete preparation plan: problem summary with verified baseline, H1-H7 ownership/non-goal slices, membership gates/throttling/owner-operation audit logging, small user-facing corrections (basement cap, truthful reset copy, Snake ordering), first-retained-build/recovery contract, private reporting channel, H-T1..H-T11 test matrix, implementation gates, file manifest, implementation order, risks, acceptance criteria, decision proposals Q-081-01..05.
- Eligibility: sole regular non-symlink `*.md` in `docs/plans/new/`; destination `active/cs-plan-081-…` did not exist; no ambiguity.

### What Was Fixed Immediately
- One minor manifest path: `RoleGuardApiTest.php` cited under `tests/integration/Controller/` but living at `tests/integration/RoleGuardApiTest.php` — corrected in the plan §5 manifest.

### Main Risks Remaining
1. None blocking. Implementation-phase risks (draft-approval/productive-use confusion, harness fix masking production gaps, throttle/fixture interactions, log overpromise, whole-server restore damage, silently deferred operator channel, scope absorption) are named in plan §7 with mitigations.

## Verification Performed

- **Baseline citations exact:** migration head `Version1033`; `HouseService::inviteMember(int $houseId, string $email, string $role)` has no owner parameter (lib/Service/HouseService.php:156), `removeHouseMember`/`updateUserRole` take caller parameters (:227/:303); all four ordered queries in `lib/Db/MinigameScoreMapper.php` sort only by `score DESC` with no `id` tiebreak; `SetupWizard.vue:551-555` clamps `floorTotal = min(6, …)` and appends the basement only when `floors.length < 6` (silent drop at the cap); `.npmrc` enables `legacy-peer-deps` citing TECH-LINT-006; `jest.config.js:4` ignores only `/node_modules/`, `/archive/`, `/\.kilo/` test paths while `moduleNameMapper` maps canonical mocks (duplicate-mock indexing unaddressed, as claimed).
- **Manifest/commands:** every §4 gate command exists in package.json (`test:noise`, `l10n:check-keys`, `stylelint`, `test:e2e:gate`, `docs:encoding`, `backlog:check`); §5 manifest verified against the tree — every M path exists (after the RoleGuardApiTest fix) and no N path exists; all ten related backlog IDs exist and are open in `backlog.md`.
- **Ownership/no overlap:** slices H1-H7 map to existing backlog owners (TECH-JEST-001/TECH-LINT-006 H1, TECH-TEST-003 H2, CR-SEC-05/SEC-AUDIT-001 H3, FEAT-UX-032 H4 + CONCEPT-001 partial, CR-053-11 Snake remainder H5, RELEASE-002 partial H6, BUG-SEC-001 H7); WP9/CONCEPT-001 remainders stay with CS-PLAN-080/070 (cross-referenced sequencing notes in both active plans).
- **Plan-only checks:** nine template sections complete; per-step ownership with estimates; coherent preparation scope with explicit non-goals (no FEAT-UX-015, no chore approval, no Task Pass, no tour/search/print/bulk/perf sweep, no TECH-DIGEST-001); risks with likelihoods/mitigations; thirteen acceptance criteria; H-T1..H-T11 test matrix with primary evidence paths; drafting gates green and re-run by this review.

## Findings

| Severity | File:line | Issue | Status |
|---|---|---|---|
| minor | docs/plans/active/cs-plan-081-pre-wp9-hardening-and-retained-data-readiness.md:268 (§5 manifest) | `RoleGuardApiTest.php` cited under `tests/integration/Controller/` but the file exists at `tests/integration/RoleGuardApiTest.php` | fixed in-round |

No open findings to route (plan-owned, fold-in, or backlog).

## Decisions

Q-081-01..05 presented as one structured question batch; all recommended options approved by the user:
1. **Q-081-01** — post-WP9 baseline adoption for retained-data protection (no retroactive support; the fresh-install rule stands until explicit adoption).
2. **Q-081-02** — native `UserRateLimit`, 60 calls / 300 elapsed seconds / authenticated user, separately on invite/remove/updateRole.
3. **Q-081-03** — AppLogger operational owner-change events with NC-managed retention and explicit durability limits (no dedicated ledger).
4. **Q-081-04** — operator-approved existing dedicated mailbox and usable public key; H7 remains required.
5. **Q-081-05** — visible five-above-ground cap with basement selected (six total), consistent input/preview/payload.

Recorded in the plan §9 decision table and `## Review Outcome`.

## Gates (documentation-only round)

- `npm run backlog:check` — green after the activation edits
- `npm run docs:encoding` — green after the activation edits
- `git diff --check` — clean
- Runtime gates belong to implementation H1-H7 and were intentionally not run (no runtime file changed).

## Plan Lifecycle

- Plan moved `new/` → `active/` with header status (`Active`, Standard A `PASS-WITH-FIXES` 2026-10-03) and an appended `## Review Outcome` (baseline, fingerprints, verification, decisions, fixes, gates, transition).
- Standalone backlog owners marked In Progress under CS-PLAN-081: TECH-JEST-001, TECH-LINT-006 (H1), TECH-TEST-003 (H2), CR-SEC-05, SEC-AUDIT-001 (H3), FEAT-UX-032 (H4), CR-053-11 Snake remainder (H5), BUG-SEC-001 (H7); CONCEPT-001/RELEASE-002 keep parent/partial ownership.
- `docs/README.md`, `docs/plans/README.md`, `docs/backlog/backlog.md`, `ACTIVE_TASKS.md` and the parent/WP9 sequencing notes updated to the active plan path and outcome.
- Unrelated pre-existing `.gitkeep` deletions preserved unstaged, per the prior reviews' handling.

---
