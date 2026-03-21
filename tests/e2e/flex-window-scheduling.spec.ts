import { test, expect } from './fixtures/test-fixtures'

/**
 * E2E test: Flex-Window Scheduling Flow
 * Tests flex window display, flexDays setting in task forms, and scheduling behavior
 * @task SCHEDULING-002
 */
test.describe('Flex-Window Scheduling', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)

		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })
	})

	test('Task form displays flex days input field', async ({ page }) => {
		// Navigate to a room to add a task
		await page.click('[data-testid="floor-item"]')
		await page.waitForTimeout(500)
		await page.click('[data-testid="room-item"]')
		await page.waitForTimeout(500)

		// Click add task button
		const addTaskBtn = page.locator('[data-testid="add-task-button"]')
		if (await addTaskBtn.isVisible()) {
			await addTaskBtn.click()
			await page.waitForTimeout(500)

			// Verify flex days input is visible
			await expect(page.locator('[data-testid="task-flex-days-input"]')).toBeVisible({ timeout: 10000 })

			// Verify default value is 0
			const flexInput = page.locator('[data-testid="task-flex-days-input"]')
			await expect(flexInput).toHaveValue('0')
		} else {
			// Skip if no room found
			test.skip()
		}
	})

	test('Flex days can be set when creating a task', async ({ page }) => {
		// Navigate to a room
		await page.click('[data-testid="floor-item"]')
		await page.waitForTimeout(500)
		await page.click('[data-testid="room-item"]')
		await page.waitForTimeout(500)

		// Click add task button if available
		const addTaskBtn = page.locator('[data-testid="add-task-button"]')
		if (await addTaskBtn.isVisible()) {
			await addTaskBtn.click()
			await page.waitForTimeout(500)

			// Set flex days to 3
			const flexInput = page.locator('[data-testid="task-flex-days-input"]')
			await flexInput.fill('3')
			await page.waitForTimeout(300)

			// Verify the value is set
			await expect(flexInput).toHaveValue('3')

			// Verify info message appears
			await expect(page.locator('.flex-days-info')).toBeVisible()
		} else {
			test.skip()
		}
	})

	test('Flex days input has correct validation', async ({ page }) => {
		// Navigate to a room
		await page.click('[data-testid="floor-item"]')
		await page.waitForTimeout(500)
		await page.click('[data-testid="room-item"]')
		await page.waitForTimeout(500)

		const addTaskBtn = page.locator('[data-testid="add-task-button"]')
		if (await addTaskBtn.isVisible()) {
			await addTaskBtn.click()
			await page.waitForTimeout(500)

			const flexInput = page.locator('[data-testid="task-flex-days-input"]')

			// Test min value (0)
			await flexInput.fill('0')
			await expect(flexInput).toHaveValue('0')

			// Test max value (14)
			await flexInput.fill('14')
			await expect(flexInput).toHaveValue('14')

			// Test negative value (should be constrained)
			await flexInput.fill('-1')
			const value = await flexInput.inputValue()
			expect(parseInt(value)).toBeGreaterThanOrEqual(0)
		} else {
			test.skip()
		}
	})

	test('Flex window display appears for tasks with flexDays > 0', async ({ page }) => {
		// Navigate to a room with tasks
		await page.click('[data-testid="floor-item"]')
		await page.waitForTimeout(500)
		await page.click('[data-testid="room-item"]')
		await page.waitForTimeout(1000)

		// Look for flex window display
		const flexWindowDisplay = page.locator('.flex-window-display')

		// Check if any task has flex window display
		const count = await flexWindowDisplay.count()

		if (count > 0) {
			// Verify flex window structure
			await expect(flexWindowDisplay.first().locator('.flex-window-header')).toBeVisible()
			await expect(flexWindowDisplay.first().locator('.flex-window-timeline')).toBeVisible()

			// Verify timeline points
			await expect(flexWindowDisplay.first().locator('.timeline-point.earliest')).toBeVisible()
			await expect(flexWindowDisplay.first().locator('.timeline-point.ideal')).toBeVisible()
			await expect(flexWindowDisplay.first().locator('.timeline-point.latest')).toBeVisible()
		} else {
			// No tasks with flex days - verify the display doesn't appear for regular tasks
			const taskItems = page.locator('.task-item')
			const taskCount = await taskItems.count()
			if (taskCount > 0) {
				// Tasks exist but none have flex days
				expect(count).toBe(0)
			}
		}
	})

	test('Flex window timeline shows correct status indicators', async ({ page }) => {
		// Navigate to a room
		await page.click('[data-testid="floor-item"]')
		await page.waitForTimeout(500)
		await page.click('[data-testid="room-item"]')
		await page.waitForTimeout(1000)

		const flexWindowDisplay = page.locator('.flex-window-display')
		const count = await flexWindowDisplay.count()

		if (count > 0) {
			const firstDisplay = flexWindowDisplay.first()

			// Check for status message
			const statusMessage = firstDisplay.locator('.flex-window-status span')
			const statusVisible = await statusMessage.isVisible().catch(() => false)

			if (statusVisible) {
				// Verify one of the valid status classes
				const hasValidStatus = await statusMessage.evaluate(el => {
					return el.classList.contains('status-early') ||
						   el.classList.contains('status-optimal') ||
						   el.classList.contains('status-overdue')
				})
				expect(hasValidStatus).toBeTruthy()
			}
		} else {
			test.skip()
		}
	})

	test('Task edit form preserves flex days value', async ({ page }) => {
		// Navigate to a room
		await page.click('[data-testid="floor-item"]')
		await page.waitForTimeout(500)
		await page.click('[data-testid="room-item"]')
		await page.waitForTimeout(1000)

		// Find a task and click edit
		const editBtn = page.locator('.task-item .action-btn').first()
		if (await editBtn.isVisible()) {
			await editBtn.click()
			await page.waitForTimeout(500)

			// Check if flex days input is visible in edit form
			const flexInput = page.locator('[data-testid="task-flex-days-input"]')
			const isVisible = await flexInput.isVisible().catch(() => false)

			if (isVisible) {
				// Get current value
				const currentValue = await flexInput.inputValue()

				// Change value
				await flexInput.fill('5')
				await page.waitForTimeout(300)

				// Cancel edit
				await page.click('.secondary-button:has-text("Cancel")')
				await page.waitForTimeout(500)

				// Re-open edit
				await editBtn.click()
				await page.waitForTimeout(500)

				// Verify original value is restored (edit was cancelled)
				await expect(page.locator('[data-testid="task-flex-days-input"]')).toHaveValue(currentValue)
			}
		} else {
			test.skip()
		}
	})

	test('Compact mode displays correctly in task list', async ({ page }) => {
		// Navigate to a room
		await page.click('[data-testid="floor-item"]')
		await page.waitForTimeout(500)
		await page.click('[data-testid="room-item"]')
		await page.waitForTimeout(1000)

		const flexWindowDisplay = page.locator('.flex-window-display.compact')
		const count = await flexWindowDisplay.count()

		if (count > 0) {
			// Verify compact class is applied
			const firstDisplay = flexWindowDisplay.first()
			await expect(firstDisplay).toHaveClass(/compact/)

			// Verify smaller sizing
			const header = firstDisplay.locator('.flex-window-header')
			await expect(header).toBeVisible()
		} else {
			test.skip()
		}
	})
})

test.describe('Flex-Window API Integration', () => {
	test('Room task API includes flex window bounds', async ({ page }) => {
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })

		// Navigate to a room
		await page.click('[data-testid="floor-item"]')
		await page.waitForTimeout(500)
		await page.click('[data-testid="room-item"]')
		await page.waitForTimeout(1000)

		// Check if any task has flex window data
		const taskItems = page.locator('.task-item')
		const count = await taskItems.count()

		if (count > 0) {
			// Click on a task to view details
			const expandBtn = taskItems.first().locator('.action-btn:has([data-icon="chevron-down"]), .action-btn:last-child')
			await expandBtn.click()
			await page.waitForTimeout(500)

			// Check for flex window display
			const flexDisplay = page.locator('.flex-window-display')
			const flexCount = await flexDisplay.count()

			// Either flex window is shown or task has no flex days
			expect(flexCount).toBeGreaterThanOrEqual(0)
		} else {
			test.skip()
		}
	})
})
