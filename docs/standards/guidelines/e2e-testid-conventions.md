# E2E Stable Selector & Testid Conventions

> How to keep the Playwright E2E suite (chromium + firefox + webkit) fast and
> flake-free. Read this before adding or modifying any `tests/e2e/*.spec.ts`.
> Related: [testing.md](./testing.md) (strategy), [engineering/testing.md](../../engineering/testing.md) (env + commands).

---

## 1. Selector priority — prefer semantics, fall back to testids

Use Playwright's **semantic locators** first; they survive markup refactors and
double as an a11y check. Reach for `data-testid` only when no stable role/label
exists (e.g. an icon-only button, a non-interactive container, a list item).

1. **`getByRole`** — buttons, links, textboxes, checkboxes, tabs.
   ```ts
   await page.getByRole('link', { name: 'Tasks' }).click()
   await page.getByRole('button', { name: /complete/i }).click()
   ```
2. **`getByLabel` / `getByPlaceholder`** — form fields.
   ```ts
   await page.getByLabel('Task name').fill('Vacuum')
   ```
3. **`getByTestId`** — the last resort, but the contract for anything without a
   reliable accessible name.
   ```ts
   await page.getByTestId('complete-task-button').click()
   ```

**Never** use text-only selectors (`text=Tasks`, `button:has-text("Complete")`)
or positional CSS (`.tab-btn:nth-child(2)`) in E2E specs. They are the leading
cause of flakes: they break on copy changes, i18n, and reordering.

## 2. Testid naming

- Pattern: **`{view}-{element}`** — e.g. `dashboard-view`, `task-manager-view`,
  `complete-task-button`, `add-floor-button`, `nav-tasks`.
- **Dynamic suffixes are allowed and encouraged** for repeated structures:
  `task-item`, `floor-section-Keller`, `room-card-Kitchen`. Keep the static stem
  stable so `getByTestId('task-item')` (first match) and
  `page.locator('[data-testid^="room-card-"]')` (all) both work.
- kebab-case, lowercase, no spaces. Stable for the life of the element — treat a
  testid rename as a breaking change to E2E.

## 3. Every element an E2E touches needs a stable hook

If a spec interacts with or asserts on an element, that element **must** carry
either a semantic role/label **or** a `data-testid`. Adding a new E2E step
without a stable hook is a defect.

## 4. `NcAppNavigationItem` — the nav testid caveat

The Nextcloud `<NcAppNavigationItem>` component renders a single `<li>` root and
relies on Vue 3 attribute fallthrough (`inheritAttrs` default `true`) to surface
`data-testid` onto that `<li>`. Each nav entry in [`src/App.vue`](../../../src/App.vue)
declares a `nav-*` testid (`nav-dashboard`, `nav-tasks`, `nav-leaderboard`,
`nav-settings`).

- **Preferred selector for nav:** `page.getByRole('link', { name: 'Tasks' })` —
  the entry's visible text is its accessible name, so this is the most robust
  and refactor-proof choice.
- **Testid fallback:** `page.getByTestId('nav-tasks')` also works (it lands on
  the `<li>`), but prefer the role selector. A regression guard
  (`tests/unit/components/App.spec.js`) asserts the `nav-*` testids are declared
  so they are never silently removed.

## 5. No `waitForTimeout` — use auto-waiting

Fixed sleeps (`page.waitForTimeout(N)`) mask timing bugs and slow the suite.
Replace them with Playwright's auto-waiting:

- **Actionability:** locator actions (`click`, `fill`, …) already auto-wait.
- **Visibility/state:** `await expect(locator).toBeVisible()` / `toHaveText()`
  retry until the assertion holds or the test times out.
- **Navigation:** `await page.waitForURL(...)`, `waitForLoadState('networkidle')`
  (sparingly — prefer a visible-content assertion).
- **Element ready:** `await page.getByTestId(...).waitFor()`.

A `waitForTimeout` in a spec is a defect — fix the underlying race (a missing
loading state, an un-awaited request) instead of masking it.

## 6. Auth — per-user `storageState` (CS-PLAN-015b)

Every browser context starts authenticated via the `setup` project in
[`playwright.config.ts`](../../../playwright.config.ts) (see
[`tests/e2e/auth.setup.ts`](../../../tests/e2e/auth.setup.ts)). It logs in
**every** account once (admin + all seeded profile users) and saves the sessions
to `.playwright/.auth/{userId}.json` (gitignored). Accounts survive re-seeding
(`TestDataService::reset()` touches only `chorestrike_*` tables, never `oc_users`).

### Consumption patterns

1. **Admin (default):** the browser projects set
   `storageState: '.playwright/.auth/admin.json'` — specs that operate as admin
   need **no login call at all**. Just navigate to the app.

2. **Single-user describe:** when every test in a `describe` runs as the same
   profile user, set the storageState at describe level:
   ```ts
   import { userStatePath } from './fixtures/auth-contexts'
   test.describe('Teamwork Rewards', () => {
       test.use({ storageState: userStatePath('mom') })
       // …tests use the default `page` as mom
   })
   ```

3. **Per-test user / multi-user flows:** create an owned context via
   [`createUserContext`](../../../tests/e2e/fixtures/auth-contexts.ts):
   ```ts
   import { createUserContext } from './fixtures/auth-contexts'
   test('mom sees tasks', async ({ browser }) => {
       const { context, page } = await createUserContext(browser, 'mom')
       try {
           await page.goto('/apps/chorestrike/')
           // …assertions
       } finally {
           await context.close()
       }
   })
   ```
   For two simultaneous users, create two contexts (in-tree precedent:
   `multi-user-interactions.spec.ts`).

### No-logout rule

**No spec may navigate to or fetch `/logout`.** The shared admin session (and
every per-user session) is created once in the setup project and reused by all
parallel workers. A single logout invalidates the server-side session for every
spec on that worker pool. This is enforced by code review and a grep guard.

## 7. Checklist before committing an E2E change

- [ ] No new `waitForTimeout` / text-only / positional selectors.
- [ ] No navigation to or fetch of `/logout` (no-logout rule).
- [ ] Every newly-touched element has a role/label or `data-testid`.
- [ ] New testid added in the same change to the component that renders it.
- [ ] If a testid was added/renamed, the component spec asserts it renders.
- [ ] Spec passes locally with `--retries=0` on chromium + firefox + webkit.
