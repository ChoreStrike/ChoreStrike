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
function emptySlice() {
	return {
		leaderboard: [],
		weeklyLeaderboard: [],
		personalBest: null,
		userRank: 0,
		recentScores: [],
	}
}

const EMPTY_SLICE = Object.freeze(emptySlice())

/** Save rejections the overlay names (R086H-03); any other failure is 'error'. */
const SAVE_FAILURES = Object.freeze({
	MINIGAME_LOCKED: 'locked',
	MINIGAME_RATE_LIMITED: 'limit',
})

/**
 * Arcade store (CS-PLAN-070 WP3, T10/D7d no-Coin cutover): scores are
 * family fun only. Nothing here touches the family wallet or contribution.
 *
 * CS-PLAN-086: per-game slices (`byGame[gameId]`); every action names its
 * game explicitly. The live round itself (score, pause, game over) belongs
 * to GameShell; the store records the result through `finishGame`. The
 * `catalog` slice is the server's view of the member's arcade
 * (`{gateEnabled, finishedChores, games: [{gameId, threshold, unlocked}]}`),
 * null until it loads.
 *
 * CS-PLAN-090 WP-J: the owner's controls — thresholds, the progress list
 * (`progress`, `[{userId, displayName, finishedChores, resetAt}]`, null
 * until it loads) and one member's reset. A read sequence per slice drops
 * an answer that a newer read or write overtook (client-state contract,
 * RF-090-37).
 */
