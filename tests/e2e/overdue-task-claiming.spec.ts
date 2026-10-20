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
import { completeAsSoleParticipantViaApi, postCompletionWithReceipt, placeholderReceipt } from './fixtures/completion-api'
import type { Page } from '@playwright/test'
import { houseDateOffset } from './fixtures/house-clock.js'

test.describe('Overdue Task Claiming', () => {
	test.use({ databaseProfile: 'family' })

	// The family seed house runs in UTC (same clock as family-rhythm.spec).
	const dayKey = (offsetDays: number): string => houseDateOffset(offsetDays, 'UTC')
	const yesterday = (): string => dayKey(-1)

	/** Create a probe task (overdue by default) through the atomic aggregate endpoint. */
	async function createOverdueProbe(
		page: Page,
		name: string,
		options: { visibility?: string; assignees?: string[]; nextDue?: string; expectedStatus?: string } = {},
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
				nextDue: options.nextDue ?? yesterday(),
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
		expect(probe?.status, 'the probe row must serialize with its house-local status')
			.toBe(options.expectedStatus ?? 'overdue')
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
			await page.getByTestId('tasks-filter-all').click()

			const overdueRow = page.locator('[data-testid="task-item"]')
				.filter({ hasText: probeName })
				.first()
			await expect(overdueRow).toBeVisible({ timeout: 15000 })
			await expect(overdueRow.getByTestId('claim-overdue-button')).toBeVisible()
		} finally {
			await context.close()
		}
	})

	test('work due today is never overdue: no claim button for others (D-084-08)', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const probeName = `Due Today ${Date.now()}`
			await createOverdueProbe(page, probeName, { assignees: ['dad'], nextDue: dayKey(0), expectedStatus: 'due_today' })

			await page.goto('/apps/chorestrike/#/tasks')
			await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 20000 })
			await page.getByTestId('tasks-filter-all').click()
			const row = page.locator('[data-testid="task-item"]').filter({ hasText: probeName }).first()
			await expect(row.getByTestId('task-assignees')).toContainText('Dad', { timeout: 15000 })
			await expect(row).not.toHaveClass(/overdue/)
			await expect(row.getByTestId('claim-overdue-button')).toHaveCount(0)
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
			await page.getByTestId('tasks-filter-all').click()

			const overdueRow = page.locator('[data-testid="task-item"]')
				.filter({ hasText: 'Claim Dialog' })
				.first()
			await expect(overdueRow).toBeVisible({ timeout: 15000 })
			// The claim dialog snapshots the assignee name when it opens, so
			// gate on the row's resolved display name first (TaskManager
			// loads members on mount; the raw fallback would be the lower-
			// case user id and never re-renders inside the open dialog).
			await expect(overdueRow.getByTestId('task-assignees')).toContainText('Dad')
			await overdueRow.getByTestId('claim-overdue-button').click()

			const dialog = page.getByTestId('overdue-claim-dialog')
			await expect(dialog).toBeVisible({ timeout: 10000 })
			// The member list resolves the original assignee's display name.
			await expect(dialog).toContainText('Dad')
			// The dialog is only observed here — confirming happens in the
			// API takeover proof below, so the probe row stays claimable.
			await dialog.locator('.secondary-button').click()
		} finally {
			await context.close()
		}
	})

	test('confirming a claim in the UI completes it once and the next occurrence returns to the assignee', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const probeName = `Claim Confirm ${Date.now()}`
			const roomTaskId = await createOverdueProbe(page, probeName, { assignees: ['dad'] })

			await page.goto('/apps/chorestrike/#/tasks')
			await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 20000 })
			await page.getByTestId('tasks-filter-all').click()
			const overdueRow = page.locator('[data-testid="task-item"]').filter({ hasText: probeName }).first()
			await expect(overdueRow.getByTestId('task-assignees')).toContainText('Dad', { timeout: 15000 })

			const completion = page.waitForResponse((response) => response.request().method() === 'POST'
				&& response.url().includes(`/room-tasks/${roomTaskId}/complete`))
			await overdueRow.getByTestId('claim-overdue-button').click()
			await page.getByTestId('overdue-claim-dialog').getByTestId('confirm-claim-button').click()
			// One confirmation is the reviewed allocation: no second participation dialog.
			expect((await completion).status()).toBe(200)
			await expect(page.getByTestId('participation-dialog')).toHaveCount(0)
			await expect(page.getByTestId(`undo-snackbar-entry-${roomTaskId}`)).toBeVisible({ timeout: 10000 })

			const rows = await page.request.get('/apps/chorestrike/api/v1/room-tasks')
			const row = ((await rows.json()).data as Array<{ id: number; wasClaimed: boolean; claimedByUser: string;
				originalAssignee: string; assignees?: string[]; status: string }>).find((item) => item.id === roomTaskId)
			expect(row).toMatchObject({ wasClaimed: true, claimedByUser: 'mom', originalAssignee: 'dad' })
			expect(row?.status, 'the claimed occurrence is done; the next one is not overdue').not.toBe('overdue')
			await expect(overdueRow.getByTestId('claim-overdue-button')).toHaveCount(0)
			await expect(overdueRow.getByTestId('task-assignees')).toContainText('Dad')
		} finally {
			await context.close()
		}
	})

	test('claiming overdue task via API records takeover', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const overdueTaskId = await createOverdueProbe(page, `Takeover ${Date.now()}`, { assignees: ['dad'] })

			// WP3 (Q-074-02): the non-assignee takes part explicitly — the
			// split names mom as the sole participant of the takeover.
			const completeResponse = await completeAsSoleParticipantViaApi(page, overdueTaskId, 'mom')

			expect(completeResponse.status()).toBe(200)
			const body = await completeResponse.json()
			expect(body.success).toBe(true)
			// The takeover is recorded on the completion receipt.
			expect(body.data.rewards.completion.totalEnergy).toBeGreaterThan(0)
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
			// WP3 (Q-074-02): the non-assignee takes part explicitly.
			const completeResponse = await completeAsSoleParticipantViaApi(page, probeId, 'teen')

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
