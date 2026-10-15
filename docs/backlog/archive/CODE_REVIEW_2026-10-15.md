# Code Review: CS-PLAN-084 Round 8 (WP-G Part 2 — Phone Projects and Full Gate) — Interim Standard B

- **Review Date**: 2026-10-15
- **Reviewer**: Claude Code
- **Mode**: Standard B (interim; plan stays `active/`)
- **Scope**: working tree at `d883bfa` (index empty): 15 modified tracked paths (unstaged diff SHA256 prefix `c214b28e00bbda1d` at capture) plus untracked `tests/e2e/mobile-core.spec.ts` (`9605af61e6ba1cfe`) — `mobile-chromium`/`mobile-webkit` Playwright projects, the 7-scenario phone core set, multi-project gate manifest rows, reset-owning phone projects, regenerated scenario inventory, `3-floor-house` test-assumption fix, CI WebKit install, testing docs, operator real-device checklist, plan/backlog tracking
- **Owning plan**: [CS-PLAN-084](../../plans/active/cs-plan-084-family-trial-ux-baseline.md)

## Findings

| ID | Severity | Area | Issue | Status |
|----|----------|------|-------|--------|
| R8-01 | minor | `database-fixtures.ts` | The two new reset-owning phone projects had no pool-guard regression (only the refusals and the serial owner were pinned) | fixed (`databaseFixtures.spec.js`: both phone projects may seed) |
| R8-02 | minor | `mobile-core.spec.ts` | The handover test's comment claimed the successor "belongs to the default owner" although the UI read can only show the default assignee, the next due day and the child's day; the obligation itself is proven server-side | fixed (comment states what is observed and points to `SuccessorOwnershipTest`) |

## Verified Non-Findings

- Selection stays strict: a multi-project row must be selected by exactly its named device projects (a missing device project is a `wrong pool`, any extra pool a violation); single-pool rows behave as before. Per-run result checks narrow the row to the running project, so each device run must execute every required scenario itself. Tests are counted once per identity, not once per project.
- The phone projects only select `mobile-core.spec.ts`; every desktop project ignores it, so nothing runs twice. Both are serial and reset-owning and the gate runs them after the Chromium pools — never concurrently with another reset owner.
- The iPhone user-agent override is test configuration only (the descriptor's iOS 16 agent hits Nextcloud's unsupported-browser redirect); no product code or browser support policy changed.
- The regenerated `required-scenarios.json` only adds identities (eight rounds-1–7 scenarios plus the phone set); nothing required was dropped.
- The `3-floor-house` change keeps the request-count assertions (scope and search are client-side) and only removes the dependency on the default *My tasks* scope and the first chip's member.
- No product code, API, authorization, logging or user-visible string changed in this round.

## Gates

Jest 123 / 1780 after the fixes, `test:noise` clean; ESLint, `l10n:check-keys` (1288), `backlog:check`, `docs:encoding`, `git diff --check`, gate `--self-test` green. Canonical NC35: PHPUnit unit 1385, integration 613; full browser gate `run-sA3H52` PASSED (chromium 95, serial-db 171, scheduling-owner 39, mobile-chromium 10, mobile-webkit 10, first attempt, no skips) after `run-fVpMiR` failed on the fixed 3-floor assumption plus three seed `fetch failed` closures (TECH-TRANSPORT-001). AIO row `cs082-cs084m-aio` (NC 34.0.4 / PHP 8.4.25 / Redis 8.10.1): unit 1385, integration 613, full browser gate `run-FDzLEB` PASSED with the same counts. After the fixes: fixture Jest 9/9, phone handover 4/4 (incl. setup).

## Decisions

- **D-084-09 (operator, 2026-10-15):** AIO v14.3.0 (NC 34.0.4, PHP 8.4.26, Redis 8.10.2) is promoted and the AIO row requalified within CS-PLAN-084 before the hand-back to CS-PLAN-080.
- Interim review + commit of round 8 before package build/smoke (operator, 2026-10-15).

## Outcome

**PASS-WITH-FIXES (interim).** Two minor findings fixed in-round; no open findings from this round. Open plan work: AIO v14.3.0 requalification, package build/smoke on both rows, inventory promotion, the operator's real-device run, final review.

## Round 9

# Code Review: CS-PLAN-084 Round 9 (D-084-09 AIO v14.3.0 Runtime Promotion) — Interim Standard B

- **Review Date**: 2026-10-15
- **Reviewer**: Claude Code
- **Mode**: Standard B (interim; plan stays `active/`)
- **Scope**: working tree at `bad0baa` (index empty): 13 modified tracked paths (unstaged diff SHA256 prefix `48a48523813a93f5` at capture), no untracked files — inventory PHP 8.4.26 / Redis 8.10.2 / AIO 14.3.0, `Dockerfile.test` defaults and inventory hash, canonical compose runtime identity, pinned Jest hashes and drift fixtures, current-runtime statements in README/docs/docker-dev skill, plan/tracking
- **Owning plan**: [CS-PLAN-084](../../plans/active/cs-plan-084-family-trial-ux-baseline.md)

