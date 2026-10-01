/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { createPinia, setActivePinia } from 'pinia'

// Deliberate error-path specs must not leak real logger output into the
// noise gate (CS-PLAN-034 convention).
jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

jest.mock('../../../src/services/api.js', () => ({
	fetchPauses: jest.fn(),
	createPause: jest.fn(),
	recognizeIllness: jest.fn(),
	approvePause: jest.fn(),
	rejectPause: jest.fn(),
	updatePause: jest.fn(),
}))

import {
	fetchPauses,
	createPause,
	recognizeIllness,
	approvePause,
	rejectPause,
	updatePause,
} from '../../../src/services/api.js'
import { usePausesStore } from '../../../src/store/pauses.js'

/**
 * Cross-layer contract for the pause store (CS-PLAN-070 WP6 §3.4 on the
 * delivered CS-PLAN-073 WP2 API): viewer-whitelisted list reads, one
 * fresh command UUID per command attempt, the displayed expectedRevision
 * on decisions/shortening, 409 draft preservation and the identity reset.
 */
describe('pauses store', () => {
	let pinia

	const pauseEntry = (overrides = {}) => ({
		pauseGroupId: 1,
		revision: 1,
		scope: 'personal',
		subjectWalletId: 11,
		subjectUserId: 'teen',
		state: 'requested',
		startDate: '2026-10-01',
		endDate: '2026-10-03',
		timezone: 'Europe/Berlin',
		createdAt: '2026-09-30T10:00:00+00:00',
		updatedAt: '2026-09-30T10:00:00+00:00',
		reasonCode: 'illness',
		isRetrospectiveIllness: false,
		...overrides,
	})

	const apiError = (status) => {
		const error = new Error(`HTTP ${status}`)
		error.status = status
		return error
	}

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		fetchPauses.mockReset()
		createPause.mockReset()
		recognizeIllness.mockReset()
		approvePause.mockReset()
		rejectPause.mockReset()
		updatePause.mockReset()
		usePausesStore().reset()
	})

	it('starts empty and neutral', () => {
		const store = usePausesStore()

		expect(store.pauses).toEqual([])
		expect(store.loading).toBe(false)
		expect(store.saving).toBe(false)
		expect(store.error).toBeNull()
		expect(store.conflict).toBe(false)
	})

	it('fetches the viewer-whitelisted pause list', async () => {
		fetchPauses.mockResolvedValue([pauseEntry()])
		const store = usePausesStore()

		await store.fetchPauses()

		expect(store.pauses).toHaveLength(1)
		expect(store.pendingRequests).toHaveLength(1)
		expect(store.error).toBeNull()
	})

	it('sends a personal request with scope personal and a fresh command UUID', async () => {
		createPause.mockResolvedValue({ pauseGroupId: 5, revision: 1, state: 'requested' })
		fetchPauses.mockResolvedValue([])
		const store = usePausesStore()

		await store.requestPersonalPause({ startDate: '2026-10-01', endDate: '2026-10-02', reasonCode: 'illness' })

		expect(createPause).toHaveBeenCalledTimes(1)
		const body = createPause.mock.calls[0][0]
		expect(body.scope).toBe('personal')
		expect(body.startDate).toBe('2026-10-01')
		expect(body.reasonCode).toBe('illness')
		expect(body.commandId).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i)
	})

	it('generates a fresh command UUID per attempt', async () => {
		createPause
			.mockRejectedValueOnce(apiError(409))
			.mockResolvedValueOnce({ pauseGroupId: 5, revision: 1, state: 'acknowledged' })
		fetchPauses.mockResolvedValue([])
		const store = usePausesStore()

		await expect(store.requestPersonalPause({ startDate: '2026-10-01', endDate: '2026-10-02', reasonCode: 'other' })).rejects.toBeDefined()
		await store.requestPersonalPause({ startDate: '2026-10-01', endDate: '2026-10-02', reasonCode: 'other' })

		const first = createPause.mock.calls[0][0].commandId
		const second = createPause.mock.calls[1][0].commandId
		expect(first).not.toBe(second)
	})

	it('sends the household pause with scope house', async () => {
		createPause.mockResolvedValue({ pauseGroupId: 7, revision: 1, state: 'acknowledged' })
		fetchPauses.mockResolvedValue([])
		const store = usePausesStore()

		await store.createHousePause({ startDate: '2026-10-01', endDate: '2026-10-05', reasonCode: 'travel' })

		const body = createPause.mock.calls[0][0]
		expect(body.scope).toBe('house')
		expect(body.reasonCode).toBe('travel')
	})

	it('sends the retrospective illness recognition with the subject identity', async () => {
		recognizeIllness.mockResolvedValue({ pauseGroupId: 9, revision: 1, state: 'acknowledged' })
		fetchPauses.mockResolvedValue([])
		const store = usePausesStore()

		await store.recognizeIllness({ subjectUserId: 'teen', startDate: '2026-09-28', endDate: '2026-09-29' })

		const body = recognizeIllness.mock.calls[0][0]
		expect(body.subjectUserId).toBe('teen')
		expect(body.startDate).toBe('2026-09-28')
		expect(body.commandId).toMatch(/^[0-9a-f-]{36}$/i)
	})

	it('sends approve/reject with the displayed expectedRevision', async () => {
		approvePause.mockResolvedValue({ pauseGroupId: 1, revision: 2, state: 'acknowledged' })
		rejectPause.mockResolvedValue({ pauseGroupId: 2, revision: 2, state: 'rejected' })
		fetchPauses.mockResolvedValue([])
		const store = usePausesStore()

		await store.approvePause(1, 1)
		await store.rejectPause(2, 1)

		expect(approvePause).toHaveBeenCalledWith(1, expect.objectContaining({ expectedRevision: 1 }))
		expect(rejectPause).toHaveBeenCalledWith(2, expect.objectContaining({ expectedRevision: 1 }))
	})

	it('sends the shortening with endDate and expectedRevision', async () => {
		updatePause.mockResolvedValue({ pauseGroupId: 1, revision: 2, state: 'acknowledged' })
		fetchPauses.mockResolvedValue([])
		const store = usePausesStore()

		await store.shortenPause(1, { endDate: '2026-10-02', expectedRevision: 1 })

		expect(updatePause).toHaveBeenCalledWith(1, expect.objectContaining({
			endDate: '2026-10-02',
			expectedRevision: 1,
		}))
	})

	it('flags the conflict on 409 and keeps the caller draft surfaceable', async () => {
		createPause.mockRejectedValue(apiError(409))
		fetchPauses.mockResolvedValue([pauseEntry({ state: 'acknowledged' })])
		const store = usePausesStore()

		await expect(store.requestPersonalPause({ startDate: '2026-10-01', endDate: '2026-10-02', reasonCode: 'other' }))
			.rejects.toMatchObject({ status: 409 })

		expect(store.conflict).toBe(true)
		// The authoritative list was refreshed for reconciliation.
		await new Promise(resolve => setTimeout(resolve, 0))
		expect(store.pauses).toHaveLength(1)
	})

	it('surfaces non-conflict errors without flagging a conflict', async () => {
		createPause.mockRejectedValue(apiError(403))
		fetchPauses.mockResolvedValue([])
		const store = usePausesStore()

		await expect(store.requestPersonalPause({ startDate: '2026-10-01', endDate: '2026-10-02', reasonCode: 'other' }))
			.rejects.toMatchObject({ status: 403 })

		expect(store.conflict).toBe(false)
		expect(store.error).toContain('403')
	})

	it('classifies pending, active and decided pauses', () => {
		fetchPauses.mockResolvedValue([
			pauseEntry({ pauseGroupId: 1, state: 'requested' }),
			pauseEntry({ pauseGroupId: 2, state: 'acknowledged' }),
			pauseEntry({ pauseGroupId: 3, state: 'rejected' }),
		])
		const store = usePausesStore()

		return store.fetchPauses().then(() => {
			expect(store.pendingRequests.map(p => p.pauseGroupId)).toEqual([1])
			expect(store.activePauses.map(p => p.pauseGroupId)).toEqual([2])
			expect(store.decidedPauses.map(p => p.pauseGroupId)).toEqual([3])
		})
	})

	it('reset clears the state and invalidates in-flight reads', async () => {
		let resolveFetch
		fetchPauses.mockImplementation(() => new Promise(resolve => { resolveFetch = resolve }))
		const store = usePausesStore()
		const pending = store.fetchPauses()

		store.reset()
		resolveFetch([pauseEntry()])
		await pending

		expect(store.pauses).toEqual([])
	})
})
