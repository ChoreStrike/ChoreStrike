# CS-PLAN-001: Project Cleanup & Public-Release Readiness (Step 2)

> **Status:** Plan (not started)
> **Effort:** L
> **Priority:** P2
> **Depends on:** Step 1 restructure (rules/skills/guidelines) — done & staged
> **Related:** `docs/plans/template.md`, `docs/standards/ai/rules/project-rules.md` §5 (archive)

## 1. Problem Summary

Step 1 established the rules/skills/guidelines structure. The repository itself still carries cleanup debt that blocks a clean **public MIT release on Codeberg**:

- **`vendor/` is fully committed (1249 tracked files)** — Nextcloud apps do not commit dependencies; CI already runs `composer install` at runtime.
- **Naming drift:** "ChoreWars" still appears in `LICENSE`, `package.json`, `CONTRIBUTING.md` (the project is **ChoreStrike**).
- **Hosting drift:** `appinfo/info.xml` is canonical → **Codeberg** (`volker-ma/ChoreStrike`), but `README.md` and `docs/standards/.../SECURITY.md` point to **GitHub**.
- **Version drift:** `appinfo/info.xml` = `0.1.1`, `package.json` = `0.1.0`.
- **App metadata:** `<types><logging/></types>` in `info.xml` is incorrect for this app.
- **Doc overlap / staleness:** root `DEPLOYMENT.md` vs `docs/DEPLOYMENT_CHECKLIST.md`; `docs/CONCEPT.md` vs `docs/concepts/`; `ARCHITECTURE.md` has an outdated directory tree.
- **Hygiene:** empty `img/emojis` & `img/icons` dirs; tracked `.sync-exclude.lst` (Nextcloud sync-client artifact).

No production **code** is expected to change — this is configuration, metadata, docs, and tracked-artifact hygiene. Anything removed is moved to `archive/2026-06/`, never deleted (project-rules §5).

## 2. Strategy

Execute in five reviewable phases. Each phase is independently committable. Reversible changes only; no schema/data migrations. Verify the build/test gates after the artifact phase (Phase 2) and at the end.

**Decisions required before/during execution** (recommendations given; confirm or override in the implementation session):

| # | Decision | Recommendation | Why |
|---|----------|----------------|-----|
| D1 | Untrack `vendor/`? | **Yes** — `git rm -r --cached vendor/` + add `vendor/` to `.gitignore` | Nextcloud apps don't ship `vendor/`; CI runs `composer install`; massively shrinks the repo. Reversible (history kept). |
| D2 | Canonical hosting? | **Codeberg** (`volker-ma/ChoreStrike`) | Already canonical in `appinfo/info.xml`. Fix README/SECURITY to match. |
| D3 | Target app version? | **0.1.1** (align `package.json` up to match `info.xml`) | Keep app-store metadata authoritative. Also add a `CHANGELOG.md` entry (latest is `[0.1.0]`). |
| D4 | `info.xml <types>`? | Remove the incorrect `<logging/>` type | A gamified task app is not a logging app. |
| D5 | Keep `.github/` issue templates on Codeberg? | **Yes**, verify they render | Forgejo reads `.github/ISSUE_TEMPLATE`. Low risk. |

## 3. Backend / Frontend Changes

**No application code changes.** Touched surfaces:

- **Build/config:** `.gitignore`, `package.json`, `appinfo/info.xml`, `composer.json` (verify no `vendor` references).
- **Root docs/governance:** `LICENSE`, `README.md`, `SECURITY.md`, `CONTRIBUTING.md`, `DEPLOYMENT.md`.
- **Docs:** `docs/CONCEPT.md`, `docs/ARCHITECTURE.md`, `docs/DEPLOYMENT_CHECKLIST.md`, `docs/concepts/`, possibly `docs/{ALPHA_TEST_GUIDE,FAMILY_TEST_SETUP,MANUAL_TEST_CHECKLIST,RELEASE_STRATEGY,ROADMAP,REALTIME_UPDATES}.md`.
- **Tracked artifacts:** `vendor/` (untrack), `.sync-exclude.lst` (untrack).

## 4. Tests

No code paths change, but re-run gates to confirm nothing depended on the removed artifacts:

```bash
npm test                 # Jest
npm run lint             # ESLint
npm run stylelint        # Stylelint (js + vue + css)
npm run build            # Webpack build still succeeds (no stray refs)
docker compose -f docker-compose.test.yml run --rm nextcloud-test \
  vendor/bin/phpunit --configuration phpunit.unit.xml   # backend still installs/runs after vendor untrack
```

Confirm the Forgejo CI (`.forgejo/workflows/test.yml`) is unaffected — it already downloads Composer at runtime, so untracking `vendor/` is safe. **`composer.lock` stays committed** (only `vendor/` is untracked).

## 5. File Manifest (indicative — refine during execution)