## Findings

None.

## Verified Non-Findings

- The new image references are the registry index and linux/amd64 digests reported by `docker buildx imagetools inspect` and match what `test:runtime:check` compares; `Dockerfile.test` defaults equal the generated primary build arguments (Jest R7 pin) and carry the new selection hash.
- PHP is a single inventory field, so the primary NC35 row moved with the AIO row; both images were rebuilt and `verify-image` reports `frozen-image-identity-match`. The AIO `lcobucci/clock` limitation is the same reviewed failure, now on PHP 8.4.26, which AIO v14.3.0 ships itself — no new allowlist entry, no suppressed diagnostic.
- Drift fixtures were moved one patch beyond the new inventory, so every drift label stays exercised; no assertion was weakened.
- Historical records (install preparation, done plans, review archives) keep PHP 8.4.25 deliberately; only current-state statements changed.
- The package builder (`docker/package-build.json`, `Dockerfile.package`) keeps its separate reproducible-build PHP pin; it is not a runtime row and no test couples it to the inventory.
- The previous-patch images (`cs082-runtime-35.0.0-local`, `-34.0.3-local`) are only inputs of the P2 preservation proof, which this plan does not run; they are rebuilt with that proof.

## Gates

Both images rebuilt and verified. Canonical stack recreated (`canonical-runtime-match`, PHP 8.4.26): PHPUnit unit 1385, integration 613; full browser gate `run-w3lY5x` PASSED (95 / 171 / 39 / 10 / 10, first attempt). AIO row `cs082-cs084n-aio` (NC 34.0.4.1 / PHP 8.4.26 / PostgreSQL 18.6 / Redis 8.10.2): unit 1385, integration 613, full browser gate `run-3CDnAc` PASSED with the same counts. `test:runtime:check` `current`, no drift. Jest 123 / 1780, ESLint, `test:noise`, `docs:encoding`, `backlog:check`, `git diff --check` green.

## Outcome

**PASS (interim).** No findings. Next: package build + smoke on both rows, inventory promotion, the operator's real-device run, final review.

## Round 10

# Code Review: CS-PLAN-084 Round 10 (D-084-10 Inventory Qualified + Release Discovery Fix) — Interim Standard B

- **Review Date**: 2026-10-15
- **Reviewer**: Claude Code
- **Mode**: Standard B (interim; plan stays `active/`)
- **Scope**: working tree at `0550316` (index empty): 9 modified tracked paths (unstaged diff SHA256 prefix `78631c3aeac982d4` at capture), no untracked files — inventory status `qualified`, `Dockerfile.test` hash default, Jest hash/qualification pins, `highestStableRelease` drift discovery with regression, testing.md status semantics and discovery note, TECH-RUNTIME-002 progress, plan/tracking
- **Owning plan**: [CS-PLAN-084](../../plans/active/cs-plan-084-family-trial-ux-baseline.md)

## Findings

None open. The discovery defect (`releases/latest` = most recently published, reported upstream 33.0.10) was found by this round's own drift check and fixed with a regression before review.

## Verified Non-Findings

- The status flip changes every selection hash; all four images were rebuilt and verified under the new hashes, and both rows reran backend and full browser gates — no evidence is bound to a stale hash. The package smoke follows on the committed HEAD.
- `highestStableRelease` fails closed on a non-list or a list without a stable `vX.Y.Z` entry, skips drafts, prereleases, release candidates and `helm-chart-*` tags, and compares versions numerically (`35.0.10 > 35.0.9`). The release list is sorted by creation date, so the highest stable version is always within the newest 100 entries.
- The "never upgrades a candidate" proof is kept on an explicit candidate copy; tooling still only reports the inventory status.

## Gates

Four images `frozen-image-identity-match` (`qualified`). Canonical (`canonical-runtime-match`, `qualified`): unit 1385, integration 613, full browser gate `run-kHjKwd` PASSED (95 / 171 / 39 / 10 / 10). AIO row `cs082-cs084q-aio` (NC 34.0.4.1 / PHP 8.4.26 / Redis 8.10.2): unit 1385, integration 613, full browser gate `run-H3C9LH` PASSED. Jest 123 / 1781 (runtime suite 106/106; negative control: 14 failures on the old discovery), ESLint, `test:noise`, `docs:encoding`, `backlog:check`, `git diff --check` green. Drift check after the fix: `update-needed`, only `upstream-nextcloud` (35.0.2).

## Outcome

**PASS (interim).** No open findings. Next: package smoke on the committed HEAD; operator decision on upstream NC 35.0.2; operator real-device run; final review.
