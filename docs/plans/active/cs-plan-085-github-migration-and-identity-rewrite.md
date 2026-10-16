# CS-PLAN-085: GitHub Migration, Identity Rewrite and Public Baseline Polish

> **Status:** Active (Standard A `PASS-WITH-FIXES` 2026-10-16). WP0-1 backup and WP0-2 organization/repository confirmed 2026-10-16 (§10); remaining prerequisite is WP0-4, a clean working tree. No ref change or rewrite performed yet.
> **Effort:** M
> **Priority:** P2
> **Depends on:** operator 7-Zip backup (WP0-1), GitHub organization `ChoreStrike` (WP0-2), clean working tree (WP0-4)
> **Related backlog items:** [TECH-REPO-001](../../backlog/backlog.md) (owning) · BUG-SEC-001 (reporting-contact links only, not resolved here)

## 1. Problem Summary

The repository is hosted on Codeberg under a pseudonymous account and its
history carries a legacy forge identity. The operator has decided to move to
GitHub under the organization `ChoreStrike`, to consolidate the history onto
one identity, and to make the public entry page presentable, because this
tree becomes the baseline for operator server testing.

### Measured starting state (2026-10-16, HEAD `f90a270`)

| Fact | Value |
|---|---|
| `origin` | the Codeberg repository of the legacy account |
| Commits on `master` | **560** |
| Distinct author/committer identities on `master` | **1** — the legacy forge noreply identity |
| Real merge commits on `master` | **0** |
| `master` root | `fcd67b2` (2026-02-24) |
| Local `master` vs `origin/master` | 16 ahead, 0 behind (fast-forward, nothing unpushed is at risk) |
| Annotated tags | 1 (`v0.1.0` → `d1ef785`) — **not an ancestor of `master`** |
| Orphan line reachable only from that tag | **14** commits (2026-02-24), second identity, **disjoint second root** `2dca40e` |
| `refs/stash` | 2 stash commits not on `master` (62 reachable in total) |
| `git rev-list --all --count` | 576 = 560 master + 14 tag-orphan + 2 stash |
| GPG-signed commits | 0 (`%G?` = `N` throughout) |
| Local `user.*` override | the legacy identity |
| Global `user.*` | already `volker-ma` / `220411049+volker-ma@users.noreply.github.com` |

Two facts here were corrected during the Standard A review and drive the
plan (A-02, A-07 in §9): the `576` total and the apparent "1 merge commit" are
artifacts of `git rev-list --all` reading `refs/stash` — the stash tip is
itself a merge commit. The **actual** `master` line is 560 commits with a
**single** identity and no merges.

The `v0.1.0` tag anchors a **fully disjoint second root**: the abandoned
pre-rename prototype line (`OCA\ChoreWars`, table prefix `chorewars_`) from
2026-02-24, which shares no ancestor with `master`. That orphan line is the
**only** place the second identity exists — including as the tag's tagger.
Per D-085-06 the tag is deleted, which removes those 14 commits and the entire
second identity from the migration without rewriting them.

### Verified absence of the feared contributor address

The operator reported an early personal contributor address (a private `.de`
domain) as a possible leftover. A full object-database scan
(`git rev-list --objects --all` piped through `git cat-file --batch`) plus
`git log -S` pickaxe passes over all refs found **zero** occurrences of that
domain's distinctive token and **zero** occurrences of the operator's personal
local part in any blob, commit message, or tag object. That address was never
committed to this repository. No history surgery is required for it; WP2 adds a
permanent guard so it cannot appear later.

The literal domain is deliberately **not** written here. Writing it into a
tracked plan would make this file the only public occurrence of the very string
the migration exists to keep out (A-08). The guard spec assembles its patterns
from fragments at runtime for the same reason, so no forbidden literal exists
anywhere in the tree; the operator keeps the plain value in `/private/` if a
record is needed ([`project-rules.md`](../../standards/ai/rules/project-rules.md) §6).

### Residual legacy-identity material on `master` (what this plan removes)

1. **Commit metadata** — all 560 `master` commits (author and committer) on the
   legacy forge identity.
2. **`fix-email.bat`** — a leftover `filter-branch` helper from an earlier
   private rewrite, added in `4f34561` and deleted in `1f6eb84`, **both on
   `master`**; its blob still quotes the legacy forge address. Each of those
   commits changes many other files, so removing the path prunes no commit.
3. **5 commit messages on `master`** naming the legacy username or the old
   repository URL: `8dd4506`, `1f6eb84`, `0d3ab15`, `fa18216`, `7c4a47e`.
   (A sixth hit, `d1ef785`, is the tag-orphan twin of `7c4a47e` and leaves
   with the tag.)
