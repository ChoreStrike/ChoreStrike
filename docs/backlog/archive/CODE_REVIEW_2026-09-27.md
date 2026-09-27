# Code Review: CS-PLAN-074 WP3 — Final Standard B

- **Review Date:** 2026-09-27
- **Tier:** Standard B, final-review attempt, no subagents
- **Mode:** Full-package final review (manifest + acceptance criteria + §4 test matrix + durable-doc gate)
- **Outcome:** `NEEDS-FOLLOW-UP` (gates green, decisions answered, findings remain)
- **Owning Plan:** [CS-PLAN-074](../../plans/active/cs-plan-074-wp3-work-accounting-and-replay.md) (stays in `active/`)
- **Base:** `master` at `28755b6584d008db150ef76022e867930a7b3811`

## Summary

Final Standard B review of the complete uncommitted WP3 work-accounting-and-replay
increment (implementation rounds 1–2: schema/work-share/audit/journal persistence,
wallet-ledger cutover, targeted completion correction aggregates, room replay,
Q-074-01A rhythm/weekly/recognition closure slice, no-Coin arcade, client cutover).
The engineering quality of the delivered aggregates is high — the shared checklist
passes across the changed set and every gate is green — but the package is **not
complete against its own plan**: the durable-doc integration is entirely missing,
the attribution endpoint has no client path, five required §4 test files do not
exist, and the review found two correctness defects in the correction surface.
Five plan-owned findings (2 block, 3 major) plus three nits are recorded in the
plan's Review Findings checklist; the user selected one dedicated fix round before
the final re-review. The implementation package stays uncommitted; this record,
the plan updates and `ACTIVE_TASKS.md` form the evidence-only commit.

## Capture

- Index **empty** at capture (staged diff hash `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391`).
- Unstaged reviewed diff: 178 tracked paths (147 M / 31 D), `git diff --binary |
  git hash-object --stdin` = **`0edea0cf34d8a4f94173c4bde0c007f92cba635d`** (SHA1).
- Untracked scope: 18 paths, sorted, with per-file SHA256 content hashes:

```
19E173B6ED13034CF21F7521C0CBB3671621B42103CE4BE2ADD3206C32124BC6  lib/Controller/CompletionController.php
5DC825D3EDD5DA3A6B3827AF4E32BF4A95F1F33CB24F0FAC45CF394CAC20E796  lib/Db/CompletionRevision.php
64E8ABA6B61790ECED10E51F08DC280D127454FCBF64D44D44B755F62F9A7CA7  lib/Db/CompletionRevisionMapper.php
8FE930A7A2E92C011663B8D3DB96AF0239E6F6DAA0D1C900E05466D0E9D6CC6E  lib/Db/RoomEvent.php
0B23B2EB27A194DA4EF82FB24A23E6A7063E5C14744F14E37C9C62B310110170  lib/Db/RoomEventMapper.php
D135CB8BE84A0D4940F0C110F797812B3DFDA8E91E78F4FA27258C568A546515  lib/Db/WorkShare.php
15786725B9FEA5B1E7AF2EFB35B40C4EF759CE1F351062C1F9C618ACBDF1B992  lib/Db/WorkShareMapper.php
9EE71F4F59733340B0636CAA796CC1095B1D6EDA8CAB5930FEAC259E8E50D114  lib/Service/EffortAllocationService.php
B62BF01893A66336CF28E49F002692C0CDB6FF83337A1C62B5C5D93AD2DB8655  lib/Service/RhythmService.php
E54770B5D14C783C74FEE620DE03E28C5B369B80F80C59455BF56923AC1C1629  lib/Service/RoomReplayService.php
EFEA97C82E4C12FA55220B0D58030C1FF2878958773F936FE7148879E00F53C8  lib/Service/WeeklyGoalService.php
27B694B2B2639178FDBA7A4D481764C446E152EAAE608FCB4E6D52EF0B991D39  src/components/Common/ParticipationDialog.vue
B884F13A9CDD42FE41044E46A1C8E0DFA4A4F7FD90A9E880561699FF772E383D  src/utils/minorUnits.js
95172CA5F0D9F6AE97F689985EBCD0361E1885E524FFB8DDB092601C656B6816  tests/unit/components/ParticipationDialog.spec.js
FADE975E5CB7CCBDC75642AC623CEAD185F81FDE60D209957F06F531EA91F131  tests/unit/Controller/CompletionControllerTest.php
4ACC7501CF4324AA4143381576FF399DE0E633E5C2812F69990AF1F7B65E20FE  tests/unit/Service/EffortAllocationServiceTest.php
81132E05EA9B2C429D042FCA93B14D79C6C2FAD689DF2030B20A082B85D059FE  tests/unit/Service/RhythmServiceTest.php
FF6E29A94272EF2FAD0EC3075CE3A5363CBA0952342FAEE8AEBD418573078654  tests/unit/Service/WeeklyGoalServiceTest.php
```

