# ADR-018: Repository Hosting and Contributor Identity

- **Status:** Accepted (2026-10-16)
- **Deciders:** Operator decisions D-085-01..08 (CS-PLAN-085)
- **Supersedes:** the previous forge hosting and the pseudonymous commit identity used until 2026-10-16

## Context

The repository was hosted on a Codeberg account under a pseudonymous username,
and its history carried that forge identity as author and committer on every
commit. A per-repository `user.name`/`user.email` override kept producing it,
even though the machine's global git identity was already the GitHub one.

Three further facts shaped the decision:

- The operator wanted a single public identity for the project and no residual
  trace of the retired one, including an early personal contributor address
  that turned out never to have been committed (verified by a full
  object-database scan before any rewrite).
- The `v0.1.0` tag did not point into the main line at all. It anchored a
  **fully disjoint second root**: a 14-commit pre-rename prototype
  (`OCA\ChoreWars`, table prefix `chorewars_`) from 2026-02-24 that shared no
  ancestor with `master` and was the only carrier of a second identity.
- Qualification evidence across plans, review records and backlog archives
  identifies builds by commit SHA. Any history rewrite invalidates all of them.

## Decision

1. **Hosting:** the canonical repository is the GitHub **organization**
   `ChoreStrike` (`https://github.com/ChoreStrike/ChoreStrike`), not a personal
   account. The organization keeps the project identity separable from the
   operator's private account and allows contributors or teams later without a
   repository transfer. The default branch stays `master`.
2. **Single identity:** every commit in the published history is authored and
   committed by `volker-ma <220411049+volker-ma@users.noreply.github.com>`.
   Future commits use the machine's **global** git identity; a per-repository
   `user.*` override is a defect, because it silently reintroduces a second
   identity.
3. **No identity literals in tracked files.** The retired username, the retired
   forge addresses and the early personal contributor domain are not written
   into any tracked file — not into plans, not into review records, and not
   into the guard that enforces this. The guard assembles its patterns from
   fragments at runtime; plain values live in `/private/`. Without this rule a
   document describing the cleanup becomes the one public occurrence of the
   strings it removes.
4. **Provenance is allowed.** The bare word "Codeberg" may stand where it
   records history — changelog entries, archived backlog records, completed
   plans, this ADR. What is forbidden is the personal identity: the retired
   username, the retired addresses and any `<forge>/<user>/` URL.
5. **The orphan root was retired, not rewritten.** Deleting the `v0.1.0` tag
   made the disjoint prototype line unreachable, which removed 14 commits and
   the entire second identity in one step. The operator's 7-Zip archive of
   2026-10-16 is its only remaining copy.
6. **Pre-migration SHAs resolve through a map.** `git filter-repo`'s commit map
   is kept as the tracked artifact
   [`docs/release/history-migration-2026-10-commit-map.tsv`](../release/history-migration-2026-10-commit-map.tsv)
   (565 rows, SHA pairs only). Evidence hashes in `ACTIVE_TASKS.md` and the
   active plans were remapped to their post-rewrite values; historical records
   and CS-PLAN-085's own pre-rewrite inventory keep their original hashes and
   resolve through the map.
7. **CI split by cost, not by ambition.** Only Docker-free checks run
   automatically (`.github/workflows/checks.yml`). The full gate, the
   profile/browser smoke matrix and the browser full matrix are
   `workflow_dispatch`; the weekly runtime-drift check stays scheduled. The
   binding quality gate remains the local Docker run.

## Consequences

### Positive

- One identity across the whole published history, with original author and
  committer dates preserved.
- The working tree is provably unchanged by the rewrite: the `HEAD` tree object
  hash is byte-identical before and after.
- A permanent Jest guard (`tests/unit/repo-metadata.spec.js`) fails the build if
  any tracked file reintroduces the retired identity, so the state cannot erode
  silently.
- A single root commit and no tags, instead of two disjoint roots plus a tag
  pointing outside the main line.

### Negative

- Every commit hash changed. Published links, local clones and any external
  reference to an old SHA break; the commit map is the only bridge.
- Historical file contents were rewritten too (`--replace-text`), so old
  versions of `appinfo/info.xml` and the docs show the GitHub URL even in
  commits predating the move. That anachronism is the price of leaving no
  identity trace in any blob.
- The prototype line is gone from the repository. It exists only in the
  operator's local backup.
- A few old short SHAs quoted inside commit messages could not be mapped by
  `filter-repo` and remain stale text in those messages.

### Neutral

- Unreachable pre-rewrite objects can linger in a local clone while another
  worktree or pseudo-ref still pins them. They are never pushed — `git push`
  transmits reachable objects only — and disappear on the next fresh clone.
- Private vulnerability reporting is now offered next to the documented
  mailbox; enabling it is a repository setting, and the mailbox verification
  tracked in BUG-SEC-001 stays open. *Update 2026-10-18:* the mailbox is no
  longer published; private vulnerability reporting is the only security
  channel, and questions go to GitHub Discussions (BUG-SEC-001).

## Alternatives Considered

- **Personal account instead of an organization.** Simpler to set up, but a
  later move to an organization would change every URL again.
- **Identity-only rewrite, leaving messages and the stale helper script.** It
  would have left the retired username in six commit messages and in a
  leftover `filter-branch` helper blob — a visible trace, for no benefit.
- **Squash to a single initial commit.** Maximally clean and fastest, but it
  discards 560 commits of development history and the traceability of the plan
  and review record that the project's own rules depend on.
- **Accept the broken SHA references.** Rejected: release qualification
  identifies candidates by commit, so the evidence chain would have become
  unverifiable.

## References

- [CS-PLAN-085](../plans/active/cs-plan-085-github-migration-and-identity-rewrite.md)
  — plan, measured baseline, verification evidence and operator decisions.
- [Review record 2026-10-16](../backlog/archive/CODE_REVIEW_2026-10-16.md)
  — Standard A findings A-01..A-08.
- [`history-migration-2026-10-commit-map.tsv`](../release/history-migration-2026-10-commit-map.tsv)
  — old → new commit hashes.
- [`project-rules.md`](../standards/ai/rules/project-rules.md) §1 (public
  project language, privacy), §5 (archive policy).
