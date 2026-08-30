/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import { translate as t } from '@nextcloud/l10n'
import { api } from '../services/api.js'
import { logger } from '../utils/logger.js'
import { createDefaultEnergyBudget, resolveEnergyForDay } from '../utils/energyBudgets.js'
import { addDaysToKey, dateKeyInZone, todayKeyInZone, weekdayOfKey } from '../utils/dateKeys.js'
import { isMemberAvailable, weekDays } from '../constants/scheduling.js'
import { useHouseStore } from './house.js'
import { useRoomTasksStore } from './roomTasks.js'
import { useTasksStore } from './tasks.js'

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
		energyBudget: createDefaultEnergyBudget(),
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
		 * Calculate total weekly energy budget
		 * @param {object} state Store state
		 * @return {number} Total energy points for the week
		 */
		totalWeeklyEnergy: (state) => {
			return Object.values(state.energyBudget).reduce((sum, energy) => sum + energy, 0)
		},

		/**
		 * Available energy capacity for a day (CS-PLAN-040).
		 *
		 * Member availability (owner-only fetch) when loaded; otherwise the
		 * current user's own preferences with the canonical weekend fallback —
		 * non-owners must still get a meaningful capacity, never 0-by-default.
		 *
		 * @param {object} state Store state
		 * @return {(day: string) => number} Function that takes a weekday key
		 */
		getCapacityForDay: (state) => (day) => {
			const dayKey = day.toLowerCase()
			if (state.memberAvailability.length > 0) {
				const availableMembers = state.memberAvailability.filter(
					m => isMemberAvailable(m, dayKey),
				)
				return availableMembers.reduce(
					(sum, m) => sum + resolveEnergyForDay(m.energyBudget, dayKey),
					0,
				)
			}

			if (state.weekdayAvailability[dayKey] === false) {
				return 0
			}
			return resolveEnergyForDay(state.energyBudget, dayKey)
		},

		/**
		 * Planned energy for a calendar day: Σ energyCost of active room
		 * tasks due that day, joined via the tasks store energy map
		 * (CS-PLAN-040 / FEAT-UX-022). The canonical planned-energy source.
		 * Day keys are house-local (CS-PLAN-057).
		 *
		 * @return {(dateKey: string) => number} Function that takes a house-local YYYY-MM-DD key
		 */
		plannedEnergyForDay: () => (dateKey) => {
			const roomTasksStore = useRoomTasksStore()
			const tasksStore = useTasksStore()
			const houseStore = useHouseStore()

			let total = 0
			for (const roomTask of roomTasksStore.roomTasks) {
				if (!roomTask.isActive || !roomTask.nextDue) continue
				if (dateKeyInZone(roomTask.nextDue, houseStore.timezone) !== dateKey) continue
				const task = tasksStore.getTaskById(roomTask.taskId)
				total += task?.energyCost ?? 0
			}
			return total
		},

		/**
		 * Days (next 7, starting today) whose planned energy exceeds the
		 * available capacity, each with the would-be-deferred tasks sorted
		 * LOWEST priority first — the locked overcapacity policy's warning
		 * duty (CS-PLAN-040 / FEAT-UX-022). Day keys are house-local
		 * (CS-PLAN-057).
		 *
		 * Pinia option-getters receive the store only — sibling getters are
		 * reached via `this`.
		 * @return {Array<{date: string, dayName: string, planned: number, capacity: number, deferred: Array<{id: number, title: string, energyCost: number, priority: number}>}>}
		 */
		overcapacityDays() {
			const roomTasksStore = useRoomTasksStore()
			const tasksStore = useTasksStore()
			const houseStore = useHouseStore()

			const dueByDay = {}
			for (const roomTask of roomTasksStore.roomTasks) {
				if (!roomTask.isActive || !roomTask.nextDue) continue
				const task = tasksStore.getTaskById(roomTask.taskId)
				if (!task) continue
				const dateKey = dateKeyInZone(roomTask.nextDue, houseStore.timezone)
				if (!dueByDay[dateKey]) {
					dueByDay[dateKey] = []
				}
				dueByDay[dateKey].push({
					id: roomTask.id,
					title: task.name,
					energyCost: task.energyCost ?? 0,
					priority: task.priority ?? 2,
				})
			}

			const todayKey = todayKeyInZone(houseStore.timezone)

			const overloaded = []
			for (let i = 0; i < 7; i++) {
				const dateKey = addDaysToKey(todayKey, i)
				if (!dateKey) break
				const items = dueByDay[dateKey]
				if (!items) continue

				const dayName = weekdayOfKey(dateKey)
				const planned = items.reduce((sum, item) => sum + item.energyCost, 0)
				const capacity = this.getCapacityForDay(dayName)
				if (planned <= capacity) continue

				// Highest priority first; what no longer fits is deferred.
				const ordered = [...items].sort((a, b) =>
					(b.priority - a.priority) || (b.energyCost - a.energyCost))
				let remaining = capacity
				const deferred = []
				for (const item of ordered) {
					if (item.energyCost <= remaining) {
						remaining -= item.energyCost
					} else {
						deferred.push(item)
					}
				}
				// Locked policy: explain which tasks are deferred first —
				// lowest priority first.
				deferred.sort((a, b) => (a.priority - b.priority) || (a.energyCost - b.energyCost))

				overloaded.push({ date: dateKey, dayName, planned, capacity, deferred })
			}

			return overloaded
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
		 * Save the current user's weekly preferences in one bulk request.
		 *
		 * Sections are optional (energy-only / availability-only saves are
		 * valid); each provided section must be a full week.
		 *
		 * Does not touch `loading` (callers manage their own saving state).
		 *
		 * @param {object} sections Sections to persist
		 * @param {object} [sections.weekdayAvailability] Full week of availability flags
		 * @param {object} [sections.energyBudget] Full week of energy budgets (1-20)
		 */
		async saveWeeklyPreferences({ weekdayAvailability = null, energyBudget = null } = {}) {
			const payload = {}
			if (weekdayAvailability !== null) payload.weekdayAvailability = weekdayAvailability
			if (energyBudget !== null) payload.energyBudget = energyBudget

			this.error = null

			try {
				const data = await api.put('/api/v1/scheduling/preferences', payload)

				this.weekdayAvailability = data.weekdayAvailability || this.weekdayAvailability
				this.energyBudget = data.energyBudget || this.energyBudget
			} catch (error) {
				this.error = error.message
				logger.error('Failed to save scheduling preferences', error, { store: 'scheduling', action: 'saveWeeklyPreferences' })
				throw error
			}
		},

		/**
		 * Fetch all member availability (for house owners).
		 *
		 * Does not touch `loading` — see `saveWeeklyPreferences`.
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
		 * Update another member's scheduling preferences (house owner only,
		 * UX-014). Sections are merged per-day by the backend.
		 *
		 * Does not touch `loading` — see `saveWeeklyPreferences`.
		 *
		 * @param {string} userId Member user ID
		 * @param {object} updates Partial preference sections keyed by weekday
		 */
		async updateMemberPreferences(userId, updates) {
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
				logger.error('Failed to update member preferences', error, { store: 'scheduling', action: 'updateMemberPreferences', userId })
				throw error
			}
		},

		/**
		 * Get scheduling suggestions for a number of schedulable tasks.
		 *
		 * The request carries only `taskCount` — the backend never used more
		 * than the task array's length (CR-016-13c). The response is
		 * normalized to an array of suggestion objects so the UI can iterate
		 * it directly. Does not touch `loading` — see `saveWeeklyPreferences`.
		 *
		 * @param {number} taskCount Number of tasks to schedule
		 * @return {Promise<Array>} One suggestion object per weekday
		 */
		async getSchedulingSuggestions(taskCount) {
			this.error = null

			try {
				const data = await api.post('/api/v1/scheduling/suggestions', {
					taskCount,
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
			return Object.entries(data ?? {}).map(([day, info]) => {
				const members = info.availableMembers ?? 0
				const energy = info.totalEnergy ?? 0
				const recommended = info.recommendedTasks ?? 0
				return {
					day,
					title: weekDays.find(d => d.key === day)?.name ?? day,
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
			this.energyBudget = createDefaultEnergyBudget()
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
