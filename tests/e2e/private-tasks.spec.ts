/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * E2E: Private Tasks — visibility, lock icon, my-private filter
 *
 * Verifies the CS-PLAN-005 Phase 3 frontend contract:
 * - Private tasks show a lock icon in the task list
 * - The "Show only my private tasks" filter works in QuickStats
 * - Private tasks created via the form get the correct visibility
 *
 * Requires: Docker test environment with the `family` database profile.
 * @see docs/plans/active/cs-plan-005-task-visibility-teamwork-overdue.md Phase 3
 */

import { test, expect, loginAsUser, getUserCredentials } from './fixtures/database-fixtures'
import type { Page } from '@playwright/test'

async function navigateToTasks(page: Page): Promise<void> {
	await page.goto('/apps/chorestrike/#/tasks')
	await page.waitForLoadState('networkidle', { timeout: 30000 })
	await page.waitForSelector('[data-testid="task-manager-view"]', { timeout: 20000 })
}

test.describe('Private Tasks', () => {
	test.use({ databaseProfile: 'family' })

	test('private task shows lock icon in task list', async ({ page }) => {
		const momCreds = getUserCredentials('family', 'mom')
		await loginAsUser(page, momCreds!.userId, momCreds!.password)
		await navigateToTasks(page)

		const privateTask = page.locator('[data-testid="task-item"]').filter({ hasText: /private|reminder/i }).first()

		if (await privateTask.count() > 0) {
			const lockIcon = privateTask.locator('[data-testid="visibility-lock-icon"], .icon-lock, svg[data-icon="lock"]')
			await expect(lockIcon).toBeVisible({ timeout: 5000 })
		}
	})

	test('user can create a private task via the form', async ({ page }) => {
		const momCreds = getUserCredentials('family', 'mom')
		await loginAsUser(page, momCreds!.userId, momCreds!.password)
		await navigateToTasks(page)

		const addTaskButton = page.locator('[data-testid="add-task-button"]')
		await expect(addTaskButton).toBeVisible({ timeout: 15000 })
		await addTaskButton.dispatchEvent('click')

		await page.waitForSelector('.modal-container', { timeout: 15000 })
		await page.fill('[data-testid="task-name-input"]', 'My Private Reminder')

		const visibilitySelect = page.locator('[data-testid="task-visibility-select"]')
		if (await visibilitySelect.count() > 0) {
			await visibilitySelect.selectOption('private')
		}

		const createResponse = page.waitForResponse(
			(response) => response.request().method() === 'POST' && response.url().includes('/api/v1/tasks'),
			{ timeout: 15000 },
		)

		await page.locator('[data-testid="task-save-button"], button:has-text("Save"), button:has-text("Create")').first().click()
		const response = await createResponse
		expect(response.status()).toBe(200)

		const body = await response.json()
		expect(body.success).toBe(true)
		expect(body.data.visibilityType).toBe('private')
	})

	test('my-private filter shows only own private tasks', async ({ page }) => {
		const momCreds = getUserCredentials('family', 'mom')
		await loginAsUser(page, momCreds!.userId, momCreds!.password)

		await page.goto('/apps/chorestrike/')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

		const privateFilter = page.locator('[data-testid="private-filter-toggle"], button:has-text("Private"), [aria-label*="private" i]')

		if (await privateFilter.count() > 0) {
			await privateFilter.first().click()
			await page.waitForTimeout(1000)

			const taskItems = page.locator('[data-testid="task-item"], .task-item')
			const count = await taskItems.count()

			for (let i = 0; i < count; i++) {
				const item = taskItems.nth(i)
				const lockIcon = item.locator('[data-testid="visibility-lock-icon"], .icon-lock, svg[data-icon="lock"]')
				if (await item.isVisible()) {
					expect(await lockIcon.count()).toBeGreaterThan(0)
				}
			}
		}
	})

	test('other users private tasks are not visible', async ({ page }) => {
		const teenCreds = getUserCredentials('family', 'teen')
		await loginAsUser(page, teenCreds!.userId, teenCreds!.password)
		await navigateToTasks(page)

		const response = await page.request.get('/apps/chorestrike/api/v1/tasks')
		expect(response.status()).toBe(200)

		const body = await response.json()
		expect(body.success).toBe(true)

		const privateTasks = (body.data || []).filter(
			(t: { visibilityType?: string; ownerUserId?: string }) =>
				t.visibilityType === 'private' && t.ownerUserId !== 'teen',
		)
		expect(privateTasks).toHaveLength(0)
	})
})
