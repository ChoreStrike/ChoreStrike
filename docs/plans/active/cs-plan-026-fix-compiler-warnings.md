# CS-PLAN-026: Fix Compiler- & Lint-Warnings (Project Sweep)

> **Status:** Implemented (2026-08-12) — all gates green; ready for `active/ → done/`
> **Effort:** M
> **Priority:** P2
> **Depends on:** none
> **Related issue:** —

## 1. Problem Summary

A project-wide compiler/lint review (2026-08-12) found **six categories** of
warnings/errors across the toolchain. Two are **blockers** (the stylelint
config is broken; the PHP coding-standard gate has never run because the config
file is missing). The rest are noise that should be cleaned up so the build
gate (`npm test` + lint + backend tests) is trustworthy and warning-free.

| # | Category | Severity | Scope | Count |
|---|----------|----------|-------|-------|
| A | **Stylelint config broken** (version mismatch) | **Block** | all `.vue`/`.css`/`.scss` | 181 errors |
| B | **PHP coding standard never enforced** (missing config + 100/100 violations) | **Major** | `lib/` (+ `tests/`) | 100 files |
| C | Node.js `punycode` DeprecationWarning (DEP0040) | Minor | Jest workers | every worker |
| D | Browserslist data 6 months stale + ERESOLVE conflict | Minor | build/tooling | 1 warn |
| E | PHP version mismatch (`composer.json` `^8.0` vs Docker 8.2) | Minor | tooling | 1 warn |
| F | `console.error` noise from frontend error-path tests | Nit | 2 Jest specs | ~6 lines |

### A. Stylelint config broken — 181 errors (blocker)
- `stylelint.config.js` extends `@nextcloud/stylelint-config@2.4.0`, whose
  peer dependency is `stylelint@^15.6.0`.
- `package.json` pins `stylelint@^16.1.0`; installed is `stylelint@16.26.1`.
- Stylelint **16 removed** the built-in rules `indentation`,
  `number-leading-zero`, `selector-list-comma-newline-after`, `string-quotes`
  (moved to `stylelint-stylistic`, then dropped). The old config still
  references them → every file reports **4 "Unknown rule" errors**.
- Real violations on top: 10 × `rule-empty-line-before` in
  `Game2048.vue` (lines 393–402).

### B. PHP coding standard never enforced — 100/100 files (major)
- `composer.json` defines `cs:check`/`cs:fix` but there is **no
  `.php-cs-fixer.dist.php`** in the repo (only a stale `.php-cs-fixer.cache`).
  Running `composer cs:check` drops into an interactive prompt instead of
  checking — so the gate has effectively never run.
- Verified via the Nextcloud coding-standard config: **all 100 `lib/` files**
  violate it (4-space indent + Allman braces vs. required **tabs** + brace on
  same line). `tests/` is equally affected.
- Of those, **18/100** also violate plain PSR-12.

### C. `punycode` DeprecationWarning (DEP0040)
- Printed once per Jest worker (`(node:NNNN) [DEP0040] DeprecationWarning: The
  'punycode' module is deprecated.`). Caused by a transitive dependency, not
  project code. Pure noise, but very loud (66 suites → ~30+ lines).

### D. Browserslist data stale + ERESOLVE conflict
- ESLint/Webpack warn that `caniuse-lite` is 6 months old.
- `npx update-browserslist-db` fails with `ERESOLVE`:
  `@nextcloud/eslint-config@8.4.2` is incompatible with the pinned
  `eslint-import-resolver-typescript@4.4.4`.

### E. PHP version mismatch
- `composer.json`: `"php": "^8.0"`; `docker/Dockerfile.test`: `php:8.2-apache`;
  `AGENTS.md` says "PHP 8.1+". Three-way inconsistency. `php-cs-fixer` warns
  it runs on 8.2 but the floor is 8.0.

### F. `console.error` in frontend error-path tests
- `MemoryGame.spec.js` and `SchedulingView.spec.js` print `[ChoreStrike]
  [error] …` via `src/utils/logger.js` on expected failure paths. These are
  *intentional* error-path tests, but the noise leaks into the test output.

## 2. Strategy

Fix the two blockers so the gates actually run, then sweep the remaining noise
in the same pass. Keep changes mechanical and reviewable — no logic changes.

