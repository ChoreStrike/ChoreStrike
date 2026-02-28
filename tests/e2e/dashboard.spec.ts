import { test, expect } from './fixtures/test-fixtures'

/**
 * E2E test: User login and dashboard access
 * Critical flow: Authentication → Dashboard → Basic navigation
 */
test.describe('User Login and Dashboard', () => {
	test.beforeEach(async ({ page }) => {
		// Navigate to Nextcloud login
		await page.goto('/')
	})

	test('User can login and view dashboard', async ({ page }) => {
		// Increase timeout for this test
		test.setTimeout(90000)

		// Login form should be visible or user already logged in
		const loginForm = await page.locator('#login-form').isVisible().catch(() => false)

		if (loginForm) {
			// Perform login
			await page.fill('#user', process.env.NEXTCLOUD_USER || 'admin')
			await page.fill('#password', process.env.NEXTCLOUD_PASSWORD || 'admin')
			await page.click('#submit-form')

			// Wait for redirect with longer timeout
			await page.waitForLoadState('networkidle', { timeout: 30000 })
		}

		// Navigate to ChoreStrike app
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })

		// Wait for API data to load
		await page.waitForTimeout(2000)

		// Dashboard should load
		await expect(page.locator('[data-testid="dashboard-view"]')).toBeVisible({ timeout: 20000 })

		// House health widget should be present
		await expect(page.locator('[data-testid="house-health"]')).toBeVisible({ timeout: 15000 })

		// Floor list should be present
		await expect(page.locator('[data-testid="floor-list"]')).toBeVisible({ timeout: 15000 })
	})

	test('Dashboard shows quick stats', async ({ page }) => {
		test.setTimeout(60000)

		// Navigate to app (assuming already logged in)
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })

		// Wait for API data to load
		await page.waitForTimeout(2000)

		// Quick stats should be visible
		await expect(page.locator('[data-testid="quick-stats"]')).toBeVisible({ timeout: 20000 })

		// Stats should show meaningful data (at least 3 stat cards expected)
		const statCards = page.locator('[data-testid="stat-card"]')
		await expect(statCards).toHaveCount(3, { timeout: 15000 })
	})

	test('Navigation menu works correctly', async ({ page }) => {
		test.setTimeout(60000)

		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })

		// Wait for initial load
		await page.waitForTimeout(1000)

		// Click on Tasks tab using text selector (Nextcloud Vue components don't pass data-testid)
		await page.click('text=Tasks')
		await page.waitForTimeout(1000)
		await expect(page).toHaveURL(/.*tasks/)

		// Click on Leaderboard tab
		await page.click('text=Leaderboard')
		await page.waitForTimeout(1000)
		await expect(page).toHaveURL(/.*leaderboard/)

		// Click on Settings tab
		await page.click('text=Settings')
		await page.waitForTimeout(1000)
		await expect(page).toHaveURL(/.*settings/)

		// Back to Dashboard
		await page.click('text=Dashboard')
		await page.waitForTimeout(1000)
		await expect(page).toHaveURL(/.*dashboard/)
	})
})
