/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, beforeEach, jest } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'
import { useUserStore } from '../../../src/store/user.js'

jest.mock('../../../src/services/api.js', () => ({
	api: {
		get: jest.fn(),
		post: jest.fn(),
		put: jest.fn(),
		delete: jest.fn(),
	},
}))

jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, message) => message),
}))

import { api } from '../../../src/services/api.js'
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
			expect(store.membership).toBe('unknown')
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

		it('isHouseOwner returns true for owner role', () => {
			store.currentUser = { id: 1, role: 'owner' }
			expect(store.isHouseOwner).toBe(true)
		})

		it('isHouseOwner returns false for member role', () => {
			store.currentUser = { id: 1, role: 'member' }
			expect(store.isHouseOwner).toBe(false)
		})

		it('role returns the raw role', () => {
			store.currentUser = { id: 1, role: 'child' }
			expect(store.role).toBe('child')
			store.currentUser = null
			expect(store.role).toBeNull()
		})

		it('isChild is true only for the child role (CS-PLAN-061 §3.9)', () => {
			store.currentUser = { id: 1, role: 'child' }
			expect(store.isChild).toBe(true)
			store.currentUser = { id: 1, role: 'member' }
			expect(store.isChild).toBe(false)
			store.currentUser = { id: 1, role: 'owner' }
			expect(store.isChild).toBe(false)
		})

		it('canManageTasks is true for owner and member, false for child (decision D5)', () => {
			store.currentUser = { id: 1, role: 'owner' }
			expect(store.canManageTasks).toBe(true)
			store.currentUser = { id: 1, role: 'member' }
			expect(store.canManageTasks).toBe(true)
			store.currentUser = { id: 1, role: 'child' }
			expect(store.canManageTasks).toBe(false)
			store.currentUser = null
			expect(store.canManageTasks).toBe(false)
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

	})

	describe('Actions', () => {
		describe('initializeCurrentUser', () => {
			it('fetches and sets current user', async () => {
				const mockUser = { id: 1, displayName: 'John' }
				api.get.mockResolvedValue(mockUser)

				const result = await store.initializeCurrentUser()

				expect(result).toEqual(mockUser)
				expect(store.currentUser).toEqual(mockUser)
			})

			it('throws ApiError on failure', async () => {
				const error = new Error('Failed to initialize current user')
				api.get.mockRejectedValue(error)

				await expect(store.initializeCurrentUser()).rejects.toThrow('Failed to initialize current user')
			})

			it('shares one request across concurrent callers', async () => {
				const mockUser = { id: 1, displayName: 'John' }
				let resolveGet
				api.get.mockReturnValue(new Promise((resolve) => {
					resolveGet = resolve
				}))

				const first = store.initializeCurrentUser()
				const second = store.initializeCurrentUser()

				resolveGet(mockUser)
				await expect(first).resolves.toEqual(mockUser)
				await expect(second).resolves.toEqual(mockUser)
				expect(api.get).toHaveBeenCalledTimes(1)
			})

			it('returns the cached user without re-fetching once resolved', async () => {
				const mockUser = { id: 1, displayName: 'John' }
				api.get.mockResolvedValue(mockUser)

				await store.initializeCurrentUser()
				const result = await store.initializeCurrentUser()

				expect(result).toEqual(mockUser)
				expect(api.get).toHaveBeenCalledTimes(1)
			})

			it('allows a retry after a failed initialization', async () => {
				const mockUser = { id: 1, displayName: 'John' }
				api.get
					.mockRejectedValueOnce(new Error('transient failure'))
					.mockResolvedValueOnce(mockUser)

				await expect(store.initializeCurrentUser()).rejects.toThrow('transient failure')
				await expect(store.initializeCurrentUser()).resolves.toEqual(mockUser)
				expect(store.currentUser).toEqual(mockUser)
			})

			it('sets membership to member after a successful resolution', async () => {
				api.get.mockResolvedValue({ id: 1, role: 'owner' })

				await store.initializeCurrentUser()

				expect(store.membership).toBe('member')
			})

			it('sets membership to denied on 403/FORBIDDEN (invite-only, ADR-010)', async () => {
				const error = new Error('Not a member of this house')
				error.name = 'ApiError'
				error.status = 403
				error.code = 'FORBIDDEN'
				api.get.mockRejectedValue(error)

				await expect(store.initializeCurrentUser()).rejects.toThrow()

				expect(store.membership).toBe('denied')
				expect(store.currentUser).toBeNull()
			})

			it('sets membership to denied on code FORBIDDEN even with a different status', async () => {
				const error = new Error('denied')
				error.name = 'ApiError'
				error.status = 500
				error.code = 'FORBIDDEN'
				api.get.mockRejectedValue(error)

				await expect(store.initializeCurrentUser()).rejects.toThrow()

				expect(store.membership).toBe('denied')
			})

			it('keeps membership unknown on non-403 failures', async () => {
				const error = new Error('boom')
				error.name = 'ApiError'
				error.status = 500
				error.code = 'CURRENT_USER_ERROR'
				api.get.mockRejectedValue(error)

				await expect(store.initializeCurrentUser()).rejects.toThrow()

				expect(store.membership).toBe('unknown')
			})
		})

		describe('fetchHouseUsers', () => {
			it('fetches house users', async () => {
				const mockUsers = [{ id: 1 }, { id: 2 }]
				api.get.mockResolvedValue(mockUsers)

				await store.fetchHouseUsers()

				expect(store.houseUsers).toEqual(mockUsers)
			})
		})

		describe('inviteUser', () => {
			it('invites user successfully', async () => {
				const result = { exists: false }
				api.post.mockResolvedValue(result)

				await store.inviteUser('test@test.com', 'member')

				expect(api.post).toHaveBeenCalledWith('/api/v1/users/invite', { email: 'test@test.com', role: 'member' })
			})

			it('throws error for invalid role', async () => {
				await expect(store.inviteUser('test@test.com', 'invalid')).rejects.toThrow('Invalid role')
				expect(t).toHaveBeenCalledWith('chorestrike', 'Invalid role')
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
				store.membership = 'denied'

				store.reset()

				expect(store.currentUser).toBeNull()
				expect(store.houseUsers).toEqual([])
				expect(store.loading).toBe(false)
				expect(store.error).toBeNull()
				expect(store.membership).toBe('unknown')
			})
		})
	})
})