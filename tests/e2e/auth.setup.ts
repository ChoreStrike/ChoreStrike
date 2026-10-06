import { test as setup, expect, type Page } from '@playwright/test'
import { PROFILE_USERS, type DatabaseProfile } from './fixtures/database-fixtures'
import { userStatePath, AUTH_DIR } from './fixtures/auth-contexts'
import { assertTestTarget } from '../../scripts/test-target'

/**
 * Playwright auth setup (CS-PLAN-015b Phase 1)
 *
 * Logs every account in once and saves the browser context (`storageState`)
 * so browser projects can reuse authenticated sessions instead of
 * re-submitting the login form on every spec.
 *
 * - `admin` login is REQUIRED: it is the default session every browser project
 *   starts from (`storageState: userStatePath('admin')` in the
 *   config).
 * - Profile users (mom/dad/teen/kid/grandma/friend/adult) are logged in once
 *   each; specs consume them via `test.use({ storageState })` or
 *   `createUserContext(browser, userId)`.
 *
 * Accounts survive re-seeding: `TestDataService::reset()` truncates only
 * `chorestrike_*` tables, never `oc_users` (verified at source level).
 */

// The three setup tests build on each other (admin session → seed → profile
// logins); keep them ordered even under the global fullyParallel config.
setup.describe.configure({ mode: 'serial' })

const ADMIN_USER = process.env.NEXTCLOUD_USER || 'admin'
const ADMIN_PASS = process.env.NEXTCLOUD_PASSWORD || 'admin'

/**
 * Basic-auth header for the disposable test instance. The test endpoints
 * require an authenticated session (CS-PLAN-019 removed `@PublicPage`), and
 * Basic auth also satisfies the CSRF gate that rejects cookie-session
 * POSTs — so the unauthenticated probes used before no longer reach them.
 */
function adminAuthHeader(): Record<string, string> {
	const token = Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')
	return { Authorization: `Basic ${token}` }
}

/**
 * Submit the Nextcloud login form and persist the authenticated context.
 * If the browser is already authenticated, /login redirects to /apps and the
 * form step is skipped.
 */
async function loginAndSave(page: Page, user: string, password: string, statePath: string): Promise<void> {
	await page.goto('/login')
	await page.waitForLoadState('domcontentloaded')

	if (!page.url().includes('/apps/')) {
		await page.locator('#user, input[name="user"]').first().fill(user)
		await page.locator('#password, input[name="password"]').first().fill(password)
		await page.locator('button[type="submit"], #submit-form, input[type="submit"]').first().click()
		await page.waitForURL('**/apps/**', { timeout: 30000 })
	}

	await page.context().storageState({ path: statePath })
}

/** Seed a profile so its user accounts exist before specs run. */
async function seedProfile(baseURL: string, profile: DatabaseProfile): Promise<void> {
	const { url: origin } = assertTestTarget(baseURL)
	const response = await fetch(`${origin}/apps/chorestrike/api/v1/test/seed/${profile}`, {
		method: 'POST',
		headers: adminAuthHeader(),
	})
	expect(response.status, `required '${profile}' seed response`).toBe(200)
	expect((await response.json()).success).toBe(true)
}

setup('authenticate as admin', async ({ page, baseURL }) => {
	assertTestTarget(baseURL || process.env.NEXTCLOUD_URL || 'http://localhost:8080')
	await loginAndSave(page, ADMIN_USER, ADMIN_PASS, `${AUTH_DIR}/admin.json`)
})

setup('seed profile users', async ({ baseURL }) => {
	const origin = baseURL || process.env.NEXTCLOUD_URL || 'http://localhost:8080'

	const response = await fetch(`${origin.replace(/\/$/, '')}/apps/chorestrike/api/v1/test/profiles`, {
		headers: adminAuthHeader(),
	})
	expect(response.status, 'test mode must be enabled').toBe(200)

	// Seed the `family` profile unconditionally: it owns the NC accounts
	// (mom/dad/teen/kid/grandma/friend) that every spec authenticates below.
	// `oc_users` survive `TestDataService::reset()` (app tables only), so a
	// single family seed provides the identities for the whole run. Auth is
	// decoupled from `TEST_DB_PROFILE` — the matrix profile only shapes the
	// per-test seed DATA (re-seeded by the `seededData` fixture), never which
	// users can log in.
	await seedProfile(origin, 'family')
})

/**
 * Log in every profile user once and save per-user storageState files.
 * Each user gets its OWN browser context so a prior login cannot leak into
 * the next iteration (a shared context would bounce /login to /apps and save
 * the first user's session for everyone). The seed step above guarantees the
 * NC accounts exist.
 */
setup('authenticate profile users', async ({ browser, baseURL }) => {
	const origin = baseURL || process.env.NEXTCLOUD_URL || 'http://localhost:8080'

	const response = await fetch(`${origin.replace(/\/$/, '')}/apps/chorestrike/api/v1/test/profiles`, {
		headers: adminAuthHeader(),
	})
	expect(response.status, 'test mode must be enabled').toBe(200)

	// Authenticate the `family` users regardless of `TEST_DB_PROFILE`. All
	// specs request these identities via `createUserContext` /
	// `test.use({ storageState })` (34 call sites, none use the `minimal`-only
	// `adult`), so coupling login to the matrix profile would break every
	// family-pinning spec under the CI `empty`/`minimal` rows. The family seed
	// above guarantees the NC accounts exist for these logins. `baseURL` is
	// passed to `newContext` so the relative `/login` in `loginAndSave`
	// resolves even on a manually-created context.
	for (const user of PROFILE_USERS.family) {
		const context = await browser.newContext({ baseURL: origin })
		const page = await context.newPage()
		try {
			await loginAndSave(page, user.userId, user.password, userStatePath(user.userId))
		} finally {
			await context.close()
		}
	}
})
