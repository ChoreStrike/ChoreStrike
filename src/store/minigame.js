/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import { api } from '../services/api.js'
import { logger } from '../utils/logger.js'

/**
 * Boost display copy (name/icon/effect). Prices are NOT defined here —
 * they come exclusively from `GET /minigames/boosts` into the store
 * catalog so affordability check and purchase can never drift apart
 * (CS-PLAN-041, UX-013).
 */
export const BOOST_DESCRIPTIONS = {
	slow_motion: { name: 'Slow Motion', icon: '🐌', effect: '20% slower snake' },
	ghost_mode: { name: 'Ghost Mode', icon: '👻', effect: '1 extra life' },
	magnet: { name: 'Magnet', icon: '🧲', effect: 'Auto-collect nearby food' },
	shrink: { name: 'Shrink', icon: '✂️', effect: 'Periodically shrink snake' },
	no_obstacles: { name: 'No Obstacles', icon: '🛡️', effect: 'Remove all obstacles' },
}

export const useMinigameStore = defineStore('minigame', {
	state: () => ({
		// Current game state
		currentGame: null,
		score: 0,
		isPlaying: false,
		isPaused: false,
		gameOver: false,

		// Boosts
		activeBoosts: [],
		purchasedBoosts: [],
		boostsLoading: false,
		// Authoritative boost price catalog from GET /minigames/boosts:
		// `{ [boostId]: cost }`. Null until loaded; purchases stay
		// disabled without it instead of falling back to stale prices.
		boostCatalog: null,

		// Coins
		currentCoins: 0,

		// Leaderboard
		leaderboard: [],
		weeklyLeaderboard: [],
		personalBest: null,
		userRank: 0,
		recentScores: [],

		// UI state
		loading: false,
		error: null,
		showBoostShop: false,
		showLeaderboard: false,
	}),

	getters: {
		/**
		 * Whether the authoritative boost catalog has been loaded.
		 * @param {object} state Pinia store state
		 */
		boostsLoaded: (state) => state.boostCatalog !== null,

		/**
		 * Catalog entries enriched with display copy, sorted by cost.
		 * Empty until the catalog is loaded — consumers render a disabled
		 * error state instead of stale prices.
		 * @param {object} state Pinia store state
		 */
		availableBoosts: (state) => {
			if (!state.boostCatalog) return []
			return Object.entries(state.boostCatalog)
				.map(([id, cost]) => ({ id, cost, ...BOOST_DESCRIPTIONS[id] }))
				.sort((a, b) => a.cost - b.cost)
		},

		/**
		 * Check if current score is a new personal best
		 * @param {object} state Pinia store state
		 */
		isNewPersonalBest: (state) => {
			if (!state.personalBest) return state.score > 0
			return state.score > state.personalBest.score
		},
	},

	actions: {
		/**
		 * Fetch current coins balance
		 */
		async fetchCoins() {
			try {
				const data = await api.get('/api/v1/minigames/coins')
				this.currentCoins = data.currentCoins
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch coins', error, { store: 'minigame', action: 'fetchCoins' })
			}
		},

		/**
		 * Fetch leaderboard for a game
		 * @param {string} gameId Game identifier (e.g. 'snake')
		 * @param {number} limit Maximum number of entries to return
		 */
		async fetchLeaderboard(gameId = 'snake', limit = 10) {
			this.loading = true
			this.error = null

			try {
				const data = await api.get(`/api/v1/minigames/${gameId}/leaderboard`, {
					params: { limit },
				})
				this.leaderboard = data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch leaderboard', error, { store: 'minigame', action: 'fetchLeaderboard' })
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch weekly leaderboard
		 * @param {string} gameId Game identifier (e.g. 'snake')
		 * @param {number} limit Maximum number of entries to return
		 */
		async fetchWeeklyLeaderboard(gameId = 'snake', limit = 10) {
			try {
				const data = await api.get(`/api/v1/minigames/${gameId}/leaderboard/weekly`, {
					params: { limit },
				})
				this.weeklyLeaderboard = data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch weekly leaderboard', error, { store: 'minigame', action: 'fetchWeeklyLeaderboard' })
			}
		},

		/**
		 * Fetch personal best score
		 * @param {string} gameId Game identifier (e.g. 'snake')
		 */
		async fetchPersonalBest(gameId = 'snake') {
			try {
				const data = await api.get(`/api/v1/minigames/${gameId}/personal-best`)
				this.personalBest = data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch personal best', error, { store: 'minigame', action: 'fetchPersonalBest' })
			}
		},

		/**
		 * Fetch user rank
		 * @param {string} gameId Game identifier (e.g. 'snake')
		 */
		async fetchUserRank(gameId = 'snake') {
			try {
				const data = await api.get(`/api/v1/minigames/${gameId}/rank`)
				this.userRank = data.rank
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch user rank', error, { store: 'minigame', action: 'fetchUserRank' })
			}
		},

		/**
		 * Fetch recent scores
		 * @param {string} gameId Game identifier (e.g. 'snake')
		 * @param {number} limit Maximum number of entries to return
		 */
		async fetchRecentScores(gameId = 'snake', limit = 5) {
			try {
				const data = await api.get(`/api/v1/minigames/${gameId}/recent`, {
					params: { limit },
				})
				this.recentScores = data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch recent scores', error, { store: 'minigame', action: 'fetchRecentScores' })
			}
		},

		/**
		 * Fetch the authoritative boost price catalog.
		 *
		 * Prices live server-side only; until this succeeds every
		 * affordability check fails and purchases are disabled so the UI can
		 * never offer stale prices (UX-013).
		 */
		async fetchBoostCatalog() {
			this.boostsLoading = true

			try {
				const data = await api.get('/api/v1/minigames/boosts')
				this.boostCatalog = { ...data }
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch boost catalog', error, { store: 'minigame', action: 'fetchBoostCatalog' })
				throw error
			} finally {
				this.boostsLoading = false
			}
		},

		/**
		 * Purchase a boost
		 * @param {string} boostId Boost identifier to purchase
		 */
		async purchaseBoost(boostId) {
			// No stale-price fallback: without the authoritative catalog the
			// purchase is refused up front instead of guessing the cost.
			if (!this.boostCatalog?.[boostId]) {
				logger.error('Purchase blocked - boost catalog unavailable', undefined, { store: 'minigame', action: 'purchaseBoost', boostId })
				return false
			}

			this.boostsLoading = true

			try {
				const data = await api.post(`/api/v1/minigames/boosts/${boostId}/purchase`)
				this.currentCoins = data.remainingCoins
				this.purchasedBoosts.push(boostId)
				return true
			} catch (error) {
				this.error = error.message
				logger.error('Failed to purchase boost', error, { store: 'minigame', action: 'purchaseBoost', boostId })
			} finally {
				this.boostsLoading = false
			}

			return false
		},

		/**
		 * Save game score
		 * @param {string} gameId Game identifier (e.g. 'snake')
		 */
		async saveScore(gameId = 'snake') {
			try {
				const data = await api.post(`/api/v1/minigames/${gameId}/scores`, {
					score: this.score,
					boostsUsed: this.purchasedBoosts,
				})

				if (data.coinsEarned > 0) {
					this.currentCoins += data.coinsEarned
				}

				await Promise.all([
					this.fetchPersonalBest(gameId),
					this.fetchUserRank(gameId),
					this.fetchLeaderboard(gameId),
				])
				return data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to save score', error, { store: 'minigame', action: 'saveScore' })
			}

			return null
		},

		/**
		 * Start a new game
		 * @param {string} gameId Game identifier (e.g. 'snake')
		 */
		startGame(gameId = 'snake') {
			this.currentGame = gameId
			this.score = 0
			this.isPlaying = true
			this.isPaused = false
			this.gameOver = false
			this.activeBoosts = [...this.purchasedBoosts]
		},

		/**
		 * Pause the current game
		 */
		pauseGame() {
			this.isPaused = true
		},

		/**
		 * Resume the current game
		 */
		resumeGame() {
			this.isPaused = false
		},

		/**
		 * End the current game
		 */
		async endGame() {
			this.isPlaying = false
			this.gameOver = true
			let saveResult = null

			// Save score to backend
			if (this.score > 0) {
				saveResult = await this.saveScore(this.currentGame)
			}

			// Clear boosts
			this.activeBoosts = []
			this.purchasedBoosts = []

			return saveResult
		},

		/**
		 * Update score
		 * @param {number} points Points to add to the current score
		 */
		updateScore(points) {
			this.score += points
		},

		/**
		 * Toggle boost shop visibility
		 */
		toggleBoostShop() {
			this.showBoostShop = !this.showBoostShop
		},

		/**
		 * Remove a purchased boost
		 * @param {string} boostId Boost identifier to remove
		 */
		removePurchasedBoost(boostId) {
			const index = this.purchasedBoosts.indexOf(boostId)
			if (index > -1) {
				this.purchasedBoosts.splice(index, 1)
			}
		},

		/**
		 * Initialize minigame data
		 * @param {string} gameId Game identifier (e.g. 'snake')
		 */
		async initialize(gameId = 'snake') {
			this.loading = true

			await Promise.all([
				this.fetchCoins(),
				this.fetchBoostCatalog(),
				this.fetchLeaderboard(gameId),
				this.fetchPersonalBest(gameId),
				this.fetchUserRank(gameId),
			])

			this.loading = false
		},

		/**
		 * Clear error state
		 */
		clearError() {
			this.error = null
		},

		/**
		 * Reset store state
		 */
		reset() {
			this.currentGame = null
			this.score = 0
			this.isPlaying = false
			this.isPaused = false
			this.gameOver = false
			this.activeBoosts = []
			this.purchasedBoosts = []
			this.boostCatalog = null
			this.currentCoins = 0
			this.leaderboard = []
			this.weeklyLeaderboard = []
			this.personalBest = null
			this.userRank = 0
			this.recentScores = []
			this.loading = false
			this.error = null
			this.showBoostShop = false
			this.showLeaderboard = false
		},
	},
})
