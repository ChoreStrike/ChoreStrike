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

// The leaderboard, combos and household-aggregation members were pruned in
// CS-PLAN-055 (no production consumers); their specs went with them.

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
			expect(store.medals).toEqual([])
			expect(store.loading).toBe(false)
			expect(store.error).toBeNull()
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

	it('exposes the weekly goal snapshot via the getter', () => {
		expect(store.weeklyGoal).toEqual(store.householdProgress.weeklyGoal)
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
				store.medals = [{ id: 1 }]
				store.loading = true
				store.error = 'Error'
				store.householdProgress.weeklyGoal.percent = 90
				store.householdProgress.weeklyGoal.achieved = true
				store.celebrations = [{ id: 1 }]

				store.reset()

				expect(store.gameState).toBeNull()
				expect(store.medals).toEqual([])
				expect(store.loading).toBe(false)
				expect(store.error).toBeNull()
				expect(store.householdProgress.weeklyGoal.percent).toBe(0)
				expect(store.householdProgress.weeklyGoal.achieved).toBe(false)
				expect(store.celebrations).toEqual([])
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
				// Clear the legitimate transition celebration so the
				// assertion below only checks the second call.
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
