/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import { emit } from '@nextcloud/event-bus'
import { api } from '../services/api.js'
import { logger } from '../utils/logger.js'
import { createDefaultEnergyBudget, resolveEnergyForDay } from '../utils/energyBudgets.js'
import { addDaysToKey, dateKeyInZone, todayKeyInZone, weekdayOfKey } from '../utils/dateKeys.js'
import { isMemberAvailable } from '../constants/scheduling.js'
import { useHouseStore } from './house.js'
import { useRoomTasksStore } from './roomTasks.js'
import { useTasksStore } from './tasks.js'

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
 * Store for managing member availability, scheduling preferences and the
 * owner plan aggregate (CS-PLAN-065): preview -> review/adjust -> apply.
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

		// Plan aggregate state (CS-PLAN-065 §3.4): idle → loading → ready |
		// empty | error; ready → applying → applied | refreshFailed; a 409
		// or an edited input marks the preview stale.
		/** @type {'idle'|'loading'|'ready'|'applying'|'applied'|'refreshFailed'|'stale'|'error'} */
		planStatus: 'idle',
		/** @type {object|null} The last preview payload (changes/unchanged/unplaced/capacity/warnings…) */
		planPreview: null,
		/** @type {object|null} The exact normalized input the preview was computed from */
		planInput: null,
		/** Monotonic request token — an older preview can never replace newer input */
		planRequestId: 0,
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
				// Decision D5: null (unset) means unlimited.
				this.maxDailyTasks = normalizeMaxDailyTasks(data.maxDailyTasks)
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch scheduling preferences', error, { store: 'scheduling', action: 'fetchUserPreferences' })
				throw error
			}
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
		 * Request a plan preview over the selected real room tasks
		 * (CS-PLAN-065 §3.1). Read-only on the server; the response carries
		 * the opaque `previewVersion` needed for the one apply call.
		 *
		 * Request sequencing (§3.4): an older in-flight preview can never
		 * replace the result of a newer input — only the latest request id
		 * may land.
		 *
		 * @param {object} input { startDate, roomTaskIds, overrides }
		 * @return {Promise<object|null>} The preview payload, or null when a newer request superseded this one
		 */
		async previewPlan(input) {
			const requestId = ++this.planRequestId
			this.planStatus = 'loading'
			this.error = null

			try {
				const data = await api.post('/api/v1/scheduling/suggestions', {
					startDate: input.startDate,
					roomTaskIds: input.roomTaskIds,
					...(input.overrides ? { overrides: input.overrides } : {}),
				})

				if (requestId !== this.planRequestId) {
					// A newer request superseded this response.
					return null
				}

				this.planPreview = data
				this.planInput = {
					startDate: input.startDate,
					roomTaskIds: [...input.roomTaskIds],
					overrides: input.overrides ? JSON.parse(JSON.stringify(input.overrides)) : {},
				}
				this.planStatus = 'ready'
				return data
			} catch (error) {
				if (requestId !== this.planRequestId) {
					return null
				}
				// Deliberately NOT the shared `error`: a plan failure must not
				// blank the whole scheduling view (SchedulingView renders its
				// error branch instead of the sections). The planner surface
				// carries its own status banner.
				this.planStatus = 'error'
				logger.error('Failed to preview the plan', error, { store: 'scheduling', action: 'previewPlan' })
				throw error
			}
		},

		/**
		 * Mark the current preview stale (an input edit or an external
		 * state change) — Apply is blocked until a fresh preview exists.
		 */
		markPlanStale() {
			if (this.planStatus === 'ready') {
				this.planStatus = 'stale'
			}
		},

		/**
		 * Apply the confirmed preview as one atomic batch and reconcile the
		 * owning stores from the authoritative response (§3.4): returned
		 * room tasks merge into the caches, then the active daily/overdue
		 * reads and the cached calendar range refetch BEFORE success is
		 * reported. A failed refresh is reported as "applied, refresh
		 * failed" — never as an unsuccessful transaction.
		 *
		 * @return {Promise<boolean>} Whether the apply succeeded (regardless of refresh outcome)
		 */
		async applyPlan() {
			if (!this.planPreview || !this.planInput || this.planStatus === 'applying') {
				return false
			}

			this.planStatus = 'applying'
			this.error = null

			try {
				const data = await api.post('/api/v1/scheduling/apply', {
					startDate: this.planInput.startDate,
					roomTaskIds: this.planInput.roomTaskIds,
					overrides: this.planInput.overrides,
					previewVersion: this.planPreview.previewVersion,
				})

				// Reconcile the returned room tasks into every cache slice.
				const roomTasksStore = useRoomTasksStore()
				for (const roomTask of data.roomTasks ?? []) {
					roomTasksStore.replaceRoomTaskInState(roomTask.id, roomTask)
				}

				const refreshed = await this.refreshAfterApply()
				this.planStatus = refreshed ? 'applied' : 'refreshFailed'
				if (!refreshed) {
					logger.warn('Plan applied but the schedule views could not be refreshed', { store: 'scheduling', action: 'applyPlan' })
				}
				emit('chorestrike:schedule-applied', {
					appliedCount: data.appliedCount,
					refreshFailed: !refreshed,
				})
				return true
			} catch (error) {
				if (error?.status === 409 || error?.response?.status === 409) {
					this.planStatus = 'stale'
				} else {
					this.planStatus = 'error'
				}
				logger.error('Failed to apply the plan', error, { store: 'scheduling', action: 'applyPlan' })
				throw error
			}
		},

		/**
		 * Refetch the scheduling-owned readers after a committed apply:
		 * filtered daily (My Day), overdue, and the cached calendar range
		 * window. The room-task store's request-order protection keeps a
		 * pre-apply response from overwriting the refreshed results.
		 *
		 * @return {Promise<boolean>} Whether every refetch succeeded
		 */
		async refreshAfterApply() {
			const roomTasksStore = useRoomTasksStore()
			try {
				const jobs = [
					roomTasksStore.fetchFilteredDailyTasks(),
					roomTasksStore.fetchOverdueTasks(),
				]
				const { from, to } = roomTasksStore.rangeTasks
				if (from && to) {
					jobs.push(roomTasksStore.fetchRangeTasks(from, to))
				}
				await Promise.all(jobs)
				return true
			} catch (error) {
				logger.error('Post-apply refresh failed', error, { store: 'scheduling', action: 'refreshAfterApply' })
				return false
			}
		},

		/**
		 * Reset the plan state (house/user change or explicit discard).
		 */
		resetPlan() {
			this.planRequestId++
			this.planStatus = 'idle'
			this.planPreview = null
			this.planInput = null
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
			this.resetPlan()
		},
	},
})
