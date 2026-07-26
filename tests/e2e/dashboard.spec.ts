import { test, expect } from './fixtures/test-fixtures'

/**
 * E2E test: User dashboard access and navigation
 * Critical flow: Dashboard → basic navigation
 *
 * CS-PLAN-015: auth handled by the `setup` project (storageState) — specs start
 * authenticated as admin. Nav clicks use `getByRole('link', { name })` instead
 * of fragile `text=` selectors; fixed sleeps replaced by auto-waiting.
 */
test.describe('User Login and Dashboard', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/apps/chorestrike/')
		await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 30000 })
	})

	test('User can view dashboard', async ({ page }) => {
		test.setTimeout(60000)

		await expect(page.getByTestId('house-health')).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('floor-list')).toBeVisible({ timeout: 15000 })
	})

	test('Dashboard shows quick stats', async ({ page }) => {
		test.setTimeout(60000)

		await expect(page.getByTestId('quick-stats')).toBeVisible({ timeout: 20000 })

		// At least 3 stat cards expected
		await expect(page.getByTestId('stat-card')).toHaveCount(3, { timeout: 15000 })
	})

	test('Navigation menu works correctly', async ({ page }) => {
		test.setTimeout(60000)

		await page.getByRole('link', { name: 'Tasks' }).click()
		await expect(page).toHaveURL(/.*tasks/)

		await page.getByRole('link', { name: 'Leaderboard' }).click()
		await expect(page).toHaveURL(/.*leaderboard/)

		await page.getByRole('link', { name: 'Settings' }).click()
		await expect(page).toHaveURL(/.*settings/)

		await page.getByRole('link', { name: 'Dashboard' }).click()
		await expect(page).toHaveURL(/.*dashboard/)
	})
})