- The three pre-existing `docs/plans/{active,new,onhold}/.gitkeep` deletions were
  excluded and left unstaged, as in every prior round.
- Review-owned deltas on top of the captured state (the only intended drift):
  this record, the plan's Review Findings/Review Outcome/header updates, and the
  `ACTIVE_TASKS.md` entry. Drift check before staging: HEAD/branch/index
  unchanged; the 18 untracked hashes unchanged; no other path touched.
- No `--no-commit` override was supplied; the scoped evidence-only commit is the
  `NEEDS-FOLLOW-UP` outcome's authorized commit.

## Findings

All findings are plan-owned (Finding Routing step 2); none reach `backlog.md`.
The actionable open checklist with fix directions lives in the plan's
`### Review Findings (final Standard B 2026-09-27)` section.

| ID | Severity | Source | Finding | State |
|---|---|---|---|---|
| RF-074-WP3-01 | block | `docs/` tree | Durable-doc integration missing: ADR-014 (N path) absent; no API/engineering/concepts/ADR/gamification-skill updates; Size Guideline Exception Inventory stale both ways (`CompletionService` 730→~1418, `GameLogicService` 349→54, `DailyCapacityService` 517→529) | Open |
| RF-074-WP3-02 | block | `src/` (no `/attribution` caller), §3.7/§3.6 contract | Attribution unreachable from the client: no store/component calls `PUT /completions/{id}/attribution`, `canCorrect` unconsumed, ParticipationDialog not reused for correction; §4 "owner attribution" browser evidence impossible | Open |
| RF-074-WP3-03 | major | §4 matrix | Required N test files missing (`CompletionCorrectionTest`, `RoomReplayServiceTest`, `RoomReplayConcurrencyTest`, `RhythmProjectionTest`, `minorUnits.spec.js`); attribution + rhythm/weekly recognition have zero integration coverage; `minorUnits` boundary formatting untested; §4-listed `HouseMemberRemovalIntegrationTest`, `workflow-state-reconciliation.spec.ts`, fixture rows unmodified | Open |
| RF-074-WP3-04 | major | `CompletionService.php:832,851-891` | Attribution union-delta skips departed participants: previous shares are mapped via CURRENT `house_users` rows, but departure deletes the row — the departed wallet keeps its credit while the new 100% allocation pays out again (completion pays out more than the frozen total); `undoAggregate` keys deltas by share-row wallet id correctly | Open; source-confirmed |
| RF-074-WP3-05 | major | `CompletionService.php:1330` | `GET /completions/{id}` 500s when an audit actor departed: uncaught `DoesNotExistException` from `houseUserMapper->find()` in the revision-history read model (the undo/attribute paths already guard the same lookup) | Open; source-confirmed |
| RF-074-WP3-06 | nit | `CompletionService.php:838-841,52-56` | Dead empty `if` branch; class-docblock lock order contradicts the implemented order (room before memberships/wallets) | Open |
| RF-074-WP3-07 | nit | `RhythmService.php:104-113` | Redundant if/elseif — both branches assign the recomputed longest streak | Open |
| RF-074-WP3-08 | nit | `CompletionController.php:140-216` | `attribute()` duplicates the closed-payload validation inline instead of sharing `validateCorrectionPayload` | Open |

## Verified (no findings)

- **Routes/API:** targeted completion routes (`GET /completions/{id}`, undo,
  attribution) added; the latest-by-room-task undo route removed without a
  compatibility alias; boost catalog/purchase and Coin balance routes removed;
  `RouteMatrixTest` pins the matrix; StreakFreeze/StreakRestock jobs removed
  from `info.xml` with the rationale documented.
- **Controllers:** thin, closed payloads with unknown-field rejection, UUID/
  version/reason validation, `@NoAdminRequired` only, membership assertion,
  shared error formatter, no secrets/stack traces.
