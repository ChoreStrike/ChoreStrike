/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * E2E: Private Tasks — visibility, lock icon, my-private filter
 *
 * Verifies the CS-PLAN-005 Phase 3 frontend contract:
 * - Private tasks show a lock icon in the task list
 * - The private scope of the task list (`?visibility=private`) shows only private tasks
 * - Private tasks created via the form get the correct visibility
 *
 * Requires: Docker test environment with the `family` database profile.
 * CS-PLAN-015b: auth via per-user storageState (no loginAsUser / no logout).
 * @see docs/plans/active/cs-plan-005-task-visibility-teamwork-overdue.md Phase 3
 */

import type { Page } from '@playwright/test'

import { createUserContext } from './fixtures/auth-contexts.ts'
import { expect, test } from './fixtures/database-fixtures.ts'
import { chooseFormRoom, openFormMore } from './fixtures/task-form.ts'
import { createVisibilityFixture } from './fixtures/visibility-fixtures.ts'

/** Open #/tasks without load-fragile networkidle waits (CS-PLAN-051). */
async function navigateToTasks(page: Page): Promise<void> {
	// One-off full-matrix load flakes (see BUG-E2E-005 history): a session
	// hiccup during the prime/navigation can leave the view unmounted —
	// retry the NAVIGATION against the durable outcome instead of sleeping.
	await expect(async () => {
		await page.goto('/apps/chorestrike/#/tasks')
		await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 10000 })
	}).toPass({ timeout: 45000 })
}

test.describe('Private Tasks', () => {
	test.use({ databaseProfile: 'family' })

	test('private task shows lock icon in task list', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			// De-vacuoused (CS-PLAN-051): assert the lock testid on an
			// API-created fixture row instead of conditionally guarding on a
			// regex match against seed contents. CS-PLAN-068: the fixture
			// THROWS on a failed precondition — the failure is loud.
			const probeName = `Private Lock Probe ${Date.now()}`
			await createVisibilityFixture(page, probeName, 'private')

			await navigateToTasks(page)

			const row = page.getByTestId('task-item').filter({ hasText: probeName }).first()
			await expect(row).toBeVisible({ timeout: 15000 })
			await expect(row.locator('[data-testid="visibility-lock-icon"]')).toBeVisible()
		} finally {
			await context.close()
		}
	})

	test('user can create a private task via the form', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await navigateToTasks(page)

			const addTaskButton = page.locator('[data-testid="add-task-button"]')
			await expect(addTaskButton).toBeVisible({ timeout: 15000 })
			await addTaskButton.dispatchEvent('click')

			await page.waitForSelector('.modal-container', { timeout: 15000 })
			await page.fill('[data-testid="task-name-input"]', 'My Private Reminder')

			// Visibility sits folded under "More" (D-091-85).
			await openFormMore(page)
			await page.getByTestId('task-visibility-select').selectOption('private')

			// Unified creation (CS-PLAN-038): a room is required — pick the
			// first available room so the save button enables.
			await chooseFormRoom(page)

			// CS-PLAN-063: the UI fires ONE atomic aggregate POST —
			// `/api/v1/tasks` (definition-only create) was pruned.
			const createResponse = page.waitForResponse(
				(response) => response.request().method() === 'POST' && response.url().includes('/api/v1/room-tasks'),
				{ timeout: 15000 },
			)

			await page.getByTestId('save-task-button').click()
			const response = await createResponse
			expect(response.status()).toBe(200)

			const body = await response.json()
			expect(body.success).toBe(true)
			expect(body.data.task.visibilityType).toBe('private')
			expect(body.data.roomTask.taskId).toBe(body.data.task.id)
		} finally {
			await context.close()
		}
	})

	test('my-private filter shows only own private tasks', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			// The Dashboard chip that led here went with CS-PLAN-090 WP-B; the
			// task list keeps the private scope behind ?visibility=private
			// (CS-PLAN-041) and its Visibility filter.
			const stamp = Date.now()
			const privateName = `Private Filter Probe ${stamp}`
			const publicName = `Public Filter Probe ${stamp}`
			await createVisibilityFixture(page, privateName, 'private')
			await createVisibilityFixture(page, publicName, 'public')

			await page.goto('/apps/chorestrike/#/tasks?visibility=private')
			await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 30000 })
			await expect(page.getByTestId('visibility-filter')).toHaveValue('private')

			const rows = page.getByTestId('task-item')
			await expect(rows.filter({ hasText: privateName })).toBeVisible({ timeout: 15000 })
			await expect(rows.filter({ hasText: publicName })).toHaveCount(0)
			const count = await rows.count()
			for (let i = 0; i < count; i++) {
				await expect(rows.nth(i).getByTestId('visibility-lock-icon')).toBeVisible()
			}
		} finally {
			await context.close()
		}
	})

	test('other users private tasks are not visible', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'teen')
		try {
			await navigateToTasks(page)

			const response = await page.request.get('/apps/chorestrike/api/v1/tasks')
			expect(response.status()).toBe(200)

			const body = await response.json()
			expect(body.success).toBe(true)

			const privateTasks = (body.data || []).filter((t: { visibilityType?: string, ownerUserId?: string }) => t.visibilityType === 'private' && t.ownerUserId !== 'teen')
			expect(privateTasks).toHaveLength(0)
		} finally {
			await context.close()
		}
	})
})
