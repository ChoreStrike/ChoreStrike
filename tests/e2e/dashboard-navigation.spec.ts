import { test, expect } from '@playwright/test'

/**
 * E2E test: Dashboard navigation and display
 * Tests house health bar, quick stats, floor list, and navigation to rooms
 */
test.describe('Dashboard', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('domcontentloaded')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })
	})

	test('Dashboard displays house name and health bar', async ({ page }) => {
		// Verify dashboard view is visible
		await expect(page.locator('[data-testid="dashboard-view"]')).toBeVisible()

		// Verify house name is displayed
		const houseName = page.locator('[data-testid="house-name"]')
		await expect(houseName).toBeVisible()
		await expect(houseName).not.toHaveText('')

		// Verify house health bar is displayed
		await expect(page.locator('[data-testid="house-health"]')).toBeVisible()
	})

	test('Dashboard displays quick stats section', async ({ page }) => {
		// Verify quick stats component is visible
		await expect(page.locator('[data-testid="quick-stats"]')).toBeVisible()
	})

	test('Dashboard displays floor list', async ({ page }) => {
		// Verify floor list component is visible
		await expect(page.locator('[data-testid="floor-list"]')).toBeVisible()
	})

	test('User can navigate to room detail from dashboard', async ({ page }) => {
		// Find a room card in the floor list
		const roomCard = page.locator('.room-card').first()

		// If there are rooms, click on one
		if (await roomCard.count() > 0) {
			const roomName = await roomCard.locator('.room-name').textContent()
			await roomCard.click()

			// Verify navigation to room detail
			await page.waitForLoadState('networkidle')
			await expect(page.locator('[data-testid="room-detail-view"]')).toBeVisible()

			// Verify room name is displayed
			const detailRoomName = page.locator('[data-testid="room-name"]')
			await expect(detailRoomName).toContainText(roomName || '')
		}
	})

	test('User can navigate to all main views from dashboard', async ({ page }) => {
		await page.click('[data-testid="nav-tasks"]')
		await page.waitForLoadState('networkidle')
		await expect(page.locator('[data-testid="task-manager-view"]')).toBeVisible()

		await page.click('[data-testid="nav-leaderboard"]')
		await page.waitForLoadState('networkidle')
		await expect(page.locator('[data-testid="leaderboard-view"]')).toBeVisible()

		await page.click('[data-testid="nav-settings"]')
		await page.waitForLoadState('networkidle')
		await expect(page.locator('[data-testid="settings-view"]')).toBeVisible()

		await page.click('[data-testid="nav-dashboard"]')
		await page.waitForLoadState('networkidle')
		await expect(page.locator('[data-testid="dashboard-view"]')).toBeVisible()
	})
})
