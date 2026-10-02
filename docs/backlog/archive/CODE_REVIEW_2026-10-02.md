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

---

# Final Code Review: CS-PLAN-079 WP8 Reward UI and Onboarding — Standard B

**Review Date**: 2026-10-02
**Reviewer**: Kilo (main agent, no subagents — Standard B tier)
**Mode**: Standard B (final implementation review; whole-owning-plan verification for the `active/` -> `done/` transition)
**Scope**: the complete WP8 working-tree package (46 modified + 11 untracked files: reward store/view/components, API wrappers, stores/reset/router/App/help/l10n, Jest suites, `family-rewards.spec.ts` + gate registration) plus the plan's full file manifest, acceptance criteria, required test evidence, durable-doc integration and implementation history
**Base Commit**: `812721a910bbd9aed3c9c282260d751645d22d2b` (`master`; index empty at capture — staged-diff sha1 `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391`; unstaged-diff sha1 `671e682493f453c1d8ddb2316d17cb98e77925b6`, 434254 bytes; 11 untracked files with per-file SHA256 captured before review; unrelated pre-existing `.gitkeep` deletions preserved unstaged)

---

## Executive Summary

### Outcome
- PASS-WITH-FIXES

### What Was Reviewed
- Full source review of every new/changed frontend file: `src/store/rewards.js`, `src/views/Rewards.vue`, `src/components/Rewards/{RewardCatalogEditor,RewardRequestList,RewardRequestDetails}.vue`, `src/services/api.js` wrappers, `src/utils/minorUnits.js` `parseCoinMinor`, house/preferences/game/user store extensions, `useStoreReset` enumeration (15 stores), router/App navigation gating, help/glossary extension, Settings/wizard/context placements, l10n artifacts, the Jest suites and the T11 browser spec + gate registration.
- Contract verification against `docs/api/rewards.md`: exact command payloads (`expectedVersion`, `commandId`, closed reason sets, caller-own `wallet` snapshot, settlement-referenced correction), cursor directions/dedup/exhaustion, fail-closed two-gate browsing, persistent request surfaces, UUID identity retention with same-key replay, definitive-4xx discard, generation guards, no optimistic accounting, no foreign wallet display, escaped authored text, literal-`t()` label maps, no `console.*`/`v-html`.
- Plan completion: manifest all-N present / all-M extended, 12/12 acceptance criteria, T11 registered (9/9 exact titles across `required-scenarios.json`, `run-e2e-gate.js`, `playwright.config.ts`), durable-doc integration performed in-review.

### What Was Fixed Immediately (user-approved in-round fix cluster)
1. RF-079-01 (minor) — `refreshAfterCommand` dropped the active state filter on post-command refreshes; now passes the context's `state` (regression added).
2. RF-079-02 (minor) — `ensureCatalog` never released its one-shot guard on failure, breaking the ErrorState retry; guard releases on failure and `loadAll()` restarts the read on retry (regression added).
3. RF-079-03 (minor) — `updateCatalogEnabled` left `stateVersion` stale after a 409; the catch now re-reads the house per plan §3.5 (regression added).
4. RF-079-04 (nit) — `backlog.md` FEAT-UX-023 status line lost its closing parenthesis + hard wraps; fixed.

### Main Risks Remaining
1. None blocking. WP9 owns cross-DB verification, the final gate pair and voluntary family evidence (CONCEPT-001 stays open); FEAT-UX-023 keeps its wider guided-tour remainder.

---

## Findings

| Severity | File:line | Issue | Status |
|---|---|---|---|
| minor | src/store/rewards.js:567 (`refreshAfterCommand`) | Post-command refresh reset touched request pages without their active state filter (filtered list silently flipped to All) | fixed in-round + regression |
| minor | src/views/Rewards.vue (`ensureCatalog`) | One-shot `catalogRequested` never released on failure; ErrorState retry never re-fetched and degraded to the empty state | fixed in-round + regression |
| minor | src/store/house.js (`updateCatalogEnabled`) | No authorized house re-read after a 409 - stale `stateVersion` until a Settings remount (plan §3.5 violation for this control) | fixed in-round + regression |
| nit | docs/backlog/backlog.md (FEAT-UX-023 status) | Missing closing parenthesis + awkward hard wraps | fixed in-round |

All findings plan-owned and resolved; nothing routed to the backlog; no `// HACK:` additions.

## Questions and Decisions

1. Disposition of RF-079-01..03 (logic changes - not silently auto-fixed)
   - Option A (Recommended): fix all three in-round with regressions and rerun gates - selected.
   - Option B: record as plan findings (`NEEDS-FOLLOW-UP`, plan stays active).
   - Option C: fix only RF-079-02 now, park the rest.
2. Browser evidence depth after the fixes
   - Option A (Recommended): full canonical three-pool gate on the final tree - selected.
   - Option B: serial pool only.
   - Option C: Jest + unit gates only.

## Gates (rerun green on the reviewed final tree)

- `npm test -- --runInBand` - Jest 1219/1219, 108 suites (1216 + 3 review regressions)
- `npm run lint` / `npm run stylelint` - zero problems
- `npm run build` - webpack 5.105.2 compiled successfully
- `npm run test:noise` - free of error-level log noise
- `npm run l10n:check-keys` - 1269 keys, POT/EN/DE exact coverage
- `npm run backlog:check` - 32 scoped files green; `npm run docs:encoding` - 865 files clean
- `git diff --check` - clean
- Docker: PHPUnit unit OK (1116 tests, 3616 assertions) + integration OK (529 tests, 2937 assertions), both `--fail-on-skipped` with zero skips (no PHP changed); `composer cs:check` 0/395 fixable
- `node scripts/run-e2e-gate.js --self-test` - passed
- `npm run test:e2e:gate` - **PASSED**: chromium -> chromium-serial-db -> chromium-scheduling-owner sequentially, zero skipped/failed/flaky required scenarios

## Documentation and Backlog Updates

- Durable docs integrated: UC-17 + gap-index/whitespace rows (`concepts/ui-use-cases.md`), `useRewardsStore` contract block + directory entries (`engineering/architecture.md`), optional-areas two-gate contract + fifteen-store reset (`concepts/calm-core-ux.md`), WP8 implemented note (`concepts/gamification-and-rewards.md`), serial-pool spec note (`engineering/testing.md`), release checklist 8a + coverage pointer (`testing/release-test-plan.md`), wizard help note (`concepts/house-setup-and-templates.md`). `docs/api/rewards.md` unchanged - the client mirrors the documented contract.
- CS-PLAN-079 moved `active/` -> `done/` with all 12 acceptance criteria checked and RF-079-01..04 resolved in its Review Findings; final Review Outcome appended (baseline, fingerprint, verification, decisions, gates).
- FEAT-UX-026 closed to `archive/2026-10.md` (whole scope satisfied by WP7+WP8); FEAT-UX-023 keeps its wider tour remainder; CONCEPT-001 stays In Progress for WP9.
- Parent CS-PLAN-070 header + WP8 row marked DELIVERED (parent stays active for WP9); `docs/README.md`, `docs/plans/README.md`, `ACTIVE_TASKS.md` updated.

## Plan Lifecycle

- Final Standard B `PASS-WITH-FIXES` with the whole owning plan verified complete: eligible `active/` -> `done/` transition performed with this review's scoped commit.
- Unrelated pre-existing `.gitkeep` deletions preserved unstaged, per the prior reviews' handling.
