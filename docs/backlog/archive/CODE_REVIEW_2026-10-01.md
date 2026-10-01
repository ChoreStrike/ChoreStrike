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

# Code Review: CS-PLAN-078 WP7 Catalog and Request Backend — Final Standard B

## Executive Summary

The complete WP7 change set (owner-gated family reward catalog + the full C4
request lifecycle) was reviewed in four read-only tracks (correctness,
architecture/ownership, security/auth/data-flow, performance/code-quality)
over the §5 manifest plus the owning plan's acceptance criteria, test
evidence and durable-doc integration. 1 block + 8 major + 19 minor/nit
consolidated findings were ALL fixed in-round or resolved by analysis — the
plan's `## Review Findings` checklist has zero open entries. Outcome:
**PASS-WITH-FIXES**; the plan moves `active/ → done/` with every acceptance
criterion verified and all durable docs integrated (`docs/api/rewards.md`,
ADR-017, concepts/architecture/notifications/testing/SKILL/development).

## Scope and Risk Framing

- Scope: 54 modified + 22 untracked paths at `master`
  `18eb7ad32e01c4015dc36c93c94c7cedab44e424` (index empty at capture;
  capture-time unstaged-diff sha1 `200ab75fb3b2cb90557c2e14f364300b600370ec`;
  final reviewed tree after fixes unstaged-diff sha1
  `1daff20561b798611b7e827182e7bde285d0f4a7`; untracked per-file SHA256
  inventory in the appendix below).
- Owning plan: CS-PLAN-078 (WP7), parent CS-PLAN-070 — unambiguous.
- Risk: authorization (C4 role/authority matrix + WP7 receipt guard),
  migrations (fresh-install chain Version1004 amendment + Version1033),
  money (reserve/settle/refund ledger sources), erasure/privacy.

## Findings (all resolved; routing per scope-first)

| # | Sev | File:Line | Issue | Status |
|---|-----|-----------|-------|--------|
| 1 | block | RewardConcurrencyTest.php:197 | Race-test baseline read post-race (fulfill branch unpassable) | fixed |
| 2 | major | RewardConcurrencyTest.php:291 | Vacuous rollback assertion (baseline after sabotage) | fixed |
| 3 | major | Version1033:104 | `idx_rreq_wallet_state` led with house_id — unusable for wallet-led queries | fixed (wallet_id, state, id) |
| 4 | major | RewardRequestService.php:690 | List-path N+1 serialization | fixed (batched prefetch) |
| 5 | major | RewardController.php, RewardRequestController.php | `is_int` paging boundary broke real HTTP GET params | fixed (ctype_digit + strict rejection) |
| 6 | major | family-reward-api.spec.ts | Lifecycle test left the catalog gate ON in the shared DB | fixed |
| 7 | major | HouseService.php:229 | Owner-invariant checked on a stale pre-lock read | fixed (under-lock recheck) |
| 8 | major | HouseService.php:246 | Departure audit/digest actor null (attribution + actor-exclusion broken) | fixed (caller forwarded) |
| 9 | minor | RewardRequestService.php:459 | Departure digest queued only the first request | fixed (per request) |
| 10 | minor | RewardRequestService.php:435 | Role-rebase digest excluded the requester; `ownersOf` in loop | fixed |
| 11 | minor | WalletService.php:207 | `settleReward` lacked reservation undershoot guard | fixed |
| 12 | minor | RewardConcurrencyTest.php:221 | Approval-vs-rebase race had a dead branch | replaced with cancel-vs-rebase (both branches live) |
| 13 | minor | RewardRequestServiceTest | C4 matrix gaps (denials, owner-fulfills-adult, replay) | 5 cases added |
| 14 | minor | RewardService.php:143 | No-op catalog toggle bumped `stateVersion` | fixed |
| 15 | minor | HouseService.php:215 | Stale C4e docblock | fixed |
| 16 | minor | RewardItemMapper.php:70 | Cursor docblock contradicted the code | fixed |
| 17 | minor | Mappers | Dead methods (`findByHouseIds`, `findAllByHouseId`, `find`) | removed |
| 18 | minor | RewardRequestService.php:510 | `runLifecycleCommand` ~97 lines | extracted (`executeLifecycleEffectUnderLock`, `digestRecipientsFor`) |
| 19 | minor | Services | Duplicated commandId/version validators | deduped (`CommandService` statics) |
| 20 | minor | WalletService.php:207 | Double wallet lock after `postEntries` | resolved by analysis (post-ledger read is structurally required) |
| 21 | nit | RewardRequestController.php:81 | FQCN constants inline | imported |
| 22 | nit | RewardService.php:372 | Hardcoded English 'Text' label in translated message | localized (key added) |
| 23 | nit | RewardRequestService.php:538 | Dead `$wallet` assignment | removed |
| 24 | nit | Services + call sites | Dead `$userId` parameters | removed |
| 25 | nit | RewardRequestService.php | Audit-actor N+1 | memoized |
| 26 | nit | docs/api/rewards.md:39 | Broken ADR-014 link | fixed |
| 27 | nit | RewardConcurrencyTest | tearDown duplicated base cleanup; unused helper/const | removed |
| 28 | nit | RewardRequestService.php | Missing `@return` tags | added |

