/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { createPinia, setActivePinia } from 'pinia'
import { useMinigameStore } from '@/store/minigame.js'

jest.mock('@/services/api.js', () => ({
	api: {
		get: jest.fn(),
		post: jest.fn(),
	},
}))

jest.mock('@/utils/logger', () => ({
	logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() },
}))

const { api } = require('@/services/api.js')

const CATALOG = {
	gateEnabled: true,
	finishedChores: 4,
	games: [
		{ gameId: 'snake', threshold: 0, unlocked: true },
		{ gameId: 'tiles', threshold: 25, unlocked: false },
	],
}

/** Answer reads per game id so cross-game leaks are visible. */
const answerReads = () => {
	api.get.mockImplementation(async (url) => {
		if (url === '/api/v1/minigames/catalog') return CATALOG
		const gameId = url.split('/')[4]
		const base = gameId === 'snake' ? 100 : 7
		if (url.endsWith('/personal-best')) return { score: base }
		if (url.endsWith('/rank')) return { rank: base === 100 ? 1 : 2 }
		if (url.endsWith('/recent')) return [{ id: base, score: base }]
		return [{ id: base, userId: 'mom', displayName: 'Mom', score: base }]
	})
}

describe('minigame store (CS-PLAN-086 per-game slices)', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useMinigameStore()
		jest.clearAllMocks()
		answerReads()
	})

	it('keeps every game in its own slice', async () => {
		await store.initialize()

		expect(store.slice('snake').personalBest).toEqual({ score: 100 })
		expect(store.slice('tiles').personalBest).toEqual({ score: 7 })
		expect(store.slice('snake').userRank).toBe(1)
		expect(store.slice('tiles').userRank).toBe(2)
		expect(store.loading).toBe(false)
	})

	it('a save in one game never changes another game', async () => {
		await store.initialize()
		const snakeBefore = JSON.stringify(store.slice('snake'))
		const readsBefore = api.get.mock.calls.length
		api.post.mockResolvedValue({ id: 9, score: 42, gameId: 'tiles' })

		await store.saveScore('tiles', 42, 30000)

		expect(api.post).toHaveBeenCalledWith('/api/v1/minigames/tiles/scores', { score: 42, durationMs: 30000 })
		expect(JSON.stringify(store.slice('snake'))).toBe(snakeBefore)
		for (const [url] of api.get.mock.calls.slice(readsBefore)) {
			expect(url).toContain('/minigames/tiles/')
		}
	})

	describe('catalog (CS-PLAN-086 §3.2)', () => {
		it('loads the catalog before the stats of every listed game', async () => {
			await store.initialize()

			expect(api.get.mock.calls[0][0]).toBe('/api/v1/minigames/catalog')
			expect(store.catalog).toEqual(CATALOG)
			const statReads = api.get.mock.calls.slice(1).map(([url]) => url).sort()
			expect(statReads).toEqual([
				'/api/v1/minigames/snake/personal-best',
				'/api/v1/minigames/snake/rank',
				'/api/v1/minigames/tiles/personal-best',
				'/api/v1/minigames/tiles/rank',
			])
		})

		it('soft-fails the catalog: the error is recorded and no stats are read', async () => {
			api.get.mockRejectedValueOnce(new Error('offline'))

			await store.initialize()

			expect(store.catalog).toBeNull()
			expect(store.error).toBe('offline')
			expect(api.get).toHaveBeenCalledTimes(1)
			expect(store.loading).toBe(false)
		})

		it('a locked rejection refreshes the catalog so the card locks again', async () => {
			store.catalog = { ...CATALOG, games: [{ gameId: 'snake', threshold: 10, unlocked: true }] }
			api.post.mockRejectedValue(Object.assign(new Error('This game is still locked.'), { status: 403, code: 'MINIGAME_LOCKED' }))

			const result = await store.finishGame('snake', 90, 12000)

			expect(result).toEqual({ saved: false, saveFailed: true, failure: 'locked', newBest: false })
			expect(api.get).toHaveBeenCalledWith('/api/v1/minigames/catalog')
			expect(store.catalog).toEqual(CATALOG)
		})

		it('other save failures leave the catalog alone; the daily cap is named', async () => {
			api.post.mockRejectedValue(Object.assign(new Error('Too many'), { status: 429, code: 'MINIGAME_RATE_LIMITED' }))

			expect(await store.saveScore('snake', 90, 12000)).toEqual({ data: null, failure: 'limit' })

			expect(api.get).not.toHaveBeenCalled()
		})
	})

	it('answers an empty, read-only slice for a game without data', () => {
		const empty = store.slice('dust')
		expect(empty).toEqual({ leaderboard: [], weeklyLeaderboard: [], personalBest: null, userRank: 0, recentScores: [] })
		expect(Object.isFrozen(empty)).toBe(true)
		expect(store.byGame).toEqual({})
	})

	it('fills boards and recent rounds with the requested limits', async () => {
		await Promise.all([
			store.fetchLeaderboard('snake', 20),
			store.fetchWeeklyLeaderboard('snake'),
			store.fetchRecentScores('snake', 3),
		])

		expect(api.get).toHaveBeenCalledWith('/api/v1/minigames/snake/leaderboard', { params: { limit: 20 } })
		expect(api.get).toHaveBeenCalledWith('/api/v1/minigames/snake/leaderboard/weekly', { params: { limit: 10 } })
		expect(api.get).toHaveBeenCalledWith('/api/v1/minigames/snake/recent', { params: { limit: 3 } })
		expect(store.slice('snake').leaderboard).toHaveLength(1)
		expect(store.slice('snake').weeklyLeaderboard).toHaveLength(1)
		expect(store.slice('snake').recentScores).toEqual([{ id: 100, score: 100 }])
	})

	it('soft-fails a read: the error is recorded and the previous value kept', async () => {
		await store.fetchPersonalBest('snake')
		api.get.mockRejectedValueOnce(new Error('offline'))

		await store.fetchPersonalBest('snake')

		expect(store.error).toBe('offline')
		expect(store.slice('snake').personalBest).toEqual({ score: 100 })
	})

	describe('finishGame', () => {
		it('does not save a zero score', async () => {
			const result = await store.finishGame('snake', 0, 5000)
			expect(result).toEqual({ saved: false, saveFailed: false, failure: null, newBest: false })
			expect(api.post).not.toHaveBeenCalled()
		})

		it('saves with the play time and reports a new personal best', async () => {
			store.ensureSlice('snake').personalBest = { score: 80 }
			api.post.mockResolvedValue({ id: 1, score: 90 })

			const result = await store.finishGame('snake', 90, 12000)

			expect(api.post).toHaveBeenCalledWith('/api/v1/minigames/snake/scores', { score: 90, durationMs: 12000 })
			expect(result).toEqual({ saved: true, saveFailed: false, failure: null, newBest: true })
		})

		it('reports no new best below the previous one', async () => {
			store.ensureSlice('snake').personalBest = { score: 500 }
			api.post.mockResolvedValue({ id: 1, score: 90 })
			expect((await store.finishGame('snake', 90, 12000)).newBest).toBe(false)
		})

		it('reports a failed save without claiming a new best', async () => {
			api.post.mockRejectedValue(new Error('Network down'))

			const result = await store.finishGame('snake', 90, 12000)

			expect(result).toEqual({ saved: false, saveFailed: true, failure: 'error', newBest: false })
			expect(store.error).toBe('Network down')
		})
	})

	it('reset drops every game slice and the catalog', async () => {
		await store.initialize()
		store.error = 'x'
		store.reset()
		expect(store.byGame).toEqual({})
		expect(store.catalog).toBeNull()
		expect(store.error).toBeNull()
		expect(store.loading).toBe(false)
	})

	it('clearError clears the error', () => {
		store.error = 'x'
		store.clearError()
		expect(store.error).toBeNull()
	})
})
