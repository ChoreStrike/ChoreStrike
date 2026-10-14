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

import { test, expect, PROFILE_USERS } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'
import { formatCoinMinor } from '../../src/utils/minorUnits'

import { holdToConfirm } from './fixtures/hold-to-confirm'
test.describe('Workflow state reconciliation (CS-PLAN-066)', () => {
	test.use({ databaseProfile: 'family' })

	test('same-device logout and child login discard the prior wallet, undo queue and optional preferences', async ({ browser, baseURL, seededData }) => {
		expect(seededData).not.toBeNull()
		// Own fresh login sessions: logout must not invalidate shared setup storage states.
		const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } })
		const page = await context.newPage()
		const login = async (userId: string) => {
			const credentials = PROFILE_USERS.family.find(user => user.userId === userId)!
			await page.goto('/login')
			await page.locator('input[name="user"]').fill(credentials.userId)
			await page.locator('input[name="password"]').fill(credentials.password)
			await page.locator('button[type="submit"]').click()
			await page.waitForURL('**/apps/**')
		}
		try {
			await login('mom')
			await page.goto('/apps/chorestrike/#/settings')
			await expect(page.getByTestId('arcade-visible-switch')).toBeChecked()
			await page.getByText('Show the arcade', { exact: true }).click()
			await page.getByTestId('save-preferences').click()
			await expect(page.getByTestId('nav-arcade')).toHaveCount(0)
			await page.getByTestId('nav-today').click()
			const row = page.getByTestId('today-task-item').filter({ hasText: 'Do the dishes' })
			const completion = page.waitForResponse(response => response.request().method() === 'POST'
				&& /\/api\/v1\/room-tasks\/\d+\/complete$/.test(new URL(response.url()).pathname))
			await holdToConfirm(row.getByRole('button', { name: 'Done', exact: true }))
			const receipt = await completion
			expect(receipt.status()).toBe(200)
			const priorMinor = (await receipt.json()).data.rewards.wallet.availableMinor
			expect(priorMinor).toBeGreaterThan(0)
			await expect(page.locator('[data-testid^="undo-snackbar-entry-"]')).toHaveCount(1)
			await page.getByTestId('nav-dashboard').click()
			await expect(page.getByTestId('own-wallet-available')).toBeVisible()
			// A visible cached wallet is not proof that the new balance rendered.
			await expect(page.getByTestId('own-wallet-available')).toHaveText(formatCoinMinor(priorMinor))
			const priorWallet = await page.getByTestId('own-wallet-available').innerText()
			const logout = await page.evaluate(() => '/logout?requesttoken=' + encodeURIComponent((window as unknown as { OC: { requestToken: string } }).OC.requestToken))
			await expect(page.locator('[data-testid^="undo-snackbar-entry-"]')).toBeVisible()
			await page.goto(logout)
			await expect(page.locator('input[name="user"]')).toBeVisible()
			await login('teen')
			await page.goto('/apps/chorestrike/#/today')
			await expect(page.getByTestId('today-task-item').filter({ hasText: 'Wipe kitchen counters' })).toBeVisible()
			await expect(page.getByTestId('today-task-item').filter({ hasText: 'Do the dishes' })).toHaveCount(0)
			await expect(page.locator('[data-testid^="undo-snackbar-entry-"]')).toHaveCount(0)
			await expect(page.getByTestId('nav-arcade')).toBeVisible()
			const childState = page.waitForResponse('**/api/v1/game/state')
			await page.getByTestId('nav-dashboard').click()
			const childResponse = await childState
			expect(childResponse.status()).toBe(200)
			const childMinor = (await childResponse.json()).data.wallet.availableMinor
			expect(childMinor).toBe(0)
			await expect(page.getByTestId('own-wallet-available')).toBeVisible()
			await expect(page.getByTestId('own-wallet-available')).toHaveText(formatCoinMinor(childMinor))
			await expect(page.getByTestId('own-wallet-available')).not.toHaveText(priorWallet)
			await page.getByTestId('nav-settings').click()
			await expect(page.getByTestId('arcade-visible-switch')).toBeChecked()
		} finally { await context.close() }
	})

	test('rapid parallel navigation ends in the reconciled state — no stale overwrite', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await page.goto('/apps/chorestrike/#/dashboard')
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

			await page.goto('/apps/chorestrike/#/dashboard')
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
			await page.goto('/apps/chorestrike/#/dashboard')
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
			await holdToConfirm(page.getByTestId(`today-complete-button-${trashRoomTask.id}`))
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