1. **Stylelint** — align the dependency trio to a mutually-compatible set
   (upgrade, not downgrade, to stay current). Re-run `stylelint:fix` to apply
   the stylistic rules and fix the real `rule-empty-line-before` violations.
2. **PHP CS** — add the missing `.php-cs-fixer.dist.php` (loads
   `nextcloud/coding-standard`), then run `composer cs:fix` across `lib/` and
   `tests/`. Commit formatting-only, in a dedicated step so the diff is easy
   to review and doesn't tangle with logic.
3. **Noise** — suppress/resolve punycode, browserslist, PHP-version, and test
   console noise where cheap; log deferrals where not.

## 3. Backend / Frontend Changes

### Backend (`lib/`, `tests/`)
- New `.php-cs-fixer.dist.php` (project root) requiring
  `vendor/nextcloud/coding-standard/.php-cs-fixer.dist.php`.
- Mechanical reformat of **all** `lib/**/*.php` + `tests/**/*.php` via
  `composer cs:fix` (tabs, brace position, import ordering). **No behavior
  change.**
- `composer.json`: bump `"php": "^8.0"` → `"php": "^8.2"` to match the Docker
  runtime (the Dockerfile and the installed PHP are both 8.2; 8.0 was never
  the actual floor). This also silences the php-cs-fixer version warning.
- `AGENTS.md` ("Project Identity"): update "PHP 8.1+" → "PHP 8.2+" so all three
  sources (AGENTS.md, composer.json, Dockerfile) agree.
- `composer.json` `lint` script: keep; `cs:check`/`cs:fix` now resolve the new
  config automatically.

### Frontend (`src/`, `package.json`)
- `package.json` devDependencies:
  - `stylelint`: `^16.1.0` → `^17.9.1`
  - `@nextcloud/stylelint-config`: `^2.4.0` → `^3.2.2`
  - `stylelint-config-recommended-scss`: `^14.1.0` → `^17.0.1`
  - (drop the now-unnecessary manual pin of `stylelint-config-recommended-vue`
    only if the new config pulls it transitively; otherwise bump to match.)
- `stylelint.config.js`: unchanged (still just `extends` the Nextcloud config).
- `src/components/Minigames/Game2048.vue`: fix the 10 `rule-empty-line-before`
  violations (or let `stylelint:fix` do it — confirm the result).
- No source-logic changes anywhere in `src/`.

### Cross-layer contracts
- None affected — all changes are tooling/formatting.

## 4. Tests

- **Unit (PHPUnit):** re-run `composer test` after the PHP reformat to confirm
  no file was accidentally altered semantically (tabs-only reformat must not
  change behavior). Expect: 690 unit + 150 integration still green.
- **Unit (Jest):** `npm test` — expect 66 suites / 677 tests still green. The
  punycode warnings should be reduced/gone after the node/dep resolution.
- **E2E (Playwright):** not required (formatting-only); skip unless a build
  break appears.
- **Lint gates (the deliverable):**
  - `npm run lint` → exit 0, no warnings.
  - `npm run stylelint` → exit 0, **0 problems**.
  - `composer cs:check` → exit 0 (no diff) on `lib/` **and** `tests/`.

## 5. File Manifest

```
new:      .php-cs-fixer.dist.php
new:      tests/jest.setup.deprecations.js (targeted punycode warning suppressor)
modified: package.json (devDependency versions + test setupFiles wiring)
modified: composer.json (php floor ^8.0 → ^8.2)
modified: AGENTS.md (Project Identity: PHP 8.1+ → PHP 8.2+)
modified: jest.config.js / package.json jest config (setupFiles entry)
modified: stylelint.config.js (no change expected; verify)
modified: src/components/Minigames/Game2048.vue (rule-empty-line-before)
modified: **/lib/**/*.php (cs:fix reformat — ~100 files)
modified: tests/**/*.php (cs:fix reformat — mock + test files)
archived: none
removed:  .php-cs-fixer.cache (regenerated, must be .gitignore'd)
```

## 6. Implementation Order

> Commit each step separately so the large formatting diff is isolated and
> reviewable. Steps 1–2 unblock the gates; 3–5 sweep noise.

