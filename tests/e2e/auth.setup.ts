import { test as setup, type Page } from '@playwright/test'
import { PROFILE_USERS, type DatabaseProfile } from './fixtures/database-fixtures'
import { userStatePath, AUTH_DIR } from './fixtures/auth-contexts'

/**
 * Playwright auth setup (CS-PLAN-015b Phase 1)
 *
 * Logs every account in once and saves the browser context (`storageState`)
 * so browser projects can reuse authenticated sessions instead of
 * re-submitting the login form on every spec.
 *
 * - `admin` login is REQUIRED: it is the default session every browser project
 *   starts from (`storageState: '.playwright/.auth/admin.json'` in the config).
 * - Profile users (mom/dad/teen/kid/grandma/friend/adult) are logged in once
 *   each; specs consume them via `test.use({ storageState })` or
 *   `createUserContext(browser, userId)`.
 *
 * Accounts survive re-seeding: `TestDataService::reset()` truncates only
 * `chorestrike_*` tables, never `oc_users` (verified at source level).
 */

const ADMIN_USER = process.env.NEXTCLOUD_USER || 'admin'
const ADMIN_PASS = process.env.NEXTCLOUD_PASSWORD || 'admin'

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
	const ok = await fetch(`${baseURL.replace(/\/$/, '')}/apps/chorestrike/api/v1/test/seed/${profile}`)
		.then((r) => r.ok)
		.catch(() => false)
	if (!ok) {
		console.log(`[setup] Could not seed '${profile}' profile (test mode off?) — profile-user specs may skip.`)
	}
}

setup('authenticate as admin', async ({ page }) => {
	await loginAndSave(page, ADMIN_USER, ADMIN_PASS, `${AUTH_DIR}/admin.json`)
})

setup('seed profile users', async ({ baseURL }) => {
	const origin = baseURL || process.env.NEXTCLOUD_URL || 'http://localhost:8080'

	const testMode = await fetch(`${origin.replace(/\/$/, '')}/apps/chorestrike/api/v1/test/profiles`)
		.then((r) => r.ok)
		.catch(() => false)
	if (!testMode) {
		return
	}

	for (const profile of Object.keys(PROFILE_USERS) as DatabaseProfile[]) {
		try {
			await seedProfile(origin, profile)
		} catch {
			// Seeding best-effort — admin session remains the guaranteed fallback.
		}
	}
})

/**
 * Log in every profile user once and save per-user storageState files.
 * Each `setup()` test gets its own browser context, so the logins are
 * parallel-safe. The seed step above guarantees the NC accounts exist.
 */
setup('authenticate profile users', async ({ page, baseURL }) => {
	const origin = baseURL || process.env.NEXTCLOUD_URL || 'http://localhost:8080'

	const testMode = await fetch(`${origin.replace(/\/$/, '')}/apps/chorestrike/api/v1/test/profiles`)
		.then((r) => r.ok)
		.catch(() => false)
	if (!testMode) {
		return
	}

	const seen = new Set<string>()
	for (const profile of Object.keys(PROFILE_USERS) as DatabaseProfile[]) {
		for (const user of PROFILE_USERS[profile]) {
			if (seen.has(user.userId)) continue
			seen.add(user.userId)
			try {
				await loginAndSave(page, user.userId, user.password, userStatePath(user.userId))
			} catch {
				console.log(`[setup] Could not authenticate '${user.userId}' — specs using this user may skip.`)
			}
		}
	}
})
