import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'
import { useGameStore } from '../../../src/store/game.js'

// Mock axios
jest.mock('@nextcloud/axios', () => ({
	get: jest.fn(),
	post: jest.fn(),
}))

import axios from '@nextcloud/axios'

describe('Game Store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useGameStore()
		jest.clearAllMocks()
	})

	describe('State', () => {
		it('has correct initial state', () => {
			expect(store.gameState).toBeNull()
			expect(store.leaderboard).toEqual([])
			expect(store.medals).toEqual([])
			expect(store.combos).toEqual([])
			expect(store.loading).toBe(false)
			expect(store.error).toBeNull()
			// Cooperative features state
			expect(store.householdProgress.totalCoins).toBe(0)
			expect(store.householdProgress.totalContributionScore).toBe(0)
			expect(store.householdProgress.teamStreak).toBe(0)
			expect(store.householdProgress.weeklyGoal.target).toBe(20)
			expect(store.householdProgress.weeklyGoal.completed).toBe(0)
			expect(store.celebrations).toEqual([])
		})
	})

	describe('Getters', () => {
		beforeEach(() => {
			store.medals = [
				{ id: 1, userId: 1, medalType: 'team_anchor' },
				{ id: 2, userId: 1, medalType: 'flow_keeper' },
				{ id: 3, userId: 2, medalType: 'room_guardian' },
			]
			store.leaderboard = [
				{ id: 1, contributionScore: 500 },
				{ id: 2, contributionScore: 300 },
				{ id: 3, contributionScore: 700 },
			]
			store.combos = [{ id: 1, type: 'speed' }]
		})

		it('userMedals filters by user id', () => {
			const user1Medals = store.userMedals(1)
			expect(user1Medals).toHaveLength(2)
		})

		it('topContributors sorts and limits to 10', () => {
			const top = store.topContributors
			expect(top[0].contributionScore).toBe(700)
			expect(top[1].contributionScore).toBe(500)
			expect(top[2].contributionScore).toBe(300)
		})

		it('hasActiveCombos returns true when combos exist', () => {
			expect(store.hasActiveCombos).toBe(true)
		})

		it('hasActiveCombos returns false when no combos', () => {
			store.combos = []
			expect(store.hasActiveCombos).toBe(false)
		})

		// Cooperative features getters
		it('householdTotalCoins returns total', () => {
			store.householdProgress.totalCoins = 500
			expect(store.householdTotalCoins).toBe(500)
		})

		it('teamStreak returns streak count', () => {
			store.householdProgress.teamStreak = 7
			expect(store.teamStreak).toBe(7)
		})

		it('weeklyGoalProgress calculates percentage', () => {
			store.householdProgress.weeklyGoal.target = 20
			store.householdProgress.weeklyGoal.completed = 10
			expect(store.weeklyGoalProgress).toBe(50)
		})

		it('weeklyGoalProgress caps at 100', () => {
			store.householdProgress.weeklyGoal.target = 20
			store.householdProgress.weeklyGoal.completed = 25
			expect(store.weeklyGoalProgress).toBe(100)
		})

		it('isWeeklyGoalAchieved returns true when goal met', () => {
			store.householdProgress.weeklyGoal.target = 20
			store.householdProgress.weeklyGoal.completed = 20
			expect(store.isWeeklyGoalAchieved).toBe(true)
		})

		it('isWeeklyGoalAchieved returns false when goal not met', () => {
			store.householdProgress.weeklyGoal.target = 20
			store.householdProgress.weeklyGoal.completed = 15
			expect(store.isWeeklyGoalAchieved).toBe(false)
		})

		it('pendingCelebrations returns undismissed celebrations', () => {
			store.celebrations = [
				{ id: 1, type: 'weekly_goal_achieved', dismissed: false },
				{ id: 2, type: 'team_streak_milestone', dismissed: true },
			]
			expect(store.pendingCelebrations).toHaveLength(1)
			expect(store.pendingCelebrations[0].id).toBe(1)
		})
	})

	describe('Actions', () => {
		describe('fetchGameState', () => {
			it('fetches game state successfully', async () => {
				const mockState = { houseHealth: 85, users: [{ id: 'm1' }] }
				axios.get.mockResolvedValue({
					data: { success: true, data: mockState }
				})

				await store.fetchGameState()

				expect(store.gameState).toEqual(mockState)
			})

			it('handles error', async () => {
				axios.get.mockResolvedValue({
					data: { success: false, error: { message: 'Failed' } }
				})

				await expect(store.fetchGameState()).rejects.toThrow('Failed')
			})
		})

		describe('fetchLeaderboard', () => {
			it('fetches leaderboard successfully', async () => {
				const mockLeaderboard = [{ id: 1, name: 'User 1', contributionScore: 100 }]
				axios.get.mockResolvedValue({
					data: { success: true, data: mockLeaderboard }
				})

				await store.fetchLeaderboard()

				expect(store.leaderboard).toEqual(mockLeaderboard)
			})
		})

		describe('fetchMedals', () => {
			it('fetches medals successfully', async () => {
				const mockMedals = [{ id: 1, medalType: 'team_anchor' }]
				axios.get.mockResolvedValue({
					data: { success: true, data: mockMedals }
				})

				await store.fetchMedals()

				expect(store.medals).toEqual(mockMedals)
			})
		})

		describe('addCombo', () => {
			it('adds combo to list', () => {
				store.addCombo({ id: 1, type: 'speed', multiplier: 2 })
				expect(store.combos).toHaveLength(1)
				expect(store.combos[0].type).toBe('speed')
			})
		})

		describe('removeCombo', () => {
			it('removes combo by id', () => {
				store.combos = [{ id: 1 }, { id: 2 }, { id: 3 }]
				store.removeCombo(2)
				expect(store.combos).toHaveLength(2)
				expect(store.combos.find(c => c.id === 2)).toBeUndefined()
			})
		})

		describe('clearError', () => {
			it('clears error', () => {
				store.error = 'Some error'
				store.clearError()
				expect(store.error).toBeNull()
			})
		})

		describe('reset', () => {
			it('resets all state', () => {
				store.gameState = { id: 1 }
				store.leaderboard = [{ id: 1 }]
				store.medals = [{ id: 1 }]
				store.combos = [{ id: 1 }]
				store.loading = true
				store.error = 'Error'
				store.householdProgress.totalTeamSparks = 500
				store.householdProgress.teamStreak = 5
				store.celebrations = [{ id: 1 }]

				store.reset()

				expect(store.gameState).toBeNull()
				expect(store.leaderboard).toEqual([])
				expect(store.medals).toEqual([])
				expect(store.combos).toEqual([])
				expect(store.loading).toBe(false)
				expect(store.error).toBeNull()
				expect(store.householdProgress.totalCoins).toBe(0)
				expect(store.householdProgress.teamStreak).toBe(0)
				expect(store.celebrations).toEqual([])
			})
		})

		// Cooperative features actions
		describe('updateHouseholdProgress', () => {
			it('aggregates coins and contribution from users', () => {
				const houseUsers = [
					{ coins: 100, contributionScore: 200 },
					{ coins: 150, contributionScore: 300 },
					{ coins: 50, contributionScore: 100 },
				]
				store.updateHouseholdProgress(houseUsers)
				expect(store.householdProgress.totalCoins).toBe(300)
				expect(store.householdProgress.totalContributionScore).toBe(600)
			})

			it('handles users with missing values', () => {
				const houseUsers = [
					{ coins: 100 },
					{ contributionScore: 200 },
					{},
				]
				store.updateHouseholdProgress(houseUsers)
				expect(store.householdProgress.totalCoins).toBe(100)
				expect(store.householdProgress.totalContributionScore).toBe(200)
			})
		})

		describe('updateTeamStreak', () => {
			it('updates team streak value', () => {
				store.updateTeamStreak(7)
				expect(store.householdProgress.teamStreak).toBe(7)
			})
		})

		describe('incrementWeeklyGoal', () => {
			it('increments completed count', () => {
				store.householdProgress.weeklyGoal.completed = 5
				store.incrementWeeklyGoal()
				expect(store.householdProgress.weeklyGoal.completed).toBe(6)
			})

			it('triggers celebration when goal achieved', () => {
				store.householdProgress.weeklyGoal.target = 3
				store.householdProgress.weeklyGoal.completed = 2
				store.incrementWeeklyGoal()
				expect(store.celebrations).toHaveLength(1)
				expect(store.celebrations[0].type).toBe('weekly_goal_achieved')
			})

			it('does not trigger celebration if already achieved', () => {
				store.householdProgress.weeklyGoal.target = 3
				store.householdProgress.weeklyGoal.completed = 5
				store.incrementWeeklyGoal()
				expect(store.celebrations).toHaveLength(0)
			})
		})

		describe('setWeeklyGoalTarget', () => {
			it('sets new goal target', () => {
				store.setWeeklyGoalTarget(30)
				expect(store.householdProgress.weeklyGoal.target).toBe(30)
			})
		})

		describe('resetWeeklyGoal', () => {
			it('resets completed count and sets start date', () => {
				store.householdProgress.weeklyGoal.completed = 15
				store.resetWeeklyGoal()
				expect(store.householdProgress.weeklyGoal.completed).toBe(0)
				expect(store.householdProgress.weeklyGoal.startDate).not.toBeNull()
			})
		})

		describe('triggerCelebration', () => {
			it('creates a celebration event', () => {
				const celebration = store.triggerCelebration('team_streak_milestone', { streak: 7 })
				expect(store.celebrations).toHaveLength(1)
				expect(celebration.type).toBe('team_streak_milestone')
				expect(celebration.data.streak).toBe(7)
				expect(celebration.dismissed).toBe(false)
			})
		})

		describe('dismissCelebration', () => {
			it('marks celebration as dismissed', () => {
				store.celebrations = [{ id: 1, dismissed: false }]
				store.dismissCelebration(1)
				expect(store.celebrations[0].dismissed).toBe(true)
			})
		})

		describe('clearDismissedCelebrations', () => {
			it('removes dismissed celebrations', () => {
				store.celebrations = [
					{ id: 1, dismissed: true },
					{ id: 2, dismissed: false },
				]
				store.clearDismissedCelebrations()
				expect(store.celebrations).toHaveLength(1)
				expect(store.celebrations[0].id).toBe(2)
			})
		})
	})
})
