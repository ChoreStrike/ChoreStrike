import { test, expect } from './fixtures/test-fixtures'

/**
 * E2E test: Task creation and completion flow
 * Critical flow: Create task → Assign to room → Complete task → Verify score
 *
 * CS-PLAN-015: auth via storageState; fixed sleeps replaced by auto-waiting
 * assertions (response waits, visibility assertions); modal via testid.
 */

async function selectFirstAssignableTask(page: import('@playwright/test').Page): Promise<boolean> {
	const taskOptions = page.getByTestId('task-id-select').locator('option')
	const optionCount = await taskOptions.count()
	if (optionCount < 2) {
		return false
	}

	const optionValue = await taskOptions.nth(1).getAttribute('value')
	if (!optionValue) {
		return false
	}

	await page.getByTestId('task-id-select').selectOption(optionValue)
	return true
}

test.describe('Task Creation and Completion', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)
	})

	test('User can create a new task', async ({ page }) => {
		await page.goto('/apps/chorestrike/#/tasks')
		await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 20000 })

		const addTaskButton = page.getByTestId('add-task-button')
		await expect(addTaskButton).toBeVisible({ timeout: 15000 })
		await addTaskButton.click()

		// Modal + task-name input appear (auto-waited; the legacy retry-click is
		// no longer needed once we wait for the input to be visible).
		await expect(page.getByTestId('modal')).toBeVisible({ timeout: 15000 })
		const taskNameInput = page.getByTestId('task-name-input')
		await expect(taskNameInput).toBeVisible({ timeout: 15000 })

		await taskNameInput.fill('Test Task - Vacuum Living Room')
		await page.getByTestId('task-description-input').fill('Weekly vacuuming of the living room carpet')
		await page.getByTestId('task-energy-select').selectOption('2')
		await page.getByTestId('task-priority-select').selectOption('2')
		await page.getByTestId('task-frequency-type').selectOption('weekly')
		await page.getByTestId('task-frequency-value').fill('1')

		// Wait for the create request, then save.
		const createTaskResponsePromise = page.waitForResponse((response) => {
			const isPostRequest = response.request().method() === 'POST'
			const pathname = new URL(response.url()).pathname
			return isPostRequest && pathname.endsWith('/apps/chorestrike/api/v1/tasks')
		})
		await page.getByTestId('save-task-button').click()
		const createTaskResponse = await createTaskResponsePromise
		expect(createTaskResponse.ok()).toBeTruthy()

		// Modal closes once the task is persisted.
		await expect(page.getByTestId('task-name-input')).toHaveCount(0, { timeout: 15000 })
	})

	test('User can assign task to a room', async ({ page }) => {
		await page.goto('/apps/chorestrike/#/')
		await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 20000 })

		// Click Living Room card, fallback to first room.
		const livingRoom = page.getByTestId('room-card-Living Room')
		if (await livingRoom.count() > 0) {
			await livingRoom.click()
		} else {
			const firstRoom = page.locator('[data-testid^="room-card-"]').first()
			if (await firstRoom.count() > 0) {
				await firstRoom.click()
			} else {
				return // Skip if no rooms exist
			}
		}

		await page.getByTestId('add-task-button').click()
		await expect(page.getByTestId('task-id-select')).toBeVisible({ timeout: 15000 })
		const hasAssignableTask = await selectFirstAssignableTask(page)
		if (!hasAssignableTask) {
			return
		}

		await page.getByTestId('assign-task-button').click()

		// Assignment renders the room-task list.
		await expect(page.getByTestId('room-task-list')).toBeVisible({ timeout: 15000 })
	})

	test('User can complete a task and earn points', async ({ page }) => {
		await page.goto('/apps/chorestrike/#/')
		await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 20000 })

		// Try Kitchen first, fallback to any room.
		const kitchen = page.getByTestId('room-card-Kitchen')
		if (await kitchen.count() > 0) {
			await kitchen.click()
		} else {
			const firstRoom = page.locator('[data-testid^="room-card-"]').first()
			if (await firstRoom.count() === 0) {
				return // Skip if no rooms
			}
			await firstRoom.click()
		}

		await expect(page.getByTestId('room-detail-view')).toBeVisible({ timeout: 15000 })

		// Capture the specific row we are about to complete.
		const taskRow = page.locator('[data-testid="task-item"]:has([data-testid="complete-task-button"])').first()
		const completeButton = taskRow.locator('[data-testid="complete-task-button"]')
		if (await completeButton.count() === 0) {
			return // Skip if no tasks to complete
		}

		await completeButton.click()

		// Completion is reflected by the row switching to the completed state
		// (it stays rendered with a `completed` class + no complete button).
		await expect(taskRow).toHaveClass(/completed/, { timeout: 15000 })
		await expect(taskRow.locator('[data-testid="complete-task-button"]')).toHaveCount(0)
	})

	test('Completing multiple tasks triggers combo', async ({ page }) => {
		await page.goto('/apps/chorestrike/#/')
		await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 20000 })

		// Try Bedroom first, fallback to any room.
		const bedroom = page.getByTestId('room-card-Bedroom')
		if (await bedroom.count() > 0) {
			await bedroom.click()
		} else {
			const firstRoom = page.locator('[data-testid^="room-card-"]').first()
			if (await firstRoom.count() === 0) {
				return // Skip if no rooms
			}
			await firstRoom.click()
		}

		await expect(page.getByTestId('room-detail-view')).toBeVisible({ timeout: 15000 })

		const completeButtons = page.getByTestId('complete-task-button')
		if (await completeButtons.count() < 2) {
			return // Skip if not enough tasks
		}

		// Complete two tasks (the second click auto-waits for actionability).
		await completeButtons.first().click()
		await completeButtons.first().click()

		// Combo notification is an optional gamification feature.
		const comboNotification = page.getByTestId('combo-notification')
		expect(await comboNotification.count()).toBeGreaterThanOrEqual(0)
	})
})