4. **13 tracked files** with Codeberg URLs containing the legacy username:
   [`appinfo/info.xml`](../../../appinfo/info.xml) (3 tags),
   [`docs/CONTRIBUTING.md`](../../CONTRIBUTING.md),
   [`docs/engineering/deployment.md`](../../engineering/deployment.md),
   [`docs/engineering/development.md`](../../engineering/development.md),
   [`docs/engineering/testing.md`](../../engineering/testing.md),
   [`docs/engineering/architecture.md`](../../engineering/architecture.md),
   [`docs/standards/guidelines/design.md`](../../standards/guidelines/design.md),
   [`docs/standards/guidelines/security.md`](../../standards/guidelines/security.md),
   [`CHANGELOG.md`](../../../CHANGELOG.md),
   [`docs/backlog/backlog.md`](../../backlog/backlog.md),
   [`docs/backlog/archive/2026-07.md`](../../backlog/archive/2026-07.md),
   [`docs/plans/done/cs-plan-081-pre-wp9-hardening-and-retained-data-readiness.md`](../done/cs-plan-081-pre-wp9-hardening-and-retained-data-readiness.md),
   and the four workflows under `.forgejo/workflows/`.
5. **18 commit SHAs cited as qualification evidence** in tracked active
   documents ([`ACTIVE_TASKS.md`](../../backlog/ACTIVE_TASKS.md) and the active
   plans), e.g. package-smoke and browser-gate rows. The rewrite changes every
   hash, so these references would dangle (A-05; resolved by D-085-07).

### Public-presentation gaps

- [`README.md`](../../../README.md) has one badge, no CI/version/platform
  badges, no screenshots, no quick-install section above the concept text,
  and no repository links.
- `package.json` has no `repository`, `bugs` or `homepage` fields.
- `.github/` has issue templates and a PR template but no workflows, no
  `CODEOWNERS` and no `dependabot.yml`.
- No GitHub organization profile page exists yet.
- [`design.md`](../../standards/guidelines/design.md) specifies a "Codeberg
  Repo Avatar" asset size; the only brand asset in the tree is
  `img/app.svg` (690 bytes).

## 2. Strategy

Nine ordered work packages in three phases:

**Phase 1 — prepare (WP0-WP2).** Operator backup and organization first;
then scrub every public reference in the working tree and commit it. Scrubbing
*before* the rewrite means the single rewrite pass also covers the scrub
commit, so exactly one history change and one force-push happen.

**Phase 2 — rewrite and verify (WP3-WP4).** Reduce the ref graph first (delete
the orphan tag, clear the stash), then one `git filter-repo` pass does identity
mapping, message replacement and `fix-email.bat` removal together, rehearsed on
a clone taken **after** the WP2 commit. Verification is a documented zero-hit
evidence block, not an assumption.

**Phase 3 — publish (WP5-WP8).** GitHub polish and CI port, then push, verify
on the remote, retire Codeberg, and integrate the durable decision.

### Target identity (single, for all commits and committers)

```
volker-ma <220411049+volker-ma@users.noreply.github.com>
```

`Co-Authored-By: Claude …` trailers stay — they are accurate attribution, not
operator identity.

### Identity literals are never written into tracked files

The legacy username and addresses are **not** reproduced in this plan, the
review record, or any other tracked file (A-03). The mailmap is **generated at
execution time** from the repository itself (WP3-3), so the only places the old
literals ever appear are untracked scratchpad files. Without this rule the plan
would reintroduce exactly the strings it exists to remove, and the WP2 guard
spec would fail against the plan file.

### Documented naming exception

The word **"Codeberg"** may remain where it records factual provenance: the
migration commit message, the `CHANGELOG.md` entry, the new ADR, and existing
historical records under `docs/backlog/archive/` and `docs/plans/done/`.
What is removed **everywhere, without exception** is the personal identity: the
legacy username token, the legacy noreply addresses, and every
`codeberg.org/<user>/…` URL path. WP2's guard enforces exactly this split.

### Out of scope

No `lib/`, `src/`, database, migration or runtime change. The data-retention
boundary ([`project-rules.md`](../../standards/ai/rules/project-rules.md) §1)
is untouched: no schema step, no reseed, no production access, no deployment
and no retained-baseline adoption happen in this plan. BUG-SEC-001's mailbox
verification stays open; only its links are repointed. The default branch stays
`master` (D-085-08).

## 3. Backend / Frontend Changes

- **Backend** (`lib/`): none.
- **Frontend** (`src/`): none.
- **Metadata, docs and tooling only:** `appinfo/info.xml` (website/bugs/
  repository), `package.json` (repository/bugs/homepage), `README.md`,
  `CHANGELOG.md`, `docs/**`, `.github/**` (new workflows, `CODEOWNERS`,
  `dependabot.yml`), `.forgejo/**` → `archive/2026-10/forgejo-workflows/`.
- **Cross-layer contracts:** none affected. The only runtime-adjacent file is
  `appinfo/info.xml`; its changed tags are metadata Nextcloud reads at app
  registration, which WP2's backend run covers.

## 4. Tests

### Unit (Jest)

