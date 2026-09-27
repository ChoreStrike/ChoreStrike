/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import { api } from '../services/api.js'
import { emit } from '@nextcloud/event-bus'
import { logger } from '../utils/logger.js'

export const useGameStore = defineStore('game', {
	state: () => ({
		gameState: null,
		medals: [],
		// WP3 §3.6: the caller's OWN wallet (minor units) + exact
		// contribution + house state version ride the game state payload.
		wallet: null,
		contributionMinor: 0,
		stateVersion: 0,
		loading: false,
		error: null,
		// The weekly household goal snapshot returned by
		// GameController#state and CompletionService::complete. It mirrors
		// the HouseService::getWeeklyGoalProgress payload shape so the store
		// is populated directly from API responses without any client-side
		// arithmetic. The dead household-aggregation state (totalCoins /
		// totalContributionScore / teamStreak) and the leaderboard slice
		// were pruned in CS-PLAN-055 (no consumers).
		householdProgress: {
			weeklyGoal: {
				dueCount: 0,
				completedCount: 0,
				percent: 0,
				targetPercent: 80,
				achieved: false,
				weekStart: null,
				weekEnd: null,
			},
		},
		// CS-PLAN-066 (D5): week identity of the last hydrated weekly-goal
		// snapshot. A celebration is only allowed for a SAME-WEEK
		// false→true transition on a confirmed completion response — first
		// load, remount, unchanged refetch and week rollover hydrate
		// silently because the week is new or unchanged-but-already-known.
		weeklyGoalWeek: null,
		celebrations: [],
	}),

	getters: {
		/**
		 * Get the underlying weekly goal snapshot (dueCount, completedCount,
		 * percent, targetPercent, achieved, weekStart, weekEnd).
		 * @param {object} state Pinia store state
		 */
		weeklyGoal: (state) => {
			return state.householdProgress.weeklyGoal
		},
	},

	actions: {
		/**
		 * Fetch game state
		 */
		async fetchGameState() {
			this.loading = true
			this.error = null

			try {
				const data = await api.get('/api/v1/game/state')

				this.gameState = data
				// WP3: own wallet (minor units), exact contribution and the
				// house state version ride the state payload.
				if ('wallet' in data) {
					this.wallet = data.wallet
				}
				if (Number.isFinite(data.contributionMinor)) {
					this.contributionMinor = data.contributionMinor
				}
				if (Number.isFinite(data.stateVersion)) {
					this.stateVersion = data.stateVersion
				}

				// CS-PLAN-066 (D5): hydration — silent. First load, Dashboard
				// remount, unchanged refetch and week rollover store the
				// snapshot without manufacturing an achievement.
				if (data?.weeklyGoal) {
					this.setWeeklyGoal(data.weeklyGoal)
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch game state', error, { store: 'game', action: 'fetchGameState' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Trigger medal evaluation server-side.
		 *
		 * Best-effort pre-pass before fetchMedals(): asks the backend to
		 * evaluate and award any due weekly medals so the subsequent fetch
		 * surfaces them (and their celebrations). A failure here must not
		 * break the Leaderboard view — medals simply stay at their last
		 * known state.
		 */
		async checkMedals() {
			try {
				await api.post('/api/v1/game/check-medals')
			} catch (error) {
				logger.error('Failed to check medals', error, { store: 'game', action: 'checkMedals' })
			}
		},

		/**
		 * Fetch medals.
		 *
		 * Emits `chorestrike:medal-awarded` for every medal in the response
		 * that wasn't already in the previous snapshot — this is the
		 * frontend producer for the celebration + toast listeners that
		 * react to new medal awards. The medal payload from the server
		 * carries an `awardedAt` timestamp so a fresh fetch (e.g. after
		 * triggering `/api/v1/game/check-medals`) surfaces the new award
		 * even when the user opened the app mid-week.
		 */
		async fetchMedals() {
			this.loading = true
			this.error = null

			try {
				const data = await api.get('/api/v1/game/medals')

				const previousIds = new Set(this.medals.map(m => m.id))
				const newMedals = data.filter(m => !previousIds.has(m.id))

				this.medals = data

				if (previousIds.size > 0) {
					for (const medal of newMedals) {
						emit('chorestrike:medal-awarded', {
							medalType: medal.medalType,
							value: medal.value,
							weekStart: medal.weekStart,
						})
					}
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch medals', error, { store: 'game', action: 'fetchMedals' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Replace the weekly goal snapshot from a server payload.
		 *
		 * Source: GameController#state (`gameState.weeklyGoal`, hydration —
		 * silent) or CompletionService complete rewards
		 * (`rewards.weeklyGoal`, a confirmed transition — may celebrate).
		 *
		 * CS-PLAN-066 (D5): a celebration (`chorestrike:weekly-goal-reached`
		 * + local celebration entry) is emitted ONLY when the caller marks
		 * the payload as a confirmed completion transition AND a snapshot
		 * for the SAME week was already hydrated AND it flips achieved
		 * false→true. Hydration, remount, unchanged refetch, week rollover
		 * and undo never manufacture an achievement. No new persisted
		 * reward policy — week identity is client-side initialization state.
		 *
		 * @param {object} weeklyGoal Server payload shape:
		 *   `{ dueCount, completedCount, percent, targetPercent, achieved, weekStart, weekEnd }`
		 * @param {object} [options] Behavior switches for the caller type.
		 * @param {boolean} [options.celebrateOnTransition] True only for a
		 *   confirmed completion response (never a fetch/hydration).
		 */
		setWeeklyGoal(weeklyGoal, { celebrateOnTransition = false } = {}) {
			if (!weeklyGoal || typeof weeklyGoal !== 'object') {
				return
			}

			const previous = this.householdProgress.weeklyGoal
			const sameWeek = this.weeklyGoalWeek !== null
				&& this.weeklyGoalWeek === (weeklyGoal.weekStart ?? null)
			const wasAchieved = previous?.achieved === true
			const nowAchieved = weeklyGoal.achieved === true

			this.householdProgress.weeklyGoal = {
				dueCount: Number.isFinite(weeklyGoal.dueCount) ? weeklyGoal.dueCount : 0,
				completedCount: Number.isFinite(weeklyGoal.completedCount) ? weeklyGoal.completedCount : 0,
				percent: Number.isFinite(weeklyGoal.percent) ? weeklyGoal.percent : 0,
				targetPercent: Number.isFinite(weeklyGoal.targetPercent) ? weeklyGoal.targetPercent : 80,
				achieved: nowAchieved,
				weekStart: weeklyGoal.weekStart ?? null,
				weekEnd: weeklyGoal.weekEnd ?? null,
			}
			this.weeklyGoalWeek = weeklyGoal.weekStart ?? null

			if (celebrateOnTransition && sameWeek && !wasAchieved && nowAchieved) {
				this.triggerCelebration('weekly_goal_achieved')
				emit('chorestrike:weekly-goal-reached', {
					targetPercent: this.householdProgress.weeklyGoal.targetPercent,
					percent: this.householdProgress.weeklyGoal.percent,
					dueCount: this.householdProgress.weeklyGoal.dueCount,
					completedCount: this.householdProgress.weeklyGoal.completedCount,
				})
			}
		},

		/**
		 * Trigger a celebration event
		 * @param {string} type - The type of celebration
		 * @param {object} data - Additional data for the celebration
		 */
		triggerCelebration(type, data = {}) {
			const celebration = {
				id: Date.now(),
				type,
				data,
				timestamp: new Date().toISOString(),
				dismissed: false,
			}

			this.celebrations.push(celebration)
			return celebration
		},

		/**
		 * Dismiss a celebration
		 * @param {number} celebrationId - The ID of the celebration to dismiss
		 */
		dismissCelebration(celebrationId) {
			const celebration = this.celebrations.find(c => c.id === celebrationId)
			if (celebration) {
				celebration.dismissed = true
			}
		},

		/**
		 * Clear dismissed celebrations
		 */
		clearDismissedCelebrations() {
			this.celebrations = this.celebrations.filter(c => !c.dismissed)
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
			this.gameState = null
			this.medals = []
			this.loading = false
			this.error = null
			this.householdProgress = {
				weeklyGoal: {
					dueCount: 0,
					completedCount: 0,
					percent: 0,
					targetPercent: 80,
					achieved: false,
					weekStart: null,
					weekEnd: null,
				},
			}
			this.celebrations = []
			this.weeklyGoalWeek = null
		},
	},
})
