/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { logger } from '../utils/logger.js'

export const useHouseStore = defineStore('house', {
	state: () => ({
		house: null,
		loading: false,
		error: null,
	}),

	getters: {
		houseHealth: (state) => state.house?.houseHealth || 100,
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
				const response = await axios.get('/apps/chorestrike/api/v1/house')

				if (response.data.success) {
					this.house = response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch house')
				}
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
				const response = await axios.put('/apps/chorestrike/api/v1/house', { name })

				if (response.data.success) {
					this.house = response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to update house')
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to update house', error, { store: 'house', action: 'updateHouseName' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Get house statistics
		 */
		async fetchHouseStats() {
			this.loading = true
			this.error = null

			try {
				const response = await axios.get('/apps/chorestrike/api/v1/house/stats')

				if (response.data.success) {
					return response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch house stats')
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch house stats', error, { store: 'house', action: 'fetchHouseStats' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Calculate and update house health
		 */
		async calculateHouseHealth() {
			this.loading = true
			this.error = null

			try {
				const response = await axios.get('/apps/chorestrike/api/v1/house/health')

				if (response.data.success) {
					// Update local house health
					if (this.house) {
						this.house.houseHealth = response.data.data.houseHealth
					}
					return response.data.data.houseHealth
				} else {
					throw new Error(response.data.error?.message || 'Failed to calculate house health')
				}
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
				const response = await axios.post('/apps/chorestrike/api/v1/house/reset')

				if (response.data.success) {
					this.house = response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to reset house')
				}
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
		},
	},
})
