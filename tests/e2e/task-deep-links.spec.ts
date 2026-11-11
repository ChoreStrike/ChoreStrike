import { expect, test } from '@playwright/test'

/**
 * E2E test: the task list's address contract (`/tasks?…`).
 *
 * These links were the targets of the Dashboard's private-tasks chip and
 * overdue tile. The Dashboard merged into Today (CS-PLAN-090 WP-B); the
 * addresses stay, for bookmarks and for the views that link to them.
 *
 * CS-PLAN-015: auth handled by the `setup` project (storageState) — specs start
 * authenticated as admin.
 */
test.describe('Task list deep links', () => {
	test('The private link scopes the task list to private tasks (CS-PLAN-041)', async ({ page }) => {
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

		// Leave the list first, so the link is read on a fresh mount.
		await page.goto('/apps/chorestrike/#/today')
		await expect(page.getByTestId('today-view')).toBeVisible({ timeout: 20000 })
		await page.goto('/apps/chorestrike/#/tasks?visibility=private')

		await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('visibility-filter')).toHaveValue('private')

		await expect(page.getByText('E2E Private Filter Task')).toBeVisible({ timeout: 15000 })
		await expect(page.getByText('E2E Public Filter Task')).toHaveCount(0)
	})

	test('The overdue link presets the household task list to overdue (CS-PLAN-084 WP-C)', async ({ page }) => {
		await page.goto('/apps/chorestrike/#/tasks?status=overdue&scope=all')
		await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 20000 })
		await expect(page.getByTestId('status-filter')).toHaveValue('overdue')
		await expect(page.getByTestId('tasks-filter-all')).toHaveAttribute('aria-pressed', 'true')
	})
})
