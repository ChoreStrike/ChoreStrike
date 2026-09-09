/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import { api } from '../services/api.js'
import { translate as t } from '@nextcloud/l10n'
import { logger } from '../utils/logger.js'

/** @type {Promise<object>|null} Shared in-flight request for initializeCurrentUser */
let currentUserRequest = null

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

		getLeaderboard: (state) => {
			return [...state.houseUsers]
				.sort((a, b) => b.contributionScore - a.contributionScore)
		},
	},

	actions: {
		/**
		 * Initialize current user from Nextcloud.
		 *
		 * Idempotent: returns the cached user when already resolved and
		 * shares one in-flight request across concurrent callers (the
		 * auto-init plugin and view-level mounts), so only one
		 * GET /api/v1/users/current fires per resolution.
		 */
		async initializeCurrentUser() {
			if (this.currentUser) {
				return this.currentUser
			}

			if (!currentUserRequest) {
				currentUserRequest = api.get('/api/v1/users/current')
					.then((data) => {
						this.currentUser = data
						this.membership = 'member'
						return data
					})
					.catch((error) => {
						// Invite-only membership (ADR-010): a 403 FORBIDDEN from
						// user#current means this authenticated user is not a
						// member of the house — removed or never invited. The
						// app renders the explicit NotAuthorizedState for it.
						if (error?.status === 403 || error?.code === 'FORBIDDEN') {
							this.membership = 'denied'
						}
						this.error = error.message
						logger.error('Failed to initialize current user', error, { store: 'user', action: 'initializeCurrentUser' })
						throw error
					})
					.finally(() => {
						currentUserRequest = null
					})
			}

			return currentUserRequest
		},

		/**
		 * Fetch all house users
		 */
		async fetchHouseUsers() {
			this.loading = true
			this.error = null

			try {
				const data = await api.get('/api/v1/users')
				this.houseUsers = data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch house users', error, { store: 'user', action: 'fetchHouseUsers' })
				throw error
			} finally {
				this.loading = false
			}
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
				return await api.post('/api/v1/users/invite', { email, role })
			} catch (error) {
				this.error = error.message
				logger.error('Failed to invite user', error, { store: 'user', action: 'inviteUser', role })
				throw error
			} finally {
				this.loading = false
			}
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
		 * Reconcile the current user's progression fields from an
		 * authoritative completion/undo rewards payload (CS-PLAN-064
		 * §3.5, decision D10). Only ever applied when the payload's
		 * totals describe the CURRENT user (the completer) — caller
		 * guards the completer-identity check; the undo actor may be a
		 * house owner restoring someone else's state.
		 * @param {object} rewards Completion/undo `rewards` object.
		 */
		applyRewardTotals(rewards) {
			if (!rewards) return

			const patch = {}
			if (Number.isFinite(rewards.totalCoins)) {
				patch.coins = rewards.totalCoins
			}
			if (Number.isFinite(rewards.totalContribution)) {
				patch.contributionScore = rewards.totalContribution
			}
			if (Number.isFinite(rewards.currentStreak)) {
				patch.currentStreak = rewards.currentStreak
			}
			if (Number.isFinite(rewards.longestStreak)) {
				patch.longestStreak = rewards.longestStreak
			}
			if (Number.isFinite(rewards.streakFreezesRemaining)) {
				patch.streakFreezes = rewards.streakFreezesRemaining
			}
			if (Object.keys(patch).length === 0 || !this.currentUser) return

			this.currentUser = { ...this.currentUser, ...patch }
			// The leaderboard slice shows the same HouseUser rows — keep it
			// consistent whenever the entry is loaded.
			this.houseUsers = this.houseUsers.map(user => (
				user.nextcloudUserId === this.currentUser.nextcloudUserId
					? { ...user, ...patch }
					: user
			))
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
			this.currentUser = null
			this.houseUsers = []
			this.loading = false
			this.error = null
			this.membership = 'unknown'
		},
	},
})