- **New static guard** `tests/unit/repo-metadata.spec.js`, following the
  established source-reading guard pattern of
  `tests/unit/css/theme-consistency.spec.js` and `tests/unit/scripts/*`:
  over all git-tracked text files, assert **zero** matches for the legacy
  username token, the early personal contributor domain, the legacy noreply
  addresses and `/codeberg\.org\/[\w.-]+\//i`; and assert that
  `appinfo/info.xml`, `package.json`, `README.md` and `docs/CONTRIBUTING.md`
  each point at `https://github.com/ChoreStrike/ChoreStrike`.
  - The forbidden patterns are **assembled from fragments at runtime** (e.g.
    joining two halves of each token) so the spec file itself contains no
    forbidden literal (A-03b, A-08). Without that, the spec either matches
    itself and can never pass, or it becomes the one tracked file that
    reintroduces the strings. The docblock states this and why.
  - The spec additionally excludes its own path, as cheap belt-and-braces
    should a future edit inline a literal.
  - The bare word `Codeberg` is explicitly allowed (§2 exception) and must stay
    allowed; the docblock records why, so a later contributor does not
    "tighten" the guard into failing on historical records.
- Existing Jest suite stays green (1780 at `f90a270`).

### Integration / backend (PHPUnit, Docker)

- Backend unit + integration on the canonical row once after WP2, because
  `appinfo/info.xml` changes app-registration metadata (expected 1385 / 613
  at `f90a270`). No new backend test is warranted for metadata-only edits.
- No E2E change. The browser gate is **not** re-run for this plan: no `src/`,
  route, or template change touches it. This is recorded as a deliberate scope
  decision, not a deferral.

### Verification evidence (WP4, required, blocking)

`$BASE` is the `master` commit count measured in WP1-1 **after** WP0-4 and WP2
land (560 at review time, plus the CS-PLAN-084 commit, the Standard A review
commit and the WP2 scrub commit). The expectation is "unchanged by the
rewrite", never a hardcoded literal (A-02).

```
git log --all --format='%an <%ae>|%cn <%ce>' | sort -u   # exactly 1 line, the new identity
git rev-list --all --count                               # == $BASE
git log --all --merges --oneline | wc -l                 # 0
git for-each-ref refs/tags                               # empty (orphan tag deleted, D-085-06)
git rev-list --max-parents=0 --all                       # exactly 1 root
git rev-list --objects --all | awk '{print $1}' | git cat-file --batch | grep -aic <early-domain-token>   # 0
git rev-list --objects --all | awk '{print $1}' | git cat-file --batch | grep -aic <legacy-user>   # 0
git log --all --format=%B | grep -ic <legacy-user>       # 0
git log --all --oneline -- fix-email.bat                 # empty
git log --all --format='%ad' --date=short | sort | head -1   # 2026-02-24 (dates preserved)
```

`<legacy-user>` is substituted at execution time from the WP1-1 measurement and
never written into a tracked file (§2).

### Gates

`npm test` · `npm run lint` · `npm run stylelint` · `npm run backlog:check` ·
`npm run docs:encoding` · `npm run l10n:check-keys` · `npm run build` ·
`npm run agents:check` · backend unit + integration in Docker (canonical row).

## 5. File Manifest

```
new:      docs/plans/active/cs-plan-085-github-migration-and-identity-rewrite.md
new:      docs/backlog/archive/CODE_REVIEW_2026-10-16.md
new:      tests/unit/repo-metadata.spec.js
new:      docs/adr/ADR-018-repository-hosting-and-contributor-identity.md
new:      docs/release/history-migration-2026-10-commit-map.tsv
new:      .github/workflows/test.yml
new:      .github/workflows/full-matrix.yml
new:      .github/workflows/release-gate.yml
new:      .github/workflows/runtime-check.yml
new:      .github/CODEOWNERS
new:      .github/dependabot.yml
modified: README.md
modified: CHANGELOG.md
modified: package.json
modified: appinfo/info.xml
modified: docs/CONTRIBUTING.md
modified: docs/SECURITY.md
modified: docs/engineering/deployment.md
modified: docs/engineering/development.md
modified: docs/engineering/testing.md
modified: docs/engineering/architecture.md
modified: docs/standards/guidelines/design.md
modified: docs/standards/guidelines/security.md
modified: docs/backlog/backlog.md
modified: docs/backlog/ACTIVE_TASKS.md
modified: docs/backlog/archive/2026-07.md
modified: docs/plans/active/*.md                 (WP8-6 SHA remap only)
modified: docs/plans/done/cs-plan-081-pre-wp9-hardening-and-retained-data-readiness.md
modified: docs/adr/README.md
archived: archive/2026-10/forgejo-workflows/{test,full-matrix,release-gate,runtime-check}.yml
deleted:  tag v0.1.0 (and with it the unreachable 14-commit orphan root)
removed:  fix-email.bat  (from all history, WP3 — never present in the working tree)
scratch:  <scratchpad>/mailmap.txt, <scratchpad>/replace-message.txt, <scratchpad>/clone-rehearsal.git
```

Operator-owned, outside the repository: the 7-Zip backup, the GitHub
organization, the organization profile README (`ChoreStrike/.github`
repository), the repository avatar/topics/description, and the Codeberg
deletion.

## 6. Implementation Order

### WP0 — Operator prerequisites (operator-owned, blocking)

1. **[DONE 2026-10-16]** **7-Zip backup of the complete local repository,
   including `.git`**, made by the operator. Nothing in WP1+ starts before the
   operator confirms it. Record the archive file name, date and byte size in
   §10 so the fallback is identifiable, and verify the archive really contains
   `.git` — the project folder is Nextcloud-synced and the repository history
   includes a `.git/` sync-exclusion change, so a working-tree-only archive
   would be a silent loss of the rollback path. This backup is the **only**
   rollback path for the history rewrite, and the only remaining copy of the
   deleted orphan root.