- **Aggregates:** CommandService receipts with terminal-before-version handling
  and authorization before replay; house-first lock order (room task → room →
  memberships → wallets, stable id order, participant-less binding ensured);
  exact union-delta attribution math; unique `(wallet, source, revision)` entry
  identities; house state version bump; no snapshot restore anywhere;
  recognition re-derived from valid history (Q-074-01A — no guarded numeric
  reversal); room journal with baseline/completion/manual/reset kinds, clamps,
  requested-delta semantics and `last_cleaned` timestamp rules; capacity seam
  on selected valid work shares (completer-only `findEnergyUsageBetweenByHouse`
  deleted); erasure/departure sweeps extended.
- **Schema:** in-place Version1030 extension per the fresh-install rule with the
  §3.1 invariants (selected-revision uniqueness, audit revision identity,
  journal `(room_id, effective_at, id)` index + stable-id uniqueness).
- **Manifest:** every delivered N source path exists; R paths retired with
  pinned supported-Snake/no-Coin replacements; 29 retired sources preserved
  under the gitignored `archive/2026-09/cs-plan-074-retired/`.
- **Security sweep:** no `@PublicPage`, no `v-html`, no raw `console.*` outside
  `logger.js`, parameterized SQL (the one raw `FOR UPDATE` statement binds its
  parameter), no token/PII logging, i18n `t()` coverage with 987-key exact
  EN/DE/POT.

## Decisions (batched structured question)

1. **E2E evidence** — user selected the recommended option: accept the
   mtime-verified passing gate run `run-qUhkY2` (2026-09-27 01:50:48; all three
   pools `processExit: 0`, zero violations; verified that no reviewed file
   changed after the run). Same approach as the WP2 final review.
2. **Fix-round organization** — user selected the recommended option: all
   findings RF-074-WP3-01..08 go to one dedicated fix round; this review ends
   `NEEDS-FOLLOW-UP` with the evidence-only commit; the final re-review runs
   after the fix round.

## Gates (rerun by this review on the reviewed source)

| Gate | Result |
|---|---|
| Docker PHPUnit unit `--fail-on-skipped` | OK — 935 tests / 2882 assertions, zero skip |
| Docker PHPUnit integration `--fail-on-skipped` | OK — 430 tests / 2143 assertions, zero skip |
| `composer cs:check` | 0 of 350 fixable |
| Jest `npm test -- --runInBand` | 1015 tests / 95 suites green |
| `npm run test:noise` | clean |
| `npm run lint` / `stylelint` | clean |
| `npm run l10n:check-keys` | 987 keys, EN/DE/POT exact |
| `npm run backlog:check` | green (27 files) |
| `npm run docs:encoding` | 791 files clean |
| `git diff --check` | clean |
| `npm run build` | webpack compiled successfully |
| `npm run test:e2e:gate` | accepted on mtime-verified run `run-qUhkY2` per decision 1 |

## Transition

None. The plan stays in `active/`; CR-SVC-63 stays open (In Progress, this plan
owning) because its complete remaining contract is not fully proven while
RF-074-WP3-03's room-replay evidence is open; the implementation package stays
uncommitted. Next: the fix round (RF-074-WP3-01..08), then the final re-review.

---

# Final Standard B re-review — PASS-WITH-FIXES

- **Review Date:** 2026-09-27
- **Tier:** Standard B, final re-review over the complete plan, no subagents
- **Mode:** Full-package final review (manifest + acceptance criteria + §4 test
  matrix + durable-doc gate + implementation-history verification)
- **Outcome:** `PASS-WITH-FIXES` (one integration pin delivered in-round per
  user decision; doc drift auto-fixed; all gates green)
- **Owning Plan:** [CS-PLAN-074](../../plans/done/cs-plan-074-wp3-work-accounting-and-replay.md)
  (moved to `done/` with this review)
- **Base:** `master` at `631f0221a288a7d728e27f72baa6a839adf37441`

## Summary

Final re-review of the complete WP3 work-accounting-and-replay increment:
implementation rounds 1–2, the dedicated RF-074-WP3-01..08 fix round and the
23-finding read-only follow-up fix round. The whole owning plan was
re-verified — §5 manifest, §4 test matrix, all sixteen acceptance criteria,
durable-doc integration and the fix-round resolution claims in source. Two
findings resulted: RF-074-WP3-09 (major, §4 capacity-seam mapper evidence
deleted without replacement) resolved in-round with the user-selected
integration pin `tests/integration/Db/WorkShareMapperEnergyTest.php`, and
RF-074-WP3-10 (nit, doc drift) auto-fixed. No finding remains open. The plan
moved `active/ → done/`; CR-SVC-63 closed; the complete implementation package
lands with the scoped final-review commit.