1. **Stylelint dependency fix** — edit `package.json`, `npm install`, verify
   `npm run stylelint` now reports **only** real violations (the 10 in
   Game2048 + any newly-visible ones from the now-valid rule set). Commit
   `chore(stylelint): align stylelint deps with @nextcloud/stylelint-config 3.x`.
2. **Stylelint code fixes** — run `npm run stylelint:fix`, eyeball the diff
   (must be whitespace-only), confirm 0 problems. Commit
   `style: apply stylelint fixes (rule-empty-line-before + stylistic)`.
3. **PHP CS config + version floor** — add `.php-cs-fixer.dist.php`, bump
   `composer.json` php to `^8.2`, run `composer install`. Verify
   `composer cs:check` runs non-interactively and still reports the violations
   (i.e. the config is picked up). Commit
   `chore(phpcs): add .php-cs-fixer.dist.php + align php floor to 8.2`.
4. **PHP CS code fix** — run `composer cs:fix` over `lib/` then `tests/`.
   **Review the diff carefully** (it will be large but must be
   whitespace/brace-only). Run `composer test` to prove no behavior change.
   Commit `style(php): apply nextcloud coding standard (tabs + braces)`.
5. **Noise sweep** — address C/D/F:
   - **(D browserslist)** try `npm install caniuse-lite@latest --save` /
     resolve the `eslint-import-resolver-typescript` ERESOLVE (may need a
     small bump or `.npmrc` override). If unresolved, log a backlog item and
     move on.
   - **(C punycode)** add a targeted deprecation suppression to the Jest run.
    Note: `--disable-warning=DEP0040` is **Node ≥21 only**; `engines` currently
    says `^20.0.0` (local dev runs Node 24, but the floor must be respected).
    Use a **Jest `setupFiles` shim** that silences `process.emitWarning` for
    `DEP0040` only (keeps real deprecations visible), or fall back to
    `NODE_OPTIONS=--no-deprecation` on the `test` script if a setupFile is
    overkill. Prefer the targeted setupFile.
   - **(F test console)** silence the two error-path specs with
     `jest.spyOn(console, 'error').mockImplementation(() => {})` around the
     expected-failure assertions, restoring afterwards.
   Commit `chore: reduce build noise (browserslist/punycode/test console)`.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Stylelint 17 exposes *new* real violations beyond Game2048 (rules that were silently "unknown" now run) | Medium | Run `stylelint:fix`; review every non-whitespace diff manually. Any non-auto-fixable rule violation → fix inline if simple, else log + `stylelint:disable-next-line` with a reason. |
| `composer cs:fix` reformats a file in a way that changes behavior (e.g. heredoc, comments) | Low | php-cs-fixer is formatting-only by design; still, run `composer test` immediately after and treat any red as a stop-the-line regression. |
| ERESOLVE on browserslist update can't be cleanly resolved (deep dep tree) | Medium | Step 5 is best-effort; if it needs a major eslint/ecosystem bump, log a backlog item (P2) and do **not** block the rest of the plan on it. |
| Large PHP reformat diff makes review hard / pollutes `git blame` | Medium | Isolate in its own commit (step 4) with a clear message; document the `.mailmap`/`git blame` consideration. Accept that this is a one-time cost of never having run the gate. |
| Bumping php floor to 8.2 surprises a contributor on 8.0/8.1 | Low | Pre-release project (no prod, fresh installs only — project-rules §1); Docker is already 8.2. Note the bump in the commit + plan. |
| Punycode suppression approach incompatible with `engines: node ^20` | Medium | `--disable-warning` is Node ≥21 only; use a Jest `setupFiles` shim instead so the Node-20 floor stays valid. Verify on the actual floor version if possible. |
| `.php-cs-fixer.dist.php` path convention wrong for this Nextcloud CS version | Low | Verify the exact require path (`vendor/nextcloud/coding-standard/.php-cs-fixer.dist.php`) against the installed `nextcloud/coding-standard` version before running `cs:fix`. |

## 8. Acceptance Criteria

- [x] `npm run stylelint` exits 0 with **0 problems** on `src/`
      (0 errors; 26 `use-logical` warnings deferred → TECH-LINT-004).
- [x] `npm run lint` exits 0 (ESLint clean) with no browserslist warning
      (warning resurfaced during the Type B review — the caniuse-lite lockfile
      update had been lost; re-applied via `update-browserslist-db`, see
      Review Outcome below).
