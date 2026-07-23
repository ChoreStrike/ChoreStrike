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
		leaderboard: [],
		medals: [],
		combos: [],
		loading: false,
		error: null,
		// Cooperative features state.
		//
		// `weeklyGoal` is the server-driven weekly household goal snapshot
		// returned by GameController#state and RoomService::completeRoomTask.
		// It mirrors the HouseService::getWeeklyGoalProgress payload shape so
		// the store can be populated directly from API responses without any
		// client-side arithmetic. The legacy `{ target, completed, startDate }`
		// shape is gone (it never reflected reality — it only incremented
		// client-side with no denominator or weekly reset).
		householdProgress: {
			totalCoins: 0,
			totalContributionScore: 0,
			teamStreak: 0,
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
		userMedals: (state) => (userId) => {
			return state.medals.filter(medal => medal.userId === userId)
		},

		topContributors: (state) => {
			return state.leaderboard
				.sort((a, b) => b.contributionScore - a.contributionScore)
				.slice(0, 10)
		},

		hasActiveCombos: (state) => state.combos.length > 0,

		/**
		 * Get household total Coins (aggregated from all members)
		 * @param {object} userStore - The user store instance to aggregate from
		 * @param state
		 */
		householdTotalCoins: (state) => {
			return state.householdProgress.totalCoins
		},

		/**
		 * Get household total contribution score
		 * @param state
		 */
		householdTotalContribution: (state) => {
			return state.householdProgress.totalContributionScore
		},

		/**
		 * Get team streak (household-level streak)
		 * @param state
		 */
		teamStreak: (state) => {
			return state.householdProgress.teamStreak
		},

		/**
		 * Get weekly goal progress percentage (server-computed).
		 *
		 * Reads the server snapshot directly — no client-side math. Returns 0
		 * when no snapshot has been loaded yet.
		 * @param state
		 */
		weeklyGoalProgress: (state) => {
			return state.householdProgress.weeklyGoal.percent ?? 0
		},

		/**
		 * Check if weekly goal is achieved (server-computed).
		 * @param state
		 */
		isWeeklyGoalAchieved: (state) => {
			return state.householdProgress.weeklyGoal.achieved === true
		},

		/**
		 * Get the underlying weekly goal snapshot (dueCount, completedCount,
		 * percent, targetPercent, achieved, weekStart, weekEnd).
		 * @param state
		 */
		weeklyGoal: (state) => {
			return state.householdProgress.weeklyGoal
		},

		/**
		 * Get pending celebrations
		 * @param state
		 */
		pendingCelebrations: (state) => {
			return state.celebrations.filter(c => !c.dismissed)
		},

		/**
		 * Get streak freezes for a specific user from game state
		 * @param {object} state - Store state
		 * @return {function(number): number} Function that takes userId and returns freeze count
		 */
		userStreakFreezes: (state) => (nextcloudUserId) => {
			if (!state.gameState?.users) return 0
			const user = state.gameState.users.find(u => u.nextcloudUserId === nextcloudUserId)
			return user?.streakFreezes ?? 0
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
		 * Fetch leaderboard
		 */
		async fetchLeaderboard() {
			this.loading = true
			this.error = null

			try {
				const data = await api.get('/api/v1/game/leaderboard')
				this.leaderboard = data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch leaderboard', error, { store: 'game', action: 'fetchLeaderboard' })
				throw error
			} finally {
				this.loading = false
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
		 * Add combo
		 * @param {object} combo - The combo data to add
		 */
		addCombo(combo) {
			this.combos.push({
				...combo,
				id: Date.now(),
				timestamp: new Date().toISOString(),
			})
		},

		/**
		 * Remove combo
		 * @param {number} comboId - The ID of the combo to remove
		 */
		removeCombo(comboId) {
			this.combos = this.combos.filter(combo => combo.id !== comboId)
		},

		/**
		 * Clear combos
		 */
		clearCombos() {
			this.combos = []
		},

		/**
		 * Calculate contribution score for task completion
		 * @param {object} task - The task object with energyCost and priority
		 * @param {number} roomCleanliness - The current cleanliness percentage of the room
		 */
		calculateContributionScore(task, roomCleanliness) {
			let baseScore = task.energyCost * 10

			// Bonus for high priority tasks
			if (task.priority === 3) baseScore *= 1.5
			if (task.priority === 2) baseScore *= 1.2

			// Bonus for cleaning dirty rooms
			if (roomCleanliness < 40) baseScore *= 1.3
			if (roomCleanliness < 20) baseScore *= 1.5

			// Bonus for overdue tasks
			// This would be calculated based on room task due date

			return Math.round(baseScore)
		},

		/**
		 * Update household progress from house users data
		 * @param {Array} houseUsers - Array of house user objects with contributionScore and coins
		 */
		updateHouseholdProgress(houseUsers) {
			const totalCoins = houseUsers.reduce((sum, user) => sum + (user.coins || 0), 0)
			const totalContributionScore = houseUsers.reduce((sum, user) => sum + (user.contributionScore || 0), 0)

			this.householdProgress.totalCoins = totalCoins
			this.householdProgress.totalContributionScore = totalContributionScore
		},

		/**
		 * Update team streak
		 * @param {number} streak - The household streak count
		 */
		updateTeamStreak(streak) {
			this.householdProgress.teamStreak = streak
		},

		/**
		 * Replace the weekly goal snapshot from a server payload.
		 *
		 * Source: GameController#state (`gameState.weeklyGoal`) or
		 * RoomService::completeRoomTask rewards (`rewards.weeklyGoal`). On the
		 * not-achieved → achieved transition the store emits
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
		 * Calculate Coins for task completion
		 * @param {object} task - The task object with energyCost and priority
		 * @param {boolean} isCombo - Whether this is part of a combo
		 */
		calculateCoins(task, isCombo = false) {
			let coins = task.energyCost * 5

			// Combo bonus
			if (isCombo) coins *= 2

			// Priority bonus
			if (task.priority === 3) coins += 10
			if (task.priority === 2) coins += 5

			return Math.round(coins)
		},

		/**
		 * Check for combo opportunities
		 * @param {string} userId - The ID of the user completing the task
		 * @param {object} completedTask - The task that was just completed
		 * @param {Array} recentCompletions - Recent task completions to check for combos
		 */
		checkForCombo(userId, completedTask, recentCompletions = []) {
			// Check if user completed multiple tasks in quick succession
			const userRecentCompletions = recentCompletions.filter(
				completion => completion.userId === userId,
			)

			// If user completed 2+ tasks in last 5 minutes, it's a combo
			const fiveMinutesAgo = new Date(Date.now() - 5 * 60 * 1000)
			const recentCount = userRecentCompletions.filter(
				completion => new Date(completion.timestamp) > fiveMinutesAgo,
			).length

			if (recentCount >= 2) {
				return {
					type: 'speed',
					multiplier: recentCount,
					description: `${recentCount} tasks completed in 5 minutes!`,
				}
			}

			// Check for room cleaning combo (multiple tasks in same room)
			const roomCompletions = recentCompletions.filter(
				completion => completion.roomId === completedTask.roomId,
			)

			if (roomCompletions.length >= 2) {
				return {
					type: 'room',
					multiplier: roomCompletions.length + 1,
					description: `Room cleaning combo! ${roomCompletions.length + 1} tasks in same room`,
				}
			}

			return null
		},

		/**
		 * Initialize game data
		 */
		async initializeGame() {
			await Promise.all([
				this.fetchGameState(),
				this.fetchLeaderboard(),
				this.fetchMedals(),
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
			this.gameState = null
			this.leaderboard = []
			this.medals = []
			this.combos = []
			this.loading = false
			this.error = null
			this.householdProgress = {
				totalCoins: 0,
				totalContributionScore: 0,
				teamStreak: 0,
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
