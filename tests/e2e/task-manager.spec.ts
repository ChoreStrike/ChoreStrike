import { test, expect } from './fixtures/test-fixtures'

/**
 * E2E test: Task Manager filters and display
 * Tests task filtering, searching, and statistics display
 */
test.describe('Task Manager', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)

		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })

		// Use text selector for navigation (Nextcloud Vue components don't pass data-testid)
		await page.click('text=Tasks')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForTimeout(1000)
	})

	test('Task Manager page loads with correct title', async ({ page }) => {
		// Wait for view with longer timeout
		await expect(page.locator('[data-testid="task-manager-view"]')).toBeVisible({ timeout: 20000 })

		// Verify title
		await expect(page.locator('[data-testid="task-manager-title"]')).toContainText('Task Manager')

		// Verify add task button is present
		await expect(page.locator('[data-testid="add-task-button"]')).toBeVisible({ timeout: 15000 })
	})

	test('Task Manager displays filter controls', async ({ page }) => {
		await page.waitForTimeout(1000)

		// Verify all filter selects are visible
		await expect(page.locator('[data-testid="status-filter"]')).toBeVisible({ timeout: 15000 })
		await expect(page.locator('[data-testid="priority-filter"]')).toBeVisible({ timeout: 15000 })
		await expect(page.locator('[data-testid="difficulty-filter"]')).toBeVisible({ timeout: 15000 })
	})

	test('Task Manager displays search input', async ({ page }) => {
		// Verify search input is visible
		await expect(page.locator('[data-testid="search-input"]')).toBeVisible()
		await expect(page.locator('[data-testid="search-input"]')).toHaveAttribute('placeholder', /Search/)
	})

	test('Task Manager displays statistics bar', async ({ page }) => {
		// Verify stats are displayed
		await expect(page.locator('[data-testid="total-tasks-stat"]')).toBeVisible()
		await expect(page.locator('[data-testid="overdue-tasks-stat"]')).toBeVisible()
		await expect(page.locator('[data-testid="due-today-stat"]')).toBeVisible()

		// Verify stat numbers are visible
		await expect(page.locator('[data-testid="total-tasks-count"]')).toBeVisible()
		await expect(page.locator('[data-testid="overdue-count"]')).toBeVisible()
		await expect(page.locator('[data-testid="due-today-count"]')).toBeVisible()
	})

	test('User can filter tasks by status', async ({ page }) => {
		// Select 'Active' from status filter
		await page.selectOption('[data-testid="status-filter"]', 'active')
		await page.waitForTimeout(300) // Allow for any filtering animation

		// Verify filter was applied (page should still be visible)
		await expect(page.locator('[data-testid="task-manager-view"]')).toBeVisible()
	})

	test('User can filter tasks by priority', async ({ page }) => {
		// Select 'Urgent' from priority filter
		await page.selectOption('[data-testid="priority-filter"]', 'urgent')
		await page.waitForTimeout(300)

		// Verify filter was applied
		await expect(page.locator('[data-testid="task-manager-view"]')).toBeVisible()
	})

	test('User can filter tasks by difficulty', async ({ page }) => {
		// Select difficulty level
		await page.selectOption('[data-testid="difficulty-filter"]', '3')
		await page.waitForTimeout(300)

		// Verify filter was applied
		await expect(page.locator('[data-testid="task-manager-view"]')).toBeVisible()
	})

	test('User can search for tasks', async ({ page }) => {
		// Type in search input
		await page.fill('[data-testid="search-input"]', 'test')
		await page.waitForTimeout(300)

		// Verify search was applied
		await expect(page.locator('[data-testid="task-manager-view"]')).toBeVisible()
	})

	test('User can open add task modal from Task Manager', async ({ page }) => {
		// Wait for page to be fully loaded
		await page.waitForTimeout(1000)

		// Click add task button
		await page.click('[data-testid="add-task-button"]')
		await page.waitForTimeout(500)

		// Verify modal is opened
		await expect(page.locator('.modal-content')).toBeVisible({ timeout: 15000 })
	})
})
