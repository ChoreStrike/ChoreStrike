/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import { api } from '../services/api.js'
import { logger } from '../utils/logger.js'

/**
 * Score state of one game. Every game owns its own slice (CS-PLAN-086), so a
 * save in one game never changes another game's boards or personal best.
 *
 * @return {object} Empty slice
 */
const emptySlice = () => ({
	leaderboard: [],
	weeklyLeaderboard: [],
	personalBest: null,
	userRank: 0,
	recentScores: [],
})

const EMPTY_SLICE = Object.freeze(emptySlice())

/**
 * Arcade store (CS-PLAN-070 WP3, T10/D7d no-Coin cutover): scores are
 * family fun only. Nothing here touches the family wallet or contribution.
 *
 * CS-PLAN-086: per-game slices (`byGame[gameId]`); every action names its
 * game explicitly. The live round itself (score, pause, game over) belongs
 * to GameShell; the store records the result through `finishGame`.
 */
export const useMinigameStore = defineStore('minigame', {
	state: () => ({
		byGame: {},
		loading: false,
		error: null,
	}),

	getters: {
		/**
		 * Read-only slice of a game (an empty one before the first fetch).
		 * @param {object} state Pinia store state
		 * @return {function(string): object}
		 */
		slice: (state) => (gameId) => state.byGame[gameId] ?? EMPTY_SLICE,
	},

	actions: {
		/**
		 * @param {string} gameId Game identifier
		 * @return {object} The writable slice of the game
		 */
		ensureSlice(gameId) {
			if (!this.byGame[gameId]) {
				this.byGame[gameId] = emptySlice()
			}
			return this.byGame[gameId]
		},

		/**
		 * Soft-failing child read: stores the result in the game's slice, or
		 * records the error and keeps the previous value (CS-PLAN-066 §3.4:
		 * child fetches never touch the aggregate `loading` flag).
		 *
		 * @param {string} gameId Game identifier
		 * @param {string} field Slice field to fill
		 * @param {function(): Promise<*>} request The API read
		 * @param {function(*): *} [pick] Maps the response data to the field value
		 */
		async readInto(gameId, field, request, pick = (data) => data) {
			try {
				this.ensureSlice(gameId)[field] = pick(await request())
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch minigame data', error, { store: 'minigame', action: field, gameId })
			}
		},

		fetchLeaderboard(gameId, limit = 10) {
			return this.readInto(gameId, 'leaderboard',
				() => api.get(`/api/v1/minigames/${gameId}/leaderboard`, { params: { limit } }))
		},

		fetchWeeklyLeaderboard(gameId, limit = 10) {
			return this.readInto(gameId, 'weeklyLeaderboard',
				() => api.get(`/api/v1/minigames/${gameId}/leaderboard/weekly`, { params: { limit } }))
		},

		fetchPersonalBest(gameId) {
			return this.readInto(gameId, 'personalBest',
				() => api.get(`/api/v1/minigames/${gameId}/personal-best`))
		},

		fetchUserRank(gameId) {
			return this.readInto(gameId, 'userRank',
				() => api.get(`/api/v1/minigames/${gameId}/rank`), (data) => data.rank)
		},

		fetchRecentScores(gameId, limit = 5) {
			return this.readInto(gameId, 'recentScores',
				() => api.get(`/api/v1/minigames/${gameId}/recent`, { params: { limit } }))
		},

		/**
		 * Save a finished round (scores only — never wallet/contribution) and
		 * refresh that game's personal best, rank and board.
		 *
		 * @param {string} gameId Game identifier
		 * @param {number} score Final score
		 * @param {number} durationMs Active play time (required, D-086-04)
		 * @return {Promise<object|null>} Saved score, or null when the save failed
		 */
		async saveScore(gameId, score, durationMs) {
			try {
				const data = await api.post(`/api/v1/minigames/${gameId}/scores`, { score, durationMs })
				await Promise.all([
					this.fetchPersonalBest(gameId),
					this.fetchUserRank(gameId),
					this.fetchLeaderboard(gameId),
				])
				return data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to save score', error, { store: 'minigame', action: 'saveScore', gameId })
				return null
			}
		},

		/**
		 * Record a finished round. A zero score is not saved.
		 *
		 * @param {string} gameId Game identifier
		 * @param {number} score Final score
		 * @param {number} durationMs Active play time
		 * @return {Promise<{saved: boolean, saveFailed: boolean, newBest: boolean}>}
		 */
		async finishGame(gameId, score, durationMs) {
			if (score <= 0) {
				return { saved: false, saveFailed: false, newBest: false }
			}
			const previousBest = this.slice(gameId).personalBest?.score ?? 0
			const saved = (await this.saveScore(gameId, score, durationMs)) !== null
			return { saved, saveFailed: !saved, newBest: saved && score > previousBest }
		},

		/**
		 * Load what the catalog cards show: personal best and rank per game.
		 *
		 * CS-PLAN-066 §3.4: the aggregate owns `loading` behind a
		 * try/finally; child fetches soft-fail into `error`.
		 * @param {string[]} gameIds Game identifiers
		 */
		async initialize(gameIds) {
			this.loading = true
			this.error = null
			try {
				await Promise.all(gameIds.flatMap((gameId) => [
					this.fetchPersonalBest(gameId),
					this.fetchUserRank(gameId),
				]))
			} finally {
				this.loading = false
			}
		},

		clearError() {
			this.error = null
		},

		reset() {
			this.byGame = {}
			this.loading = false
			this.error = null
		},
	},
})
