import type { APIResponse } from '@playwright/test'
import { test, expect } from './fixtures/database-fixtures'
import { userStatePath } from './fixtures/auth-contexts'
import { completeRoomTaskViaApi, newCommandId, resolveOccurrenceReceipt } from './fixtures/completion-api'
import { houseDateOffset } from './fixtures/house-clock.js'

const API = '/apps/chorestrike/api/v1'

async function dataOf(response: APIResponse) {
	expect(response.status(), await response.text()).toBe(200)
	return (await response.json()).data
}

/**
 * E2E test: Settings - house and floor management
 * Tests house name editing, floor management, and reset functionality
 *
 * CS-PLAN-015b: auth via project-level storageState (no loginAsUser).
 * CS-PLAN-042: house/floors/danger sections are OWNER-only, so this spec
 * runs as `mom` (seeded family-house owner) — the default `admin` identity
 * is a plain member under the family profile.
 */
test.describe('Settings', () => {
	test.use({ storageState: userStatePath('mom'), databaseProfile: 'family' })

	test.beforeEach(async ({ page, seededData }) => {
		expect(seededData).not.toBeNull()
		test.setTimeout(60000)

		await page.goto('/apps/chorestrike/#/dashboard')
		await page.waitForLoadState('networkidle', { timeout: 30000 })

		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })

		await page.getByTestId('nav-settings').click()
		await page.waitForLoadState('networkidle', { timeout: 30000 })

		await page.waitForSelector('[data-testid="settings-view"]', { timeout: 20000 })
	})

	test('Settings page loads correctly', async ({ page }) => {
		await expect(page.locator('[data-testid="settings-view"]')).toBeVisible()

		await expect(page.locator('[data-testid="settings-title"]')).toContainText('Settings')
	})

	test('House drafts wait for the winning read on a direct Settings load', async ({ page }) => {
		await expect(page.getByTestId('house-name-input')).toBeEnabled()
		const savedName = await page.getByTestId('house-name-input').inputValue()
		expect(savedName).not.toBe('')
		let requests = 0
		let settingsDelivered = false
		let releaseSettings!: () => void
		let releaseLatest!: () => void
		const settingsBarrier = new Promise<void>((resolve) => {
			releaseSettings = resolve
		})
		const latestBarrier = new Promise<void>((resolve) => {
			releaseLatest = resolve
		})
		await page.route(`**${API}/house`, async (route) => {
			const index = ++requests
			const response = await route.fetch()
			if (index === 2) {
				await settingsBarrier
			}
			if (index === 3) {
				await latestBarrier
			}
			await route.fulfill({ response })
			if (index === 2) {
				settingsDelivered = true
			}
		})
		try {
			await page.reload()
			await expect.poll(() => requests).toBe(2)
			await page.evaluate(() => {
				const app = (window as unknown as { chorestrike: { pinia: { _s: Map<string, { fetchHouse: () => Promise<unknown> }> } } }).chorestrike
				app.pinia._s.get('house')!.fetchHouse()
			})
			await expect.poll(() => requests).toBe(3)
			releaseSettings()
			await expect.poll(() => settingsDelivered).toBe(true)
			await expect(page.getByTestId('house-name-input')).toBeDisabled()
			await expect(page.getByTestId('house-name-input')).toHaveValue('')
			releaseLatest()
			await expect(page.getByTestId('house-name-input')).toBeEnabled()
			await expect(page.getByTestId('house-name-input')).toHaveValue(savedName)
			await expect(page.getByTestId('save-house-name')).toBeDisabled()
		} finally {
			releaseSettings()
			releaseLatest()
		}
	})

	test('Cold structure hydration survives room reconciliation', async ({ page }) => {
		let release!: () => void
		const barrier = new Promise<void>((resolve) => {
			release = resolve
		})
		let snapshot: { floor: { id: number }, rooms: { id: number }[] }[] | null = null
		let requests = 0
		await page.route(`**${API}/floors/with-rooms`, async (route) => {
			const index = ++requests
			const response = await route.fetch()
			if (index === 1) {
				snapshot = (await response.json()).data
				await barrier
			}
			await route.fulfill({ response })
		})
		try {
			await page.reload()
			await expect.poll(() => snapshot?.length || 0).toBeGreaterThan(0)
			const room = snapshot!.flatMap((item) => item.rooms)[0]
			expect(room).toBeDefined()
			await page.evaluate((room) => {
				const app = (window as unknown as { chorestrike: { pinia: { _s: Map<string, { floors: unknown[], rooms: unknown[], reconcileRoom: (room: unknown) => void }> } } }).chorestrike
				const store = app.pinia._s.get('rooms')!
				if (store.floors.length || store.rooms.length) {
					throw new Error('Expected a cold structure cache')
				}
				store.reconcileRoom(room)
			}, room)
			await expect(page.getByTestId('structure-loading')).toBeVisible()
			release()
			await expect.poll(() => requests).toBe(2)
			await expect(page.getByTestId('floors-list').locator('.floor-item')).toHaveCount(snapshot!.length)
			await expect(page.getByTestId('structure-loading')).toHaveCount(0)
			await expect(page.getByTestId('no-floors-message')).toHaveCount(0)
		} finally {
			release()
		}
	})

	for (const width of [390, 1440]) {
		test(`Owner timezone and populated floor controls remain reachable at ${width}px`, async ({ page }) => {
			await page.setViewportSize({ width, height: width === 390 ? 844 : 900 })
			await page.goto('/apps/chorestrike/')
			await expect(page.getByTestId('today-view')).toBeVisible()
			if (width < 768) {
				await page.getByRole('button', { name: /Open navigation/i }).click()
			}
			await page.getByTestId('nav-settings').click()
			await expect(page.getByTestId('house-section')).toBeVisible()
			if (width < 768) {
				await page.getByRole('button', { name: /Close navigation/i }).click()
			}

			const floor = page.getByTestId('floors-list').locator('.floor-item').first()
			await expect(floor).toBeVisible()
			await expect(floor.locator('.room-count')).toContainText(/[1-9]\d* rooms/)
			const actions = floor.locator('.floor-actions button')
			await expect(actions).toHaveCount(5)
			const timezone = page.getByTestId('house-timezone-select')
			const originalZone = await timezone.inputValue()
			const changedZone = originalZone === 'Europe/Berlin' ? 'UTC' : 'Europe/Berlin'
			await timezone.selectOption(changedZone)
			await expect(page.getByTestId('save-house-timezone')).toBeEnabled()

			for (const control of [timezone, page.getByTestId('save-house-timezone'), ...await actions.all()]) {
				await expect(control).toBeVisible()
				await control.scrollIntoViewIfNeeded()
				await expect.poll(() => control.evaluate((element) => {
					const bounds = element.getBoundingClientRect()
					const content = element.closest('.setting-card')!.getBoundingClientRect()
					return bounds.width > 0 && bounds.left >= Math.max(0, content.left)
						&& bounds.right <= Math.min(window.innerWidth, content.right)
				})).toBe(true)
			}
			for (const container of [page.locator('#app-content-vue'), page.getByTestId('settings-view'), floor]) {
				await expect.poll(() => container.evaluate((element) => element.scrollWidth <= element.clientWidth)).toBe(true)
			}

			const saved = page.waitForResponse((response) => response.url().endsWith(`${API}/house`) && response.request().method() === 'PUT')
			await page.getByTestId('save-house-timezone').click()
			expect((await saved).ok()).toBe(true)
			await expect(page.getByTestId('save-house-timezone')).toBeDisabled()
			await page.reload()
			await expect(timezone).toHaveValue(changedZone)
			await timezone.selectOption(originalZone)
			const restored = page.waitForResponse((response) => response.url().endsWith(`${API}/house`) && response.request().method() === 'PUT')
			await page.getByTestId('save-house-timezone').click()
			expect((await restored).ok()).toBe(true)
			await expect(page.getByTestId('save-house-timezone')).toBeDisabled()
			// Reach the far-right destructive action without deleting seeded facts.
			await floor.getByTitle('Delete floor', { exact: true }).click()
			await expect(page.getByTestId('confirm-dialog')).toBeVisible()
			await page.getByTestId('confirm-dialog').getByRole('button', { name: 'Cancel', exact: true }).click()
			await expect(floor).toBeVisible()
		})
	}

	test('House name input is displayed and interactive', async ({ page }) => {
		await expect(page.locator('[data-testid="house-name-input"]')).toBeVisible()

		await expect(page.locator('[data-testid="save-house-name"]')).toBeVisible()

		await expect(page.locator('[data-testid="house-name-input"]')).toHaveAttribute('placeholder', /house name/i)
	})

	test('User can type in house name field', async ({ page }) => {
		await page.fill('[data-testid="house-name-input"]', 'Test House')

		await expect(page.locator('[data-testid="house-name-input"]')).toHaveValue('Test House')
	})

	test('Floors section is displayed with add button', async ({ page }) => {
		await expect(page.locator('[data-testid="add-floor-button"]')).toBeVisible()
	})

	test('Floors list or empty state is displayed', async ({ page }) => {
		// The floors fetch resolves asynchronously after the view mounts; wait
		// for whichever end state applies instead of probing instantly.
		await expect(
			page.getByTestId('floors-list').or(page.getByTestId('no-floors-message')),
		).toBeVisible({ timeout: 15000 })
	})

	test('User can open add floor modal', async ({ page }) => {
		await page.click('[data-testid="add-floor-button"]')

		await expect(page.locator('.modal-content')).toBeVisible()
	})

	test('Settings page has danger zone section', async ({ page }) => {
		const dangerZone = page.locator('.danger-zone, .reset-section, [data-testid="danger-zone"]')

		if (await dangerZone.count() > 0) {
			await expect(dangerZone).toBeVisible()
		}
	})

	/**
	 * CS-PLAN-081 H4/H-T8: the danger-zone reset restores rooms and house
	 * health only — setup, structure, tasks, planning, history, finances,
	 * reward requests and achievements all survive. The wording must say so
	 * and the retained facts must still be there after the action.
	 */
	test('Reset keeps setup, structure, tasks and history (CS-PLAN-081 H4)', async ({ page }) => {
		test.setTimeout(180000)
		const token = await page.evaluate(() => (window as unknown as { OC: { requestToken: string } }).OC.requestToken)
		await page.context().setExtraHTTPHeaders({ requesttoken: token })
		// The dedicated serial pool owns this richer seed, including linked
		// historical weekly recognition; ordinary APIs create the other facts.
		await dataOf(await page.request.post(`${API}/test/seed/family?closedWeekEvidence=1`))
		const floors = await dataOf(await page.request.get(`${API}/floors/with-rooms`))
		const roomId = floors[0].rooms[0].id
		const probe = await dataOf(await page.request.post(`${API}/room-tasks`, {
			data: {
				roomId,
				assigneeUserIds: ['mom'],
				nextDue: houseDateOffset(0, 'UTC'),
				task: { name: 'Retained agreement probe', energyCost: 1, frequencyType: 'daily', frequencyValue: 1 },
			},
		}))
		const roomTaskId = probe.roomTask.id as number
		const receipt = await resolveOccurrenceReceipt(page, roomTaskId)
		expect(receipt).not.toBeNull()
		await dataOf(await page.request.post(`${API}/occurrences/${receipt!.occurrenceId}/amend`, {
			data: {
				roomTaskId, commandId: newCommandId(), ...receipt, consent: true, date: houseDateOffset(0, 'UTC'),
			},
		}))
		const rows = await dataOf(await page.request.get(`${API}/room-tasks`))
		const earningTask = rows.find((row: { id: number; assignees: string[]; occurrence?: object }) => row.id !== roomTaskId && row.assignees.includes('mom') && row.occurrence)
		expect(earningTask).toBeTruthy()
		const completion = await dataOf(await completeRoomTaskViaApi(page, earningTask.id))
		const house = await dataOf(await page.request.get(`${API}/house`))
		await dataOf(await page.request.put(`${API}/house/catalog`, {
			data: {
				catalogEnabled: true, expectedVersion: house.stateVersion, commandId: newCommandId(),
			},
		}))
		const item = await dataOf(await page.request.post(`${API}/rewards`, {
			data: {
				title: 'Retained reservation', priceMinor: 100, commandId: newCommandId(),
			},
		}))
		const rewardRequest = await dataOf(await page.request.post(`${API}/reward-requests`, {
			data: {
				itemId: item.id, itemVersion: item.version, priceMinor: 100, commandId: newCommandId(),
			},
		}))
		expect(rewardRequest.wallet.reservedMinor).toBe(100)
		expect(rewardRequest.wallet.bookedMinor).toBeGreaterThanOrEqual(100)
		await dataOf(await page.request.post(`${API}/game/check-medals`))

		const snapshot = async () => {
			const structure = await dataOf(await page.request.get(`${API}/floors/with-rooms`))
			const taskRows = await dataOf(await page.request.get(`${API}/room-tasks`))
			const currentHouse = await dataOf(await page.request.get(`${API}/house`))
			return {
				setup: {
					id: currentHouse.id,
					name: currentHouse.name,
					timezone: currentHouse.timezone,
					catalogEnabled: currentHouse.catalogEnabled,
				},
				structure: structure.map((entry: { floor: { id: number; name: string; sortOrder: number }; rooms: Array<{ id: number; floorId: number; name: string; icon: string; roomType: string }> }) => ({
					id: entry.floor.id,
					name: entry.floor.name,
					sortOrder: entry.floor.sortOrder,
					// The room query has no ORDER BY: retained facts, not physical row order, are the contract.
					rooms: entry.rooms.map((room) => ({ id: room.id, floorId: room.floorId, name: room.name, icon: room.icon, roomType: room.roomType })).sort((a, b) => a.id - b.id),
				})),
				tasks: await dataOf(await page.request.get(`${API}/tasks`)),
				planning: taskRows.map((row: { id: number; roomId: number; taskId: number; assignees: string[]; occurrence?: { occurrenceId: string; expectedVersion: number; workDate: string } }) => ({
					id: row.id,
					roomId: row.roomId,
					taskId: row.taskId,
					assignees: row.assignees,
					occurrence: row.occurrence ? { occurrenceId: row.occurrence.occurrenceId, expectedVersion: row.occurrence.expectedVersion, workDate: row.occurrence.workDate } : null,
				})),
				history: await dataOf(await page.request.get(`${API}/completions/${completion.completionId}`)),
				member: await dataOf(await page.request.get(`${API}/users/current`)),
				catalog: await dataOf(await page.request.get(`${API}/rewards?scope=admin`)),
				request: await dataOf(await page.request.get(`${API}/reward-requests/${rewardRequest.id}`)),
				achievements: await dataOf(await page.request.get(`${API}/game/medals`)),
			}
		}
		const before = await snapshot()
		expect(before.structure.length).toBeGreaterThan(0)
		for (const floor of before.structure) {
			expect(floor).toEqual(expect.objectContaining({ id: expect.any(Number), name: expect.any(String), sortOrder: expect.any(Number) }))
		}
		expect(before.history.id).toBe(completion.completionId)
		expect(before.history.allocation.length).toBeGreaterThan(0)
		expect(before.achievements.length).toBeGreaterThan(0)
		expect(before.planning.find((row) => row.id === roomTaskId)?.occurrence?.expectedVersion).toBeGreaterThan(receipt!.expectedVersion)
		await page.reload()
		await expect(page.getByTestId('settings-view')).toBeVisible()
		const dangerZone = page.locator('[data-testid="danger-zone"]')
		await expect(dangerZone).toBeVisible()
		await expect(dangerZone).toContainText(/house health/i)
		await expect(dangerZone).toContainText(/achievements are kept/i)

		await page.locator('[data-testid="danger-zone"] .danger-button').click()
		const modal = page.locator('.modal-overlay')
		await expect(modal).toBeVisible()
		await expect(modal).toContainText(/house health is restored/i)
		await expect(modal).toContainText(/completion history/i)

		// Invalid confirmation and cancellation never send a mutation.
		await modal.locator('input[placeholder="RESET"]').fill('reset')
		await expect(modal.locator('.danger-button')).toBeDisabled()
		let resetCalls = 0
		page.on('request', (request) => {
			if (request.url().endsWith('/house/reset')) {
				resetCalls++
			}
		})
		await modal.locator('.secondary-button').click()
		expect(resetCalls).toBe(0)
		expect(await snapshot()).toEqual(before)
		await page.locator('[data-testid="danger-zone"] .danger-button').click()
		await modal.locator('input[placeholder="RESET"]').fill('RESET')
		// A transport/server failure leaves the modal and retained facts intact;
		// the user's retry is exercised in the same populated installation.
		await page.route(`**${API}/house/reset`, (route) => route.fulfill({
			status: 503, contentType: 'application/json', body: JSON.stringify({ success: false, error: { code: 'RESET_ERROR', message: 'Synthetic test failure' } }),
		}), { times: 1 })
		await modal.locator('.danger-button').click()
		await expect(page.locator('body')).toContainText('Failed to reset house')
		await expect(modal).toBeVisible()
		expect(await snapshot()).toEqual(before)
		const requestPromise = page.waitForRequest((request) => request.url().endsWith('/house/reset') && request.method() === 'POST')
		await modal.locator('.danger-button').click()
		const resetRequest = await requestPromise
		await expect(page.locator('body')).toContainText('Rooms and house health were reset', { timeout: 15000 })
		await expect(modal).not.toBeVisible()
		expect(await snapshot()).toEqual(before)
		const resetHouse = await dataOf(await page.request.get(`${API}/house`))
		expect(resetHouse.houseHealth).toBe(100)
		const cleanFloors = await dataOf(await page.request.get(`${API}/floors/with-rooms`))
		for (const room of cleanFloors.flatMap((floor: { rooms: Array<{ cleanliness: number; monsterCount: number }> }) => floor.rooms)) {
			expect(room.cleanliness).toBe(100)
			expect(room.monsterCount).toBe(0)
		}
		// Repeating the successful receipt must not reset anything a second time.
		await dataOf(await page.request.post(`${API}/house/reset`, { data: resetRequest.postDataJSON() }))
		expect(await dataOf(await page.request.get(`${API}/floors/with-rooms`))).toEqual(cleanFloors)
		expect(await snapshot()).toEqual(before)
	})
})