## Capture

- Index **empty** at capture (staged diff hash `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391`).
- Unstaged reviewed diff: 217 tracked paths (186 M / 31 D), `git diff --binary |
  git hash-object --stdin` = **`4ed47d4270f1d664479d666c6ce9c5ea1333fef4`** (SHA1).
- Untracked scope: 26 paths, sorted, with per-file SHA256 content hashes:

```
96831EEF47562DAFADB10CC1F2976382CA4FA3ADBA9332349E87A192A20430BB  docs/adr/ADR-014-work-accounting-ledger-and-replay.md
995AA434CDED554B700E7CFEC98F0438B58CFD91F20544DD268D61EF6D75AA3C  lib/Controller/CompletionController.php
5DC825D3EDD5DA3A6B3827AF4E32BF4A95F1F33CB24F0FAC45CF394CAC20E796  lib/Db/CompletionRevision.php
64E8ABA6B61790ECED10E51F08DC280D127454FCBF64D44D44B755F62F9A7CA7  lib/Db/CompletionRevisionMapper.php
8FE930A7A2E92C011663B8D3DB96AF0239E6F6DAA0D1C900E05466D0E9D6CC6E  lib/Db/RoomEvent.php
1A42862F0915759001C52D4D5E462C973C9FA641F1CB94192F45F121AB4D101F  lib/Db/RoomEventMapper.php
26978B8C44B50AC1891E64A09C5C3F250904ACD65BC3FED39A76BCA9B773155D  lib/Db/WeeklyGoalReceiptMapper.php
D135CB8BE84A0D4940F0C110F797812B3DFDA8E91E78F4FA27258C568A546515  lib/Db/WorkShare.php
746FA4FF30E907F77C918D4EF7F45895B83A60798957BFF61AC2739D769FB7E3  lib/Db/WorkShareMapper.php
9EE71F4F59733340B0636CAA796CC1095B1D6EDA8CAB5930FEAC259E8E50D114  lib/Service/EffortAllocationService.php
E4949258337A6938A3BEA999120129BC13B98A0AF9A4E6F654F43CD93FB5682C  lib/Service/RhythmService.php
BC405D96F37CB00C923083BB90FC087A4ADB40761A483773CA8E441ED9D413AC  lib/Service/RoomReplayService.php
31FBF7507FB1C2FFF86764F876F54C248FD1CE668B3CA008825B95EDAAD58E97  lib/Service/WeeklyGoalService.php
129E6BBFE624055773956CAE2F64AED713506BFB7E1FA9CA54E56DB01D2B1880  src/components/Common/ParticipationDialog.vue
F4D7E19316786E0708B730E3C245B9C816EC35894E76F5F083138C3222C6E0DC  src/utils/minorUnits.js
86AA248FBA9E9BE91866A4492AD313F789C979690816E5F8D7C02490261DBD49  tests/integration/Service/CompletionCorrectionTest.php
F7D76092980668C08499EAB062C284AAA607C27EB0A255C47362231F3B6B9BE8  tests/integration/Service/RhythmProjectionTest.php
FD15BA174CC4DBB71CB491CBB6B8634184931B9C494F2467AA9E0C9884B2DCA6  tests/integration/Service/RoomReplayConcurrencyTest.php
2B25EBD615F9F5B6CFCCE338F5ABF6A10A0B84CA418B599BD62F001A401791C4  tests/integration/Service/WeeklyGoalClosureTest.php
FADE975E5CB7CCBDC75642AC623CEAD185F81FDE60D209957F06F531EA91F131  tests/unit/Controller/CompletionControllerTest.php
4ACC7501CF4324AA4143381576FF399DE0E633E5C2812F69990AF1F7B65E20FE  tests/unit/Service/EffortAllocationServiceTest.php
472A5C2E4C72C739D7A91CDCC091972C2238E039B165E5A165E39E903E58DF8A  tests/unit/Service/RhythmServiceTest.php
8C1ED871483E7DC81CBC86267D6C5B6991FA73800EB86113339CE2AC84017450  tests/unit/Service/RoomReplayServiceTest.php
329584FCA2ED58321DAB18F04EDD76906EE49ECE2C68E7FA5B4C9130CFE9992A  tests/unit/Service/WeeklyGoalServiceTest.php
07083A14E5D62F1C6A2338AF549873DC7E5A37FBE60D23D2D88943A96D3E9C8A  tests/unit/components/ParticipationDialog.spec.js
E8C6FA105CCEC3542072DCB4F63474D5B85F85D3676C641D49E3F7D0541F3A57  tests/unit/utils/minorUnits.spec.js
```

