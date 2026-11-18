/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import { isMemberAvailable } from '../constants/scheduling.js'
import { api } from '../services/api.js'
import { addDaysToKey, todayKeyInZone, weekdayOfKey } from '../utils/dateKeys.js'
import { createDefaultEnergyBudget, resolveEnergyForDay } from '../utils/energyBudgets.js'
import { logger } from '../utils/logger.js'
import { plannedDayKey } from '../utils/taskDisplay.js'
import { useHouseStore } from './house.js'
import { useRoomTasksStore } from './roomTasks.js'
import { useTasksStore } from './tasks.js'
import { useUserStore } from './user.js'

/**
 * Null or finite number → the internal limit representation (Infinity = unlimited).
 *
 * @param {number|null} value The API limit value
 * @return {number} The finite limit or Infinity
 */
function normalizeMaxDailyTasks(value) {
	return Number.isFinite(value) ? value : Infinity
}

/**
 * Store for managing member availability and scheduling preferences. The
 * applied plan went with ADR-022: nothing is saved as an agreement any
 * more; the live suggestion of CS-PLAN-091 WP-C reads these preferences.
 */
export const useSchedulingStore = defineStore('scheduling', {
	state: () => ({
		/** @type {Array} Member availability settings per weekday */
		memberAvailability: [],
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
		// Monotonic read token and the read in flight (RF-090-37): an older
		// preferences read can never overwrite a newer read or an accepted
		// save, so an unsaved form edit is never replaced by a stale answer.
		preferencesRequestSeq: 0,
		preferencesRequest: null,
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
		getCapacityForDay(state) {
			return (day) => {
				const dayKey = day.toLowerCase()
				if (state.memberAvailability.length > 0) {
					const availableMembers = state.memberAvailability.filter((m) => isMemberAvailable(m, dayKey))
					return availableMembers.reduce(
						(sum, m) => sum + resolveEnergyForDay(m.energyBudget, dayKey),
						0,
					)
				}
				return this.getOwnCapacityForDay(dayKey)
			}
		},

		/**
		 * The current user's own energy capacity for a day, whatever the
		 * owner's household read holds: Today compares a member's own work
		 * with it.
		 *
		 * @param {object} state Store state
		 * @return {(day: string) => number} Function that takes a weekday key
		 */
		getOwnCapacityForDay: (state) => (day) => {
			const dayKey = day.toLowerCase()
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
				if (!roomTask.isActive || plannedDayKey(roomTask, houseStore.timezone) !== dateKey) {
					continue
				}
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
				const dateKey = roomTask.isActive ? plannedDayKey(roomTask, houseStore.timezone) : ''
				if (!dateKey) {
					continue
				}
				const task = tasksStore.getTaskById(roomTask.taskId)
				if (!task) {
					continue
				}
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
				if (!dateKey) {
					break
				}
				const items = dueByDay[dateKey]
				if (!items) {
					continue
				}

				const dayName = weekdayOfKey(dateKey)
				const planned = items.reduce((sum, item) => sum + item.energyCost, 0)
				const capacity = this.getCapacityForDay(dayName)
				if (planned <= capacity) {
					continue
				}

				// Highest priority first; what no longer fits is deferred.
				const ordered = [...items].sort((a, b) => (b.priority - a.priority) || (b.energyCost - a.energyCost))
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
		 * An answer that a newer read or a save overtook is dropped, and its
		 * caller waits for the winning read instead (RF-090-37).
		 */
		fetchUserPreferences() {
			const seq = ++this.preferencesRequestSeq
			this.error = null

			const request = (async () => {
				try {
					const data = await api.get('/api/v1/scheduling/preferences')
					if (seq !== this.preferencesRequestSeq) {
						// Wait for the winning read when one is still in flight.
						return this.preferencesRequest !== request ? this.preferencesRequest : null
					}
					this.energyBudget = data.energyBudget || this.energyBudget
					this.weekdayAvailability = data.weekdayAvailability || this.weekdayAvailability
					// Decision D5: null (unset) means unlimited.
					this.maxDailyTasks = normalizeMaxDailyTasks(data.maxDailyTasks)
				} catch (error) {
					if (seq === this.preferencesRequestSeq) {
						this.error = error.message
					}
					logger.error('Failed to fetch scheduling preferences', error, { store: 'scheduling', action: 'fetchUserPreferences' })
					throw error
				} finally {
					if (this.preferencesRequest === request) {
						this.preferencesRequest = null
					}
				}
			})()
			this.preferencesRequest = request
			return request
		},

		/**
		 * Save the current user's weekly preferences in one bulk request.
		 *
		 * Sections are optional (energy-only / availability-only saves are
		 * valid); each provided section must be a full week. The child
		 * daily-task limit is owner-only and never part of this payload
		 * (decision D5).
		 *
		 * Does not touch `loading` (callers manage their own saving state).
		 *
		 * @param {object} sections Sections to persist
		 * @param {object} [sections.weekdayAvailability] Full week of availability flags
		 * @param {object} [sections.energyBudget] Full week of energy budgets (1-20)
		 */
		async saveWeeklyPreferences({ weekdayAvailability = null, energyBudget = null } = {}) {
			const payload = {}
			if (weekdayAvailability !== null) {
				payload.weekdayAvailability = weekdayAvailability
			}
			if (energyBudget !== null) {
				payload.energyBudget = energyBudget
			}

			this.error = null

			try {
				const data = await api.put('/api/v1/scheduling/preferences', payload)

				// An accepted save invalidates every read that started before it.
				this.preferencesRequestSeq++
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
		 * UX-014). Sections are merged per-day by the backend; the optional
		 * `maxDailyTasks` child limit rides the same owner-only endpoint
		 * (decision D5): omitted = unchanged, `null` = unlimited, 1-100 =
		 * finite bound.
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

				const index = this.memberAvailability.findIndex((m) => m.userId === userId)
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
		 * Initialize scheduling data.
		 *
		 * Runs sequentially: preferences (member-gated, required) first so a
		 * failure surfaces to the view; member availability (owner-only) is
		 * fetched best-effort for owners. The role is awaited, because a
		 * reload lands here before the current user has resolved.
		 */
		async initializeScheduling() {
			this.loading = true
			this.error = null

			try {
				await this.fetchUserPreferences()

				// Member availability is owner-only: any other role would only
				// write an expected refusal into the server log (TECH-LOG-001).
				const userStore = useUserStore()
				// A failed read is logged by the store and leaves the role unknown.
				await userStore.initializeCurrentUser().catch(() => null)
				if (!userStore.isHouseOwner) {
					return
				}
				try {
					await this.fetchMemberAvailability()
				} catch {
					// Non-critical: a transient failure must not block the view —
					// the owner can still edit their own preferences. The action logs
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
			// A late read of the previous identity can never land after the reset.
			this.preferencesRequestSeq++
			this.preferencesRequest = null
			this.memberAvailability = []
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
