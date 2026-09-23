/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Family rhythm — occurrence timeline WP1 slice (CS-PLAN-072).
 *
 * Browser/HTTP-seam proofs of the occurrence receipt contract the UI store
 * consumes (parent CS-PLAN-070 §3.4):
 * - A completion from the real UI carries the FULL receipt (a fresh
 *   `commandId` UUID, the shown target's public `occurrenceId` key, its
 *   `expectedVersion` and the server `selectionToken`) — the client never
 *   invents work time or target effort.
 * - A NEW command on an already-fulfilled occurrence answers 409 — the
 *   server never silently credits a different target (occurrence validity
 *   replaces the former day-duplicate guard).
 * - A REPLAY of the same command returns the original completion identity
 *   with `replay: true` and writes no second ledger row.
 *
 * The deep enumeration / revision / catch-up / zone-change math and the
 * occurrence-isolated checklist semantics are owned by the service-level
 * `OccurrenceTimelineTest` and the `RecurrenceCalculatorTest` / store unit
 * suites; this slice pins the client-visible contract only (extend-first,
 * never duplicate the lock choreography).
 *
 * Seed-mutating: registered in `SEED_DEPENDENT_SPECS` (chromium-serial-db).
 * Requires: Docker test environment with the `family` database profile.
 */

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'
import { expandAllFloors } from './fixtures/dashboard-floors'
import { houseDateOffset } from './fixtures/house-clock.js'
import {
	resolveOccurrenceReceipt,
	postCompletionWithReceipt,
	newCommandId,
} from './fixtures/completion-api'
import type { Page } from '@playwright/test'

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
const OCCURRENCE_KEY_RE = /^\d+:\d+$/

/** Resolve the Kitchen room at runtime (the DELETE-based reset never restarts AUTO_INCREMENT). */
async function kitchenRoom(page: Page): Promise<{ id: number }> {
	const floorsResponse = await page.request.get('/apps/chorestrike/api/v1/floors/with-rooms', {
		headers: { Accept: 'application/json' },
	})
	expect(floorsResponse.status()).toBe(200)
	const rooms = ((await floorsResponse.json()).data as Array<{ rooms: Array<{ id: number; name: string }> }>)
		.flatMap((floor) => floor.rooms)
	const kitchen = rooms.find((room) => room.name === 'Kitchen')
	expect(kitchen, 'the family seed always provides the Kitchen room').toBeTruthy()
	return kitchen!
}

/** Create a daily probe task DUE TODAY (house-local), assigned to `assignees`,
 * via the atomic aggregate. The explicit `nextDue` overrides the creation
 * default (tomorrow) so the occurrence window is open and the target is
 * completable/amendable now (CS-PLAN-072 WP1). */
async function createProbeTask(page: Page, name: string, assignees: string[]): Promise<number> {
	const kitchen = await kitchenRoom(page)
	const createResponse = await page.request.post('/apps/chorestrike/api/v1/room-tasks', {
		data: {
			roomId: kitchen.id,
			assigneeUserIds: assignees,
			nextDue: houseDateOffset(0, 'UTC'),
			task: { name, energyCost: 1, frequencyType: 'daily', frequencyValue: 1 },
		},
		headers: { Accept: 'application/json' },
	})
	expect(createResponse.status(), await createResponse.text()).toBe(200)
	const roomTaskId = (await createResponse.json()).data?.roomTask?.id
	expect(roomTaskId, 'the aggregate create returns the linked room task').toBeTruthy()
	return roomTaskId as number
}

