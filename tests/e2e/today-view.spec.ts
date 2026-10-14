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
 * are deliberately not asserted (E2E flakiness risk).
 *
 * Requires: Docker test environment with the `family` database profile.
 */

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'
import { completeWithOptionalDialog } from './fixtures/completion-api'

import { holdToConfirm } from './fixtures/hold-to-confirm'
test.describe('Release: Today view', () => {
	test.use({ databaseProfile: 'family' })

	test('shows seeded due-today rows and completes one with row state change', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const dishesTask = seededData.data.tasks.find(task => task.name === 'Do the dishes')
		expect(dishesTask).toBeDefined()
		const dishesRoomTask = seededData.data.roomTasks.find(roomTask => roomTask.taskId === dishesTask.id)
		expect(dishesRoomTask).toBeDefined()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await page.goto('/apps/chorestrike/#/dashboard')
			await page.getByTestId('dashboard-view').waitFor({ state: 'visible' })

			await page.getByTestId('nav-today').click()
			await page.getByTestId('today-view').waitFor({ state: 'visible' })

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
			await page.goto('/apps/chorestrike/#/dashboard')
			await page.getByTestId('dashboard-view').waitFor({ state: 'visible' })

			await page.getByTestId('nav-today').click()
			await page.getByTestId('today-view').waitFor({ state: 'visible' })

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

		const taskIdsByName = (name: string) => seededData.data.tasks.find(task => task.name === name)
		const roomTaskIdFor = (name: string) => {
			const task = taskIdsByName(name)
			const roomTask = seededData.data.roomTasks.find(rt => rt.taskId === task.id)
			return roomTask.id
		}
		const dishesId = roomTaskIdFor('Do the dishes')
		const countersId = roomTaskIdFor('Wipe kitchen counters')

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await page.goto('/apps/chorestrike/#/dashboard')
			await page.getByTestId('dashboard-view').waitFor({ state: 'visible' })

			await page.getByTestId('nav-today').click()
			await page.getByTestId('today-view').waitFor({ state: 'visible' })

			// Both kitchen daily tasks under the everyone filter (counters
			// is teen's; the owner may complete it).
			await page.getByTestId('today-filter-all').click()

			// Dishes is mom's own row (direct completion); counters belongs
			// to teen — WP3 §3.6: the foreign-agreement proposal opens the
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

			// Both occurrences are restored — after a reload the view keeps
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
})
