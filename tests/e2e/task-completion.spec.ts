import { test, expect } from '@playwright/test'

/**
 * E2E test: Task creation and completion flow
 * Critical flow: Create task → Assign to room → Complete task → Verify score
 */
test.describe('Task Creation and Completion', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle')
	})

	test('User can create a new task', async ({ page }) => {
		// Navigate to Tasks view
		await page.click('[data-testid="nav-tasks"]')
		await page.waitForLoadState('networkidle')

		// Click add task button
		await page.click('[data-testid="add-task-button"]')

		// Fill task form
		await page.fill('[data-testid="task-name-input"]', 'Test Task - Vacuum Living Room')
		await page.fill('[data-testid="task-description-input"]', 'Weekly vacuuming of the living room carpet')

		// Select energy cost
		await page.selectOption('[data-testid="task-energy-select"]', '2')

		// Select priority
		await page.selectOption('[data-testid="task-priority-select"]', '2')

		// Select frequency
		await page.selectOption('[data-testid="task-frequency-type"]', 'weekly')
		await page.fill('[data-testid="task-frequency-value"]', '1')

		// Save task
		await page.click('[data-testid="save-task-button"]')

		// Verify task appears in list
		await expect(page.locator('text=Test Task - Vacuum Living Room')).toBeVisible()
	})

	test('User can assign task to a room', async ({ page }) => {
		// Navigate to room detail
		await page.click('[data-testid="nav-dashboard"]')
		await page.click('[data-testid="room-card-Living Room"]')
		await page.waitForLoadState('networkidle')

		// Click add existing task
		await page.click('[data-testid="add-room-task-button"]')

		// Select task from list
		await page.click('[data-testid="task-item-Vacuum floor"]')

		// Assign task
		await page.click('[data-testid="assign-task-button"]')

		// Verify task appears in room
		await expect(page.locator('[data-testid="room-task-list"]')).toContainText('Vacuum floor')
	})

	test('User can complete a task and earn points', async ({ page }) => {
		// Navigate to room with tasks
		await page.click('[data-testid="nav-dashboard"]')
		await page.click('[data-testid="room-card-Kitchen"]')
		await page.waitForLoadState('networkidle')

		// Get initial score
		const initialScore = await page.locator('[data-testid="contribution-score"]').textContent()

		// Complete first active task
		await page.click('[data-testid="complete-task-button"]:has-text("Complete") >> first')

		// Wait for completion animation
		await page.waitForSelector('[data-testid="completion-toast"]', { timeout: 5000 })

		// Verify score increased
		await page.waitForTimeout(1000) // Allow animation
		const newScore = await page.locator('[data-testid="contribution-score"]').textContent()
		expect(Number(newScore)).toBeGreaterThan(Number(initialScore))
	})

	test('Completing multiple tasks triggers combo', async ({ page }) => {
		// Navigate to room
		await page.click('[data-testid="nav-dashboard"]')
		await page.click('[data-testid="room-card-Bedroom"]')

		// Complete two tasks quickly
		await page.click('[data-testid="complete-task-button"] >> first')
		await page.waitForTimeout(500)
		await page.click('[data-testid="complete-task-button"] >> first')

		// Combo notification should appear
		await expect(page.locator('[data-testid="combo-notification"]')).toBeVisible({ timeout: 5000 })
	})
})