test.describe('Family rhythm — occurrence timeline (WP1)', () => {
	test.use({ databaseProfile: 'family' })

	test('a UI completion carries the full occurrence receipt', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await page.goto('/apps/chorestrike/')
			await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 30000 })
			await expandAllFloors(page)
			await page.getByTestId('room-card-Kitchen').first().click()
			await expect(page.getByTestId('room-detail-view')).toBeVisible({ timeout: 15000 })

			const completeButton = page.getByTestId('complete-task-button').first()
			await expect(completeButton).toBeVisible({ timeout: 15000 })

			// Capture the request body the store sends and the response it gets.
			const requestPromise = page.waitForRequest((request) => {
				const pathname = new URL(request.url()).pathname
				return request.method() === 'POST' && pathname.endsWith('/complete')
			})
			const responsePromise = page.waitForResponse((response) => {
				const pathname = new URL(response.url()).pathname
				return response.request().method() === 'POST' && pathname.endsWith('/complete')
			})
			await completeButton.click()

			const request = await requestPromise
			const body = request.postDataJSON() as Record<string, unknown>
			// The closed-set receipt contract: all four fields, well-formed.
			expect(typeof body.commandId).toBe('string')
			expect(body.commandId as string).toMatch(UUID_RE)
			expect(typeof body.occurrenceId).toBe('string')
			expect(body.occurrenceId as string).toMatch(OCCURRENCE_KEY_RE)
			expect(Number.isInteger(body.expectedVersion)).toBe(true)
			expect(typeof body.selectionToken).toBe('string')
			expect((body.selectionToken as string).length).toBeGreaterThan(0)

			const response = await responsePromise
			expect(response.status()).toBe(200)
			const receipt = (await response.json()).data
			expect(receipt.replay).toBe(false)
			// The response carries the fresh-selection contract key. It may be
			// null when the next target's window is not open yet (the fulfilled
			// occurrence never rides back as the shown target).
			expect('occurrence' in receipt).toBe(true)
		} finally {
			await context.close()
		}
	})

	test('a new command on a fulfilled occurrence conflicts 409, never credits another target', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const roomTaskId = await createProbeTask(page, `Fulfilled Target ${Date.now()}`, ['mom'])

			// Resolve the shown target, then fulfill it through the real contract.
			const receipt = await resolveOccurrenceReceipt(page, roomTaskId)
			expect(receipt, 'the probe must expose a completable occurrence').not.toBeNull()
			const first = await postCompletionWithReceipt(page, roomTaskId, receipt!)
			expect(first.status()).toBe(200)
			expect((await first.json()).data.replay).toBe(false)

			// A NEW command on the SAME (now fulfilled) target: 409, and the
			// server never silently credits a different occurrence.
			const second = await postCompletionWithReceipt(page, roomTaskId, receipt!)
			expect(second.status()).toBe(409)
			const conflictBody = await second.json()
			expect(conflictBody.success).toBe(false)
			expect(conflictBody.error.message).toBe('This occurrence is already completed')
		} finally {
			await context.close()
		}
	})

	test('replaying the same command returns the original identity without a second effect', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const roomTaskId = await createProbeTask(page, `Replay Probe ${Date.now()}`, ['mom'])
			const receipt = await resolveOccurrenceReceipt(page, roomTaskId)
			expect(receipt, 'the probe must expose a completable occurrence').not.toBeNull()

			// One logical command, sent twice with the SAME commandId.
			const commandId = newCommandId()
			const first = await page.request.post(`/apps/chorestrike/api/v1/room-tasks/${roomTaskId}/complete`, {
				data: { commandId, ...receipt! },
				headers: { Accept: 'application/json' },
			})
			expect(first.status()).toBe(200)
			const firstData = (await first.json()).data
			expect(firstData.replay).toBe(false)
			expect(firstData.completionId).toBeTruthy()

			const replay = await page.request.post(`/apps/chorestrike/api/v1/room-tasks/${roomTaskId}/complete`, {
				data: { commandId, ...receipt! },
				headers: { Accept: 'application/json' },
			})
			expect(replay.status()).toBe(200)
			const replayData = (await replay.json()).data
			expect(replayData.replay).toBe(true)
			// The replay returns the ORIGINAL completion identity — no second
			// ledger row, no repeated post-commit effects.
			expect(replayData.completionId).toBe(firstData.completionId)
		} finally {
			await context.close()
		}
	})

	test('a stale receipt after a due-date move conflicts 409', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const roomTaskId = await createProbeTask(page, `Stale Receipt ${Date.now()}`, ['mom'])

			// Capture the shown target, then move the due date behind its back
			// (the agreement writer amends the occurrence and bumps its version).
			const staleReceipt = await resolveOccurrenceReceipt(page, roomTaskId)
			expect(staleReceipt, 'the probe must expose a completable occurrence').not.toBeNull()

			const moveResponse = await page.request.put(`/apps/chorestrike/api/v1/room-tasks/${roomTaskId}/due-date`, {
				data: { nextDue: '+3 days' },
				headers: { Accept: 'application/json' },
			})
			expect(moveResponse.status()).toBe(200)

			// The stale receipt no longer matches the shown target → 409; the
			// server never silently credits the moved occurrence.
			const staleCompletion = await postCompletionWithReceipt(page, roomTaskId, staleReceipt!)
			expect(staleCompletion.status()).toBe(409)
			expect((await staleCompletion.json()).success).toBe(false)
		} finally {
			await context.close()
		}
	})
})
