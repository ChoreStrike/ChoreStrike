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
