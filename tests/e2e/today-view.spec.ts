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
			await page.goto('/apps/chorestrike/')
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
			await page.getByTestId(`today-complete-button-${dishesRoomTask.id}`).click()
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
			await page.goto('/apps/chorestrike/')
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
})
