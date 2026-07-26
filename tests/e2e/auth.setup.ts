import { test as setup, type Page } from '@playwright/test'
import { PROFILE_USERS, type DatabaseProfile } from './fixtures/database-fixtures'

/**
 * Playwright auth setup (CS-PLAN-015 Phase 1b)
 *
 * Logs the `admin` role into Nextcloud once and saves the browser context
 * (`storageState`) so the browser projects can reuse the authenticated session
 * instead of re-submitting the login form on every spec (~3–5 s saved per spec).
 *
 * The `admin` login is REQUIRED: it is the default session every browser project
 * starts from (`storageState: '.playwright/.auth/admin.json'` in the config). If
 * it fails, the dependent specs cannot run anyway.
 *
 * Seeded profile users (adult/kid/mom/…) are NOT logged in here: only `admin`
 * storageState is referenced by the projects, and profile-user specs authenticate
 * via `loginAsUser()`. The setup only *seeds* the profiles so those accounts
 * exist before any spec runs.
 */

const AUTH_DIR = '.playwright/.auth'
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

	// Already authenticated? Nextcloud bounces /login → /apps.
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

	// Only seed when the test endpoints are enabled.
	const testMode = await fetch(`${origin.replace(/\/$/, '')}/apps/chorestrike/api/v1/test/profiles`)
		.then((r) => r.ok)
		.catch(() => false)
	if (!testMode) {
		return
	}

	// Best-effort: create the profile accounts so loginAsUser() can authenticate
	// them. Per-spec seeding (the seededData fixture) still runs for specs that
	// need fresh data; this just guarantees the NC accounts exist up front.
	for (const profile of Object.keys(PROFILE_USERS) as DatabaseProfile[]) {
		try {
			await seedProfile(origin, profile)
		} catch {
			// Seeding best-effort — admin session remains the guaranteed fallback.
		}
	}
})

