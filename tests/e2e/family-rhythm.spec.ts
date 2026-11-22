/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Family rhythm — derived cycle contract (ADR-021, CS-PLAN-091).
 *
 * Browser/HTTP-seam proofs of the cycle receipt contract the UI store
 * consumes:
 * - A completion from the real UI carries the FULL receipt (a fresh
 *   `commandId` UUID, the shown cycle's `cycleKey` and its
 *   `expectedVersion`) — the client never invents work time or target
 *   effort, and the retired `occurrenceId` / `selectionToken` are gone.
 * - A NEW command on an already-closed cycle answers 409 ALREADY_COMPLETED,
 *   a receipt that drifted (a definition edit) answers 409 STALE_TARGET and
 *   a second completion of the same room task on the same house-local day
 *   answers 409 SAME_DAY — the server never silently credits another target.
 * - A REPLAY of the same command returns the original completion identity
 *   with `replay: true` and writes no second ledger row.
 * - A cycle may be completed before its window opens; an absence hides and
 *   suspends nothing; work above a child's daily limit is accepted.
 *
 * The window math (fixed and after-completion rules, flex, postponement) and
 * the cycle-scoped checklist semantics are owned by the service-level
 * `WindowService` / `CycleService` suites and the store unit suites; this
 * slice pins the client-visible contract only.
 *
 * Seed-mutating: registered in `SEED_DEPENDENT_SPECS` (chromium-serial-db).
 * Requires: Docker test environment with the `family` database profile.
 */

import type { Page } from '@playwright/test'

import { createUserContext } from './fixtures/auth-contexts.ts'
import {
	completeWithOptionalDialog,
	newCommandId,
	postCompletionWithReceipt,
	readFlatRow,
	receiptOf,
	resolveCycleReceipt,
} from './fixtures/completion-api.ts'
import { expect, test } from './fixtures/database-fixtures.ts'
import { holdToConfirm } from './fixtures/hold-to-confirm.ts'
import { houseDateOffset } from './fixtures/house-clock.js'
import { preferringDay } from './fixtures/planning.ts'
import { openRoom } from './fixtures/rooms.ts'
import { openEditForm, putRoomTask } from './fixtures/task-form.ts'

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
const CYCLE_KEY_RE = /^(d\d{4}-\d{2}-\d{2}|c\d+)$/

/** Resolve the Kitchen room at runtime (the DELETE-based reset never restarts AUTO_INCREMENT). */
async function kitchenRoom(page: Page): Promise<{ id: number }> {
	const floorsResponse = await page.request.get('/apps/chorestrike/api/v1/floors/with-rooms', {
		headers: { Accept: 'application/json' },
	})
	expect(floorsResponse.status()).toBe(200)
	const rooms = ((await floorsResponse.json()).data as Array<{ rooms: Array<{ id: number, name: string }> }>)
		.flatMap((floor) => floor.rooms)
	const kitchen = rooms.find((room) => room.name === 'Kitchen')
	expect(kitchen, 'the family seed always provides the Kitchen room').toBeTruthy()
	return kitchen!
}

/** Create a daily probe task whose first day is TODAY (house-local) unless
 * told otherwise, assigned to `assignees`, via the atomic aggregate. The
 * explicit `firstDate` overrides the creation default (tomorrow) so the
 * cycle is open and completable now. */
async function createProbeTask(page: Page, name: string, assignees: string[], firstDate = houseDateOffset(0, 'UTC')): Promise<number> {
	const kitchen = await kitchenRoom(page)
	const createResponse = await page.request.post('/apps/chorestrike/api/v1/room-tasks', {
		data: {
			roomId: kitchen.id,
			assigneeUserIds: assignees,
			firstDate,
			task: { name, energyCost: 1, frequencyType: 'daily', frequencyValue: 1 },
		},
		headers: { Accept: 'application/json' },
	})
	expect(createResponse.status(), await createResponse.text()).toBe(200)
	const roomTaskId = (await createResponse.json()).data?.roomTask?.id
	expect(roomTaskId, 'the aggregate create returns the linked room task').toBeTruthy()
	return roomTaskId as number
}

