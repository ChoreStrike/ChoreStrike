import { test, expect } from './fixtures/test-fixtures'

/**
 * E2E test: Task Manager filters and display
 * Tests task filtering, searching, and statistics display.
 *
 * CS-PLAN-015: auth via storageState; fixed sleeps replaced by auto-waiting
 * assertions; modal surface targeted via `getByTestId('modal')`.
 */
test.describe('Task Manager', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)
		await page.goto('/apps/chorestrike/#/tasks')
		await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 20000 })
	})

	test('Task Manager page loads with correct title', async ({ page }) => {
		await expect(page.getByTestId('task-manager-title')).toContainText('Task Manager')
		await expect(page.getByTestId('add-task-button')).toBeVisible({ timeout: 15000 })
	})

	test('Task Manager displays filter controls', async ({ page }) => {
		await expect(page.getByTestId('status-filter')).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('priority-filter')).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('difficulty-filter')).toBeVisible({ timeout: 15000 })
	})

	test('Task Manager displays search input', async ({ page }) => {
		await expect(page.getByTestId('search-input')).toBeVisible()
		await expect(page.getByTestId('search-input')).toHaveAttribute('placeholder', /Search/)
	})

	test('Task Manager displays statistics bar', async ({ page }) => {
		await expect(page.getByTestId('total-tasks-stat')).toBeVisible()
		await expect(page.getByTestId('overdue-tasks-stat')).toBeVisible()
		await expect(page.getByTestId('due-today-stat')).toBeVisible()

		await expect(page.getByTestId('total-tasks-count')).toBeVisible()
		await expect(page.getByTestId('overdue-count')).toBeVisible()
		await expect(page.getByTestId('due-today-count')).toBeVisible()
	})

	test('User can filter tasks by status', async ({ page }) => {
		await page.getByTestId('status-filter').selectOption('active')
		await expect(page.getByTestId('task-manager-view')).toBeVisible()
	})

	test('User can filter tasks by priority', async ({ page }) => {
		await page.getByTestId('priority-filter').selectOption('urgent')
		await expect(page.getByTestId('task-manager-view')).toBeVisible()
	})

	test('User can filter tasks by difficulty', async ({ page }) => {
		await page.getByTestId('difficulty-filter').selectOption('3')
		await expect(page.getByTestId('task-manager-view')).toBeVisible()
	})

	test('User can search for tasks', async ({ page }) => {
		await page.getByTestId('search-input').fill('test')
		await expect(page.getByTestId('task-manager-view')).toBeVisible()
	})

	test('User can open add task modal from Task Manager', async ({ page }) => {
		await page.getByTestId('add-task-button').click()

		// Modal surface + task-name input appear (auto-waited).
		await expect(page.getByTestId('modal')).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('task-name-input')).toBeVisible({ timeout: 15000 })
	})
})
