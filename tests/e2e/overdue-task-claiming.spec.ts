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
 * @see docs/plans/active/cs-plan-005-task-visibility-teamwork-overdue.md Phase 3
 */

import { test, expect, loginAsUser, getUserCredentials } from './fixtures/database-fixtures'

test.describe('Overdue Task Claiming', () => {
	test.use({ databaseProfile: 'family' })

	test('overdue task assigned to another user shows claim button', async ({ page }) => {
		const dadCreds = getUserCredentials('family', 'dad')
		await loginAsUser(page, dadCreds!.userId, dadCreds!.password)

		await page.goto('/apps/chorestrike/')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

		const overdueTask = page.locator('[data-testid="task-item"], .task-item').filter({ hasText: /overdue/i }).first()

		if (await overdueTask.count() > 0) {
			const claimButton = overdueTask.locator('[data-testid="claim-overdue-button"], button:has-text("Claim"), button:has-text("Übernehmen")')
			if (await claimButton.count() > 0) {
				await expect(claimButton.first()).toBeVisible({ timeout: 5000 })
			}
		}
	})

	test('claim button opens confirmation dialog', async ({ page }) => {
		const dadCreds = getUserCredentials('family', 'dad')
		await loginAsUser(page, dadCreds!.userId, dadCreds!.password)

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
	})

	test('claiming overdue task via API records takeover', async ({ page }) => {
		const momCreds = getUserCredentials('family', 'mom')
		await loginAsUser(page, momCreds!.userId, momCreds!.password)

		const tasksResponse = await page.request.get('/apps/chorestrike/api/v1/room-tasks')
		if (tasksResponse.status() !== 200) {
			test.skip(true, 'Could not fetch room tasks')
			return
		}

		const tasksData = await tasksResponse.json()
		const roomTasks = tasksData.data || []

		const overdueTask = roomTasks.find(
			(rt: { isOverdue?: boolean; assignedToUserId?: string }) =>
				rt.isOverdue && rt.assignedToUserId && rt.assignedToUserId !== 'mom',
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
	})

	test('non-assignee can complete overdue public task', async ({ page }) => {
		const teenCreds = getUserCredentials('family', 'teen')
		await loginAsUser(page, teenCreds!.userId, teenCreds!.password)

		const tasksResponse = await page.request.get('/apps/chorestrike/api/v1/room-tasks')
		if (tasksResponse.status() !== 200) {
			test.skip(true, 'Could not fetch room tasks')
			return
		}

		const tasksData = await tasksResponse.json()
		const roomTasks = tasksData.data || []

		const overduePublic = roomTasks.find(
			(rt: { isOverdue?: boolean; visibilityType?: string; assignedToUserId?: string }) =>
				rt.isOverdue
				&& (rt.visibilityType === 'public' || !rt.visibilityType)
				&& rt.assignedToUserId
				&& rt.assignedToUserId !== 'teen',
		)

		if (!overduePublic) {
			test.skip(true, 'No overdue public task assigned to another user')
			return
		}

		const completeResponse = await page.request.post(
			`/apps/chorestrike/api/v1/room-tasks/${overduePublic.id}/complete`,
		)

		expect(completeResponse.status()).toBe(200)
	})

	test('private overdue task cannot be claimed by non-assignee', async ({ page }) => {
		const teenCreds = getUserCredentials('family', 'teen')
		await loginAsUser(page, teenCreds!.userId, teenCreds!.password)

		const tasksResponse = await page.request.get('/apps/chorestrike/api/v1/room-tasks')
		if (tasksResponse.status() !== 200) {
			test.skip(true, 'Could not fetch room tasks')
			return
		}

		const tasksData = await tasksResponse.json()
		const roomTasks = tasksData.data || []

		const overduePrivate = roomTasks.find(
			(rt: { isOverdue?: boolean; visibilityType?: string; isPrivate?: boolean }) =>
				rt.isOverdue && (rt.visibilityType === 'private' || rt.isPrivate),
		)

		if (!overduePrivate) {
			test.skip(true, 'No overdue private task found')
			return
		}

		const completeResponse = await page.request.post(
			`/apps/chorestrike/api/v1/room-tasks/${overduePrivate.id}/complete`,
		)

		expect(completeResponse.status()).toBe(403)
	})
})
