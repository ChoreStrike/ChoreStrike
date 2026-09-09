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

				if (data?.weeklyGoal) {
					const previousAchieved = this.householdProgress.weeklyGoal?.achieved === true
					this.setWeeklyGoal(data.weeklyGoal)

					if (!previousAchieved && this.householdProgress.weeklyGoal.achieved) {
						const last = this.celebrations[this.celebrations.length - 1]
						if (last && last.type === 'weekly_goal_achieved') {
							last.dismissed = true
						}
					}
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
		 * Source: GameController#state (`gameState.weeklyGoal`) or
		 * CompletionService complete/undo rewards (`rewards.weeklyGoal`). On
		 * the not-achieved → achieved transition the store emits
		 * `chorestrike:weekly-goal-reached` so the celebration composable can
		 * fire confetti + sound. Detecting the transition here (rather than in
		 * the backend) keeps the server stateless about who has already seen
		 * the celebration.
		 *
		 * @param {object} weeklyGoal Server payload shape:
		 *   `{ dueCount, completedCount, percent, targetPercent, achieved, weekStart, weekEnd }`
		 */
		setWeeklyGoal(weeklyGoal) {
			if (!weeklyGoal || typeof weeklyGoal !== 'object') {
				return
			}

			const previous = this.householdProgress.weeklyGoal
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

			if (!wasAchieved && nowAchieved) {
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
		},
	},
})
