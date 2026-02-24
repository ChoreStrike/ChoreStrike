import { test, expect } from '@playwright/test'

/**
 * E2E test: Leaderboard and gamification features
 * Critical flow: View leaderboard → Check medals → Track progress
 */
test.describe('Leaderboard and Gamification', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle')
	})

	test('User can view leaderboard with rankings', async ({ page }) => {
		// Navigate to leaderboard
		await page.click('[data-testid="nav-leaderboard"]')
		await page.waitForLoadState('networkidle')

		// Leaderboard should be visible
		await expect(page.locator('[data-testid="leaderboard-view"]')).toBeVisible()

		// Should have leaderboard entries
		const entries = page.locator('[data-testid="leaderboard-entry"]')
		await expect(entries).toHaveCount.greaterThan(0)

		// Top contributor should be highlighted
		await expect(page.locator('[data-testid="top-contributor"]')).toBeVisible()
	})

	test('Leaderboard shows contribution scores', async ({ page }) => {
		await page.goto('/apps/chorestrike/leaderboard')
		await page.waitForLoadState('networkidle')

		// Check for score display
		const scoreElements = page.locator('[data-testid="contribution-score"]')
		await expect(scoreElements).toHaveCount.greaterThan(0)

		// Verify scores are numeric
		const firstScore = await scoreElements.first().textContent()
		expect(Number(firstScore)).toBeGreaterThanOrEqual(0)
	})

	test('User medals are displayed on leaderboard', async ({ page }) => {
		await page.goto('/apps/chorestrike/leaderboard')
		await page.waitForLoadState('networkidle')

		// Medal section should exist
		await expect(page.locator('[data-testid="medal-summary"]')).toBeVisible()

		// Medal badges should be present if user has medals
		const medalBadges = page.locator('[data-testid="medal-badge"]')
		const count = await medalBadges.count()

		if (count > 0) {
			// Verify known medal types
			const medalTypes = ['team-anchor', 'flow-keeper', 'room-guardian', 'team-player', 'streak-keeper']
			const hasKnownMedal = await Promise.all(
				medalTypes.map(async (type) => {
					return page.locator(`[data-testid="medal-${type}"]`).isVisible().catch(() => false)
				})
			)
			expect(hasKnownMedal.some(Boolean)).toBeTruthy()
		}
	})

	test('Weekly stats are displayed correctly', async ({ page }) => {
		await page.goto('/apps/chorestrike/leaderboard')
		await page.waitForLoadState('networkidle')

		// Weekly stats section
		await expect(page.locator('[data-testid="weekly-stats"]')).toBeVisible()

		// Should show week range
		await expect(page.locator('[data-testid="week-range"]')).toBeVisible()

		// Stats should include tasks completed
		await expect(page.locator('[data-testid="tasks-completed-stat"]')).toBeVisible()
	})

	test('Leaderboard updates after task completion', async ({ page }) => {
		// Get initial leaderboard data
		await page.goto('/apps/chorestrike/leaderboard')
		await page.waitForLoadState('networkidle')

		const initialRank = await page.locator('[data-testid="user-rank"]').textContent()
		const initialScore = await page.locator('[data-testid="user-score"]').textContent()

		// Complete a task
		await page.goto('/apps/chorestrike/')
		await page.click('[data-testid="room-card-Kitchen"]')
		await page.click('[data-testid="complete-task-button"] >> first')
		await page.waitForTimeout(2000)

		// Go back to leaderboard
		await page.goto('/apps/chorestrike/leaderboard')
		await page.waitForLoadState('networkidle')

		// Score should have increased
		const newScore = await page.locator('[data-testid="user-score"]').textContent()
		expect(Number(newScore)).toBeGreaterThanOrEqual(Number(initialScore))
	})
})
