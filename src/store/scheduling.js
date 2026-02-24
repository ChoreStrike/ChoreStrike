import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'

/**
 * Store for managing member availability and scheduling preferences
 */
export const useSchedulingStore = defineStore('scheduling', {
	state: () => ({
		/** @type {Array} Member availability settings per weekday */
		memberAvailability: [],
		/** @type {object} Current user's energy budget per weekday */
		energyBudget: {
			monday: 10,
			tuesday: 10,
			wednesday: 10,
			thursday: 10,
			friday: 10,
			saturday: 15,
			sunday: 15,
		},
		/** @type {object} Current user's availability per weekday */
		weekdayAvailability: {
			monday: true,
			tuesday: true,
			wednesday: true,
			thursday: true,
			friday: true,
			saturday: true,
			sunday: true,
		},
		/** @type {boolean} Loading state */
		loading: false,
		/** @type {string|null} Error message */
		error: null,
	}),

	getters: {
		/**
		 * Get available weekdays for current user
		 * @param {object} state Store state
		 * @return {string[]} Array of available weekday names
		 */
		availableWeekdays: (state) => {
			return Object.entries(state.weekdayAvailability)
				.filter(([_, available]) => available)
				.map(([day]) => day)
		},

		/**
		 * Get unavailable weekdays for current user
		 * @param {object} state Store state
		 * @return {string[]} Array of unavailable weekday names
		 */
		unavailableWeekdays: (state) => {
			return Object.entries(state.weekdayAvailability)
				.filter(([_, available]) => !available)
				.map(([day]) => day)
		},

		/**
		 * Calculate total weekly energy budget
		 * @param {object} state Store state
		 * @return {number} Total energy points for the week
		 */
		totalWeeklyEnergy: (state) => {
			return Object.values(state.energyBudget).reduce((sum, energy) => sum + energy, 0)
		},

		/**
		 * Calculate average daily energy
		 * @param {object} state Store state
		 * @param {object} getters Store getters
		 * @return {number} Average energy per available day
		 */
		averageDailyEnergy: (state, getters) => {
			const availableDays = getters.availableWeekdays.length
			if (availableDays === 0) return 0
			return Math.round(getters.totalWeeklyEnergy / availableDays)
		},

		/**
		 * Check if user is available on a specific day
		 * @param {object} state Store state
		 * @return {(day: string) => boolean} Function that takes day name and returns availability
		 */
		isAvailableOn: (state) => (day) => {
			return state.weekdayAvailability[day.toLowerCase()] ?? true
		},

		/**
		 * Get energy budget for a specific day
		 * @param {object} state Store state
		 * @return {(day: string) => number} Function that takes day name and returns energy
		 */
		getEnergyForDay: (state) => (day) => {
			return state.energyBudget[day.toLowerCase()] ?? 10
		},

		/**
		 * Get remaining energy for today
		 * @param {object} state Store state
		 * @param {object} getters Store getters
		 * @return {number} Remaining energy points
		 */
		todayRemainingEnergy: (state, getters) => {
			const today = new Date().toLocaleDateString('en-US', { weekday: 'long' }).toLowerCase()
			const total = state.energyBudget[today] ?? 10
			// TODO: Subtract consumed energy from completed tasks
			return total
		},

		/**
		 * Get member availability by user ID
		 * @param {object} state Store state
		 * @return {(userId: string) => object|undefined} Function that takes userId and returns availability
		 */
		getMemberAvailability: (state) => (userId) => {
			return state.memberAvailability.find(m => m.userId === userId)
		},

		/**
		 * Get all available members for a specific day
		 * @param {object} state Store state
		 * @return {(day: string) => object[]} Function that takes day and returns available members
		 */
		getAvailableMembersForDay: (state) => (day) => {
			const dayKey = day.toLowerCase()
			return state.memberAvailability.filter(
				m => m.weekdayAvailability?.[dayKey] !== false,
			)
		},

		/**
		 * Calculate total available energy across all members for a day
		 * @param {object} state Store state
		 * @return {(day: string) => number} Function that takes day and returns total energy
		 */
		getTotalEnergyForDay: (state) => (day) => {
			const dayKey = day.toLowerCase()
			const availableMembers = state.memberAvailability.filter(
				m => m.weekdayAvailability?.[dayKey] !== false,
			)
			return availableMembers.reduce((sum, m) => {
				return sum + (m.energyBudget?.[dayKey] ?? 10)
			}, 0)
		},
	},

	actions: {
		/**
		 * Fetch current user's scheduling preferences
		 */
		async fetchUserPreferences() {
			this.loading = true
			this.error = null

			try {
				const response = await axios.get('/apps/chorestrike/api/v1/scheduling/preferences')

				if (response.data.success) {
					this.energyBudget = response.data.data.energyBudget || this.energyBudget
					this.weekdayAvailability = response.data.data.weekdayAvailability || this.weekdayAvailability
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch preferences')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to fetch scheduling preferences:', error)
				// Use defaults on error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Update energy budget for a specific day
		 * @param {string} day Weekday name (e.g., 'monday')
		 * @param {number} energy Energy points (1-20)
		 */
		async updateEnergyBudget(day, energy) {
			const dayKey = day.toLowerCase()
			if (energy < 1 || energy > 20) {
				throw new Error('Energy budget must be between 1 and 20')
			}

			this.loading = true
			this.error = null

			try {
				const response = await axios.put('/apps/chorestrike/api/v1/scheduling/energy', {
					day: dayKey,
					energy,
				})

				if (response.data.success) {
					this.energyBudget[dayKey] = energy
				} else {
					throw new Error(response.data.error?.message || 'Failed to update energy budget')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to update energy budget:', error)
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Update weekday availability
		 * @param {string} day Weekday name
		 * @param {boolean} available Whether user is available
		 */
		async updateAvailability(day, available) {
			const dayKey = day.toLowerCase()

			this.loading = true
			this.error = null

			try {
				const response = await axios.put('/apps/chorestrike/api/v1/scheduling/availability', {
					day: dayKey,
					available,
				})

				if (response.data.success) {
					this.weekdayAvailability[dayKey] = available
				} else {
					throw new Error(response.data.error?.message || 'Failed to update availability')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to update availability:', error)
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch all member availability (for house owners)
		 */
		async fetchMemberAvailability() {
			this.loading = true
			this.error = null

			try {
				const response = await axios.get('/apps/chorestrike/api/v1/scheduling/members')

				if (response.data.success) {
					this.memberAvailability = response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch member availability')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to fetch member availability:', error)
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Update another member's availability (admin only)
		 * @param {string} userId Member user ID
		 * @param {object} updates Availability and energy updates
		 */
		async updateMemberAvailability(userId, updates) {
			this.loading = true
			this.error = null

			try {
				const response = await axios.put(`/apps/chorestrike/api/v1/scheduling/members/${userId}`, updates)

				if (response.data.success) {
					// Update local member data
					const index = this.memberAvailability.findIndex(m => m.userId === userId)
					if (index >= 0) {
						this.memberAvailability[index] = {
							...this.memberAvailability[index],
							...updates,
						}
					}
				} else {
					throw new Error(response.data.error?.message || 'Failed to update member availability')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to update member availability:', error)
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Get scheduling suggestions for tasks
		 * @param {Array} tasks Tasks to schedule
		 */
		async getSchedulingSuggestions(tasks) {
			this.loading = true
			this.error = null

			try {
				const response = await axios.post('/apps/chorestrike/api/v1/scheduling/suggestions', {
					tasks,
				})

				if (response.data.success) {
					return response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to get suggestions')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to get scheduling suggestions:', error)
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Initialize scheduling data
		 */
		async initializeScheduling() {
			await Promise.all([
				this.fetchUserPreferences(),
				this.fetchMemberAvailability(),
			])
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
			this.memberAvailability = []
			this.energyBudget = {
				monday: 10,
				tuesday: 10,
				wednesday: 10,
				thursday: 10,
				friday: 10,
				saturday: 15,
				sunday: 15,
			}
			this.weekdayAvailability = {
				monday: true,
				tuesday: true,
				wednesday: true,
				thursday: true,
				friday: true,
				saturday: true,
				sunday: true,
			}
			this.loading = false
			this.error = null
		},
	},
})
