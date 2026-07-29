# CS-PLAN-019: Test-Endpoint Security Hardening (`@PublicPage` removal + `isTestMode()` tightening)

> **Status:** Done (2026-07-29) — committed; moved `active/ → done/`
> **Effort:** S
> **Priority:** P1
> **Depends on:** none
> **Source:** `docs/backlog/bugs.md` → "[P1] Test endpoints exposed via `@PublicPage`; `isTestMode()` triggers on any `CI` env var" (surfaced 2026-07-27 during the CS-PLAN-015b review)
> **D1 Decision:** Option A — keep + harden (user decision, 2026-07-29)

## 1. Problem Summary

The test-data endpoints (`seed`, `reset`, `profiles`) are a live, route-registered
surface (`appinfo/routes.php:146-148`) that can mutate/wipe the application database.
They are currently over-exposed in two ways that together form a P1 security hole:

1. **All three `TestController` methods are annotated `@PublicPage`**
   (`lib/Controller/TestController.php:37, 93, 132`) — a direct violation of the
   project rule "Never `@PublicPage`" (project-rules §2 / AGENTS.md). `@PublicPage`
   makes the route reachable **without any Nextcloud session**, i.e. unauthenticated.
2. **`TestDataService::isTestMode()` returns `true` whenever `getenv('CI')` is set**
   (`lib/Service/TestDataService.php:414-420`). ChoreStrike installed on **any**
   machine that defines a `CI` env var (GitHub Actions, GitLab, Jenkins, generic
   dev tooling) silently activates its test endpoints. Because `@PublicPage` removes
   the auth requirement, a bare `GET /apps/chorestrike/api/v1/test/reset` can wipe the
   app tables of such an instance from an unauthenticated request.
3. **`profiles()` has no `isTestMode()` guard** (`lib/Controller/TestController.php:135`)
   and always returns data including the `testMode` flag — a reconnaissance probe that
   reveals whether the seed/reset attack surface is live on a given instance.

Net effect: an unauthenticated attacker who can reach the app on a host where `CI` is
defined (a common env var on developer/CI machines) can reset or reseed the
ChoreStrike database. The CS-PLAN-015b CI repair (`test.yml`) now calls these
endpoints from CI, raising the relevance and making the gap concrete.

This is the **only open P1** in the backlog (the authorization cluster S1–S7, the
fresh-install schema gap, and the migration defects are all closed). It is
security-critical, small, and independent — hence its own focused plan.

## 2. Strategy

Restore defense-in-depth on the test surface by closing all three vectors, while
keeping the **local Docker test environment** working unchanged (it already seeds via
the authenticated `occ chorestrike:test-data:seed` command and sets
`NEXTCLOUD_TEST_MODE=true` explicitly):

1. **Remove `@PublicPage`** from `seed`/`reset`/`profiles`; keep `@NoAdminRequired`
   only → the routes now require an authenticated Nextcloud session (any logged-in
   user), satisfying "Never `@PublicPage`".
2. **Guard every method with `isTestMode()`** at the top (including `profiles()`):
   outside test mode, `seed`/`reset` return 403 and `profiles()` omits the
   `testMode` field (or returns 403) — no reconnaissance.
3. **Tighten `isTestMode()`** to require the explicit, ChoreStrike-owned flag
   `NEXTCLOUD_TEST_MODE` (via `getenv` and `$_ENV`) and **drop the broad
   `getenv('CI')` fallback**. A generic CI indicator must never arm DB-wiping
   endpoints.
4. **Fix CI seeding** so it no longer depends on the removed `@PublicPage` path: the
   Forgejo `test.yml` "Mount and enable" step currently does an **unauthenticated**
   `curl GET .../test/seed/{profile}` with `|| true` + `continue-on-error: true`
   (almost certainly silently failing). Replace it with the canonical authenticated
   `occ` seed that the local env already uses. Note: `NEXTCLOUD_TEST_MODE=true` is
   **already** set on the Nextcloud service container (`test.yml:84`), so
   `isTestMode()` already returns true in-process there — no env change is required;
   only the seed command needs replacing.

