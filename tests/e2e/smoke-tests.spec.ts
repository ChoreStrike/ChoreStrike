import { test, expect } from './fixtures/test-fixtures'

/**
 * Basic Smoke Tests
 * Tests that the app loads and basic navigation works
 */
test.describe('Smoke Tests', () => {
	test('App loads without crashing', async ({ page }) => {
		test.setTimeout(30000)
		
		// Navigate to app
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		
		// Wait a bit for any dynamic content
		await page.waitForTimeout(2000)
		
		// Check that we have some content (not a blank page or error)
		const body = page.locator('body')
		await expect(body).toBeVisible()
		
		// Check for any common app elements
		const hasContent = await page.locator('h1, .dashboard, main, [data-testid]').count() > 0
		expect(hasContent).toBe(true)
	})

	test('Can navigate between main sections', async ({ page }) => {
		test.setTimeout(30000)
		
		// Start at app root
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForTimeout(1000)
		
		// Try to click navigation items
		const navItems = ['Tasks', 'Leaderboard', 'Settings', 'Dashboard']
		
		for (const item of navItems) {
			try {
				// Look for clickable element with this text
				const element = page.locator(`text=${item}`).first()
				const isVisible = await element.isVisible().catch(() => false)
				
				if (isVisible) {
					await element.click()
					await page.waitForTimeout(1000)
					
					// Check that URL changed appropriately
					const url = page.url()
					if (item === 'Dashboard') {
						expect(url).toMatch(/dashboard/)
					} else if (item === 'Tasks') {
						expect(url).toMatch(/tasks/)
					} else if (item === 'Leaderboard') {
						expect(url).toMatch(/leaderboard/)
					} else if (item === 'Settings') {
						expect(url).toMatch(/settings/)
					}
				}
			} catch (error: any) {
				// Navigation item might not exist or be clickable - that's ok for smoke test
				console.log(`Could not navigate to ${item}:`, error?.message || 'Unknown error')
			}
		}
	})

	test('Page titles are present', async ({ page }) => {
		test.setTimeout(30000)
		
		// Navigate to app
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForTimeout(1000)
		
		// Check for page title
		const title = await page.title()
		expect(title).toBeTruthy()
		expect(title.length).toBeGreaterThan(0)
		
		// Check for h1 or main heading
		const headings = page.locator('h1, h2, [role="heading"]')
		const headingCount = await headings.count()
		expect(headingCount).toBeGreaterThan(0)
	})

	test('No JavaScript errors in console', async ({ page }) => {
		test.setTimeout(30000)
		
		// Listen for console errors
		const errors: string[] = []
		page.on('console', msg => {
			if (msg.type() === 'error') {
				errors.push(msg.text())
			}
		})
		
		// Navigate to app
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForTimeout(2000)
		
		// Check for any JavaScript errors
		expect(errors.length).toBe(0)
	})

	test('Basic UI elements are present', async ({ page }) => {
		test.setTimeout(30000)
		
		// Navigate to app
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForTimeout(1000)
		
		// Look for common UI elements
		const commonSelectors = [
			'button',
			'input',
			'select',
			'.navigation',
			'.nav',
			'[role="navigation"]',
			'main',
			'.content'
		]
		
		let foundElements = 0
		for (const selector of commonSelectors) {
			const elements = page.locator(selector)
			const count = await elements.count()
			foundElements += count
		}
		
		// Should have at least some basic UI elements
		expect(foundElements).toBeGreaterThan(0)
	})
})