2. **[DONE 2026-10-16]** Create the GitHub organization `ChoreStrike` and
   inside it an **empty** public repository `ChoreStrike` — no auto-generated
   README, LICENSE or `.gitignore`, which would create a conflicting root
   commit. WP7-1 re-checks emptiness before the first push.
3. Confirm the organization's public contact and whether
   `chorestrike@gallero.de` stays the published address (BUG-SEC-001).
4. **Clean working tree:** the CS-PLAN-084 round-11 bookkeeping edits in
   `docs/backlog/ACTIVE_TASKS.md` and
   `docs/plans/active/cs-plan-084-family-trial-ux-baseline.md` (11 lines) must
   be committed under CS-PLAN-084 first, or exported as a patch and re-applied
   after WP3. `git filter-repo` refuses to run on a dirty tree, and a stash is
   not an option (WP3-2 clears the stash).

### WP1 — Baseline inventory

1. Record the §1 measurement table against the actual HEAD at execution time
   and fix `$BASE` = `git rev-list master --count`. Every later count
   expectation refers to `$BASE`, never to a literal.
2. Remove the local identity override so new commits use the correct identity:
   `git config --local --unset user.name` and `--unset user.email`; confirm
   `git config user.email` resolves to the global GitHub address.

### WP2 — Source scrub and public-metadata update (one commit)

1. Replace every `https://codeberg.org/<legacy-user>/ChoreStrike[.git]` with
   `https://github.com/ChoreStrike/ChoreStrike[.git]` and every `.../issues`
   with the GitHub issues URL, across the 13 files of §1.
2. Rewrite the prose around those links where it names the host as the tracker
   ("Codeberg tracker" → "GitHub issue tracker"), and in
   [`design.md`](../../standards/guidelines/design.md) rename the "Codeberg
   Repo Avatar" asset to "Repository avatar (GitHub organization)".
3. In [`security.md`](../../standards/guidelines/security.md) and
   [`docs/SECURITY.md`](../../SECURITY.md), repoint the reporting route to the
   GitHub private-advisory path plus the documented mailbox; keep BUG-SEC-001's
   unresolved mailbox-verification caveat intact.
4. In historical records (`CHANGELOG.md` older entries,
   `docs/backlog/archive/2026-07.md`, `docs/plans/done/cs-plan-081-…`), strip
   the username from URLs; the word "Codeberg" may stay as provenance (§2).
5. `appinfo/info.xml`: new `website`, `bugs`, `repository` values.
6. `package.json`: add `repository`, `bugs`, `homepage`.
7. Add the guard spec `tests/unit/repo-metadata.spec.js` (§4), including its own
   path exclusion, and make it pass.
8. Run the full gate list from §4 plus the Docker backend row.
9. Commit (one scoped conventional commit; the message may name Codeberg as the
   former host but not the legacy username).

### WP3 — History rewrite

1. Install the tool with the available Python 3.13.6
   (`py -m pip install --user git-filter-repo`) and record
   `git filter-repo --version` in §10. `git filter-branch` is explicitly not
   used: it is deprecated, slow and error-prone at this history size.
2. **Reduce the ref graph** so nothing outside `master` is rewritten or
   carried along:
   - `git tag -d v0.1.0` (D-085-06) — this makes the disjoint 14-commit
     orphan root unreachable; the WP0-1 backup is its only remaining copy.
   - `git stash list` (expect the 2 known entries; the operator confirms the
     WIP entry is obsolete), then `git stash clear`.
   - `git reflog expire --expire=now --all`, `git gc --prune=now`.
   - Re-verify: `git rev-list --all --count` == `$BASE`,
     `git rev-list --max-parents=0 --all` == 1 root,
     `git log --all --format='%an <%ae>' | sort -u` == 1 identity.
3. **Generate** the scratchpad mailmap from the repository, so no legacy
   literal is typed into a tracked file (§2):

   ```
   LEGACY="$(git log --all --format='%an <%ae>' | sort -u)"   # must be exactly one line
   printf '%s %s\n' 'volker-ma <220411049+volker-ma@users.noreply.github.com>' "$LEGACY" \
     > <scratchpad>/mailmap.txt
   ```

   Abort if `$LEGACY` is not exactly one line — that means WP3-2 left an
   unexpected ref alive.
4. Write the scratchpad message-replacement file. **Order matters** — the URL
   and address rules must precede the bare-username rule, or the username rule
   would corrupt the URLs. Generate the lines from `$LEGACY` plus the known URL
   shapes rather than hand-typing them:

   ```
   https://codeberg.org/<legacy-user>/ChoreStrike.git==>https://github.com/ChoreStrike/ChoreStrike.git
   https://codeberg.org/<legacy-user>/ChoreStrike==>https://github.com/ChoreStrike/ChoreStrike
   <legacy-address-long>==>220411049+volker-ma@users.noreply.github.com
   <legacy-address-short>==>220411049+volker-ma@users.noreply.github.com
   <legacy-user>==>volker-ma
   <legacy-user-lowercase>==>volker-ma
   ```

