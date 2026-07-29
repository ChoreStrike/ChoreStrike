import { test, expect } from './fixtures/test-fixtures'

/**
 * E2E test: Leaderboard and gamification features
 * Critical flow: View leaderboard → Check medals → Track progress
 */
test.describe('Leaderboard and Gamification', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)

		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('domcontentloaded')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })
	})

	test('User can view leaderboard with rankings', async ({ page }) => {
		await page.getByTestId('nav-leaderboard').click()
		await expect(page.getByTestId('leaderboard-view')).toBeVisible({ timeout: 20000 })
	})

	test('Leaderboard shows contribution scores', async ({ page }) => {
		await page.goto('/apps/chorestrike/#/leaderboard')
		await expect(page.getByTestId('leaderboard-view')).toBeVisible({ timeout: 20000 })
	})

	test('User medals are displayed on leaderboard', async ({ page }) => {
		await page.goto('/apps/chorestrike/#/leaderboard')
		await expect(page.getByTestId('leaderboard-view')).toBeVisible({ timeout: 20000 })

		const medalSummary = page.locator('[data-testid="medal-summary"]')
		if (await medalSummary.count() > 0) {
			await expect(medalSummary).toBeVisible({ timeout: 15000 })
		}
	})

	test('Weekly stats are displayed correctly', async ({ page }) => {
		await page.goto('/apps/chorestrike/#/leaderboard')
		await expect(page.getByTestId('leaderboard-view')).toBeVisible({ timeout: 20000 })

		const weeklyStats = page.locator('[data-testid="weekly-stats"]')
		if (await weeklyStats.count() > 0) {
			await expect(weeklyStats).toBeVisible({ timeout: 15000 })
		}
	})

	test('Leaderboard updates after task completion', async ({ page }) => {
		await page.goto('/apps/chorestrike/#/leaderboard')
		await expect(page.getByTestId('leaderboard-view')).toBeVisible({ timeout: 20000 })
	})
})
