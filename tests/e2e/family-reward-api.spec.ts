/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Browser-contract E2E — the family reward catalog and request lifecycle
 * (CS-PLAN-070 WP7 / CS-PLAN-078 §3.3).
 *
 * The WP8 reward UI arrives later; this spec pins the HTTP contract the
 * UI store will consume, through the REAL controllers of the running
 * Nextcloud:
 *  - The catalog gate is owner-controlled and versioned (PUT
 *    /house/catalog with the house `stateVersion`); a stale version
 *    answers 409.
 *  - While the gate is off the active catalog is an explicit empty state
 *    and new requests are denied — reads and processing of EXISTING
 *    requests stay available.
 *  - The full child flow through the real API: fund the wallet with a
 *    real completion, create a request (pending, own wallet attached,
 *    frozen terms), owner approve + fulfill (exact booked debit), then
 *    correct the false fulfillment (exact refund).
 *  - A displayed-terms drift (stale itemVersion) answers 409 — never a
 *    silently reserved different price.
 *
 * Seed-mutating (gate flag, catalog rows, completion, request state):
 * registered in SEED_DEPENDENT_SPECS (chromium-serial-db).
 * Requires: Docker test environment with the `family` database profile.
 */

import { test, expect, DatabaseProfile } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'
import { completeRoomTaskViaApi } from './fixtures/completion-api'

const CATALOG_URL = '/apps/chorestrike/api/v1/house/catalog'
const REWARDS_URL = '/apps/chorestrike/api/v1/rewards'
const REQUESTS_URL = '/apps/chorestrike/api/v1/reward-requests'

function newCommandId(): string {
	return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
		const random = (Math.random() * 16) | 0
		const value = char === 'x' ? random : (random & 0x3) | 0x8
		return value.toString(16)
	})
}

