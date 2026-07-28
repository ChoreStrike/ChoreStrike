/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import { translate as t } from '@nextcloud/l10n'
import { api } from '../services/api.js'
import { logger } from '../utils/logger.js'

/**
 * Store for managing member availability and scheduling preferences
 */
export const useSchedulingStore = defineStore('scheduling', {
	state: () => ({
		/** @type {Array} Member availability settings per weekday */
		memberAvailability: [],
		/** @type {number} Energy consumed by current user today */
		todayConsumedEnergy: 0,
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
		/** @type {number} Maximum daily tasks for current user (child role limit) */
		maxDailyTasks: Infinity,
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
			return Math.max(0, total - state.todayConsumedEnergy)
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
		 * Fetch current user's scheduling preferences.
		 *
		 * Does not touch `loading`: the initial-page-load flag is owned by
		 * `initializeScheduling` so per-action calls never unmount the view.
		 */
		async fetchUserPreferences() {
			this.error = null

			try {
				const data = await api.get('/api/v1/scheduling/preferences')

				this.energyBudget = data.energyBudget || this.energyBudget
				this.weekdayAvailability = data.weekdayAvailability || this.weekdayAvailability
				this.maxDailyTasks = Number.isFinite(data.maxDailyTasks)
					? data.maxDailyTasks
					: Infinity
				this.todayConsumedEnergy = Number.isFinite(data.todayConsumedEnergy)
					? data.todayConsumedEnergy
					: 0
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch scheduling preferences', error, { store: 'scheduling', action: 'fetchUserPreferences' })
				throw error
			}
		},

		/**
		 * Register consumed energy for today after a completed task.
		 * @param {number} energyCost Energy points consumed by the completed task.
		 */
		registerTodayEnergyConsumption(energyCost) {
			if (!Number.isFinite(energyCost) || energyCost <= 0) {
				return
			}

			this.todayConsumedEnergy += energyCost
		},

		/**
		 * Update energy budget for a specific day.
		 *
		 * Does not touch `loading` (callers manage their own saving state);
		 * toggling the shared flag per call unmounted the scheduling view
		 * mid-save-loop.
		 *
		 * @param {string} day Weekday name (e.g., 'monday')
		 * @param {number} energy Energy points (1-20)
		 */
		async updateEnergyBudget(day, energy) {
			const dayKey = day.toLowerCase()
			if (energy < 1 || energy > 20) {
				throw new Error('Energy budget must be between 1 and 20')
			}

			this.error = null

			try {
				await api.put('/api/v1/scheduling/energy', {
					day: dayKey,
					energy,
				})

				this.energyBudget[dayKey] = energy
			} catch (error) {
				this.error = error.message
				logger.error('Failed to update energy budget', error, { store: 'scheduling', action: 'updateEnergyBudget', day: dayKey })
				throw error
			}
		},

		/**
		 * Update weekday availability.
		 *
		 * Does not touch `loading` — see `updateEnergyBudget`.
		 *
		 * @param {string} day Weekday name
		 * @param {boolean} available Whether user is available
		 */
		async updateAvailability(day, available) {
			const dayKey = day.toLowerCase()

			this.error = null

			try {
				await api.put('/api/v1/scheduling/availability', {
					day: dayKey,
					available,
				})

				this.weekdayAvailability[dayKey] = available
			} catch (error) {
				this.error = error.message
				logger.error('Failed to update availability', error, { store: 'scheduling', action: 'updateAvailability', day: dayKey })
				throw error
			}
		},

		/**
		 * Fetch all member availability (for house owners).
		 *
		 * Does not touch `loading` — see `updateEnergyBudget`.
		 */
		async fetchMemberAvailability() {
			this.error = null

			try {
				const data = await api.get('/api/v1/scheduling/members')
				this.memberAvailability = data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch member availability', error, { store: 'scheduling', action: 'fetchMemberAvailability' })
				throw error
			}
		},

		/**
		 * Update another member's availability (house owner only).
		 *
		 * Does not touch `loading` — see `updateEnergyBudget`.
		 *
		 * @param {string} userId Member user ID
		 * @param {object} updates Availability and energy updates
		 */
		async updateMemberAvailability(userId, updates) {
			this.error = null

			try {
				await api.put(`/api/v1/scheduling/members/${userId}`, updates)

				const index = this.memberAvailability.findIndex(m => m.userId === userId)
				if (index >= 0) {
					this.memberAvailability[index] = {
						...this.memberAvailability[index],
						...updates,
					}
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to update member availability', error, { store: 'scheduling', action: 'updateMemberAvailability', userId })
				throw error
			}
		},

		/**
		 * Get scheduling suggestions for tasks.
		 *
		 * The backend returns an object keyed by weekday; this action
		 * normalizes it to an array of suggestion objects so the UI components
		 * can iterate it directly. Does not touch `loading` — see
		 * `updateEnergyBudget`.
		 *
		 * @param {Array} tasks Tasks to schedule
		 * @return {Promise<Array>} One suggestion object per weekday
		 */
		async getSchedulingSuggestions(tasks) {
			this.error = null

			try {
				const data = await api.post('/api/v1/scheduling/suggestions', {
					tasks,
				})

				return this.normalizeDaySuggestions(data)
			} catch (error) {
				this.error = error.message
				logger.error('Failed to get scheduling suggestions', error, { store: 'scheduling', action: 'getSchedulingSuggestions' })
				throw error
			}
		},

		/**
		 * Normalize the backend's weekday-keyed suggestions payload into an
		 * array of display-ready suggestion objects.
		 *
		 * @param {object} data Backend payload keyed by weekday name
		 * @return {Array} One suggestion object per weekday
		 */
		normalizeDaySuggestions(data) {
			const dayNames = {
				monday: t('chorestrike', 'Monday'),
				tuesday: t('chorestrike', 'Tuesday'),
				wednesday: t('chorestrike', 'Wednesday'),
				thursday: t('chorestrike', 'Thursday'),
				friday: t('chorestrike', 'Friday'),
				saturday: t('chorestrike', 'Saturday'),
				sunday: t('chorestrike', 'Sunday'),
			}

			return Object.entries(data ?? {}).map(([day, info]) => {
				const members = info.availableMembers ?? 0
				const energy = info.totalEnergy ?? 0
				const recommended = info.recommendedTasks ?? 0
				return {
					day,
					title: dayNames[day] ?? day,
					availableMembers: members,
					totalEnergy: energy,
					recommendedTasks: recommended,
					description: t('chorestrike', '{members} members available, {energy} energy, up to {tasks} tasks', {
						members,
						energy,
						tasks: recommended,
					}),
				}
			})
		},

		/**
		 * Initialize scheduling data.
		 *
		 * Runs sequentially: preferences (member-gated, required) first so a
		 * failure surfaces to the view; member availability (owner-only) is
		 * fetched best-effort — for non-owners it 403s, which is expected and
		 * must not error the whole view.
		 */
		async initializeScheduling() {
			this.loading = true
			this.error = null

			try {
				await this.fetchUserPreferences()

				try {
					await this.fetchMemberAvailability()
				} catch (error) {
					// Member availability is owner-only and non-critical. A 403 for
					// non-owners (or any transient failure) must not block the view —
					// the user can still edit their own preferences. The action logs
					// the failure; clear the action's error so the view stays usable.
					this.error = null
				}
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
			this.memberAvailability = []
			this.todayConsumedEnergy = 0
			this.maxDailyTasks = Infinity
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
