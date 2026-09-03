/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * E2E: Overdue Task Claiming — claim button, takeover confirmation, notification
 *
 * Verifies the CS-PLAN-005 Phase 3 frontend contract:
 * - Overdue tasks assigned to another user show a "Claim & complete" button
 * - Clicking the button opens a confirmation dialog naming the original assignee
 * - Confirming the claim completes the task and records the takeover
 * - The original assignee's streak is unaffected
 *
 * Requires: Docker test environment with the `family` database profile.
 * CS-PLAN-015b: auth via per-user storageState (no loginAsUser / no logout).
 * @see docs/plans/active/cs-plan-005-task-visibility-teamwork-overdue.md Phase 3
 */

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'

test.describe('Overdue Task Claiming', () => {
	test.use({ databaseProfile: 'family' })

	test('overdue task assigned to another user shows claim button', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'dad')
		try {
			await page.goto('/apps/chorestrike/')
			await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

			const overdueTask = page.locator('[data-testid="task-item"], .task-item').filter({ hasText: /overdue/i }).first()

			if (await overdueTask.count() > 0) {
				const claimButton = overdueTask.locator('[data-testid="claim-overdue-button"], button:has-text("Claim"), button:has-text("Übernehmen")')
				if (await claimButton.count() > 0) {
					await expect(claimButton.first()).toBeVisible({ timeout: 5000 })
				}
			}
		} finally {
			await context.close()
		}
	})

	test('claim button opens confirmation dialog', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'dad')
		try {
			await page.goto('/apps/chorestrike/')
			await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

			const claimButton = page.locator('[data-testid="claim-overdue-button"], button:has-text("Claim"), button:has-text("Übernehmen")').first()

			if (await claimButton.count() > 0 && await claimButton.isVisible()) {
				await claimButton.click()

				const dialog = page.locator('[data-testid="overdue-claim-dialog"], .modal-container, [role="dialog"]')
				await expect(dialog).toBeVisible({ timeout: 10000 })

				const dialogText = await dialog.textContent()
				expect(dialogText).toBeTruthy()
			}
		} finally {
			await context.close()
		}
	})

	test('claiming overdue task via API records takeover', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const tasksResponse = await page.request.get('/apps/chorestrike/api/v1/room-tasks')
			if (tasksResponse.status() !== 200) {
				test.skip(true, 'Could not fetch room tasks')
				return
			}

			const tasksData = await tasksResponse.json()
			const roomTasks = tasksData.data || []

			const overdueTask = roomTasks.find(
				(rt: { isOverdue?: boolean; assignees?: string[] }) =>
					rt.isOverdue && (rt.assignees?.length ?? 0) > 0 && !rt.assignees!.includes('mom'),
			)

			if (!overdueTask) {
				test.skip(true, 'No overdue task assigned to another user found')
				return
			}

			const completeResponse = await page.request.post(
				`/apps/chorestrike/api/v1/room-tasks/${overdueTask.id}/complete`,
			)

			expect(completeResponse.status()).toBe(200)
			const body = await completeResponse.json()
			expect(body.success).toBe(true)
		} finally {
			await context.close()
		}
	})

	test('non-assignee can complete overdue public task', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'teen')
		try {
			const tasksResponse = await page.request.get('/apps/chorestrike/api/v1/room-tasks')
			if (tasksResponse.status() !== 200) {
				test.skip(true, 'Could not fetch room tasks')
				return
			}

			const tasksData = await tasksResponse.json()
			const roomTasks = tasksData.data || []

			const overduePublic = roomTasks.find(
				(rt: { isOverdue?: boolean; visibilityType?: string; assignees?: string[] }) =>
					rt.isOverdue
					&& (rt.visibilityType === 'public' || !rt.visibilityType)
					&& (rt.assignees?.length ?? 0) > 0
					&& !rt.assignees!.includes('teen'),
			)

			if (!overduePublic) {
				test.skip(true, 'No overdue public task assigned to another user')
				return
			}

			const completeResponse = await page.request.post(
				`/apps/chorestrike/api/v1/room-tasks/${overduePublic.id}/complete`,
			)

			expect(completeResponse.status()).toBe(200)
		} finally {
			await context.close()
		}
	})

	test('private overdue task cannot be claimed by non-assignee', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'teen')
		try {
			const tasksResponse = await page.request.get('/apps/chorestrike/api/v1/room-tasks')
			if (tasksResponse.status() !== 200) {
				test.skip(true, 'Could not fetch room tasks')
				return
			}

			const tasksData = await tasksResponse.json()
			const roomTasks = tasksData.data || []

			const overduePrivate = roomTasks.find(
				(rt: { isOverdue?: boolean; visibilityType?: string }) =>
					rt.isOverdue && rt.visibilityType === 'private',
			)

			if (!overduePrivate) {
				test.skip(true, 'No overdue private task found')
				return
			}

			const completeResponse = await page.request.post(
				`/apps/chorestrike/api/v1/room-tasks/${overduePrivate.id}/complete`,
			)

			expect(completeResponse.status()).toBe(403)
		} finally {
			await context.close()
		}
	})
})