- The three pre-existing `docs/plans/{active,new,onhold}/.gitkeep` deletions were
  excluded and left unstaged, as in every prior round.
- Review-owned deltas on top of the captured state (the only intended drift):
  the in-round fixes (RF-074-WP3-09 integration pin, RF-074-WP3-10 doc repairs),
  this record, the plan's findings/outcome/header/§4-matrix updates, the plan
  move `active/ → done/`, the parent/backlog/archive/README/ACTIVE_TASKS
  transition updates, and the ADR-014 plan-link repair.

## Verification (whole plan)

- **§5 manifest:** every delivered N source path exists (incl. the follow-up
  round's `WeeklyGoalReceiptMapper`); R paths retired with pinned
  supported-Snake/no-Coin replacements; 29 retired sources preserved under the
  gitignored `archive/2026-09/cs-plan-074-retired/`.
- **§4 test matrix:** all required N files present
  (`CompletionCorrectionTest` 6, `RoomReplayServiceTest` 11,
  `RoomReplayConcurrencyTest` 5, `RhythmProjectionTest` 5, `minorUnits.spec.js`
  13, plus `WeeklyGoalClosureTest`); the two deviations carry explicit mapping
  notes (capacity-seam row → the new `WorkShareMapperEnergyTest`;
  `RoleGuardApiTest` unchanged by design — completion-route authorization is
  pinned in `CompletionControllerTest`/`CompletionCorrectionTest`/E2E).
- **Acceptance criteria:** all sixteen verified against source and gate
  evidence and checked off in the plan.
- **RF-074-WP3-01..08 resolutions re-verified in source:** wallet-keyed
  attribution union (`previousByWallet`, departed wallets locked and reversed),
  the real deviation-review gate (`sameAllocation` → 409), `showCompletion`
  departed-actor guard with the localized placeholder, the client attribution
  path (`roomTasks.fetchCompletion`/`attributeCompletion` → viewer-scoped
  `canCorrect` → `ActivityFeed` action → `InsightsView` correction session →
  `ParticipationDialog` correction mode), shared `validateCorrectionPayload`,
  collapsed rhythm branch, corrected docblock lock order,
  `COIN_MINOR_PER_UNIT = 100`, `wallet_entries.delta_minor` schema default.
- **Follow-up round spot-checks:** viewer-scoped `canCorrect`/redaction with
  batched share reads in `ActivityService`; house-first re-authorization in
  `MinigameService::saveScore`; unique `(house_id, week_start)` weekly-goal
  receipt (Version1023); erasure anonymization of the new share/revision/entry
  actor and wallet links; manual adjust routed through journaled
  `room.manual_delta` commands.
- **Durable docs:** ADR-014 delivered and indexed (WP3 accounting accepted,
  WP7 redemption explicitly pending); ADR-004/011/012/013 status sections
  annotated; `docs/api/README.md`, `docs/engineering/{architecture,testing}.md`,
  the concepts files, the gamification skill and `release-test-plan.md`
  updated; Size Guideline Exception Inventory re-measured (see RF-074-WP3-10).
- **Stale-reference audit:** live docs carry no open-CR-SVC-63 references; the
  remaining mentions are dated historical records (ADR bodies, dated plan
  sections, the WP0 audit table, review archives) preserved verbatim by policy.

## Findings

| ID | Severity | Source | Finding | State |
|---|---|---|---|---|
| RF-074-WP3-09 | major | §4 capacity-seam row, `WorkShareMapper::findSelectedSharesBetweenByHouse` | §4-named `CompletedTaskMapperEnergyTest` deleted with the completer-only query; the new selected-share capacity query had no real-DB pin while §7 requires mapper-level selected-share tests; §4-listed `RoleGuardApiTest` unmodified | **Resolved in-round** (user-selected fix): N `WorkShareMapperEnergyTest` (2 tests / 18 assertions — selected revision, valid window, undo exclusion, `evaluateDays` completed/planned split) + §4 mapping notes |
| RF-074-WP3-10 | nit | `docs/` | ADR-014 broken link (`cs-plan-071-wp0-schema.md`) + missing WP7-redemption-pending note; delivered ADR name vs reserved placeholder misaligned in parent/§5; size inventory stale (8 rows) and missing the two newly crossing WP3 files (`MedalService` 331, `RoomService` 328) | **Resolved in-round** (safe auto-fixes) |

## Decisions (batched structured question)

1. **E2E evidence** — user selected the recommended option: accept the
   mtime-verified passing gate run `run-UK5bLd` (2026-09-27 15:16:20 local;
   all three pools `processExit: 0`, zero violations, 95+124+39 tests;
   verified that only review/tracking docs changed after the run — no
   E2E-relevant source). Same approach as the WP2/WP3 prior final reviews.
2. **RF-074-WP3-09** — user selected the recommended option: deliver the
   capacity-seam integration pin in this review round (with the §4 mapping
   note for `RoleGuardApiTest`).

## Gates (rerun by this review on the reviewed source)

| Gate | Result |
|---|---|
| Docker PHPUnit unit `--fail-on-skipped` | OK — 981 tests / 3077 assertions, zero skip |
| Docker PHPUnit integration `--fail-on-skipped` | OK — 467 tests / 2402 assertions, zero skip (incl. the new seam pin) |
| `composer cs:check` | 0 of 357 fixable |
| Jest `npm test -- --runInBand` | 1054 tests / 96 suites green |
| `npm run test:noise` | clean |
| `npm run lint` / `stylelint` | clean |
| `npm run l10n:check-keys` | 1003 keys, EN/DE/POT exact |
| `npm run backlog:check` | green (27 files) |
| `npm run docs:encoding` | 792 files clean |
| `git diff --check` | clean |
| `npm run build` | webpack compiled successfully |
| `npm run test:e2e:gate` | accepted on mtime-verified run `run-UK5bLd` per decision 1 |

## Transition

Plan moved `active/ → done/`; CR-SVC-63 closed to
[`2026-09.md`](2026-09.md) (its complete journal/replay remainder passes);
CONCEPT-001 stays open (WP4+ remain); the parent's RF-070-07/08 are resolved
and the WP3 portions of RF-070-09/13/15/16/17 annotated; parent header/child/
WP-table rows, plans/docs READMEs, `ACTIVE_TASKS.md` and the ADR-014 plan link
updated. The complete implementation package (implementation rounds 1–2 + both
fix rounds + this review's in-round fixes and transition files) lands with the
scoped final-review commit.

---

# CS-PLAN-075 WP4 — Standard A Plan Activation

- **Date/tier/mode:** 2026-09-27; Standard A, Standard Batch Review (no subagents).
- **Scope/owner:** [CS-PLAN-075](../../plans/active/cs-plan-075-wp4-rhythm-goals-and-ranking.md),
  its pre-existing drafting deltas in the parent, plans/docs indexes, backlog and
  `ACTIVE_TASKS.md`. `master` at `b3ff6dab8e290d2d2c73690b4d143c2c3aa2b977`;
  index empty, staged binary diff empty. The original untracked plan had SHA256
  `6B5E0383C7744D989F4619D7C3661F949F5B58514C4AC2B5FE079D521915D13D`;
  the tracked drafting pointer binary diff SHA1 was
  `ea51a4b51a4137625d8f605063045d7891e1ab6b`.
- **Outcome:** `PASS-WITH-FIXES`. Checked parent C1/C2/C3/C5 and D3/D5/D7/D10,
  delivered WP3 evaluator/recognition baseline, reserved Version1032/ADR-015,
  one active parent (no competing child), backlog ownership, per-step validation,
  acceptance and future PHPUnit/Jest/Playwright evidence. Manifest nits fixed:
  section 4 listed nonexistent `tests/unit/components/Leaderboard.spec.js` as M;
  the existing `tests/unit/views/Leaderboard.spec.js` now owns the pin, and
  `TitlePicker.vue` is R rather than M. GET-purity assertion scoped to initialized
  house. No open finding.
- **Decisions:** In one structured batch the user selected Q-075-01 A (real
  membership-bound ranking consent in Version1032 in WP4, WP5 extends in place;
  5-9-day estimate, prior WP5-first ordering superseded) and Q-075-02 A (WP4
  retires `selected_title_pack`, picker and selection route, retaining a slim
  first-earned marker). Recorded in the child and parent; neither change has
  been implemented yet.
- **Gates:** documentation/link/encoding/backlog and whitespace checks after
  finalization; no runtime code changed and section 4 implementation gates
  remain due at delivery.
- **Transition/tracking:** `new/ → active/`, indexes and live registry updated;
  CONCEPT-001 remains In Progress, CR-053-11 remains open for independent Snake
  leaderboard ordering. The three previously deleted `.gitkeep` files remain
  untouched, unstaged and outside this commit.
