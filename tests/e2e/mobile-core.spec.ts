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

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'
import { completeWithOptionalDialog } from './fixtures/completion-api'
import { holdToConfirm } from './fixtures/hold-to-confirm'
import { houseDateOffset } from './fixtures/house-clock.js'
import type { Browser, BrowserContext, BrowserContextOptions, Locator, Page, TestInfo } from '@playwright/test'

// The family seed house runs in UTC.
const day = (offset: number): string => houseDateOffset(offset, 'UTC')
const API = '/apps/chorestrike/api/v1'

interface ListRow { id: number; status: string; nextDue?: string | null; assignees?: string[]; wasClaimed?: boolean; claimedByUser?: string; originalAssignee?: string; occurrence?: { occurrenceId: string; plannedDate?: string } | null }

/** Sign `userId` in on this project's phone (contexts do not inherit the project device). */
async function phone(browser: Browser, userId: string, testInfo: TestInfo): Promise<{ context: BrowserContext; page: Page }> {
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
async function createProbe(page: Page, name: string, assignees: string[], nextDue = day(0)): Promise<number> {
	const floors = await page.request.get(`${API}/floors/with-rooms`)
	expect(floors.status()).toBe(200)
	const kitchen = ((await floors.json()).data as Array<{ rooms: Array<{ id: number; name: string }> }>)
		.flatMap(floor => floor.rooms).find(room => room.name === 'Kitchen')
	expect(kitchen, 'the family seed always provides the Kitchen room').toBeTruthy()
	const response = await page.request.post(`${API}/room-tasks`, {
		data: { roomId: kitchen!.id, assigneeUserIds: assignees, nextDue, task: { name, energyCost: 1, frequencyType: 'daily', frequencyValue: 1 } },
	})
	expect(response.status(), await response.text()).toBe(200)
	return (await response.json()).data.roomTask.id as number
}

async function listRow(page: Page, roomTaskId: number): Promise<ListRow | undefined> {
	const response = await page.request.get(`${API}/room-tasks`)
	expect(response.status()).toBe(200)
	return ((await response.json()).data as ListRow[]).find(row => row.id === roomTaskId)
}

/** Children have small daily limits in the seed; lift them so probes reach My Day. */
async function openDailyLimit(owner: Page, userId: string): Promise<void> {
	const everyDay = Object.fromEntries(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'].map(name => [name, true]))
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

test.describe('@mobile core set (CS-PLAN-084 WP-G)', () => {
	test.use({ databaseProfile: 'family' })

	test('My Day hold-complete and undo: a tap explains the hold, the hold completes once, undo restores', async ({ browser, seededData }, testInfo) => {
		const task = seededData!.data.tasks!.find(entry => entry.name === 'Tidy up toys')!
		const roomTaskId = seededData!.data.roomTasks!.find(entry => entry.taskId === task.id)!.id
		const { context, page } = await phone(browser, 'kid', testInfo)
		try {
			await openMyDay(page)
			const done = page.getByTestId(`today-complete-button-${roomTaskId}`)
			const box = await done.boundingBox()
			expect(box!.height).toBeGreaterThanOrEqual(44)
			// CS-PLAN-087 RF-087-08: the task text keeps a full-width line on the
			// phone instead of shrinking beside the Done and Checklist buttons.
			const row = page.getByTestId('today-task-item').filter({ has: done })
			const rowBox = await row.boundingBox()
			expect((await row.locator('.task-info').boundingBox())!.width).toBeGreaterThan(rowBox!.width * 0.7)
			const completions: string[] = []
			page.on('request', request => {
				if (request.method() === 'POST' && request.url().endsWith('/complete')) {
					completions.push(request.url())
				}
			})
			await done.tap()
			await expect(page.getByTestId(`today-complete-button-${roomTaskId}-hint`)).toHaveText('Press and hold to confirm')
			expect(completions).toEqual([])

			expect((await completeWithOptionalDialog(page, done)).status()).toBe(200)
			// The completed row leaves the open list; the undo entry confirms it.
			await expect(done).toHaveCount(0)
			await expect(page.getByTestId(`undo-snackbar-check-${roomTaskId}`)).toBeVisible()
			expect(completions).toHaveLength(1)
			await expectWithinViewport(page, page.getByTestId(`undo-snackbar-undo-button-${roomTaskId}`))

			const undo = page.waitForResponse(response => response.request().method() === 'POST' && /\/completions\/\d+\/undo$/.test(response.url()))
			await page.getByTestId(`undo-snackbar-undo-button-${roomTaskId}`).tap()
			expect((await (await undo).json()).data.undone).toBe(true)
			await expect(done).toBeVisible()
			await expectNoHorizontalOverflow(page)
		} finally {
			await context.close()
		}
	})

	test('claim confirm: an owner takes over overdue work in one confirmation and the next occurrence stays with the assignee', async ({ browser, seededData }, testInfo) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await phone(browser, 'mom', testInfo)
		try {
			const name = `Mobile claim ${Date.now()}`
			const roomTaskId = await createProbe(page, name, ['dad'], day(-1))
			expect((await listRow(page, roomTaskId))?.status).toBe('overdue')

			await openHouseholdTasks(page)
			const row = page.getByTestId('task-item').filter({ hasText: name })
			await expect(row.getByTestId('task-assignees')).toContainText('Dad', { timeout: 15000 })
			await row.getByTestId('claim-overdue-button').tap()
			const dialog = page.getByTestId('overdue-claim-dialog')
			await expect(dialog).toContainText('Dad')
			await expectWithinViewport(page, dialog.getByTestId('confirm-claim-button'))
			const completion = page.waitForResponse(response => response.request().method() === 'POST' && response.url().endsWith(`/room-tasks/${roomTaskId}/complete`))
			await dialog.getByTestId('confirm-claim-button').tap()
			expect((await completion).status()).toBe(200)
			await expect(page.getByTestId('participation-dialog')).toHaveCount(0)
			await expect(page.getByTestId(`undo-snackbar-entry-${roomTaskId}`)).toBeVisible()

			const claimed = await listRow(page, roomTaskId)
			expect(claimed).toMatchObject({ wasClaimed: true, claimedByUser: 'mom', originalAssignee: 'dad', assignees: ['dad'] })
			expect(claimed?.status).not.toBe('overdue')
			await expect(row.getByTestId('claim-overdue-button')).toHaveCount(0)
			await expectNoHorizontalOverflow(page)
		} finally {
			await context.close()
		}
	})

	test('handover: a child completes the handed-over occurrence and the successor returns to the default owner', async ({ browser, seededData }, testInfo) => {
		expect(seededData).not.toBeNull()
		const owner = await phone(browser, 'mom', testInfo)
		const child = await phone(browser, 'kid', testInfo)
		try {
			await openDailyLimit(owner.page, 'kid')
			const name = `Mobile handover ${Date.now()}`
			const roomTaskId = await createProbe(owner.page, name, ['mom'])
			const handedOccurrence = (await listRow(owner.page, roomTaskId))?.occurrence?.occurrenceId
			expect(handedOccurrence).toBeTruthy()

			await openHouseholdTasks(owner.page)
			const row = owner.page.getByTestId('task-item').filter({ hasText: name })
			await row.getByRole('button', { name: 'Show details', exact: true }).tap()
			const handover = row.getByTestId('agreement-handover')
			await expectWithinViewport(owner.page, handover.getByTestId('agreement-handover-member'))
			await handover.getByTestId('agreement-handover-member').selectOption('kid')
			await row.getByTestId('agreement-consent').check()
			const amended = owner.page.waitForResponse(response => response.request().method() === 'POST' && response.url().endsWith(`/occurrences/${handedOccurrence}/amend`))
			await handover.getByTestId('agreement-handover-submit').tap()
			expect((await amended).status()).toBe(200)

			await openMyDay(child.page)
			const done = child.page.getByTestId(`today-complete-button-${roomTaskId}`)
			const completed = child.page.waitForResponse(response => response.url().endsWith(`/room-tasks/${roomTaskId}/complete`))
			await holdToConfirm(done)
			expect((await completed).status()).toBe(200)
			await expect(child.page.getByTestId('participation-dialog')).toHaveCount(0)
			await expect(child.page.getByTestId(`undo-snackbar-entry-${roomTaskId}`)).toBeVisible()

			const successor = await listRow(owner.page, roomTaskId)
			expect(successor).toMatchObject({ assignees: ['mom'], wasClaimed: false })
			// What the family sees of the successor: due tomorrow under the default
			// owner, gone from the child's day. Its obligated members (the default
			// owner only) are proven server-side by SuccessorOwnershipTest.
			expect(successor?.nextDue?.slice(0, 10)).toBe(day(1))
			await openMyDay(child.page)
			await expect(child.page.getByTestId(`today-complete-button-${roomTaskId}`)).toHaveCount(0)
			await expectNoHorizontalOverflow(child.page)
		} finally {
			await owner.context.close()
			await child.context.close()
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
			await row.getByRole('button', { name: 'Show details', exact: true }).tap()
			for (const userId of ['teen', 'kid']) {
				const saved = owner.page.waitForResponse(response => response.request().method() === 'PUT' && response.url().endsWith(`/room-tasks/${roomTaskId}/assignees`))
				await row.getByTestId(`assignee-chip-${userId}`).tap()
				expect((await saved).status()).toBe(200)
			}
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

	test('dashboard tiles lead to their targets and a room card opens the room', async ({ browser, seededData }, testInfo) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await phone(browser, 'mom', testInfo)
		const openDashboard = async () => {
			await page.goto('/apps/chorestrike/#/dashboard')
			await expect(page.getByTestId('quick-stats')).toBeVisible({ timeout: 20000 })
		}
		try {
			await openDashboard()
			await expectNoHorizontalOverflow(page)
			await page.getByTestId('overdue-stat-card').tap()
			await expect(page.getByTestId('status-filter')).toHaveValue('overdue')
			await expect(page.locator('[data-testid="task-item"]:not(.overdue)')).toHaveCount(0)

			await openDashboard()
			await page.locator('.stat-card.due-today').tap()
			await expect(page.getByTestId('today-view')).toBeVisible()

			await openDashboard()
			await page.getByTestId('stat-average-cleanliness').tap()
			await expect(page.getByTestId('floor-list')).toBeFocused()
			const kitchen = page.getByTestId('room-card-Kitchen').first()
			await expect(kitchen).toBeVisible()
			await expectWithinViewport(page, kitchen)
			await kitchen.locator('[data-testid^="room-link-"]').tap()
			await expect(page.getByTestId('room-detail-view')).toBeVisible()
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
