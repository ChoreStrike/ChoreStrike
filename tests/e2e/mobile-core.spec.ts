/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * `@mobile` core set (CS-PLAN-084 WP-G, TECH-TEST-005).
 *
 * Runs in the `mobile-chromium` (Pixel 7) and `mobile-webkit` (iPhone 14)
 * projects — the family trial happens on phones. Every scenario uses a
 * fresh family seed and the project's device (viewport, touch, mobile user
 * agent) for each signed-in person, and ends without horizontal overflow.
 *
 * Successor ownership is proven by SuccessorOwnershipTest on the server;
 * here the same lifecycle is driven through the phone UI and checked where
 * a family sees it: My Day, the task list and the list read.
 */

import type { Browser, BrowserContext, BrowserContextOptions, Locator, Page, TestInfo } from '@playwright/test'

import { createUserContext } from './fixtures/auth-contexts.ts'
import { completeWithOptionalDialog } from './fixtures/completion-api.ts'
import { expect, test } from './fixtures/database-fixtures.ts'
import { holdToConfirm } from './fixtures/hold-to-confirm.ts'
import { houseDateOffset } from './fixtures/house-clock.js'
import { openRoom } from './fixtures/rooms.ts'

// The family seed house runs in UTC.
const day = (offset: number): string => houseDateOffset(offset, 'UTC')
const API = '/apps/chorestrike/api/v1'

interface ListRow { id: number, status: string, nextDue?: string | null, assignees?: string[], wasClaimed?: boolean, claimedByUser?: string, originalAssignee?: string, cycle?: { cycleKey?: string, state: string, centreOn?: string } | null }

/** Sign `userId` in on this project's phone (contexts do not inherit the project device). */
async function phone(browser: Browser, userId: string, testInfo: TestInfo): Promise<{ context: BrowserContext, page: Page }> {
	const { viewport, screen, userAgent, deviceScaleFactor, isMobile, hasTouch } = testInfo.project.use
	const device: BrowserContextOptions = { viewport, screen, userAgent, deviceScaleFactor, isMobile, hasTouch }
	return createUserContext(browser, userId, device)
}

async function expectNoHorizontalOverflow(page: Page): Promise<void> {
	await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
}

async function expectWithinViewport(page: Page, locator: Locator): Promise<void> {
	const box = await locator.boundingBox()
	expect(box).not.toBeNull()
	expect(box!.x).toBeGreaterThanOrEqual(0)
	expect(box!.x + box!.width).toBeLessThanOrEqual(page.viewportSize()!.width)
}

/** Daily Kitchen probe through the atomic aggregate; returns the room task id. */
async function createProbe(page: Page, name: string, assignees: string[], firstDate = day(0)): Promise<number> {
	const floors = await page.request.get(`${API}/floors/with-rooms`)
	expect(floors.status()).toBe(200)
	const kitchen = ((await floors.json()).data as Array<{ rooms: Array<{ id: number, name: string }> }>)
		.flatMap((floor) => floor.rooms).find((room) => room.name === 'Kitchen')
	expect(kitchen, 'the family seed always provides the Kitchen room').toBeTruthy()
	const response = await page.request.post(`${API}/room-tasks`, {
		data: { roomId: kitchen!.id, assigneeUserIds: assignees, firstDate, task: { name, energyCost: 1, frequencyType: 'daily', frequencyValue: 1 } },
	})
	expect(response.status(), await response.text()).toBe(200)
	return (await response.json()).data.roomTask.id as number
}

async function listRow(page: Page, roomTaskId: number): Promise<ListRow | undefined> {
	const response = await page.request.get(`${API}/room-tasks`)
	expect(response.status()).toBe(200)
	return ((await response.json()).data as ListRow[]).find((row) => row.id === roomTaskId)
}

