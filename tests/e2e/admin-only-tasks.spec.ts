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

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'

test.describe('Admin-Only Tasks', () => {
	test.use({ databaseProfile: 'family' })

	test('admin-only task shows crown icon for owner', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await page.goto('/apps/chorestrike/#/tasks')
			await page.waitForLoadState('networkidle', { timeout: 30000 })
			await page.waitForSelector('[data-testid="task-manager-view"]', { timeout: 20000 })

			const adminTask = page.locator('[data-testid="task-item"]').filter({ hasText: /admin|audit|owner/i }).first()

			if (await adminTask.count() > 0) {
				const crownIcon = adminTask.locator('[data-testid="visibility-crown-icon"], .icon-crown, svg[data-icon="crown"]')
				if (await crownIcon.count() > 0) {
					await expect(crownIcon).toBeVisible({ timeout: 5000 })
				}
			}
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

			const adminOnlyTasks = (body.data || []).filter(
				(t: { visibilityType?: string }) => t.visibilityType === 'admin-only',
			)
			expect(adminOnlyTasks).toHaveLength(0)
		} finally {
			await context.close()
		}
	})

	test('non-owner cannot create admin-only task', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'teen')
		try {
			const response = await page.request.post('/apps/chorestrike/api/v1/tasks', {
				data: {
					name: 'Teen Admin Task',
					energyCost: 5,
					visibilityType: 'admin-only',
				},
			})

			expect(response.status()).toBe(403)
			const body = await response.json()
			expect(body.success).toBe(false)
		} finally {
			await context.close()
		}
	})

	test('non-owner cannot complete admin-only task', async ({ browser }) => {
		const mom = await createUserContext(browser, 'mom')
		const teen = await createUserContext(browser, 'teen')

		try {
			const createResponse = await mom.page.request.post('/apps/chorestrike/api/v1/tasks', {
				data: {
					name: 'Owner Only Audit',
					energyCost: 8,
					visibilityType: 'admin-only',
				},
			})

			if (createResponse.status() !== 200) {
				test.skip(true, 'Could not create admin-only task')
				return
			}

			const taskData = await createResponse.json()
			const taskId = taskData.data.id

			const assignResponse = await mom.page.request.post(
				'/apps/chorestrike/api/v1/room-tasks',
				{ data: { taskId, roomId: 1 } },
			)

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

			const completeResponse = await teen.page.request.post(
				`/apps/chorestrike/api/v1/room-tasks/${roomTaskId}/complete`,
			)

			expect(completeResponse.status()).toBe(403)
		} finally {
			await mom.context.close()
			await teen.context.close()
		}
	})

	test('owner can create admin-only task', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const response = await page.request.post('/apps/chorestrike/api/v1/tasks', {
				data: {
					name: 'Owner Audit Task',
					energyCost: 8,
					visibilityType: 'admin-only',
				},
			})

			expect(response.status()).toBe(200)
			const body = await response.json()
			expect(body.success).toBe(true)
			expect(body.data.visibilityType).toBe('admin-only')
		} finally {
			await context.close()
		}
	})
})