/** Switch the household timezone (owner) and return the previous one. */
async function setHouseTimezone(page: Page, timezone: string): Promise<string> {
	const read = await page.request.get('/apps/chorestrike/api/v1/house')
	expect(read.status()).toBe(200)
	const house = (await read.json()).data as { name: string, timezone: string }
	const update = await page.request.put('/apps/chorestrike/api/v1/house', { data: { name: house.name, timezone } })
	expect(update.status(), await update.text()).toBe(200)
	return house.timezone
}

test.describe('Family rhythm — derived cycles', () => {
	test.use({ databaseProfile: 'family' })

	test('fixed and relative schedules explain and preserve their different next dates after late work', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const kitchen = await kitchenRoom(page)
			const yesterday = houseDateOffset(-1, 'UTC')
			const weekday = new Intl.DateTimeFormat('en', { weekday: 'long', timeZone: 'UTC' }).format(new Date(`${yesterday}T12:00:00Z`)).toLowerCase()
			// A fixed rule moves to its next rule day (the weekday of the
			// planned day, six days after today); an after-completion rule
			// plans one interval after the DAY OF THE WORK (seven days).
			for (const [mode, offset] of [['static', 6], ['dynamic', 7]] as const) {
				const name = `WP9 ${mode} weekly`
				const created = await page.request.post('/apps/chorestrike/api/v1/room-tasks', {
					data: {
						roomId: kitchen.id,
						assigneeUserIds: ['mom'],
						firstDate: yesterday,
						task: {
							name,
							energyCost: 1,
							frequencyType: 'weekly',
							frequencyValue: 1,
							flexDays: 0,
							schedulingMode: mode,
							...(mode === 'static' ? { fixedScheduleTime: '00:00', fixedScheduleDays: [weekday] } : {}),
						},
					},
				})
				expect(created.status(), await created.text()).toBe(200)
				const id = (await created.json()).data.roomTask.id
				await page.goto('/apps/chorestrike/#/tasks')
				// The one form names the kind of rhythm, the fixed rule day and
				// the read-only next window (CS-PLAN-091 WP-B6).
				const row = page.getByTestId('task-item').filter({ hasText: name })
				await openEditForm(page, row)
				await expect(page.getByTestId(`task-rhythm-${mode === 'static' ? 'fixed' : 'after'}`)).toHaveAttribute('aria-pressed', 'true')
				await expect(page.getByTestId(`task-rhythm-${mode === 'static' ? 'after' : 'fixed'}`)).toHaveAttribute('aria-pressed', 'false')
				if (mode === 'static') {
					await expect(page.getByTestId(`task-weekday-${weekday}`)).toHaveAttribute('aria-pressed', 'true')
				}
				await expect(page.getByTestId('task-next-window')).toContainText('Next window')
				await page.getByTestId('task-cancel-button').click()
				await expect(page.getByTestId('task-form')).toHaveCount(0)
				await page.goto('/apps/chorestrike/#/today')
				const button = page.getByTestId(`today-complete-button-${id}`)
				await expect(page.getByTestId('today-section-catchup').getByTestId(`today-complete-button-${id}`)).toBeVisible()
				const response = await completeWithOptionalDialog(page, button)
				expect(response.ok()).toBe(true)
				const body = (await response.json()).data
				expect(String(body.roomTask.idealDueDate)).toContain(houseDateOffset(offset, 'UTC'))
				expect(body.cycle).toMatchObject({ centreOn: houseDateOffset(offset, 'UTC'), state: 'upcoming' })
				// An after-completion cycle follows the completion; a fixed
				// one hangs on its rule day.
				expect(body.cycle.cycleKey).toMatch(mode === 'dynamic' ? /^c\d+$/ : /^d\d{4}-\d{2}-\d{2}$/)
				await expect(button).toHaveCount(0)
			}
		} finally {
			await context.close()
		}
	})

	test('a UI completion carries the full cycle receipt', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await openRoom(page, 'Kitchen')

			const completeButton = page.getByTestId('complete-task-button').first()
			await expect(completeButton).toBeVisible({ timeout: 15000 })

			// Capture the request body the store sends and the response it gets.
			const requestPromise = page.waitForRequest((request) => {
				const pathname = new URL(request.url()).pathname
				return request.method() === 'POST' && pathname.endsWith('/complete')
			})
			// WP3 §3.6: a task of several people, or of someone else, opens
			// the participation dialog — settle it so the completion fires
			// (the helper performs the click).
			const responsePromise = completeWithOptionalDialog(page, completeButton)

			const request = await requestPromise
			const body = request.postDataJSON() as Record<string, unknown>
			// The closed-set receipt contract: the cycle's key and version.
			expect(typeof body.commandId).toBe('string')
			expect(body.commandId as string).toMatch(UUID_RE)
			expect(typeof body.cycleKey).toBe('string')
			expect(body.cycleKey as string).toMatch(CYCLE_KEY_RE)
			expect(Number.isInteger(body.expectedVersion)).toBe(true)
			// The retired occurrence receipt never rides along.
			expect(body).not.toHaveProperty('occurrenceId')
			expect(body).not.toHaveProperty('selectionToken')

			const response = await responsePromise
			expect(response.status()).toBe(200)
			const receipt = (await response.json()).data
			expect(receipt.replay).toBe(false)
			// The response carries the NEXT cycle (null for a finished
			// one-time task) — never the one just closed — and no occurrence.
			expect('cycle' in receipt).toBe(true)
			expect(receipt.cycle?.cycleKey).not.toBe(body.cycleKey)
			expect('occurrence' in receipt).toBe(false)
		} finally {
			await context.close()
		}
	})

	test('a new command on a closed cycle conflicts 409, never credits another target', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const roomTaskId = await createProbeTask(page, `Fulfilled Target ${Date.now()}`, ['mom'])

			// Resolve the shown cycle, then close it through the real contract.
			const receipt = await resolveCycleReceipt(page, roomTaskId)
			expect(receipt, 'the probe must expose a completable cycle').not.toBeNull()
			const first = await postCompletionWithReceipt(page, roomTaskId, receipt!)
			expect(first.status()).toBe(200)
			expect((await first.json()).data.replay).toBe(false)

			// A NEW command on the SAME (now closed) cycle: 409, and the
			// server never silently credits the next one.
			const second = await postCompletionWithReceipt(page, roomTaskId, receipt!)
			expect(second.status()).toBe(409)
			const conflictBody = await second.json()
			expect(conflictBody.success).toBe(false)
			expect(conflictBody.error.code).toBe('ALREADY_COMPLETED')
			expect(conflictBody.error.message).toBe('This task is already completed')
		} finally {
			await context.close()
		}
	})

	test('a second completion on the same day conflicts 409 SAME_DAY, even for the next cycle', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const roomTaskId = await createProbeTask(page, `Same Day ${Date.now()}`, ['mom'])
			const receipt = await resolveCycleReceipt(page, roomTaskId)
			expect(receipt).not.toBeNull()
			const first = await postCompletionWithReceipt(page, roomTaskId, receipt!)
			expect(first.status()).toBe(200)

			// The response names the NEXT cycle (tomorrow's); a fresh command
			// on it still hits the same house-local day.
			const next = (await first.json()).data.cycle
			expect(next.state).toBe('upcoming')
			const second = await postCompletionWithReceipt(page, roomTaskId, receiptOf(next))
			expect(second.status()).toBe(409)
			const conflict = await second.json()
			expect(conflict.error.code).toBe('SAME_DAY')
			expect(conflict.error.message).toBe('This task was already completed today')
		} finally {
			await context.close()
		}
	})

	test('replaying the same command returns the original identity without a second effect', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const roomTaskId = await createProbeTask(page, `Replay Probe ${Date.now()}`, ['mom'])
			const receipt = await resolveCycleReceipt(page, roomTaskId)
			expect(receipt, 'the probe must expose a completable cycle').not.toBeNull()

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

	test('a stale receipt after a definition edit conflicts 409 STALE_TARGET and the fresh one pays the new energy', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const roomTaskId = await createProbeTask(page, `Stale Receipt ${Date.now()}`, ['mom'])

			// Capture the shown cycle, then change the open work behind its
			// back: an energy edit applies to the current cycle at once and
			// bumps its version (open work is not frozen).
			const staleReceipt = await resolveCycleReceipt(page, roomTaskId)
			expect(staleReceipt, 'the probe must expose a completable cycle').not.toBeNull()
			const edit = await putRoomTask(page, roomTaskId, { task: { energyCost: 3 } })
			expect(edit.status(), await edit.text()).toBe(200)

			// The stale receipt no longer matches the shown cycle → 409; the
			// server never silently credits the edited one.
			const staleCompletion = await postCompletionWithReceipt(page, roomTaskId, staleReceipt!)
			expect(staleCompletion.status()).toBe(409)
			const conflict = await staleCompletion.json()
			expect(conflict.success).toBe(false)
			expect(conflict.error.code).toBe('STALE_TARGET')
			expect(conflict.error.message).toBe('The task changed since it was displayed. Reload and try again.')

			// A reread shows the new version, and completing it pays the
			// edited energy (the row shows and pays the definition's cost).
			const fresh = await resolveCycleReceipt(page, roomTaskId)
			expect(fresh!.expectedVersion).toBeGreaterThan(staleReceipt!.expectedVersion)
			const completion = await postCompletionWithReceipt(page, roomTaskId, fresh!)
			expect(completion.status(), await completion.text()).toBe(200)
			expect((await completion.json()).data.rewards.completion.totalEnergy).toBe(3)
		} finally {
			await context.close()
		}
	})

	test('a wrong cycle key and the retired occurrence receipt are refused 400', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const roomTaskId = await createProbeTask(page, `Receipt Shape ${Date.now()}`, ['mom'])
			const receipt = await resolveCycleReceipt(page, roomTaskId)
			expect(receipt).not.toBeNull()
			const post = (data: Record<string, unknown>) => page.request.post(`/apps/chorestrike/api/v1/room-tasks/${roomTaskId}/complete`, {
				data: { commandId: newCommandId(), ...data },
				headers: { Accept: 'application/json' },
			})

			// A malformed key never reaches the cycle lookup.
			const malformed = await post({ ...receipt!, cycleKey: 'not-a-cycle-key' })
			expect(malformed.status()).toBe(400)
			expect((await malformed.json()).error.message).toBe('Invalid cycle key')

			// The closed body rejects the old occurrence receipt fields.
			const retired = await post({ ...receipt!, occurrenceId: '1:1', selectionToken: 'retired' })
			expect(retired.status()).toBe(400)

			// Neither refusal consumed the cycle: the real receipt still completes.
			expect((await post({ ...receipt! })).status()).toBe(200)
		} finally {
			await context.close()
		}
	})

	test('work can be completed before its window opens and a completed overdue row is not due again today', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const kitchen = await kitchenRoom(page)
			const createWeekly = async (name: string, firstDate: string): Promise<number> => {
				const created = await page.request.post('/apps/chorestrike/api/v1/room-tasks', {
					data: {
						roomId: kitchen.id,
						assigneeUserIds: ['mom'],
						firstDate,
						task: { name, energyCost: 1, frequencyType: 'weekly', frequencyValue: 1, flexDays: 0 },
					},
					headers: { Accept: 'application/json' },
				})
				expect(created.status(), await created.text()).toBe(200)
				return (await created.json()).data.roomTask.id as number
			}

			// Upcoming: the window opens in five days, and the work is done now.
			const upcomingId = await createWeekly(`Early Work ${Date.now()}`, houseDateOffset(5, 'UTC'))
			expect((await readFlatRow(page, upcomingId))!.cycle).toMatchObject({ state: 'upcoming', centreOn: houseDateOffset(5, 'UTC') })
			const early = await postCompletionWithReceipt(page, upcomingId, (await resolveCycleReceipt(page, upcomingId))!)
			expect(early.status(), await early.text()).toBe(200)

			// Overdue: late work counts from today, so the row is neither red
			// nor due today after it.
			const overdueId = await createWeekly(`Late Work ${Date.now()}`, houseDateOffset(-3, 'UTC'))
			expect((await readFlatRow(page, overdueId))!.cycle).toMatchObject({ state: 'overdue' })
			const late = await postCompletionWithReceipt(page, overdueId, (await resolveCycleReceipt(page, overdueId))!)
			expect(late.status(), await late.text()).toBe(200)
			const afterwards = await readFlatRow(page, overdueId)
			expect(afterwards!.cycle).toMatchObject({ state: 'upcoming', centreOn: houseDateOffset(7, 'UTC') })
			expect(afterwards!.status).toBe('upcoming')
		} finally {
			await context.close()
		}
	})

	// BUG-UX-005 (CS-PLAN-088 feedback #5): work inside its flex window can
	// be completed from Today before the day it is suggested for. Since
	// CS-PLAN-091 WP-C the live suggestion decides that day (ADR-022): mom
	// prefers tomorrow and has room there on every weekday of the seed, so the
	// probe is suggested there and only possible today.
	test('My Day offers work inside its flex window before the planned day', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const preferred = await page.request.put('/apps/chorestrike/api/v1/scheduling/preferences', {
				data: preferringDay(houseDateOffset(1, 'UTC')),
				headers: { Accept: 'application/json' },
			})
			expect(preferred.status(), await preferred.text()).toBe(200)
			const kitchen = await kitchenRoom(page)
			const created = await page.request.post('/apps/chorestrike/api/v1/room-tasks', {
				data: {
					roomId: kitchen.id,
					assigneeUserIds: ['mom'],
					firstDate: houseDateOffset(1, 'UTC'),
					task: { name: 'Flex window probe', energyCost: 1, frequencyType: 'weekly', frequencyValue: 1, flexDays: 2 },
				},
				headers: { Accept: 'application/json' },
			})
			expect(created.status(), await created.text()).toBe(200)
			const roomTaskId = (await created.json()).data.roomTask.id as number

			await page.goto('/apps/chorestrike/#/today')
			// Work that is only possible today waits behind one line.
			const possible = page.getByTestId('today-section-open-window')
			await expect(possible).toHaveAttribute('aria-expanded', 'false', { timeout: 20000 })
			await expect(page.getByTestId(`today-complete-button-${roomTaskId}`)).toHaveCount(0)
			await possible.click()
			// The row names how long its window stays open, not a planned day.
			await expect(page.getByTestId(`today-window-${roomTaskId}`)).toBeVisible()
			const completed = page.waitForResponse((response) => response.url().endsWith(`/room-tasks/${roomTaskId}/complete`))
			await holdToConfirm(page.getByTestId(`today-complete-button-${roomTaskId}`))
			const response = await completed
			expect(response.status(), await response.text()).toBe(200)
		} finally {
			await context.close()
		}
	})
})