/** Children have small daily limits in the seed; lift them so probes reach My Day. */
async function openDailyLimit(owner: Page, userId: string): Promise<void> {
	const everyDay = Object.fromEntries(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'].map((name) => [name, true]))
	const response = await owner.request.put(`${API}/scheduling/members/${userId}`, { data: { maxDailyTasks: 10, weekdayAvailability: everyDay } })
	expect(response.status(), await response.text()).toBe(200)
}

/** Fresh My Day read (a hash-only `goto` to the current route would keep the old store). */
async function openMyDay(page: Page): Promise<void> {
	const again = page.url().includes('#/today')
	await page.goto('/apps/chorestrike/#/today')
	if (again) {
		await page.reload()
	}
	await expect(page.getByTestId('today-view')).toBeVisible({ timeout: 20000 })
	await expect(page.getByTestId('today-section-today').or(page.getByTestId('today-empty-state'))).toBeVisible()
}

async function openHouseholdTasks(page: Page): Promise<void> {
	await page.goto('/apps/chorestrike/#/tasks?scope=all')
	await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 20000 })
}

/** Open a task row in place with a tap (CS-PLAN-091 WP-B6) and return its details. */
async function tapOpenRow(row: Locator): Promise<Locator> {
	await row.getByTestId('task-expand').tap()
	const details = row.locator('xpath=..').getByTestId('task-row-details')
	await expect(details).toBeVisible({ timeout: 15000 })
	return details
}

