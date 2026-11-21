/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Release E2E regression — Today view (CS-PLAN-038, UX-002).
 *
 * Runs as `mom` (owner) against the `family` profile. The seed creates
 * six due-today room tasks; `Do the dishes` is assigned to mom, so the
 * default "My tasks" filter (D3) shows it. Completing a task must flip
 * the row to its completed state — celebration effects (confetti/sound)
 * are deliberately not asserted (E2E flakiness risk). The last scenario
 * is the bonus day of CS-PLAN-091 WP-B4 and WP-B5.
 *
 * Requires: Docker test environment with the `family` database profile.
 */

import { createUserContext } from './fixtures/auth-contexts.ts'
import { completeWithOptionalDialog, postCompletionWithReceipt } from './fixtures/completion-api.ts'
import { expect, test } from './fixtures/database-fixtures.ts'
import { holdToConfirm } from './fixtures/hold-to-confirm.ts'
import { openToday } from './fixtures/rooms.ts'
import { openSettings } from './fixtures/settings.ts'

const API = '/apps/chorestrike/api/v1'

test.describe('Release: Today view', () => {
	test.use({ databaseProfile: 'family' })

	// CS-PLAN-090 WP-B: Today is the one landing page. It took over what the
	// Dashboard answered at a glance; the Dashboard's tiles, health bar and
	// room cards left the screens (D-090-11).
	test('Today is the landing page with the own status, and the old Dashboard address leads to it', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await page.goto('/apps/chorestrike/#/dashboard')
			await expect(page.getByTestId('today-view')).toBeVisible({ timeout: 30000 })
			await expect(page).toHaveURL(/#\/today$/)
			await expect(page.getByTestId('nav-dashboard')).toHaveCount(0)
			await expect(page.getByTestId('today-date')).not.toBeEmpty()

			// Every tile holds a value once its read arrived, never a dash.
			const status = page.getByTestId('today-status-line')
			for (const tile of ['today-energy', 'today-weekly-goal', 'today-streak', 'today-coins']) {
				await expect(status.getByTestId(tile).locator('.status-value')).toHaveText(/\d/, { timeout: 15000 })
			}
			await expect(status.getByTestId('own-wallet-available')).toHaveText(/^-?\d+(\.\d+)?$/)

			// Status first, then the work: the due-today heading follows it.
			const statusBox = await status.boundingBox()
			const dueBox = await page.getByTestId('today-section-today').boundingBox()
			expect(statusBox.y).toBeLessThan(dueBox.y)

			// What left the screens with the Dashboard.
			for (const gone of ['quick-stats', 'house-health', 'floor-list', 'today-section-appointments']) {
				await expect(page.getByTestId(gone)).toHaveCount(0)
			}
		} finally {
			await context.close()
		}
	})

	test('shows seeded due-today rows and completes one with row state change', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const dishesTask = seededData.data.tasks.find((task) => task.name === 'Do the dishes')
		expect(dishesTask).toBeDefined()
		const dishesRoomTask = seededData.data.roomTasks.find((roomTask) => roomTask.taskId === dishesTask.id)
		expect(dishesRoomTask).toBeDefined()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await openToday(page)

			// Default filter is "My tasks" (D3): the seeded dishes task is
			// assigned to mom and must be visible with its room.
			const row = page.getByTestId('today-task-item').filter({ hasText: 'Do the dishes' })
			await expect(row).toBeVisible()
			await expect(row).toContainText('Kitchen')

			// Complete it and assert the row state change (not confetti):
			// the dishes task is daily-recurring, so completion shifts its
			// next due date out of today and the row leaves the list.
			await holdToConfirm(page.getByTestId(`today-complete-button-${dishesRoomTask.id}`))
			await expect(row).toHaveCount(0)
			await expect(page.getByTestId(`today-complete-button-${dishesRoomTask.id}`)).toHaveCount(0)
		} finally {
			await context.close()
		}
	})

	test('the mine filter hides other members tasks, everyone shows them', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await openToday(page)

			// 'Tidy up toys' is assigned to kid — hidden under mom's default
			const toysRow = page.getByTestId('today-task-item').filter({ hasText: 'Tidy up toys' })
			await expect(toysRow).toHaveCount(0)

			await page.getByTestId('today-filter-all').click()
			await expect(toysRow.first()).toBeVisible()
		} finally {
			await context.close()
		}
	})

	test('undo queue keeps two completions independently undoable (UX-015, CS-PLAN-064)', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const taskIdsByName = (name: string) => seededData.data.tasks.find((task) => task.name === name)
		const roomTaskIdFor = (name: string) => {
			const task = taskIdsByName(name)
			const roomTask = seededData.data.roomTasks.find((rt) => rt.taskId === task.id)
			return roomTask.id
		}
		const dishesId = roomTaskIdFor('Do the dishes')
		const countersId = roomTaskIdFor('Wipe kitchen counters')

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await openToday(page)

			// Both kitchen daily tasks under the everyone filter (counters
			// is teen's; the owner may complete it).
			await page.getByTestId('today-filter-all').click()

			// Dishes is mom's own row (direct completion); counters belongs
			// to teen — WP3 §3.6: a task of someone else opens the
			// participation dialog, confirming it posts the explicit split.
			await holdToConfirm(page.getByTestId(`today-complete-button-${dishesId}`))
			const countersResponse = await completeWithOptionalDialog(
				page,
				page.getByTestId(`today-complete-button-${countersId}`),
			)
			expect(countersResponse.ok()).toBeTruthy()

			// UX-015: the second completion must NOT destroy the first
			// undo action — both queue entries are visible side by side.
			const firstEntry = page.getByTestId(`undo-snackbar-entry-${dishesId}`)
			const secondEntry = page.getByTestId(`undo-snackbar-entry-${countersId}`)
			await expect(firstEntry).toBeVisible()
			await expect(secondEntry).toBeVisible()
			// WP-F: every entry carries the always-on check-mark acknowledgement.
			await expect(page.getByTestId(`undo-snackbar-check-${dishesId}`)).toBeVisible()

			// Undo the FIRST entry: it disappears, the second stays
			// undoable until after its own click. WP3 §3.4: the targeted
			// correction posts to /completions/{id}/undo (exact numeric id).
			const firstUndo = page.waitForResponse((response) => {
				const pathname = new URL(response.url()).pathname
				return response.request().method() === 'POST'
					&& /^\/apps\/chorestrike\/api\/v1\/completions\/\d+\/undo$/.test(pathname)
			})
			await page.getByTestId(`undo-snackbar-undo-button-${dishesId}`).click()
			const firstUndoResponse = await firstUndo
			expect((await firstUndoResponse.json()).data.undone).toBe(true)

			await expect(firstEntry).toHaveCount(0)
			await expect(secondEntry).toBeVisible()

			const secondUndo = page.waitForResponse((response) => {
				const pathname = new URL(response.url()).pathname
				return response.request().method() === 'POST'
					&& /^\/apps\/chorestrike\/api\/v1\/completions\/\d+\/undo$/.test(pathname)
			})
			await page.getByTestId(`undo-snackbar-undo-button-${countersId}`).click()
			const secondUndoResponse = await secondUndo
			expect((await secondUndoResponse.json()).data.undone).toBe(true)

			await expect(page.getByTestId('undo-snackbar')).toHaveCount(0)

			// Both cycles are restored — after a reload the view keeps
			// its #/today route, and both rows are due today again (server
			// truth, no stale caches).
			await page.reload()
			await page.getByTestId('today-view').waitFor({ state: 'visible', timeout: 30000 })
			await page.getByTestId('today-filter-all').click()

			await expect(page.getByTestId(`today-complete-button-${dishesId}`)).toBeVisible()
			await expect(page.getByTestId(`today-complete-button-${countersId}`)).toBeVisible()
		} finally {
			await context.close()
		}
	})

	// CS-PLAN-091 WP-B4 and WP-B5 (ADR-021 decisions 8 and 11): the owner
	// sets a coin factor and starts a bonus day in the settings; Today names
	// the bonus day and lists the bonus task in its own section; the task
	// pays each person once that day, at the multipliers of the day.
	test('a bonus day on Today: a bonus task pays once per person at the coin factor and the bonus rate', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const kitchen = seededData.data.rooms.find((room) => room.name === 'Kitchen')
		expect(kitchen).toBeDefined()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await openToday(page)
			// A bonus task of mom's, energy 1, created as one (CS-PLAN-091 WP-B6).
			const created = await page.request.post(`${API}/room-tasks`, {
				data: { roomId: kitchen.id, assigneeUserIds: ['mom'], task: { name: 'Water the plants', energyCost: 1, frequencyType: 'daily', frequencyValue: 1, isVoluntary: true } },
			})
			expect(created.status(), await created.text()).toBe(200)
			const { roomTask, task } = (await created.json()).data
			expect(task.isVoluntary).toBe(true)

			// The owner's two multipliers: mom at × 2, bonus days from today.
			await openSettings(page, 'rewards')
			await page.locator('[data-testid="coin-factor-row"][data-user-id="mom"]').getByTestId('coin-factor-select').selectOption('2')
			await expect(page.getByTestId('coin-factor-saved')).toContainText('Mom')
			await page.getByTestId('bonus-days-submit').click()
			await expect(page.getByTestId('bonus-days-period')).toContainText('Running today')

			await openToday(page)
			await expect(page.getByTestId('today-bonus-days')).toContainText('Bonus day: coins count × 1.5 today.')
			const coins = page.getByTestId('own-wallet-available')
			await expect(coins).toHaveText(/^-?\d+(\.\d+)?$/, { timeout: 15000 })
			const before = Number(await coins.textContent())

			// Its own section, apart from the day's work.
			const row = page.getByTestId('today-section-bonus').getByTestId('today-bonus-item').filter({ hasText: 'Water the plants' })
			await expect(row).toContainText('Bonus task')
			await expect(page.getByTestId('today-section-today').getByText('Water the plants')).toHaveCount(0)

			// A hold credits mom: energy 1 pays 10 Coins × 2 × 1.5; a bonus
			// task has no cycle to name.
			const response = await completeWithOptionalDialog(page, page.getByTestId(`today-bonus-complete-${roomTask.id}`))
			expect(response.status(), await response.text()).toBe(200)
			expect(response.request().postDataJSON()).not.toHaveProperty('cycleKey')
			const completion = (await response.json()).data
			expect(completion.rewards.completion.bonusPercent).toBe(150)
			expect(completion.rewards.participants).toEqual([expect.objectContaining({ userId: 'mom', energyMinor: 100, coinMinor: 3000 })])
			await expect(coins).toHaveText(String(before + 30))

			// Counted for mom today: the hold is gone, others can still be credited.
			await expect(page.getByTestId(`today-bonus-counted-${roomTask.id}`)).toBeVisible()
			await expect(page.getByTestId(`today-bonus-counted-for-${roomTask.id}`)).toHaveText('Counted today for Mom')
			await expect(page.getByTestId(`today-bonus-complete-${roomTask.id}`)).toHaveCount(0)

			// The server decides: a second credit of mom today is refused.
			const bonusRows = (await (await page.request.get(`${API}/room-tasks/bonus`)).json()).data
			const bonusRow = bonusRows.find((candidate) => candidate.roomTask.id === roomTask.id)
			expect(bonusRow.creditedToday).toEqual(['mom'])
			expect(bonusRow.cycle).toMatchObject({ state: 'bonus' })
			const again = await postCompletionWithReceipt(page, roomTask.id, { expectedVersion: bonusRow.cycle.expectedVersion })
			expect(again.status()).toBe(409)
			expect((await again.json()).error.code).toBe('BONUS_ALREADY_TODAY')

			// Someone else did it too: the picker offers everyone but mom.
			await page.getByTestId(`today-bonus-other-${roomTask.id}`).click()
			await expect(page.getByTestId('participant-active-mom')).toBeDisabled()
			await expect(page.getByTestId('participant-row-mom')).toContainText('counted today')
			await page.getByTestId('participant-active-dad').check()
			const forDad = page.waitForResponse((candidate) => candidate.request().method() === 'POST' && new URL(candidate.url()).pathname.endsWith('/complete'))
			await page.getByTestId('participation-confirm').click()
			const dadCompletion = (await (await forDad).json()).data
			// Dad has no coin factor: 10 Coins × 1.5, and mom's wallet stays.
			expect(dadCompletion.rewards.participants).toEqual([expect.objectContaining({ userId: 'dad', coinMinor: 1500 })])
			await expect(page.getByTestId(`today-bonus-counted-for-${roomTask.id}`)).toHaveText('Counted today for Dad, Mom')
			await expect(coins).toHaveText(String(before + 30))

			// Server truth after a reload; cancelling the bonus days takes the
			// banner away and keeps what the day paid.
			await page.reload()
			await expect(page.getByTestId(`today-bonus-counted-for-${roomTask.id}`)).toHaveText('Counted today for Dad, Mom', { timeout: 30000 })
			await openSettings(page, 'rewards')
			await page.getByTestId('bonus-days-cancel').click()
			await expect(page.getByTestId('bonus-days-form')).toBeVisible()
			await openToday(page)
			await expect(page.getByTestId('today-section-bonus')).toBeVisible({ timeout: 15000 })
			await expect(page.getByTestId('today-bonus-days')).toHaveCount(0)
			await expect(coins).toHaveText(String(before + 30))
		} finally {
			await context.close()
		}
	})
})