5. **Take the rehearsal clone now** — after WP2's commit, not earlier, or it
   would be missing the scrub commit (A-01):
   `git clone --no-local --mirror . <scratchpad>/clone-rehearsal.git`.
   Run the rewrite there first, inspect the rewritten messages and identities,
   then run the identical command on the real repository:

   ```
   git filter-repo --mailmap <scratchpad>/mailmap.txt \
                   --replace-message <scratchpad>/replace-message.txt \
                   --invert-paths --path fix-email.bat --force
   ```

   `filter-repo` preserves author and committer **dates** by default, applies
   the mailmap to authors, committers and taggers, and drops the `origin`
   remote refs — which is wanted, since those refs point at old objects.
6. Preserve `.git/filter-repo/commit-map` (D-085-07) before any further `gc`:
   copy it to `docs/release/history-migration-2026-10-commit-map.tsv`. It holds
   only old→new SHA pairs, nothing sensitive, and it is the only way to resolve
   pre-migration qualification evidence afterwards.
7. Confirm no commit was pruned: the two commits touching `fix-email.bat` each
   change many further files, so the count stays `$BASE`. A lower count is a
   stop condition, not a rounding difference.

### WP4 — Verification (blocking gate)

1. Run the complete §4 evidence block and record the actual output in §10. Any
   non-zero hit, a count other than `$BASE`, more than one root, or a surviving
   tag stops the plan here; the recovery path is the WP0-1 backup.
2. Re-run `npm test`, `npm run lint` and the Docker backend row on the
   rewritten tree — a rewrite changes commit hashes, and the guard spec and
   backlog/plan link checks must still pass.
3. Confirm the working tree content is unchanged apart from the intended edits:
   compare a recursive checksum of the tracked tree against the pre-rewrite
   backup.

### WP5 — GitHub presentation polish

1. **README** restructure for a first-time GitHub visitor: a short project line
   and badge row (license, CI, Nextcloud 34-35, PHP 8.4, pre-release status)
   directly under the title; then a screenshot block; then "What it is" /
   "Install" / "Documentation" / "Status" / "Contributing" / "Contact". Keep the
   existing honest scope statements — the implemented vs. not-implemented split
   and the pre-release warning stay, they are a strength, not clutter. Keep it
   near its current length; link out instead of inlining.
2. **Screenshots** (optional, droppable without blocking): capture 3-4 views
   from the canonical Docker runtime via the existing Playwright setup, store
   under `docs/screenshots/`, and reference them from the README. If the
   operator prefers to produce them later, the README ships without a
   screenshot section and this item becomes its own backlog item.
3. **`.github/CODEOWNERS`** with `* @volker-ma`.
4. **`.github/dependabot.yml`** for `npm`, `composer` and `github-actions`,
   monthly, grouped — low noise for a single-maintainer project.
5. Keep the existing issue templates and PR template; only repoint links and
   drop Codeberg-specific wording. The community-health files stay where they
   are: GitHub detects `SECURITY.md`, `CONTRIBUTING.md` and
   `CODE_OF_CONDUCT.md` in `docs/`, and `LICENSE` is already at the root, so no
   file moves are needed.
6. **Operator-owned, outside this repository:** organization avatar from
   `img/app.svg` per [`design.md`](../../standards/guidelines/design.md) §4
   sizes, repository description and topics (`nextcloud`, `nextcloud-app`,
   `vue`, `php`, `household`, `gamification`), and the organization profile
   README in a `ChoreStrike/.github` repository.

### WP6 — CI port (Forgejo → GitHub Actions)

1. Port the four `.forgejo/workflows/*.yml` to `.github/workflows/`. The
   workflows are already GHA-compatible in syntax, so the real work is triggers
   and runners: replace `runs-on: docker` with `runs-on: ubuntu-latest`, and
   verify every `uses:` action version. Branch triggers stay `master`
   (D-085-08), so no trigger rename is needed.
2. Per D-085-04, split the triggers: lint + Jest + `test:runtime:check` run on
   `push`/`pull_request`; the heavy Docker/E2E matrices (`test.yml` E2E job,
   `full-matrix.yml`, `release-gate.yml`) become `workflow_dispatch`, keeping
   any existing `schedule` for `runtime-check.yml`. Document in each workflow
   header that the binding gate stays the local Docker run, and that
   TECH-TRANSPORT-001 flakiness is why the E2E matrix is not a push gate.
3. Move `.forgejo/` to `archive/2026-10/forgejo-workflows/` per
   [`project-rules.md`](../../standards/ai/rules/project-rules.md) §5
   (`git rm --cached` first; the archive is gitignored).
4. Update the CI references in
   [`architecture.md`](../../engineering/architecture.md) (the `.forgejo/` tree
   entry), [`development.md`](../../engineering/development.md) and
   [`testing.md`](../../engineering/testing.md) (three Forgejo mentions).
5. Re-run the gate list; commit WP5+WP6 as one scoped commit.

### WP7 — Push, remote verification, Codeberg retirement

