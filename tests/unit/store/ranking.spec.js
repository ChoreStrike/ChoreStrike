import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'
import { useRankingStore } from '../../../src/store/ranking.js'

jest.mock('../../../src/services/api.js', () => ({
	api: {
		get: jest.fn(),
		post: jest.fn(),
	},
}))

jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

import { api } from '../../../src/services/api.js'

const serverView = (overrides = {}) => ({
	rankingEnabled: true,
	week: { weekKey: '2026-09-21', weekStart: '', weekEnd: '', provisional: true },
	me: { state: 'none', generation: null, effectiveMonday: null },
	entries: [
		{ houseUserId: 2, displayName: 'Alice', eligible: 4, onTime: 3, neutral: false, rank: 1, tied: false, isMe: false },
	],
	pending: [],
	...overrides,
})

describe('Ranking Store (CS-PLAN-070 WP4 consent-filtered ranking)', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useRankingStore()
		jest.clearAllMocks()
	})

	describe('State', () => {
		it('has correct initial state', () => {
			expect(store.rankingEnabled).toBe(false)
			expect(store.week).toBeNull()
			expect(store.me).toEqual({ state: 'none', generation: null, effectiveMonday: null })
			expect(store.entries).toEqual([])
			expect(store.pending).toEqual([])
			expect(store.loading).toBe(false)
			expect(store.error).toBeNull()
		})
	})

	describe('fetchRanking', () => {
		it('applies the server ranking view', async () => {
			const view = serverView()
			api.get.mockResolvedValue(view)

			await store.fetchRanking()

			expect(api.get).toHaveBeenCalledWith('/api/v1/ranking')
			expect(store.rankingEnabled).toBe(true)
			expect(store.week).toEqual(view.week)
			expect(store.me).toEqual(view.me)
			expect(store.entries).toEqual(view.entries)
			expect(store.pending).toEqual(view.pending)
			expect(store.loading).toBe(false)
			expect(store.error).toBeNull()
		})

		it('defaults missing entries/pending to empty arrays', async () => {
			api.get.mockResolvedValue({ rankingEnabled: false, week: null, me: { state: 'none' } })

			await store.fetchRanking()

			expect(store.entries).toEqual([])
			expect(store.pending).toEqual([])
		})

		it('stores the error and rethrows on failure', async () => {
			api.get.mockRejectedValue(new Error('Not found'))

			await expect(store.fetchRanking()).rejects.toThrow('Not found')
			expect(store.error).toBe('Not found')
			expect(store.loading).toBe(false)
		})
	})

	describe('giveConsent', () => {
		it('refreshes the own state from the consent response and re-reads the view', async () => {
			api.post.mockResolvedValue({ state: 'pending', generation: 4, effectiveMonday: '2026-09-28' })
			api.get.mockResolvedValue(serverView({ me: { state: 'pending', generation: 4, effectiveMonday: '2026-09-28' } }))

			await store.giveConsent()

			expect(api.post).toHaveBeenCalledWith('/api/v1/ranking/consent')
			expect(api.get).toHaveBeenCalledWith('/api/v1/ranking')
			expect(store.me.state).toBe('pending')
			expect(store.me.generation).toBe(4)
			expect(store.error).toBeNull()
		})

		it('stores the error and rethrows on failure', async () => {
			api.post.mockRejectedValue(new Error('Forbidden'))

			await expect(store.giveConsent()).rejects.toThrow('Forbidden')
			expect(store.error).toBe('Forbidden')
			expect(store.loading).toBe(false)
		})
	})

	describe('withdrawConsent', () => {
		it('applies the immediate none state and re-reads the view', async () => {
			store.me = { state: 'effective', generation: 4, effectiveMonday: '2026-09-28' }
			api.post.mockResolvedValue({ state: 'none', generation: null, effectiveMonday: null })
			api.get.mockResolvedValue(serverView({ entries: [] }))

			await store.withdrawConsent()

			expect(api.post).toHaveBeenCalledWith('/api/v1/ranking/withdraw')
			expect(api.get).toHaveBeenCalledWith('/api/v1/ranking')
			expect(store.me.state).toBe('none')
		})

		it('stores the error and rethrows on failure', async () => {
			api.post.mockRejectedValue(new Error('Server error'))

			await expect(store.withdrawConsent()).rejects.toThrow('Server error')
			expect(store.error).toBe('Server error')
		})
	})

	describe('clearError / reset', () => {
		it('clears the error state', () => {
			store.error = 'boom'
			store.clearError()
			expect(store.error).toBeNull()
		})

		it('reset restores the initial state (identity/house switch)', () => {
			store.rankingEnabled = true
			store.week = { weekKey: 'x' }
			store.me = { state: 'effective', generation: 2, effectiveMonday: '2026-09-21' }
			store.entries = [{ houseUserId: 1 }]
			store.pending = [{ houseUserId: 7 }]
			store.loading = true
			store.error = 'boom'

			store.reset()

			expect(store.rankingEnabled).toBe(false)
			expect(store.week).toBeNull()
			expect(store.me).toEqual({ state: 'none', generation: null, effectiveMonday: null })
			expect(store.entries).toEqual([])
			expect(store.pending).toEqual([])
			expect(store.loading).toBe(false)
			expect(store.error).toBeNull()
		})
	})
})
