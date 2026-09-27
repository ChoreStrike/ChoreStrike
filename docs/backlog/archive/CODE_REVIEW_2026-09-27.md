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
