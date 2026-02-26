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
			expect(store.monsters).toEqual({})
			expect(store.combos).toEqual([])
			expect(store.loading).toBe(false)
			expect(store.error).toBeNull()
		})
	})

	describe('Getters', () => {
		beforeEach(() => {
			store.monsters = {
				1: [{ id: 'm1', type: 'dust-bunny' }],
				2: [{ id: 'm2', type: 'slime-blob' }, { id: 'm3', type: 'pixel-spider' }],
			}
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

		it('currentMonsters returns monsters for room', () => {
			const roomMonsters = store.currentMonsters(2)
			expect(roomMonsters).toHaveLength(2)
		})

		it('currentMonsters returns empty array for room with no monsters', () => {
			const roomMonsters = store.currentMonsters(999)
			expect(roomMonsters).toEqual([])
		})

		it('totalMonsters counts all monsters', () => {
			expect(store.totalMonsters).toBe(3)
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
	})

	describe('Actions', () => {
		describe('fetchGameState', () => {
			it('fetches game state successfully', async () => {
				const mockState = { houseHealth: 85, monsters: { 1: [{ id: 'm1' }] } }
				axios.get.mockResolvedValue({
					data: { success: true, data: mockState }
				})

				await store.fetchGameState()

				expect(store.gameState).toEqual(mockState)
				expect(store.monsters).toEqual({ 1: [{ id: 'm1' }] })
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
				store.monsters = { 1: [{ id: 1 }] }
				store.combos = [{ id: 1 }]
				store.loading = true
				store.error = 'Error'

				store.reset()

				expect(store.gameState).toBeNull()
				expect(store.leaderboard).toEqual([])
				expect(store.medals).toEqual([])
				expect(store.monsters).toEqual({})
				expect(store.combos).toEqual([])
				expect(store.loading).toBe(false)
				expect(store.error).toBeNull()
			})
		})
	})
})
