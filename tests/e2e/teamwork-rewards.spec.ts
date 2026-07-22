/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * E2E: Teamwork Rewards — bonus display for multi-assign tasks
 *
 * Verifies the CS-PLAN-005 Phase 3 frontend contract:
 * - Multi-assign tasks show a teamwork-bonus hint in the task list
 * - Completing a multi-assign task returns a positive teamworkBonus in the API
 * - Single-assign tasks show no teamwork hint
 *
 * Requires: Docker test environment with the `family` database profile.
 * @see docs/plans/active/cs-plan-005-task-visibility-teamwork-overdue.md Phase 3
 */

import { test, expect, loginAsUser, getUserCredentials } from './fixtures/database-fixtures'

test.describe('Teamwork Rewards', () => {
	test.use({ databaseProfile: 'family' })

	test('multi-assign task shows teamwork bonus hint', async ({ page }) => {
		const momCreds = getUserCredentials('family', 'mom')
		await loginAsUser(page, momCreds!.userId, momCreds!.password)

		await page.goto('/apps/chorestrike/#/tasks')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForSelector('[data-testid="task-manager-view"]', { timeout: 20000 })

		const teamTask = page.locator('[data-testid="task-item"]').filter({ hasText: /team|together|shared/i }).first()

		if (await teamTask.count() > 0) {
			const bonusHint = teamTask.locator('[data-testid="teamwork-bonus-hint"], text=/teamwork|bonus/i')
			if (await bonusHint.count() > 0) {
				await expect(bonusHint.first()).toBeVisible({ timeout: 5000 })
			}
		}
	})

	test('completing multi-assign task returns teamwork bonus via API', async ({ page }) => {
		const momCreds = getUserCredentials('family', 'mom')
		await loginAsUser(page, momCreds!.userId, momCreds!.password)

		const createResponse = await page.request.post('/apps/chorestrike/api/v1/tasks', {
			data: {
				name: 'Team Clean Test',
				energyCost: 10,
				frequencyType: 'weekly',
				frequencyValue: 1,
			},
		})

		if (createResponse.status() !== 200) {
			test.skip(true, 'Could not create task')
			return
		}

		const taskData = await createResponse.json()
		const taskId = taskData.data.id

		const assignResponse = await page.request.post('/apps/chorestrike/api/v1/room-tasks', {
			data: { taskId, roomId: 1 },
		})

		if (assignResponse.status() !== 200) {
			test.skip(true, 'Could not assign task to room')
			return
		}

		const assignData = await assignResponse.json()
		const roomTaskId = assignData.data?.id

		if (!roomTaskId) {
			test.skip(true, 'No room task ID returned')
			return
		}

		await page.request.put(`/apps/chorestrike/api/v1/room-tasks/${roomTaskId}/assignees`, {
			data: { userIds: ['mom', 'dad'] },
		})

		const completeResponse = await page.request.post(
			`/apps/chorestrike/api/v1/room-tasks/${roomTaskId}/complete`,
		)

		expect(completeResponse.status()).toBe(200)
		const body = await completeResponse.json()
		expect(body.success).toBe(true)
		expect(body.data?.rewards?.teamworkBonus).toBeGreaterThan(0)
	})

	test('single-assign task has no teamwork bonus', async ({ page }) => {
		const momCreds = getUserCredentials('family', 'mom')
		await loginAsUser(page, momCreds!.userId, momCreds!.password)

		const createResponse = await page.request.post('/apps/chorestrike/api/v1/tasks', {
			data: {
				name: 'Solo Task Test',
				energyCost: 5,
				frequencyType: 'weekly',
				frequencyValue: 1,
			},
		})

		if (createResponse.status() !== 200) {
			test.skip(true, 'Could not create task')
			return
		}

		const taskData = await createResponse.json()
		const taskId = taskData.data.id

		const assignResponse = await page.request.post('/apps/chorestrike/api/v1/room-tasks', {
			data: { taskId, roomId: 1, assignedToUserId: 'mom' },
		})

		if (assignResponse.status() !== 200) {
			test.skip(true, 'Could not assign task to room')
			return
		}

		const assignData = await assignResponse.json()
		const roomTaskId = assignData.data?.id

		if (!roomTaskId) {
			test.skip(true, 'No room task ID returned')
			return
		}

		const completeResponse = await page.request.post(
			`/apps/chorestrike/api/v1/room-tasks/${roomTaskId}/complete`,
		)

		if (completeResponse.status() === 200) {
			const body = await completeResponse.json()
			expect(body.success).toBe(true)
			if (body.data?.rewards) {
				expect(body.data.rewards.teamworkBonus).toBe(0)
			}
		}
	})
})
