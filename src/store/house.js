import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'

/**
 * Store for managing house data
 */
export const useHouseStore = defineStore('house', {
	/**
	 * Store state
	 * @typedef {object} HouseState
	 * @property {object|null} house Current house data
	 * @property {boolean} loading Loading state
	 * @property {string|null} error Error message
	 */
	state: () => ({
		/** @type {object|null} Current house data */
		house: null,
		/** @type {boolean} Loading state */
		loading: false,
		/** @type {string|null} Error message */
		error: null,
	}),

	/**
	 * Store getters
	 */
	getters: {
		/**
		 * Get house health percentage
		 * @param {HouseState} state Store state
		 * @return {number} House health (0-100)
		 */
		houseHealth: (state) => state.house?.houseHealth || 100,

		/**
		 * Get house name
		 * @param {HouseState} state Store state
		 * @return {string} House name
		 */
		houseName: (state) => state.house?.name || 'My House',

		/**
		 * Check if user has a house
		 * @param {object} state Store state
		 * @return {boolean} True if house exists
		 */
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
				console.error('Failed to fetch house:', error)
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
				console.error('Failed to update house:', error)
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
				console.error('Failed to fetch house stats:', error)
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
				console.error('Failed to calculate house health:', error)
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
				console.error('Failed to reset house:', error)
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
