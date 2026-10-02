# Plan Activation: CS-PLAN-079 WP8 Reward UI and Onboarding — Standard A

**Review Date**: 2026-10-02
**Reviewer**: Kilo (main agent, no subagents — Standard A tier)
**Mode**: Standard A (plan review before `new/` → `active/`)
**Scope**: `docs/plans/new/cs-plan-079-wp8-reward-ui-and-onboarding.md` only (documentation round; runtime tree untouched)
**Base Commit**: `f7ddcbb1860b9c1b4d2021bfd10960e535081247` (`master`, equals the planning baseline recorded in the plan header; index empty at capture — staged-diff sha1 `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391`; unstaged-diff sha1 `56926889dd679b611d610f5cb5fe33c492f9d7a2` covering the draft-support tracking package plus the unrelated pre-existing `.gitkeep` deletions, preserved unstaged; untracked plan SHA256 `C5F9B645B77C9571590098EE32BB7D8FED49834E65448D07BA08E01A75B9A0BB`)

---

## Executive Summary

### Outcome
- PASS-WITH-FIXES

### What Was Reviewed
- The complete WP8 child plan: problem summary/gap table, strategy and owner boundaries, surfaces/two-gate design, transport/read-state, exact prices, request states/attestations, conflict/uncertain-outcome handling, essential onboarding/help, accessibility/localization, test plan (Jest + T11 browser + gates), file manifest, implementation order, risks, acceptance criteria.
- Eligibility: sole regular non-symlink `*.md` in `docs/plans/new/` (`LinkType`/`Target` empty — regular file); destination `active/` did not exist; no ambiguity.

### What Was Fixed Immediately
1. Plan §3.1 said proposed routes "reuse `Rewards.vue`" although `src/views/Rewards.vue` does not exist (the manifest correctly lists it as `N`); reworded to "use one new `Rewards.vue`" — documentation-only wording fix.

### Main Risks Remaining
1. None blocking. Implementation-phase risks (stranded promises under hidden browsing, fresh-key retry duplication, stale roles, decimal conversion) are named in plan §7 with mitigations and required T11 proofs.

---

## Verification Performed

- **Baseline citations exact against the runtime:** `src/store/preferences.js:23-39` and `lib/Service/PreferenceService.php:274-287` default `catalogVisible=true`; `src/utils/minorUnits.js` exports exact formatters (`formatCoinMinor`/`formatEnergyMinor`/`formatMinor`) and no parser; `src/store/house.js:242-271` retains the uncertain reset command identity (`pendingResetCommand`, 4xx-only identity reset); `src/views/Dashboard.vue:52-54` reads `gameStore.wallet`; `src/store/user.js` has `applyOwnWallet()` (line 286) and the cached `initializeCurrentUser()` (line 66); `src/store/game.js` `fetchGameState()` (line 74); `src/components/Help/HelpEntry.vue` renders a navigation `<li>` with `NcModal`; shared `Modal.vue`/`ConfirmDialog.vue`/`EmptyState.vue`/`ErrorState.vue` exist under `src/components/Common/` (the plan references them by name, no wrong path).
- **API contract claims match `docs/api/rewards.md` exactly:** `PUT /house/catalog` is the only gate writer (`PUT /house` never); versioned payloads carry `expectedVersion`; reject/cancel use closed `reasonCode` sets; false-fulfillment correction requires the original `settlementEntryId`; `allowedActions` is a viewer hint; `fundingHold` (B<R) is orthogonal; title 1–120 / description ≤2000 bounds.
- **Manifest:** every `M` path present (all stores, views, components, API/router, help/setup surfaces, Jest specs, E2E specs/fixtures, gate config/scripts, durable docs); every `N` path absent (`src/store/rewards.js`, `src/views/Rewards.vue`, the three `src/components/Rewards/` components, their specs, `tests/e2e/family-rewards.spec.ts`).
- **Gate infrastructure:** `tests/e2e/fixtures/{database-fixtures,auth-contexts,completion-api}.ts` exist; `SEED_DEPENDENT_SPECS` and the `chromium-serial-db` pool exist in `playwright.config.ts`; `scripts/run-e2e-gate.js` has `--self-test` and internally uses Playwright `--list` discovery while exposing no `--list` CLI option itself — the plan's statement is accurate.
- **Parent alignment:** CS-PLAN-070 authorizes exactly this WP8 slice (WP8 implementation row, T11 test mapping, and the recorded 5-8-day refinement of the original 3-5-day allowance, parent lines 856/1001/1118/1130); no overlap with any other active plan (the parent is the only one).
- **Plan-only checks:** nine template sections complete; implementation order with ownership and exit evidence; coherent single-slice scope; risks with likelihoods and mitigations; acceptance criteria complete; test plan concrete (exact URLs/payloads, cursor directions, role/state matrix, uncertain-outcome UI proof, real EN/DE and mobile/keyboard browser scenarios with gate registration).

## Findings

| Severity | File:line | Issue | Status |
|---|---|---|---|
| minor | plan §3.1 | "reuse `Rewards.vue`" implied an existing file; manifest lists it as `N` | fixed (reworded) |

No open findings to route (plan-owned, fold-in, or backlog).

## Decisions

None required — the parent authorizes exactly this WP8 slice and the WP7/C4 product behavior was user-approved 2026-09-17; no behavior, architecture, migration or security question arose.

## Gates (documentation-only round)

- `npm run backlog:check` — green after the activation edits
- `npm run docs:encoding` — green after the activation edits
- `git diff --check` — clean
- Runtime gates (Jest, lint, build, Docker PHPUnit, browser gate) belong to implementation steps 2-6 of the plan and were intentionally not run (no runtime file changed).

## Plan Lifecycle

- Plan moved `new/` → `active/` with header status (`Active`, Standard A `PASS-WITH-FIXES` 2026-10-02, implementation not started) and an appended `## Review Outcome` (baseline, fingerprint, verification, fix, gates).
- FEAT-UX-026: WP8 Standard A sub-item checked; CS-PLAN-079 added as WP8 owner; item stays In Progress for the WP8 UI implementation remainder.
- FEAT-UX-023: status updated (WP8 essential slice activated); wider guided-tour scope remains open — no whole-item closure.
- CONCEPT-001: narrative updated (WP8 activated); stays In Progress for WP8-WP9 and family evidence.
- Parent CS-PLAN-070 header/T11/WP8 rows, `docs/README.md`, `docs/plans/README.md` and `ACTIVE_TASKS.md` (In Progress entry + draft/activation line) updated.
- Unrelated pre-existing `.gitkeep` deletions preserved unstaged, per the prior reviews' handling.
