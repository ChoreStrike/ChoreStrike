import { test, expect } from '@playwright/test'

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
		// Login form should be visible or user already logged in
		const loginForm = await page.locator('#login-form').isVisible().catch(() => false)

		if (loginForm) {
			// Perform login
			await page.fill('#user', process.env.NEXTCLOUD_USER || 'admin')
			await page.fill('#password', process.env.NEXTCLOUD_PASSWORD || 'admin')
			await page.click('#submit-form')

			// Wait for redirect
			await page.waitForLoadState('networkidle')
		}

		// Navigate to ChoreStrike app
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle')

		// Dashboard should load
		await expect(page.locator('[data-testid="dashboard-view"]')).toBeVisible()

		// House health widget should be present
		await expect(page.locator('[data-testid="house-health"]')).toBeVisible()

		// Floor list should be present
		await expect(page.locator('[data-testid="floor-list"]')).toBeVisible()
	})

	test('Dashboard shows quick stats', async ({ page }) => {
		// Navigate to app (assuming already logged in)
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle')

		// Quick stats should be visible
		await expect(page.locator('[data-testid="quick-stats"]')).toBeVisible()

		// Stats should show meaningful data
		const statCards = page.locator('[data-testid="stat-card"]')
		await expect(statCards).toHaveCount(3)
	})

	test('Navigation menu works correctly', async ({ page }) => {
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle')

		// Click on Tasks tab
		await page.click('[data-testid="nav-tasks"]')
		await expect(page).toHaveURL(/.*tasks/)

		// Click on Leaderboard tab
		await page.click('[data-testid="nav-leaderboard"]')
		await expect(page).toHaveURL(/.*leaderboard/)

		// Click on Settings tab
		await page.click('[data-testid="nav-settings"]')
		await expect(page).toHaveURL(/.*settings/)

		// Back to Dashboard
		await page.click('[data-testid="nav-dashboard"]')
		await expect(page).toHaveURL(/.*dashboard/)
	})
})
