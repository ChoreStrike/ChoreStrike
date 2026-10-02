/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { createPinia, setActivePinia } from 'pinia'

jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

jest.mock('../../../src/services/api.js', () => ({
	fetchCatalog: jest.fn(),
	createCatalogItem: jest.fn(),
	updateCatalogItem: jest.fn(),
	fetchRewardRequests: jest.fn(),
	fetchRewardRequest: jest.fn(),
	createRewardRequest: jest.fn(),
	approveRewardRequest: jest.fn(),
	rejectRewardRequest: jest.fn(),
	cancelRewardRequest: jest.fn(),
	fulfillRewardRequest: jest.fn(),
	correctRewardRequest: jest.fn(),
}))

import {
	fetchCatalog,
	createCatalogItem,
	updateCatalogItem,
	fetchRewardRequests,
	fetchRewardRequest,
	createRewardRequest,
	approveRewardRequest,
	fulfillRewardRequest,
	correctRewardRequest,
} from '../../../src/services/api.js'
import { useRewardsStore, REWARDS_PAGE_LIMIT, REASON_CODES } from '../../../src/store/rewards.js'

const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

const item = (id, overrides = {}) => ({ id, title: `Item ${id}`, description: null, priceMinor: 500, archived: false, version: 0, ...overrides })
const request = (id, overrides = {}) => ({
	id,
	itemId: 1,
	requester: { houseUserId: 7, displayName: 'Teen', isFormerMember: false },
	title: `Wish ${id}`,
	description: null,
	priceMinor: 500,
	state: 'pending',
	version: 0,
	createdAt: '2026-10-01T10:00:00+00:00',
	updatedAt: '2026-10-01T10:00:00+00:00',
	allowedActions: ['cancel'],
	fundingHold: false,
	...overrides,
})

const flush = () => new Promise(resolve => setTimeout(resolve, 0))

/**
 * Cross-layer contract for the reward client (CS-PLAN-070 WP8 §3.2 on
 * docs/api/rewards.md): separate paged contexts per scope/filter with
 * both cursor directions, stable command identities across uncertain
 * outcomes, conflict-safe drafts and complete identity-reset isolation.
 */
