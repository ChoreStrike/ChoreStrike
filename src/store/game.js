/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { logger } from '../utils/logger.js'

export const useGameStore = defineStore('game', {
	state: () => ({
		gameState: null,
		leaderboard: [],
		medals: [],
		monsters: {},
		combos: [],
		loading: false,
		error: null,
		// Cooperative features state
		householdProgress: {
			totalTeamSparks: 0,
			totalContributionScore: 0,
			teamStreak: 0,
			weeklyGoal: {
				target: 20, // Configurable weekly task goal
				completed: 0,
				startDate: null,
			},
		},
		celebrations: [],
	}),

	getters: {
		currentMonsters: (state) => (roomId) => {
			return state.monsters[roomId] || []
		},

		totalMonsters: (state) => {
			return Object.values(state.monsters).flat().length
		},

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
		 * Get household total Team Sparks (aggregated from all members)
		 * @param {object} userStore - The user store instance to aggregate from
		 */
		householdTotalTeamSparks: (state) => {
			return state.householdProgress.totalTeamSparks
		},

		/**
		 * Get household total contribution score
		 */
		householdTotalContribution: (state) => {
			return state.householdProgress.totalContributionScore
		},

		/**
		 * Get team streak (household-level streak)
		 */
		teamStreak: (state) => {
			return state.householdProgress.teamStreak
		},

		/**
		 * Get weekly goal progress percentage
		 */
		weeklyGoalProgress: (state) => {
			const { target, completed } = state.householdProgress.weeklyGoal
			return Math.min(Math.round((completed / target) * 100), 100)
		},

		/**
		 * Check if weekly goal is achieved
		 */
		isWeeklyGoalAchieved: (state) => {
			return state.householdProgress.weeklyGoal.completed >= state.householdProgress.weeklyGoal.target
		},

		/**
		 * Get pending celebrations
		 */
		pendingCelebrations: (state) => {
			return state.celebrations.filter(c => !c.dismissed)
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
				const response = await axios.get('/apps/chorestrike/api/v1/game/state')

				if (response.data.success) {
					this.gameState = response.data.data
					this.monsters = response.data.data.monsters || {}
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch game state')
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
				const response = await axios.get('/apps/chorestrike/api/v1/game/leaderboard')

				if (response.data.success) {
					this.leaderboard = response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch leaderboard')
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch leaderboard', error, { store: 'game', action: 'fetchLeaderboard' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch medals
		 */
		async fetchMedals() {
			this.loading = true
			this.error = null

			try {
				const response = await axios.get('/apps/chorestrike/api/v1/game/medals')

				if (response.data.success) {
					this.medals = response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch medals')
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
		 * Update monsters for a room
		 * @param {number} roomId - The ID of the room
		 * @param {Array} monsters - The monsters data for the room
		 */
		updateRoomMonsters(roomId, monsters) {
			this.monsters[roomId] = monsters
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
		 * @param {Array} houseUsers - Array of house user objects with contributionScore and teamSparks
		 */
		updateHouseholdProgress(houseUsers) {
			const totalTeamSparks = houseUsers.reduce((sum, user) => sum + (user.teamSparks || 0), 0)
			const totalContributionScore = houseUsers.reduce((sum, user) => sum + (user.contributionScore || 0), 0)

			this.householdProgress.totalTeamSparks = totalTeamSparks
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
		 * Increment weekly goal progress
		 * @param {number} increment - Number of tasks to add (default 1)
		 */
		incrementWeeklyGoal(increment = 1) {
			this.householdProgress.weeklyGoal.completed += increment

			// Check if goal was just achieved
			if (this.householdProgress.weeklyGoal.completed >= this.householdProgress.weeklyGoal.target
				&& this.householdProgress.weeklyGoal.completed - increment < this.householdProgress.weeklyGoal.target) {
				this.triggerCelebration('weekly_goal_achieved')
			}
		},

		/**
		 * Set weekly goal target
		 * @param {number} target - The new weekly goal target
		 */
		setWeeklyGoalTarget(target) {
			this.householdProgress.weeklyGoal.target = target
		},

		/**
		 * Reset weekly goal for new week
		 */
		resetWeeklyGoal() {
			const now = new Date()
			const weekStart = this.getWeekStart(now)
			this.householdProgress.weeklyGoal.completed = 0
			this.householdProgress.weeklyGoal.startDate = weekStart
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
		 * Calculate Team Sparks for task completion
		 * @param {object} task - The task object with energyCost and priority
		 * @param {boolean} isCombo - Whether this is part of a combo
		 */
		calculateTeamSparks(task, isCombo = false) {
			let sparks = task.energyCost * 5

			// Combo bonus
			if (isCombo) sparks *= 2

			// Priority bonus
			if (task.priority === 3) sparks += 10
			if (task.priority === 2) sparks += 5

			return Math.round(sparks)
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
		 * Award medal to user
		 * @param {string} userId - The ID of the user to award the medal to
		 * @param {string} medalType - The type of medal to award
		 * @param {number} value - The value associated with the medal
		 */
		awardMedal(userId, medalType, value) {
			const medal = {
				id: Date.now(),
				userId,
				medalType,
				value,
				awardedAt: new Date().toISOString(),
				weekStart: this.getWeekStart(new Date()),
			}

			this.medals.push(medal)
			return medal
		},

		/**
		 * Get week start date
		 * @param {Date|string} date - The date to calculate week start from
		 */
		getWeekStart(date = new Date()) {
			const d = new Date(date)
			const day = d.getDay()
			const diff = d.getDate() - day + (day === 0 ? -6 : 1) // Adjust when day is Sunday
			return new Date(d.setDate(diff)).toISOString().split('T')[0]
		},

		/**
		 * Check medal eligibility
		 * @param {string} userId - The ID of the user to check
		 * @param {object} weeklyStats - Weekly statistics for the user
		 */
		checkMedalEligibility(userId, weeklyStats) {
			const medals = []

			// Team Anchor: Most tasks completed in a week
			if (weeklyStats.tasksCompleted >= 10) {
				medals.push(this.awardMedal(userId, 'team_anchor', weeklyStats.tasksCompleted))
			}

			// Flow Keeper: Consistent daily activity
			if (weeklyStats.activeDays >= 5) {
				medals.push(this.awardMedal(userId, 'flow_keeper', weeklyStats.activeDays))
			}

			// Room Guardian: Most room improvements
			if (weeklyStats.roomsImproved >= 3) {
				medals.push(this.awardMedal(userId, 'room_guardian', weeklyStats.roomsImproved))
			}

			// Team Player: Most collaborative tasks
			if (weeklyStats.collaborativeTasks >= 5) {
				medals.push(this.awardMedal(userId, 'team_player', weeklyStats.collaborativeTasks))
			}

			// Streak Keeper: Longest activity streak
			if (weeklyStats.currentStreak >= 7) {
				medals.push(this.awardMedal(userId, 'streak_keeper', weeklyStats.currentStreak))
			}

			return medals
		},

		/**
		 * Update monster count based on room cleanliness
		 * @param {number} roomId - The ID of the room
		 * @param {number} cleanliness - The cleanliness percentage of the room
		 */
		updateMonstersForCleanliness(roomId, cleanliness) {
			let monsterCount = 0

			if (cleanliness >= 80) {
				monsterCount = 0
			} else if (cleanliness >= 60) {
				monsterCount = 1
			} else if (cleanliness >= 40) {
				monsterCount = 2
			} else if (cleanliness >= 20) {
				monsterCount = 3
			} else {
				monsterCount = 4
			}

			const monsters = []
			for (let i = 0; i < monsterCount; i++) {
				monsters.push({
					id: `${roomId}-${i}`,
					type: this.getMonsterType(cleanliness),
					position: this.getRandomPosition(),
				})
			}

			this.updateRoomMonsters(roomId, monsters)
			return monsters
		},

		/**
		 * Get monster type based on cleanliness
		 * @param {number} cleanliness - The cleanliness percentage of the room
		 */
		getMonsterType(cleanliness) {
			if (cleanliness >= 60) return 'dust-bunny'
			if (cleanliness >= 40) return 'slime-blob'
			if (cleanliness >= 20) return 'pixel-spider'
			return 'dust-demon'
		},

		/**
		 * Get random position for monster
		 */
		getRandomPosition() {
			return {
				x: Math.random() * 100,
				y: Math.random() * 100,
			}
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
			this.monsters = {}
			this.combos = []
			this.loading = false
			this.error = null
			this.householdProgress = {
				totalTeamSparks: 0,
				totalContributionScore: 0,
				teamStreak: 0,
				weeklyGoal: {
					target: 20,
					completed: 0,
					startDate: null,
				},
			}
			this.celebrations = []
		},
	},
})
