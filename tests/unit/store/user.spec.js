/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, beforeEach, jest } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'
import { useUserStore } from '../../../src/store/user.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: {
		get: jest.fn(),
		post: jest.fn(),
		put: jest.fn(),
		delete: jest.fn(),
	},
}))

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, message) => message),
}))

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'

describe('User Store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useUserStore()
		jest.clearAllMocks()
	})

	describe('Initial State', () => {
		it('has correct initial state', () => {
			expect(store.currentUser).toBeNull()
			expect(store.houseUsers).toEqual([])
			expect(store.loading).toBe(false)
			expect(store.error).toBeNull()
		})
	})

	describe('Getters', () => {
		it('userId returns current user id', () => {
			store.currentUser = { id: 1, displayName: 'John' }
			expect(store.userId).toBe(1)
		})

		it('userId returns null when no current user', () => {
			expect(store.userId).toBeNull()
		})

		it('userDisplayName returns display name', () => {
			store.currentUser = { id: 1, displayName: 'John Doe' }
			expect(store.userDisplayName).toBe('John Doe')
		})

		it('userDisplayName returns Unknown User when no current user', () => {
			expect(store.userDisplayName).toBe('Unknown User')
			expect(t).toHaveBeenCalledWith('chorestrike', 'Unknown User')
		})

		it('isHouseOwner returns true for owner role', () => {
			store.currentUser = { id: 1, role: 'owner' }
			expect(store.isHouseOwner).toBe(true)
		})

		it('isHouseOwner returns false for member role', () => {
			store.currentUser = { id: 1, role: 'member' }
			expect(store.isHouseOwner).toBe(false)
		})

		it('isHouseMember returns true for owner', () => {
			store.currentUser = { id: 1, role: 'owner' }
			expect(store.isHouseMember).toBe(true)
		})

		it('isHouseMember returns true for member', () => {
			store.currentUser = { id: 1, role: 'member' }
			expect(store.isHouseMember).toBe(true)
		})

		it('isHouseMember returns false for other roles', () => {
			store.currentUser = { id: 1, role: 'guest' }
			expect(store.isHouseMember).toBe(false)
		})

		it('contributionScore returns score', () => {
			store.currentUser = { id: 1, contributionScore: 100 }
			expect(store.contributionScore).toBe(100)
		})

		it('teamSparks returns sparks', () => {
			store.currentUser = { id: 1, teamSparks: 50 }
			expect(store.teamSparks).toBe(50)
		})

		it('currentStreak returns streak', () => {
			store.currentUser = { id: 1, currentStreak: 5 }
			expect(store.currentStreak).toBe(5)
		})

		it('getUserById finds user by nextcloudUserId', () => {
			store.houseUsers = [
				{ id: 1, nextcloudUserId: 'user1', displayName: 'User 1' },
				{ id: 2, nextcloudUserId: 'user2', displayName: 'User 2' },
			]
			const user = store.getUserById('user2')
			expect(user.displayName).toBe('User 2')
		})

		it('getLeaderboard sorts by contribution score', () => {
			store.houseUsers = [
				{ id: 1, contributionScore: 50 },
				{ id: 2, contributionScore: 100 },
				{ id: 3, contributionScore: 75 },
			]
			const leaderboard = store.getLeaderboard
			expect(leaderboard[0].id).toBe(2)
			expect(leaderboard[1].id).toBe(3)
			expect(leaderboard[2].id).toBe(1)
		})

		it('getTopContributors returns top 5', () => {
			store.houseUsers = [
				{ id: 1, contributionScore: 10 },
				{ id: 2, contributionScore: 20 },
				{ id: 3, contributionScore: 30 },
				{ id: 4, contributionScore: 40 },
				{ id: 5, contributionScore: 50 },
				{ id: 6, contributionScore: 60 },
			]
			const top = store.getTopContributors
			expect(top).toHaveLength(5)
			expect(top[0].id).toBe(6)
		})

		it('canPerformAction validates permissions', () => {
			store.houseUsers = [
				{ id: 1, nextcloudUserId: 'user1', role: 'owner' },
				{ id: 2, nextcloudUserId: 'user2', role: 'member' },
				{ id: 3, nextcloudUserId: 'user3', role: 'child' },
			]
			expect(store.canPerformAction('user1', 'manage_users')).toBe(true)
			expect(store.canPerformAction('user2', 'manage_users')).toBe(false)
			expect(store.canPerformAction('user2', 'manage_house')).toBe(true)
			expect(store.canPerformAction('user3', 'complete_tasks')).toBe(true)
		})
	})

	describe('Actions', () => {
		describe('initializeCurrentUser', () => {
			it('fetches and sets current user', async () => {
				const mockUser = { id: 1, displayName: 'John' }
				axios.get.mockResolvedValue({
					data: { success: true, data: mockUser }
				})

				const result = await store.initializeCurrentUser()

				expect(result).toEqual(mockUser)
				expect(store.currentUser).toEqual(mockUser)
			})

			it('uses localized fallback for initializeCurrentUser error', async () => {
				axios.get.mockResolvedValue({
					data: { success: false, error: {} }
				})

				await expect(store.initializeCurrentUser()).rejects.toThrow('Failed to initialize current user')
				expect(t).toHaveBeenCalledWith('chorestrike', 'Failed to initialize current user')
			})
		})

		describe('fetchHouseUsers', () => {
			it('fetches house users', async () => {
				const mockUsers = [{ id: 1 }, { id: 2 }]
				axios.get.mockResolvedValue({
					data: { success: true, data: mockUsers }
				})

				await store.fetchHouseUsers()

				expect(store.houseUsers).toEqual(mockUsers)
			})
		})

		describe('inviteUser', () => {
			it('invites user successfully', async () => {
				const result = { exists: false }
				axios.post.mockResolvedValue({
					data: { success: true, data: result }
				})

				await store.inviteUser('test@test.com', 'member')

				expect(axios.post).toHaveBeenCalledWith('/apps/chorestrike/api/v1/users/invite', { email: 'test@test.com', role: 'member' })
			})

			it('throws error for invalid role', async () => {
				await expect(store.inviteUser('test@test.com', 'invalid')).rejects.toThrow('Invalid role')
				expect(t).toHaveBeenCalledWith('chorestrike', 'Invalid role')
			})
		})

		describe('updateUserStats', () => {
			it('updates user statistics', () => {
				store.houseUsers = [{ id: 1, contributionScore: 50 }]
				store.updateUserStats(1, { contributionScore: 75 })
				expect(store.houseUsers[0].contributionScore).toBe(75)
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
				store.currentUser = { id: 1 }
				store.houseUsers = [{ id: 1 }]
				store.loading = true
				store.error = 'Error'

				store.reset()

				expect(store.currentUser).toBeNull()
				expect(store.houseUsers).toEqual([])
				expect(store.loading).toBe(false)
				expect(store.error).toBeNull()
			})
		})
	})
})
