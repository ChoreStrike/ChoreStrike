import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'
import { useMinigameStore } from '../../../src/store/minigame.js'

// Authoritative catalog shape as returned by GET /minigames/boosts.
const MOCK_CATALOG = {
	slow_motion: 10,
	ghost_mode: 20,
	magnet: 30,
	shrink: 15,
	no_obstacles: 25,
}

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

describe('Minigame Store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useMinigameStore()
		jest.clearAllMocks()
	})

	describe('State', () => {
		it('has correct initial state', () => {
			expect(store.currentGame).toBeNull()
			expect(store.score).toBe(0)
			expect(store.isPlaying).toBe(false)
			expect(store.isPaused).toBe(false)
			expect(store.gameOver).toBe(false)
			expect(store.activeBoosts).toEqual([])
			expect(store.purchasedBoosts).toEqual([])
			expect(store.boostCatalog).toBeNull()
			expect(store.currentCoins).toBe(0)
			expect(store.leaderboard).toEqual([])
			expect(store.weeklyLeaderboard).toEqual([])
			expect(store.personalBest).toBeNull()
			expect(store.userRank).toBe(0)
			expect(store.recentScores).toEqual([])
			expect(store.loading).toBe(false)
			expect(store.error).toBeNull()
			expect(store.showBoostShop).toBe(false)
			expect(store.showLeaderboard).toBe(false)
		})
	})

	describe('Boost catalog (UX-013)', () => {
		it('boostsLoaded is false until the catalog is fetched', () => {
			expect(store.boostsLoaded).toBe(false)
		})

		it('fetchBoostCatalog stores the authoritative prices', async () => {
			api.get.mockResolvedValue(MOCK_CATALOG)

			await store.fetchBoostCatalog()

			expect(api.get).toHaveBeenCalledWith('/api/v1/minigames/boosts')
			expect(store.boostCatalog).toEqual(MOCK_CATALOG)
			expect(store.boostsLoaded).toBe(true)
		})

		it('fetchBoostCatalog failure keeps the catalog absent and throws', async () => {
			api.get.mockRejectedValue(new Error('Offline'))

			await expect(store.fetchBoostCatalog()).rejects.toThrow('Offline')

			expect(store.boostCatalog).toBeNull()
			expect(store.boostsLoaded).toBe(false)
		})

		it('availableBoosts enriches catalog entries with display copy, sorted by cost', () => {
			store.boostCatalog = { magnet: 30, slow_motion: 10 }

			const boosts = store.availableBoosts

			expect(boosts.map(b => b.id)).toEqual(['slow_motion', 'magnet'])
			expect(boosts[0].cost).toBe(10)
			expect(boosts[0].name).toBe('Slow Motion')
		})

		it('availableBoosts is empty without a catalog', () => {
			expect(store.availableBoosts).toEqual([])
		})

		it('blocks purchases without a catalog instead of using stale prices', async () => {
			store.currentCoins = 100

			const result = await store.purchaseBoost('slow_motion')

			expect(result).toBe(false)
			expect(api.post).not.toHaveBeenCalled()
			expect(store.purchasedBoosts).toEqual([])
		})
	})

	describe('Getters', () => {
		beforeEach(() => {
			store.boostCatalog = { ...MOCK_CATALOG }
			store.currentCoins = 50
			store.purchasedBoosts = ['slow_motion']
			store.activeBoosts = ['slow_motion', 'ghost_mode']
			store.personalBest = { score: 100 }
		})

		it('isNewPersonalBest returns true when score exceeds best', () => {
			store.score = 150
			expect(store.isNewPersonalBest).toBe(true)
		})

		it('isNewPersonalBest returns false when score below best', () => {
			store.score = 50
			expect(store.isNewPersonalBest).toBe(false)
		})

		it('isNewPersonalBest returns true when no previous best', () => {
			store.personalBest = null
			store.score = 10
			expect(store.isNewPersonalBest).toBe(true)
		})
	})

	describe('Actions', () => {
		describe('fetchCoins', () => {
			it('fetches coins successfully', async () => {
				api.get.mockResolvedValue({ currentCoins: 75 })

				await store.fetchCoins()

				expect(api.get).toHaveBeenCalledWith('/api/v1/minigames/coins')
				expect(store.currentCoins).toBe(75)
			})

			it('handles error gracefully', async () => {
				api.get.mockRejectedValue(new Error('Network error'))

				await store.fetchCoins()

				expect(store.currentCoins).toBe(0)
			})
		})

		describe('fetchLeaderboard', () => {
			it('fetches leaderboard successfully', async () => {
				const mockLeaderboard = [
					{ id: 1, userId: 'user1', displayName: 'Alice', score: 500 },
					{ id: 2, userId: 'user2', displayName: 'Bob', score: 300 },
				]
				api.get.mockResolvedValue(mockLeaderboard)

				await store.fetchLeaderboard('snake', 10)

				expect(store.leaderboard).toEqual(mockLeaderboard)
				expect(store.loading).toBe(false)
			})

			it('handles error', async () => {
				api.get.mockRejectedValue(new Error('Failed'))

				await store.fetchLeaderboard('snake')

				expect(store.error).toBe('Failed')
				expect(store.loading).toBe(false)
			})
		})

		describe('fetchPersonalBest', () => {
			it('fetches personal best successfully', async () => {
				api.get.mockResolvedValue({ score: 250, id: 1 })

				await store.fetchPersonalBest('snake')

				expect(store.personalBest).toEqual({ score: 250, id: 1 })
			})
		})

		describe('fetchUserRank', () => {
			it('fetches user rank successfully', async () => {
				api.get.mockResolvedValue({ rank: 5 })

				await store.fetchUserRank('snake')

				expect(store.userRank).toBe(5)
			})
		})

		describe('purchaseBoost', () => {
			beforeEach(() => {
				store.boostCatalog = { ...MOCK_CATALOG }
			})

			it('purchases boost successfully', async () => {
				store.currentCoins = 50
				api.post.mockResolvedValue({ remainingCoins: 40 })

				const result = await store.purchaseBoost('slow_motion')

				expect(result).toBe(true)
				expect(store.currentCoins).toBe(40)
				// CS-PLAN-068 §3.6: purchasedBoosts is the SHOP'S selection
				// state — purchaseBoost only confirms with the server and
				// reconciles the wallet; pushing here duplicated entries the
				// shop's toggle had already added.
				expect(store.purchasedBoosts).not.toContain('slow_motion')
			})

			it('handles insufficient coins error', async () => {
				store.currentCoins = 5
				api.post.mockRejectedValue(new Error('Insufficient coins'))

				const result = await store.purchaseBoost('slow_motion')

				expect(result).toBe(false)
				expect(store.error).toBeTruthy()
			})
		})

		describe('saveScore', () => {
			it('saves score and refreshes data', async () => {
				store.score = 150
				store.purchasedBoosts = ['slow_motion']

				api.post.mockResolvedValue({ id: 1, score: 150 })
				api.get.mockResolvedValue([])

				const result = await store.saveScore('snake')

				expect(result).toEqual({ id: 1, score: 150 })
			})
		})

		describe('startGame', () => {
			it('initializes game state', () => {
				store.purchasedBoosts = ['slow_motion', 'ghost_mode']

				store.startGame('snake')

				expect(store.currentGame).toBe('snake')
				expect(store.score).toBe(0)
				expect(store.isPlaying).toBe(true)
				expect(store.isPaused).toBe(false)
				expect(store.gameOver).toBe(false)
				expect(store.activeBoosts).toEqual(['slow_motion', 'ghost_mode'])
			})
		})

		describe('pauseGame', () => {
			it('sets isPaused to true', () => {
				store.isPlaying = true
				store.pauseGame()
				expect(store.isPaused).toBe(true)
			})
		})

		describe('resumeGame', () => {
			it('sets isPaused to false', () => {
				store.isPaused = true
				store.resumeGame()
				expect(store.isPaused).toBe(false)
			})
		})

		describe('endGame', () => {
			it('ends game and saves score', async () => {
				store.score = 100
				store.isPlaying = true
				store.activeBoosts = ['slow_motion']

				api.post.mockResolvedValue({ id: 1 })
				api.get.mockResolvedValue(null)

				await store.endGame()

				expect(store.isPlaying).toBe(false)
				expect(store.gameOver).toBe(true)
				expect(store.activeBoosts).toEqual([])
				expect(store.purchasedBoosts).toEqual([])
			})

			it('returns the saved score payload so the UI can show earned coins', async () => {
				store.currentGame = 'snake'
				store.score = 120
				store.purchasedBoosts = ['slow_motion']
				api.post.mockResolvedValue({ id: 1, score: 120, coinsEarned: 12 })
				api.get.mockResolvedValue([])

				const result = await store.endGame()

				expect(result).toEqual({ id: 1, score: 120, coinsEarned: 12 })
			})

			it('does not save zero score', async () => {
				store.score = 0
				store.isPlaying = true

				await store.endGame()

				expect(api.post).not.toHaveBeenCalled()
			})
		})

		describe('updateScore', () => {
			it('adds points to score', () => {
				store.score = 50
				store.updateScore(10)
				expect(store.score).toBe(60)
			})
		})

		describe('toggleBoostShop', () => {
			it('toggles boost shop visibility', () => {
				store.showBoostShop = false
				store.toggleBoostShop()
				expect(store.showBoostShop).toBe(true)
				store.toggleBoostShop()
				expect(store.showBoostShop).toBe(false)
			})
		})

		describe('removePurchasedBoost', () => {
			it('removes specific boost', () => {
				store.purchasedBoosts = ['slow_motion', 'ghost_mode', 'magnet']
				store.removePurchasedBoost('ghost_mode')
				expect(store.purchasedBoosts).toEqual(['slow_motion', 'magnet'])
			})

			it('does nothing if boost not in list', () => {
				store.purchasedBoosts = ['slow_motion']
				store.removePurchasedBoost('magnet')
				expect(store.purchasedBoosts).toEqual(['slow_motion'])
			})
		})

		describe('initialize', () => {
			it('fetches all initial data incl. the boost catalog', async () => {
				api.get.mockResolvedValue({ currentCoins: 100 })

				await store.initialize('snake')

				expect(api.get).toHaveBeenCalledTimes(5)
				expect(store.boostCatalog).toEqual({ currentCoins: 100 })
				expect(store.loading).toBe(false)
			})

			// CS-PLAN-066 §3.4: the aggregate try/finally owner — a rejected
			// child fetch settles loading and propagates recoverably.
			it('settles loading and propagates a recoverable error on rejection', async () => {
				api.get.mockRejectedValue(new Error('catalog offline'))

				await expect(store.initialize('snake')).rejects.toThrow('catalog offline')

				expect(store.loading).toBe(false)
				expect(store.error).toBe('catalog offline')
			})

			it('a retry after failure succeeds and keeps loading settled', async () => {
				// The boost catalog rethrows into the aggregate; sibling
				// fetches stay soft-fail.
				api.get.mockImplementation((url) => {
					if (String(url).includes('/boosts')) {
						return Promise.reject(new Error('offline'))
					}
					return Promise.resolve({ currentCoins: 30 })
				})

				await expect(store.initialize('snake')).rejects.toThrow('offline')

				api.get.mockImplementation((url) => (
					String(url).includes('/boosts')
						? Promise.resolve({ magnet: 10 })
						: Promise.resolve({ currentCoins: 30 })
				))
				await store.initialize('snake')

				expect(store.loading).toBe(false)
				expect(store.error).toBeNull()
				expect(store.boostCatalog).toEqual({ magnet: 10 })
			})

			it('child fetches never touch the aggregate loading state', async () => {
				// Direct child call — no aggregate owner running.
				await store.fetchLeaderboard('snake')

				expect(store.loading).toBe(false)
			})
		})

		describe('reset', () => {
			it('resets all state to initial values', () => {
				store.currentGame = 'snake'
				store.score = 500
				store.isPlaying = true
				store.isPaused = true
				store.gameOver = false
				store.activeBoosts = ['slow_motion']
				store.purchasedBoosts = ['ghost_mode']
				store.boostCatalog = { ...MOCK_CATALOG }
				store.currentCoins = 100
				store.leaderboard = [{ id: 1 }]
				store.weeklyLeaderboard = [{ id: 2 }]
				store.personalBest = { score: 300 }
				store.userRank = 5
				store.recentScores = [{ id: 3 }]
				store.loading = true
				store.error = 'Error'
				store.showBoostShop = true
				store.showLeaderboard = true

				store.reset()

				expect(store.currentGame).toBeNull()
				expect(store.score).toBe(0)
				expect(store.isPlaying).toBe(false)
				expect(store.isPaused).toBe(false)
				expect(store.gameOver).toBe(false)
				expect(store.activeBoosts).toEqual([])
				expect(store.purchasedBoosts).toEqual([])
				expect(store.boostCatalog).toBeNull()
				expect(store.currentCoins).toBe(0)
				expect(store.leaderboard).toEqual([])
				expect(store.weeklyLeaderboard).toEqual([])
				expect(store.personalBest).toBeNull()
				expect(store.userRank).toBe(0)
				expect(store.recentScores).toEqual([])
				expect(store.loading).toBe(false)
				expect(store.error).toBeNull()
				expect(store.showBoostShop).toBe(false)
				expect(store.showLeaderboard).toBe(false)
			})
		})
	})
})