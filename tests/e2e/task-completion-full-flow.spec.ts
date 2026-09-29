/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Complete Task Completion Flow - Full E2E with Rewards and UI Validation.
 *
 * CS-PLAN-015b: auth via per-user storageState (no loginAsUser / no logout).
 * Every `waitForTimeout` is replaced with an auto-waiting assertion and the
 * `.task-item`/text selectors use the new `task-item` testid.
 *
 * CS-PLAN-068 §3.4: the swallowed reward-toast expectation, the conditional
 * `if (count > 0)` guards and the `toBeGreaterThanOrEqual(0)` combo
 * tautology were repaired — completion receipts are captured and asserted
 * exactly, preconditions are guaranteed by the family seed plus dedicated
 * probe tasks, and a missing fixture FAILS instead of skipping. The WP5
 * completion → undo exact-inverse test below is retained unchanged in
 * intent (CS-PLAN-064).
 */

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'
import { expandAllFloors } from './fixtures/dashboard-floors'
import { houseDateOffset } from './fixtures/house-clock.js'
import type { Page } from '@playwright/test'

/** Resolve the Kitchen room at runtime — the DELETE-based test reset never
 * restarts AUTO_INCREMENT, so room ids grow. */
async function kitchenRoom(page: Page): Promise<{ id: number; cleanliness: number }> {
	const floorsResponse = await page.request.get('/apps/chorestrike/api/v1/floors/with-rooms')
	const rooms = ((await floorsResponse.json()).data as Array<{ rooms: Array<{ id: number; name: string; cleanliness: number }> }>)
		.flatMap((floor) => floor.rooms)
	const kitchen = rooms.find((room) => room.name === 'Kitchen')
	expect(kitchen, 'the family seed always provides the Kitchen room').toBeTruthy()
	return kitchen!
}

async function openKitchenRoomDetail(page: Page): Promise<void> {
	await page.goto('/apps/chorestrike/')
	await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 30000 })
	await expandAllFloors(page)
	await page.getByTestId('room-card-Kitchen').first().click()
	await expect(page.getByTestId('room-detail-view')).toBeVisible({ timeout: 15000 })
}

/**
 * The Kitchen room task whose server proposal lets `userId` complete
 * DIRECTLY (WP3 Q-074-02): no proposal (solo work) or a sole agreement
 * naming the user — rows agreed to someone else would open the
 * participation dialog instead.
 */
async function ownKitchenTask(page: Page, userId: string): Promise<{ id: number; name: string }> {
	const rows = await (await page.request.get('/apps/chorestrike/api/v1/room-tasks')).json()
	const kitchen = await kitchenRoom(page)
	const candidates = ((rows.data ?? []) as Array<{
		id: number; roomId: number; taskId: number; occurrence: unknown; proposal: { participants: Array<{ userId: string }> } | null
	}>).filter(row => row.roomId === kitchen.id && row.occurrence)
	const own = candidates.find(row => {
		const participants = row.proposal?.participants ?? []
		return participants.length === 0 || (participants.length === 1 && participants[0].userId === userId)
	})
	expect(own, 'the family seed always provides a Kitchen task the user may complete directly').toBeTruthy()

	const tasks = await (await page.request.get('/apps/chorestrike/api/v1/tasks')).json()
	const name = ((tasks.data ?? []) as Array<{ id: number; name: string }>).find(task => task.id === own!.taskId)?.name
	expect(name, 'the task definition must resolve').toBeTruthy()
	return { id: own!.id, name: name! }
}

