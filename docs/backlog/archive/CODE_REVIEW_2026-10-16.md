# Code Review Record — 2026-10-16

## Standard A (Plan) — CS-PLAN-085 GitHub migration and identity rewrite

**Outcome:** `PASS-WITH-FIXES` · **Transition:** `new/ → active/`

### Scope

| Item | Value |
|---|---|
| Reviewed artifact | `docs/plans/new/cs-plan-085-github-migration-and-identity-rewrite.md` (untracked at review time) |
| Content hash at capture | `de23151490f33171d574accb7fbd16933cb9fde2f51ad0453715e3827235ecf4` |
| Branch | `master` |
| Base HEAD | `f90a270c3e77da00ac19d468ddad6409edd7c99b` |
| Index at capture | empty (staged diff = empty-tree hash) |
| Unstaged diff fingerprint | `af6d3ddf12041e7d7fefc69c6a66dfca239672d099fa60f93f4dd3d4ae29a201` |
| Preserved unrelated changes | CS-PLAN-084 round-11 edits in `docs/backlog/ACTIVE_TASKS.md` and `docs/plans/active/cs-plan-084-family-trial-ux-baseline.md` — untouched by this review |
| Owning backlog item | TECH-REPO-001 (moved to `In Progress`, `Owning: CS-PLAN-085`) |

### Findings (8, all fixed in-round)

| ID | Sev | Area | Issue | Status |
|----|-----|------|-------|--------|
| A-01 | Medium | §6 WP1/WP3 | Rehearsal clone was created before the WP2 scrub commit, so the rehearsed history would not have matched the real run | Fixed — clone creation moved into WP3-5 |
| A-02 | High | §1/§4/§8 | Baseline counts came from `git rev-list --all`, which includes `refs/stash` and the orphan tag line; "576 commits / 1 merge / 2 identities" was wrong. `master` is 560 commits, one identity, zero merges — the reported "merge" was the stash tip | Fixed — table corrected per ref; all expectations now reference `$BASE` measured at execution time |
| A-03 | High | §2/§4/§8 | The plan reproduced the legacy identity in tracked content, and the proposed guard spec would have matched its own pattern literals, so it could never pass | Fixed — new §2 rule, mailmap generated at execution time, guard patterns assembled from fragments |
| A-04 | Low | §5 | File Manifest archive path double-nested | Fixed — flattened |
| A-05 | High | §1/§6/§7 | 18 commit SHAs cited as qualification evidence in tracked active documents would dangle after the rewrite, leaving pre-migration release evidence unresolvable | Fixed — D-085-07: `commit-map` preserved as a tracked artifact, documented, active SHAs remapped |
| A-06 | Low | §2/§6 | Default branch unspecified while GitHub defaults to `main` and all four workflows trigger on `master` | Fixed — D-085-08: keep `master`, verified in WP7-2 |
| A-07 | High | §1/§6 | `v0.1.0` was treated as an ordinary tag on the main line; it actually anchors a disjoint second root (14 commits, pre-rename `OCA\ChoreWars`) that is the sole carrier of the second identity | Fixed — D-085-06: delete the tag; acceptance criteria now assert one root and an empty `refs/tags` |
| A-08 | High | §1/§4/§7/§8 | Found by verifying the revised plan against its own A-03 rule: the plan and the guard spec would have become the only tracked occurrences of the early personal contributor domain — a string that occurs nowhere in the repository today | Fixed — domain no longer written in tracked files; patterns assembled from fragments; plain value stays in `/private/` |

No finding was deferred, rescoped, or routed to the backlog. No security finding
arose (the plan changes no code, authorization path, or data flow).

### Key verification performed during the review

- Full object-database scan (`git rev-list --objects --all` → `git cat-file --batch`)
  for the early contributor domain token and the operator's personal local part:
  **0 hits each** across 576 reachable commits. The feared address was never
  committed.
- `git log -S` pickaxe passes over all refs for the same tokens: 0 hits.
- Per-ref accounting: `master` 560 commits / 1 identity / 0 merges / root `fcd67b2`;
  tag-orphan line 14 commits / second identity / disjoint root `2dca40e`;
  `refs/stash` 2 further commits. `--all` total 576 = 560 + 14 + 2.
- `v0.1.0` → `d1ef785` confirmed **not** an ancestor of `master`
  (`git merge-base --is-ancestor` negative; no common ancestor).
- Local `master` vs `origin/master`: 16 ahead, 0 behind.
- Signature survey: `%G?` = `N` for all 576 commits (no re-signing required).
- Which rewrite targets are on `master`: 5 of 6 message-hit commits, and both
  `fix-email.bat` commits.
- `node scripts/check-backlog.js` — green (34 scoped files link-checked).
- `node scripts/check-docs-encoding.js` — green (985 tracked files).
- All relative links in the plan resolved manually (17/17).
- Final literal-compliance sweep of the plan: 0 hits for the legacy username,
  the legacy noreply domain, `codeberg.org/<user>/`, the early contributor
  domain token and the personal local part.

### Gates not run, with reason

Jest, PHPUnit, the Playwright browser gate and `npm run build` were not run:
this round changed only Markdown planning and backlog documents — no code,
runtime artifact, string catalogue or adapter source. `npm run agents:check`
likewise does not apply, as `docs/standards/ai/` and `.claude/` are untouched.
The plan's own §4 gate list binds at implementation time.

### Decisions recorded

D-085-06 (delete the `v0.1.0` tag and let the orphan root drop out),
D-085-07 (preserve the `commit-map` as a tracked artifact and remap the 18
active evidence SHAs), D-085-08 (keep `master` as the default branch) — one
batched operator question round, recorded in the plan's §11.