The HTTP test routes remain registered (auth-gated + test-mode-gated). Removing the
HTTP routes entirely (seed-only-via-occ) is captured as **Decision D1** below; the
default is to keep + harden them (least disruptive, preserves the `profiles` probe
for test tooling).

## 3. Backend / Frontend Changes

- **Backend** (`lib/`):
  - `lib/Controller/TestController.php`:
    - Remove `@PublicPage` from `seed()`, `reset()`, `profiles()` (keep
      `@NoAdminRequired`, `@NoCSRFRequired`).
    - Add an `isTestMode()` early-return (403 `NOT_TEST_MODE`) to `profiles()`
      (mirrors `seed`/`reset`); when guarded, omit the `testMode` field from the
      payload.
    - Optionally: extract a private `requireTestMode(): ?JSONResponse` helper to DRY
      the three identical 403 blocks (rule of three — three identical blocks → one
      helper).
  - `lib/Service/TestDataService.php` (`isTestMode()`, :414): drop the
    `getenv('CI') !== false` term; keep `getenv('NEXTCLOUD_TEST_MODE') === 'true'`
    and `isset($_ENV['NEXTCLOUD_TEST_MODE'])`.
  - No entity/mapper/migration change.
- **CI** (`.forgejo/workflows/test.yml`):
  - `NEXTCLOUD_TEST_MODE=true` is **already present** on the Nextcloud service
    container (`test.yml:84`) — no env change needed. (Do **not** confuse this with
    the job-level/runner `env:` on the Playwright step, which only affects the
    Node test runner, not the PHP process.)
  - Replace the unauthenticated `curl GET .../test/seed/{profile}` seed
    (`test.yml:129`) with an authenticated
    `docker exec nextcloud su -s /bin/bash www-data -c "php occ
    chorestrike:test-data:seed --profile=<profile>"` (the canonical local path; see
    `docker/seed-test-data.sh:27`).
- **Frontend** (`src/`): none. (No Pinia store consumes `/test/*`; verified — the
  test endpoints are consumed only by tooling, not the app UI.)
- **Cross-layer contracts**: none affected. The `{success, error:{code,message}}`
  envelope and the 403 `NOT_TEST_MODE` code are unchanged; only the trigger condition
  and the `@PublicPage` annotation change.

## 4. Tests

- **Unit (PHPUnit)** — `tests/unit/Service/TestDataServiceTest.php`:
  - **Invert** `testIsTestModeDetectsCI()` (currently asserts `CI=true` → test mode):
    with `CI=true` and `NEXTCLOUD_TEST_MODE` unset, `isTestMode()` must now return
    **false**.
  - Keep `testIsTestModeDetectsTestMode()` (`NEXTCLOUD_TEST_MODE=true` → true) and
    `testIsTestModeReturnsFalseInProduction()` (both unset → false).
  - Add a case: `$_ENV['NEXTCLOUD_TEST_MODE']` path → true (covers the `isset($_ENV)`
    branch).
- **Unit/Integration (PHPUnit)** — `tests/integration/controller/TestControllerTest.php`:
  - `profiles()` outside test mode no longer returns the `testMode` field / returns 403
    (update/extend `testProfilesIncludesTestModeStatus`).
  - Add explicit "not-test-mode → 403" coverage for `profiles()` (currently the only
    method lacking a guard test).
  - Note: these are mock-based (no real auth) and **excluded from the CI unit gate**
    today (see `bugs.md` [P2]). They still assert controller behavior and run locally;
    the gate-blindness is a separate tracked item.
- **Regression (security)** — add a real-DB / integration test asserting that an
  **unauthenticated** request to all three endpoints is rejected (403/404/redirect),
  at minimum for `seed` and `reset`. (Pairs with CR-016-10's "unblock the integration
  harness" dependency; if the harness is still blocked, add an authenticated-context
  regression that proves the `@PublicPage` removal took effect by asserting the route
  requires a session.)
- **E2E (Playwright)**: no new spec required (the CS-015b `storageState` auth infra
  already proves authenticated access; the test endpoints are not part of the app UI).
  Verify the local Docker E2E suite still seeds correctly after the change
  (`occ`-based path is untouched).
