/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * E2E: Admin-Only Tasks — crown icon, owner-gated creation and completion
 *
 * Verifies the CS-PLAN-005 Phase 3 frontend contract:
 * - Admin-only tasks show a crown icon in the task list
 * - Non-owners cannot see admin-only tasks via the API
 * - Non-owners cannot create admin-only tasks (403)
 * - Non-owners cannot complete admin-only tasks (403)
 *
 * Requires: Docker test environment with the `family` database profile.
 * CS-PLAN-015b: auth via per-user storageState (no loginAsUser / no logout).
 * @see docs/plans/active/cs-plan-005-task-visibility-teamwork-overdue.md Phase 3
 */

import { createUserContext } from './fixtures/auth-contexts'
import { placeholderReceipt, postCompletionWithReceipt } from './fixtures/completion-api'
import { expect, test } from './fixtures/database-fixtures'
import { createVisibilityFixture } from './fixtures/visibility-fixtures'

test.describe('Admin-Only Tasks', () => {
	test.use({ databaseProfile: 'family' })

	test('admin-only task shows crown icon for owner', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			// De-vacuoused (CS-PLAN-051): assert on an API-created fixture row,
			// not on whatever the seed happens to contain. CS-PLAN-068: the
			// fixture THROWS on a failed precondition — the failure is loud.
			const probeName = `Admin Crown Probe ${Date.now()}`
			await createVisibilityFixture(page, probeName, 'admin-only')

			await page.goto('/apps/chorestrike/#/tasks')
			await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 20000 })

			const row = page.getByTestId('task-item').filter({ hasText: probeName }).first()
			await expect(row).toBeVisible({ timeout: 15000 })
			await expect(row.locator('[data-testid="visibility-crown-icon"]')).toBeVisible()
		} finally {
			await context.close()
		}
	})

	test('non-owner cannot see admin-only tasks via API', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'teen')
		try {
			const response = await page.request.get('/apps/chorestrike/api/v1/tasks')
			expect(response.status()).toBe(200)

			const body = await response.json()
			expect(body.success).toBe(true)

			const adminOnlyTasks = (body.data || []).filter((t: { visibilityType?: string }) => t.visibilityType === 'admin-only',)
			expect(adminOnlyTasks).toHaveLength(0)
		} finally {
			await context.close()
		}
	})

	test('non-owner cannot create admin-only task', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'teen')
		try {
			// CS-PLAN-063: creation runs through the atomic aggregate; the
			// admin-only gate needs a valid room (the room-ownership check
			// precedes the visibility gate — 404 would fire first).
			const floorsResponse = await page.request.get('/apps/chorestrike/api/v1/floors/with-rooms', {
				headers: { Accept: 'application/json' },
			})
			const roomId = ((await floorsResponse.json()).data ?? [])
				.flatMap((entry: { rooms?: Array<{ id: number }> }) => entry.rooms ?? [])[0]?.id
			expect(roomId, 'family seed must provide a room').toBeTruthy()

			const response = await page.request.post('/apps/chorestrike/api/v1/room-tasks', {
				data: {
					roomId,
					task: {
						name: 'Teen Admin Task',
						energyCost: 5,
						visibilityType: 'admin-only',
					},
				},
				headers: { Accept: 'application/json' },
			})

			expect(response.status()).toBe(403)
		} finally {
			await context.close()
		}
	})

	test('non-owner cannot complete admin-only task', async ({ browser }) => {
		const mom = await createUserContext(browser, 'mom')
		const teen = await createUserContext(browser, 'teen')

		try {
			const floorsResponse = await mom.page.request.get('/apps/chorestrike/api/v1/floors/with-rooms', {
				headers: { Accept: 'application/json' },
			})
			expect(floorsResponse.status()).toBe(200)
			const roomId = ((await floorsResponse.json()).data ?? [])
				.flatMap((entry: { rooms?: Array<{ id: number }> }) => entry.rooms ?? [])[0]?.id
			expect(roomId, 'family seed must provide a room').toBeTruthy()

			// CS-PLAN-063: one aggregate request creates definition + room task.
			const createResponse = await mom.page.request.post('/apps/chorestrike/api/v1/room-tasks', {
				data: {
					roomId,
					task: {
						name: 'Owner Only Audit',
						energyCost: 5,
						visibilityType: 'admin-only',
					},
				},
				headers: { Accept: 'application/json' },
			})

			expect(createResponse.status()).toBe(200)

			const roomTaskId = (await createResponse.json()).data?.roomTask?.id

			expect(roomTaskId).toBeTruthy()

			const completeResponse = await postCompletionWithReceipt(teen.page, roomTaskId, placeholderReceipt())

			// ADR-010 decision D3: an invisible task "does not exist" for the
			// non-owner — 404 anti-enumeration, never a 403 that would
			// confirm the room-task id exists. CS-PLAN-072 WP1: the receipt
			// is format-valid; the visibility gate fires before the
			// occurrence is resolved.
			expect(completeResponse.status()).toBe(404)
		} finally {
			await mom.context.close()
			await teen.context.close()
		}
	})

	test('owner can create admin-only task', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const floorsResponse = await page.request.get('/apps/chorestrike/api/v1/floors/with-rooms', {
				headers: { Accept: 'application/json' },
			})
			const roomId = ((await floorsResponse.json()).data ?? [])
				.flatMap((entry: { rooms?: Array<{ id: number }> }) => entry.rooms ?? [])[0]?.id
			expect(roomId, 'family seed must provide a room').toBeTruthy()

			const response = await page.request.post('/apps/chorestrike/api/v1/room-tasks', {
				data: {
					roomId,
					task: {
						name: 'Owner Audit Task',
						energyCost: 5,
						visibilityType: 'admin-only',
					},
				},
			})

			expect(response.status()).toBe(200)
			const body = await response.json()
			expect(body.success).toBe(true)
			expect(body.data.task.visibilityType).toBe('admin-only')
			expect(body.data.roomTask.taskId).toBe(body.data.task.id)
		} finally {
			await context.close()
		}
	})
})
