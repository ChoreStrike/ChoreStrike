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
	completeWithOptionalDialog,
} from './fixtures/completion-api'
import type { Page } from '@playwright/test'

import { holdToConfirm } from './fixtures/hold-to-confirm'
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
async function createProbeTask(page: Page, name: string, assignees: string[], nextDue = houseDateOffset(0, 'UTC')): Promise<number> {
	const kitchen = await kitchenRoom(page)
	const createResponse = await page.request.post('/apps/chorestrike/api/v1/room-tasks', {
		data: {
			roomId: kitchen.id,
			assigneeUserIds: assignees,
			nextDue,
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

	test('fixed and relative schedules explain and preserve their different next dates after late work', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const kitchen = await kitchenRoom(page)
			const yesterday = houseDateOffset(-1, 'UTC')
			const weekday = new Intl.DateTimeFormat('en', { weekday: 'long', timeZone: 'UTC' }).format(new Date(`${yesterday}T12:00:00Z`)).toLowerCase()
			for (const [mode, offset] of [['static', 6], ['dynamic', 7]] as const) {
				const name = `WP9 ${mode} weekly`
				const created = await page.request.post('/apps/chorestrike/api/v1/room-tasks', {
					data: {
						roomId: kitchen.id,
						assigneeUserIds: ['mom'],
						nextDue: yesterday,
						task: {
							name,
							energyCost: 1,
							frequencyType: 'weekly',
							frequencyValue: 1,
							schedulingMode: mode,
							...(mode === 'static' ? { fixedScheduleTime: '00:00', fixedScheduleDays: [weekday] } : {}),
						},
					},
				})
				expect(created.status(), await created.text()).toBe(200)
				const id = (await created.json()).data.roomTask.id
				await page.goto('/apps/chorestrike/#/tasks')
				const row = page.getByTestId('task-item').filter({ hasText: name })
				await row.getByRole('button', { name: 'Edit', exact: true }).click()
				await expect(page.locator(`input[type="radio"][value="${mode}"]`)).toBeChecked()
				await expect(page.getByText('Next due shifts based on when you complete the task', { exact: true })).toBeVisible()
				await expect(page.getByText('Fixed schedule (e.g., every Monday 18:00)', { exact: true })).toBeVisible()
				await page.goto('/apps/chorestrike/#/today')
				const button = page.getByTestId(`today-complete-button-${id}`)
				await expect(page.getByTestId('today-section-catchup').getByTestId(`today-complete-button-${id}`)).toBeVisible()
				const response = await completeWithOptionalDialog(page, button)
				expect(response.ok()).toBe(true)
				expect(String((await response.json()).data.roomTask.nextDue)).toContain(houseDateOffset(offset, 'UTC'))
				await expect(button).toHaveCount(0)
			}
		} finally {
			await context.close()
		}
	})

	test('a UI completion carries the full occurrence receipt', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await page.goto('/apps/chorestrike/#/dashboard')
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
			// WP3 §3.6: a multi-person proposal opens the participation
			// dialog — settle it so the completion fires (the helper
			// performs the click).
			const responsePromise = completeWithOptionalDialog(page, completeButton)

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

/**
 * CS-PLAN-073 WP2 browser slice (RF-073-WP2-12): the delivered agreement/
 * pause contract through the real HTTP seam — owner-recorded amendments
 * keep the occurrence identity for every member, API-created pauses render
 * their NEUTRAL clarification state in the owner planner, pending child
 * requests stay effect-free and child approvals are denied, and a private
 * task stays a 404 even for the house owner. No not-yet-built pause
 * management UI is claimed — pauses travel through the frozen §3.5 API.
 */
test.describe('Family rhythm — agreements & pauses (WP2)', () => {
	test.use({ databaseProfile: 'family' })

	test('an owner records an agreed handover in the UI without changing future assignments', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const owner = await createUserContext(browser, 'mom', { viewport: { width: 390, height: 844 }, hasTouch: true })
		const child = await createUserContext(browser, 'kid')
		try {
			const available = await owner.page.request.put('/apps/chorestrike/api/v1/scheduling/members/kid', {
				data: {
					maxDailyTasks: 10,
					weekdayAvailability: Object.fromEntries(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'].map((day) => [day, true])),
				},
			})
			expect(available.status(), await available.text()).toBe(200)
			const name = 'Single occurrence handover'
			const roomTaskId = await createProbeTask(owner.page, name, ['mom'])
			const receipt = await resolveOccurrenceReceipt(owner.page, roomTaskId)
			expect(receipt).not.toBeNull()
			await owner.page.goto('/apps/chorestrike/#/dashboard')
			await expandAllFloors(owner.page)
			await owner.page.getByTestId('room-card-Kitchen').first().click()
			const row = owner.page.getByTestId('task-item').filter({ hasText: name })
			await row.getByRole('button', { name: 'Show details', exact: true }).click()
			const handover = row.getByTestId('agreement-handover')
			await expect(handover).toBeVisible()
			const selectBox = await handover.getByTestId('agreement-handover-member').boundingBox()
			expect(selectBox).not.toBeNull()
			expect(selectBox!.height).toBeGreaterThanOrEqual(44)
			expect(selectBox!.x + selectBox!.width).toBeLessThanOrEqual(390)
			await handover.getByTestId('agreement-handover-member').selectOption('kid')
			await expect(handover.getByTestId('agreement-handover-submit')).toBeDisabled()
			await row.getByTestId('agreement-consent').check()
			const amended = owner.page.waitForResponse((response) => response.request().method() === 'POST'
				&& response.url().endsWith(`/occurrences/${receipt!.occurrenceId}/amend`))
			await handover.getByTestId('agreement-handover-submit').click()
			const response = await amended
			expect(response.status(), await response.text()).toBe(200)
			expect(response.request().postDataJSON()).toMatchObject({
				...receipt,
				consent: true,
				participants: [{ userId: 'kid', role: 'responsible', sharePct: 100 }],
			})
			expect(response.request().postDataJSON()).not.toHaveProperty('date')
			await expect(handover.getByTestId('agreement-handover-member')).toHaveValue('')
			// The same open details must now carry the refreshed receipt, not
			// force every subsequent agreement into a stale-version conflict.
			await row.getByTestId('agreement-amend-date').fill(houseDateOffset(0, 'UTC'))
			await row.getByTestId('agreement-consent').check()
			const secondAmendment = owner.page.waitForResponse((result) => result.request().method() === 'POST'
				&& result.url().endsWith(`/occurrences/${receipt!.occurrenceId}/amend`))
			await row.getByTestId('agreement-amend-submit').click()
			const second = await secondAmendment
			expect(second.status(), await second.text()).toBe(200)
			expect(second.request().postDataJSON().expectedVersion).toBe((await response.json()).data.expectedVersion)
			const rows = await child.page.request.get('/apps/chorestrike/api/v1/room-tasks')
			expect(rows.status()).toBe(200)
			const handed = (await rows.json()).data.find((item: { id: number }) => item.id === roomTaskId)
			expect(handed.assignees).toEqual(['mom'])
			expect(handed.occurrence.occurrenceId).toBe(receipt!.occurrenceId)
			expect(handed.occurrence.expectedVersion).toBeGreaterThan(receipt!.expectedVersion)
			await owner.page.goto('/apps/chorestrike/#/today')
			await expect(owner.page.getByTestId('today-section-today')).toBeVisible()
			await expect(owner.page.getByTestId(`today-complete-button-${roomTaskId}`)).toHaveCount(0)
			await child.page.goto('/apps/chorestrike/#/today')
			await expect(child.page.getByTestId(`today-complete-button-${roomTaskId}`)).toBeVisible()
			const completed = child.page.waitForResponse((result) => result.url().endsWith(`/room-tasks/${roomTaskId}/complete`))
			await holdToConfirm(child.page.getByTestId(`today-complete-button-${roomTaskId}`))
			expect((await completed).status()).toBe(200)
			await expect(child.page.getByTestId('participation-dialog')).toHaveCount(0)
		} finally {
			await owner.context.close()
			await child.context.close()
		}
	})

	test('My Day shows one catch-up card with its original planned date, not a duplicate today', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const name = 'One missed chore'
			const plannedDate = houseDateOffset(-3, 'UTC')
			const roomTaskId = await createProbeTask(page, name, ['mom'], plannedDate)
			await page.goto('/apps/chorestrike/#/today')
			const card = page.getByTestId('today-section-catchup').getByTestId('today-task-item').filter({ hasText: name })
			await expect(card).toHaveCount(1)
			const dateLabel = await page.evaluate((key) => {
				const [year, month, day] = key.split('-').map(Number)
				return new Date(year, month - 1, day).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })
			}, plannedDate)
			await expect(card.getByTestId('catchup-planned')).toContainText(dateLabel)
			await expect(page.getByTestId(`today-complete-button-${roomTaskId}`)).toHaveCount(1)
			await expect(page.getByTestId('today-task-item').filter({ hasText: name })).toHaveCount(1)
		} finally {
			await context.close()
		}
	})

	test('owner date amendment keeps the occurrence identity visible to the child', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const todayKey = houseDateOffset(0, 'UTC')

		const { context: momContext, page: momPage } = await createUserContext(browser, 'mom')
		const { context: dadContext, page: dadPage } = await createUserContext(browser, 'dad')
		const { context: kidContext, page: kidPage } = await createUserContext(browser, 'kid')
		try {
			const roomTaskId = await createProbeTask(momPage, `Amend Probe ${Date.now()}`, ['mom'])
			const receipt = await resolveOccurrenceReceipt(momPage, roomTaskId)
			expect(receipt, 'the probe must expose an amendable target').not.toBeNull()

			// The OWNER records the amendment through the closed §3.5 payload.
			// The agreed date re-confirms TODAY (a real owner-recorded date
			// amendment): the occurrence keeps its identity and open window
			// while the version and the evidence advance.
			const amend = await dadPage.request.post(
				`/apps/chorestrike/api/v1/occurrences/${receipt!.occurrenceId}/amend`,
				{
					data: {
						roomTaskId,
						commandId: newCommandId(),
						expectedVersion: receipt!.expectedVersion,
						selectionToken: receipt!.selectionToken,
						consent: true,
						date: todayKey,
					},
					headers: { Accept: 'application/json' },
				},
			)
			expect(amend.status(), await amend.text()).toBe(200)
			const amendData = (await amend.json()).data
			expect(amendData.occurrenceId).toBe(receipt!.occurrenceId)
			expect(amendData.expectedVersion).toBeGreaterThan(receipt!.expectedVersion)

			// The child sees the SAME agreed identity with the advanced
			// version — agreements are house-visible facts, never
			// owner-only shadows.
			const kidRows = await kidPage.request.get('/apps/chorestrike/api/v1/room-tasks', {
				headers: { Accept: 'application/json' },
			})
			expect(kidRows.status()).toBe(200)
			const kidRow = ((await kidRows.json()).data ?? [])
				.find((row: { id: number }) => row.id === roomTaskId)
			expect(kidRow, 'the amended row stays visible to the child').toBeTruthy()
			expect(kidRow.occurrence?.occurrenceId).toBe(receipt!.occurrenceId)
			expect(kidRow.occurrence?.expectedVersion).toBe(amendData.expectedVersion)
			expect(typeof kidRow.occurrence?.selectionToken).toBe('string')
			expect(kidRow.occurrence?.suspended).toBe(false)
			expect(kidRow.occurrence?.workDate).toBe(todayKey)
		} finally {
			await momContext.close()
			await dadContext.close()
			await kidContext.close()
		}
	})

	test('an API-created house pause renders its neutral clarification in the owner planner', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'dad')
		const { context: kidContext, page: kidPage } = await createUserContext(browser, 'kid')
		try {
			const roomTaskId = await createProbeTask(page, `Pause Probe ${Date.now()}`, ['mom'])

			// The pause travels through the frozen §3.5 API — this spec
			// claims NO pause-management UI.
			const created = await page.request.post('/apps/chorestrike/api/v1/pauses', {
				data: {
					scope: 'house',
					commandId: newCommandId(),
					startDate: houseDateOffset(0, 'UTC'),
					endDate: houseDateOffset(2, 'UTC'),
					reasonCode: 'travel',
				},
				headers: { Accept: 'application/json' },
			})
			expect(created.status(), await created.text()).toBe(200)
			expect((await created.json()).data.state).toBe('acknowledged')

			// The neutral state rides the child-visible DTO: the row stays,
			// its open duty is released (no completable target block).
			const kidRows = await kidPage.request.get('/apps/chorestrike/api/v1/room-tasks', {
				headers: { Accept: 'application/json' },
			})
			const kidRow = ((await kidRows.json()).data ?? [])
				.find((row: { id: number }) => row.id === roomTaskId)
			expect(kidRow, 'a paused row is neutral, never lost').toBeTruthy()
			expect(kidRow.occurrence).toBeNull()

			// The owner planner renders the clarification: covered days show
			// the paused member state instead of silent capacity numbers.
			await expect(async () => {
				await page.goto('/apps/chorestrike/#/scheduling')
				await page.getByTestId('schedule-planner').waitFor({ state: 'visible', timeout: 15000 })
			}).toPass({ timeout: 45000 })
			await page.getByTestId(`planner-task-checkbox-${roomTaskId}`).check()
			await page.getByTestId('preview-plan-button').click()
			await expect(page.getByTestId('plan-capacity')).toBeVisible({ timeout: 15000 })
			// Locale-independent state pin: the covered days render the
			// paused member state (td.paused) instead of silent capacity
			// numbers — profile users render in their own Nextcloud
			// language, so the translated cell text is not a stable selector.
			await expect(page.locator('[data-testid="plan-capacity"] td.paused').first()).toBeVisible({ timeout: 15000 })
		} finally {
			await context.close()
			await kidContext.close()
		}
	})

	test('a pending child request stays effect-free and a child approval is denied', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context: dadContext, page: dadPage } = await createUserContext(browser, 'dad')
		const { context, page } = await createUserContext(browser, 'kid')
		try {
			// Task creation is adult-gated — the owner seeds the child's probe.
			const roomTaskId = await createProbeTask(dadPage, `Request Probe ${Date.now()}`, ['kid'])

			// A child request stays pending (no execution effect).
			const created = await page.request.post('/apps/chorestrike/api/v1/pauses', {
				data: {
					commandId: newCommandId(),
					startDate: houseDateOffset(0, 'UTC'),
					endDate: houseDateOffset(2, 'UTC'),
					reasonCode: 'other',
				},
				headers: { Accept: 'application/json' },
			})
			expect(created.status(), await created.text()).toBe(200)
			const createdData = (await created.json()).data
			expect(createdData.state).toBe('requested')

			// The pending request suspends nothing — the target stays live.
			const receipt = await resolveOccurrenceReceipt(page, roomTaskId)
			expect(receipt, 'a pending request has zero execution effect').not.toBeNull()

			// The child may not approve its own (or any) request.
			const approve = await page.request.post(
				`/apps/chorestrike/api/v1/pauses/${createdData.pauseGroupId}/approve`,
				{
					data: { commandId: newCommandId(), expectedRevision: createdData.revision },
					headers: { Accept: 'application/json' },
				},
			)
			expect(approve.status()).toBe(403)

			// Still effect-free after the denied approval.
			const stillLive = await resolveOccurrenceReceipt(page, roomTaskId)
			expect(stillLive).not.toBeNull()
		} finally {
			await context.close()
			await dadContext.close()
		}
	})

	test('a private task amendment stays a 404 for the house owner', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context: momContext, page: momPage } = await createUserContext(browser, 'mom')
		const { context: dadContext, page: dadPage } = await createUserContext(browser, 'dad')
		try {
			// Mom creates a PRIVATE task with a live occurrence target
			// through the aggregate contract (CS-PLAN-063 shape).
			const kitchen = await kitchenRoom(momPage)
			const created = await momPage.request.post('/apps/chorestrike/api/v1/room-tasks', {
				data: {
					roomId: kitchen.id,
					assigneeUserIds: ['mom'],
					nextDue: houseDateOffset(0, 'UTC'),
					task: {
						name: `Private Probe ${Date.now()}`,
						energyCost: 1,
						frequencyType: 'daily',
						frequencyValue: 1,
						visibilityType: 'private',
					},
				},
				headers: { Accept: 'application/json' },
			})
			expect(created.status(), await created.text()).toBe(200)
			const roomTaskId = (await created.json()).data?.roomTask?.id as number
			expect(roomTaskId).toBeGreaterThan(0)

			const receipt = await resolveOccurrenceReceipt(momPage, roomTaskId)
			expect(receipt, 'the private target is live for its owner').not.toBeNull()

			// The OWNER is outside the private visibility scope (D2): the
			// agreement command answers the 404 anti-enumeration class even
			// with a well-formed, leaked receipt.
			const amend = await dadPage.request.post(
				`/apps/chorestrike/api/v1/occurrences/${receipt!.occurrenceId}/amend`,
				{
					data: {
						roomTaskId,
						commandId: newCommandId(),
						expectedVersion: receipt!.expectedVersion,
						selectionToken: receipt!.selectionToken,
						consent: true,
						date: houseDateOffset(1, 'UTC'),
					},
					headers: { Accept: 'application/json' },
				},
			)
			expect(amend.status()).toBe(404)
		} finally {
			await momContext.close()
			await dadContext.close()
		}
	})

	test('an overloaded child day renders the clarification amounts in the owner planner', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'dad')
		const { context: kidContext, page: kidPage } = await createUserContext(browser, 'kid')
		try {
			// The child shrinks its own daily energy budget to 1 (self
			// settings API — no owner involvement).
			const prefs = await kidPage.request.put('/apps/chorestrike/api/v1/scheduling/preferences', {
				data: {
					energyBudget: {
						monday: 1,
						tuesday: 1,
						wednesday: 1,
						thursday: 1,
						friday: 1,
						saturday: 1,
						sunday: 1,
					},
				},
				headers: { Accept: 'application/json' },
			})
			expect(prefs.status(), await prefs.text()).toBe(200)

			// Two energy-1 obligations due today overload the 1-energy day.
			const first = await createProbeTask(page, `Overload A ${Date.now()}`, ['kid'])
			await createProbeTask(page, `Overload B ${Date.now()}`, ['kid'])

			await expect(async () => {
				await page.goto('/apps/chorestrike/#/scheduling')
				await page.getByTestId('schedule-planner').waitFor({ state: 'visible', timeout: 15000 })
			}).toPass({ timeout: 45000 })
			await page.getByTestId(`planner-task-checkbox-${first}`).check()
			await page.getByTestId('preview-plan-button').click()
			await expect(page.getByTestId('plan-capacity')).toBeVisible({ timeout: 15000 })
			// The overload stays visible with its amounts and the
			// clarification flag (never silently hidden) — pinned via the
			// locale-independent td.overloaded state class (profile users
			// render in their own Nextcloud language).
			await expect(page.locator('[data-testid="plan-capacity"] td.overloaded').first()).toBeVisible({ timeout: 15000 })
		} finally {
			await context.close()
			await kidContext.close()
		}
	})
})

