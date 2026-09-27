/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import { translate as t } from '@nextcloud/l10n'
import { api } from '../services/api.js'
import { logger } from '../utils/logger.js'

export const useHouseStore = defineStore('house', {
	state: () => ({
		house: null,
		loading: false,
		error: null,
		/** @type {object|null} Insights payload ({ house, stats }) from GET /house/stats */
		insights: null,
		/** @type {Array} Activity feed completions from GET /house/activity */
		activity: [],
		/** @type {boolean} Loading state of the activity fetch */
		activityLoading: false,
		/** @type {string|null} Error message of the activity fetch */
		activityError: null,
		// CS-PLAN-066 §3.3: request generations scoped per resource — a
		// stale house read can never overwrite newer navigation, mutation,
		// or health-refresh results, and reset() discards in-flight ones.
		houseRequestSeq: 0,
		insightsRequestSeq: 0,
		activityRequestSeq: 0,
		pendingResetCommand: null,
	}),

	getters: {
		houseHealth: (state) => state.house?.houseHealth ?? 100,
		houseName: (state) => state.house?.name || t('chorestrike', 'My House'),
		hasHouse: (state) => state.house !== null,
		/**
		 * Household timezone (CS-PLAN-057). UTC fallback while the house
		 * payload isn't loaded yet — day keys computed before the fetch
		 * resolves stay stable for UTC households and merely differ by the
		 * zone offset for others until the refetch.
		 *
		 * @param {object} state Pinia state
		 * @return {string} IANA timezone id of the household
		 */
		timezone: (state) => state.house?.timezone || 'UTC',
	},

	actions: {
		/**
		 * Fetch current house
		 */
		async fetchHouse() {
			const seq = ++this.houseRequestSeq
			this.loading = true
			this.error = null

			try {
				const data = await api.get('/api/v1/house')
				if (seq !== this.houseRequestSeq) {
					logger.debug('Dropping superseded house read', { store: 'house', action: 'fetchHouse' })
					return this.house
				}
				this.house = data
				return data
			} catch (error) {
				if (seq === this.houseRequestSeq) {
					this.error = error.message
				}
				logger.error('Failed to fetch house', error, { store: 'house', action: 'fetchHouse' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Update house name and optionally the timezone (CS-PLAN-057).
		 * @param {string} name New house name.
		 * @param {string|null} [timezone] New IANA timezone id (omitted/null = unchanged).
		 */
		async updateHouseName(name, timezone = null) {
			if (!name || name.trim() === '') {
				throw new Error('House name is required')
			}

			this.loading = true
			this.error = null

			try {
				const payload = { name }
				if (timezone) {
					payload.timezone = timezone
				}
				const data = await api.put('/api/v1/house', payload)
				// Accepted mutation invalidates pre-mutation house reads
				// (CS-PLAN-066 §3.3) — the authoritative response lands.
				this.houseRequestSeq++
				this.house = data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to update house', error, { store: 'house', action: 'updateHouseName' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the insights payload (house stats) for the Insights view.
		 * Replaces the never-consumed `fetchHouseStats` wrapper (CS-PLAN-040).
		 */
		async fetchInsights() {
			const seq = ++this.insightsRequestSeq
			this.loading = true
			this.error = null

			try {
				const data = await api.get('/api/v1/house/stats')
				if (seq !== this.insightsRequestSeq) {
					logger.debug('Dropping superseded insights read', { store: 'house', action: 'fetchInsights' })
					return this.insights
				}
				this.insights = data
				return data
			} catch (error) {
				if (seq === this.insightsRequestSeq) {
					this.error = error.message
				}
				logger.error('Failed to fetch house stats', error, { store: 'house', action: 'fetchInsights' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the house activity feed ("who did what when").
		 * @param {number} days Period length in days (1-90, default 30).
		 * @param {number} limit Maximum completions (1-200, default 50).
		 */
		async fetchActivity(days = 30, limit = 50) {
			const seq = ++this.activityRequestSeq
			this.activityLoading = true
			this.activityError = null

			try {
				const data = await api.get('/api/v1/house/activity', { params: { days, limit } })
				if (seq !== this.activityRequestSeq) {
					logger.debug('Dropping superseded activity read', { store: 'house', action: 'fetchActivity', days, limit })
					return data
				}
				this.activity = data.completions || []
				return data
			} catch (error) {
				if (seq === this.activityRequestSeq) {
					this.activityError = error.message
				}
				logger.error('Failed to fetch house activity', error, { store: 'house', action: 'fetchActivity', days, limit })
				throw error
			} finally {
				this.activityLoading = false
			}
		},

		/**
		 * Refresh the server-calculated house health (CS-PLAN-066 §3.2).
		 *
		 * The health value is authoritative from the server — never a
		 * client average of loaded rooms. The refresh shares the house
		 * generation: a newer full house read (or mutation) discards this
		 * result, and this refresh can never clobber fresher
		 * name/timezone fields — it patches ONLY `houseHealth` onto the
		 * currently loaded house payload.
		 */
		async calculateHouseHealth() {
			const seq = ++this.houseRequestSeq
			this.loading = true
			this.error = null

			try {
				const data = await api.get('/api/v1/house/health')

				if (seq !== this.houseRequestSeq) {
					logger.debug('Dropping superseded house-health refresh', { store: 'house', action: 'calculateHouseHealth' })
					return this.house?.houseHealth ?? null
				}

				if (this.house) {
					this.house = { ...this.house, houseHealth: data.houseHealth }
				}
				return data.houseHealth
			} catch (error) {
				if (seq === this.houseRequestSeq) {
					this.error = error.message
				}
				logger.error('Failed to calculate house health', error, { store: 'house', action: 'calculateHouseHealth' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Reset house to initial state
		 */
		async resetHouse() {
			this.loading = true
			this.error = null

			try {
				if (!this.pendingResetCommand) {
					if (!this.house) await this.fetchHouse()
					if (!Number.isInteger(this.house?.stateVersion)) {
						throw new Error('House state version is unavailable')
					}
					this.pendingResetCommand = {
						commandId: crypto.randomUUID(),
						expectedVersion: this.house.stateVersion,
					}
				}
				const data = await api.post('/api/v1/house/reset', this.pendingResetCommand)
				this.houseRequestSeq++
				this.house = data
				this.pendingResetCommand = null
			} catch (error) {
				// A timeout/5xx may mean the reset committed. Only a definitive
				// rejection allows a new command identity on the next attempt.
				if (error.status >= 400 && error.status < 500) this.pendingResetCommand = null
				this.error = error.message
				logger.error('Failed to reset house', error, { store: 'house', action: 'resetHouse' })
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
			// Invalidate every in-flight read before clearing (CS-PLAN-066
			// §3.3) — a late success can never land after the reset.
			this.houseRequestSeq++
			this.insightsRequestSeq++
			this.activityRequestSeq++
			this.house = null
			this.loading = false
			this.error = null
			this.insights = null
			this.activity = []
			this.pendingResetCommand = null
			this.activityLoading = false
			this.activityError = null
		},
	},
})
