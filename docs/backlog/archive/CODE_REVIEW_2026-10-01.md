# Code Review: CS-PLAN-077 WP6 Calm Core UI — Final Standard B

**Review Date**: 2026-10-01
**Reviewer**: Kilo (main agent, no subagents — Standard B tier)
**Mode**: Standard B (final implementation review of the whole owning plan)
**Scope**: The complete uncommitted WP6 implementation package against the owning plan [cs-plan-077-wp6-calm-core-ui.md](../../plans/done/cs-plan-077-wp6-calm-core-ui.md): 79 modified tracked files + 12 untracked paths (before review fixes), spanning routing/landing, calm feedback, preferences/pause/agreement UI, store reset, help slice, no-Coin closure, Jest/E2E suites, gate config, l10n artifacts and durable docs.
**Base Commit**: `2f1abd5` (`master`, index empty at capture; unstaged-diff sha1 `8e05ee65c9f6acbddf178e2ae143073595635de5`; staged-diff sha1 `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391` (empty); 16 untracked paths fingerprinted per-file SHA256, recorded in the session capture)

---

## Executive Summary

### Outcome
- PASS-WITH-FIXES

### What Was Reviewed
- All-age My Day landing/content (router redirect, TodayView C5f content set, Dashboard tagline/wallet/arcade disposition)
- Calm feedback gating (sound/confetti opt-in modules, useCelebration re-sync, reduced-motion hard floor)
- `NotificationPreferences.vue` + optional-area consumers (`/arcade` route, nav gating, Settings delegation)
- Pause UI (`pauses` store, `PauseSettings.vue`, owner house administration) and occurrence agreement entry points (`TaskListDetails.vue` + scheduling store actions)
- Shared-device reset (`useStoreReset.js`, fourteen-store enumeration), help slice (`HelpEntry`/`HelpGlossary`), no-Coin closure, Leaderboard a11y pass
- Test surface: 10 modified + 5 new Jest specs, 37 modified E2E specs (landing cutover), new `calm-core-ui.spec.ts`, gate manifest/config, l10n artifacts, durable docs (`concepts/calm-core-ux.md`, testing.md, gamification skill), backlog updates

### What Was Fixed Immediately
1. **RF-077-07 (major, i18n)** — `HelpGlossary.vue` glossary strings and `PauseSettings.vue` `Acknowledged`/`Declined` state labels reached `t()` through dynamic map lookups whitelisted in `check-l10n-keys.js` DYNAMIC; the checker never extracted the map values, so 22 strings were absent from POT/EN/DE and rendered untranslated outside English. Fixed: literal `t()` keys (computed terms / label methods), both DYNAMIC rows removed, artifacts regenerated via `--write` (1108 keys) with full German copy supplied; `l10n:check-keys` green.
2. **RF-077-08 (minor)** — cleared digest-time field sent `''` instead of `null` (parity regression vs. the replaced WP5 inline form); `|| null` restored.
3. **RF-077-09 (minor)** — untracked throwaway `tests/e2e/zzdebug-dash.spec.ts` (no assertions, one ESLint warning) deleted per user decision.

### Main Risks Remaining
1. None blocking. `SchedulePlanner.vue` agreement entry deferred until the planner gains occurrence-row identity (RF-077-03, user-accepted); WP8 remainders (tour/catalog help, catalog consumer) tracked in FEAT-UX-023/CONCEPT-001.

---

## Scope and Risk Framing

Frontend-only package; no PHP file touched. Backend contract unchanged (consumed WP1-WP5 reads/commands). Risk hypotheses checked: landing-switch regression across 37 E2E specs, celebration-gating visibility regression, child/owner authority blur in pause UI, shared-device leakage, l10n key drift — all covered by pinned tests and the three-pool gate.

## Findings

| Severity | File:line | Issue | Status |
|---|---|---|---|
| major | src/components/Help/HelpGlossary.vue, src/components/Settings/PauseSettings.vue | Dynamic `t()` map values invisible to the l10n key check; 22 strings missing from POT/EN/DE (RF-077-07) | fixed |
| minor | src/components/Settings/NotificationPreferences.vue:276 | `digestTime` empty string vs `null` parity (RF-077-08) | fixed |
| minor | tests/e2e/zzdebug-dash.spec.ts | Throwaway debug spec, ESLint warning (RF-077-09) | fixed (deleted, user decision) |
| minor | plan §9 RF-077-03..06 | Documented scope consolidations (planner entry unused, nav-level help, catch-up testid rename, `/arcade` route) | resolved — user-accepted as documented |

## Decisions (batched structured question, recommendations first, all selected)

1. zzdebug spec → **delete**.
2. RF-077-03..06 → **resolve all four as accepted** documented scope consolidations.
3. E2E evidence → **rerun the full three-pool gate** on the reviewed tree.
4. Backlog closure → **close FEAT-UX-024 + FEAT-UX-025** to `archive/2026-10.md`; FEAT-UX-023 stays open (WP8 remainder); CONCEPT-001 stays In Progress.

## Gates (all rerun by this review on the reviewed tree)

