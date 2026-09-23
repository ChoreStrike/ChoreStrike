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
 * Auth via `createUserContext` (fresh login sessions — CS-PLAN-068: the
 * saved-storageState contexts cannot call the JSON API, the container's
 * CSRF gate answers 412; every green API spec uses fresh sessions).
 * CS-PLAN-068 (CR-WF-05): no fixture-dependent skips — the room is resolved
 * at runtime (the DELETE-based reset never restarts AUTO_INCREMENT) and a
 * failed precondition is a FAILURE. Reward arithmetic stays owned by the
 * frozen-rule unit tests; these receipts pin the teamworkBonus contract.
 */

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'
import { completeRoomTaskViaApi } from './fixtures/completion-api'
import { houseDateOffset } from './fixtures/house-clock.js'
import type { Page } from '@playwright/test'

test.describe('Teamwork Rewards', () => {
	test.use({ databaseProfile: 'family' })

	async function kitchenRoom(page: Page): Promise<number> {
		const floorsResponse = await page.request.get('/apps/chorestrike/api/v1/floors/with-rooms')
		expect(floorsResponse.status()).toBe(200)
		const rooms = ((await floorsResponse.json()).data as Array<{ rooms: Array<{ id: number; name: string }> }>)
			.flatMap((floor) => floor.rooms)
		const kitchen = rooms.find((room) => room.name === 'Kitchen')
		expect(kitchen, 'the family seed always provides the Kitchen room').toBeTruthy()
		return kitchen!.id
	}

	async function createProbeTask(page: Page, name: string, assignees: string[]): Promise<number> {
		const roomId = await kitchenRoom(page)
		const createResponse = await page.request.post('/apps/chorestrike/api/v1/room-tasks', {
			data: {
				roomId,
				assigneeUserIds: assignees,
				// CS-PLAN-072 WP1: due TODAY (the creation default is tomorrow)
				// so the occurrence window is open and the target is completable.
				nextDue: houseDateOffset(0, 'UTC'),
				task: {
					name,
					energyCost: 2,
					frequencyType: 'weekly',
					frequencyValue: 1,
				},
			},
		})
		expect(createResponse.status(), await createResponse.text()).toBe(200)
		const roomTaskId = (await createResponse.json()).data?.roomTask?.id
		expect(roomTaskId, 'the aggregate create returns the linked room task').toBeTruthy()
		return roomTaskId
	}

	test('multi-assign task shows teamwork bonus hint', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const roomTaskId = await createProbeTask(page, `Team Clean ${Date.now()}`, ['mom', 'dad'])

			await page.goto('/apps/chorestrike/#/tasks')
			await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 20000 })

			const teamTask = page.locator('[data-testid="task-item"]').filter({ hasText: 'Team Clean' }).first()
			await expect(teamTask).toBeVisible({ timeout: 15000 })
			await expect(teamTask.locator('.teamwork-hint')).toContainText('Teamwork bonus')
			// The list identity is stable for the completed assertion below.
			expect(roomTaskId).toBeGreaterThan(0)
		} finally {
			await context.close()
		}
	})

	test('completing multi-assign task returns teamwork bonus via API', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const roomTaskId = await createProbeTask(page, `Team Bonus ${Date.now()}`, ['mom', 'dad'])

			// Explicit second assignee through the canonical assignees endpoint.
			const assignResponse = await page.request.put(`/apps/chorestrike/api/v1/room-tasks/${roomTaskId}/assignees`, {
				data: { userIds: ['mom', 'dad'] },
			})
			expect(assignResponse.status()).toBe(200)
			expect(await assignResponse.json()).toEqual({ success: true, data: ['mom', 'dad'] })

			const completeResponse = await completeRoomTaskViaApi(page, roomTaskId)

			expect(completeResponse.status()).toBe(200)
			const body = await completeResponse.json()
			expect(body.success).toBe(true)
			// Two participants → a strictly positive per-participant bonus.
			expect(body.data?.rewards?.teamworkBonus).toBeGreaterThan(0)
		} finally {
			await context.close()
		}
	})

	test('single-assign task has no teamwork bonus', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const roomTaskId = await createProbeTask(page, `Solo Task ${Date.now()}`, ['mom'])

			const completeResponse = await completeRoomTaskViaApi(page, roomTaskId)

			expect(completeResponse.status()).toBe(200)
			const body = await completeResponse.json()
			expect(body.success).toBe(true)
			expect(body.data.rewards.teamworkBonus).toBe(0)
		} finally {
			await context.close()
		}
	})
})
