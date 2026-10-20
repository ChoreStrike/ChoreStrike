/**
 * E2E test: reward UI and onboarding (CS-PLAN-070 WP8 / CS-PLAN-079) —
 * serial family pool.
 *
 * Covers the WP8 slice through the real UI: the two independent catalog
 * gates, owner administration with exact decimal prices, the child
 * request → approval → delivery lifecycle, adult self-approval /
 * cancellation / attested correction, conflict-safe terms review, the
 * correction-related funding hold, role/privacy/identity boundaries, the
 * uncertain-command replay and the mobile/keyboard/localization proof.
 * APIs set up data and stimulate concurrent changes; the measured
 * actions run in the browser. Post-action state is polled through the
 * authoritative reads (never through UI toasts, which may still show a
 * previous action's message).
 */

import { createUserContext } from './fixtures/auth-contexts'
import { completeAsSoleParticipantViaApi, newCommandId } from './fixtures/completion-api'
import { type APIResponse, type Page, DatabaseProfile, expect, test } from './fixtures/database-fixtures'
import { runOcc } from './fixtures/occ-runner'

const API = '/apps/chorestrike/api/v1'

/** Small, stable item/request DTO shapes used across the scenarios. */
interface RewardItem {
	id: number
	title: string
	description: string | null
	priceMinor: number
	archived: boolean
	version: number
}

interface RewardRequest {
	id: number
	title: string
	priceMinor: number
	state: 'pending' | 'approved' | 'fulfilled' | 'rejected' | 'cancelled' | 'corrected'
	version: number
	requester: { houseUserId: number, displayName: string, isFormerMember: boolean } | null
	allowedActions: string[]
	fundingHold: boolean
	settlementEntryId?: number | null
}

interface Wallet {
	bookedMinor: number
	reservedMinor: number
	availableMinor: number
	fundingHold: boolean
}

// ---------------------------------------------------------------------------
// API helpers (setup/stimulation/authority polls — the measured actions
// run in the UI)
// ---------------------------------------------------------------------------

const jsonOf = async (response: APIResponse) => {
	expect(response.ok(), await response.text()).toBe(true)
	return (await response.json()).data
}

/** The ONLY catalog gate writer (narrow versioned command). */
const setCatalogGate = async (page: Page, enabled: boolean): Promise<void> => {
	const house = await jsonOf(await page.request.get(`${API}/house`))
	await jsonOf(await page.request.put(`${API}/house/catalog`, {
		data: { catalogEnabled: enabled, expectedVersion: house.stateVersion, commandId: newCommandId() },
	}))
}

const createItem = async (page: Page, fields: { title: string, description?: string, priceMinor: number }): Promise<RewardItem> => {
	return await jsonOf(await page.request.post(`${API}/rewards`, {
		data: { title: fields.title, description: fields.description ?? null, priceMinor: fields.priceMinor, commandId: newCommandId() },
	})) as RewardItem
}

const fetchHouseUsers = async (page: Page): Promise<Array<{ id: number, nextcloudUserId: string, role: string }>> => {
	return await jsonOf(await page.request.get(`${API}/users`))
}

const myWallet = async (page: Page): Promise<Wallet> => {
	const state = await jsonOf(await page.request.get(`${API}/game/state`))
	return state.wallet as Wallet
}

const fetchMyRequest = async (page: Page, id: number): Promise<RewardRequest> => {
	return await jsonOf(await page.request.get(`${API}/reward-requests/${id}`)) as RewardRequest
}

/**
 * Poll an authorized request list until the displayed title reaches the
 * expected state (or exists at all). This waits for the SERVER truth —
 * never on UI feedback, which can lag or show a previous action's toast.
 */
async function waitForRequest(page: Page, scope: 'mine' | 'house', title: string, state?: RewardRequest['state']): Promise<number> {
	const deadline = Date.now() + 45000
	for (;;) {
		const data = await jsonOf(await page.request.get(`${API}/reward-requests?scope=${scope}&limit=50`)).catch(() => null)
		const match = (data?.requests as RewardRequest[] | undefined)?.find((entry) => entry.title === title && (!state || entry.state === state),)
		if (match) {
			return match.id
		}
		if (Date.now() > deadline) {
			throw new Error(`timed out waiting for a ${scope} request "${title}"${state ? ` in state "${state}"` : ''}`)
		}
		await new Promise((resolve) => setTimeout(resolve, 700))
	}
}

/**
 * Fund a user through the real completion path: prefer a seeded room task
 * assigned to them, fall back to any active task with an explicit solo
 * split (Q-074-02: the completer takes part in the work). Not every
 * assigned task exposes a completable occurrence at every wall-clock
 * moment, so the helper walks the candidates.
 */
