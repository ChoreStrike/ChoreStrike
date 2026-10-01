import { Page, TestInfo, Locator } from '@playwright/test'

/**
 * Browser-aware navigation helper.
 *
 * CS-PLAN-015: the previous implementation padded every navigation with
 * 2–5 s per-browser `waitForTimeout` calls to paper over render timing. Those
 * have been removed in favour of Playwright's auto-waiting (`waitForLoadState`
 * + a visible-content assertion). The public API is preserved so existing specs
 * keep compiling; new specs should prefer direct `getByRole`/`getByTestId`
 * locators over these wrappers.
 */
export class BrowserAwareNavigation {
	/**
	 * Navigate to the ChoreStrike app and wait for the dashboard shell.
	 * CS-PLAN-070 WP6: the bare root lands on My Day for every role — the
	 * dashboard shell is reached through its explicit secondary path.
	 */
	static async navigateToApp(page: Page, _testInfo?: TestInfo): Promise<void> {
		await page.goto('/apps/chorestrike/#/dashboard')
		await page.waitForLoadState('networkidle')
		await this.waitForDashboardContent(page)
	}

	/**
	 * Wait for the dashboard root testid (or a content fallback) to appear.
	 */
	static async waitForDashboardContent(page: Page): Promise<void> {
		try {
			await page.getByTestId('dashboard-view').waitFor({ state: 'visible', timeout: 15000 })
		} catch {
			try {
				await page.locator('.dashboard, .dashboard-view, main').first().waitFor({ state: 'visible', timeout: 10000 })
			} catch {
				await page.locator('h1, .content, [role="main"]').first().waitFor({ state: 'visible', timeout: 8000 })
			}
		}
	}

	/**
	 * Wait for the first selector that matches, trying fallbacks in order.
	 */
	static async waitForElementWithFallback(
		page: Page,
		primarySelector: string,
		fallbackSelectors: string[] = [],
		timeout: number = 15000,
	): Promise<Locator> {
		try {
			await page.waitForSelector(primarySelector, { timeout })
			return page.locator(primarySelector).first()
		} catch {
			for (const fallback of fallbackSelectors) {
				try {
					await page.waitForSelector(fallback, { timeout: 5000 })
					return page.locator(fallback).first()
				} catch {
					continue
				}
			}
			throw new Error(`None of the selectors were found: ${primarySelector}, ${fallbackSelectors.join(', ')}`)
		}
	}

	/**
	 * Fill an element, trying fallback selectors.
	 */
	static async safeFill(page: Page, selector: string, value: string, fallbackSelectors: string[] = []): Promise<void> {
		const element = await this.waitForElementWithFallback(page, selector, fallbackSelectors)
		await element.fill(value)
	}
}
