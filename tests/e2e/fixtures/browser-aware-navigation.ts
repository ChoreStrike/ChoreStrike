import type { Locator, Page, TestInfo } from '@playwright/test'

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
	 * Navigate to the ChoreStrike app and wait for Today, the landing page
	 * of every role (CS-PLAN-070 WP6, CS-PLAN-090 WP-B).
	 */
	static async navigateToApp(page: Page, _testInfo?: TestInfo): Promise<void> {
		await page.goto('/apps/chorestrike/#/today')
		await page.waitForLoadState('networkidle')
		await page.getByTestId('today-view').waitFor({ state: 'visible', timeout: 30000 })
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