- [x] `composer cs:check` runs **non-interactively** and exits 0 (no diff) on
      `lib/` and `tests/` (0 of 250 files fixable, verified in Docker).
- [x] `composer test` green (690 unit + 150 integration) after the reformat.
- [x] `npm test` green (66 suites / 677 tests); punycode warning suppressed or
      gone (0 × DEP0040 in a full run).
- [x] `.php-cs-fixer.dist.php` committed; `.php-cs-fixer.cache` gitignored.
- [x] Browserslist warning resolved **or** a backlog item logged with reason.
- [x] Frontend error-path test specs no longer print `console.error` noise.
- [x] Build gate green (`npm test` + `npm run lint` + `npm run stylelint` +
      backend tests in Docker).
- [x] Durable conventions integrated into permanent docs (update
      `docs/engineering/testing.md` / `docs/standards/` with the CS gate
      commands so the plan is not the only place they live).
- [x] `docs/backlog/ACTIVE_TASKS.md` updated; completed item moved to
      `archive/2026-08.md` on completion.

---

**Review note:** This plan touches the entire `lib/` and `tests/` tree via an
automated formatter. The changes are mechanical, but the diff volume is high.
Recommend a `/plan-review` before `new/ → active/`.

---

## Implementation Outcome (2026-08-12)

**All blocking gates green**, verified after the change set:

| Gate | Result |
|------|--------|
| `npm run lint` | exit 0 (browserslist warning resurfaced later — re-fixed in the Type B review, see below) |
| `npm run stylelint` | exit 0 — **0 errors**, 26 `use-logical` warnings (deferred) |
| `npm test` | 66 suites / 677 tests, **0 punycode** DEP0040 |
| `npm run build` | webpack compiled successfully |
| `composer cs:check` | **0 of 250** files fixable (non-interactive) |
| `composer test` (unit) | 690 tests / 1842 assertions |
| `composer test` (integration) | 150 tests / 356 assertions / 24 skipped |
| `npm ci` | exit 0 (CI install works) |

### Deviations from the plan (with reasons)
- **PHP CS config mechanism** — the plan assumed `.php-cs-fixer.dist.php`
  could simply `require` `vendor/nextcloud/coding-standard/.php-cs-fixer.dist.php`.
  That file binds its Finder to its own package dir (`__DIR__`), which would
  leave `lib/`/`tests/` unscanned. The shipped config instead instantiates the
  Nextcloud `Config` class (same rules) and points the Finder at `lib/`+`tests/`.
- **Punycode (item C)** — a pure `setupFiles` shim is too late (jsdom loads
  `punycode` during environment setup, before `setupFiles` runs). Solved by
  preloading the same targeted shim via `NODE_OPTIONS --require` in the test
  scripts (`cross-env`), keeping the Node-20 floor valid. The `setupFiles`
  entry is retained as an inert fallback.
- **Stylelint `use-logical`** — the newly-valid config exposed 26 warnings
  (physical→logical property suggestions). These are RTL/layout logic changes,
  explicitly out of the plan's "no source-logic changes" scope, so left as
  warnings → new backlog **TECH-LINT-004**.
- **console.error noise (item F)** — the plan named 2 specs, but the same
  `api.js`↔mock gap produces ~95 lines across 8 store specs. The 2 named specs
  are silenced; the systemic root cause is deferred → **TECH-LINT-005**.
- **`.npmrc` (legacy-peer-deps)** — added because the pre-existing eslint
  peer-ESOLVE breaks `npm ci` (CI). Documented as **TECH-LINT-006**.
- **CI workflow** — stylelint promoted advisory→blocking; `composer cs:check`
  step added (with `composer install`), so the gate docs are truthful.
- **PHP floor** — also aligned `appinfo/info.xml` + `README.md` +
  `docs/engineering/*` (plan named only AGENTS.md + composer.json).

### Deferred to backlog
- **TECH-LINT-004** — 26 stylelint `use-logical` warnings (RTL sweep).
- **TECH-LINT-005** — Jest `[ChoreStrike] [error]` noise from `api.js`↔mock gap.
- **TECH-LINT-006** — npm eslint peer-ESOLVE (needs `--legacy-peer-deps`).