1. `git remote add origin https://github.com/ChoreStrike/ChoreStrike.git`
   (replacing the dropped Codeberg remote), then `git push -u origin master`.
   No `--tags` push: the only tag was deleted in WP3-2. A force push is not
   needed against an empty repository; if the remote is non-empty, stop and
   re-check WP0-2 instead of forcing.
2. Confirm GitHub adopted `master` as the default branch (D-085-08) and that no
   `main` was auto-created.
3. **Remote verification:** the GitHub commit list attributes every commit to
   `volker-ma`; the API shows no legacy address
   (`gh api repos/ChoreStrike/ChoreStrike/commits --paginate -q '.[].commit.author.email' | sort -u`
   returns exactly the new address); the Actions run on the pushed tree is
   green; the community-profile checklist shows
   README/LICENSE/CONTRIBUTING/CODE_OF_CONDUCT/SECURITY/templates detected.
4. Set the branch protection the operator wants (at minimum: no force-push to
   `master` after this migration).
5. **Only after that verification passes:** the operator deletes the Codeberg
   repository. The 7-Zip backup remains the fallback; the deletion is
   irreversible and is the operator's action, not the agent's.

### WP8 — Durable knowledge integration and backlog closure

1. **ADR-018** "Repository hosting and contributor identity": the GitHub
   organization decision, the single-identity rule, the §2 naming exception,
   the retirement of the orphan `v0.1.0` root, and the rule that future commits
   use the global identity (no per-repository override). Add it to
   [`docs/adr/README.md`](../../adr/README.md).
2. `CHANGELOG.md`: one entry for the migration under the current version,
   including that `v0.1.0` no longer exists as a tag.
3. [`development.md`](../../engineering/development.md): the clone URL, the
   contribution route, and the identity requirement for contributors.
4. [`deployment.md`](../../engineering/deployment.md): the clone URL in the
   installation section and the issues link in the support section.
5. Document the commit map (WP3-6) where release qualification is defined —
   [`testing.md`](../../engineering/testing.md) and
   [`deployment.md`](../../engineering/deployment.md): pre-migration
   qualification SHAs resolve through
   `docs/release/history-migration-2026-10-commit-map.tsv`.
6. **Remap the 18 cited evidence SHAs** (D-085-07) in
   [`ACTIVE_TASKS.md`](../../backlog/ACTIVE_TASKS.md) and the active plans to
   their post-rewrite hashes via the commit map, in a commit **after** WP3.
   Archived records keep their original SHAs and resolve through the map.
7. **TECH-REPO-001** is fully resolved by this plan → move it to
   `docs/backlog/archive/2026-10.md` on the final passing review; any dropped
   WP5-2 screenshot work becomes its own backlog item.
8. Update [`ACTIVE_TASKS.md`](../../backlog/ACTIVE_TASKS.md) on start, pause and
   finish.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| History rewrite loses commits or corrupts the tree | Low | Operator 7-Zip backup (WP0-1) as the sole rollback; rehearsal on a post-WP2 mirror clone; WP4 count/identity/date/root evidence is blocking; tracked-tree checksum compared against the backup |
| Deleting `v0.1.0` discards the orphan root irrecoverably | Medium | The WP0-1 backup is taken before any ref change and is the explicit archive of that line; D-085-06 is a recorded operator decision; the line is a disjoint pre-rename prototype, not an ancestor of anything shipped |
| Message rule order corrupts the rewritten URLs | Medium | URL and address rules precede the bare-username rule (WP3-4); rehearsal output inspected before the real run |
| Hardcoded count expectations block a correct rewrite | Medium | All expectations refer to `$BASE` measured in WP1-1 (A-02); `--all` counts are known to include stash/orphan refs, which WP3-2 removes first |
| A commit becomes empty from the `fix-email.bat` removal and is pruned | Low | Both touching commits change many other files; WP3-7 asserts the `$BASE` count and stops on any drop |
| The guard spec can never pass because it matches its own literals | Medium | WP2-7 requires the spec to exclude its own path, and §2 keeps legacy literals out of every other tracked file (A-03) |
| Pre-migration qualification evidence becomes unresolvable | Medium | `commit-map` preserved as a tracked artifact and documented in the release/testing docs; active SHAs remapped (WP3-6, WP8-5/6) |
| In-flight CS-PLAN-084 work is lost to the dirty-tree requirement | Medium | WP0-4 commits or patch-exports the 11 lines first; the stash is explicitly not used because WP3-2 clears it |
| Codeberg deleted before GitHub is verified | Low | WP7 orders verification strictly before deletion; the deletion is operator-only |
| Porting CI introduces a red default-branch badge | Medium | Heavy matrices move to `workflow_dispatch`; only lint/Jest/runtime-check gate pushes; the badge reflects that workflow |
| Scrub misses a reference | Low | The WP2 guard spec fails the build on any legacy-username, early-contributor-domain or `codeberg.org/<user>/` match in a tracked file, permanently |
| The plan or guard becomes the only public occurrence of a forbidden string | Medium | §2 keeps identity literals out of tracked files; the guard assembles patterns from fragments; the plain values stay in `/private/` and in untracked scratchpad files (A-03, A-08) |
| `appinfo/info.xml` edit breaks app registration | Low | WP2-8 runs the Docker backend row; metadata tags only, no namespace or dependency change |