/**
 * Browser slice of the cycle contract in a household with absences and
 * limits: a house pause or a child's absence hides and suspends nothing,
 * work above a child's daily limit is accepted, and a private task stays a
 * 404 even for the house owner. Pauses travel through the frozen API — no
 * pause-management UI is claimed.
 */
test.describe('Family rhythm — absences, limits & visibility', () => {
	test.use({ databaseProfile: 'family' })

	// BUG-UX-002 (CS-PLAN-088 feedback #15): a non-UTC house hangs the first
	// cycle on ITS day, and the work completes there.
	test('a non-UTC house hangs the cycle on its own day and completes it', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const zone = 'Europe/Berlin'
		const owner = await createUserContext(browser, 'mom')
		const previousZone = await setHouseTimezone(owner.page, zone)
		try {
			const today = houseDateOffset(0, zone)
			const roomTaskId = await createProbeTask(owner.page, 'Non-UTC cycle', ['mom'], today)
			const row = await readFlatRow(owner.page, roomTaskId)
			expect(row!.cycle).toMatchObject({ cycleKey: `d${today}`, state: 'open', centreOn: today })
			const completion = await postCompletionWithReceipt(owner.page, roomTaskId, receiptOf(row!.cycle!))
			expect(completion.status(), await completion.text()).toBe(200)
			expect((await completion.json()).data.cycle).toMatchObject({ centreOn: houseDateOffset(1, zone), state: 'upcoming' })
		} finally {
			await setHouseTimezone(owner.page, previousZone).catch(() => {})
			await owner.context.close()
		}
	})

	test('My Day shows one overdue card with its planned day, not a duplicate today', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const name = 'One missed chore'
			const plannedDate = houseDateOffset(-3, 'UTC')
			const roomTaskId = await createProbeTask(page, name, ['mom'], plannedDate)
			await page.goto('/apps/chorestrike/#/today')
			const block = page.getByTestId('today-section-catchup')
			const card = block.getByTestId('today-task-item').filter({ hasText: name })
			await expect(card).toHaveCount(1)
			// Late work still counts: the block says so.
			await expect(block).toContainText('Completing it now counts as late work.')
			const dateLabel = await page.evaluate((key) => {
				const [year, month, day] = key.split('-').map(Number)
				return new Date(year, month - 1, day).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })
			}, plannedDate)
			await expect(card.getByTestId('catchup-planned')).toContainText(dateLabel)
			// A daily row's window ended on its planned day: no "until".
			await expect(card.getByTestId('catchup-planned')).not.toContainText('until')
			await expect(page.getByTestId(`today-complete-button-${roomTaskId}`)).toHaveCount(1)
			await expect(page.getByTestId('today-task-item').filter({ hasText: name })).toHaveCount(1)
		} finally {
			await context.close()
		}
	})

	test('a house pause leaves the rows and their cycles in place', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'dad')
		const { context: kidContext, page: kidPage } = await createUserContext(browser, 'kid')
		try {
			const roomTaskId = await createProbeTask(page, `Pause Probe ${Date.now()}`, ['mom'])
			const before = await readFlatRow(kidPage, roomTaskId)
			expect(before?.cycle, 'the probe is a live cycle first').toBeTruthy()

			// The pause travels through the frozen API — this spec claims NO
			// pause-management UI.
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

			// ADR-021 / D-091-03: an absence only makes the window neutral for
			// the recognitions — the row and its cycle stay exactly as shown.
			const after = await readFlatRow(kidPage, roomTaskId)
			expect(after, 'a paused row is never lost').toBeTruthy()
			expect(after!.cycle).toEqual(before!.cycle)
		} finally {
			await context.close()
			await kidContext.close()
		}
	})

	test('a child absence takes effect at once, keeps the work completable and deciding it stays owner-only', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context: dadContext, page: dadPage } = await createUserContext(browser, 'dad')
		const { context, page } = await createUserContext(browser, 'kid')
		try {
			// Task creation is adult-gated — the owner seeds the child's probe.
			const roomTaskId = await createProbeTask(dadPage, `Absence Probe ${Date.now()}`, ['kid'])
			expect(await resolveCycleReceipt(page, roomTaskId), 'the probe is a live duty first').not.toBeNull()

			// ADR-020: a child's absence needs no request.
			const created = await page.request.post('/apps/chorestrike/api/v1/pauses', {
				data: {
					commandId: newCommandId(),
					startDate: houseDateOffset(0, 'UTC'),
					endDate: houseDateOffset(2, 'UTC'),
				},
				headers: { Accept: 'application/json' },
			})
			expect(created.status(), await created.text()).toBe(200)
			const createdData = (await created.json()).data
			expect(createdData.state).toBe('acknowledged')

			// ADR-021: the duty of an absent member stays visible.
			expect(await resolveCycleReceipt(page, roomTaskId), 'an absence suspends nothing').not.toBeNull()

			// Decisions stay owner-only, also for a child's own entry.
			const approve = await page.request.post(
				`/apps/chorestrike/api/v1/pauses/${createdData.pauseGroupId}/approve`,
				{
					data: { commandId: newCommandId(), expectedRevision: createdData.revision },
					headers: { Accept: 'application/json' },
				},
			)
			expect(approve.status()).toBe(403)

			// The child deletes its own absence; the duty never moved.
			const deleted = await page.request.delete(
				`/apps/chorestrike/api/v1/pauses/${createdData.pauseGroupId}`,
				{
					params: { commandId: newCommandId(), expectedRevision: createdData.revision },
					headers: { Accept: 'application/json' },
				},
			)
			expect(deleted.status(), await deleted.text()).toBe(200)
			expect((await deleted.json()).data.state).toBe('cancelled')

			// The row of an absent member stays completable.
			const receipt = await resolveCycleReceipt(page, roomTaskId)
			expect(receipt, 'what the absence covered is still the child\'s duty').not.toBeNull()
			const completion = await postCompletionWithReceipt(page, roomTaskId, receipt!)
			expect(completion.status(), await completion.text()).toBe(200)
		} finally {
			await context.close()
			await dadContext.close()
		}
	})

	test('a private task completion stays a 404 for the house owner', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context: momContext, page: momPage } = await createUserContext(browser, 'mom')
		const { context: dadContext, page: dadPage } = await createUserContext(browser, 'dad')
		try {
			// Mom creates a PRIVATE task with a live cycle through the
			// aggregate contract (CS-PLAN-063 shape).
			const kitchen = await kitchenRoom(momPage)
			const created = await momPage.request.post('/apps/chorestrike/api/v1/room-tasks', {
				data: {
					roomId: kitchen.id,
					assigneeUserIds: ['mom'],
					firstDate: houseDateOffset(0, 'UTC'),
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

			const receipt = await resolveCycleReceipt(momPage, roomTaskId)
			expect(receipt, 'the private cycle is live for its creator').not.toBeNull()

			// The OWNER is outside the private visibility scope (D2): the
			// completion answers the 404 anti-enumeration class even with a
			// well-formed, leaked receipt.
			const complete = await postCompletionWithReceipt(dadPage, roomTaskId, receipt!)
			expect(complete.status()).toBe(404)
		} finally {
			await momContext.close()
			await dadContext.close()
		}
	})

	test('work above a child daily limit is accepted', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'dad')
		const { context: kidContext, page: kidPage } = await createUserContext(browser, 'kid')
		try {
			// The owner caps the child at one task per day through the
			// member preferences, which stay.
			const weekdays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']
			const limit = await page.request.put('/apps/chorestrike/api/v1/scheduling/members/kid', {
				data: {
					maxDailyTasks: 1,
					weekdayAvailability: Object.fromEntries(weekdays.map((day) => [day, true])),
				},
			})
			expect(limit.status(), await limit.text()).toBe(200)

			// Three obligations due today exceed the limit: the creation is
			// no longer refused, and every row shows its cycle to the child.
			const ids = [
				await createProbeTask(page, `Overload A ${Date.now()}`, ['kid']),
				await createProbeTask(page, `Overload B ${Date.now()}`, ['kid']),
				await createProbeTask(page, `Overload C ${Date.now()}`, ['kid']),
			]
			for (const id of ids) {
				expect((await readFlatRow(kidPage, id))?.cycle, 'the capacity never refuses work').toMatchObject({ state: 'open' })
			}

			// ADR-022 decision 2 rule 5 with BUG-UX-013: Today suggests the
			// child no more than the limit and hides nothing it owes — the
			// rest waits as also possible.
			const daily = kidPage.waitForResponse((response) => response.url().includes('/room-tasks/filtered-daily'))
			await kidPage.goto('/apps/chorestrike/#/today')
			const rows = (await (await daily).json()).data.rows as Array<{ roomTask: { id: number }, openWindow: boolean, suggestedFor?: string[] }>
			const suggested = rows.filter((row) => !row.openWindow)
			expect(suggested).toHaveLength(1)
			expect(suggested[0].suggestedFor).toContain('kid')
			for (const id of ids) {
				expect(rows.some((row) => row.roomTask.id === id), `probe ${id} stays on the child's Today`).toBe(true)
			}
			await expect(kidPage.getByTestId('today-task-item')).toHaveCount(1, { timeout: 20000 })
			const possible = kidPage.getByTestId('today-section-open-window')
			await expect(possible).toBeVisible()
			await possible.click()
			await expect(kidPage.getByTestId('today-task-item')).toHaveCount(rows.length)
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
			// One fulfilled, window-open cycle via the real receipt
			// contract (the rhythm evaluation consumes the same facts).
			const roomTaskId = await createProbeTask(page, `Read Contract Probe ${Date.now()}`, ['mom'])
			const receipt = await resolveCycleReceipt(page, roomTaskId)
			expect(receipt, 'the probe must expose a completable cycle').not.toBeNull()
			const completion = await postCompletionWithReceipt(page, roomTaskId, receipt!)
			expect(completion.status()).toBe(200)

			// CS-PLAN-070 WP4 §3.4 read contract: the pure state read exposes
			// the caller's rhythm projection and the personal weekly-goal
			// slice from the SAME cycle facts — no award write happens
			// on the GET. C1f: the just-fulfilled cycle's window is
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