Out-of-scope (backlog): `DigestChangeMapper::expireQueuedBefore` uses
`setMaxResults()` on an UPDATE (silently ignored by Doctrine DBAL) —
pre-existing WP5 defect → **TECH-DIGEST-001** (P4).

## Decisions

No batched user questions were required: every behavior change conforms to
the approved C4/C5e text (departure digest per request, role-rebase
including the requester, no-op toggle not advancing the version), and all
fixes stayed inside the plan's scope.

## Gates (all rerun by this review on the reviewed tree)

- Unit PHPUnit **1116 OK** (host + Docker container, `phpunit.unit.xml`)
- Integration PHPUnit **529 OK** (`phpunit.integration.xml`, rebuilt family-profile fresh chain `down -v` + `up -d`)
- Jest **1118 OK** · `composer cs:check` 0-of-395 · ESLint clean
- `l10n:check-keys` 1137 keys EN/DE/POT exact · docs encoding 842 files · backlog checker green · `git diff --check` clean
- Full three-pool E2E gate **PASSED** (`run-l4c22e`: chromium + serial-db + scheduling-owner, zero skipped/failed/flaky required scenarios). An intermediate gate run flaked in `3-floor-house.spec.ts` under host load (click-stability timeouts); the spec passes standalone and in the rerun — not a regression.

## Plan Lifecycle

- Plan moved `active/ → done/` with all 14 acceptance criteria checked and
  every `## Review Findings` entry resolved; the final `## Review Outcome`
  records scope, fingerprint, tracks, findings, decisions and gates.
- Parent CS-PLAN-070: header + WP7 row marked **DELIVERED**; RF-070-13/17
  WP7 portions marked DELIVERED.
- `ACTIVE_TASKS.md`: WP7 entry moved to Recently Completed; parent stays
  In Progress for WP8-WP9. FEAT-UX-026: WP7 backend sub-item checked —
  item stays In Progress for the WP8 UI remainder.

## Appendix: Untracked-File Fingerprint (capture time)

```
docs/adr/ADR-017-family-catalog-and-redemption.md  28d3265b56ba92f2693c2817b47d76c0c22608d4f64d980f4556a47f2f98112e
docs/api/rewards.md  2d43d7428b5f1cdd523558a8f2bee461d1d75814b56b6e9876156db664c64aed
lib/Controller/RewardController.php  be7d4c14059b72aaa152cbff5d3817e94653c259a4a206de91f10f554caa47de
lib/Controller/RewardRequestController.php  49e25a50906d3cfad70ce768501440a0d5006e78c36942529f02a1ac7ffe0fe2
lib/Db/RewardItem.php  eca4b73148c4c644e784f6d569e673c7e89c4ba361d2235565e3f2e6f0bd1821
lib/Db/RewardItemMapper.php  0c04c6813a79eb68388a257c2da11187337caf748ecee92530ba394dc17b1d71
lib/Db/RewardRequest.php  9cd2c51e26418cfadb2d7827fdde910f5deba8c1882c9a632f022973beca418f
lib/Db/RewardRequestEvent.php  3822f3bbf9cd6429df0aafc06b0d3218bae891f48dc4dee6d540074340b9c4df
lib/Db/RewardRequestEventMapper.php  a9fcf681fd89498cd8a177bbcbdc03d367f5f302beaa02b5576cc98b0896d27a
lib/Db/RewardRequestMapper.php  7c7533f98c46d2e6c8919c491ddcfdcc0f5ef9cfff7ef4743b03b6aea741790c
lib/Migration/Version1033Date20260918000000.php  7d04a97b5dafd5f67b5a975350c4b69475a481221607ac1364581ac495dc47b4
lib/Service/RewardRequestService.php  7e5e47fd4415d72f4f341207e8c1b2a306ac5bac2c26f9d4dc652b6047439a56
lib/Service/RewardService.php  7cc42c87cf6d94079d465b5f3a47869aff7cd691ae543c3af47623f6bea61203
tests/e2e/family-reward-api.spec.ts  e35525c35aaeb0fb7509b62e2e312a09c7be3d867b2ac5673d54d1c423d0f0f8
tests/integration/Controller/RewardApiTest.php  7321cbd9d8cc147d57a2ed5fc4e0b777e6fb7ef200b25fff85321f581cadc198
tests/integration/Service/RewardConcurrencyTest.php  b3a86c1c9e5f03108edcb1a3ced3449d861d26fdb344c778e54710d3ec1fe0b5
tests/integration/Service/RewardLifecycleTest.php  44a6f02866803c441018e1ed5abaa39e4fe9450183f6fcd4b0ba634683eff08d
tests/unit/Controller/RewardControllerTest.php  dee59b373ca427b127b37ce49cade2a28b68645fc0093e50ea374d8c1c06b745
tests/unit/Controller/RewardRequestControllerTest.php  5051cc4e7e9ab419f6d388d6ffce706c7e02d37ccddb3e7b28212fe68bddb677
tests/unit/Service/RewardRequestServiceTest.php  27d8c878361c7e8f6fce08c17f7b623bb1af0ae0cfb45c8c41c1fdc6f7466aeb
tests/unit/Service/RewardServiceTest.php  662f1795aa57974fb45a96adf2f34119b22a8f3a83b99141ffa51085c943b0d8
```

Note: these are the per-file SHA256 hashes of the untracked NEW files on the
final reviewed tree (post-fix); the combined final-tree diff fingerprint above
covers the same state.

