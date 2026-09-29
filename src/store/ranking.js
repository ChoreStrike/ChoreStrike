/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import { api } from '../services/api.js'
import { logger } from '../utils/logger.js'

/**
 * Consent-filtered ranking store (CS-PLAN-070 WP4, C5i/C5l/D10b).
 *
 * The server is the sole ranking authority: visible entries are EFFECTIVE
 * consents only, each with the on-time ratio of the member's public agreed
 * occurrences — exact ties shared, zero-denominator members neutral and
 * unranked, the week labeled provisional while it or any window is open,
 * and pending consents listed without a rank. This slice never computes a
 * ranking from the raw member list, and no Coins/contribution/private-task
 * data flows through it.
 */
export const useRankingStore = defineStore('ranking', {
	state: () => ({
		rankingEnabled: false,
		week: null,
		me: { state: 'none', generation: null, effectiveMonday: null },
		entries: [],
		pending: [],
		loading: false,
		error: null,
	}),

	actions: {
		/**
		 * Fetch the consent-filtered ranking view for the caller's house.
		 */
		async fetchRanking() {
			this.loading = true
			this.error = null
			try {
				const data = await api.get('/api/v1/ranking')
				this.applyView(data)
				return data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch ranking', error, { store: 'ranking', action: 'fetchRanking' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Give the current person's ranking consent (children included).
		 * The new generation activates next Monday — the response only
		 * refreshes the authorized ranking state.
		 */
		async giveConsent() {
			this.loading = true
			this.error = null
			try {
				const data = await api.post('/api/v1/ranking/consent')
				this.me = { ...this.me, ...data }
				await this.fetchRanking()
			} catch (error) {
				this.error = error.message
				logger.error('Failed to give ranking consent', error, { store: 'ranking', action: 'giveConsent' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Withdraw the current person's ranking consent. Removal is
		 * immediate; the generation is invalidated.
		 */
		async withdrawConsent() {
			this.loading = true
			this.error = null
			try {
				const data = await api.post('/api/v1/ranking/withdraw')
				this.me = { ...this.me, ...data }
				await this.fetchRanking()
			} catch (error) {
				this.error = error.message
				logger.error('Failed to withdraw ranking consent', error, { store: 'ranking', action: 'withdrawConsent' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Apply a server ranking view (shared by fetch and the consent
		 * mutations' re-read).
		 * @param {object} data Server view payload.
		 */
		applyView(data) {
			this.rankingEnabled = data.rankingEnabled
			this.week = data.week
			this.me = data.me
			this.entries = data.entries || []
			this.pending = data.pending || []
		},

		/**
		 * Clear error state.
		 */
		clearError() {
			this.error = null
		},

		/**
		 * Reset store state (identity/house switch).
		 */
		reset() {
			this.rankingEnabled = false
			this.week = null
			this.me = { state: 'none', generation: null, effectiveMonday: null }
			this.entries = []
			this.pending = []
			this.loading = false
			this.error = null
		},
	},
})
