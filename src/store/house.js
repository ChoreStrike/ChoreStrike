/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
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
	}),

	getters: {
		houseHealth: (state) => state.house?.houseHealth ?? 100,
		houseName: (state) => state.house?.name || 'My House',
		hasHouse: (state) => state.house !== null,
	},

	actions: {
		/**
		 * Fetch current house
		 */
		async fetchHouse() {
			this.loading = true
			this.error = null

			try {
				const data = await api.get('/api/v1/house')
				this.house = data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch house', error, { store: 'house', action: 'fetchHouse' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Update house name
		 * @param {string} name New house name.
		 */
		async updateHouseName(name) {
			if (!name || name.trim() === '') {
				throw new Error('House name is required')
			}

			this.loading = true
			this.error = null

			try {
				const data = await api.put('/api/v1/house', { name })
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
			this.loading = true
			this.error = null

			try {
				const data = await api.get('/api/v1/house/stats')
				this.insights = data
				return data
			} catch (error) {
				this.error = error.message
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
			this.activityLoading = true
			this.activityError = null

			try {
				const data = await api.get('/api/v1/house/activity', { params: { days, limit } })
				this.activity = data.completions || []
				return data
			} catch (error) {
				this.activityError = error.message
				logger.error('Failed to fetch house activity', error, { store: 'house', action: 'fetchActivity', days, limit })
				throw error
			} finally {
				this.activityLoading = false
			}
		},

		/**
		 * Calculate and update house health
		 */
		async calculateHouseHealth() {
			this.loading = true
			this.error = null

			try {
				const data = await api.get('/api/v1/house/health')

				if (this.house) {
					this.house.houseHealth = data.houseHealth
				}
				return data.houseHealth
			} catch (error) {
				this.error = error.message
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
				const data = await api.post('/api/v1/house/reset')
				this.house = data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to reset house', error, { store: 'house', action: 'resetHouse' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Initialize house (fetch if not exists)
		 */
		async initializeHouse() {
			if (!this.house) {
				await this.fetchHouse()
			}
			return this.house
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
			this.house = null
			this.loading = false
			this.error = null
			this.insights = null
			this.activity = []
			this.activityLoading = false
			this.activityError = null
		},
	},
})
