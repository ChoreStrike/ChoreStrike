/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import { api } from '../services/api.js'
import { createDefaultEnergyBudget, resolveEnergyForDay } from '../utils/energyBudgets.js'
import { logger } from '../utils/logger.js'

/** The suggestion of no days: what the calendar shows before its read. */
function emptySuggestion() {
	return { days: {}, overdue: [], loads: {} }
}

/**
 * Store of the scheduling preferences (availability, energy and preferred
 * days per weekday) and of the live suggestion that the server computes
 * from them on read (ADR-022, CS-PLAN-091 WP-C). Nothing is applied or
 * saved as an agreement.
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
		/** @type {string[]} Current user's preferred weekdays; the suggestion fills them first */
		preferredWeekdays: [],
		/**
		 * The suggestion of the last range read: per house-local day its
		 * entries, the overdue work that counts against today, and per day
		 * the load of each member the viewer may read, by user id.
		 * @type {{days: object, overdue: Array, loads: object}}
		 */
		suggestion: emptySuggestion(),
		// Monotonic read token: an older range read never replaces a newer one.
		suggestionRequestSeq: 0,
		// Monotonic read token and the read in flight (RF-090-37): an older
		// preferences read can never overwrite a newer read or an accepted
		// save, so an unsaved form edit is never replaced by a stale answer.
		preferencesRequestSeq: 0,
		preferencesRequest: null,
	}),

	getters: {
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
	},

	actions: {
		/**
		 * Fetch current user's scheduling preferences. An answer that a
		 * newer read or a save overtook is dropped, and its caller waits for
		 * the winning read instead (RF-090-37).
		 */
		fetchUserPreferences() {
			const seq = ++this.preferencesRequestSeq

			const request = (async () => {
				try {
					const data = await api.get('/api/v1/scheduling/preferences')
					if (seq !== this.preferencesRequestSeq) {
						// Wait for the winning read when one is still in flight.
						return this.preferencesRequest !== request ? this.preferencesRequest : null
					}
					this.energyBudget = data.energyBudget || this.energyBudget
					this.weekdayAvailability = data.weekdayAvailability || this.weekdayAvailability
					this.preferredWeekdays = data.preferredWeekdays || []
				} catch (error) {
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
		 * valid); availability and energy must each be a full week, the
		 * preferred days a list of weekday keys, possibly empty. The child
		 * daily-task limit is owner-only and never part of this payload
		 * (decision D5). Callers manage their own saving state.
		 *
		 * @param {object} sections Sections to persist
		 * @param {object} [sections.weekdayAvailability] Full week of availability flags
		 * @param {object} [sections.energyBudget] Full week of energy budgets (1-20)
		 * @param {string[]} [sections.preferredWeekdays] Preferred weekday keys
		 */
		async saveWeeklyPreferences({ weekdayAvailability = null, energyBudget = null, preferredWeekdays = null } = {}) {
			const payload = {}
			if (weekdayAvailability !== null) {
				payload.weekdayAvailability = weekdayAvailability
			}
			if (energyBudget !== null) {
				payload.energyBudget = energyBudget
			}
			if (preferredWeekdays !== null) {
				payload.preferredWeekdays = preferredWeekdays
			}

			try {
				const data = await api.put('/api/v1/scheduling/preferences', payload)

				// An accepted save invalidates every read that started before it.
				this.preferencesRequestSeq++
				this.weekdayAvailability = data.weekdayAvailability || this.weekdayAvailability
				this.energyBudget = data.energyBudget || this.energyBudget
				this.preferredWeekdays = data.preferredWeekdays || []
			} catch (error) {
				logger.error('Failed to save scheduling preferences', error, { store: 'scheduling', action: 'saveWeeklyPreferences' })
				throw error
			}
		},

		/**
		 * Fetch all member availability (for house owners).
		 */
		async fetchMemberAvailability() {
			try {
				const data = await api.get('/api/v1/scheduling/members')
				this.memberAvailability = data
			} catch (error) {
				logger.error('Failed to fetch member availability', error, { store: 'scheduling', action: 'fetchMemberAvailability' })
				throw error
			}
		},

		/**
		 * Update another member's scheduling preferences (house owner only,
		 * UX-014). Sections are merged per-day by the backend; the optional
		 * `maxDailyTasks` child limit rides the same owner-only endpoint
		 * (decision D5): omitted = unchanged, `null` = unlimited, 1-100 =
		 * finite bound. `preferredWeekdays` replaces the member's list.
		 *
		 * @param {string} userId Member user ID
		 * @param {object} updates Partial preference sections keyed by weekday
		 */
		async updateMemberPreferences(userId, updates) {
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
				logger.error('Failed to update member preferences', error, { store: 'scheduling', action: 'updateMemberPreferences', userId })
				throw error
			}
		},

		/**
		 * Read the live suggestion for a range of house-local days: from
		 * today, at most the 14-day horizon (ADR-022 decision 6). An answer
		 * that a newer read overtook is dropped.
		 *
		 * @param {string} from First day (`YYYY-MM-DD`)
		 * @param {string} to Last day (`YYYY-MM-DD`)
		 * @return {Promise<boolean>} False when a newer read replaced this one
		 */
		async fetchSuggestion(from, to) {
			const seq = ++this.suggestionRequestSeq
			try {
				const data = await api.get('/api/v1/scheduling/suggestion', { params: { from, to } })
				if (seq !== this.suggestionRequestSeq) {
					return false
				}
				const loads = {}
				for (const row of data.members ?? []) {
					(loads[row.day] ??= {})[row.userId] = row
				}
				this.suggestion = {
					days: Object.fromEntries((data.days ?? []).map((day) => [day.day, day.entries])),
					overdue: data.overdue ?? [],
					loads,
				}
				return true
			} catch (error) {
				logger.error('Failed to fetch the suggestion', error, { store: 'scheduling', action: 'fetchSuggestion' })
				throw error
			}
		},

		/** Forget the suggestion: the calendar shows a range without one. */
		clearSuggestion() {
			this.suggestionRequestSeq++
			this.suggestion = emptySuggestion()
		},

		/**
		 * Reset store state
		 */
		reset() {
			// A late read of the previous identity can never land after the reset.
			this.preferencesRequestSeq++
			this.preferencesRequest = null
			this.memberAvailability = []
			this.preferredWeekdays = []
			this.suggestionRequestSeq++
			this.suggestion = emptySuggestion()
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
		},
	},
})