- **No new frontend tests** (no `src/` change).

## 5. File Manifest

```
modified: lib/Controller/TestController.php                       (remove @PublicPage ×3, guard profiles(), optional helper)
modified: lib/Service/TestDataService.php                         (isTestMode() drop CI fallback)
modified: tests/unit/Service/TestDataServiceTest.php              (invert CI assertion + $_ENV case)
modified: tests/integration/Controller/TestControllerTest.php     (profiles guard coverage + not-test-mode ×3)
modified: .forgejo/workflows/test.yml                             (NEXTCLOUD_TEST_MODE already set @ L84 — verify; replace unauth seed curl w/ occ; drop || true/continue-on-error)
modified: docker/seed-test-data.sh                                 (remove the now-dead unauthenticated API fallback @ L28-40; occ is the only seed path)
modified: docs/engineering/testing.md                             (note: test-mode is NEXTCLOUD_TEST_MODE only; occ is the seed path)
modified: docs/backlog/bugs.md                                    (close the [P1] item once shipped)
modified: docs/backlog/ACTIVE_TASKS.md                            (register + complete entry)
new:      docs/plans/active/cs-plan-019-test-endpoint-security-hardening.md  (this plan, moved new/ → active/ on start)
```

No `archive/` moves. No migration. No `l10n/` change (no new user-visible strings —
the `NOT_TEST_MODE` code already exists).

## 6. Implementation Order

1. **Tighten `isTestMode()`** in `TestDataService` + update/invert the unit test
   (`CI` alone → false). Run `phpunit.unit.xml` for the service test. *(Foundation —
   the controller guard depends on this being correct.)*
2. **Harden `TestController`**: remove `@PublicPage` ×3, add the `isTestMode()` guard
   to `profiles()` (+ optional `requireTestMode()` helper). Update the controller test.
3. **Fix CI `test.yml`**: `NEXTCLOUD_TEST_MODE=true` is already on the Nextcloud
   service container (`test.yml:84`) — verify only. Replace the unauthenticated
   seed curl (`test.yml:129`) with the authenticated `occ` seed command, and remove
   the now-pointless `continue-on-error: true`/`|| true` so a real seed failure
   surfaces.
4. **Regression verification (Docker)**: `down -v` → `up -d --build`, confirm the
   local env still seeds via `occ` and `isTestMode()` is true; confirm an
   unauthenticated `curl .../test/reset` is rejected.
5. **Docs**: update `docs/engineering/testing.md` (test-mode = `NEXTCLOUD_TEST_MODE`
   only) and close the `bugs.md` [P1] item.
6. **Build gate**: `npm test` + `npm run lint` (frontend unchanged, sanity) + PHPUnit
   unit + integration green in Docker; `php -l` clean on touched PHP.