## 8. Acceptance Criteria

- [ ] Operator 7-Zip backup confirmed and recorded in §10 before any ref change
      or rewrite step
- [ ] GitHub organization `ChoreStrike` and empty public repository exist
- [ ] `$BASE` recorded in WP1-1; the post-rewrite commit count equals `$BASE`
- [ ] Every commit's author and committer is
      `volker-ma <220411049+volker-ma@users.noreply.github.com>` (exactly one
      identity across all refs)
- [ ] Exactly one root commit remains; `refs/tags` is empty; zero merge commits
- [ ] Original author and committer dates preserved (oldest still 2026-02-24)
- [ ] Zero occurrences of the early personal contributor domain, the legacy
      username, the legacy noreply addresses and `codeberg.org/<user>/` URLs in
      any blob or commit message
- [ ] `fix-email.bat` absent from every commit
- [ ] No local `user.name`/`user.email` override remains in the repository
- [ ] No legacy identity literal is present in any tracked file, including this
      plan, the guard spec and the review record (§2)
- [ ] `tests/unit/repo-metadata.spec.js` guards the above, excludes its own
      path, and is green
- [ ] README, `appinfo/info.xml`, `package.json` and the docs point at the
      GitHub organization URLs
- [ ] `.github/workflows/` replaces `.forgejo/` (archived, not deleted), with
      the trigger split documented in each workflow header
- [ ] `docs/release/history-migration-2026-10-commit-map.tsv` committed; the 18
      active evidence SHAs remapped; the map documented in testing/deployment
- [ ] Pushed to GitHub with `master` as the default branch; remote attribution,
      Actions run and community profile verified (WP7-3)
- [ ] Codeberg repository deleted by the operator after that verification
- [ ] ADR-018 recorded and linked; CHANGELOG, development.md and deployment.md
      updated
- [ ] TECH-REPO-001 archived to `docs/backlog/archive/2026-10.md`
- [ ] Build gate green (`npm test` + lint + backend tests in Docker)
- [ ] Required tests ran without new or unapproved skips (browser gate
      deliberately out of scope, recorded in §4)
- [ ] All implementation decisions and review questions resolved and recorded
- [ ] All plan-owned review findings (§9) resolved or user-rescoped to `backlog.md`
- [ ] Owning backlog items updated or archived according to their actual completion state
- [ ] Durable concepts/decisions integrated into the permanent docs
- [ ] Final Standard B or Full review is `PASS` or `PASS-WITH-FIXES`
- [ ] [`docs/backlog/ACTIVE_TASKS.md`](../../backlog/ACTIVE_TASKS.md) updated

## 9. Review Findings (plan-owned)

Standard A, 2026-10-16. All eight were fixed in-round in this plan document.

- [x] **A-01** (Medium · §6 WP1/WP3) Rehearsal clone was created in WP1, before
      the WP2 scrub commit, so the rehearsed history would not have matched the
      real run. → Clone creation moved into WP3-5, explicitly after WP2.
- [x] **A-02** (High · §1/§4/§8) Baseline counts were taken from
      `git rev-list --all`, which includes `refs/stash` and the orphan tag line:
      "576 commits / 1 merge commit / 2 identities" was wrong. `master` is 560
      commits, one identity, zero merges; the "merge" was the stash tip. →
      §1 table corrected and sourced per ref; all expectations now refer to
      `$BASE` measured in WP1-1 instead of literals.
- [x] **A-03** (High · §2/§4/§8) The plan reproduced the legacy username and
      addresses in tracked content, and the proposed guard spec would have
      matched its own pattern literals — the guard could never pass, and the
      plan itself would have reintroduced the strings it removes. → New §2 rule
      "identity literals are never written into tracked files", mailmap
      generated at execution time (WP3-3), guard spec excludes its own path
      (WP2-7, §4).
- [x] **A-04** (Low · §5) File Manifest archive path was double-nested
      (`archive/…/forgejo-workflows/.forgejo/workflows/…`). → Flattened.
- [x] **A-05** (High · §1/§6/§7) 18 commit SHAs cited as qualification evidence
      in tracked active documents would dangle after the rewrite, with no way to
      resolve pre-migration release evidence. → D-085-07: `commit-map` preserved
      as a tracked artifact (WP3-6), documented in testing/deployment (WP8-5),
      active SHAs remapped (WP8-6).
- [x] **A-06** (Low · §2/§6) Default branch was unspecified while GitHub
      defaults new repositories to `main` and all four workflows trigger on
      `master`. → D-085-08: keep `master`; verified explicitly in WP7-2.
- [x] **A-07** (High · §1/§6) The `v0.1.0` tag was treated as an ordinary
      annotated tag on the main line. It actually anchors a **disjoint second
      root** (14 commits, pre-rename `OCA\ChoreWars`) that is the sole carrier
      of the second identity. → D-085-06: delete the tag in WP3-2; the orphan
      line leaves the migration without being rewritten, and the acceptance
      criteria now assert one root and an empty `refs/tags`.