test.describe('Family reward catalog & requests (WP7)', () => {
	test.use({ databaseProfile: 'family' as DatabaseProfile })
	test.setTimeout(120000)

	test('the catalog gate is owner-controlled, versioned and denied for members', async ({ browser, seededData: _seededData }) => {
		const mom = await createUserContext(browser, 'mom')
		const teen = await createUserContext(browser, 'teen')
		try {
			// A member cannot toggle the gate.
			const denied = await teen.page.request.put(CATALOG_URL, {
				data: { catalogEnabled: true, expectedVersion: 0, commandId: newCommandId() },
			})
			expect(denied.status()).toBe(403)

			// The owner toggles with the stateVersion contract.
			const house = await (await mom.page.request.get('/apps/chorestrike/api/v1/house')).json()
			const enable = await mom.page.request.put(CATALOG_URL, {
				data: { catalogEnabled: true, expectedVersion: house.data.stateVersion, commandId: newCommandId() },
			})
			expect(enable.status()).toBe(200)
			expect((await enable.json()).data.catalogEnabled).toBe(true)

			// A stale version answers 409.
			const stale = await mom.page.request.put(CATALOG_URL, {
				data: { catalogEnabled: false, expectedVersion: 0, commandId: newCommandId() },
			})
			expect(stale.status()).toBe(409)

			// The gate reaches GET /house through the entity serialization.
			const refreshed = await (await mom.page.request.get('/apps/chorestrike/api/v1/house')).json()
			expect(refreshed.data.catalogEnabled).toBe(true)
			expect(_seededData!.data.house!.id).toBeGreaterThan(0)
		} finally {
			// Leave the gate off for the other pools (the gate reuses the DB).
			const house = await (await mom.page.request.get('/apps/chorestrike/api/v1/house')).json()
			await mom.page.request.put(CATALOG_URL, {
				data: { catalogEnabled: false, expectedVersion: house.data.stateVersion, commandId: newCommandId() },
			})
			await mom.context.close()
			await teen.context.close()
		}
	})

	test('disabled gate: active catalog is an explicit empty state while admin scope keeps working', async ({ browser, seededData: _seededData }) => {
		const mom = await createUserContext(browser, 'mom')
		const teen = await createUserContext(browser, 'teen')
		try {
			// Gate off (fresh family seed): the active scope reports the
			// disabled state with no content.
			const active = await teen.page.request.get(`${REWARDS_URL}?scope=active`)
			expect(active.status()).toBe(200)
			const activeBody = await active.json()
			expect(activeBody.data.catalogEnabled).toBe(false)
			expect(activeBody.data.items).toEqual([])

			// The owner's admin scope still lists (and can create) items.
			const created = await mom.page.request.post(REWARDS_URL, {
				data: { title: 'Offline zoo', priceMinor: 100, commandId: newCommandId() },
			})
			expect(created.status()).toBe(200)
			const admin = await mom.page.request.get(`${REWARDS_URL}?scope=admin`)
			expect(admin.status()).toBe(200)
			expect((await admin.json()).data.items.some((item: { title: string }) => item.title === 'Offline zoo')).toBe(true)

			// scope=admin stays owner-only.
			const memberAdmin = await teen.page.request.get(`${REWARDS_URL}?scope=admin`)
			expect(memberAdmin.status()).toBe(403)
		} finally {
			await mom.context.close()
			await teen.context.close()
		}
	})

	test('child request lifecycle: frozen terms, approve, fulfill, exact debit, exact refund', async ({ browser, seededData }) => {
		const seed = seededData!.data
		const mom = await createUserContext(browser, 'mom')
		const teen = await createUserContext(browser, 'teen')
		try {
			// Enable the gate and stock the catalog.
			const house = await (await mom.page.request.get('/apps/chorestrike/api/v1/house')).json()
			const enable = await mom.page.request.put(CATALOG_URL, {
				data: { catalogEnabled: true, expectedVersion: house.data.stateVersion, commandId: newCommandId() },
			})
			expect(enable.status()).toBe(200)
			const item = await (await mom.page.request.post(REWARDS_URL, {
				data: { title: 'Zoo day', description: 'Popcorn included', priceMinor: 500, commandId: newCommandId() },
			})).json()
			const itemId = item.data.id as number

			// Fund the child's wallet with a REAL completion of an assigned
			// seeded task (the only production earning path).
			const teenTask = seed.roomTasks!.find((row) => row.assignees?.includes('teen'))
			expect(teenTask, 'the seed must assign a task to teen').toBeTruthy()
			const completion = await completeRoomTaskViaApi(teen.page, teenTask!.id)
			expect(completion.status()).toBe(200)

			// The owner reprices while the child holds the displayed page:
			// the stale displayed terms answer 409 — never a silently
			// reserved different price.
			const reprice = await mom.page.request.put(`${REWARDS_URL}/${itemId}`, {
				data: { priceMinor: 700, expectedVersion: 0, commandId: newCommandId() },
			})
			expect(reprice.status()).toBe(200)
			const drifted = await teen.page.request.post(REQUESTS_URL, {
				data: { itemId, itemVersion: 0, priceMinor: 500, commandId: newCommandId() },
			})
			expect(drifted.status()).toBe(409)

			// The child reloads and requests the FRESH terms: pending, own
			// wallet, frozen terms.
			const created = await teen.page.request.post(REQUESTS_URL, {
				data: { itemId, itemVersion: 1, priceMinor: 700, commandId: newCommandId() },
			})
			expect(created.status()).toBe(200)
			const request = (await created.json()).data
			expect(request.state).toBe('pending')
			expect(request.title).toBe('Zoo day')
			expect(request.priceMinor).toBe(700)
			expect(request.allowedActions).toEqual(['cancel'])
			expect(request.wallet.fundingHold).toBe(false)
			const requestId = request.id as number

			// The owner approves and fulfills — the booked balance drops by
			// exactly the frozen price.
			const approve = await mom.page.request.post(`${REQUESTS_URL}/${requestId}/approve`, {
				data: { commandId: newCommandId(), expectedVersion: request.version },
			})
			expect(approve.status()).toBe(200)
			expect((await approve.json()).data.state).toBe('approved')

			const fulfill = await mom.page.request.post(`${REQUESTS_URL}/${requestId}/fulfill`, {
				data: { commandId: newCommandId(), expectedVersion: 1, delivered: true },
			})
			expect(fulfill.status()).toBe(200)
			const fulfilled = (await fulfill.json()).data
			expect(fulfilled.state).toBe('fulfilled')
			expect(fulfilled.settlementEntryId).toBeGreaterThan(0)

			// The owner reprices again; the request keeps its frozen terms.
			const repriceAgain = await mom.page.request.put(`${REWARDS_URL}/${itemId}`, {
				data: { priceMinor: 9000, expectedVersion: 1, commandId: newCommandId() },
			})
			expect(repriceAgain.status()).toBe(200)
			const detail = await (await teen.page.request.get(`${REQUESTS_URL}/${requestId}`)).json()
			expect(detail.data.priceMinor).toBe(700)
			expect(detail.data.audit.map((event: { action: string }) => event.action)).toEqual([
				'create',
				'approve',
				'fulfill',
			])

			// The owner corrects the false fulfillment against the stored
			// settlement reference — the ORIGINAL debit is refunded.
			const correct = await mom.page.request.post(`${REQUESTS_URL}/${requestId}/correct`, {
				data: {
					commandId: newCommandId(),
					expectedVersion: 2,
					notDelivered: true,
					reasonCode: 'not_delivered',
					settlementEntryId: fulfilled.settlementEntryId,
				},
			})
			expect(correct.status()).toBe(200)
			expect((await correct.json()).data.state).toBe('corrected')
		} finally {
			// Leave the gate off for the other pools (the gate reuses the DB).
			const house = await (await mom.page.request.get('/apps/chorestrike/api/v1/house')).json()
			await mom.page.request.put(CATALOG_URL, {
				data: { catalogEnabled: false, expectedVersion: house.data.stateVersion, commandId: newCommandId() },
			})
			await mom.context.close()
			await teen.context.close()
		}
	})
})