export const useMinigameStore = defineStore('minigame', {
	state: () => ({
		byGame: {},
		catalog: null,
		progress: null,
		catalogSeq: 0,
		progressSeq: 0,
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
		 * @param {() => Promise<unknown>} request The API read
		 * @param {(data: unknown) => unknown} [pick] Maps the response data to the field value
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
			return this.readInto(
				gameId,
				'leaderboard',
				() => api.get(`/api/v1/minigames/${gameId}/leaderboard`, { params: { limit } }),
			)
		},

		fetchWeeklyLeaderboard(gameId, limit = 10) {
			return this.readInto(
				gameId,
				'weeklyLeaderboard',
				() => api.get(`/api/v1/minigames/${gameId}/leaderboard/weekly`, { params: { limit } }),
			)
		},

		fetchPersonalBest(gameId) {
			return this.readInto(
				gameId,
				'personalBest',
				() => api.get(`/api/v1/minigames/${gameId}/personal-best`),
			)
		},

		fetchUserRank(gameId) {
			return this.readInto(
				gameId,
				'userRank',
				() => api.get(`/api/v1/minigames/${gameId}/rank`),
				(data) => data.rank,
			)
		},

		fetchRecentScores(gameId, limit = 5) {
			return this.readInto(
				gameId,
				'recentScores',
				() => api.get(`/api/v1/minigames/${gameId}/recent`, { params: { limit } }),
			)
		},

		/**
		 * Load the member's catalog: game ids, unlock thresholds and lock
		 * states (CS-PLAN-086 §3.2). Soft-fails like the score reads.
		 */
		async fetchCatalog() {
			const seq = ++this.catalogSeq
			try {
				const catalog = await api.get('/api/v1/minigames/catalog')
				if (seq === this.catalogSeq) {
					this.catalog = catalog
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch the arcade catalog', error, { store: 'minigame', action: 'fetchCatalog' })
			}
		},

		/**
		 * Owner-only: save every game's threshold (CS-PLAN-090 D-090-66).
		 * The answer is the owner's catalog, so cards and form agree at once;
		 * an older catalog read still in flight is dropped. A failure reaches
		 * the form.
		 *
		 * @param {Object<string, number>} thresholds Game id => whole number
		 */
		async saveThresholds(thresholds) {
			const seq = ++this.catalogSeq
			try {
				const catalog = await api.put('/api/v1/house/arcade-thresholds', { thresholds })
				if (seq === this.catalogSeq) {
					this.catalog = catalog
				}
			} catch (error) {
				logger.error('Failed to save the arcade thresholds', error, { store: 'minigame', action: 'saveThresholds' })
				throw error
			}
		},

		/**
		 * Owner-only: each member's finished chores since their last reset.
		 * A failure reaches the list.
		 */
		async fetchProgress() {
			const seq = ++this.progressSeq
			try {
				const progress = await api.get('/api/v1/house/arcade-progress')
				if (seq === this.progressSeq) {
					this.progress = progress
				}
			} catch (error) {
				logger.error('Failed to fetch the arcade progress', error, { store: 'minigame', action: 'fetchProgress' })
				throw error
			}
		},

		/**
		 * Owner-only: reset one member for all games (CS-PLAN-090 D-090-63).
		 * The answer is the progress list; the catalog is read again, because
		 * the owner may have reset themselves.
		 *
		 * @param {string} userId The member's Nextcloud user id
		 */
		async resetProgress(userId) {
			const seq = ++this.progressSeq
			try {
				const progress = await api.post(`/api/v1/house/members/${encodeURIComponent(userId)}/arcade-reset`)
				if (seq === this.progressSeq) {
					this.progress = progress
				}
			} catch (error) {
				logger.error('Failed to reset the arcade progress', error, { store: 'minigame', action: 'resetProgress' })
				throw error
			}
			await this.fetchCatalog()
		},

		/**
		 * Save a finished round (scores only — never wallet/contribution) and
		 * refresh that game's personal best, rank and board. A locked
		 * rejection (the game re-locked after an undo while it was open)
		 * refreshes the catalog, so the card shows the lock again.
		 *
		 * @param {string} gameId Game identifier
		 * @param {number} score Final score
		 * @param {number} durationMs Active play time (required, D-086-04)
		 * @return {Promise<{data: object|null, failure: string|null}>} The saved
		 *   score, or null with the reason: 'locked', 'limit' (the daily save
		 *   cap) or 'error'
		 */
		async saveScore(gameId, score, durationMs) {
			try {
				const data = await api.post(`/api/v1/minigames/${gameId}/scores`, { score, durationMs })
				await Promise.all([
					this.fetchPersonalBest(gameId),
					this.fetchUserRank(gameId),
					this.fetchLeaderboard(gameId),
				])
				return { data, failure: null }
			} catch (error) {
				this.error = error.message
				logger.error('Failed to save score', error, { store: 'minigame', action: 'saveScore', gameId })
				if (error?.code === 'MINIGAME_LOCKED') {
					await this.fetchCatalog()
				}
				return { data: null, failure: SAVE_FAILURES[error?.code] ?? 'error' }
			}
		},

		/**
		 * Record a finished round. A zero score is not saved.
		 *
		 * @param {string} gameId Game identifier
		 * @param {number} score Final score
		 * @param {number} durationMs Active play time
		 * @return {Promise<{saved: boolean, saveFailed: boolean, failure: string|null, newBest: boolean}>}
		 */
		async finishGame(gameId, score, durationMs) {
			if (score <= 0) {
				return { saved: false, saveFailed: false, failure: null, newBest: false }
			}
			const previousBest = this.slice(gameId).personalBest?.score ?? 0
			const { data, failure } = await this.saveScore(gameId, score, durationMs)
			const saved = data !== null
			return { saved, saveFailed: !saved, failure, newBest: saved && score > previousBest }
		},

		/**
		 * Load what the catalog cards show: the catalog first, then personal
		 * best and rank of every game it lists.
		 *
		 * CS-PLAN-066 §3.4: the aggregate owns `loading` behind a
		 * try/finally; child fetches soft-fail into `error`.
		 */
		async initialize() {
			this.loading = true
			this.error = null
			try {
				await this.fetchCatalog()
				await Promise.all((this.catalog?.games ?? []).flatMap(({ gameId }) => [
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
			this.catalog = null
			this.progress = null
			// Invalidate reads still in flight.
			this.catalogSeq++
			this.progressSeq++
			this.loading = false
			this.error = null
		},
	},
})