test.describe('Task Completion Full Flow', () => {
	test.use({ databaseProfile: 'family' })

	test('complete task shows rewards feedback and updates the wallet exactly', async ({ browser, seededData: _seededData }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			// Independently captured before-state: the OWN wallet the
			// completion must credit exactly (never a value compared to
			// itself). WP3 §3.6: /game/state is the own-wallet surface.
			const stateBefore = await (await page.request.get('/apps/chorestrike/api/v1/game/state')).json()

			await openKitchenRoomDetail(page)

			// Guaranteed precondition (family seed): a Kitchen task mom may
			// complete DIRECTLY — a missing fixture is a FAILURE, not a skip.
			const own = await ownKitchenTask(page, 'mom')
			const completeButton = page.locator('[data-testid="task-item"]')
				.filter({ hasText: own.name })
				.first()
				.locator('[data-testid="complete-task-button"]')
			await expect(completeButton).toBeVisible({ timeout: 15000 })

			const completeResponsePromise = page.waitForResponse((response) => {
				const pathname = new URL(response.url()).pathname
				return response.request().method() === 'POST' && pathname.endsWith('/complete')
			})
			await completeButton.click()
			const receipt = await (await completeResponsePromise).json()
			expect(receipt.success).toBe(true)

			// The completion feedback (the queued-undo snackbar with the
			// reward summary) MUST appear — no swallowed expectation.
			await expect(page.getByTestId('undo-snackbar')).toBeVisible({ timeout: 5000 })

			// Exact credited minor units (WP3 §3.1): the frozen rate is
			// 10 Coins per energy — the receipt's own-wallet block equals
			// the before total plus totalEnergy * 1000.
			const energy = receipt.data.rewards.completion.totalEnergy
			expect(receipt.data.rewards.wallet.bookedMinor)
				.toBe((stateBefore.data.wallet?.bookedMinor ?? 0) + energy * 1000)
			const stateAfter = await (await page.request.get('/apps/chorestrike/api/v1/game/state')).json()
			expect(stateAfter.data.wallet.bookedMinor).toBe(receipt.data.rewards.wallet.bookedMinor)

			// Exact credited minor units in the caller's own game-state
			// projection (WP4 cutover: the leaderboard no longer renders
			// contribution scores — the state endpoint is the authoritative
			// read for the credited amount, decision D10).
			expect(stateAfter.data.contributionMinor).toBe(receipt.data.rewards.contributionMinor)

		} finally {
			await context.close()
		}
	})

	test('completing multiple tasks books every receipt (combo retired)', async ({ browser, seededData: _seededData }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			// Three UNASSIGNED probe tasks (solo work — no participation
			// dialog) give three always-completable rows; create them
			// BEFORE opening the room so the list read includes them.
			const kitchen = await kitchenRoom(page)
			const probeNames: string[] = []
			for (let i = 0; i < 3; i++) {
				const name = `Sequence Probe ${Date.now()}-${i}`
				const probe = await page.request.post('/apps/chorestrike/api/v1/room-tasks', {
					data: {
						roomId: kitchen.id,
						// CS-PLAN-072 WP1: due TODAY so the occurrence window is
						// open and the UI completion resolves a shown target.
						nextDue: houseDateOffset(0, 'UTC'),
						task: { name, energyCost: 1, frequencyType: 'daily', frequencyValue: 1 },
					},
				})
				expect(probe.status()).toBe(200)
				probeNames.push(name)
			}

			await openKitchenRoomDetail(page)

			// The retired combo flag is gone; the durable claim is that every
			// completion books its own frozen-rate credit into the wallet.

			const receipts: Array<{ bookedMinor: number; totalEnergy: number }> = []
			for (const name of probeNames) {
				const button = page.locator('[data-testid="task-item"]')
					.filter({ hasText: name })
					.first()
					.locator('[data-testid="complete-task-button"]')
				await expect(button).toBeVisible({ timeout: 15000 })
				const completeResponsePromise = page.waitForResponse((response) => {
					const pathname = new URL(response.url()).pathname
					return response.request().method() === 'POST' && pathname.endsWith('/complete')
				})
				await button.click()
				const receipt = await (await completeResponsePromise).json()
				expect(receipt.success).toBe(true)
				receipts.push(receipt.data.rewards)
			}

			// Every receipt booked its own frozen rate credit: consecutive
			// receipt wallet totals differ by exactly the later completion's
			// totalEnergy * 1000, and the final receipt matches the
			// persisted own-wallet total.
			const credit = (r: { completion: { totalEnergy: number } }) => r.completion.totalEnergy * 1000
			for (let i = 1; i < receipts.length; i++) {
				expect(receipts[i].wallet.bookedMinor - receipts[i - 1].wallet.bookedMinor).toBe(credit(receipts[i]))
			}
			const stateAfter = await (await page.request.get('/apps/chorestrike/api/v1/game/state')).json()
			expect(receipts[2].wallet.bookedMinor).toBe(stateAfter.data.wallet.bookedMinor)
		} finally {
			await context.close()
		}
	})

	test('task completion updates room cleanliness by the exact delta', async ({ browser, seededData: _seededData }) => {
		const { context, page } = await createUserContext(browser, 'teen')
		try {
			await page.goto('/apps/chorestrike/')
			// CS-PLAN-066 (D4): a child's bare-root visit lands on My Day;
			// the Dashboard stays reachable through explicit navigation.
			await expect(page.getByTestId('today-view')).toBeVisible({ timeout: 30000 })
			await page.getByTestId('nav-dashboard').click()
			await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 30000 })

			await expandAllFloors(page)

			const kitchen = await kitchenRoom(page)
			const roomCard = page.getByTestId('room-card-Kitchen').first()
			await expect(roomCard.locator('.cleanliness-value')).toHaveText(`${kitchen.cleanliness}%`)
			const initialCleanliness = kitchen.cleanliness

			await roomCard.click()
			await expect(page.getByTestId('room-detail-view')).toBeVisible({ timeout: 15000 })

			// Guaranteed precondition: an active task exists to complete.
			const completeButton = page.getByTestId('task-item').filter({ hasText: 'Wipe kitchen counters' }).getByTestId('complete-task-button')
			await expect(completeButton).toBeVisible({ timeout: 15000 })

			const completeResponsePromise = page.waitForResponse((response) => {
				const pathname = new URL(response.url()).pathname
				return response.request().method() === 'POST' && pathname.endsWith('/complete')
			})
			await completeButton.click()
			const receipt = await (await completeResponsePromise).json()
			expect(receipt.success).toBe(true)

			await page.reload()
			await expect(page.getByTestId('room-detail-view')).toBeVisible({ timeout: 15000 })

			// Exact clamp arithmetic: initial + 10% per energy point, capped
			// at 100 (never "greater than or equal" hand-waving).
			expect(initialCleanliness).toBe(75)
			expect(receipt.data.rewards.cleanlinessChange).toBe(20)
			await expect(page.getByTestId('cleanliness-value')).toHaveText('95%', { timeout: 15000 })
		} finally {
			await context.close()
		}
	})

	test('completed task shows completed checkmark instead of button', async ({ browser, seededData: _seededData }) => {
		const { context, page } = await createUserContext(browser, 'dad')
		try {
			await openKitchenRoomDetail(page)

			// Guaranteed precondition — no runtime skip on a missing fixture.
			const taskItem = page.locator('[data-testid="task-item"]:has([data-testid="complete-task-button"])').first()
			await expect(taskItem).toBeVisible({ timeout: 15000 })
			const taskName = await taskItem.locator('.task-name').textContent() ?? ''

			const completeResponsePromise = page.waitForResponse((response) => {
				const pathname = new URL(response.url()).pathname
				return response.request().method() === 'POST' && pathname.endsWith('/complete')
			})
			await taskItem.locator('[data-testid="complete-task-button"]').click()
			const receipt = await (await completeResponsePromise).json()
			expect(receipt.success).toBe(true)

			await page.reload()
			await expect(page.getByTestId('room-detail-view')).toBeVisible({ timeout: 15000 })

			// Unconditional: the completed task no longer offers a complete
			// button (deactivated occurrence or completed projection).
			const completedTaskItem = page.locator('[data-testid="task-item"]').filter({ hasText: String(taskName) }).first()
			await expect(completedTaskItem.locator('[data-testid="complete-task-button"]')).toHaveCount(0, { timeout: 15000 })
		} finally {
			await context.close()
		}
	})

	test('complete → undo restores the exact state, stable across reload (CS-PLAN-064)', async ({ browser, seededData: _seededData }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			// Resolve the Kitchen room at runtime — the DELETE-based test
			// reset never restarts AUTO_INCREMENT, so room ids grow.
			const kitchen = await kitchenRoom(page)

			// A dedicated ONE-TIME task: its completion deactivates the row
			// and claims rewards/cleanliness — the undo aggregate must
			// restore the full recorded before-state (exact inverse).
			const createResponse = await page.request.post('/apps/chorestrike/api/v1/room-tasks', {
				data: {
					roomId: kitchen.id,
					assigneeUserIds: ['mom'],
					// CS-PLAN-072 WP1: due TODAY so the once occurrence window
					// is open and the UI completion resolves a shown target.
					nextDue: houseDateOffset(0, 'UTC'),
					task: {
						name: 'WP5 Undo Proof',
						energyCost: 2,
						frequencyType: 'weekly',
						frequencyValue: 1,
						recurrenceMode: 'once',
					},
				},
			})
			expect(createResponse.status()).toBe(200)
			const created = (await createResponse.json()).data.roomTask

			// Independently captured before-state: the OWN wallet whose
			// exact restoration the undo proves (WP3: /game/state).
			const stateBefore = await (await page.request.get('/apps/chorestrike/api/v1/game/state')).json()
			const walletBefore = stateBefore.data.wallet?.bookedMinor ?? 0

			await openKitchenRoomDetail(page)

			const taskRow = page.locator('[data-testid="task-item"]').filter({ hasText: 'WP5 Undo Proof' }).first()
			await expect(taskRow).toBeVisible({ timeout: 15000 })

			const completeResponsePromise = page.waitForResponse((response) => {
				const pathname = new URL(response.url()).pathname
				return response.request().method() === 'POST' && pathname === `/apps/chorestrike/api/v1/room-tasks/${created.id}/complete`
			})
			await taskRow.locator('[data-testid="complete-task-button"]').click()
			const completeBody = await (await completeResponsePromise).json()
			expect(completeBody.success).toBe(true)

			// Exact UI state right after the one aggregate response: the row
			// is completed (one-time deactivation) and the cleanliness stat
			// shows the reconciled server value — no re-fetch.
			await expect(taskRow).toHaveClass(/completed/)
			await expect(page.getByTestId('cleanliness-value')).toHaveText(`${completeBody.data.room.cleanliness}%`)

			// Undo through the snackbar → one targeted correction
			// (WP3 §3.4: POST /completions/{id}/undo with the receipt id).
			await expect(page.getByTestId('undo-snackbar')).toBeVisible()
			const undoResponsePromise = page.waitForResponse((response) => {
				const pathname = new URL(response.url()).pathname
				return response.request().method() === 'POST'
					&& /^\/apps\/chorestrike\/api\/v1\/completions\/\d+\/undo$/.test(pathname)
			})
			await page.locator(`[data-testid="undo-snackbar-undo-button-${created.id}"]`).click()
			const undoBody = await (await undoResponsePromise).json()
			expect(undoBody.data.undone).toBe(true)

			// Live revert: the row is active again with its complete button.
			await expect(taskRow).not.toHaveClass(/completed/)
			await expect(taskRow.locator('[data-testid="complete-task-button"]')).toBeVisible()
			await expect(page.getByTestId('cleanliness-value')).toHaveText(`${kitchen.cleanliness}%`)

			// No stale caches: after a reload the ROOM VIEW carries the
			// original persisted state again.
			await page.reload()
			await expect(page.getByTestId('room-detail-view')).toBeVisible({ timeout: 15000 })
			await expect(taskRow).not.toHaveClass(/completed/)
			await expect(page.getByTestId('cleanliness-value')).toHaveText(`${kitchen.cleanliness}%`)

			// …the OWN wallet shows the PRE-COMPLETION booked total again
			// (the correction posted the exact negative entries)…
			const stateAfterUndo = await (await page.request.get('/apps/chorestrike/api/v1/game/state')).json()
			expect(stateAfterUndo.data.wallet.bookedMinor).toBe(walletBefore)

			// …and the persisted room task row equals its pre-completion
			// state (recurrence inverse via the API ground truth).
			const roomTasksResponse = await page.request.get(`/apps/chorestrike/api/v1/room-tasks?roomId=${kitchen.id}`)
			const restored = ((await roomTasksResponse.json()).data as Array<{ id: number }>)
				.find(roomTask => roomTask.id === created.id)
			expect(restored).toBeTruthy()
			expect(restored!.lastCompleted).toBeNull()
			expect(restored!.isActive).toBe(true)
			expect(restored!.nextDue).toBe(created.nextDue)
		} finally {
			await context.close()
		}
	})
})
