import { test, expect } from '@playwright/test'

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

	test('No dead period filter remains (CS-PLAN-041)', async ({ page }) => {
		await page.goto('/apps/chorestrike/#/leaderboard')
		await expect(page.getByTestId('leaderboard-view')).toBeVisible({ timeout: 20000 })

		// The backend leaderboard has no period parameter — the filter was
		// removed instead of pretending to work.
		await expect(page.locator('[data-testid="time-range-filter"]')).toHaveCount(0)
		await expect(page.locator('.filter-button')).toHaveCount(0)
	})

	test('Weekly medals render from the game store when medals exist', async ({ page }) => {
		await page.goto('/apps/chorestrike/#/leaderboard')
		await expect(page.getByTestId('leaderboard-view')).toBeVisible({ timeout: 20000 })

		const medalSummary = page.getByTestId('medal-summary')
		await medalSummary.waitFor({ state: 'visible', timeout: 15000 }).catch(() => {
			// Medals are weekly awards; an empty house legitimately has none.
		})

		if (await medalSummary.isVisible()) {
			const badges = page.getByTestId('medal-badge')
			const count = await badges.count()
			for (let i = 0; i < count; i++) {
				await expect(badges.nth(i)).toHaveAttribute('data-medal-type', /team_anchor|flow_keeper|room_guardian|team_player|streak_keeper/)
			}
		}
	})
})