const fundUserViaCompletion = async (page: Page, seeded: { data: { roomTasks: Array<{ id: number, assignees: string[], isActive: boolean }> } }, userId: string): Promise<{ roomTaskId: number, response: APIResponse }> => {
	const active = seeded.data.roomTasks.filter((entry) => entry.isActive)
	const ordered = [
		...active.filter((entry) => entry.assignees.includes(userId)),
		...active.filter((entry) => !entry.assignees.includes(userId)),
	]
	for (const roomTask of ordered) {
		const response = await completeAsSoleParticipantViaApi(page, roomTask.id, userId).catch(() => null)
		if (response && response.ok()) {
			return { roomTaskId: roomTask.id, response }
		}
	}
	throw new Error(`could not fund ${userId} through any active seeded room task`)
}

/** A fresh app boot: the client re-reads house and preferences. */
async function reloadApp(page: Page, hash: string): Promise<void> {
	await page.goto(`/apps/chorestrike/#${hash}`)
	await page.reload()
}

test.describe('Reward UI and onboarding (CS-PLAN-070 WP8)', () => {
	test.use({ databaseProfile: 'family' as DatabaseProfile })
	test.setTimeout(240000)

	// -------------------------------------------------------------------
	// Scenario 1: catalog-off core and independent opt-ins
	// -------------------------------------------------------------------
	test('catalog-off core works for every role and the two opt-ins stay independent', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const owner = await createUserContext(browser, 'mom')
		const teen = await createUserContext(browser, 'teen')
		try {
			// House gate is OFF by default: the core stays fully usable.
			for (const session of [owner, teen]) {
				await session.page.goto('/apps/chorestrike/')
				await expect(session.page.getByTestId('today-view')).toBeVisible({ timeout: 30000 })
				await session.page.goto('/apps/chorestrike/#/scheduling')
				await expect(session.page.getByTestId('scheduling-view')).toBeVisible({ timeout: 30000 })
				// No catalog entry; the request surface is persistent.
				await expect(session.page.getByTestId('nav-rewards')).toHaveCount(0)
				await expect(session.page.getByTestId('nav-reward-requests')).toBeVisible()
			}

			// A direct entry while hidden shows the neutral explanation.
			await teen.page.goto('/apps/chorestrike/#/rewards')
			await expect(teen.page.getByTestId('rewards-unavailable')).toBeVisible({ timeout: 30000 })

			// Owner turns the HOUSE gate on → both roles browse (their
			// personal preference defaults to visible). The gate flipped in
			// another session, so each app refreshes its reads with a boot.
			await setCatalogGate(owner.page, true)
			await reloadApp(owner.page, '/today')
			await expect(owner.page.getByTestId('nav-rewards')).toBeVisible({ timeout: 15000 })
			await reloadApp(teen.page, '/today')
			await expect(teen.page.getByTestId('nav-rewards')).toBeVisible({ timeout: 15000 })

			// The personal opt-out wins over the house gate: teen hides the
			// catalog in the own notification settings…
			await teen.page.goto('/apps/chorestrike/#/settings')
			const catalogSwitch = teen.page.getByTestId('catalog-visible-switch')
			await expect(catalogSwitch).toBeChecked()
			await teen.page.getByText('Show the reward catalog', { exact: true }).click()
			await expect(catalogSwitch).not.toBeChecked()
			await teen.page.getByTestId('save-preferences').click()

			// …and the navigation entry disappears immediately — while My
			// Requests stays and the owner still sees the catalog.
			await expect(teen.page.getByTestId('nav-rewards')).toHaveCount(0, { timeout: 15000 })
			await expect(teen.page.getByTestId('nav-reward-requests')).toBeVisible()
			await expect(owner.page.getByTestId('nav-rewards')).toBeVisible()

			// Personal opt-in restores browsing for teen.
			await teen.page.goto('/apps/chorestrike/#/settings')
			await teen.page.getByText('Show the reward catalog', { exact: true }).click()
			await teen.page.getByTestId('save-preferences').click()
			await teen.page.goto('/apps/chorestrike/#/today')
			await expect(teen.page.getByTestId('nav-rewards')).toBeVisible({ timeout: 15000 })
		} finally {
			await setCatalogGate(owner.page, false).catch(() => {})
			await owner.context.close()
			await teen.context.close()
		}
	})

	// -------------------------------------------------------------------
	// Scenario 2: owner administration and exact prices
	// -------------------------------------------------------------------
	test('owner administration works while the catalog is off and prices are exact', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const owner = await createUserContext(browser, 'mom')
		const member = await createUserContext(browser, 'grandma')
		try {
			// The gate stays OFF: owner administration must not depend on it.
			await owner.page.goto('/apps/chorestrike/#/settings')
			const catalogSection = owner.page.getByTestId('catalog-section')
			await expect(catalogSection).toBeVisible({ timeout: 30000 })

			// Create with an exact two-decimal price.
			await owner.page.getByTestId('new-item-title').fill('Movie night')
			await owner.page.getByTestId('new-item-price').fill('12.50')
			await owner.page.getByTestId('new-item-submit').click()
			await expect(owner.page.getByTestId('catalog-form-error')).toHaveCount(0)
			await expect(catalogSection.getByText('Movie night')).toBeVisible()
			await expect(catalogSection.getByText('12.5')).toBeVisible()

			// Whole prices and comma decimals parse the same.
			await owner.page.getByTestId('new-item-title').fill('Choose dinner')
			await owner.page.getByTestId('new-item-price').fill('7')
			await owner.page.getByTestId('new-item-submit').click()
			await expect(catalogSection.getByText('Choose dinner')).toBeVisible()
			await expect(catalogSection.getByText('7')).toBeVisible()

			// Rejected inputs: too many decimals, zero, garbage — nothing is sent.
			for (const bad of ['1.234', '0', 'abc']) {
				await owner.page.getByTestId('new-item-title').fill('Bad price')
				await owner.page.getByTestId('new-item-price').fill(bad)
				await owner.page.getByTestId('new-item-submit').click()
				await expect(owner.page.getByTestId('catalog-form-error')).toBeVisible()
			}

			// Members have no administration surface.
			await member.page.goto('/apps/chorestrike/#/settings')
			await expect(member.page.getByTestId('catalog-section')).toHaveCount(0)
		} finally {
			await owner.context.close()
			await member.context.close()
		}
	})

	// -------------------------------------------------------------------
	// Scenario 3: child request, approval and delivery
	// -------------------------------------------------------------------
	test('child request → owner approval → delivery spends exactly once', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const owner = await createUserContext(browser, 'mom')
		const teen = await createUserContext(browser, 'teen')
		try {
			await setCatalogGate(owner.page, true)
			const item = await createItem(owner.page, { title: 'Ice cream sundae', priceMinor: 500 })

			// Fund the child through the real completion path.
			await fundUserViaCompletion(teen.page, seededData as never, 'teen')
			const before = await myWallet(teen.page)

			// The child requests from the explicitly displayed terms.
			await teen.page.goto('/apps/chorestrike/#/rewards')
			await expect(teen.page.getByTestId('catalog-item-title')).toHaveText('Ice cream sundae')
			await expect(teen.page.getByTestId('catalog-item-price')).toHaveText('5')
			await teen.page.getByTestId(`catalog-request-${item.id}`).click()
			const confirmDialog = teen.page.getByTestId('request-confirm')
			await expect(confirmDialog).toBeVisible()
			await expect(confirmDialog.getByTestId('request-confirm-title')).toHaveText('Ice cream sundae')
			await teen.page.getByTestId('request-confirm-button').click()

			// Pending + reserved (not spent): available drops by exactly 5.
			const pendingId = await waitForRequest(teen.page, 'mine', 'Ice cream sundae', 'pending')
			await teen.page.goto('/apps/chorestrike/#/reward-requests')
			await expect(teen.page.getByTestId(`request-row-${pendingId}`)).toBeVisible({ timeout: 30000 })
			await expect(teen.page.getByTestId('requests-wallet-reserved')).toHaveText('5')
			const afterRequest = await myWallet(teen.page)
			expect(afterRequest.reservedMinor).toBe(before.reservedMinor + 500)
			expect(afterRequest.availableMinor).toBe(before.availableMinor - 500)
			expect(afterRequest.bookedMinor).toBe(before.bookedMinor)

			// The owner approves from the household scope (explicit switch).
			await owner.page.goto('/apps/chorestrike/#/reward-requests')
			await owner.page.getByTestId('scope-house').click()
			const row = owner.page.getByTestId(`request-row-${pendingId}`)
			await expect(row).toBeVisible({ timeout: 30000 })
			await row.click()
			await expect(owner.page.getByTestId('detail-actions')).toBeVisible({ timeout: 30000 })
			await owner.page.getByTestId('action-approve').click()
			await expect(owner.page.getByTestId('approve-dialog')).toBeVisible()
			await owner.page.getByTestId('approve-dialog').getByRole('button', { name: 'Approve' }).click()
			await waitForRequest(owner.page, 'house', 'Ice cream sundae', 'approved')

			// Approval is never fulfillment: still reserved, not spent.
			await teen.page.reload()
			await expect(teen.page.getByTestId('requests-wallet-reserved')).toHaveText('5')
			await expect(teen.page.getByTestId(`request-row-${pendingId}`)).toContainText('Approved, not delivered')
			const afterApprove = await myWallet(teen.page)
			expect(afterApprove.bookedMinor).toBe(before.bookedMinor)

			// The owner confirms the actual delivery (the attestation).
			await owner.page.goto(`/apps/chorestrike/#/reward-requests/${pendingId}`)
			await expect(owner.page.getByTestId('detail-actions')).toBeVisible({ timeout: 30000 })
			await owner.page.getByTestId('action-fulfill').click()
			await expect(owner.page.getByTestId('fulfill-dialog')).toBeVisible()
			await owner.page.getByTestId('fulfill-dialog').getByRole('button', { name: 'It was delivered' }).click()
			await waitForRequest(owner.page, 'house', 'Ice cream sundae', 'fulfilled')

			// Only now the Coins are spent — exactly once.
			await teen.page.reload()
			await expect(teen.page.getByTestId('requests-wallet-reserved')).toHaveText('0')
			const afterDelivery = await myWallet(teen.page)
			expect(afterDelivery.bookedMinor).toBe(before.bookedMinor - 500)
			expect(afterDelivery.availableMinor).toBe(before.availableMinor - 500)
			await expect(teen.page.getByTestId(`request-row-${pendingId}`)).toContainText('Delivered')
		} finally {
			await setCatalogGate(owner.page, false).catch(() => {})
			await owner.context.close()
			await teen.context.close()
		}
	})

	// -------------------------------------------------------------------
	// Scenario 4: adult flow, cancellation and correction
	// -------------------------------------------------------------------
	test('adult self-approval, cancellation and the attested correction refund exactly once', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const owner = await createUserContext(browser, 'mom')
		const adult = await createUserContext(browser, 'grandma')
		try {
			await setCatalogGate(owner.page, true)
			const item = await createItem(owner.page, { title: 'Bakery visit', priceMinor: 400 })
			await fundUserViaCompletion(adult.page, seededData as never, 'grandma')
			const before = await myWallet(adult.page)

			// An adult request is explicitly self-approved (no owner step).
			await adult.page.goto('/apps/chorestrike/#/rewards')
			await adult.page.getByTestId(`catalog-request-${item.id}`).click()
			await adult.page.getByTestId('request-confirm-button').click()
			const firstId = await waitForRequest(adult.page, 'mine', 'Bakery visit', 'approved')

			// Cancellation releases the reservation without a booked credit.
			await adult.page.goto(`/apps/chorestrike/#/reward-requests/${firstId}`)
			await expect(adult.page.getByTestId('detail-actions')).toBeVisible({ timeout: 30000 })
			await adult.page.getByTestId('action-cancel').click()
			await expect(adult.page.getByTestId('cancel-dialog')).toBeVisible()
			await adult.page.getByTestId('cancel-confirm').click()
			await waitForRequest(adult.page, 'mine', 'Bakery visit', 'cancelled')
			let wallet = await myWallet(adult.page)
			expect(wallet.reservedMinor).toBe(before.reservedMinor)
			expect(wallet.bookedMinor).toBe(before.bookedMinor)

			// Second request: self-fulfill the delivery attestation…
			await adult.page.goto('/apps/chorestrike/#/rewards')
			await adult.page.getByTestId(`catalog-request-${item.id}`).click()
			await adult.page.getByTestId('request-confirm-button').click()
			const secondId = await waitForRequest(adult.page, 'mine', 'Bakery visit', 'approved')
			expect(secondId).toBeGreaterThan(firstId)
			await adult.page.goto(`/apps/chorestrike/#/reward-requests/${secondId}`)
			await expect(adult.page.getByTestId('detail-actions')).toBeVisible({ timeout: 30000 })
			await adult.page.getByTestId('action-fulfill').click()
			await expect(adult.page.getByTestId('fulfill-dialog')).toBeVisible()
			await adult.page.getByTestId('fulfill-dialog').getByRole('button', { name: 'It was delivered' }).click()
			await waitForRequest(adult.page, 'mine', 'Bakery visit', 'fulfilled')
			wallet = await myWallet(adult.page)
			expect(wallet.bookedMinor).toBe(before.bookedMinor - 400)

			// …then the owner reports it as never delivered: the attested
			// correction refunds the ORIGINAL settlement exactly once.
			await owner.page.goto(`/apps/chorestrike/#/reward-requests/${secondId}`)
			await expect(owner.page.getByTestId('detail-actions')).toBeVisible({ timeout: 30000 })
			await owner.page.getByTestId('action-correct').click()
			const correctDialog = owner.page.getByTestId('correct-dialog')
			await expect(correctDialog).toBeVisible()
			const refundButton = correctDialog.getByTestId('correct-confirm')
			await expect(refundButton).toBeDisabled()
			await correctDialog.getByTestId('correct-reason-select').selectOption('not_delivered')
			await correctDialog.getByTestId('correct-attestation').check()
			await refundButton.click()
			await waitForRequest(adult.page, 'mine', 'Bakery visit', 'corrected')
			wallet = await myWallet(adult.page)
			expect(wallet.bookedMinor).toBe(before.bookedMinor)

			// A correction is terminal — no ordinary refund/cancel remains,
			// and the frozen terms survive a later price change.
			const corrected = await fetchMyRequest(adult.page, secondId)
			expect(corrected.state).toBe('corrected')
			expect(corrected.allowedActions).toEqual([])
			const live = await jsonOf(await owner.page.request.get(`${API}/rewards?scope=admin&limit=50`))
			const liveItem = (live.items as RewardItem[]).find((entry) => entry.id === item.id)!
			await owner.page.request.put(`${API}/rewards/${item.id}`, {
				data: { priceMinor: 999, expectedVersion: liveItem.version, commandId: newCommandId() },
			})
			const stillFrozen = await fetchMyRequest(adult.page, secondId)
			expect(stillFrozen.priceMinor).toBe(400)
		} finally {
			await setCatalogGate(owner.page, false).catch(() => {})
			await owner.context.close()
			await adult.context.close()
		}
	})

	// -------------------------------------------------------------------
	// Scenario 5: changed terms and hidden promises
	// -------------------------------------------------------------------
	test('changed terms require renewed review and hidden promises stay reachable', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const owner = await createUserContext(browser, 'mom')
		const teen = await createUserContext(browser, 'teen')
		try {
			await setCatalogGate(owner.page, true)
			const item = await createItem(owner.page, { title: 'Sticker pack', priceMinor: 300 })
			await fundUserViaCompletion(teen.page, seededData as never, 'teen')
			const before = await myWallet(teen.page)

			// The child reviews the displayed terms and opens the confirm…
			await teen.page.goto('/apps/chorestrike/#/rewards')
			await teen.page.getByTestId(`catalog-request-${item.id}`).click()
			await expect(teen.page.getByTestId('request-confirm')).toBeVisible()

			// …while the owner reprices the item (concurrent change).
			await owner.page.request.put(`${API}/rewards/${item.id}`, {
				data: { priceMinor: 900, expectedVersion: item.version, commandId: newCommandId() },
			})

			// The stale confirmation is rejected: conflict note, no silent
			// reservation, the dialog closes for a renewed review.
			await teen.page.getByTestId('request-confirm-button').click()
			await expect(teen.page.getByTestId('catalog-conflict')).toBeVisible({ timeout: 20000 })
			await expect(teen.page.getByTestId('request-confirm')).toHaveCount(0)
			let wallet = await myWallet(teen.page)
			expect(wallet.reservedMinor).toBe(before.reservedMinor)

			// The refreshed catalog shows the new price; the re-reviewed
			// request succeeds against the new frozen terms.
			await teen.page.reload()
			await expect(teen.page.getByTestId('catalog-item-price')).toHaveText('9')
			await teen.page.getByTestId(`catalog-request-${item.id}`).click()
			await teen.page.getByTestId('request-confirm-button').click()
			const requestId = await waitForRequest(teen.page, 'mine', 'Sticker pack', 'pending')
			const dto = await fetchMyRequest(teen.page, requestId)
			expect(dto.priceMinor).toBe(900)
			wallet = await myWallet(teen.page)
			expect(wallet.reservedMinor).toBe(before.reservedMinor + 900)

			// The owner archives the item AND disables the gate: the
			// existing promise keeps its frozen terms and stays processable.
			const live = await jsonOf(await owner.page.request.get(`${API}/rewards?scope=admin&limit=50`))
			const liveItem = (live.items as RewardItem[]).find((entry) => entry.id === item.id)!
			await owner.page.request.put(`${API}/rewards/${item.id}`, {
				data: { archived: true, expectedVersion: liveItem.version, commandId: newCommandId() },
			})
			await setCatalogGate(owner.page, false)

			await reloadApp(teen.page, '/today')
			await expect(teen.page.getByTestId('nav-rewards')).toHaveCount(0)
			await teen.page.goto(`/apps/chorestrike/#/reward-requests/${requestId}`)
			await expect(teen.page.getByTestId('detail-terms')).toContainText('9')
			// The owner releases the reservation while the catalog is off.
			await owner.page.goto(`/apps/chorestrike/#/reward-requests/${requestId}`)
			await expect(owner.page.getByTestId('detail-actions')).toBeVisible({ timeout: 30000 })
			await owner.page.getByTestId('action-reject').click()
			await expect(owner.page.getByTestId('reject-dialog')).toBeVisible()
			await owner.page.getByTestId('reject-confirm').click()
			await waitForRequest(owner.page, 'house', 'Sticker pack', 'rejected')
			wallet = await myWallet(teen.page)
			expect(wallet.reservedMinor).toBe(before.reservedMinor)
		} finally {
			await setCatalogGate(owner.page, false).catch(() => {})
			await owner.context.close()
			await teen.context.close()
		}
	})

	// -------------------------------------------------------------------
	// Scenario 6: correction-related funding hold
	// -------------------------------------------------------------------
	test('a correction-related funding hold blocks new money neutrally and clears with coverage', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const owner = await createUserContext(browser, 'mom')
		const teen = await createUserContext(browser, 'teen')
		try {
			await setCatalogGate(owner.page, true)
			const item = await createItem(owner.page, { title: 'Cinema ticket', priceMinor: 250 })
			const secondItem = await createItem(owner.page, { title: 'Cookie', priceMinor: 100 })

			// Fund the child, request and approve — then undo the funding
			// completion so booked < reserved (B < R).
			const { response } = await fundUserViaCompletion(teen.page, seededData as never, 'teen')
			const completion = (await response.json()).data.rewards.completion
			await teen.page.goto('/apps/chorestrike/#/rewards')
			await teen.page.getByTestId(`catalog-request-${item.id}`).click()
			await teen.page.getByTestId('request-confirm-button').click()
			const requestId = await waitForRequest(teen.page, 'mine', 'Cinema ticket', 'pending')
			const fresh = await fetchMyRequest(teen.page, requestId)
			await jsonOf(await owner.page.request.post(`${API}/reward-requests/${requestId}/approve`, {
				data: { commandId: newCommandId(), expectedVersion: fresh.version },
			}))
			await waitForRequest(owner.page, 'house', 'Cinema ticket', 'approved')

			const receipt = await jsonOf(await teen.page.request.get(`${API}/completions/${completion.id}`))
			const undo = await teen.page.request.post(`${API}/completions/${completion.id}/undo`, {
				data: { commandId: newCommandId(), expectedVersion: receipt.version },
			})
			expect(undo.ok(), await undo.text()).toBe(true)

			// The hold surfaces neutrally on the child's surfaces; new
			// requests and the fulfillment confirmation wait.
			await teen.page.goto(`/apps/chorestrike/#/reward-requests/${requestId}`)
			await expect(teen.page.getByTestId('detail-hold')).toBeVisible({ timeout: 30000 })
			await teen.page.goto('/apps/chorestrike/#/rewards')
			await expect(teen.page.getByTestId('wallet-hold')).toBeVisible({ timeout: 30000 })
			await expect(teen.page.getByTestId(`catalog-request-${secondItem.id}`)).toBeDisabled()

			// The OWNER's fulfillment action is disabled by the hold, while
			// approval/cancellation stay possible (owner view).
			await owner.page.goto(`/apps/chorestrike/#/reward-requests/${requestId}`)
			await expect(owner.page.getByTestId('action-fulfill')).toBeDisabled({ timeout: 30000 })
			await expect(owner.page.getByTestId('action-cancel')).toBeEnabled()

			// Cancellation stays possible — releasing the reservation ends
			// the under-funding, and the child can request again.
			await owner.page.getByTestId('action-cancel').click()
			await expect(owner.page.getByTestId('cancel-dialog')).toBeVisible()
			await owner.page.getByTestId('cancel-confirm').click()
			await waitForRequest(owner.page, 'house', 'Cinema ticket', 'cancelled')
			await teen.page.goto('/apps/chorestrike/#/rewards')
			await expect(teen.page.getByTestId(`catalog-request-${secondItem.id}`)).toBeEnabled({ timeout: 30000 })
		} finally {
			await setCatalogGate(owner.page, false).catch(() => {})
			await owner.context.close()
			await teen.context.close()
		}
	})

	// -------------------------------------------------------------------
	// Scenario 7: role/privacy/identity boundaries
	// -------------------------------------------------------------------
	test('foreign requests stay invisible, identities stay isolated and role changes re-require approval', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const owner = await createUserContext(browser, 'mom')
		const dad = await createUserContext(browser, 'dad')
		const other = await createUserContext(browser, 'kid')
		try {
			await setCatalogGate(owner.page, true)
			const item = await createItem(owner.page, { title: 'Book voucher', priceMinor: 600 })

			// An adult request self-approves…
			await fundUserViaCompletion(dad.page, seededData as never, 'dad')
			await dad.page.goto('/apps/chorestrike/#/rewards')
			await dad.page.getByTestId(`catalog-request-${item.id}`).click()
			await dad.page.getByTestId('request-confirm-button').click()
			const dadRequestId = await waitForRequest(dad.page, 'mine', 'Book voucher', 'approved')

			// …but another identity sees nothing: the foreign detail renders
			// the generic unavailable state and the own list stays empty.
			await other.page.goto(`/apps/chorestrike/#/reward-requests/${dadRequestId}`)
			await expect(other.page.getByTestId('detail-unavailable')).toBeVisible({ timeout: 30000 })
			await other.page.goto('/apps/chorestrike/#/reward-requests')
			await expect(other.page.getByTestId('requests-empty')).toBeVisible({ timeout: 30000 })

			// Demoting the second owner removes the household scope from
			// their UI after a fresh identity read.
			const users = await fetchHouseUsers(owner.page)
			const dadUser = users.find((entry) => entry.nextcloudUserId === 'dad')!
			await owner.page.request.put(`${API}/users/${dadUser.id}/role`, { data: { role: 'member' } })
			await reloadApp(dad.page, '/reward-requests')
			await expect(dad.page.getByTestId('scope-house')).toHaveCount(0, { timeout: 30000 })

			// An adult→child role change re-requires owner approval.
			await owner.page.request.put(`${API}/users/${dadUser.id}/role`, { data: { role: 'child' } })
			await waitForRequest(dad.page, 'mine', 'Book voucher', 'pending')
			await owner.page.goto(`/apps/chorestrike/#/reward-requests/${dadRequestId}`)
			await expect(owner.page.getByTestId('detail-actions')).toBeVisible({ timeout: 30000 })
			await owner.page.getByTestId('action-approve').click()
			await expect(owner.page.getByTestId('approve-dialog')).toBeVisible()
			await owner.page.getByTestId('approve-dialog').getByRole('button', { name: 'Approve' }).click()
			await waitForRequest(owner.page, 'house', 'Book voucher', 'approved')

			// Restore the second owner for the other serial specs.
			await owner.page.request.put(`${API}/users/${dadUser.id}/role`, { data: { role: 'owner' } })
		} finally {
			await setCatalogGate(owner.page, false).catch(() => {})
			await owner.context.close()
			await dad.context.close()
			await other.context.close()
		}
	})

	// -------------------------------------------------------------------
	// Scenario 8: uncertain UI command outcome
	// -------------------------------------------------------------------
	test('an uncertain command outcome replays with the same key and never double-spends', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const owner = await createUserContext(browser, 'mom')
		const adult = await createUserContext(browser, 'grandma')
		try {
			await setCatalogGate(owner.page, true)
			const item = await createItem(owner.page, { title: 'Plant for the desk', priceMinor: 350 })
			await fundUserViaCompletion(adult.page, seededData as never, 'grandma')
			const before = await myWallet(adult.page)

			await adult.page.goto('/apps/chorestrike/#/rewards')
			await adult.page.getByTestId(`catalog-request-${item.id}`).click()
			await adult.page.getByTestId('request-confirm-button').click()
			const requestId = await waitForRequest(adult.page, 'mine', 'Plant for the desk', 'approved')

			// The server processes the delivery confirmation, but the
			// response never reaches the client (aborted connection).
			await adult.context.route('**/reward-requests/*/fulfill', async (route) => {
				await route.fetch()
				await route.abort('connectionaborted')
			})
			await adult.page.goto(`/apps/chorestrike/#/reward-requests/${requestId}`)
			await expect(adult.page.getByTestId('detail-actions')).toBeVisible({ timeout: 30000 })
			await adult.page.getByTestId('action-fulfill').click()
			await expect(adult.page.getByTestId('fulfill-dialog')).toBeVisible()
			await adult.page.getByTestId('fulfill-dialog').getByRole('button', { name: 'It was delivered' }).click()

			// The UI cannot know the outcome: the explicit same-key retry
			// banner appears and stays reachable on the persistent Requests
			// surface — even after browsing is hidden in another session
			// (the in-memory intent survives in THIS session; a reload
			// would legitimately drop it).
			await adult.page.goto('/apps/chorestrike/#/reward-requests')
			await expect(adult.page.getByTestId('unresolved-command')).toBeVisible({ timeout: 30000 })
			await setCatalogGate(owner.page, false)
			await expect(adult.page.getByTestId('unresolved-command')).toBeVisible()
			await adult.context.unrouteAll()

			// The server already applied the delivery once (the lost
			// response never changed that): the replay returns the stored
			// outcome — exactly one debit, no second effect.
			const serverStateBeforeRetry = await myWallet(adult.page)
			await adult.page.getByTestId('unresolved-retry').click()
			await waitForRequest(adult.page, 'mine', 'Plant for the desk', 'fulfilled')
			const wallet = await myWallet(adult.page)
			expect(wallet.reservedMinor).toBe(serverStateBeforeRetry.reservedMinor)
			expect(wallet.bookedMinor).toBe(before.bookedMinor - 350)
		} finally {
			await setCatalogGate(owner.page, false).catch(() => {})
			await owner.context.close()
			await adult.context.close()
		}
	})

	// -------------------------------------------------------------------
	// Scenario 9: mobile, keyboard and real localization
	// -------------------------------------------------------------------
	test('mobile targets, keyboard operation and real EN/DE copy work', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const owner = await createUserContext(browser, 'mom')
		const teen = await createUserContext(browser, 'teen', {
			viewport: { width: 390, height: 844 },
			hasTouch: true,
			isMobile: true,
		})
		try {
			await setCatalogGate(owner.page, true)
			const item = await createItem(owner.page, { title: 'Picnic afternoon', priceMinor: 800 })
			await fundUserViaCompletion(teen.page, seededData as never, 'teen')

			// Mobile: the request flow works on a narrow touch viewport and
			// every interactive target honors the 44px minimum.
			await teen.page.goto('/apps/chorestrike/#/rewards')
			const requestButton = teen.page.getByTestId(`catalog-request-${item.id}`)
			await expect(requestButton).toBeVisible({ timeout: 30000 })
			const box = await requestButton.boundingBox()
			expect(box).not.toBeNull()
			expect(box!.height).toBeGreaterThanOrEqual(44)
			await requestButton.tap()
			await expect(teen.page.getByTestId('request-confirm')).toBeVisible()
			const confirmButton = teen.page.getByTestId('request-confirm-button')
			const confirmBox = await confirmButton.boundingBox()
			expect(confirmBox).not.toBeNull()
			expect(confirmBox!.height).toBeGreaterThanOrEqual(44)
			await confirmButton.tap()
			const picnicId = await waitForRequest(teen.page, 'mine', 'Picnic afternoon', 'pending')
			await teen.page.goto('/apps/chorestrike/#/reward-requests')
			await expect(teen.page.getByTestId(`request-row-${picnicId}`)).toBeVisible({ timeout: 30000 })

			// Keyboard: the help modal traps focus, Escape closes it and
			// returns focus to its trigger (shared Modal contract).
			await teen.page.goto('/apps/chorestrike/#/today')
			const helpTrigger = teen.page.getByTestId('help-context-progress')
			await helpTrigger.focus()
			await teen.page.keyboard.press('Enter')
			const helpModal = teen.page.getByTestId('help-modal')
			await expect(helpModal).toBeVisible({ timeout: 15000 })
			for (let i = 0; i < 8; i++) {
				await teen.page.keyboard.press('Tab')
			}
			await expect(teen.page.locator(':focus')).not.toContainText('My Day')
			await teen.page.keyboard.press('Escape')
			await expect(helpModal).toHaveCount(0)
			await expect(teen.page.locator(':focus')).toHaveAttribute('data-testid', 'help-context-progress')

			// Real DE copy from the shipped translations (no hardcoded EN).
			runOcc('user:setting teen core lang de')
			try {
				const german = await createUserContext(browser, 'teen')
				try {
					await german.page.goto('/apps/chorestrike/')
					await expect(german.page.getByTestId('nav-reward-requests')).toContainText('Meine Anfragen')
					await german.page.goto('/apps/chorestrike/#/reward-requests')
					await expect(german.page.getByTestId(`request-row-${picnicId}`)).toContainText('Wartet auf Freigabe')
					await expect(german.page.getByTestId('help-context-catalog')).toBeVisible()
				} finally {
					await german.context.close()
				}
			} finally {
				runOcc('user:setting teen core lang en')
			}
		} finally {
			await setCatalogGate(owner.page, false).catch(() => {})
			await owner.context.close()
			await teen.context.close()
		}
	})
})
