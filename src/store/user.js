/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import { api } from '../services/api.js'
import { translate as t } from '@nextcloud/l10n'
import { logger } from '../utils/logger.js'
import { usePreferencesStore } from './preferences.js'

export const useUserStore = defineStore('user', {
	state: () => ({
		currentUser: null,
		houseUsers: [],
		loading: false,
		error: null,
		/**
		 * Authorization state driven by user#current (CS-PLAN-061 §3.9):
		 * 'unknown' before the first resolution, 'member' once the current
		 * user resolved, 'denied' when the backend answered 403/FORBIDDEN —
		 * removed and never-invited are deliberately indistinguishable
		 * (anti-enumeration).
		 */
		membership: 'unknown',
		// CS-PLAN-066 §3.3: the shared in-flight request lives IN the store
		// (not a module variable) with a generation counter, so reset()
		// invalidates it — a pre-reset resolution can never repopulate
		// old-user data, and an older request's finally can never clear a
		// newer request's shared promise.
		currentUserRequest: null,
		currentUserRequestSeq: 0,
		houseUsersRequestSeq: 0,
	}),

	getters: {
		userId: (state) => state.currentUser?.id || null,

		isHouseOwner: (state) => state.currentUser?.role === 'owner',

		role: (state) => state.currentUser?.role ?? null,

		isChild: (state) => state.currentUser?.role === 'child',

		/**
		 * Task-domain management surface (decision D5): owners and members
		 * may create/edit/delete/assign tasks; children execute only.
		 *
		 * @param {object} state Pinia state.
		 * @return {boolean} True when the current role may manage tasks.
		 */
		canManageTasks: (state) => ['owner', 'member'].includes(state.currentUser?.role),
	},

	actions: {
		/**
		 * Initialize current user from Nextcloud.
		 *
		 * Idempotent: returns the cached user when already resolved and
		 * shares one in-flight request across concurrent callers (the
		 * auto-init plugin and view-level mounts), so only one
		 * GET /api/v1/users/current fires per resolution. The shared
		 * promise is released only by its own completion (identity-checked
		 * finally) or by reset() — an older request can never clear a
		 * newer one, and a stale resolution never lands after a reset.
		 */
		async initializeCurrentUser() {
			if (this.currentUser) {
				return this.currentUser
			}

			if (this.currentUserRequest) {
				return this.currentUserRequest
			}

			const seq = ++this.currentUserRequestSeq
			const request = api.get('/api/v1/users/current')
				.then((data) => {
					if (seq === this.currentUserRequestSeq) {
						this.currentUser = data
						this.membership = 'member'
					}
					return data
				})
				.catch((error) => {
					if (seq === this.currentUserRequestSeq) {
						// Invite-only membership (ADR-010): a 403 FORBIDDEN
						// from user#current means this authenticated user is
						// not a member of the house — removed or never
						// invited. The app renders the explicit
						// NotAuthorizedState for it.
						if (error?.status === 403 || error?.code === 'FORBIDDEN') {
							this.membership = 'denied'
						}
						this.error = error.message
						logger.error('Failed to initialize current user', error, { store: 'user', action: 'initializeCurrentUser' })
					}
					throw error
				})
				.finally(() => {
					// Identity-checked release: only the request that is
					// still the shared one may clear it — an older request
					// settling after a retry/reset leaves the newer promise
					// untouched.
					if (this.currentUserRequest === request) {
						this.currentUserRequest = null
					}
				})
			this.currentUserRequest = request

			return request
		},

		/**
		 * Fetch all house members (authoritative membership list), guarded
		 * by a request generation so a superseded read can never overwrite
		 * a newer result or survive a reset.
		 */
		async fetchHouseUsers() {
			// CS-PLAN-066 §3.3: member reads are generation-guarded — every
			// accepted mutation (invite/role change/removal) invalidates
			// pre-mutation reads, and reset() discards in-flight results.
			const seq = ++this.houseUsersRequestSeq
			this.loading = true
			this.error = null

			try {
				const data = await api.get('/api/v1/users')
				if (seq !== this.houseUsersRequestSeq) {
					logger.debug('Dropping superseded house-users read', { store: 'user', action: 'fetchHouseUsers' })
					return this.houseUsers
				}
				this.houseUsers = this.upsertMembersByStableIdentity(data)
				return this.houseUsers
			} catch (error) {
				if (seq === this.houseUsersRequestSeq) {
					this.error = error.message
				}
				logger.error('Failed to fetch house users', error, { store: 'user', action: 'fetchHouseUsers' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * CS-PLAN-066 §3.1: member lists replace/upsert by STABLE identity
		 * (the numeric membership `id`), never append blindly — an invite
		 * followed by a member-list refresh can never duplicate a row.
		 * @param {Array<object>} incoming Authoritative member rows.
		 * @return {Array<object>} Deduplicated member list (input order).
		 */
		upsertMembersByStableIdentity(incoming) {
			const seen = new Set()
			const result = []
			for (const member of incoming || []) {
				const key = member?.id ?? member?.nextcloudUserId
				if (key === undefined || key === null || seen.has(key)) continue
				seen.add(key)
				result.push(member)
			}
			return result
		},

		/**
		 * Invite user to house.
		 * The backend returns the (created or existing) house-user object —
		 * the caller can name the joined user from it (CS-PLAN-062 §3.4, D5).
		 * @param {string} email Email address of the user to invite.
		 * @param {string} role Role to assign to the invited user.
		 * @return {Promise<object>} The house-user object of the invited user.
		 */
		async inviteUser(email, role = 'member') {
			if (!email || email.trim() === '') {
				throw new Error(t('chorestrike', 'Email is required'))
			}

			if (!['owner', 'member', 'child'].includes(role)) {
				throw new Error(t('chorestrike', 'Invalid role'))
			}

			this.loading = true
			this.error = null

			try {
				const joined = await api.post('/api/v1/users/invite', { email, role })
				// CS-PLAN-066 §3.2: the authoritative membership changed —
				// pre-mutation member-list reads are invalidated; the
				// joined row is upserted by stable identity so a duplicate
				// invite refresh can never create duplicate rows.
				this.invalidateMemberReads()
				this.houseUsers = this.upsertMembersByStableIdentity([
					...this.houseUsers,
					joined,
				])
				return joined
			} catch (error) {
				this.error = error.message
				logger.error('Failed to invite user', error, { store: 'user', action: 'inviteUser', role })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Invalidate in-flight member-list reads (an accepted membership
		 * mutation supersedes them) without discarding the current list.
		 */
		invalidateMemberReads() {
			this.houseUsersRequestSeq++
		},

		/**
		 * Remove user from house
		 * @param {string|number} userId Internal identifier of the user to remove.
		 */
		async removeUser(userId) {
			if (!userId) {
				throw new Error(t('chorestrike', 'User ID is required'))
			}

			this.loading = true
			this.error = null

			try {
				await api.delete(`/api/v1/users/${userId}`)
				this.invalidateMemberReads()
				this.houseUsers = this.houseUsers.filter(user => user.id !== userId)
			} catch (error) {
				this.error = error.message
				logger.error('Failed to remove user', error, { store: 'user', action: 'removeUser', userId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Update user role
		 * @param {string|number} userId Internal identifier of the user to update.
		 * @param {string} role New role value.
		 */
		async updateUserRole(userId, role) {
			if (!userId) {
				throw new Error(t('chorestrike', 'User ID is required'))
			}

			if (!['owner', 'member', 'child'].includes(role)) {
				throw new Error(t('chorestrike', 'Invalid role'))
			}

			this.loading = true
			this.error = null

			try {
				const data = await api.put(`/api/v1/users/${userId}/role`, { role })
				this.invalidateMemberReads()

				const userIndex = this.houseUsers.findIndex(user => user.id === userId)
				if (userIndex >= 0) {
					this.houseUsers[userIndex] = data
				}

				if (this.currentUser && this.currentUser.id === userId) {
					this.currentUser = data
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to update user role', error, { store: 'user', action: 'updateUserRole', userId, role })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Reconcile the caller's OWN wallet/contribution from an
		 * authoritative completion/undo rewards payload (WP3 §3.6):
		 * wallet in minor units (booked/reserved/available/fundingHold),
		 * exact contributionMinor. Called on every completion/undo
		 * response that carries the block; unknown/null values keep the
		 * current state.
		 * @param {?object} wallet Own wallet block (minor units) or null.
		 * @param {?number} contributionMinor Exact contribution minor units.
		 */
		applyOwnWallet(wallet, contributionMinor) {
			if (!this.currentUser) return
			const patch = {}
			if (wallet && typeof wallet === 'object') {
				patch.wallet = wallet
			}
			if (Number.isFinite(contributionMinor)) {
				patch.contributionMinor = contributionMinor
			}
			if (Object.keys(patch).length === 0) return

			this.currentUser = { ...this.currentUser, ...patch }
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
			// CS-PLAN-066 §3.3: identity change — invalidate any in-flight
			// user/member read BEFORE clearing, so a late success can never
			// repopulate old-user data and the shared promise is released.
			this.currentUserRequestSeq++
			this.houseUsersRequestSeq++
			this.currentUserRequest = null
			this.currentUser = null
			this.houseUsers = []
			this.loading = false
			this.error = null
			this.membership = 'unknown'
			// CS-PLAN-070 WP5 (C5h): preferences are membership-keyed — an
			// identity/house switch drops the local preference state like
			// every other store.
			usePreferencesStore().reset()
		},
	},
})
