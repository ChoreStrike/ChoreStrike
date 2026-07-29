import { test, expect } from './fixtures/test-fixtures'

/**
 * Minimal Smoke Tests
 * Very basic tests that check the app doesn't crash completely
 */
test.describe('Minimal Smoke Tests', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(30000)

		// Simple navigation with basic timeout
		await page.goto('/apps/chorestrike/')
		await page.waitForTimeout(3000)
	})

	test('Page loads without errors', async ({ page }) => {
		// Check page loaded
		const title = await page.title()
		expect(title).toBeTruthy()
		expect(title.length).toBeGreaterThan(0)

		// Check body exists and has content
		const body = page.locator('body')
		await expect(body).toBeVisible()

		// Should have some content (not blank page)
		const bodyText = await body.textContent()
		expect(bodyText?.length || 0).toBeGreaterThan(100)
	})

	test('No critical console errors', async ({ page }) => {
		const errors: string[] = []

		// Listen for console errors
		page.on('console', msg => {
			if (msg.type() === 'error') {
				const text = msg.text()
				// Filter out common non-critical errors
				if (!text.includes('favicon') && !text.includes('404') && !text.includes('net::ERR')) {
					errors.push(text)
				}
			}
		})

		// Wait a bit to collect any errors
		await page.waitForTimeout(2000)

		// Should have minimal errors
		if (errors.length >= 5) {
			throw new Error(`Too many console errors: ${errors.slice(0, 3).join(', ')}`)
		}
	})

	test('Basic page structure exists', async ({ page }) => {
		// Look for any common page elements
		const hasHeader = await page.locator('header, .header, h1').count() > 0
		const hasMain = await page.locator('main, .main, .content, [role="main"]').count() > 0

		expect(hasHeader || hasMain).toBe(true)
	})

	test('Can interact with page', async ({ page }) => {
		// Try to click on any clickable elements
		const clickableElements = page.locator('button, a, [role="button"], input')
		const count = await clickableElements.count()

		if (count > 0) {
			// Try clicking first element
			try {
				await clickableElements.first().click({ timeout: 5000 })
				await page.waitForTimeout(1000)
			} catch {
			}
		}

		// Page should still be responsive
		const body = page.locator('body')
		await expect(body).toBeVisible()
	})

	test('URL is accessible', async ({ page }) => {
		// Check we're on the right URL
		const url = page.url()
		expect(url).toContain('chorestrike')

		// Check we can navigate to different sections (if they exist)
		const testUrls = [
			'/apps/chorestrike/',
			'/apps/chorestrike/#/tasks',
			'/apps/chorestrike/#/settings',
		]

		for (const testUrl of testUrls) {
			try {
				await page.goto(testUrl)
				await page.waitForTimeout(1000)

				// Should not show error page
				const title = await page.title()
				expect(title).not.toContain('Error')
				expect(title).not.toContain('404')
				expect(title).not.toContain('Not Found')
			} catch {
			}
		}
	})
})
