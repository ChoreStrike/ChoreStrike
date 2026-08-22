import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'
import { useGameStore } from '../../../src/store/game.js'

jest.mock('../../../src/services/api.js', () => ({
	api: { get: jest.fn(), post: jest.fn() },
}))

jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

import { api } from '../../../src/services/api.js'

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
			// Weekly goal snapshot starts empty (server has not been queried
			// yet); defaults match the server payload shape.
			expect(store.householdProgress.weeklyGoal).toEqual({
				dueCount: 0,
				completedCount: 0,
				percent: 0,
				targetPercent: 80,
				achieved: false,
				weekStart: null,
				weekEnd: null,
			})
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

		it('weeklyGoalProgress returns server snapshot percent', () => {
			store.setWeeklyGoal({
				dueCount: 10,
				completedCount: 5,
				percent: 50,
				targetPercent: 80,
				achieved: false,
				weekStart: null,
				weekEnd: null,
			})
			expect(store.weeklyGoalProgress).toBe(50)
		})

		it('weeklyGoalProgress defaults to 0 without a snapshot', () => {
			expect(store.weeklyGoalProgress).toBe(0)
		})

		it('isWeeklyGoalAchieved returns true when achieved flag set', () => {
			store.setWeeklyGoal({
				dueCount: 5,
				completedCount: 5,
				percent: 100,
				targetPercent: 80,
				achieved: true,
				weekStart: null,
				weekEnd: null,
			})
			expect(store.isWeeklyGoalAchieved).toBe(true)
		})

		it('isWeeklyGoalAchieved returns false when not achieved', () => {
			store.setWeeklyGoal({
				dueCount: 5,
				completedCount: 3,
				percent: 60,
				targetPercent: 80,
				achieved: false,
				weekStart: null,
				weekEnd: null,
			})
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
				api.get.mockResolvedValue(mockState)

				await store.fetchGameState()

				expect(store.gameState).toEqual(mockState)
			})

			it('hydrates weekly goal snapshot from the response', async () => {
				const mockState = {
					houseHealth: 85,
					weeklyGoal: {
						dueCount: 5,
						completedCount: 4,
						percent: 80,
						targetPercent: 80,
						achieved: true,
						weekStart: '2026-07-13T00:00:00+00:00',
						weekEnd: '2026-07-19T23:59:59+00:00',
					},
				}
				api.get.mockResolvedValue(mockState)

				await store.fetchGameState()

				expect(store.householdProgress.weeklyGoal.dueCount).toBe(5)
				expect(store.householdProgress.weeklyGoal.completedCount).toBe(4)
				expect(store.householdProgress.weeklyGoal.percent).toBe(80)
				expect(store.householdProgress.weeklyGoal.achieved).toBe(true)
			})

			it('does not celebrate a previously-achieved goal on initial load', async () => {
				const mockState = {
					houseHealth: 85,
					weeklyGoal: {
						dueCount: 5,
						completedCount: 5,
						percent: 100,
						targetPercent: 80,
						achieved: true,
						weekStart: null,
						weekEnd: null,
					},
				}
				api.get.mockResolvedValue(mockState)

				await store.fetchGameState()

				// The transition celebration is suppressed (dismissed) so the
				// user is not greeted by a stale toast for a goal that was
				// already achieved in a previous session.
				const weeklyCelebration = store.celebrations.find(c => c.type === 'weekly_goal_achieved')
				expect(weeklyCelebration).toBeDefined()
				expect(weeklyCelebration.dismissed).toBe(true)
			})

			it('handles error', async () => {
				api.get.mockRejectedValue(new Error('Failed'))

				await expect(store.fetchGameState()).rejects.toThrow('Failed')
			})
		})

		describe('fetchLeaderboard', () => {
			it('fetches leaderboard successfully', async () => {
				const mockLeaderboard = [{ id: 1, name: 'User 1', contributionScore: 100 }]
				api.get.mockResolvedValue(mockLeaderboard)

				await store.fetchLeaderboard()

				expect(store.leaderboard).toEqual(mockLeaderboard)
			})
		})

		describe('fetchMedals', () => {
			it('fetches medals successfully', async () => {
				const mockMedals = [{ id: 1, medalType: 'team_anchor' }]
				api.get.mockResolvedValue(mockMedals)

				await store.fetchMedals()

				expect(store.medals).toEqual(mockMedals)
			})
		})

		describe('checkMedals', () => {
			it('posts the check-medals endpoint', async () => {
				api.post.mockResolvedValue({ awardedMedals: [], count: 0 })

				await store.checkMedals()

				expect(api.post).toHaveBeenCalledWith('/api/v1/game/check-medals')
			})

			it('swallows failures so the Leaderboard view keeps working', async () => {
				api.post.mockRejectedValue(new Error('Offline'))

				await expect(store.checkMedals()).resolves.toBeUndefined()

				expect(store.error).toBeNull()
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
				store.householdProgress.totalCoins = 500
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
				expect(store.householdProgress.weeklyGoal.percent).toBe(0)
				expect(store.householdProgress.weeklyGoal.achieved).toBe(false)
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

		describe('setWeeklyGoal', () => {
			it('stores the server snapshot verbatim', () => {
				store.setWeeklyGoal({
					dueCount: 8,
					completedCount: 4,
					percent: 50,
					targetPercent: 75,
					achieved: false,
					weekStart: '2026-07-13T00:00:00+00:00',
					weekEnd: '2026-07-19T23:59:59+00:00',
				})

				expect(store.householdProgress.weeklyGoal.dueCount).toBe(8)
				expect(store.householdProgress.weeklyGoal.completedCount).toBe(4)
				expect(store.householdProgress.weeklyGoal.percent).toBe(50)
				expect(store.householdProgress.weeklyGoal.targetPercent).toBe(75)
				expect(store.householdProgress.weeklyGoal.achieved).toBe(false)
				expect(store.householdProgress.weeklyGoal.weekStart).toBe('2026-07-13T00:00:00+00:00')
				expect(store.householdProgress.weeklyGoal.weekEnd).toBe('2026-07-19T23:59:59+00:00')
			})

			it('triggers celebration on the not-achieved → achieved transition', () => {
				store.setWeeklyGoal({
					dueCount: 5,
					completedCount: 3,
					percent: 60,
					targetPercent: 80,
					achieved: false,
					weekStart: null,
					weekEnd: null,
				})

				store.setWeeklyGoal({
					dueCount: 5,
					completedCount: 4,
					percent: 80,
					targetPercent: 80,
					achieved: true,
					weekStart: null,
					weekEnd: null,
				})

				expect(store.celebrations).toHaveLength(1)
				expect(store.celebrations[0].type).toBe('weekly_goal_achieved')
			})

			it('does not trigger celebration when already achieved', () => {
				// Seed the store as already-achieved so subsequent updates do
				// not re-emit the celebration. (The fresh store starts at
				// achieved=false; calling setWeeklyGoal with achieved=true
				// once would emit a celebration — that path is covered above.)
				store.setWeeklyGoal({
					dueCount: 5,
					completedCount: 5,
					percent: 100,
					targetPercent: 80,
					achieved: true,
					weekStart: null,
					weekEnd: null,
				})
				// Pop and dismiss the legitimate transition celebration so
				// the assertion below only checks the second call.
				store.celebrations = []

				// Subsequent update, still achieved — no new celebration.
				store.setWeeklyGoal({
					dueCount: 6,
					completedCount: 6,
					percent: 100,
					targetPercent: 80,
					achieved: true,
					weekStart: null,
					weekEnd: null,
				})

				expect(store.celebrations).toHaveLength(0)
			})

			it('ignores non-object payloads gracefully', () => {
				store.setWeeklyGoal(null)
				store.setWeeklyGoal(undefined)
				store.setWeeklyGoal('not-an-object')

				// Initial defaults remain untouched.
				expect(store.householdProgress.weeklyGoal.percent).toBe(0)
				expect(store.householdProgress.weeklyGoal.achieved).toBe(false)
			})

			it('coerces missing numeric fields to safe defaults', () => {
				store.setWeeklyGoal({
					achieved: true,
					// All numeric fields omitted.
				})

				expect(store.householdProgress.weeklyGoal.dueCount).toBe(0)
				expect(store.householdProgress.weeklyGoal.completedCount).toBe(0)
				expect(store.householdProgress.weeklyGoal.percent).toBe(0)
				expect(store.householdProgress.weeklyGoal.targetPercent).toBe(80)
				expect(store.householdProgress.weeklyGoal.achieved).toBe(true)
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