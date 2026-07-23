import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'
import { useMinigameStore, BOOST_COSTS } from '../../../src/store/minigame.js'

jest.mock('../../../src/services/api.js', () => ({
	api: {
		get: jest.fn(),
		post: jest.fn(),
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

	describe('Getters', () => {
		beforeEach(() => {
			store.currentCoins = 50
			store.purchasedBoosts = ['slow_motion']
			store.activeBoosts = ['slow_motion', 'ghost_mode']
			store.personalBest = { score: 100 }
		})

		it('canAffordBoost returns true when sparks sufficient', () => {
			expect(store.canAffordBoost('slow_motion')).toBe(true) // costs 10
		})

		it('canAffordBoost returns false when coins insufficient', () => {
			store.currentCoins = 5
			expect(store.canAffordBoost('ghost_mode')).toBe(false) // costs 20
		})

		it('selectedBoostsCost calculates total cost', () => {
			store.purchasedBoosts = ['slow_motion', 'ghost_mode']
			expect(store.selectedBoostsCost).toBe(30) // 10 + 20
		})

		it('isBoostActive returns true for active boost', () => {
			expect(store.isBoostActive('slow_motion')).toBe(true)
		})

		it('isBoostActive returns false for inactive boost', () => {
			expect(store.isBoostActive('magnet')).toBe(false)
		})

		it('affordableBoosts returns boosts within budget', () => {
			store.currentCoins = 25
			const affordable = store.affordableBoosts
			expect(affordable.some(b => b.id === 'slow_motion')).toBe(true) // 10
			expect(affordable.some(b => b.id === 'shrink')).toBe(true) // 15
			expect(affordable.some(b => b.id === 'ghost_mode')).toBe(true) // 20
			expect(affordable.some(b => b.id === 'no_obstacles')).toBe(true) // 25
			expect(affordable.some(b => b.id === 'magnet')).toBe(false) // 30
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
			it('purchases boost successfully', async () => {
				store.currentCoins = 50
				api.post.mockResolvedValue({ remainingCoins: 40 })

				const result = await store.purchaseBoost('slow_motion')

				expect(result).toBe(true)
				expect(store.currentCoins).toBe(40)
				expect(store.purchasedBoosts).toContain('slow_motion')
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

		describe('toggleLeaderboard', () => {
			it('toggles leaderboard visibility', () => {
				store.showLeaderboard = false
				store.toggleLeaderboard()
				expect(store.showLeaderboard).toBe(true)
			})
		})

		describe('clearPurchasedBoosts', () => {
			it('clears purchased boosts', () => {
				store.purchasedBoosts = ['slow_motion', 'ghost_mode']
				store.clearPurchasedBoosts()
				expect(store.purchasedBoosts).toEqual([])
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
			it('fetches all initial data', async () => {
				api.get.mockResolvedValue({ currentCoins: 100 })

				await store.initialize('snake')

				expect(api.get).toHaveBeenCalledTimes(4)
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

	describe('BOOST_COSTS', () => {
		it('has correct boost costs', () => {
			expect(BOOST_COSTS.slow_motion).toBe(10)
			expect(BOOST_COSTS.ghost_mode).toBe(20)
			expect(BOOST_COSTS.magnet).toBe(30)
			expect(BOOST_COSTS.shrink).toBe(15)
			expect(BOOST_COSTS.no_obstacles).toBe(25)
		})
	})
})