test.describe('@mobile core set (CS-PLAN-084 WP-G)', () => {
	test.use({ databaseProfile: 'family' })

	test('My Day hold-complete and undo: a tap opens the picker, the hold completes once, undo restores', async ({ browser, seededData }, testInfo) => {
		const task = seededData!.data.tasks!.find((entry) => entry.name === 'Tidy up toys')!
		const roomTaskId = seededData!.data.roomTasks!.find((entry) => entry.taskId === task.id)!.id
		const { context, page } = await phone(browser, 'kid', testInfo)
		try {
			await openMyDay(page)
			const done = page.getByTestId(`today-complete-button-${roomTaskId}`)
			const box = await done.boundingBox()
			expect(box!.height).toBeGreaterThanOrEqual(44)
			// CS-PLAN-087 RF-087-08: the task text never shrinks to a sliver. The
			// one row (CS-PLAN-091 WP-B6) holds only Done and the arrow beside it,
			// and the text fills everything between the two (a gap of 12 px each).
			const row = page.getByTestId('today-task-item').filter({ has: done })
			const infoBox = (await row.locator('.task-info').boundingBox())!
			const expandBox = (await row.getByTestId('task-expand').boundingBox())!
			expect(infoBox.x - (box!.x + box!.width)).toBeLessThanOrEqual(16)
			expect(expandBox.x - (infoBox.x + infoBox.width)).toBeLessThanOrEqual(16)
			expect(infoBox.width).toBeGreaterThan(expandBox.width * 3)
			const completions: string[] = []
			page.on('request', (request) => {
				if (request.method() === 'POST' && request.url().endsWith('/complete')) {
					completions.push(request.url())
				}
			})
			await done.tap()
			await expect(page.getByTestId('participation-dialog')).toBeVisible()
			await expectWithinViewport(page, page.getByTestId('participation-cancel'))
			await page.getByTestId('participation-cancel').tap()
			await expect(page.getByTestId('participation-dialog')).toHaveCount(0)
			expect(completions).toEqual([])

			expect((await completeWithOptionalDialog(page, done)).status()).toBe(200)
			// The completed row leaves the open list; the undo entry confirms it.
			await expect(done).toHaveCount(0)
			await expect(page.getByTestId(`undo-snackbar-check-${roomTaskId}`)).toBeVisible()
			expect(completions).toHaveLength(1)
			await expectWithinViewport(page, page.getByTestId(`undo-snackbar-undo-button-${roomTaskId}`))

			const undo = page.waitForResponse((response) => response.request().method() === 'POST' && /\/completions\/\d+\/undo$/.test(response.url()))
			await page.getByTestId(`undo-snackbar-undo-button-${roomTaskId}`).tap()
			expect((await (await undo).json()).data.undone).toBe(true)
			await expect(done).toBeVisible()
			await expectNoHorizontalOverflow(page)
		} finally {
			await context.close()
		}
	})

	// CS-PLAN-091 WP-B6: the claim dialog went; the picker of a short tap
	// records the overdue work of someone else as the owner's own.
	test('claim confirm: an owner takes over overdue work in the picker and the next cycle stays with the assignee', async ({ browser, seededData }, testInfo) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await phone(browser, 'mom', testInfo)
		try {
			const name = `Mobile claim ${Date.now()}`
			const roomTaskId = await createProbe(page, name, ['dad'], day(-1))
			expect((await listRow(page, roomTaskId))?.status).toBe('overdue')

			await openHouseholdTasks(page)
			const row = page.getByTestId('task-item').filter({ hasText: name })
			await expect(row.getByTestId('task-assignees')).toContainText('Dad', { timeout: 15000 })
			await row.getByTestId('complete-task-button').tap()
			const dialog = page.getByTestId('participation-dialog')
			await expect(dialog.getByTestId('participant-active-dad')).toBeChecked()
			await dialog.getByTestId('participant-active-dad').uncheck()
			await dialog.getByTestId('participant-active-mom').check()
			await dialog.getByTestId('participant-share-mom').fill('100')
			await expectWithinViewport(page, dialog.getByTestId('participation-confirm'))
			const completion = page.waitForResponse((response) => response.request().method() === 'POST' && response.url().endsWith(`/room-tasks/${roomTaskId}/complete`))
			await dialog.getByTestId('participation-confirm').tap()
			const recorded = await completion
			expect(recorded.status()).toBe(200)
			expect(((await recorded.json()).data.rewards.participants as Array<{ userId: string }>).map((share) => share.userId)).toEqual(['mom'])
			await expect(dialog).toHaveCount(0)
			await expect(page.getByTestId(`undo-snackbar-entry-${roomTaskId}`)).toBeVisible()

			const claimed = await listRow(page, roomTaskId)
			expect(claimed).toMatchObject({ wasClaimed: true, claimedByUser: 'mom', originalAssignee: 'dad', assignees: ['dad'] })
			expect(claimed?.status).not.toBe('overdue')
			await expectNoHorizontalOverflow(page)
		} finally {
			await context.close()
		}
	})

	// ADR-021: there is no agreed handover of a single date any more. Anyone
	// may record the work for someone else: a tap opens the picker with the
	// assignee ticked, and the next cycle stays with the assignees.
	test('handover: a member records the work of someone else and the successor stays with the assignee', async ({ browser, seededData }, testInfo) => {
		expect(seededData).not.toBeNull()
		const helper = await phone(browser, 'dad', testInfo)
		try {
			const name = `Mobile handover ${Date.now()}`
			const roomTaskId = await createProbe(helper.page, name, ['mom'])
			expect((await listRow(helper.page, roomTaskId))?.cycle).toMatchObject({ state: 'open', centreOn: day(0) })

			await openMyDay(helper.page)
			// The task is mom's: it waits behind the shared view.
			await helper.page.getByTestId('today-filter-all').click()
			const done = helper.page.getByTestId(`today-complete-button-${roomTaskId}`)
			await expect(done).toBeVisible({ timeout: 15000 })
			// The one who records it says who did it: the picker opens with
			// the assignee ticked and the recorder not, and the confirm stays
			// reachable. Dad took no part and is credited nothing.
			const completed = helper.page.waitForResponse((response) => response.url().endsWith(`/room-tasks/${roomTaskId}/complete`))
			await done.tap()
			await expect(helper.page.getByTestId('participation-dialog')).toBeVisible()
			await expect(helper.page.getByTestId('participant-active-mom')).toBeChecked()
			await expect(helper.page.getByTestId('participant-active-dad')).not.toBeChecked()
			await expectWithinViewport(helper.page, helper.page.getByTestId('participation-confirm'))
			await helper.page.getByTestId('participation-confirm').tap()
			const recorded = await completed
			expect(recorded.status()).toBe(200)
			expect(((await recorded.json()).data.rewards.participants as Array<{ userId: string }>).map((share) => share.userId)).toEqual(['mom'])
			await expect(helper.page.getByTestId(`undo-snackbar-entry-${roomTaskId}`)).toBeVisible()

			const successor = await listRow(helper.page, roomTaskId)
			expect(successor).toMatchObject({ assignees: ['mom'], wasClaimed: false })
			// What the family sees of the successor: planned tomorrow under the
			// unchanged assignee, gone from today's open list.
			expect(successor?.cycle).toMatchObject({ state: 'upcoming', centreOn: day(1) })
			expect(successor?.nextDue?.slice(0, 10)).toBe(day(1))
			await expectNoHorizontalOverflow(helper.page)
		} finally {
			await helper.context.close()
		}
	})

	// CS-PLAN-091 WP-B3: children postpone and correct their own latest
	// entry from the opened task row; nobody is asked, and the owner sees it.
	test('postpone and history: a child postpones a task, then re-dates and deletes its own entry', async ({ browser, seededData }, testInfo) => {
		expect(seededData).not.toBeNull()
		const owner = await phone(browser, 'mom', testInfo)
		const kid = await phone(browser, 'kid', testInfo)
		try {
			await openDailyLimit(owner.page, 'kid')
			const name = `Mobile postpone ${Date.now()}`
			// Weekly with two flex days: one press moves the window by one day.
			const floors = await owner.page.request.get(`${API}/floors/with-rooms`)
			const kitchen = ((await floors.json()).data as Array<{ rooms: Array<{ id: number, name: string }> }>)
				.flatMap((floor) => floor.rooms).find((room) => room.name === 'Kitchen')
			const created = await owner.page.request.post(`${API}/room-tasks`, {
				data: { roomId: kitchen!.id, assigneeUserIds: ['kid'], firstDate: day(0), task: { name, energyCost: 1, frequencyType: 'weekly', frequencyValue: 1, flexDays: 2 } },
			})
			expect(created.status(), await created.text()).toBe(200)
			const roomTaskId = (await created.json()).data.roomTask.id as number

			await openHouseholdTasks(kid.page)
			const row = kid.page.getByTestId('task-item').filter({ hasText: name })
			let details = await tapOpenRow(row)
			const postpone = details.getByTestId('postpone-button')
			await expect(postpone).toContainText('3× left')
			await expectWithinViewport(kid.page, postpone)
			const postponed = kid.page.waitForResponse((response) => response.url().endsWith(`/room-tasks/${roomTaskId}/postpone`))
			await postpone.tap()
			expect((await postponed).status()).toBe(200)
			await expect(postpone).toContainText('2× left')
			expect((await listRow(kid.page, roomTaskId))?.cycle).toMatchObject({ centreOn: day(1), state: 'open' })
			await expect(details.getByTestId('task-history-empty')).toBeVisible()

			// The child does it and finds the entry in the history.
			const completed = kid.page.waitForResponse((response) => response.url().endsWith(`/room-tasks/${roomTaskId}/complete`))
			await holdToConfirm(row.getByTestId('complete-task-button'))
			expect((await completed).status()).toBe(200)
			await openHouseholdTasks(kid.page)
			await kid.page.reload()
			await expect(kid.page.getByTestId('task-manager-view')).toBeVisible({ timeout: 20000 })
			details = await tapOpenRow(row)
			const entry = details.getByTestId('task-history-entry')
			await expect(entry).toHaveCount(1)

			// It was done yesterday: the next window moves with the corrected day.
			await entry.getByTestId('task-history-redate').tap()
			await entry.getByTestId('task-history-date').fill(day(-1))
			const redated = kid.page.waitForResponse((response) => /\/completions\/\d+\/date$/.test(response.url()))
			await entry.getByTestId('task-history-save').tap()
			expect((await redated).status()).toBe(200)
			expect((await listRow(kid.page, roomTaskId))?.cycle).toMatchObject({ centreOn: day(6), state: 'upcoming' })
			await expectNoHorizontalOverflow(kid.page)

			// And it was not done after all: the entry goes, the task is open again.
			await entry.getByTestId('task-history-delete').tap()
			const undone = kid.page.waitForResponse((response) => /\/completions\/\d+\/undo$/.test(response.url()))
			await kid.page.getByTestId('confirm-dialog-confirm').tap()
			expect((await (await undone).json()).data.undone).toBe(true)
			await expect(details.getByTestId('task-history-empty')).toBeVisible()
			expect((await listRow(kid.page, roomTaskId))?.cycle).toMatchObject({ state: 'open' })

			// The owner sees all of it in the recent activity.
			await owner.page.goto('/apps/chorestrike/#/insights')
			await expect(owner.page.getByTestId('insights-view')).toBeVisible({ timeout: 20000 })
			for (const type of ['postponed', 'date_changed', 'deleted']) {
				await expect(owner.page.getByTestId(`activity-event-${type}`).filter({ hasText: name })).toBeVisible()
			}
			await expectNoHorizontalOverflow(owner.page)
		} finally {
			await owner.context.close()
			await kid.context.close()
		}
	})

	test('reassignment: the former assignee loses the task and the new assignee sees it', async ({ browser, seededData }, testInfo) => {
		expect(seededData).not.toBeNull()
		const owner = await phone(browser, 'mom', testInfo)
		const former = await phone(browser, 'kid', testInfo)
		const next = await phone(browser, 'teen', testInfo)
		try {
			await openDailyLimit(owner.page, 'kid')
			await openDailyLimit(owner.page, 'teen')
			const name = `Mobile reassign ${Date.now()}`
			const roomTaskId = await createProbe(owner.page, name, ['kid'])
			await openMyDay(former.page)
			await expect(former.page.getByTestId(`today-complete-button-${roomTaskId}`)).toBeVisible()

			await openHouseholdTasks(owner.page)
			const row = owner.page.getByTestId('task-item').filter({ hasText: name })
			// The members change in the one form (CS-PLAN-091 WP-B6), saved at once.
			const details = await tapOpenRow(row)
			await details.getByTestId('task-edit-button').tap()
			const form = owner.page.getByTestId('task-form')
			await expect(form).toBeVisible({ timeout: 15000 })
			for (const userId of ['teen', 'kid']) {
				await form.getByTestId(`assignee-chip-${userId}`).tap()
			}
			await expectWithinViewport(owner.page, form.getByTestId('save-task-button'))
			const saved = owner.page.waitForResponse((response) => response.request().method() === 'PUT' && response.url().endsWith(`/room-tasks/${roomTaskId}`))
			await form.getByTestId('save-task-button').tap()
			expect((await saved).status()).toBe(200)
			await expect(form).toHaveCount(0)
			expect((await listRow(owner.page, roomTaskId))?.assignees).toEqual(['teen'])

			await openMyDay(former.page)
			await expect(former.page.getByTestId(`today-complete-button-${roomTaskId}`)).toHaveCount(0)
			await openMyDay(next.page)
			await expect(next.page.getByTestId(`today-complete-button-${roomTaskId}`)).toBeVisible()
			await expectNoHorizontalOverflow(next.page)
		} finally {
			await owner.context.close()
			await former.context.close()
			await next.context.close()
		}
	})

	test('paged task list: 20 rows per page with collapsed filters on the phone', async ({ browser, seededData }, testInfo) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await phone(browser, 'mom', testInfo)
		try {
			const active = ((await (await page.request.get(`${API}/room-tasks`)).json()).data as ListRow[]).length
			for (let index = active; index < 21; index++) {
				await createProbe(page, `Mobile paging ${index}`, ['mom'])
			}
			const total = Math.max(active, 21)

			await openHouseholdTasks(page)
			await expect(page.getByTestId('task-item')).toHaveCount(20)
			const toggle = page.getByTestId('tasks-filter-toggle')
			await expect(toggle).toHaveAttribute('aria-expanded', 'false')
			await expect(page.getByTestId('status-filter')).toBeHidden()
			await expectNoHorizontalOverflow(page)

			await page.getByTestId('task-pager-next').tap()
			await expect(page).toHaveURL(/[?&]page=2/)
			await expect(page.getByTestId('task-item')).toHaveCount(Math.min(20, total - 20))

			await toggle.tap()
			await expect(toggle).toHaveAttribute('aria-expanded', 'true')
			await expectWithinViewport(page, page.getByTestId('status-filter'))
			await page.getByTestId('status-filter').selectOption('overdue')
			await expect(page).not.toHaveURL(/[?&]page=/)
			await expect(page.getByTestId('task-item').first()).toBeVisible()
			await expect(page.locator('[data-testid="task-item"]:not(.overdue)')).toHaveCount(0)
			await expectNoHorizontalOverflow(page)
		} finally {
			await context.close()
		}
	})

	// CS-PLAN-090 WP-B: Today took the place of the Dashboard and its tiles.
	test('today shows the status line and the overdue block, and a room opens', async ({ browser, seededData }, testInfo) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await phone(browser, 'mom', testInfo)
		try {
			await page.goto('/apps/chorestrike/#/today')
			const status = page.getByTestId('today-status-line')
			await expect(status).toBeVisible({ timeout: 20000 })
			await expectNoHorizontalOverflow(page)
			const tileTops = await status.locator('.status-tile').evaluateAll((tiles) => tiles.map((tile) => Math.round(tile.getBoundingClientRect().top)))
			expect(tileTops).toHaveLength(4)
			expect(new Set(tileTops).size, 'the four tiles share one row').toBe(1)

			// The household's overdue work stands first, in its own block.
			await page.getByTestId('today-filter-all').tap()
			const overdue = page.getByTestId('today-section-catchup')
			await expect(overdue).toBeVisible()
			await expectWithinViewport(page, overdue)
			const overdueBox = await overdue.boundingBox()
			const dueBox = await page.getByTestId('today-section-today').boundingBox()
			expect(overdueBox!.y).toBeLessThan(dueBox!.y)
			await expectNoHorizontalOverflow(page)

			// The household's overdue list holds nothing but overdue work.
			await page.goto('/apps/chorestrike/#/tasks?status=overdue&scope=all')
			await expect(page.getByTestId('status-filter')).toHaveValue('overdue')
			await expect(page.getByTestId('task-item').first()).toBeVisible()
			await expect(page.locator('[data-testid="task-item"]:not(.overdue)')).toHaveCount(0)

			await openRoom(page, 'Kitchen')
			await expectNoHorizontalOverflow(page)
		} finally {
			await context.close()
		}
	})

	test('settings stay reachable through the phone navigation for a child', async ({ browser, seededData }, testInfo) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await phone(browser, 'kid', testInfo)
		try {
			await openMyDay(page)
			await page.getByRole('button', { name: /Open navigation|Navigation öffnen/i }).tap()
			await page.getByTestId('nav-settings').tap()
			const settings = page.getByTestId('settings-view')
			await expect(settings).toBeVisible()
			await page.getByRole('button', { name: /Close navigation|Navigation schließen/i }).tap()
			await page.getByTestId('settings-category-notifications').tap()
			for (const control of [page.getByTestId('settings-title'), page.getByTestId('task-digest-switch')]) {
				await expect(control).toBeVisible()
				await expectWithinViewport(page, control)
			}
			await expectNoHorizontalOverflow(page)
		} finally {
			await context.close()
		}
	})
})