test.describe('Family rhythm — read contract (WP4)', () => {
	test.use({ databaseProfile: 'family' })

	test('the state read carries the rhythm projection and the personal weekly slice after a completion', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			// One fulfilled, window-open occurrence via the real receipt
			// contract (the rhythm evaluation consumes the same facts).
			const roomTaskId = await createProbeTask(page, `Read Contract Probe ${Date.now()}`, ['mom'])
			const receipt = await resolveOccurrenceReceipt(page, roomTaskId)
			expect(receipt, 'the probe must expose a completable occurrence').not.toBeNull()
			const completion = await postCompletionWithReceipt(page, roomTaskId, receipt!)
			expect(completion.status()).toBe(200)

			// CS-PLAN-070 WP4 §3.4 read contract: the pure state read exposes
			// the caller's rhythm projection and the personal weekly-goal
			// slice from the SAME occurrence facts — no award write happens
			// on the GET. C1f: the just-fulfilled occurrence's window is
			// still open, so the day is pending-neutral — the confirmed
			// streak has not stepped yet (confirmed == current), while the
			// weekly goal already counts the valid on-time work and the
			// week stays provisional.
			const state = await page.request.get('/apps/chorestrike/api/v1/game/state', {
				headers: { Accept: 'application/json' },
			})
			expect(state.status()).toBe(200)
			const data = (await state.json()).data

			expect(data.rhythm).toBeTruthy()
			expect(data.rhythm.current).toBe(data.rhythm.confirmed)
			expect(data.rhythm.best).toBeGreaterThanOrEqual(data.rhythm.current)
			expect(data.rhythm.pending).toBe(true)

			expect(data.myWeeklyGoal).toBeTruthy()
			expect(data.myWeeklyGoal.eligible).toBeGreaterThanOrEqual(1)
			expect(data.myWeeklyGoal.onTime).toBeGreaterThanOrEqual(1)
			expect(data.myWeeklyGoal.onTime).toBeLessThanOrEqual(data.myWeeklyGoal.eligible)
			expect(typeof data.myWeeklyGoal.neutral).toBe('number')
			// The probe's window is still open → the week stays provisional.
			expect(data.myWeeklyGoal.provisional).toBe(true)

			// The house goal block carries the explicit counts and the fixed
			// 80% target (no config override exists since WP4).
			expect(data.weeklyGoal.targetPercent).toBe(80)
			expect(data.weeklyGoal.eligible).toBeGreaterThanOrEqual(data.weeklyGoal.onTime)
		} finally {
			await context.close()
		}
	})
})
