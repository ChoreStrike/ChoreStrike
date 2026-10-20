import { expect, test } from '@playwright/test'

/**
 * E2E test: Dashboard navigation and display
 * Tests house health bar, quick stats, floor list, and navigation to rooms
 */
test.describe('Dashboard', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/apps/chorestrike/#/dashboard')
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
		// CS-PLAN-084 WP-C: the room tiles reveal every floor (they start
		// collapsed, which used to leave this test without a room card).
		await page.getByRole('button', { name: /Total Rooms/ }).click()
		await expect(page.getByTestId('floor-list')).toBeFocused()
		const roomCard = page.locator('.room-card').first()
		await expect(roomCard).toBeVisible()
		const roomName = (await roomCard.locator('.room-name').textContent())?.trim() ?? ''
		expect(roomName).not.toBe('')

		// A click anywhere on the card follows its stretched room link.
		await roomCard.click()
		await expect(page.getByTestId('room-detail-view')).toBeVisible()
		await expect(page.getByTestId('room-name')).toContainText(roomName)
	})

	test('Room cards open from the keyboard and the Due Today tile opens My Day (CS-PLAN-084 WP-C)', async ({ page }) => {
		await page.getByRole('button', { name: /Clean Rooms/ }).click()
		const roomLink = page.locator('[data-testid^="room-link-"]').first()
		await expect(roomLink).toBeVisible()
		const roomName = (await roomLink.textContent())?.trim() ?? ''
		await roomLink.focus()
		await page.keyboard.press('Enter')
		await expect(page.getByTestId('room-detail-view')).toBeVisible()
		await expect(page.getByTestId('room-name')).toContainText(roomName)

		await page.goto('/apps/chorestrike/#/dashboard')
		await page.locator('.stat-card.due-today').click()
		await expect(page.getByTestId('today-view')).toBeVisible()
	})

	test('The overdue tile target presets the household task list to overdue (CS-PLAN-084 WP-C)', async ({ page }) => {
		// The tile only renders while overdue work exists; its exact link is
		// pinned in Jest, this proves the router contract end to end.
		await page.goto('/apps/chorestrike/#/tasks?status=overdue&scope=all')
		await expect(page.getByTestId('task-manager-view')).toBeVisible()
		await expect(page.getByTestId('status-filter')).toHaveValue('overdue')
		await expect(page.getByTestId('tasks-filter-all')).toHaveAttribute('aria-pressed', 'true')
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
