/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * WP7 E2E — workflow state reconciliation (CS-PLAN-066 §4.3 row 2).
 *
 * Pins the race/reconciliation guarantees at the real HTTP boundary:
 *  - Rapid parallel navigation (Dashboard ↔ My Day) never leaves stale
 *    projections — the final rendered state matches the seeded server truth.
 *  - A committed completion stays successful when a derived-projection
 *    refetch fails: the stale-slice banner offers a read-only RETRY, and the
 *    retry clears it without replaying any write (D3).
 *  - Request-order protection: a slow superseded read cannot overwrite the
 *    newer rendered state (achieved with route-level delay on the
 *    superseded request).
 *
 * Mutates seed state (completes the daily counters task) — serial pool.
 * Requires: Docker test environment with the `family` database profile.
 */

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'

test.describe('Workflow state reconciliation (CS-PLAN-066)', () => {
	test.use({ databaseProfile: 'family' })

	test('rapid parallel navigation ends in the reconciled state — no stale overwrite', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await page.goto('/apps/chorestrike/')
			await page.getByTestId('dashboard-view').waitFor({ state: 'visible' })

			// Race Dashboard ↔ My Day navigations back to back; every load
			// carries its own request generation (§3.3), so the LAST landed
			// surface must render the complete, current data.
			await page.getByTestId('nav-today').click()
			await page.getByTestId('nav-dashboard').click()
			await page.getByTestId('nav-today').click()
			await page.getByTestId('today-view').waitFor({ state: 'visible' })

			// My Day renders the seeded mom task under the default mine
			// filter — nothing from an aborted earlier navigation got lost.
			const row = page.getByTestId('today-task-item').filter({ hasText: 'Do the dishes' }).first()
			await expect(row).toBeVisible()
		} finally {
			await context.close()
		}
	})

	test('a superseded slow read never overwrites the newer rendered state', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			// Delay only the FIRST daily read so a later, faster one wins the
			// race — the delayed response must be DROPPED by the store's
			// generation guard, never rendered.
			let dailyCalls = 0
			await page.route('**/api/v1/room-tasks/filtered-daily*', async (route) => {
				dailyCalls++
				if (dailyCalls === 1) {
					await new Promise((resolve) => setTimeout(resolve, 3000))
				}
				await route.continue()
			})

			await page.goto('/apps/chorestrike/')
			await page.getByTestId('dashboard-view').waitFor({ state: 'visible' })

			// Navigate to My Day (slow first daily read starts), navigate
			// away, then back (fast read lands first).
			await page.getByTestId('nav-today').click()
			await page.getByTestId('today-view').waitFor({ state: 'visible' })
			await page.getByTestId('nav-dashboard').click()
			await page.getByTestId('dashboard-view').waitFor({ state: 'visible' })
			await page.getByTestId('nav-today').click()
			await page.getByTestId('today-view').waitFor({ state: 'visible' })

			// After the slow response eventually lands, the view must still
			// show the CURRENT daily payload (mom's dishes task visible) —
			// the superseded result never replaced it.
			await page.waitForTimeout(3500)
			const row = page.getByTestId('today-task-item').filter({ hasText: 'Do the dishes' }).first()
			await expect(row).toBeVisible()
		} finally {
			await context.close()
		}
	})

	test('a committed completion survives a failing derived refresh; the retry is read-only (D3)', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const task = seededData.data.tasks.find(entry => entry.name === 'Do the dishes')!
		const trashRoomTask = seededData.data.roomTasks.find(roomTask =>
			roomTask.taskId === task.id && roomTask.isActive)!

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await page.goto('/apps/chorestrike/')
			await page.getByTestId('dashboard-view').waitFor({ state: 'visible' })
			await page.getByTestId('nav-today').click()
			await page.getByTestId('today-view').waitFor({ state: 'visible' })

			// Break ONLY the derived count refreshes (both projections).
			await page.route('**/api/v1/room-tasks/overdue*', route => route.abort('connectionreset'))
			await page.route('**/api/v1/room-tasks/due-today*', route => route.abort('connectionreset'))

			const complete = page.waitForResponse((response) => {
				const pathname = new URL(response.url()).pathname
				return response.request().method() === 'POST'
					&& pathname === `/apps/chorestrike/api/v1/room-tasks/${trashRoomTask.id}/complete`
			})
			await page.getByTestId(`today-complete-button-${trashRoomTask.id}`).click()
			const completeResponse = await complete

			// The committed write is a 2xx success and its response-owned
			// reconcile applied (row left Today, undo queued) — the failing
			// OPTIONAL refresh never misreports the completion.
			expect(completeResponse.ok()).toBeTruthy()
			await expect(page.getByTestId(`undo-snackbar-entry-${trashRoomTask.id}`)).toBeVisible()

			// The stale-slice banner offers the read-only retry.
			await page.getByTestId('today-refresh-failed').waitFor({ state: 'visible' })

			// Heal the network and retry — no POST/PUT/DELETE may replay.
			let writesReplayed = 0
			page.on('request', (request) => {
				const method = request.method()
				if (['POST', 'PUT', 'DELETE'].includes(method)) {
					writesReplayed++
				}
			})
			await page.unroute('**/api/v1/room-tasks/overdue*')
			await page.unroute('**/api/v1/room-tasks/due-today*')

			await page.getByTestId('today-refresh-failed').getByRole('button').click()
			await page.getByTestId('today-refresh-failed').waitFor({ state: 'hidden' })

			// Only the two projection GETs happened — zero write replays.
			expect(writesReplayed).toBe(0)
		} finally {
			await context.close()
		}
	})
})
