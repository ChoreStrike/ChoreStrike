/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { logger } from '../utils/logger.js'

/**
 * Available boost types with their costs
 */
export const BOOST_COSTS = {
	slow_motion: 10,
	ghost_mode: 20,
	magnet: 30,
	shrink: 15,
	no_obstacles: 25,
}

/**
 * Boost descriptions for UI display
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
		 * Check if user can afford a boost
		 * @param state
		 */
		canAffordBoost: (state) => (boostId) => {
			const cost = BOOST_COSTS[boostId]
			return state.currentCoins >= cost
		},

		/**
		 * Get total cost of selected boosts
		 * @param state
		 */
		selectedBoostsCost: (state) => {
			return state.purchasedBoosts.reduce((sum, boostId) => {
				return sum + (BOOST_COSTS[boostId] || 0)
			}, 0)
		},

		/**
		 * Check if a boost is currently active
		 * @param state
		 */
		isBoostActive: (state) => (boostId) => {
			return state.activeBoosts.includes(boostId)
		},

		/**
		 * Get available boosts that user can afford
		 * @param state
		 */
		affordableBoosts: (state) => {
			return Object.entries(BOOST_COSTS)
				.filter(([id, cost]) => state.currentCoins >= cost)
				.map(([id, cost]) => ({
					id,
					cost,
					...BOOST_DESCRIPTIONS[id],
				}))
		},

		/**
		 * Check if current score is a new personal best
		 * @param state
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
				const response = await axios.get('/apps/chorestrike/api/v1/minigames/coins')
				if (response.data.success) {
					this.currentCoins = response.data.data.currentCoins
				}
			} catch (error) {
				logger.error('Failed to fetch coins', error, { store: 'minigame', action: 'fetchCoins' })
			}
		},

		/**
		 * Fetch leaderboard for a game
		 * @param gameId
		 * @param limit
		 */
		async fetchLeaderboard(gameId = 'snake', limit = 10) {
			this.loading = true
			this.error = null

			try {
				const response = await axios.get(`/apps/chorestrike/api/v1/minigames/${gameId}/leaderboard`, {
					params: { limit },
				})

				if (response.data.success) {
					this.leaderboard = response.data.data
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch leaderboard', error, { store: 'minigame', action: 'fetchLeaderboard' })
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch weekly leaderboard
		 * @param gameId
		 * @param limit
		 */
		async fetchWeeklyLeaderboard(gameId = 'snake', limit = 10) {
			try {
				const response = await axios.get(`/apps/chorestrike/api/v1/minigames/${gameId}/leaderboard/weekly`, {
					params: { limit },
				})

				if (response.data.success) {
					this.weeklyLeaderboard = response.data.data
				}
			} catch (error) {
				logger.error('Failed to fetch weekly leaderboard', error, { store: 'minigame', action: 'fetchWeeklyLeaderboard' })
			}
		},

		/**
		 * Fetch personal best score
		 * @param gameId
		 */
		async fetchPersonalBest(gameId = 'snake') {
			try {
				const response = await axios.get(`/apps/chorestrike/api/v1/minigames/${gameId}/personal-best`)

				if (response.data.success) {
					this.personalBest = response.data.data
				}
			} catch (error) {
				logger.error('Failed to fetch personal best', error, { store: 'minigame', action: 'fetchPersonalBest' })
			}
		},

		/**
		 * Fetch user rank
		 * @param gameId
		 */
		async fetchUserRank(gameId = 'snake') {
			try {
				const response = await axios.get(`/apps/chorestrike/api/v1/minigames/${gameId}/rank`)

				if (response.data.success) {
					this.userRank = response.data.data.rank
				}
			} catch (error) {
				logger.error('Failed to fetch user rank', error, { store: 'minigame', action: 'fetchUserRank' })
			}
		},

		/**
		 * Fetch recent scores
		 * @param gameId
		 * @param limit
		 */
		async fetchRecentScores(gameId = 'snake', limit = 5) {
			try {
				const response = await axios.get(`/apps/chorestrike/api/v1/minigames/${gameId}/recent`, {
					params: { limit },
				})

				if (response.data.success) {
					this.recentScores = response.data.data
				}
			} catch (error) {
				logger.error('Failed to fetch recent scores', error, { store: 'minigame', action: 'fetchRecentScores' })
			}
		},

		/**
		 * Purchase a boost
		 * @param boostId
		 */
		async purchaseBoost(boostId) {
			this.boostsLoading = true

			try {
				const response = await axios.post(`/apps/chorestrike/api/v1/minigames/boosts/${boostId}/purchase`)

				if (response.data.success) {
					this.currentCoins = response.data.data.remainingCoins
					this.purchasedBoosts.push(boostId)
					return true
				}
			} catch (error) {
				this.error = error.response?.data?.error?.message || error.message
				logger.error('Failed to purchase boost', error, { store: 'minigame', action: 'purchaseBoost', boostId })
			} finally {
				this.boostsLoading = false
			}

			return false
		},

		/**
		 * Save game score
		 * @param gameId
		 */
		async saveScore(gameId = 'snake') {
			try {
				const response = await axios.post(`/apps/chorestrike/api/v1/minigames/${gameId}/scores`, {
					score: this.score,
					boostsUsed: this.purchasedBoosts,
				})

				if (response.data.success) {
					// Update coins if earned
					if (response.data.data.coinsEarned > 0) {
						this.currentCoins += response.data.data.coinsEarned
					}

					// Refresh leaderboard and personal best
					await Promise.all([
						this.fetchPersonalBest(gameId),
						this.fetchUserRank(gameId),
						this.fetchLeaderboard(gameId),
					])
					return response.data.data
				}
			} catch (error) {
				logger.error('Failed to save score', error, { store: 'minigame', action: 'saveScore' })
			}

			return null
		},

		/**
		 * Start a new game
		 * @param gameId
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
		 * @param points
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
		 * Toggle leaderboard visibility
		 */
		toggleLeaderboard() {
			this.showLeaderboard = !this.showLeaderboard
		},

		/**
		 * Clear purchased boosts
		 */
		clearPurchasedBoosts() {
			this.purchasedBoosts = []
		},

		/**
		 * Remove a purchased boost
		 * @param boostId
		 */
		removePurchasedBoost(boostId) {
			const index = this.purchasedBoosts.indexOf(boostId)
			if (index > -1) {
				this.purchasedBoosts.splice(index, 1)
			}
		},

		/**
		 * Initialize minigame data
		 * @param gameId
		 */
		async initialize(gameId = 'snake') {
			this.loading = true

			await Promise.all([
				this.fetchCoins(),
				this.fetchLeaderboard(gameId),
				this.fetchPersonalBest(gameId),
				this.fetchUserRank(gameId),
			])

			this.loading = false
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
