import { type Browser, type BrowserContext, type Page } from '@playwright/test'

export const AUTH_DIR = '.playwright/.auth'

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
	const page = await context.newPage()
	return { context, page }
}
