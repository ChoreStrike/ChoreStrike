/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import { api } from '../services/api.js'
import { logger } from '../utils/logger.js'

/**
 * Arcade store (CS-PLAN-070 WP3, T10/D7d no-Coin cutover): scores are
 * family fun only. The Coin minting, boost shop/catalog and Coin balance
 * reads are retired — nothing here touches the family wallet or
 * contribution; refreshes refetch game state instead.
 */
export const useMinigameStore = defineStore('minigame', {
	state: () => ({
		// Current game state
		currentGame: null,
		score: 0,
		isPlaying: false,
		isPaused: false,
		gameOver: false,

		// Leaderboard
		leaderboard: [],
		weeklyLeaderboard: [],
		personalBest: null,
		userRank: 0,
		recentScores: [],

		// UI state
		loading: false,
		error: null,
		showLeaderboard: false,
	}),

	getters: {
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
		 * Fetch leaderboard for a game
		 *
		 * CS-PLAN-066 §3.4: child fetches own their slice states and never
		 * touch the AGGREGATE `loading` flag — a parallel child fetch
		 * settling early must not clear the initialization owner's loading
		 * state.
		 * @param {string} gameId Game identifier (e.g. 'snake')
		 * @param {number} limit Maximum number of entries to return
		 */
		async fetchLeaderboard(gameId = 'snake', limit = 10) {
			this.error = null

			try {
				const data = await api.get(`/api/v1/minigames/${gameId}/leaderboard`, {
					params: { limit },
				})
				this.leaderboard = data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch leaderboard', error, { store: 'minigame', action: 'fetchLeaderboard' })
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
		 * Save game score (scores only — never wallet/contribution)
		 * @param {string} gameId Game identifier (e.g. 'snake')
		 */
		async saveScore(gameId = 'snake') {
			try {
				const data = await api.post(`/api/v1/minigames/${gameId}/scores`, {
					score: this.score,
				})

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
		 * Initialize minigame data
		 *
		 * CS-PLAN-066 §3.4: the aggregate owns `loading` behind a
		 * try/finally, so a rejected child fetch can never leave the store
		 * stuck in a loading state, and the failure is propagated
		 * recoverably to the caller for its error/retry UI. Child fetches
		 * keep their own soft-fail behavior (partial data beats a blank
		 * arcade).
		 * @param {string} gameId Game identifier (e.g. 'snake')
		 * @throws {Error} When a child fetch fails (recoverable — retry by
		 *   calling initialize again).
		 */
		async initialize(gameId = 'snake') {
			this.loading = true
			this.error = null

			try {
				await Promise.all([
					this.fetchLeaderboard(gameId),
					this.fetchPersonalBest(gameId),
					this.fetchUserRank(gameId),
				])
			} catch (error) {
				this.error = error.message
				logger.error('Failed to initialize minigame data', error, { store: 'minigame', action: 'initialize', gameId })
				throw error
			} finally {
				this.loading = false
			}
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
			this.leaderboard = []
			this.weeklyLeaderboard = []
			this.personalBest = null
			this.userRank = 0
			this.recentScores = []
			this.loading = false
			this.error = null
			this.showLeaderboard = false
		},
	},
})