7. **Backlog sync**: ACTIVE_TASKS.md entry.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Dropping the `CI` fallback breaks a real test path that relied on it | Low | Verified: the **local** Docker env sets `NEXTCLOUD_TEST_MODE=true` explicitly (`docker-compose.test.yml:36`) and seeds via `occ` — it never relied on `CI`. CI is fixed in the same change. `testIsTestModeDetectsCI` is the only test asserting the old behavior; it is inverted in step 1. |
| Removing `@PublicPage` breaks the CI seed curl | High (expected) | The CI seed curl is already best-effort (`|| true` + `continue-on-error: true`) and likely silently failing today. Step 3 replaces it with the authenticated `occ` seed used locally — strictly more correct. `NEXTCLOUD_TEST_MODE=true` is already on the NC container (`test.yml:84`), so `isTestMode()` stays true there. |
| `docker/seed-test-data.sh` API fallback becomes dead/broken | Low | Its unauthenticated `curl -X POST .../test/seed` fallback (`L28-40`) fires only if `occ` fails. After `@PublicPage` removal it would 403. Step 3 removes the fallback so `occ` is the single seed path (KISS; matches the CI fix). |
| The test HTTP routes become unreachable for some downstream tooling that used `@PublicPage` | Low | The `occ chorestrike:test-data:seed` command is the canonical, authenticated seed path (used by `docker/seed-test-data.sh`). Tooling should call `occ`, not the public HTTP API. Flagged in `testing.md`. |
| Seed route was `GET` that mutates DB (`/test/seed/{profile}`) | Low (pre-existing) | **Resolved (code-review F3):** `seed`/`reset` routes changed `GET`→`POST` (`routes.php:147-148`); `profiles` stays `GET` (read). Eliminates the GET-mutation / CSRF-via-GET smell; docs (`api/README.md`, `testing.md`) aligned. |
| Mock-based controller tests don't exercise the real `@PublicPage` removal | Medium | Covered by `bugs.md` [P2] (CI runs no controller tests). **Resolved (code-review F2):** real-DB check in Docker confirms unauthenticated `GET profiles` / `POST seed` / `POST reset` → **401 ×3** (auth layer rejects before the controller). |
| **Decision D1**: keep HTTP routes (hardened) vs remove them entirely (seed only via `occ`) | — | **Default: keep + harden** (least disruptive, preserves `profiles` for tooling). Alternative: delete the 3 routes + the controller and seed exclusively via `occ` — smaller attack surface but a larger change. Raise at plan review; default proceeds unless the user chooses removal. |

## 8. Acceptance Criteria

- [x] `@PublicPage` removed from all three `TestController` methods (`seed`, `reset`,
      `profiles`); `@NoAdminRequired` only remains.
- [x] `profiles()` is guarded by `isTestMode()` and no longer leaks the `testMode`
      flag / endpoint data outside test mode.
- [x] `TestDataService::isTestMode()` no longer returns `true` on a bare `CI` env var;
      it requires the explicit `NEXTCLOUD_TEST_MODE` flag.
- [x] Regression test confirms `CI=true` alone → `isTestMode()` is `false`; and an
      unauthenticated request to `seed`/`reset` is rejected (403/404/redirect).
- [x] The **local Docker** test environment still seeds correctly (`occ` path,
      `NEXTCLOUD_TEST_MODE=true`) after the change.
- [x] CI `test.yml` seeds via the authenticated `occ` command; `NEXTCLOUD_TEST_MODE=true`
      remains on the Nextcloud service container (`test.yml:84`, already present) and
      the `|| true`/`continue-on-error: true` masking is removed (no dependency on
      `@PublicPage` or the `CI` env var).
- [x] `docs/engineering/testing.md` documents that test mode is `NEXTCLOUD_TEST_MODE`
      only and that `occ chorestrike:test-data:seed` is the canonical seed path.
- [x] Build gate green: `npm test` + `npm run lint` + PHPUnit unit + integration
      (Docker) + `php -l` clean on touched files.
- [x] `docs/backlog/bugs.md` [P1] item closed; `docs/backlog/ACTIVE_TASKS.md` updated.

## Review Outcome (2026-07-29)

**Tier:** Standard Review — Type A (Plan), pre `new/` → `active/`.
**Verdict:** GO-WITH-CHANGES (see report). D1 is the user decision blocking the
final call.

**Verified against source (claims accurate):** `@PublicPage` on
`seed`/`reset`/`profiles` (`TestController.php:37,93,132`); `profiles()` unguarded
+ leaks `testMode` (`:135,:163`); `isTestMode()` `getenv('CI')` fallback
(`TestDataService.php:418`); routes `appinfo/routes.php:146-148`; CI seed curl
unauthenticated + `|| true` + `continue-on-error: true` (`test.yml:129-130`);
`bugs.md` [P1] entry (`L24`); no active-plan overlap.

**Fixed inline in this plan (doc edits, no quality gate):**
- **Factual correction:** the plan claimed `NEXTCLOUD_TEST_MODE=true` must be
  *added* to the CI Nextcloud container — it is **already set** (`test.yml:84`).
  Corrected in §2, §3, §6 step 3, §5 manifest, §8 acceptance. The real CI fix is
  replacing the unauth seed curl + removing the `|| true` masking.