```
modified: .gitignore                        # add vendor/, .sync-exclude.lst
modified: LICENSE                           # "ChoreWars Contributors" -> "ChoreStrike Contributors"
modified: package.json                      # author -> "ChoreStrike Contributors"; version -> 0.1.1
modified: appinfo/info.xml                  # remove <types><logging/>; keep Codeberg URLs
modified: README.md                         # install URL -> codeberg.org; doc links
modified: SECURITY.md                       # vuln-report URL -> Codeberg
modified: docs/DEVELOPMENT.md               # placeholder yourorg -> codeberg.org
modified: docs/TESTING.md                   # clone URL -> codeberg.org
modified: CONTRIBUTING.md                   # remaining "ChoreWars" -> "ChoreStrike"
modified: CHANGELOG.md                      # add 0.1.1 / Unreleased cleanup entry
modified: docs/ARCHITECTURE.md              # refresh stale directory tree
keep:    composer.lock                       # stays committed (only vendor/ is untracked)
archived: DEPLOYMENT.md            -> archive/2026-06/docs/
archived: docs/CONCEPT.md          -> archive/2026-06/docs/  (if consolidated into concepts/)
archived: .sync-exclude.lst        -> archive/2026-06/cruft/
untracked+ignored: vendor/ (1249 files)     # git rm -r --cached, stays on disk
removed:  img/emojis/, img/icons/           # empty dirs
```

Historical mentions of "ChoreWars" in `CHANGELOG.md` ("Renamed from …") and `docs/backlog/ACTIVE_TASKS.md` are **preserved as history** — not changed.

## 6. Implementation Order

**Phase 1 — Naming & metadata** (low risk, high value)
- `LICENSE` copyright → ChoreStrike Contributors.
- `package.json` author + version (0.1.1).
- `CONTRIBUTING.md` remaining "ChoreWars".
- `appinfo/info.xml`: drop `<types><logging/></types>`; verify Codeberg URLs.

**Phase 2 — Hosting consistency** (only the project's **own** repo URLs; `github.com/nextcloud/*`, dependency URLs in lockfiles, and asset URLs for lucide/fluentui are intentional and **out of scope**)
- `README.md` clone URL + badges → Codeberg.
- `SECURITY.md` advisory/reporting → Codeberg.
- `docs/DEVELOPMENT.md` (placeholder `github.com/yourorg/ChoreStrike`) → Codeberg.
- `docs/TESTING.md` clone URL → Codeberg.
- Grep-and-fix any other `github.com/volker-ma/ChoreStrike` references.

**Phase 3 — Untrack build/runtime artifacts** (D1)
- Add `vendor/` and `.sync-exclude.lst` to `.gitignore`.
- `git rm -r --cached vendor/`; `git rm --cached .sync-exclude.lst`; move `.sync-exclude.lst` to `archive/2026-06/cruft/`.
- Remove empty `img/emojis/`, `img/icons/`.
- **Run gates** (npm build + Docker phpunit) to confirm nothing broke.

**Phase 4 — Doc consolidation**
- Reconcile root `DEPLOYMENT.md` with `docs/DEPLOYMENT_CHECKLIST.md` → single `docs/DEPLOYMENT.md`; archive the loser.
- Reconcile `docs/CONCEPT.md` (broad "Concept & Design" overview) with `docs/concepts/PRODUCT_VISION.md` — decide: fold into `concepts/PRODUCT_VISION.md` or relocate as `concepts/OVERVIEW.md`; archive the duplicate. Don't keep both.
- Refresh the stale directory tree in `docs/ARCHITECTURE.md`.
- Triage remaining top-level docs (test guides, release strategy, roadmap, realtime) → keep, move under a `docs/guides/` style folder, or archive.

**Phase 5 — Governance & final sweep**
- Verify `.github/` issue/PR templates render on Codeberg (D5); adjust only if broken.
- Final grep: no `ChoreWars` outside `CHANGELOG.md` + historical backlog; no `github.com` outside intentional links; no tracked `vendor/`.
- Update `docs/backlog/ACTIVE_TASKS.md` with the cleanup result.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Local/Docker dev relies on committed `vendor/` | Low | `vendor/` stays on disk (`--cached` only); CI already runs `composer install`. Document `composer install` in DEVELOPMENT.md if missing. |
| Broken internal doc links after consolidation | Medium | Update cross-links in the same phase; grep for stale paths; re-run a link sanity check. |
| Wrong canonical host chosen | Low | `appinfo/info.xml` already declares Codeberg — treat it as source of truth. |
| Loss of historical "ChoreWars" context | Low | Keep CHANGELOG/backlog mentions; only fix forward-facing metadata. |
| Accidentally deleting instead of archiving | Low | All removals → `archive/2026-06/<sub>/`; nothing hard-deleted (project-rules §5). |

## 8. Acceptance Criteria

- [ ] No `ChoreWars` occurrences outside `CHANGELOG.md` and historical backlog entries.
- [ ] No **project-own** `github.com/volker-ma/ChoreStrike` or `yourorg` URL remains; all point to **Codeberg**. (Dependency/`nextcloud/*`/asset URLs untouched.)
- [ ] `vendor/` is untracked and in `.gitignore`; **`composer.lock` remains tracked**; `npm run build` + Docker PHPUnit still pass.
- [ ] `package.json`, `appinfo/info.xml`, and `CHANGELOG.md` versions agree (0.1.1).
- [ ] `info.xml` no longer claims the `logging` app type.
- [ ] Deployment docs are consolidated (one source of truth); `ARCHITECTURE.md` tree is current.
- [ ] `CONCEPT.md` is either consolidated into `concepts/` or archived — not duplicated.
- [ ] Every removed file is preserved under `archive/2026-06/`; nothing hard-deleted.
- [ ] `docs/backlog/ACTIVE_TASKS.md` updated; build gate green (`npm test` + lint + stylelint + backend tests).