---

## Review Outcome (Type A — Plan Review, 2026-08-12)

**Tier:** Standard A (plan review, self-review). **Result:** Revised — 3 findings
fixed inline, plan ready for `new/ → active/` after the user confirms.

| # | Severity | Finding | Resolution |
|---|----------|---------|------------|
| 1 | Major | Step 5(C) proposed `--disable-warning=DEP0040`, but that flag is **Node ≥21 only** while `engines` says `^20.0.0`. Would break on the declared floor. | Rewrote to a **Jest `setupFiles` shim** (targeted `process.emitWarning` filter) with `NODE_OPTIONS=--no-deprecation` as documented fallback. Node-20-safe. |
| 2 | Minor | PHP version stated in two places (composer.json, Dockerfile) but `AGENTS.md` ("PHP 8.1+") is a third, inconsistent source. The floor bump would leave AGENTS.md stale. | Added an explicit step to update `AGENTS.md` "Project Identity" → "PHP 8.2+" so all three agree. Added to File Manifest + Backend Changes. |
| 3 | Nit | File Manifest listed `package.json` vaguely as "devDependency versions" and missed the Jest `setupFiles` wiring + new setup shim file. | Expanded Manifest: new `tests/jest.setup.deprecations.js`, `jest.config` setupFiles entry, AGENTS.md line. |

**Checks passed:** scope coherent (tooling/formatting only, no mixed logic
concerns); no overlap with CS-PLAN-024 (E2E suite) or any active plan; steps
individually validatable; risks named with mitigations; acceptance criteria
testable and mapped to build-gate commands. No workaround-stacking.

**Open for the user to decide:** confirm the Node-20 punycode approach
(setupFile vs. `--no-deprecation`) and the php floor target (`^8.2` vs. keeping
`^8.1` and only fixing the AGENTS.md wording) before moving to `active/`.

---

## Review Outcome (Type B — Code Review, 2026-08-12)

**Tier:** Standard B (uncommitted CS-026 change set, ~250 files). **Result:**
2 findings fixed inline, gates re-verified green; plan ready for
`active/ → done/` (manual move by the user).

**Reformat safety proof:** all 249 changed PHP files were token-stream-compared
against HEAD (whitespace/comment-insensitive). Result: brace joining, tab
indentation, trailing commas in multiline, import ordering, quote normalization,
and 19 removed unused/same-namespace imports — **zero semantic changes**.

| # | Severity | Finding | Resolution |
|---|----------|---------|------------|
| 1 | Minor | Browserslist "caniuse-lite 6 months old" warning still present during the review although the Implementation Outcome claimed it resolved — the lockfile contained no caniuse-lite update. | Re-ran `npx update-browserslist-db@latest` (lockfile updated); `npm run lint` re-verified: exit 0, no warning. Corrected the outcome table above. |
| 2 | Nit | Closed items P9-CONFIG-001/002 still had full sections in `docs/backlog/backlog.md` (backlog rule: no Done items in backlog.md). | Sections removed; the CS-026 entry in `archive/2026-08.md` already documents both. |

**Gates re-verified (2026-08-12, this review):** `npm run lint` exit 0 (no
browserslist warning) · `npm run stylelint` exit 0 (0 errors, 26 `use-logical`
warnings) · `npm test` 66 suites / 677 tests, 0 × DEP0040 ·
`composer cs:check` (Docker) 0 of 250 fixable · PHPUnit unit (Docker)
690 / 1842 assertions · PHPUnit integration (Docker) 150 / 356 / 24 skipped.

**Checklist:** architecture/auth/i18n/logging N/A (tooling/formatting-only;
no logic, no new strings, no `console.*` in production code — the two spec
silencers are test-scoped `jest.spyOn` with restore). The punycode shim is a
documented targeted suppression (root cause: transitive dependency; removal
condition: Node floor ≥ 21 or the dependency drops `punycode`), tracked under
TECH-LINT-003. CI workflow + engineering docs updated in the same change.

**Deferred (already in backlog):** TECH-LINT-004 (26 `use-logical` warnings),
TECH-LINT-005 (`api.js`↔mock console.error noise), TECH-LINT-006 (npm
peer-ESOLVE / `.npmrc` legacy-peer-deps).