- **Added missing consideration:** `docker/seed-test-data.sh:28-40` has an
  unauthenticated API fallback that 403s after `@PublicPage` removal. Added a risk
  row + manifest line to remove it (occ = single seed path).
- Risk/acceptance wording aligned with the corrected CI change.

**Not edited (architectural/scope → user decision):**
- **D1 (user question "ist das ein wirklich risiko, das so zu lassen?"):** see the
  review report. Reviewer recommends **Option B (remove the HTTP routes entirely,
  seed via `occ` only)** as the security-optimal + KISS-aligned choice; the plan's
  default "keep + harden" is an acceptable low-but-nonzero residual risk *if*
  `NEXTCLOUD_TEST_MODE` is guaranteed never to be set on a shared/multi-user
  instance. Choice is security-relevant → user decides; do not pre-bake either.
- GET-mutates smell on `seed`/`reset` (`routes.php:147-148`) — already captured as
  a pre-existing follow-up; unaffected by D1.
- `@NoCSRFRequired` kept on now-session-authenticated endpoints — defensible for
  GET test routes; revisit only if D1 keeps the routes and they move to POST.

**Backlog:** none added (plan is the tracking artifact). No separate follow-up
identified beyond the already-noted GET-mutates smell.
**Commit:** not committed — awaiting user D1 decision.

## Implementation Outcome (2026-07-29)

**D1 decision:** Option A — keep + harden (user chose the plan default).

**Changes delivered (all 7 steps):**

1. **`isTestMode()` tightened** (`lib/Service/TestDataService.php:414-417`):
   dropped `getenv('CI') !== false`; now `getenv('NEXTCLOUD_TEST_MODE') === 'true'
   || isset($_ENV['NEXTCLOUD_TEST_MODE'])` only.
2. **`TestController` hardened** (`lib/Controller/TestController.php`):
   `@PublicPage` removed from `seed`/`reset`/`profiles`; `@NoAdminRequired` +
   `@NoCSRFRequired` remain. `profiles()` guarded by `isTestMode()`; `testMode`
   field removed from payload. Extracted `requireTestMode(): ?JSONResponse`
   helper (DRY — three identical 403 blocks → one).
3. **Unit tests updated** (`tests/unit/Service/TestDataServiceTest.php`):
   `testIsTestModeDetectsCI` inverted → `testIsTestModeIgnoresCIEnvVar`
   (`CI=true` alone → `false`); added `testIsTestModeDetectsTestModeViaEnvSuperglobal`
   (`$_ENV` branch).
4. **Integration tests updated** (`tests/integration/Controller/TestControllerTest.php`):
   `testProfilesIncludesTestModeStatus` → `testProfilesReturns403WhenNotInTestMode`;
   `testProfilesReturnsAvailableProfiles` asserts `testMode` key absent.
5. **CI fixed** (`.forgejo/workflows/test.yml`): unauthenticated seed curl replaced
   with `docker exec nextcloud su -s /bin/bash www-data -c "cd /var/www/html &&
   php occ chorestrike:test-data:seed --profile=..."`; `|| true` and
   `continue-on-error: true` removed. `NEXTCLOUD_TEST_MODE=true` verified present
   on the NC service container (L84, unchanged).
6. **Docker seed script** (`docker/seed-test-data.sh`): unauthenticated API fallback
   (L28-40) removed; `occ` is the single seed path.
7. **Docs updated:** `docs/engineering/testing.md` (test-mode = `NEXTCLOUD_TEST_MODE`
   only, `occ` = canonical seed path), `docs/api/README.md` (auth + test-mode
   requirement, `testMode` field removed from example), `docs/backlog/bugs.md`
   ([P1] closed with resolution), `docs/backlog/ACTIVE_TASKS.md` (entry added).

**Build gate:** `php -l` clean (4 files), Jest 579/579, ESLint 0 errors,
PHPUnit unit 481/483 (2 pre-existing `AppLoggerTest` errors, unrelated),
PHPUnit integration `TestControllerTest` 9/9.

**Not committed** — awaiting approval. `active/ → done/` move is manual (user).

## Code Review Outcome — Type B (post-implementation, 2026-07-29)