- [x] **A-08** (High · §1/§4/§7/§8) Found while verifying the plan against its
      own A-03 rule: the plan wrote the early personal contributor domain as a
      literal three times, and the guard spec was specified to carry it as a
      pattern literal. Since that string occurs **nowhere** in the repository
      today, the plan and spec would have become its only public occurrences —
      the precise outcome the migration exists to prevent. → The domain is no
      longer written in tracked files; the guard assembles patterns from
      fragments; the plain value stays in `/private/`.

## 10. Execution Record

Filled in during implementation; empty entries are open.

| Item | Value |
|---|---|
| Operator 7-Zip backup (file, date, size) | **Confirmed 2026-10-16** — `ChoreStrike 26-10-16.7z` in the operator's local archive folder, 6,972,369,946 bytes, written 2026-10-16 17:48 |
| Backup contains `.git` (rollback path real) | **Verified 2026-10-16** — archive listing shows 4,845 entries under `ChoreStrike\.git\`, including `logs/refs/` and the remote refs. The backup is therefore a complete history archive, not a working-tree-only copy |
| GitHub organization + empty repository (WP0-2) | **Confirmed by the operator 2026-10-16** |
| Identity re-verification before any rewrite | **2026-10-16** — the early contributor domain token has 0 hits in the object database, the tracked tree, all commit messages and identities, git config at every scope, and untracked/ignored files including `private/` |
| `$BASE` (`git rev-list master --count` at WP1-1) | *(pending; 560 at review time)* |
| HEAD before rewrite | *(pending)* |
| `git filter-repo --version` | *(pending)* |
| Commit count after rewrite | *(pending, expect `$BASE`)* |
| Roots after rewrite | *(pending, expect 1)* |
| Tag `v0.1.0` deleted | *(pending — WP3-2)* |
| Stash entries dropped | *(pending, expect 2)* |
| `commit-map` rows preserved | *(pending — WP3-6)* |
| Verification evidence block output | *(pending — WP4-1)* |
| First GitHub push (commit, date) | *(pending)* |
| Codeberg deletion confirmed by operator | *(pending)* |

## 11. Operator Decisions

| ID | Decision | Date |
|---|---|---|
| D-085-01 | Host on the GitHub **organization** `ChoreStrike`, not the personal account — project identity separate from the private account, contributors and teams possible without a later repository transfer | 2026-10-16 |
| D-085-02 | **Maximum rewrite depth:** identity for all commits, plus username/URL removal from historical commit messages, plus `fix-email.bat` removal from all history. `Co-Authored-By: Claude` trailers stay | 2026-10-16 |
| D-085-03 | **Delete the Codeberg repository** after a verified GitHub push; the 7-Zip backup is the fallback | 2026-10-16 |
| D-085-04 | **Port the CI workflows**, with the heavy Docker/E2E matrices as `workflow_dispatch`; the local Docker gate stays binding | 2026-10-16 |
| D-085-05 | The operator makes the full local repository backup with 7-Zip himself (WP0-1) | 2026-10-16 |
| D-085-06 | **Delete the `v0.1.0` tag**; the disjoint 14-commit pre-rename orphan root it anchors drops out of the migration together with the second identity. The WP0-1 backup is its only archive | 2026-10-16 |
| D-085-07 | **Preserve the `commit-map`** as a tracked artifact and **remap the 18 active evidence SHAs**; archived records resolve through the map | 2026-10-16 |
| D-085-08 | **Keep `master`** as the default branch — zero churn across four workflows and the deployment docs | 2026-10-16 |

## 12. Review Outcome

**Standard A (Plan) · 2026-10-16 · `PASS-WITH-FIXES`**

- **Scope:** `docs/plans/new/cs-plan-085-github-migration-and-identity-rewrite.md`
  (untracked at review time, content hash `de231514…`).
- **Base:** branch `master`, HEAD `f90a270c3e77da00ac19d468ddad6409edd7c99b`,
  empty index; pre-existing unrelated CS-PLAN-084 edits in
  `docs/backlog/ACTIVE_TASKS.md` and
  `docs/plans/active/cs-plan-084-family-trial-ux-baseline.md` preserved
  untouched (unstaged diff fingerprint `af6d3ddf…`).
- **Findings:** 8 (4 High, 4 Medium/Low as listed in §9) — all fixed in-round
  in the plan document; none deferred, none rescoped. A-08 was found by
  verifying the revised plan against its own A-03 rule.
- **Decisions:** D-085-06, D-085-07, D-085-08 recorded in §11 from one batched
  operator question round.
- **Verification run during review:** full object-database scan for the early
  contributor domain token and the operator's personal local part (0 hits for
  both), per-ref commit/identity/root/merge counts, orphan-line and
  tag-target analysis, ahead/behind check against `origin/master`,
  `node scripts/check-backlog.js` (all green, 34 scoped files link-checked),
  `node scripts/check-docs-encoding.js` (985 files, clean), and manual
  resolution of all 17 relative links in this plan.
- **Not run (correctly out of scope for a plan review):** Jest, PHPUnit, the
  browser gate and the build — this round changes no code or runtime artifact.
  The plan's own §4 gates bind at implementation time.
- **Transition:** `new/ → active/`; TECH-REPO-001 marked `In Progress` and
  assigned to this plan; `ACTIVE_TASKS.md` updated.
