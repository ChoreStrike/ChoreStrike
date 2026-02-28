import { Page, TestInfo } from '@playwright/test'

/**
 * Browser-aware navigation helper
 * Handles different browser behaviors for more reliable tests
 */
export class BrowserAwareNavigation {
	static async navigateToApp(page: Page, testInfo?: TestInfo) {
		const browserName = testInfo?.project?.name || 'unknown'
		
		// Navigate to app
		await page.goto('/apps/chorestrike/')
		
		// Use different wait strategies based on browser
		if (browserName === 'firefox') {
			// Firefox is slower with networkidle, use domcontentloaded + timeout
			await page.waitForLoadState('domcontentloaded', { timeout: 45000 })
			await page.waitForTimeout(3000) // Extra wait for Firefox
		} else {
			// Chrome and WebKit work better with networkidle
			await page.waitForLoadState('networkidle', { timeout: 30000 })
			await page.waitForTimeout(2000)
		}
		
		// Wait for any dashboard content with fallback
		await this.waitForDashboardContent(page)
	}
	
	static async waitForDashboardContent(page: Page) {
		try {
			// Try specific dashboard selector first
			await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 15000 })
		} catch (error) {
			try {
				// Fallback to generic dashboard selectors
				await page.waitForSelector('.dashboard, .dashboard-view, main', { timeout: 10000 })
			} catch (fallbackError) {
				// Last resort - wait for any meaningful content
				await page.waitForSelector('h1, .content, [role="main"]', { timeout: 8000 })
			}
		}
	}
	
	static async waitForElementWithFallback(
		page: Page, 
		primarySelector: string, 
		fallbackSelectors: string[] = [], 
		timeout: number = 15000
	) {
		try {
			await page.waitForSelector(primarySelector, { timeout })
			return page.locator(primarySelector)
		} catch (error) {
			for (const fallback of fallbackSelectors) {
				try {
					await page.waitForSelector(fallback, { timeout: 5000 })
					return page.locator(fallback)
				} catch (fallbackError) {
					continue
				}
			}
			throw new Error(`None of the selectors were found: ${primarySelector}, ${fallbackSelectors.join(', ')}`)
		}
	}
	
	static async safeClick(page: Page, selector: string, fallbackSelectors: string[] = []) {
		const element = await this.waitForElementWithFallback(page, selector, fallbackSelectors)
		await element.click()
		await page.waitForTimeout(500) // Brief pause after click
	}
	
	static async safeFill(page: Page, selector: string, value: string, fallbackSelectors: string[] = []) {
		const element = await this.waitForElementWithFallback(page, selector, fallbackSelectors)
		await element.fill(value)
	}
}
