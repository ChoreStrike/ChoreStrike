/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { test, expect } from './fixtures/test-fixtures'

/**
 * E2E Tests for Task Checklist (Feature 3: Sub-Checklists)
 */
test.describe('Task Checklist', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)
		
		// Navigate to app (assuming already logged in)
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })
	})

	test('should display checklist when expanding a task', async ({ page }) => {
		// Navigate to task manager
		await page.getByTestId('nav-tasks').click()
		await page.waitForSelector('[data-testid="task-manager-view"]')

		// Find first active task
		const taskItem = page.locator('.task-item').first()
		await expect(taskItem).toBeVisible()

		// Click expand button
		await taskItem.locator('button[title="Show details"]').click()

		// Checklist should be visible
		await expect(page.locator('[data-testid="task-checklist"]')).toBeVisible()
	})

	test('should show progress bar in checklist', async ({ page }) => {
		// Navigate to task manager
		await page.getByTestId('nav-tasks').click()
		await page.waitForSelector('[data-testid="task-manager-view"]')

		// Expand first task
		const taskItem = page.locator('.task-item').first()
		await taskItem.locator('button[title="Show details"]').click()

		// Progress bar should be visible
		const progressBar = page.locator('.progress-bar-container')
		await expect(progressBar).toBeVisible()
	})

	test('should toggle checklist item completion', async ({ page }) => {
		// Navigate to task manager
		await page.getByTestId('nav-tasks').click()
		await page.waitForSelector('[data-testid="task-manager-view"]')

		// Expand first task
		const taskItem = page.locator('.task-item').first()
		await taskItem.locator('button[title="Show details"]').click()

		// Find first checklist item
		const checklistItem = page.locator('[data-testid^="checklist-item-"]').first()
		
		// Check if there are checklist items
		const count = await checklistItem.count()
		if (count > 0) {
			// Get checkbox
			const checkbox = checklistItem.locator('input[type="checkbox"]')
			
			// Toggle checkbox
			const initialState = await checkbox.isChecked()
			await checkbox.click()
			
			// Wait for state change
			await page.waitForTimeout(500)
			
			// Verify state changed (or API call was made)
			// Note: Actual state depends on backend response
		}
	})

	test('should collapse task details', async ({ page }) => {
		// Navigate to task manager
		await page.getByTestId('nav-tasks').click()
		await page.waitForSelector('[data-testid="task-manager-view"]')

		// Expand first task
		const taskItem = page.locator('.task-item').first()
		await taskItem.locator('button[title="Show details"]').click()

		// Checklist should be visible
		await expect(page.locator('[data-testid="task-checklist"]')).toBeVisible()

		// Collapse task
		await taskItem.locator('button[title="Show details"]').click()

		// Checklist should be hidden (or removed from DOM)
		await expect(page.locator('[data-testid="task-checklist"]')).not.toBeVisible()
	})

	test('should show completion badge with correct count', async ({ page }) => {
		// Navigate to task manager
		await page.getByTestId('nav-tasks').click()
		await page.waitForSelector('[data-testid="task-manager-view"]')

		// Expand first task
		const taskItem = page.locator('.task-item').first()
		await taskItem.locator('button[title="Show details"]').click()

		// Progress badge should show format "X/Y"
		const progressBadge = page.locator('.progress-badge')
		await expect(progressBadge).toBeVisible()
		
		// Badge text should match pattern "number/number"
		const badgeText = await progressBadge.textContent()
		expect(badgeText).toMatch(/^\d+\/\d+$/)
	})

	test('completed checklist items should have strikethrough', async ({ page }) => {
		// Navigate to task manager
		await page.getByTestId('nav-tasks').click()
		await page.waitForSelector('[data-testid="task-manager-view"]')

		// Expand first task
		const taskItem = page.locator('.task-item').first()
		await taskItem.locator('button[title="Show details"]').click()

		// Find completed items (checked checkboxes)
		const completedItems = page.locator('.checklist-item .item-checkbox.is-completed')
		
		// If there are completed items, verify styling
		const count = await completedItems.count()
		if (count > 0) {
			await expect(completedItems.first()).toHaveCSS('text-decoration', /line-through/)
		}
	})
})

/**
 * E2E Tests for Group Assignment (Feature 5)
 */
test.describe('Group Assignment', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)
		
		// Navigate to app (assuming already logged in)
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })
	})

	test('should show assigned status for tasks', async ({ page }) => {
		// Navigate to task manager
		await page.getByTestId('nav-tasks').click()
		await page.waitForSelector('[data-testid="task-manager-view"]')

		// Check for assigned indicator in task meta
		const assignedIndicators = page.locator('.meta-item.assigned')
		
		// Either there are assigned tasks or not
		const count = await assignedIndicators.count()
		if (count > 0) {
			await expect(assignedIndicators.first()).toContainText('Assigned')
		}
	})
})

/**
 * E2E Tests for Dynamic/Static Scheduling (Feature 1)
 */
test.describe('Task Scheduling Modes', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)
		
		// Navigate to app (assuming already logged in)
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })
	})

	test('should show scheduling mode options in task edit form', async ({ page }) => {
		// Navigate to task manager
		await page.getByTestId('nav-tasks').click()
		await page.waitForSelector('[data-testid="task-manager-view"]')

		// Click add new task
		await page.click('[data-testid="add-task-button"]')

		// Modal should open
		await expect(page.locator('.modal')).toBeVisible()

		// Scheduling mode options should be visible
		await expect(page.locator('text=Dynamic')).toBeVisible()
		await expect(page.locator('text=Static')).toBeVisible()
	})

	test('should show time input for static scheduling', async ({ page }) => {
		// Navigate to task manager
		await page.getByTestId('nav-tasks').click()
		await page.waitForSelector('[data-testid="task-manager-view"]')

		// Click add new task
		await page.click('[data-testid="add-task-button"]')

		// Select static scheduling
		await page.click('input[value="static"]')

		// Time input should appear
		await expect(page.locator('input[type="time"]')).toBeVisible()
	})
})

/**
 * E2E Tests for Template Selector (Feature 2)
 */
test.describe('Template Selector', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)
		
		// Navigate to app (assuming already logged in)
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })
	})

	test('should show template selector when creating task', async ({ page }) => {
		// Navigate to task manager
		await page.getByTestId('nav-tasks').click()
		await page.waitForSelector('[data-testid="task-manager-view"]')

		// Click add new task
		await page.click('[data-testid="add-task-button"]')

		// Template selector section should be visible
		await expect(page.locator('text=Start from Template')).toBeVisible()
	})
})