**Tier:** Standard Review — Type B (changed files only), with security scrutiny.
**Verdict:** **GO.** The implementation correctly closes all three P1 vectors; it
matches the plan. No blocking or major defects found in the diff. The one gating
item is an unverified Docker/CI run (see F2).

**Verified against source (all claims hold):**
- `@PublicPage` removed from `seed`/`reset`/`profiles`; `@NoAdminRequired` +
  `@NoCSRFRequired` remain (`TestController.php:37,86,118`). Grep confirms **no
  `@PublicPage` annotation** remains anywhere in `lib/` (only the doc warning at
  `:20`). Routes still registered in `appinfo/routes.php:146-148` (now auth-gated).
- `profiles()` guarded via `requireTestMode()` (`TestController.php:123`); `testMode`
  field removed from payload (`:154`). No frontend consumer of `/test/*` or
  `testMode` (grep `src/` → none), so removing the field is safe.
- `requireTestMode(): ?JSONResponse` helper extracted — DRY consolidation of the
  three identical 403 blocks; logic correct (null when in test mode, else 403).
- `isTestMode()` dropped `getenv('CI')` (`TestDataService.php:414-417`).
- CI `occ` seed path is valid: `chorestrike:test-data:seed` is registered
  (`info.xml:51`), accepts `--profile` (`TestDataSeed.php:41-47`), and
  `NEXTCLOUD_TEST_MODE=true` is on the NC service container (`test.yml:84`) — so the
  command's own `isTestMode()` gate (`TestDataSeed.php:59`) passes. The
  `su www-data -c "..."` pattern (no `--login`) preserves the container env and
  matches the working local `seed-test-data.sh`.

**Findings:**
| # | Sev | File:line | Issue | Status |
|---|-----|-----------|-------|--------|
| F1 | minor (pre-existing, security-adjacent) | `TestDataService.php:416` | `isset($_ENV['NEXTCLOUD_TEST_MODE'])` was true for **any** value (incl. `'false'`/`'0'`), unlike the `getenv` term which requires exactly `'true'`. | **Fixed.** `($_ENV['NEXTCLOUD_TEST_MODE'] ?? '') === 'true'` — both paths now require `'true'`. Locked by new `testIsTestModeIgnoresNonTrueEnvSuperglobalValue`. |
| F2 | major (process) | gate / plan §8 | PHPUnit integration + the real occ-seed path were unverified; mock-based `TestControllerTest` does not exercise the `@PublicPage` removal. | **Verified in Docker.** PHPUnit unit 482/484 (2 pre-existing `AppLoggerTest` errors), `TestControllerTest` 9/9; unauthenticated `GET profiles` / `POST seed` / `POST reset` → **401 ×3**; `occ …:test-data:seed --profile=minimal` succeeds (isTestMode true). |
| F3 | nit (pre-existing) | `routes.php:147-148` | `seed`/`reset` were **GET** verbs that mutate the DB (CSRF-via-GET surface); docs were inconsistent (testing.md said POST, route was GET). | **Fixed.** Routes → `POST`; `docs/api/README.md` + `docs/engineering/testing.md` aligned. `profiles` stays GET (read). `@NoCSRFRequired` kept (machine-to-machine test endpoints, `isTestMode()`-gated). No frontend/CI consumer of these HTTP verbs. |
| F4 | nit | `tests/unit/Service/TestDataServiceTest.php` | Env cleanup was per-test with no central `tearDown`. | **Fixed.** Added `tearDown()` clearing `CI` / `NEXTCLOUD_TEST_MODE` / `$_ENV`; removed now-redundant trailing cleanups (setup-clears retained for order-independence). |

**Resolution & re-verification (2026-07-29):** all four findings fixed and verified.
Quality gate: `php -l` clean (5 files); ESLint 0 errors; PHPUnit unit 482/484
(2 pre-existing `AppLoggerTest::construct` `ILogFactory` vs `ILogger` mock errors —
unrelated, pre-existing); `TestControllerTest` 9/9; unauthenticated access → 401 ×3.
**Commit:** not committed — awaiting user approval.
