import { type Browser, type BrowserContext, type Page } from '@playwright/test'

export const AUTH_DIR = '.playwright/.auth'

const BASE_URL = process.env.NEXTCLOUD_URL || 'http://localhost:8080'

/**
 * Prime the context for calls to the app's regular (non-OCS) API routes.
 * Nextcloud rejects those without a CSRF `requesttoken` header (HTTP 412);
 * the frontend sets it globally (`src/main.js`), so E2E API requests must
 * too. Loads one authenticated page, reads `OC.requestToken` and registers
 * it as an extra header on the context.
 *
 * @param context - Context to attach the `requesttoken` header to.
 * @param page - Authenticated page used to read `window.OC.requestToken`.
 * @returns Resolves once the header is registered (no-op if no token).
 */
async function primeCsrfToken(context: BrowserContext, page: Page): Promise<void> {
	await page.goto(`${BASE_URL}/index.php/apps/dashboard/`)
	const token = await page.evaluate(
		() => (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken,
	)
	if (!token) {
		throw new Error(
			'primeCsrfToken: OC.requestToken not found after loading the dashboard. '
			+ 'The storageState may be expired or the Nextcloud instance is unreachable.',
		)
	}
	await context.setExtraHTTPHeaders({ requesttoken: token })
}

/**
 * Path to the saved storageState file for a given user.
 * Created once by `auth.setup.ts` and reused by every browser project.
 *
 * @param userId - Profile user id (e.g. `admin`, `mom`, `teen`).
 * @returns The storageState file path under `AUTH_DIR`.
 */
export function userStatePath(userId: string): string {
	return `${AUTH_DIR}/${userId}.json`
}

/**
 * Create a fresh browser context authenticated as `userId` from the saved
 * storageState. The caller owns the returned context and must close it
 * (try/finally) or use the fixture-managed variant with auto-cleanup.
 *
 * In-tree precedent: `multi-user-interactions.spec.ts:195` already uses
 * `browser.newContext()` for two-user flows.
 *
 * The returned page has loaded the dashboard once so the context carries the
 * CSRF `requesttoken` header required by the app's API routes.
 *
 * @param browser - Playwright browser to create the context on.
 * @param userId - Profile user id whose storageState should be loaded.
 * @returns The owned `{ context, page }`; close `context` when done.
 */
export async function createUserContext(
	browser: Browser,
	userId: string,
): Promise<{ context: BrowserContext; page: Page }> {
	const context = await browser.newContext({
		storageState: userStatePath(userId),
	})
	let page: Page
	try {
		page = await context.newPage()
		await primeCsrfToken(context, page)
	} catch (err) {
		await context.close()
		throw err
	}
	return { context, page }
}
