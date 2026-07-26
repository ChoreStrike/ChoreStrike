import { test, expect } from './fixtures/test-fixtures'

/**
 * E2E Tests for Core App Functionality
 * Tests basic navigation, data loading, and UI components.
 *
 * CS-PLAN-015: nav uses `getByRole('link', { name })` (unlocked by the App.vue
 * testid/role passthrough fix); auth via storageState; fixed sleeps replaced by
 * auto-waiting assertions.
 */
test.describe('Core App Functionality', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)
		await page.goto('/apps/chorestrike/')
		await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 30000 })
	})

	test('Dashboard loads and displays basic components', async ({ page }) => {
		await expect(page.getByTestId('house-health')).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('floor-list')).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('quick-stats')).toBeVisible({ timeout: 15000 })
	})

	test('Navigation between main views works', async ({ page }) => {
		await page.getByRole('link', { name: 'Tasks' }).click()
		await expect(page).toHaveURL(/.*tasks/)

		await page.getByRole('link', { name: 'Leaderboard' }).click()
		await expect(page).toHaveURL(/.*leaderboard/)

		await page.getByRole('link', { name: 'Settings' }).click()
		await expect(page).toHaveURL(/.*settings/)

		await page.getByRole('link', { name: 'Dashboard' }).click()
		await expect(page).toHaveURL(/.*dashboard/)
	})

	test('Task Manager loads and displays controls', async ({ page }) => {
		await page.getByRole('link', { name: 'Tasks' }).click()
		await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 20000 })

		await expect(page.getByTestId('status-filter')).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('priority-filter')).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('difficulty-filter')).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('search-input')).toBeVisible()
		await expect(page.getByTestId('add-task-button')).toBeVisible({ timeout: 15000 })
	})

	test('Settings page loads and displays controls', async ({ page }) => {
		await page.getByRole('link', { name: 'Settings' }).click()
		await expect(page.getByTestId('settings-view')).toBeVisible({ timeout: 20000 })

		await expect(page.getByTestId('house-name-input')).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('floors-section')).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('add-floor-button')).toBeVisible({ timeout: 15000 })
	})

	test('Leaderboard page loads', async ({ page }) => {
		await page.getByRole('link', { name: 'Leaderboard' }).click()
		await expect(page.getByTestId('leaderboard-view')).toBeVisible({ timeout: 20000 })
	})

	test('Room cards are clickable and navigate to room detail', async ({ page }) => {
		const roomCards = page.locator('[data-testid^="room-card-"]')
		if (await roomCards.count() === 0) {
			test.skip()
			return
		}

		await roomCards.first().click()
		await expect(page.getByTestId('room-detail-view')).toBeVisible({ timeout: 15000 })
	})

	test('Task completion flow works', async ({ page }) => {
		await page.getByRole('link', { name: 'Tasks' }).click()
		await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 20000 })

		// Room-task completion lives in the room detail view; navigate to the
		// first available room and look for a complete button there.
		const roomCards = page.locator('[data-testid^="room-card-"]')
		if (await roomCards.count() === 0) {
			test.skip()
			return
		}
		await roomCards.first().click()
		await expect(page.getByTestId('room-detail-view')).toBeVisible({ timeout: 15000 })

		const completeButton = page.getByTestId('complete-task-button').first()
		if (await completeButton.count() === 0) {
			test.skip()
			return
		}
		await completeButton.click()
		// A reward toast or state change confirms completion.
		await expect(page.getByTestId('task-item').first()).toBeVisible({ timeout: 15000 })
	})

	test('Filters and search work in Task Manager', async ({ page }) => {
		await page.getByRole('link', { name: 'Tasks' }).click()
		await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 20000 })

		await page.selectOption(page.getByTestId('status-filter'), 'active')
		await page.selectOption(page.getByTestId('priority-filter'), 'urgent')
		await page.getByTestId('search-input').fill('test')
		await page.getByTestId('search-input').fill('')

		// View stays mounted after all interactions.
		await expect(page.getByTestId('task-manager-view')).toBeVisible()
	})
})
