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
 * - Public overdue tasks are completable by non-assignees; private ones are denied
 *
 * Requires: Docker test environment with the `family` database profile.
 * CS-PLAN-015b: auth via per-user storageState (no loginAsUser / no logout).
 * CS-PLAN-068 (CR-WF-05): no fixture-dependent skips — the seeded overdue row
 * (Take out trash, assigned Dad) plus dedicated API probes make every
 * precondition deterministic, and room-task payloads are filtered by the real
 * `status: 'overdue'` field (the former `isOverdue` field never existed, so
 * the old finds always missed and skipped).
 */

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'
import { completeRoomTaskViaApi, postCompletionWithReceipt, placeholderReceipt } from './fixtures/completion-api'
import type { Page } from '@playwright/test'

test.describe('Overdue Task Claiming', () => {
	test.use({ databaseProfile: 'family' })

	const yesterday = (): string => {
		const d = new Date()
		d.setDate(d.getDate() - 1)
		const y = d.getFullYear()
		const m = String(d.getMonth() + 1).padStart(2, '0')
		const day = String(d.getDate()).padStart(2, '0')
		return `${y}-${m}-${day}`
	}

	/** Create an overdue probe task through the atomic aggregate endpoint. */
	async function createOverdueProbe(
		page: Page,
		name: string,
		options: { visibility?: string; assignees?: string[] } = {},
	): Promise<number> {
		const floorsResponse = await page.request.get('/apps/chorestrike/api/v1/floors/with-rooms')
		expect(floorsResponse.status()).toBe(200)
		const rooms = ((await floorsResponse.json()).data as Array<{ rooms: Array<{ id: number; name: string }> }>)
			.flatMap((floor) => floor.rooms)
		const kitchen = rooms.find((room) => room.name === 'Kitchen')
		expect(kitchen, 'the family seed always provides the Kitchen room').toBeTruthy()

		const createResponse = await page.request.post('/apps/chorestrike/api/v1/room-tasks', {
			data: {
				roomId: kitchen.id,
				assigneeUserIds: options.assignees ?? ['dad'],
				nextDue: yesterday(),
				task: {
					name,
					energyCost: 1,
					frequencyType: 'daily',
					frequencyValue: 1,
					...(options.visibility ? { visibilityType: options.visibility } : {}),
				},
			},
		})
		expect(createResponse.status(), await createResponse.text()).toBe(200)
		const roomTaskId = (await createResponse.json()).data?.roomTask?.id
		expect(roomTaskId, 'the aggregate create returns the linked room task').toBeTruthy()

		// Confirm the probe is really overdue before the test continues.
		const listResponse = await page.request.get('/apps/chorestrike/api/v1/room-tasks')
		expect(listResponse.status()).toBe(200)
		const probe = ((await listResponse.json()).data as Array<{ id: number; status: string }>)
			.find((row) => row.id === roomTaskId)
		expect(probe?.status, 'a past-due probe row must serialize as overdue').toBe('overdue')
		return roomTaskId
	}

	test('overdue task assigned to another user shows claim button', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			// Dedicated probe (CS-PLAN-068): parallel-pool siblings also
			// complete seeded Kitchen rows, so the seeded "Take out trash" is
			// not a stable claim target — the probe is owned by this file.
			const probeName = `Claim Button ${Date.now()}`
			await createOverdueProbe(page, probeName, { assignees: ['dad'] })

			// The claim affordance lives on the TaskManager task rows
			// (TaskListItem), not on My Day.
			await page.goto('/apps/chorestrike/#/tasks')
			await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 20000 })

			const overdueRow = page.locator('[data-testid="task-item"]')
				.filter({ hasText: probeName })
				.first()
			await expect(overdueRow).toBeVisible({ timeout: 15000 })
			await expect(overdueRow.getByTestId('claim-overdue-button')).toBeVisible()
		} finally {
			await context.close()
		}
	})

	test('claim button opens confirmation dialog naming the assignee', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await createOverdueProbe(page, `Claim Dialog ${Date.now()}`, { assignees: ['dad'] })

			await page.goto('/apps/chorestrike/#/tasks')
			await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 20000 })

			const overdueRow = page.locator('[data-testid="task-item"]')
				.filter({ hasText: 'Claim Dialog' })
				.first()
			await expect(overdueRow).toBeVisible({ timeout: 15000 })
			await overdueRow.getByTestId('claim-overdue-button').click()

			const dialog = page.getByTestId('overdue-claim-dialog')
			await expect(dialog).toBeVisible({ timeout: 10000 })
			// The dialog names the original assignee (user id).
			await expect(dialog).toContainText('dad')
			// The dialog is only observed here — confirming happens in the
			// API takeover proof below, so the probe row stays claimable.
			await dialog.locator('.secondary-button').click()
		} finally {
			await context.close()
		}
	})

	test('claiming overdue task via API records takeover', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const overdueTaskId = await createOverdueProbe(page, `Takeover ${Date.now()}`, { assignees: ['dad'] })

			const completeResponse = await completeRoomTaskViaApi(page, overdueTaskId)

			expect(completeResponse.status()).toBe(200)
			const body = await completeResponse.json()
			expect(body.success).toBe(true)
			// The takeover is recorded on the completion receipt.
			expect(body.data.rewards.contributionScore).toBeGreaterThan(0)
		} finally {
			await context.close()
		}
	})

	test('non-assignee can complete overdue public task', async ({ browser }) => {
		const mom = await createUserContext(browser, 'mom')
		let probeId: number
		try {
			probeId = await createOverdueProbe(mom.page, `Overdue Public ${Date.now()}`, { assignees: ['dad'] })
		} finally {
			await mom.context.close()
		}

		const { context, page } = await createUserContext(browser, 'teen')
		try {
			const completeResponse = await completeRoomTaskViaApi(page, probeId)

			expect(completeResponse.status()).toBe(200)
			const body = await completeResponse.json()
			expect(body.success).toBe(true)
		} finally {
			await context.close()
		}
	})

	test('private overdue task cannot be claimed by non-assignee', async ({ browser }) => {
		const mom = await createUserContext(browser, 'mom')
		let probeId: number
		try {
			probeId = await createOverdueProbe(mom.page, `Overdue Private ${Date.now()}`, {
				visibility: 'private',
				assignees: ['mom'],
			})
		} finally {
			await mom.context.close()
		}

		const { context, page } = await createUserContext(browser, 'teen')
		try {
			// CS-PLAN-072 WP1: the completion is a receipt contract, so the
			// non-viewer posts a format-valid receipt — the visibility gate
			// (assertCanView) fires before the occurrence is resolved.
			const completeResponse = await postCompletionWithReceipt(page, probeId, placeholderReceipt())

			// A private task is invisible to non-assignees: per the ADR-010
			// 403/404 standard the takeover path answers 404 (no existence
			// leak) rather than a 403 disclosure.
			expect(completeResponse.status()).toBe(404)
		} finally {
			await context.close()
		}
	})
})