describe('rewards store', () => {
	let pinia

	const mocks = [
		fetchCatalog, createCatalogItem, updateCatalogItem, fetchRewardRequests,
		fetchRewardRequest, createRewardRequest, approveRewardRequest,
		fulfillRewardRequest, correctRewardRequest,
	]

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		for (const mock of mocks) {
			mock.mockReset()
		}
	})

	describe('catalog pages', () => {
		it('loads the first active page without a cursor and reports the gate flag', async () => {
			fetchCatalog.mockResolvedValue({ catalogEnabled: true, items: [item(1), item(2)] })
			const store = useRewardsStore()

			await store.fetchCatalogPage('active')

			expect(fetchCatalog).toHaveBeenCalledWith({ scope: 'active', limit: REWARDS_PAGE_LIMIT })
			expect(store.catalog.active.items).toHaveLength(2)
			expect(store.catalogEnabled).toBe(true)
			expect(store.catalog.active.cursor).toBe(2)
		})

		it('advances the catalog cursor ascending on the last returned id', async () => {
			fetchCatalog
				.mockResolvedValueOnce({ catalogEnabled: true, items: Array.from({ length: REWARDS_PAGE_LIMIT }, (_, i) => item(i + 1)) })
				.mockResolvedValueOnce({ catalogEnabled: true, items: [item(51)] })
			const store = useRewardsStore()

			await store.fetchCatalogPage('active')
			await store.fetchCatalogPage('active')

			expect(fetchCatalog).toHaveBeenLastCalledWith({ scope: 'active', limit: REWARDS_PAGE_LIMIT, cursor: 50 })
			expect(store.catalog.active.items).toHaveLength(REWARDS_PAGE_LIMIT + 1)
		})

		it('deduplicates overlapping pages and marks the end when a page comes back short', async () => {
			const fullPage = Array.from({ length: REWARDS_PAGE_LIMIT }, (_, i) => item(i + 1))
			// Overlapping full page: id 50 was already loaded.
			const overlappingPage = Array.from({ length: REWARDS_PAGE_LIMIT }, (_, i) => item(50 + i))
			fetchCatalog
				.mockResolvedValueOnce({ catalogEnabled: true, items: fullPage })
				.mockResolvedValueOnce({ catalogEnabled: true, items: overlappingPage })
				.mockResolvedValueOnce({ catalogEnabled: true, items: [] })
			const store = useRewardsStore()

			await store.fetchCatalogPage('active')
			await store.fetchCatalogPage('active')
			const ids = store.catalog.active.items.map(i => i.id)
			expect(ids).toHaveLength(99)
			expect(new Set(ids).size).toBe(ids.length)
			expect(ids[98]).toBe(99)

			// Full pages are not exhaustion; an empty page is.
			expect(store.catalog.active.exhausted).toBe(false)
			await store.fetchCatalogPage('active')
			expect(store.catalog.active.exhausted).toBe(true)

			// An exhausted page never issues another request.
			await store.fetchCatalogPage('active')
			expect(fetchCatalog).toHaveBeenCalledTimes(3)
		})

		it('keeps the active and admin scopes separate (admin works while hidden)', async () => {
			fetchCatalog
				.mockResolvedValueOnce({ catalogEnabled: false, items: [] })
				.mockResolvedValueOnce({ catalogEnabled: false, items: [item(9, { archived: true })] })
			const store = useRewardsStore()

			await store.fetchCatalogPage('active')
			await store.fetchCatalogPage('admin')

			expect(store.catalog.active.items).toHaveLength(0)
			expect(store.catalog.admin.items).toHaveLength(1)
			expect(fetchCatalog).toHaveBeenNthCalledWith(1, { scope: 'active', limit: REWARDS_PAGE_LIMIT })
			expect(fetchCatalog).toHaveBeenNthCalledWith(2, { scope: 'admin', limit: REWARDS_PAGE_LIMIT })
		})
	})

	describe('request pages', () => {
		it('loads mine and house scopes separately with the descending cursor', async () => {
			fetchRewardRequests
				.mockResolvedValueOnce({ requests: [request(10), request(9)] })
				.mockResolvedValueOnce({ requests: [request(30)] })
			const store = useRewardsStore()

			await store.fetchRequestPage('mine')
			await store.fetchRequestPage('house')

			expect(store.requests.mine.items.map(r => r.id)).toEqual([10, 9])
			expect(store.requests.house.items.map(r => r.id)).toEqual([30])
			// The house scope was explicitly requested — never prefetched.
			expect(fetchRewardRequests).toHaveBeenCalledTimes(2)
		})

		it('passes the state filter and restarts the page when it changes', async () => {
			fetchRewardRequests
				.mockResolvedValueOnce({ requests: [request(5)] })
				.mockResolvedValueOnce({ requests: [request(8, { state: 'pending' })] })
			const store = useRewardsStore()

			await store.fetchRequestPage('mine', { state: null })
			await store.fetchRequestPage('mine', { state: 'pending' })

			expect(fetchRewardRequests).toHaveBeenNthCalledWith(2, { scope: 'mine', limit: REWARDS_PAGE_LIMIT, state: 'pending' })
			expect(store.requests.mine.items.map(r => r.id)).toEqual([8])
		})
	})

	describe('request detail', () => {
		it('loads the authorized detail with the audit trail', async () => {
			fetchRewardRequest.mockResolvedValue(request(5, { audit: [{ revision: 1, action: 'create' }] }))
			const store = useRewardsStore()

			await store.fetchRequestDetail(5)

			expect(fetchRewardRequest).toHaveBeenCalledWith(5)
			expect(store.detail.request.id).toBe(5)
			expect(store.detail.audit).toHaveLength(1)
		})

		it('renders foreign/invisible ids as a generic unavailable state', async () => {
			const error = new Error('Not found')
			error.status = 404
			fetchRewardRequest.mockRejectedValue(error)
			const store = useRewardsStore()

			await expect(store.fetchRequestDetail(99)).rejects.toThrow('Not found')
			expect(store.detail.unavailable).toBe(true)
			expect(store.detail.request).toBeNull()
			expect(store.detail.audit).toEqual([])
		})
	})

	describe('commands and identity', () => {
		it('creates a request with the displayed frozen terms and one UUID', async () => {
			createRewardRequest.mockResolvedValue(request(5))
			const store = useRewardsStore()
			const displayed = item(2, { version: 3, priceMinor: 700 })

			await store.createRequest(displayed)

			const body = createRewardRequest.mock.calls[0][0]
			expect(body).toEqual(expect.objectContaining({ itemId: 2, itemVersion: 3, priceMinor: 700 }))
			expect(body.commandId).toMatch(UUID_PATTERN)
		})

		it('guards against duplicate submits while a command is in flight', async () => {
			createRewardRequest.mockImplementation(() => new Promise(resolve => setTimeout(() => resolve(request(5)), 20)))
			const store = useRewardsStore()

			const first = store.createRequest(item(2))
			await expect(store.createRequest(item(2))).rejects.toThrow('already in flight')
			await first
		})

		it('retains the SAME UUID/payload across an uncertain outcome and replays it', async () => {
			const networkError = new Error('Network dropped')
			networkError.status = undefined
			createRewardRequest
				.mockRejectedValueOnce(networkError)
				.mockResolvedValueOnce(request(5))
			const store = useRewardsStore()

			await expect(store.createRequest(item(2))).rejects.toThrow('Network dropped')
			expect(store.unresolvedCommand).not.toBeNull()
			const firstCommandId = createRewardRequest.mock.calls[0][0].commandId

			await store.retryUnresolved()
			expect(createRewardRequest).toHaveBeenCalledTimes(2)
			expect(createRewardRequest.mock.calls[1][0].commandId).toBe(firstCommandId)
			expect(store.unresolvedCommand).toBeNull()
		})

		it('retains the identity across a 5xx outcome as well', async () => {
			const serverError = new Error('Internal server error')
			serverError.status = 500
			approveRewardRequest.mockRejectedValue(serverError)
			const store = useRewardsStore()

			await expect(store.approveRequest(request(5))).rejects.toThrow('Internal server error')
			expect(store.unresolvedCommand).not.toBeNull()
		})

		it('discards the identity on a definitive 4xx and flags conflicts on 409', async () => {
			const conflict = new Error('Displayed terms drifted')
			conflict.status = 409
			approveRewardRequest.mockRejectedValue(conflict)
			const store = useRewardsStore()

			await expect(store.approveRequest(request(5))).rejects.toThrow('Displayed terms drifted')
			expect(store.unresolvedCommand).toBeNull()
			expect(store.conflict).toBe(true)
		})

		it('never reuses a command key for a changed intent (fresh UUID per attempt)', async () => {
			createRewardRequest
				.mockRejectedValueOnce(Object.assign(new Error('conflict'), { status: 409 }))
				.mockResolvedValueOnce(request(6))
			const store = useRewardsStore()

			await expect(store.createRequest(item(2))).rejects.toThrow('conflict')
			await store.createRequest(item(2, { version: 1 }))

			const [first, second] = createRewardRequest.mock.calls.map(call => call[0].commandId)
			expect(first).not.toBe(second)
		})

		it('fulfill carries the explicit delivered attestation', async () => {
			fulfillRewardRequest.mockResolvedValue(request(5, { state: 'fulfilled' }))
			const store = useRewardsStore()

			await store.fulfillRequest(request(5, { version: 2 }))

			expect(fulfillRewardRequest.mock.calls[0][0]).toBe(5)
			expect(fulfillRewardRequest.mock.calls[0][1]).toEqual(expect.objectContaining({
				expectedVersion: 2,
				delivered: true,
			}))
		})

		it('correct references the server settlement entry, never a client amount', async () => {
			correctRewardRequest.mockResolvedValue(request(5, { state: 'corrected' }))
			const store = useRewardsStore()

			await store.correctRequest(request(5, { version: 2, settlementEntryId: 77 }), 'not_delivered')

			expect(correctRewardRequest.mock.calls[0][1]).toEqual(expect.objectContaining({
				expectedVersion: 2,
				notDelivered: true,
				reasonCode: 'not_delivered',
				settlementEntryId: 77,
			}))
		})

		it('exposes the closed reason-code set', () => {
			expect(REASON_CODES).toEqual(['not_needed', 'changed_mind', 'unavailable', 'duplicate', 'not_delivered', 'other'])
		})
	})

	describe('refresh contract', () => {
		it('refreshes only touched contexts after a command (never prefetches house)', async () => {
			fetchRewardRequests.mockResolvedValue({ requests: [request(5)] })
			createRewardRequest.mockResolvedValue(request(6))
			const store = useRewardsStore()

			await store.fetchRequestPage('mine')
			fetchRewardRequests.mockClear()
			await store.createRequest(item(2))

			expect(fetchRewardRequests).toHaveBeenCalledTimes(1)
			expect(fetchRewardRequests).toHaveBeenCalledWith({ scope: 'mine', limit: REWARDS_PAGE_LIMIT })
		})

		it('preserves the active state filter when refreshing touched contexts after a command', async () => {
			fetchRewardRequests.mockResolvedValue({ requests: [request(5)] })
			createRewardRequest.mockResolvedValue(request(6))
			const store = useRewardsStore()

			await store.fetchRequestPage('mine', { state: 'pending' })
			fetchRewardRequests.mockClear()
			await store.createRequest(item(2))
			await flush()

			expect(fetchRewardRequests).toHaveBeenCalledWith({ scope: 'mine', limit: REWARDS_PAGE_LIMIT, state: 'pending' })
			expect(store.requests.mine.state).toBe('pending')
		})

		it('keeps a successful command successful when the refresh fails (visible refresh error)', async () => {
			fetchRewardRequests.mockRejectedValue(new Error('read failed'))
			createRewardRequest.mockResolvedValue(request(6))
			const store = useRewardsStore()

			await store.fetchRequestPage('mine').catch(() => {})
			const dto = await store.createRequest(item(2))
			await flush()

			expect(dto.id).toBe(6)
			expect(store.refreshError).toBe('read failed')
		})

		it('re-reads the touched detail after a lifecycle command', async () => {
			fetchRewardRequest.mockResolvedValue(request(5, { version: 1 }))
			approveRewardRequest.mockResolvedValue(request(5, { version: 2, state: 'approved' }))
			const store = useRewardsStore()

			await store.fetchRequestDetail(5)
			fetchRewardRequest.mockClear()
			await store.approveRequest(request(5))

			expect(fetchRewardRequest).toHaveBeenCalledWith(5)
		})
	})

	describe('generations and reset', () => {
		it('drops a delayed page that was superseded by a scope/filter switch', async () => {
			let resolveFirst = null
			fetchRewardRequests
				.mockImplementationOnce(() => new Promise(resolve => { resolveFirst = resolve }))
				.mockResolvedValue({ requests: [request(8)] })
			const store = useRewardsStore()

			const delayed = store.fetchRequestPage('mine', { state: null })
			store.fetchRequestPage('mine', { state: 'pending' })
			resolveFirst({ requests: [request(1)] })
			await delayed
			await flush()

			// The superseded first page must not repopulate the context.
			expect(store.requests.mine.items.map(r => r.id)).toEqual([8])
		})

		it('reset invalidates in-flight reads and clears wishes, drafts and commands', async () => {
			let resolveRead = null
			fetchCatalog.mockImplementation(() => new Promise(resolve => { resolveRead = resolve }))
			const store = useRewardsStore()

			const pending = store.fetchCatalogPage('active')
			store.reset()
			resolveRead({ catalogEnabled: true, items: [item(1)] })
			await pending

			expect(store.catalog.active.items).toEqual([])
			expect(store.catalogEnabled).toBeNull()

			store.unresolvedCommand = { commandId: 'x', label: 'y' }
			store.reset()
			expect(store.unresolvedCommand).toBeNull()
		})

		it('catalog item create/edit carry the exact whitelisted payloads', async () => {
			createCatalogItem.mockResolvedValue(item(3))
			updateCatalogItem.mockResolvedValue(item(3, { version: 1, archived: true }))
			const store = useRewardsStore()

			await store.createCatalogItem({ title: 'Zoo', description: null, priceMinor: 1250 })
			expect(createCatalogItem.mock.calls[0][0]).toEqual(expect.objectContaining({ title: 'Zoo', priceMinor: 1250 }))

			await store.updateCatalogItem(item(3), { archived: true })
			expect(updateCatalogItem.mock.calls[0][0]).toBe(3)
			expect(updateCatalogItem.mock.calls[0][1]).toEqual(expect.objectContaining({ archived: true, expectedVersion: 0 }))
		})
	})
})
