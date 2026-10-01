/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { test, expect } from '@playwright/test'
import { createUserContext } from './fixtures/auth-contexts'

/**
 * E2E Tests for Task Checklist (Feature 3: Sub-Checklists)
 *
 * CS-PLAN-050: the toggle test now creates its own sub-task through the
 * owner-gated editor (the seed ships no checklist items) and asserts the
 * durable outcome — checkbox state flips to checked and the completed item
 * title is struck through. The old conditional `if (count > 0)` toggle and
 * strikethrough tests (which could pass without testing anything, and
 * asserted line-through on the wrong element) were replaced by it.
 */
test.describe('Task Checklist', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)

		// Navigate to app (assuming already logged in)
		await page.goto('/apps/chorestrike/#/dashboard')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })
	})

	test('should display checklist when expanding a task', async ({ page }) => {
		// Navigate to task manager
		await page.getByTestId('nav-tasks').click()
		await page.waitForSelector('[data-testid="task-manager-view"]')

		// Find first active task (firefox under parallel load can take
		// longer than the 5 s assertion default to render the list).
		const taskItem = page.locator('.task-item').first()
		await expect(taskItem).toBeVisible({ timeout: 20000 })

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

	test('should toggle checklist item completion', async ({ browser }) => {
		// The checklist editor (add-item form) is owner-gated, so the
		// toggle test acts as the seeded owner `mom`.
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await page.goto('/apps/chorestrike/#/dashboard')
			await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })
			await page.getByTestId('nav-tasks').click()
			await page.waitForSelector('[data-testid="task-manager-view"]')

			// Expand the first task and add a sub-task through the editor
			// (the seed ships no checklist items).
			const taskItem = page.locator('.task-item').first()
			await taskItem.locator('button[title="Show details"]').click()

			const label = `E2E sub-task ${Date.now()}`
			await page.locator('.add-item-trigger').click()
			await page.getByTestId('checklist-new-item-input').fill(label)
			await page.getByTestId('checklist-add-item-button').click()

			const newItem = page.locator('.checklist-item', { hasText: label })
			await expect(newItem).toBeVisible({ timeout: 15000 })

			// Toggle the checkbox and verify the durable outcome: the
			// checkbox flips to checked, the item is marked completed, and
			// the title is struck through. The raw input is visually
			// hidden behind the custom checkmark — click the label.
			const checkbox = newItem.locator('input[type="checkbox"]')
			await expect(checkbox).not.toBeChecked()
			await newItem.locator('label.item-checkbox').click()
			await expect(checkbox).toBeChecked({ timeout: 15000 })
			await expect(newItem.locator('.item-content')).toHaveClass(/is-completed/)
			await expect(newItem.locator('.item-title')).toHaveCSS('text-decoration', /line-through/)
		} finally {
			await context.close()
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
})

/**
 * E2E Tests for Group Assignment (Feature 5)
 */
test.describe('Group Assignment', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)

		// Navigate to app (assuming already logged in)
		await page.goto('/apps/chorestrike/#/dashboard')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })
	})

	test('should show assigned status for tasks', async ({ page }) => {
		// Navigate to task manager
		await page.getByTestId('nav-tasks').click()
		await page.waitForSelector('[data-testid="task-manager-view"]')

		// The family seed guarantees assigned room tasks; the assigned
		// indicator is rendered for every task with at least one assignee.
		const assignedIndicators = page.locator('.meta-item.assigned')
		await expect(assignedIndicators.first()).toBeVisible({ timeout: 15000 })
		await expect(assignedIndicators.first()).toContainText(/assigned/i)
	})
})

/**
 * E2E Tests for Dynamic/Static Scheduling (Feature 1)
 */
test.describe('Task Scheduling Modes', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)

		// Navigate to app (assuming already logged in)
		await page.goto('/apps/chorestrike/#/dashboard')
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
		await expect(page.locator('[data-testid="modal"]')).toBeVisible()

		// Scheduling mode options should be visible (CS-PLAN-044 vocabulary).
		// Exact match: the form also renders a "Flexible scheduling window
		// in days" hint that a substring locator would match twice.
		await expect(page.getByText('Flexible', { exact: true })).toBeVisible()
		await expect(page.getByText('Fixed schedule', { exact: true })).toBeVisible()
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
		await page.goto('/apps/chorestrike/#/dashboard')
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
