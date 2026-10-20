import { test, expect } from '@playwright/test'

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
		await page.goto('/apps/chorestrike/#/dashboard')
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

		await page.getByTestId('nav-tasks').click()
		await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 15000 })

		await page.getByTestId('nav-leaderboard').click()
		await expect(page.getByTestId('leaderboard-view')).toBeVisible({ timeout: 15000 })

		await page.getByTestId('nav-settings').click()
		await expect(page.getByTestId('settings-view')).toBeVisible({ timeout: 15000 })

		await page.getByTestId('nav-dashboard').click()
		await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 15000 })
	})

	test('Private filter chip scopes the task list to private tasks (CS-PLAN-041)', async ({ page }) => {
		test.setTimeout(90000)

		// Seed one public and one private task so the filter outcome is
		// deterministic regardless of prior seed state.
		await page.goto('/apps/chorestrike/#/tasks')
		await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 20000 })

		const createTask = async (name: string, visibility: string) => {
			await page.getByTestId('add-task-button').click()
			await expect(page.getByTestId('task-name-input')).toBeVisible({ timeout: 15000 })
			await page.getByTestId('task-name-input').fill(name)
			await page.getByTestId('task-visibility-select').selectOption(visibility)

			const roomSelect = page.getByTestId('room-picker-room')
			const roomOption = await roomSelect.locator('option').nth(1).getAttribute('value')
			if (!roomOption) {
				await page.getByTestId('modal').getByTestId('modal-close').click()
				return false
			}
			await roomSelect.selectOption(roomOption)

			const createResponse = page.waitForResponse(
				(response) => response.request().method() === 'POST' && response.url().includes('/api/v1/room-tasks'),
				{ timeout: 15000 },
			)
			await page.getByTestId('save-task-button').click()
			expect((await createResponse).ok()).toBeTruthy()
			await expect(page.getByTestId('task-name-input')).toHaveCount(0, { timeout: 15000 })
			return true
		}

		if (!await createTask('E2E Public Filter Task', 'public')) {
			return
		}
		if (!await createTask('E2E Private Filter Task', 'private')) {
			return
		}

		// The chip on the Dashboard routes to the pre-filtered task list.
		await page.goto('/apps/chorestrike/#/dashboard')
		await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 20000 })
		await page.getByTestId('private-filter-toggle').click()

		await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('visibility-filter')).toHaveValue('private')

		await expect(page.getByText('E2E Private Filter Task')).toBeVisible({ timeout: 15000 })
		await expect(page.getByText('E2E Public Filter Task')).toHaveCount(0)
	})
})
