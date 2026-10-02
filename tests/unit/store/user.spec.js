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

		it('getLeaderboard is retired (WP4: ranking is consent-filtered server-side)', () => {
			expect(store.getLeaderboard).toBeUndefined()
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
			it('posts the invite and returns the house-user payload (CS-PLAN-062 D5)', async () => {
				const houseUser = { id: 9, nextcloudUserId: 'bob', displayName: 'Bob', role: 'member' }
				api.post.mockResolvedValue(houseUser)

				const result = await store.inviteUser('test@test.com', 'member')

				expect(api.post).toHaveBeenCalledWith('/api/v1/users/invite', { email: 'test@test.com', role: 'member' })
				// The backend payload IS the house-user object — no exists/user
				// envelope (the dead branch is gone).
				expect(result).toBe(houseUser)
			})

			it('upserts the joined member by stable identity — never duplicates (CS-PLAN-066 §3.2)', async () => {
				api.post.mockResolvedValue({ id: 9, nextcloudUserId: 'bob', displayName: 'Bob' })
				store.houseUsers = [{ id: 1, nextcloudUserId: 'mom', displayName: 'Mom' }]

				await store.inviteUser('bob@test.com', 'member')

				// The joined row joins the cached list exactly once.
				expect(store.houseUsers).toHaveLength(2)
				expect(store.houseUsers.filter(user => user.id === 9)).toHaveLength(1)

				// A repeated refresh of the SAME membership never duplicates
				// the row (CR-053-20 invite-uniqueness slice).
				store.houseUsers = store.upsertMembersByStableIdentity([...store.houseUsers, { id: 9, nextcloudUserId: 'bob', displayName: 'Bob' }])
				expect(store.houseUsers).toHaveLength(2)
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

		// CS-PLAN-066 §3.3: the shared current-user in-flight promise lives
		// in the store behind a generation — reset/identity changes discard
		// it, and an older request can never clear a newer one.
		describe('shared current-user request generations (CS-PLAN-066)', () => {
			it('shares one in-flight request across concurrent callers', async () => {
				let resolveRequest
				api.get.mockImplementation(() => new Promise((resolve) => { resolveRequest = resolve }))

				const first = store.initializeCurrentUser()
				const second = store.initializeCurrentUser()
				resolveRequest({ id: 1, nextcloudUserId: 'mom', role: 'owner' })

				expect(await first).toEqual({ id: 1, nextcloudUserId: 'mom', role: 'owner' })
				expect(await second).toEqual({ id: 1, nextcloudUserId: 'mom', role: 'owner' })
				expect(api.get).toHaveBeenCalledTimes(1)
				// The shared promise is released once it settles.
				expect(store.currentUserRequest).toBeNull()
			})

			it('reset during the in-flight request discards the late identity', async () => {
				let resolveRequest
				api.get.mockImplementation(() => new Promise((resolve) => { resolveRequest = resolve }))

				const pending = store.initializeCurrentUser()
				store.reset()
				resolveRequest({ id: 1, nextcloudUserId: 'old-user', role: 'owner' })
				await pending.catch(() => {})

				// The pre-reset resolution never repopulates old-user data.
				expect(store.currentUser).toBeNull()
				expect(store.membership).toBe('unknown')

				// A fresh initialization after the reset issues a NEW request.
				api.get.mockResolvedValue({ id: 2, nextcloudUserId: 'new-user', role: 'child' })
				await store.initializeCurrentUser()
				expect(api.get).toHaveBeenCalledTimes(2)
				expect(store.currentUser.nextcloudUserId).toBe('new-user')
			})

			it('an older in-flight release never clears a newer shared request', async () => {
				let resolveFirst
				api.get.mockImplementationOnce(() => new Promise((resolve) => { resolveFirst = resolve }))

				const first = store.initializeCurrentUser()
				store.reset()

				// A retry after the reset starts the NEWER shared request.
				api.get.mockResolvedValue({ id: 2, nextcloudUserId: 'new-user', role: 'child' })
				const second = store.initializeCurrentUser()
				await second

				// The older request settling afterwards must not clear or
				// overwrite the newer shared state.
				resolveFirst({ id: 1, nextcloudUserId: 'stale-user', role: 'owner' })
				await first.catch(() => {})

				expect(store.currentUser.nextcloudUserId).toBe('new-user')
				expect(store.currentUserRequest).toBeNull()
			})

			it('a superseded member-list read never overwrites the newer result', async () => {
				let resolveFirst
				api.get.mockImplementationOnce(() => new Promise((resolve) => { resolveFirst = resolve }))
				api.get.mockResolvedValueOnce([{ id: 2, displayName: 'Newer' }])

				const first = store.fetchHouseUsers()
				await store.fetchHouseUsers()
				resolveFirst([{ id: 1, displayName: 'Older' }])
				await first

				expect(store.houseUsers).toEqual([{ id: 2, displayName: 'Newer' }])
			})
		})

		// CS-PLAN-070 WP8 §3.2: role/membership reconciliation before
		// reward actions — the cached initialization is not a fresh
		// authority check.
		describe('refreshCurrentUser', () => {
			it('bypasses the cache and re-reads the current identity', async () => {
				api.get.mockResolvedValueOnce({ id: 5, nextcloudUserId: 'mom', role: 'owner' })
				await store.initializeCurrentUser()

				api.get.mockResolvedValueOnce({ id: 5, nextcloudUserId: 'mom', role: 'member' })
				const fresh = await store.refreshCurrentUser()

				expect(api.get).toHaveBeenCalledTimes(2)
				expect(api.get).toHaveBeenLastCalledWith('/api/v1/users/current')
				expect(fresh.role).toBe('member')
				expect(store.currentUser.role).toBe('member')
			})

			it('marks membership denied on a fresh 403', async () => {
				const denied = new Error('Forbidden')
				denied.status = 403
				api.get.mockRejectedValue(denied)

				await expect(store.refreshCurrentUser()).rejects.toThrow('Forbidden')
				expect(store.membership).toBe('denied')
			})

			it('drops a delayed refresh that was superseded by reset', async () => {
				let resolveFirst
				api.get.mockImplementationOnce(() => new Promise((resolve) => { resolveFirst = resolve }))
				const pending = store.refreshCurrentUser()
				store.reset()
				resolveFirst({ id: 9, nextcloudUserId: 'other', role: 'owner' })
				await pending

				expect(store.currentUser).toBeNull()
				expect(store.membership).toBe('unknown')
			})
		})
	})
})