- Jest 1118 tests / 103 suites — green
- `npm run test:noise` — green
- ESLint + stylelint — clean (zero warnings after the debug-spec deletion)
- webpack build — success
- `l10n:check-keys` — 1108 source/catalog keys, POT/EN/DE exact coverage
- `backlog:check`, `docs:encoding` (824 files), `git diff --check` — green
- Docker PHPUnit unit 1028/3287 and integration 493/2654 with `--fail-on-skipped` (zero skips), `composer cs:check` 0-of-377 — green (backend untouched; runtime graph certified)
- Full three-pool browser gate `run-jYP3nz` — chromium 91 + chromium-serial-db 132 + chromium-scheduling-owner 39 scenarios, all expected, zero skipped/failed/flaky/violations (rerun after the review fixes; the first rerun attempt failed environmentally — `TEST_DB_PROFILE` defaulting to `minimal` after a volume reset — recreated with `family` per the canonical workflow, not a product defect)

## Plan Lifecycle

- Plan moved `active/ → done/`; all 15 acceptance criteria verified complete; §9 findings RF-077-01..09 resolved.
- FEAT-UX-024 and FEAT-UX-025 closed to `archive/2026-10.md`; CONCEPT-001 narrative updated (WP6 delivered); parent CS-PLAN-070 WP6 row marked DELIVERED; `docs/README.md`, `docs/plans/README.md`, `docs/concepts/ui-use-cases.md` and `ACTIVE_TASKS.md` updated.
- Durable knowledge integrated in the implementation round (`docs/concepts/calm-core-ux.md`, `docs/engineering/testing.md`, gamification skill celebration contract) — the plan is not the only description.

---

# Plan Activation: CS-PLAN-078 WP7 Catalog and Request Backend — Standard A

## Executive Summary

### Outcome

**PASS** — plan activated `new/ → active/`; no findings, no fixes required.

### What Was Reviewed

- Scope: `docs/plans/new/cs-plan-078-wp7-catalog-and-request-backend.md` only (documentation round; runtime tree untouched).
- Baseline: `master` at `4b5b089ea180ab6514eb94725eff78032f172138` (equals the planning baseline recorded in the header); index empty at capture; unstaged diff sha1 `2e01799d35b56001812661085a6cbf756ab66d34` (drafting metadata package plus unrelated `.gitkeep` deletions, both preserved unstaged where unrelated); untracked plan SHA256 `18EC3E9E222E780DA4DB3617B8655405AE84C4FDEF50FFDCB03A21AF66E9BEDC`.
- Eligibility: sole regular non-symlink `*.md` in `docs/plans/new/` (pinned cloud-reparse attribute, `LinkType`/`Target` empty — regular file); destination `active/` did not exist; no ambiguity.

### Verification Performed

- Section-1 baseline citations checked exact against the runtime: `House::jsonSerialize()` omits `catalogEnabled` while the typed property and Version1000 flag exist; `WalletService::postEntries()` owns booked value with `reserved_minor` present and no reservation writer; own-wallet reads expose B/R/A + `fundingHold`; `HouseUser`/`Version1004Date20260228131728` (the `house_users` creator) have no `role_revision`; `DigestService::orgSourceIsRelevant()` closed over takeover/agreement/pause; `AccountErasureService` covers active memberships plus the orphan-wallet sweep; `ensureBinding()` on rejoin; `updateUserRole()`; `CommandService::runCommand()`; frozen house-first lock order with the final child-row group available for reward rows; `catalogVisible` persisted on `MemberPreference`; no reward routes/entities/services/controllers/tests exist; `PUT /house` (`house#update`) has no command/version contract while `house#reset` demonstrates the pattern the new `PUT /house/catalog` follows.
- Manifest: all 26 M paths present, all 21 N paths absent; `Version1033Date20260918000000.php` matches the parent's reservation (CS-PLAN-070 line 1041); ADR-017 number free; all referenced npm gate scripts exist.
- Parent alignment: §3.2 state/actor/accounting table matches the approved C4 matrix row for row; C4.1-14/T6/C3.10-11 enumerated in the parent (line 850); effort consistent with the WP7 row; no overlap with any other active plan.
- Plan-only checks: 9 template sections complete; per-step ownership with exit evidence; coherent single-slice scope; risks with likelihoods and mitigations; acceptance criteria complete; test plan concrete (races with real connections, injected rollback, replay/authorization-on-replay, erasure, privacy HTTP matrix, browser contract).

## Findings

None. No open items to route (plan-owned, fold-in, or backlog).

## Decisions

None required — C4 product behavior was user-approved 2026-09-17 and the parent authorizes exactly this WP7 backend slice; in-plan "Proposal:" parameter choices (text bounds, list paging) fall inside that approval and need no separate authorization.

## Gates (documentation-only round)

- `npm run backlog:check` — green after activation edits
- `npm run docs:encoding` — green after activation edits
- `git diff --check` — clean
- Plan standalone whitespace check — clean
- Runtime gates (Jest, lint, build, Docker PHPUnit, browser gate) belong to implementation steps 2-7 of the plan and were intentionally not run (no runtime file changed).

## Plan Lifecycle

- Plan moved `new/ → active/` with header status and appended `## Review Outcome` (baseline, fingerprint, verification, outcome).
- FEAT-UX-026 set **In Progress** (WP7 backend slice owned by CS-PLAN-078); Standard A sub-item checked, T6 implementation sub-item added.
- Parent CS-PLAN-070 header and WP7 row updated (child activated, implementation not started); `docs/README.md`, `docs/plans/README.md` and `ACTIVE_TASKS.md` (In Progress entry + consolidated draft/activation line) updated.
- Unrelated pre-existing `.gitkeep` deletions preserved unstaged, per plan §5 instruction and the prior reviews' handling.
